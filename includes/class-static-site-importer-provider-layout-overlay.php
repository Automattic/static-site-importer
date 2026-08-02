<?php
/**
 * Validates provider-owned layout targets and compiles their bounded CSS overlay.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Static_Site_Importer_Provider_Layout_Overlay {
	public const MAP_SCHEMA = 'generic/provider-layout-target-map/v1';
	public const OVERLAY_SCHEMA = 'static-site-importer/provider-layout-overlay/v1';

	/** @return array{css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,overlay:array<string,mixed>} */
	public static function compile( array $graph, array $map ): array {
		$targets = self::targets( $graph, $map );
		if ( null === $targets ) {
			return self::result( '', array(), array( self::loss( 'provider_structure_mismatch', '' ) ) );
		}
		$rules = array();
		$operations = array();
		$losses = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || empty( $node['layout'] ) ) {
				continue;
			}
			$id = (string) ( $node['id'] ?? '' );
			if ( ! isset( $targets[ $id ] ) ) {
				$losses[] = self::loss( 'provider_structure_mismatch', $id );
				continue;
			}
			$declarations = self::declarations( $node['layout'], $targets[ $id ]['capabilities'], $id, $losses );
			if ( ! empty( $declarations ) ) {
				$rules[] = $targets[ $id ]['selector'] . '{' . implode( ';', $declarations ) . '}';
				$operations[] = array( 'dimension' => 'layout', 'strategy' => 'provider_selector_transposition', 'node_hash' => hash( 'sha256', $id ), 'target_hash' => hash( 'sha256', $targets[ $id ]['selector'] ) );
			}
		}
		$css = empty( $rules ) ? '' : "/* Static Site Importer provider layout overlay */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
		return self::result( $css, $operations, $losses );
	}

	/** Accept only the compiler's bounded, content-addressed overlay shape. */
	public static function valid_overlay( mixed $overlay ): bool {
		return is_array( $overlay ) && self::OVERLAY_SCHEMA === ( $overlay['schema'] ?? null ) && is_string( $overlay['css'] ?? null ) && is_string( $overlay['sha256'] ?? null ) && is_int( $overlay['bytes'] ?? null ) && $overlay['bytes'] === strlen( $overlay['css'] ) && $overlay['bytes'] <= 16384 && hash_equals( $overlay['sha256'], hash( 'sha256', $overlay['css'] ) ) && str_starts_with( $overlay['css'], "/* Static Site Importer provider layout overlay */\n" );
	}

	/** @return array<string,array{selector:string,capabilities:array<int,string>}>|null */
	private static function targets( array $graph, array $map ): ?array {
		if ( self::MAP_SCHEMA !== ( $map['schema'] ?? null ) || 'jetpack' !== ( $map['provider'] ?? null ) || ! is_string( $map['scope'] ?? null ) || ! preg_match( '/^\.ssi-form-[a-f0-9]{12}$/D', $map['scope'] ) || ! is_array( $map['targets'] ?? null ) || ! array_is_list( $map['targets'] ) ) {
			return null;
		}
		$nodes = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = true;
			}
		}
		$targets = array();
		foreach ( $map['targets'] as $target ) {
			if ( ! is_array( $target ) || ! is_string( $target['node'] ?? null ) || ! isset( $nodes[ $target['node'] ] ) || isset( $targets[ $target['node'] ] ) || ! is_string( $target['selector'] ?? null ) || ! preg_match( '/^' . preg_quote( $map['scope'], '/' ) . '(?: > form\.jetpack-contact-form__form| \.ssi-node-[a-f0-9]{12})$/D', $target['selector'] ) || ! is_array( $target['capabilities'] ?? null ) ) {
				return null;
			}
			$capabilities = array_values( array_unique( $target['capabilities'] ) );
			if ( array_diff( $capabilities, array( 'container_layout', 'direct_child_layout', 'item_layout' ) ) ) {
				return null;
			}
			$targets[ $target['node'] ] = array( 'selector' => $target['selector'], 'capabilities' => $capabilities );
		}
		return $targets;
	}

	/** @param array<int,string> $capabilities @param array<int,array<string,mixed>> $losses @return array<int,string> */
	private static function declarations( array $layout, array $capabilities, string $node, array &$losses ): array {
		$properties = array( 'display' => 'display', 'columns' => 'grid-template-columns', 'rows' => 'grid-template-rows', 'gap' => 'gap', 'row_gap' => 'row-gap', 'column_gap' => 'column-gap', 'direction' => 'flex-direction', 'wrap' => 'flex-wrap', 'column' => 'grid-column', 'row' => 'grid-row', 'area' => 'grid-area' );
		$declarations = array();
		foreach ( $layout as $fact => $value ) {
			if ( ! isset( $properties[ $fact ] ) || ! self::safe_value( $fact, $value ) ) {
				$losses[] = self::loss( 'unsafe_layout_value', $node );
				continue;
			}
			$is_item = in_array( $fact, array( 'column', 'row', 'area' ), true );
			if ( $is_item && ( ! in_array( 'item_layout', $capabilities, true ) || ! in_array( 'direct_child_layout', $capabilities, true ) ) ) {
				$losses[] = self::loss( 'direct_child_relationship_unrepresentable', $node );
				continue;
			}
			if ( ! $is_item && ! in_array( 'container_layout', $capabilities, true ) ) {
				$losses[] = self::loss( 'provider_structure_mismatch', $node );
				continue;
			}
			$declarations[] = $properties[ $fact ] . ':' . $value;
		}
		return $declarations;
	}

	private static function safe_value( string $fact, mixed $value ): bool {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value || strlen( $value ) > 120 || preg_match( '/(?:url\(|[;{}\\\\]|!important|expression\()/i', $value ) ) {
			return false;
		}
		if ( 'display' === $fact ) {
			return in_array( $value, array( 'grid', 'flex', 'block' ), true );
		}
		if ( in_array( $fact, array( 'direction', 'wrap' ), true ) ) {
			return in_array( $value, array( 'row', 'column', 'wrap', 'nowrap' ), true );
		}
		return (bool) preg_match( '/^(?:auto|span [1-9][0-9]*|[1-9][0-9]*(?: \/ [1-9][0-9]*)?|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|fr)|repeat\([1-9][0-9]*, ?1fr\)|(?:1fr ?){1,8})$/D', $value );
	}

	/** @return array{css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,overlay:array<string,mixed>} */
	private static function result( string $css, array $operations, array $losses ): array {
		return array( 'css' => $css, 'operations' => $operations, 'losses' => $losses, 'overlay' => '' === $css ? array() : array( 'schema' => self::OVERLAY_SCHEMA, 'css' => $css, 'sha256' => hash( 'sha256', $css ), 'bytes' => strlen( $css ) ) );
	}

	/** @return array<string,mixed> */
	private static function loss( string $reason, string $node ): array {
		return array( 'dimension' => 'layout', 'reason_code' => $reason, 'node_hash' => hash( 'sha256', $node ) );
	}
}
