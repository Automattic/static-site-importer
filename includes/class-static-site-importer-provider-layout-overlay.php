<?php
/**
 * Bounded provider layout target maps and scoped overlay CSS.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Static_Site_Importer_Provider_Layout_Overlay {
	public const MAP_SCHEMA         = 'generic/provider-layout-target-map/v1';
	public const OVERLAY_SCHEMA     = 'static-site-importer/provider-layout-overlay/v1';
	private const MAX_OVERLAY_BYTES = 16384;

	/** @return array{map?:array<string,mixed>,error?:string} */
	public static function validate_map( mixed $map, array $graph ): array {
		if ( ! is_array( $map ) || self::MAP_SCHEMA !== ( $map['schema'] ?? null ) || ! is_string( $map['provider'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $map['provider'] ) || ! is_string( $map['scope'] ?? null ) || ! preg_match( '/^\.ssi-form-[a-f0-9]{12}$/D', $map['scope'] ) || ! is_array( $map['targets'] ?? null ) || ! array_is_list( $map['targets'] ) || count( $map['targets'] ) > 128 || array_diff( array_keys( $map ), array( 'schema', 'provider', 'scope', 'targets' ) ) ) {
			return array( 'error' => 'provider layout target map is not a bounded canonical map.' );
		}
		$nodes = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = true;
			}
		}
		$seen    = array();
		$targets = array();
		foreach ( $map['targets'] as $target ) {
			if ( ! is_array( $target ) || array_diff( array_keys( $target ), array( 'node', 'selector', 'capabilities' ) ) || ! is_string( $target['node'] ?? null ) || ! isset( $nodes[ $target['node'] ] ) || isset( $seen[ $target['node'] ] ) || ! is_string( $target['selector'] ?? null ) || ! self::safe_selector( $target['selector'], $map['scope'] ) || ! is_array( $target['capabilities'] ?? null ) || ! array_is_list( $target['capabilities'] ) || array_diff( $target['capabilities'], array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' ) ) ) {
				return array( 'error' => 'provider layout target map contains an unsafe target.' );
			}
			$seen[ $target['node'] ] = true;
			$targets[]               = array(
				'node'         => $target['node'],
				'selector'     => $target['selector'],
				'capabilities' => array_values( array_unique( $target['capabilities'] ) ),
			);
		}
		return array(
			'map' => array(
				'schema'   => self::MAP_SCHEMA,
				'provider' => $map['provider'],
				'scope'    => $map['scope'],
				'targets'  => $targets,
			),
		);
	}

	/** @return array{overlay:array<string,mixed>,css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>} */
	public static function compile( array $graph, mixed $map ): array {
		$validated     = self::validate_map( $map, $graph );
		$validated_map = $validated['map'] ?? null;
		if ( isset( $validated['error'] ) || ! is_array( $validated_map ) || ! isset( $validated_map['targets'] ) || ! is_array( $validated_map['targets'] ) ) {
			return array(
				'overlay'    => array(),
				'css'        => '',
				'operations' => array(),
				'losses'     => array(
					array(
						'dimension'   => 'layout',
						'reason_code' => 'provider_structure_mismatch',
						'map_error'   => $validated['error'] ?? 'provider layout target map could not be normalized.',
					),
				),
			);
		}
		$targets = array();
		foreach ( $validated_map['targets'] as $target ) {
			$targets[ $target['node'] ] = $target;
		}
		$rules      = array();
		$operations = array();
		$losses     = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || empty( $node['layout'] ) ) {
				continue;
			}
			$id     = (string) ( $node['id'] ?? '' );
			$target = $targets[ $id ] ?? null;
			if ( null === $target ) {
				$losses[] = self::loss( 'provider_structure_mismatch', $id );
				continue; }
			$declarations = self::declarations( $node['layout'], $target['capabilities'], $id, $losses );
			if ( ! empty( $declarations ) ) {
				$rules[]      = $target['selector'] . '{' . implode( ';', $declarations ) . '}';
				$operations[] = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_selector_transposition',
					'node_hash'   => hash( 'sha256', $id ),
					'target_hash' => hash( 'sha256', $target['selector'] ),
				); }
		}
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( ! is_array( $variant ) || empty( $variant['layout_patch'] ) ) {
				continue;
			}
			$id     = (string) ( $variant['node'] ?? '' );
			$target = $targets[ $id ] ?? null;
			if ( null === $target || ! in_array( 'responsive_layout', $target['capabilities'], true ) || ! self::safe_condition( $variant['condition'] ?? null ) ) {
				$losses[] = self::loss( 'responsive_layout_ownership', $id );
				continue; }
			$declarations = self::declarations( $variant['layout_patch'], $target['capabilities'], $id, $losses );
			if ( ! empty( $declarations ) ) {
				$rules[]      = '@media ' . $variant['condition']['query'] . '{' . $target['selector'] . '{' . implode( ';', $declarations ) . '}}';
				$operations[] = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_selector_transposition',
					'node_hash'   => hash( 'sha256', $id ),
					'target_hash' => hash( 'sha256', $target['selector'] ),
					'responsive'  => true,
				); }
		}
		$css     = empty( $rules ) ? '' : '/* Static Site Importer provider layout overlay: ' . substr( hash( 'sha256', implode( "\n", $rules ) ), 0, 12 ) . " */\n" . implode( "\n", array_values( array_unique( $rules ) ) ) . "\n";
		$overlay = '' === $css ? array() : array(
			'schema' => self::OVERLAY_SCHEMA,
			'css'    => $css,
			'sha256' => hash( 'sha256', $css ),
			'bytes'  => strlen( $css ),
		);
		return array(
			'overlay'    => $overlay,
			'css'        => $css,
			'operations' => $operations,
			'losses'     => $losses,
		);
	}

	/** Validate a compiler-produced overlay before it is admitted to a stylesheet. */
	public static function validate_overlay( mixed $overlay ): ?array {
		if ( ! is_array( $overlay ) || array_keys( $overlay ) !== array( 'schema', 'css', 'sha256', 'bytes' ) || self::OVERLAY_SCHEMA !== ( $overlay['schema'] ?? null ) || ! is_string( $overlay['css'] ?? null ) || ! is_string( $overlay['sha256'] ?? null ) || ! is_int( $overlay['bytes'] ?? null ) ) {
			return null;
		}
		$css = $overlay['css'];
		if ( '' === $css || strlen( $css ) !== $overlay['bytes'] || $overlay['bytes'] > self::MAX_OVERLAY_BYTES || ! preg_match( '/^[a-f0-9]{64}$/D', $overlay['sha256'] ) || ! hash_equals( $overlay['sha256'], hash( 'sha256', $css ) ) ) {
			return null;
		}
		if ( ! preg_match( '/^\/\* Static Site Importer provider layout overlay: [a-f0-9]{12} \*\/\n/', $css, $header ) ) {
			return null;
		}
		$body = substr( $css, strlen( $header[0] ) );
		if ( ! str_ends_with( $body, "\n" ) || str_contains( $body, 'url(' ) || str_contains( $body, '@import' ) || str_contains( $body, '!important' ) ) {
			return null;
		}
		foreach ( array_filter( explode( "\n", trim( $body ) ) ) as $rule ) {
			if ( ! self::safe_compiled_rule( $rule ) ) {
				return null;
			}
		}
		return $overlay;
	}

	private static function safe_compiled_rule( string $rule ): bool {
		if ( preg_match( '/^@media (\((?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)\))\{(.+)\}$/D', $rule, $matches ) ) {
			return self::safe_compiled_rule( $matches[2] );
		}
		if ( ! preg_match( '/^(\.ssi-form-[a-f0-9]{12}(?: > [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*| \.ssi-node-[a-f0-9]{12}))\{([^{}]+)\}$/D', $rule, $matches ) ) {
			return false;
		}
		$allowed = array( 'display', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area' );
		foreach ( explode( ';', $matches[2] ) as $declaration ) {
			if ( ! preg_match( '/^([a-z-]+):(.+)$/D', $declaration, $parts ) || ! in_array( $parts[1], $allowed, true ) || ! self::safe_value( str_replace( array( 'grid-template-columns', 'grid-template-rows', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area' ), array( 'columns', 'rows', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area' ), $parts[1] ), $parts[2] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_selector( string $selector, string $scope ): bool {
		return (bool) preg_match( '/^' . preg_quote( $scope, '/' ) . '(?: > [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*| \.ssi-node-[a-f0-9]{12})$/D', $selector );
	}
	private static function safe_condition( mixed $condition ): bool {
		return is_array( $condition ) && array_keys( $condition ) === array( 'kind', 'query' ) && 'media' === ( $condition['kind'] ?? null ) && is_string( $condition['query'] ?? null ) && (bool) preg_match( '/^\((?:min|max)-(?:width|height): ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|em|rem|vw|vh)\)$/D', $condition['query'] );
	}
	private static function declarations( array $layout, array $capabilities, string $node, array &$losses ): array {
		$map          = array(
			'display'         => 'display',
			'columns'         => 'grid-template-columns',
			'rows'            => 'grid-template-rows',
			'gap'             => 'gap',
			'row_gap'         => 'row-gap',
			'column_gap'      => 'column-gap',
			'direction'       => 'flex-direction',
			'wrap'            => 'flex-wrap',
			'align_items'     => 'align-items',
			'align_content'   => 'align-content',
			'justify_content' => 'justify-content',
			'align_self'      => 'align-self',
			'justify_self'    => 'justify-self',
			'order'           => 'order',
			'flex'            => 'flex',
			'flex_grow'       => 'flex-grow',
			'flex_shrink'     => 'flex-shrink',
			'flex_basis'      => 'flex-basis',
			'column'          => 'grid-column',
			'row'             => 'grid-row',
			'area'            => 'grid-area',
		);
		$declarations = array();
		foreach ( $layout as $fact => $value ) {
			if ( ! isset( $map[ $fact ] ) || ! self::safe_value( $fact, $value ) ) {
				$losses[] = self::loss( 'unsafe_layout_value', $node );
				continue; }
			if ( in_array( $fact, array( 'column', 'row', 'area', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self' ), true ) && ( ! in_array( 'item_layout', $capabilities, true ) || ! in_array( 'direct_child_layout', $capabilities, true ) ) ) {
				$losses[] = self::loss( 'direct_child_relationship_unrepresentable', $node );
				continue; }
			if ( ! in_array( $fact, array( 'column', 'row', 'area', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self' ), true ) && ! in_array( 'container_layout', $capabilities, true ) ) {
				$losses[] = self::loss( 'provider_structure_mismatch', $node );
				continue; }
			$declarations[] = $map[ $fact ] . ':' . $value;
		}
		return $declarations;
	}
	private static function safe_value( string $fact, mixed $value ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}
		$value = (string) $value;
		if ( '' === $value || strlen( $value ) > 160 || preg_match( '/(?:url\(|[;{}\\\\]|!important|expression\()/i', $value ) ) {
			return false;
		}
		if ( in_array( $fact, array( 'display', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self' ), true ) ) {
			return (bool) preg_match( '/^(?:flex|grid|block|inline-flex|row|row-reverse|column|column-reverse|wrap|nowrap|wrap-reverse|flex-start|flex-end|center|stretch|baseline|space-between|space-around|space-evenly|start|end)$/D', $value );
		}
		if ( in_array( $fact, array( 'order', 'flex_grow', 'flex_shrink' ), true ) ) {
			return (bool) preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/D', $value );
		}
		return (bool) preg_match( '/^(?:auto|none|span [1-9][0-9]*|[1-9][0-9]*|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)|minmax\((?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr), ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)\)|repeat\([1-9][0-9]*, ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)\))+(?: \/ [1-9][0-9]*)?$/D', $value );
	}
	private static function loss( string $reason, string $node ): array { return array(
		'dimension'   => 'layout',
		'reason_code' => $reason,
		'node_hash'   => hash( 'sha256', $node ),
	); }
}
