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
	public const MAP_SCHEMA                = 'generic/provider-layout-target-map/v1';
	public const OVERLAY_SCHEMA            = 'static-site-importer/provider-layout-overlay/v1';
	private const MAX_LAYOUT_OVERLAY_BYTES = 16384;
	private const MAX_OVERLAY_BYTES        = 32768;

	/** @return array{map?:array<string,mixed>,error?:string} */
	public static function validate_map( mixed $map, array $graph ): array {
		if ( ! is_array( $map ) || self::MAP_SCHEMA !== ( $map['schema'] ?? null ) || ! is_string( $map['provider'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $map['provider'] ) || ! is_string( $map['scope'] ?? null ) || ! preg_match( '/^\.ssi-form-[a-f0-9]{12}$/D', $map['scope'] ) || ! is_array( $map['targets'] ?? null ) || ! array_is_list( $map['targets'] ) || count( $map['targets'] ) > 128 || ! is_array( $map['presentation_targets'] ?? array() ) || ! array_is_list( $map['presentation_targets'] ?? array() ) || count( $map['presentation_targets'] ?? array() ) > 128 || array_diff( array_keys( $map ), array( 'schema', 'provider', 'scope', 'targets', 'presentation_targets' ) ) ) {
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
		$presentation_targets = array();
		$seen_presentations   = array();
		foreach ( $map['presentation_targets'] ?? array() as $target ) {
			if ( ! is_array( $target ) || ! is_int( $target['index'] ?? null ) || $target['index'] < 0 || $target['index'] >= 128 || isset( $seen_presentations[ $target['index'] ] ) ) {
				return array( 'error' => 'provider presentation target map contains an unsafe target.' );
			}
			$destinations = $target['destinations'] ?? self::legacy_presentation_destinations( $target );
			if ( ! self::has_only_keys( $target, array( 'index', 'control', 'label', 'destinations' ) ) || ! is_array( $destinations ) || ! array_is_list( $destinations ) || empty( $destinations ) || count( $destinations ) > 8 ) {
				return array( 'error' => 'provider presentation target map contains an unsafe target.' );
			}
			$clean = array(
				'index'        => $target['index'],
				'destinations' => array(),
			);
			foreach ( $destinations as $destination ) {
				if ( ! is_array( $destination ) || ! self::has_only_keys( $destination, array( 'role', 'selector', 'properties', 'aliases', 'resets', 'priority' ) ) || ! in_array( $destination['priority'] ?? '', array( '', 'important' ), true ) || ! in_array( $destination['role'] ?? null, array( 'control', 'label' ), true ) || ! is_string( $destination['selector'] ?? null ) || ! self::safe_selector( $destination['selector'], $map['scope'] ) || ! is_array( $destination['properties'] ?? null ) || ! array_is_list( $destination['properties'] ) || ( empty( $destination['properties'] ) && empty( $destination['resets'] ) ) || count( $destination['properties'] ) > 64 || array_diff( $destination['properties'], array_keys( self::presentation_property_map() ) ) || ! self::safe_presentation_aliases( $destination['aliases'] ?? array(), $destination['properties'] ) || ! self::safe_presentation_resets( $destination['resets'] ?? array() ) ) {
					return array( 'error' => 'provider presentation target map contains an unsafe destination.' );
				}
				$clean['destinations'][] = array_filter( array(
					'role'       => $destination['role'],
					'selector'   => $destination['selector'],
					'properties' => array_values( array_unique( $destination['properties'] ) ),
					'aliases'    => empty( $destination['aliases'] ) ? null : $destination['aliases'],
					'resets'     => empty( $destination['resets'] ) ? null : $destination['resets'],
					'priority'   => $destination['priority'] ?? null,
				), static fn( $value ): bool => null !== $value );
			}
			$seen_presentations[ $target['index'] ] = true;
			$presentation_targets[]                 = $clean;
		}
		return array(
			'map' => array(
				'schema'               => self::MAP_SCHEMA,
				'provider'             => $map['provider'],
				'scope'                => $map['scope'],
				'targets'              => $targets,
				'presentation_targets' => $presentation_targets,
			),
		);
	}

	/** @return array{overlay:array<string,mixed>,css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>} */
	public static function compile( array $graph, mixed $map, array $presentation_graph = array() ): array {
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
				$rules[]      = self::conditional_rule( $variant['condition'], $target['selector'] . '{' . implode( ';', $declarations ) . '}' );
				$operations[] = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_selector_transposition',
					'node_hash'   => hash( 'sha256', $id ),
					'target_hash' => hash( 'sha256', $target['selector'] ),
					'responsive'  => true,
				); }
		}
		$presentation_targets = array_column( $validated_map['presentation_targets'] ?? array(), null, 'index' );
		foreach ( $presentation_graph['controls'] ?? array() as $control ) {
			if ( ! is_array( $control ) || ! is_int( $control['index'] ?? null ) ) {
				continue;
			}
			$target = $presentation_targets[ $control['index'] ] ?? array();
			foreach ( array( 'control', 'label' ) as $role ) {
				if ( ! isset( $control[ $role ]['styles'] ) || ! is_array( $control[ $role ]['styles'] ) ) {
					continue;
				}
				$destinations = array_filter( $target['destinations'] ?? array(), static fn( array $destination ): bool => $role === $destination['role'] );
				if ( empty( $destinations ) ) {
					$losses[] = self::presentation_loss( 'provider_structure_mismatch', $control['index'], $role );
					continue;
				}
				self::compile_presentation_destinations( $destinations, $control[ $role ]['styles'], $control['index'], $role, null, $rules, $operations, $losses );
			}
		}
		foreach ( $presentation_graph['variants'] ?? array() as $variant ) {
			$index = $variant['index'] ?? null;
			$role  = $variant['role'] ?? null;
			// Inline SVG parts are rendered by the companion's field-state projection.
			// They retain their source precedence in the validated v2 graph but have no
			// generic provider CSS destination here.
			if ( 'visual_part' === $role ) {
				continue;
			}
			$destinations = is_int( $index ) && is_string( $role ) ? array_filter( $presentation_targets[ $index ]['destinations'] ?? array(), static fn( array $destination ): bool => $role === $destination['role'] ) : array();
			if ( ! is_int( $index ) || ! in_array( $role, array( 'control', 'label' ), true ) || empty( $destinations ) || ! self::safe_condition( $variant['condition'] ?? null ) || ! is_array( $variant['style_patch'] ?? null ) ) {
				$losses[] = self::presentation_loss( 'responsive_layout_ownership', is_int( $index ) ? $index : 0, is_string( $role ) ? $role : 'control' );
				continue;
			}
			self::compile_presentation_destinations( $destinations, $variant['style_patch'], $index, $role, $variant['condition'], $rules, $operations, $losses );
		}
		if ( empty( $losses ) ) {
			$rules[]      = $validated_map['scope'] . '{position:relative;z-index:1;pointer-events:auto}';
			$operations[] = array(
				'dimension'   => 'interaction',
				'strategy'    => 'provider_interaction_carrier',
				'target_hash' => hash( 'sha256', $validated_map['scope'] ),
			);
		}
		$css               = empty( $rules ) ? '' : '/* Static Site Importer provider layout overlay: ' . substr( hash( 'sha256', implode( "\n", $rules ) ), 0, 12 ) . " */\n" . implode( "\n", array_values( array_unique( $rules ) ) ) . "\n";
		$max_overlay_bytes = empty( $presentation_graph ) ? self::MAX_LAYOUT_OVERLAY_BYTES : self::MAX_OVERLAY_BYTES;
		if ( strlen( $css ) > $max_overlay_bytes ) {
			return array(
				'overlay'    => array(),
				'css'        => '',
				'operations' => array(),
				'losses'     => array(
					array(
						'dimension'   => 'layout',
						'reason_code' => 'provider_structure_mismatch',
						'map_error'   => 'provider layout overlay exceeds its bounded size.',
					),
				),
			);
		}
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
		if ( ! str_ends_with( $body, "\n" ) || str_contains( $body, 'url(' ) || str_contains( $body, '@import' ) ) {
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
		if ( preg_match( '/^@(?:media|container) (\((?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)\))\{(.+)\}$/D', $rule, $matches ) ) {
			return self::safe_compiled_rule( $matches[2] );
		}
		if ( ! preg_match( '/^(\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})?(?: > [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*| \.ssi-node-[a-f0-9]{12}(?:-(?:wrap|destination-[a-z][a-z0-9-]{0,31}))?(?: > \.wp-block-button__link)?)?)\{([^{}]+)\}$/D', $rule, $matches ) ) {
			return false;
		}
		$layout_allowed       = array( 'display', 'width', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area', 'position', 'z-index', 'pointer-events' );
		$presentation_allowed = array_merge( array_values( self::presentation_property_map() ), array( 'flex' ) );
		foreach ( explode( ';', $matches[2] ) as $declaration ) {
			$declaration = preg_replace( '/!important$/D', '', $declaration ) ?? $declaration;
			if ( preg_match( '/^(--[a-z][a-z0-9-]{0,79}):(.+)$/D', $declaration, $alias ) ) {
				if ( ! self::safe_presentation_value( $alias[2] ) ) {
					return false;
				}
				continue;
			}
			if ( ! preg_match( '/^([a-z-]+):(.+)$/D', $declaration, $parts ) || ( ! in_array( $parts[1], $layout_allowed, true ) && ! in_array( $parts[1], $presentation_allowed, true ) ) || ( in_array( $parts[1], $presentation_allowed, true ) ? ! self::safe_presentation_value( $parts[2] ) : ! self::safe_value( str_replace( array( 'grid-template-columns', 'grid-template-rows', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area' ), array( 'columns', 'rows', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area' ), $parts[1] ), $parts[2] ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_selector( string $selector, string $scope ): bool {
		// The bare scope is the block wrapper the provider renders at the source form's
		// own position, so it carries the form box's placement inside the page.
		// A generated node hook resolves to the control, and its provider `-wrap` copy
		// resolves to that control's field shell.
		return (bool) preg_match( '/^' . preg_quote( $scope, '/' ) . '(?: > [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*| \.ssi-node-[a-f0-9]{12}(?:-(?:wrap|destination-[a-z][a-z0-9-]{0,31}))?(?: > \.wp-block-button__link)?)?$/D', $selector );
	}
	private static function safe_condition( mixed $condition ): bool {
		if ( ! is_array( $condition ) ) {
			return false;
		}
		if ( in_array( $condition['kind'] ?? null, array( 'media', 'container' ), true ) ) {
			return array_keys( $condition ) === array( 'kind', 'query' ) && is_string( $condition['query'] ?? null ) && (bool) preg_match( '/^\((?:min|max)-(?:width|height): ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|em|rem|vw|vh)\)$/D', $condition['query'] );
		}
		return 'all' === ( $condition['kind'] ?? null ) && array_keys( $condition ) === array( 'kind', 'conditions' ) && is_array( $condition['conditions'] ) && count( $condition['conditions'] ) >= 2 && count( $condition['conditions'] ) <= 4 && array_is_list( $condition['conditions'] ) && ! array_filter( $condition['conditions'], static fn ( $part ): bool => ! self::safe_condition( $part ) );
	}
	private static function conditional_rule( array $condition, string $rule ): string {
		$conditions = 'all' === ( $condition['kind'] ?? null ) ? $condition['conditions'] : array( $condition );
		foreach ( array_reverse( $conditions ) as $part ) {
			$rule = '@' . $part['kind'] . ' ' . $part['query'] . '{' . $rule . '}';
		}
		return $rule;
	}
	private static function declarations( array $layout, array $capabilities, string $node, array &$losses ): array {
		$map          = array(
			'display'         => 'display',
			'width'           => 'width',
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
			// Source stylesheets drive these keywords through their own custom properties.
			// The overlay references the property the preserved source CSS already defines
			// rather than resolving it to a guessed keyword, exactly as lengths already do.
			$keyword = '(?:flex|grid|block|inline-flex|row|row-reverse|column|column-reverse|wrap|nowrap|wrap-reverse|flex-start|flex-end|center|stretch|baseline|space-between|space-around|space-evenly|start|end)';
			return (bool) preg_match( '/^(?:' . $keyword . '|var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}(?:, ?' . $keyword . ')?\))$/D', $value );
		}
		if ( in_array( $fact, array( 'order', 'flex_grow', 'flex_shrink' ), true ) ) {
			return (bool) preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/D', $value );
		}
		if ( 'flex' === $fact ) {
			return (bool) preg_match( '/^(?:none|(?:[0-9]+(?:\.[0-9]+)?)(?: [0-9]+(?:\.[0-9]+)?)? (?:auto|0|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh)))$/D', $value );
		}
		if ( 'position' === $fact ) {
			return 'relative' === $value;
		}
		if ( 'z-index' === $fact ) {
			return '1' === $value;
		}
		if ( 'pointer-events' === $fact ) {
			return 'auto' === $value;
		}
		if ( in_array( $fact, array( 'column', 'row' ), true ) ) {
			return (bool) preg_match( '/^(?:auto|[1-9][0-9]*|span [1-9][0-9]*) \/ (?:auto|[1-9][0-9]*|span [1-9][0-9]*)$/D', $value );
		}
		if ( 'area' === $fact ) {
			return (bool) preg_match( '/^(?:auto|[1-9][0-9]*|span [1-9][0-9]*)(?: \/ (?:auto|[1-9][0-9]*|span [1-9][0-9]*)){3}$/D', $value );
		}
		return (bool) preg_match( '/^(?:var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}(?:, ?(?:0|[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh)))?\)|auto|none|0|span [1-9][0-9]*|[1-9][0-9]*|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)|minmax\((?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr), ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)\)|repeat\([1-9][0-9]*, ?(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)\))+(?: \/ [1-9][0-9]*)?$/D', $value );
	}
	private static function loss( string $reason, string $node ): array { return array(
		'dimension'   => 'layout',
		'reason_code' => $reason,
		'node_hash'   => hash( 'sha256', $node ),
	); }

	/** @return array<string,string> */
	private static function presentation_property_map(): array {
		$keys = array( 'appearance', 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius', 'box_sizing', 'color', 'display', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'height', 'letter_spacing', 'line_height', 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'max_width', 'min_height', 'min_width', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end', 'text_align', 'text_decoration', 'text_indent', 'text_transform', 'vertical_align', 'width', 'flex_shrink', 'position', 'transform' );
		return array_combine( $keys, array_map( static fn( string $key ): string => str_replace( '_', '-', $key ), $keys ) );
	}

	/** Adapter maps may select only these captured presentation properties. */
	public static function presentation_property_keys(): array {
		return array_diff_key( self::presentation_property_map(), array_flip( array( 'flex_shrink', 'position', 'transform' ) ) );
	}

	/** Additional properties are valid only for explicitly declared positioned controls. */
	public static function positioned_control_presentation_property_keys(): array {
		return self::presentation_property_map();
	}

	private static function presentation_declarations( array $styles, int $index, string $role, array &$losses, array $properties = array(), array $aliases = array() ): array {
		$map          = self::presentation_property_map();
		$declarations = array();
		foreach ( $styles as $key => $value ) {
			if ( ! isset( $map[ $key ] ) || ! self::safe_presentation_value( $value ) ) {
				$losses[] = self::presentation_loss( 'unsafe_presentation_value', $index, $role );
				continue;
			}
			if ( ! in_array( $key, $properties, true ) ) {
				continue;
			}
			$declarations[] = $map[ $key ] . ':' . $value;
			if ( isset( $aliases[ $key ] ) ) {
				$declarations[] = $aliases[ $key ] . ':' . $value;
			}
		}
		return $declarations;
	}

	/** Compile one adapter-owned destination without knowing its provider or markup. */
	private static function compile_presentation_destinations( array $destinations, array $styles, int $index, string $role, ?array $condition, array &$rules, array &$operations, array &$losses ): void {
		$represented = array();
		foreach ( $destinations as $destination ) {
			$represented  = array_merge( $represented, $destination['properties'] );
			$declarations = self::presentation_declarations( $styles, $index, $role, $losses, $destination['properties'], $destination['aliases'] ?? array() );
			foreach ( $destination['resets'] ?? array() as $property => $value ) {
				$declarations[] = $property . ':' . $value;
			}
			if ( empty( $declarations ) ) {
				continue;
			}
			if ( 'important' === ( $destination['priority'] ?? '' ) ) {
				$declarations = array_map( static fn( string $declaration ): string => $declaration . '!important', $declarations );
			}
			$rule         = self::authoritative_presentation_selector( $destination['selector'] ) . '{' . implode( ';', $declarations ) . '}';
			$rules[]      = null === $condition ? $rule : self::conditional_rule( $condition, $rule );
			$operations[] = self::presentation_operation( $index, $role, $destination['selector'], null !== $condition );
		}
		if ( array_diff( array_keys( $styles ), $represented ) ) {
			$losses[] = self::presentation_loss( 'provider_structure_mismatch', $index, $role );
		}
	}

	/** Normalize maps produced before destination maps were introduced. */
	private static function legacy_presentation_destinations( array $target ): array {
		$destinations = array();
		foreach ( array( 'control', 'label' ) as $role ) {
			if ( is_string( $target[ $role ] ?? null ) ) {
				$destinations[] = array(
					'role'       => $role,
					'selector'   => $target[ $role ],
					'properties' => array_keys( self::presentation_property_map() ),
				);
			}
		}
		return $destinations;
	}

	private static function safe_presentation_aliases( mixed $aliases, array $properties ): bool {
		if ( ! is_array( $aliases ) || ! self::has_only_keys( $aliases, $properties ) ) {
			return false;
		}
		foreach ( $aliases as $property => $alias ) {
			if ( ! is_string( $property ) || ! is_string( $alias ) || ! preg_match( '/^--[a-z][a-z0-9-]{0,79}$/D', $alias ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_presentation_resets( mixed $resets ): bool {
		if ( ! is_array( $resets ) || ! self::has_only_keys( $resets, array( 'flex', 'min-width', 'padding', 'border', 'background' ) ) ) {
			return false;
		}
		foreach ( $resets as $property => $value ) {
			if ( ! is_string( $property ) || ! self::safe_presentation_value( $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function authoritative_presentation_selector( string $selector ): string {
		return preg_replace( '/^(\.ssi-form-[a-f0-9]{12})/', '$1$1', $selector, 1 ) ?? $selector;
	}

	private static function safe_presentation_value( mixed $value ): bool {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 160 && ! preg_match( '/(?:url\(|@import|[;{}\\\\]|!important|expression\(|javascript:)/i', $value ) && (bool) preg_match( "~^[a-zA-Z0-9_#%.,()\\s+\\-*/'\"]+$~D", $value );
	}

	private static function presentation_operation( int $index, string $role, string $target, bool $responsive ): array {
		return array_filter( array(
			'dimension'   => 'presentation',
			'strategy'    => 'provider_presentation_transposition',
			'node_hash'   => hash( 'sha256', 'control-' . $index . ':' . $role ),
			'target_hash' => hash( 'sha256', $target ),
			'responsive'  => $responsive ? true : null,
		), static fn( $value ): bool => null !== $value );
	}

	private static function presentation_loss( string $reason, int $index, string $role ): array {
		return array(
			'dimension'   => 'presentation',
			'reason_code' => $reason,
			'node_hash'   => hash( 'sha256', 'control-' . $index . ':' . $role ),
		);
	}

	private static function has_only_keys( array $value, array $keys ): bool {
		return ! array_diff( array_keys( $value ), $keys );
	}
}
