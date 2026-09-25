<?php
/**
 * Topology and layout projection for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-layout-overlay.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Field_Markup' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-field-markup.php';
}

/**
 * Projects source form topology onto provider layout overlays.
 */
final class Static_Site_Importer_Form_Layout_Projection {
	/** @return array<int,array{control:int,layout:array<string,string>,condition?:array<string,mixed>}> */
	public static function direct_label_control_gap_targets( array $form ): array {
		$relations = $form['sibling_relations'] ?? null;
		$graph     = $form['layout_graph'] ?? null;
		if ( ! is_array( $relations ) || 'generic/form-sibling-relations/v1' !== ( $relations['schema'] ?? null ) || true === ( $relations['truncated'] ?? false ) || ! is_array( $relations['pairs'] ?? null ) || ! is_array( $graph['nodes'] ?? null ) ) {
			return array();
		}
		$form_node = current( array_filter( $graph['nodes'], static fn( $node ): bool => is_array( $node ) && 'form' === ( $node['id'] ?? null ) ) );
		$targets   = array();
		$plans     = array(
			array(
				'layout'     => $form_node['layout'] ?? array(),
				'condition'  => null,
				'provenance' => $form_node['provenance'] ?? array(),
			),
		);
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && 'form' === ( $variant['node'] ?? null ) ) {
				$plans[] = array(
					'layout'     => $variant['layout_patch'] ?? array(),
					'condition'  => $variant['condition'] ?? null,
					'provenance' => $variant['provenance'] ?? array(),
				);
			}
		}
		foreach ( $plans as $plan ) {
			$layout   = is_array( $plan['layout'] ) ? $plan['layout'] : array();
			$property = isset( $layout['row_gap'] ) ? 'row-gap' : ( isset( $layout['gap'] ) ? 'gap' : '' );
			$gap      = '' !== $property ? $layout[ str_replace( '-', '_', $property ) ] : null;
			if ( 'flex' !== ( $layout['display'] ?? null ) || 'column' !== ( $layout['direction'] ?? null ) || ! is_string( $gap ) || '' === trim( $gap ) ) {
				continue;
			}
			$proven = array_filter( $plan['provenance'], static fn( $fact ): bool => is_array( $fact ) && in_array( 'display', $fact['properties'], true ) && in_array( 'flex-direction', $fact['properties'], true ) && in_array( $property, $fact['properties'], true ) );
			if ( empty( $proven ) ) {
				continue;
			}
			foreach ( $relations['pairs'] as $pair ) {
				if ( is_array( $pair ) && is_int( $pair['control'] ?? null ) ) {
					$targets[] = array_filter( array(
						'control'   => $pair['control'],
						'layout'    => array(
							'display'   => 'flex',
							'direction' => 'column',
							'gap'       => $gap,
						),
						'condition' => $plan['condition'],
					), static fn( $value ): bool => null !== $value );
				}
			}
		}
		return $targets;
	}

	/**
	 * Canonicalize the CSS identity condition before provider planning.
	 *
	 * A producer may retain a stylesheet's implicit `media="all"` wrapper as a
	 * graph variant. It has no responsive behavior, so an unconflicted patch can
	 * safely become a base fact. Conflicting values remain variants and continue
	 * through the existing fail-closed receipt path.
	 */
	public static function normalize_unconditional_layout_variants( array $form ): array {
		$graph = $form['layout_graph'] ?? null;
		if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['variants'] ?? null ) ) {
			return $form;
		}

		$nodes = array();
		foreach ( $graph['nodes'] as $index => $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) && is_array( $node['layout'] ?? null ) ) {
				$nodes[ $node['id'] ] = $index;
			}
		}
		$variants = array();
		foreach ( $graph['variants'] as $variant ) {
			$node_id = is_array( $variant ) ? $variant['node'] ?? null : null;
			$patch   = is_array( $variant ) && is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : null;
			if ( ! is_string( $node_id ) || ! isset( $nodes[ $node_id ] ) || ! is_array( $patch ) || ! self::is_unconditional_media_variant( $variant ) ) {
				$variants[] = $variant;
				continue;
			}
			$node_index = $nodes[ $node_id ];
			$layout     = $graph['nodes'][ $node_index ]['layout'];
			if ( array_intersect_key( $layout, $patch ) && array_diff_assoc( array_intersect_key( $layout, $patch ), $patch ) ) {
				$variants[] = $variant;
				continue;
			}
			$graph['nodes'][ $node_index ]['layout'] = array_merge( $layout, $patch );
			foreach ( $variant['provenance'] ?? array() as $fact ) {
				if ( ! is_array( $fact ) ) {
					continue;
				}
				$fact['condition']                             = null;
				$graph['nodes'][ $node_index ]['provenance'][] = $fact;
			}
		}
		$graph['variants']    = $variants;
		$form['layout_graph'] = $graph;
		return $form;
	}

	private static function is_unconditional_media_variant( mixed $variant ): bool {
		return is_array( $variant ) && array(
			'kind'  => 'media',
			'query' => 'all',
		) === ( $variant['condition'] ?? null );
	}

	/**
	 * Report whether a source control carries content the provider is expected to represent.
	 *
	 * Hidden inputs carry the source platform's form-handler plumbing, such as endpoint
	 * identifiers and captcha tokens, rather than content an author wrote or a visitor sees.
	 * A provider form supersedes that plumbing, so leaving hidden inputs behind is the intended
	 * conversion rather than a fidelity loss.
	 *
	 * @param string $type Normalized source control type.
	 */
	private static function control_carries_authored_content( string $type ): bool {
		return 'hidden' !== $type;
	}

	/** Accept only a non-nested root fieldset that contains every mapped provider control. */
	public static function projectable_plain_root_fieldset( array $fieldset, array $nodes, array $field_blocks ): bool {
		if ( 'wrapper' !== ( $fieldset['kind'] ?? null ) || 'fieldset' !== ( $fieldset['tag'] ?? null ) || 'plain_group' !== ( $fieldset['fieldset_semantics'] ?? null ) || null !== ( $fieldset['parent'] ?? null ) || ! is_string( $fieldset['id'] ?? null ) || empty( $field_blocks ) ) {
			return false;
		}
		$nodes_by_id     = array();
		$controls_by_key = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return false;
			}
			$nodes_by_id[ $node['id'] ] = $node;
			if ( 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) ) {
				$controls_by_key[ $node['control'] ] = $node;
			}
		}
		foreach ( $nodes as $node ) {
			if ( 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || ( $node['id'] ?? null ) === $fieldset['id'] ) {
				continue;
			}
			$parent = $node['parent'] ?? null;
			while ( is_string( $parent ) ) {
				if ( $fieldset['id'] === $parent ) {
					return false;
				}
				$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
			}
		}
		foreach ( array_keys( $field_blocks ) as $control_index ) {
			$parent = $controls_by_key[ $control_index ]['parent'] ?? null;
			while ( is_string( $parent ) && $fieldset['id'] !== $parent ) {
				$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
			}
			if ( $fieldset['id'] !== $parent ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Flatten the validated generic tree into Jetpack's constrained direct-child
	 * grammar. Equal-fraction field-row grids (2/3/4 columns, any number of
	 * occupying fields) and complete provenance-backed percentage rows map to
	 * provider field widths; other wrapper semantics/layout remain explicit
	 * receipt losses.
	 *
	 * @param array<int,array<string,mixed>> $field_blocks
	 * @param array<int,array<string,mixed>> $controls
	 * @return array{blocks:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>,represented_layout_nodes:array<int,string>,represented_topology_nodes:array<int,string>,suppressed_layout_properties:array<string,array<int,string>>,overlay_node_targets:array<int,array<string,mixed>>,responsive_variant_targets:array<int,array<string,mixed>>,native_visibility_targets:array<int,string>,form_classes:array<int,string>,provider_layout_targets:array<string,string>,phone_popup_targets:array<int,int>}|null
	 */
	public static function topology_inner_blocks( array $form, array $field_blocks, array $controls, array $suppressed_controls = array() ): ?array {
		if ( ! isset( $form['control_topology'] ) ) {
			$derived_topology = self::derive_control_topology_from_layout_graph( $form, $controls );
			if ( null !== $derived_topology ) {
				$form['control_topology'] = $derived_topology;
			} else {
				return array(
					'blocks'                       => array_values( $field_blocks ),
					'losses'                       => array(),
					'operations'                   => array(),
					'represented_layout_nodes'     => array(),
					'represented_topology_nodes'   => array(),
					'suppressed_layout_properties' => array(),
					'overlay_node_targets'         => array(),
					'responsive_variant_targets'   => array(),
					'native_visibility_targets'    => array(),
					'form_classes'                 => array(),
					'provider_layout_targets'      => array(),
					'phone_popup_targets'          => array(),
				);
			}
		}
		$nodes = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $nodes ) ) {
			return null;
		}
		$children             = array( '$root' => array() );
		$topology_nodes_by_id = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return null;
			}
			$parent                              = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$children[ $parent ][]               = $node;
			$topology_nodes_by_id[ $node['id'] ] = $node;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, static fn ( array $left, array $right ): int => $left['order'] <=> $right['order'] );
		}
		unset( $siblings );
		$control_parents  = array();
		$topology_parents = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$parent                          = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$topology_parents[ $node['id'] ] = $parent;
			if ( 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) ) {
				$control_parents[ $node['control'] ] = $parent;
			}
		}
		$provider_controls        = array();
		$auxiliary_popup_controls = array();
		$phone_popup_targets      = array();
		$shares_phone_group       = static function ( int $popup_control, int $phone_control ) use ( $control_parents, $topology_parents ): bool {
			$popup_parent = $control_parents[ $popup_control ] ?? null;
			$phone_parent = $control_parents[ $phone_control ] ?? null;
			if ( ! is_string( $popup_parent ) || ! is_string( $phone_parent ) || '$root' === $phone_parent ) {
				return false;
			}
			for ( $depth = 0; $depth < 16 && '$root' !== $popup_parent; ++$depth ) {
				if ( $phone_parent === $popup_parent ) {
					return true;
				}
				$popup_parent = $topology_parents[ $popup_parent ] ?? '$root';
			}
			return false;
		};
		foreach ( $controls as $control_index => $control ) {
			if ( ! in_array( strtolower( trim( (string) ( $control['type'] ?? '' ) ) ), array( 'phone', 'tel' ), true ) ) {
				$type      = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
				$tag       = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
				$popup     = strtolower( trim( (string) ( $control['aria_haspopup'] ?? '' ) ) );
				$described = preg_split( '/\s+/', trim( (string) ( $control['aria_describedby'] ?? '' ) ) );
				if ( 'button' !== $tag || 'button' !== $type || ! in_array( $popup, array( 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog' ), true ) || false === $described || empty( $described ) ) {
					continue;
				}
				foreach ( $controls as $field_index => $field ) {
					$label_id = trim( (string) ( $field['label_id'] ?? '' ) );
					if ( $field_index === $control_index || empty( $field['readonly'] ) || '' === $label_id || ! in_array( $label_id, $described, true ) || ( $control_parents[ $field_index ] ?? null ) !== ( $control_parents[ $control_index ] ?? null ) ) {
						continue;
					}
					$provider_controls[ $control_index ]        = true;
					$auxiliary_popup_controls[ $control_index ] = true;
					break;
				}
				continue;
			}
			$previous = $controls[ $control_index - 1 ] ?? null;
			if ( is_array( $previous ) && Static_Site_Importer_Form_Field_Markup::is_provider_auxiliary_button( $controls, $control_index - 1 ) && $shares_phone_group( $control_index - 1, $control_index ) ) {
				$provider_controls[ $control_index - 1 ]        = true;
				$phone_popup_targets[ $control_index - 1 ]      = $control_index;
				$auxiliary_popup_controls[ $control_index - 1 ] = true;
			}
		}
		$losses                     = array();
		$operations                 = array();
		$represented_layout_nodes   = array();
		$represented_topology_nodes = array();
		/** @var array<string,array<int,string>> $suppressed_layout_properties */
		$suppressed_layout_properties = array();
		$overlay_node_targets         = array();
		$responsive_variant_targets   = array();
		$native_visibility_targets    = array();
		$wrapper_hooks                = array();
		$provider_layout_targets      = array();
		$overlay_represented_nodes    = array();
		$layout_by_node               = array();
		$layout_nodes_by_id           = array();
		$variants_by_node             = array();
		$form_classes                 = array();
		foreach ( array_keys( $auxiliary_popup_controls ) as $control_index ) {
			$operations[] = array(
				'dimension'   => 'topology',
				'strategy'    => 'provider_auxiliary_popup_control',
				'target_hash' => hash( 'sha256', 'control-' . $control_index ),
			);
		}
		foreach ( $form['layout_graph']['nodes'] ?? array() as $layout_node ) {
			if ( is_array( $layout_node ) && is_string( $layout_node['id'] ?? null ) ) {
				$layout_by_node[ $layout_node['id'] ]     = is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
				$layout_nodes_by_id[ $layout_node['id'] ] = $layout_node;
			}
		}
		foreach ( $form['layout_graph']['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_string( $variant['node'] ?? null ) ) {
				$variants_by_node[ $variant['node'] ][] = $variant;
			}
		}
		$exact_native_tree = self::exact_native_div_topology( $nodes, $children, $field_blocks, $suppressed_controls, $layout_nodes_by_id, $layout_by_node, $variants_by_node, self::layout_scope( $form ) );
		if ( null !== $exact_native_tree ) {
			return $exact_native_tree;
		}
		$collect_controls   = static function ( array $node, bool $include_auxiliary = true ) use ( &$collect_controls, $children, $provider_controls, $phone_popup_targets, $suppressed_controls ): array {
			if ( 'control' === ( $node['kind'] ?? null ) ) {
				$control_index = $node['control'] ?? null;
				if ( $include_auxiliary && is_int( $control_index ) && isset( $phone_popup_targets[ $control_index ] ) && ! isset( $suppressed_controls[ $phone_popup_targets[ $control_index ] ] ) ) {
					return array( $phone_popup_targets[ $control_index ] );
				}
				return is_int( $control_index ) && ! isset( $provider_controls[ $control_index ] ) && ! isset( $suppressed_controls[ $control_index ] ) ? array( $control_index ) : array();
			}
			$controls = array();
			foreach ( $children[ $node['id'] ?? '' ] ?? array() as $child ) {
				$controls = array_merge( $controls, $collect_controls( $child, $include_auxiliary ) );
			}
			return array_values( array_unique( $controls ) );
		};
		$wrapper_chains     = array();
		$compound_ancestors = array();
		foreach ( $phone_popup_targets as $auxiliary => $primary ) {
			$parent = $control_parents[ $auxiliary ] ?? '$root';
			for ( $depth = 0; $depth < 16 && '$root' !== $parent; ++$depth ) {
				$compound_ancestors[ $parent ][ $primary ] = true;
				$parent                                    = $topology_parents[ $parent ] ?? '$root';
			}
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			// Provider ownership does not imply DOM containment: a prefix's wrappers
			// must not be restored around the primary value input.
			$branch_controls = $collect_controls( $node, false );
			if ( empty( $branch_controls ) ) {
				$branch_controls          = $collect_controls( $node );
				$node['destination_role'] = 'prefix';
			}
			if ( 1 !== count( $branch_controls ) ) {
				continue;
			}
			$control_index = $branch_controls[0];
			if ( isset( $compound_ancestors[ $node['id'] ][ $control_index ] ) && ! isset( $node['destination_role'] ) ) {
				$node['destination_role'] = 'shell';
			}
			$source_class                 = trim( (string) ( $node['class'] ?? '' ) );
			$is_projectable_classless_box = '' === $source_class
				&& in_array( $node['tag'] ?? '', array( 'div', 'span' ), true )
				&& ( ! empty( $layout_by_node[ $node['id'] ] ?? array() ) || ! empty( $variants_by_node[ $node['id'] ] ?? array() ) );
			if ( ! is_int( $control_index ) || ! isset( $field_blocks[ $control_index ] ) || ( '' === $source_class && ! $is_projectable_classless_box ) ) {
				continue;
			}
			$wrapper_chains[ $control_index ][] = $node;
		}
		foreach ( $wrapper_chains as $control_index => $chain ) {
			usort( $chain, static fn ( array $left, array $right ): int => (int) ( $left['depth'] ?? 0 ) <=> (int) ( $right['depth'] ?? 0 ) );
			$class_names = array( (string) ( $field_blocks[ $control_index ]['attrs']['className'] ?? '' ) );
			// A button control is rendered by Core, which carries the source box as its
			// own block element and has no provider field shell to rebuild layers inside.
			if ( 'core/button' === ( $field_blocks[ $control_index ]['name'] ?? '' ) ) {
				$outermost                         = $chain[0];
				$button_hook                       = self::layout_node_class( self::layout_scope( $form ), $outermost['id'] );
				$class_names[]                     = $button_hook;
				$wrapper_hooks[ $outermost['id'] ] = $button_hook;

				$field_blocks[ $control_index ]['attrs']['className'] = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $class_names ) ) ) );

				$operations[] = array(
					'dimension'   => 'topology',
					'strategy'    => 'provider_field_wrapper_class_projection',
					'target_hash' => hash( 'sha256', $outermost['id'] ),
				);
				continue;
			}
			foreach ( $chain as $offset => $node ) {
				$generated_class = self::layout_node_class( self::layout_scope( $form ), $node['id'] );
				$wrapper_classes = preg_split( '/\s+/', trim( (string) ( $node['class'] ?? '' ) ) );
				if ( false === $wrapper_classes ) {
					$wrapper_classes = array();
				}
				$wrapper_classes = array_values( array_filter( $wrapper_classes ) );
				// The outermost source box is the provider's own field shell, and the
				// runtime rebuilds every deeper box as its own element. Giving each box
				// its own hook keeps one source element addressable by one target instead
				// of collapsing a nested chain onto a single element.
				$is_primary_wrapper = ! isset( $node['destination_role'] );
				if ( 0 === $offset && $is_primary_wrapper ) {
					$class_names[] = $generated_class;
				} else {
					$wrapper_classes[] = $generated_class;
				}
				$layer = min( 99, max( 0, (int) ( $node['depth'] ?? 0 ) ) );
				// The suffix is an explicit runtime projection contract: these classes
				// describe wrapper layers, not provider block classes. The runtime consumes
				// them only inside a provider field shell and rebuilds the layer at the
				// native control, leaving source classes out of persisted block markup.
				// Jetpack derives its field-shell classes by adding `-wrap`. Keep the
				// transport marker unsuffixed so that derivation leaves one recognizable
				// suffix rather than making it part of the restored source class.
				// A classless source box can still have proven inline layout. Transport its
				// generated hook so the runtime can restore a concrete box for the overlay.
				if ( empty( $wrapper_classes ) ) {
					$wrapper_classes[] = $generated_class;
				}
				$wrapper_role                 = $is_primary_wrapper ? '' : $node['destination_role'] . '-';
				$class_names[]                = implode( ' ', array_map( static fn ( string $class_name ): string => 'ssi-source-wrapper-' . $wrapper_role . $layer . '--' . $class_name, $wrapper_classes ) );
				$wrapper_hooks[ $node['id'] ] = 0 === $offset && $is_primary_wrapper ? $generated_class . '-wrap' : $generated_class;
				$operations[]                 = array(
					'dimension'   => 'topology',
					'strategy'    => 'provider_field_wrapper_class_projection',
					'target_hash' => hash( 'sha256', $node['id'] ),
				);
				// A source box whose own stylesheet addresses it by class keeps its layout
				// through the projected classes, so it needs no generated overlay target.
				$class_tokens = preg_split( '/\s+/', trim( (string) ( $node['class'] ?? '' ) ) );
				if ( false === $class_tokens ) {
					$class_tokens = array();
				}
				$class_tokens = array_values( array_filter( $class_tokens ) );
				$provenance   = $layout_nodes_by_id[ $node['id'] ]['provenance'] ?? array();
				$class_owned  = ! empty( $layout_by_node[ $node['id'] ] ?? array() ) && ! empty( $provenance );
				foreach ( $provenance as $provenance_row ) {
					$selector      = is_array( $provenance_row ) && is_string( $provenance_row['selector'] ?? null ) ? $provenance_row['selector'] : '';
					$matches_class = false;
					if ( preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector ) ) {
						foreach ( $class_tokens as $class_token ) {
							if ( preg_match( '/\.' . preg_quote( $class_token, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) {
								$matches_class = true;
								break;
							}
						}
					}
					if ( ! $matches_class ) {
						$class_owned = false;
						break;
					}
				}
				if ( $class_owned ) {
					$represented_layout_nodes[] = $node['id'];
				}
			}
			$field_blocks[ $control_index ]['attrs']['className'] = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $class_names ) ) ) );
		}
		// A source row that holds several boxes is a band of columns. The provider
		// states that relationship with its own field width, so the boxes sit side
		// by side there instead of stacking one per row.
		$topology_nodes_by_id = array();
		foreach ( $nodes as $topology_node ) {
			if ( is_array( $topology_node ) && is_string( $topology_node['id'] ?? null ) ) {
				$topology_nodes_by_id[ $topology_node['id'] ] = $topology_node;
			}
		}
		foreach ( self::source_grid_row_bands( $layout_nodes_by_id, $variants_by_node ) as $band ) {
			$members = array();
			foreach ( $band as $node_id => $width ) {
				$node = $topology_nodes_by_id[ $node_id ] ?? null;
				if ( ! is_array( $node ) ) {
					continue 2;
				}
				$branch = array_values( array_filter( $collect_controls( $node ), static fn ( int $index ): bool => isset( $field_blocks[ $index ] ) ) );
				if ( 1 !== count( $branch ) || 'core/button' === ( $field_blocks[ $branch[0] ]['name'] ?? '' ) ) {
					continue 2;
				}
				$members[ $branch[0] ] = $width;
			}
			$total = array_sum( $members );
			if ( count( $members ) < 2 || $total <= 0 ) {
				continue;
			}
			foreach ( $members as $control_index => $width ) {
				$field_blocks[ $control_index ]['attrs']['width'] = self::provider_field_width( $width / $total );
				$operations[]                                     = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_row_band_field_width',
					'target_hash' => hash( 'sha256', (string) $control_index ),
				);
			}
		}
		// Jetpack fields own their editable label/control pair. A source paragraph
		// around exactly that pair can be restored at render time without claiming
		// that an arbitrary semantic wrapper is a Gutenberg group.
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'p' !== ( $node['tag'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$branch_controls = array_values( array_filter( $collect_controls( $node ), static fn ( int $index ): bool => isset( $field_blocks[ $index ] ) ) );
			if ( 1 !== count( $branch_controls ) ) {
				continue;
			}
			$control_index = $branch_controls[0];
			$classes       = preg_split( '/\s+/', trim( (string) ( $node['class'] ?? '' ) ) );
			$classes       = false === $classes ? array() : $classes;
			$markers       = array( 'ssi-source-semantic-wrapper-' . min( 99, max( 0, (int) $node['depth'] ) ) . '--p' );
			foreach ( $classes as $class ) {
				$markers[] = 'ssi-source-semantic-wrapper-' . min( 99, max( 0, (int) $node['depth'] ) ) . '--p--' . $class;
			}
			$field_blocks[ $control_index ]['attrs']['className'] = trim( implode( ' ', array_filter( array_merge( array( (string) ( $field_blocks[ $control_index ]['attrs']['className'] ?? '' ) ), $markers ) ) ) );
			$represented_topology_nodes[]                         = $node['id'];
			$operations[] = array(
				'dimension'   => 'topology',
				'strategy'    => 'provider_paragraph_wrapper_projection',
				'target_hash' => hash( 'sha256', $node['id'] ),
			);
		}
		// A nested labelled fieldset around one to four mapped text fields is the
		// source Name/phone group. Producers often omit legend text while still
		// classifying the wrapper as labelled_group. Jetpack keeps the fields.
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'labelled_group' !== ( $node['fieldset_semantics'] ?? null ) || null === ( $node['parent'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$branch_controls = $collect_controls( $node );
			$branch_fields   = array();
			$unmapped        = false;
			foreach ( $branch_controls as $control_index ) {
				if ( isset( $suppressed_controls[ $control_index ] ) ) {
					continue;
				}
				if ( ! isset( $field_blocks[ $control_index ] ) || 'core/button' === ( $field_blocks[ $control_index ]['name'] ?? '' ) ) {
					$unmapped = true;
					break;
				}
				$type = strtolower( trim( (string) ( $controls[ $control_index ]['type'] ?? $controls[ $control_index ]['tag'] ?? '' ) ) );
				if ( 'radio' === $type || ! in_array( $type, array( '', 'text', 'email', 'tel', 'phone', 'number', 'url' ), true ) ) {
					$unmapped = true;
					break;
				}
				$branch_fields[] = $control_index;
			}
			if ( $unmapped || ! in_array( count( $branch_fields ), array( 1, 2, 3, 4 ), true ) ) {
				continue;
			}
			$represented_topology_nodes[] = $node['id'];
			$represented_layout_nodes[]   = $node['id'];
			$operations[]                 = array(
				'dimension'     => 'semantic',
				'strategy'      => 'provider_labelled_text_fieldset_projection',
				'target_hash'   => hash( 'sha256', $node['id'] ),
				'control_count' => count( $branch_fields ),
			);
		}
		$mapped_controls = array_keys( $field_blocks );
		sort( $mapped_controls );
		// Jetpack owns the form and its handler nodes, but a plain root fieldset that
		// contains every mapped control can be restored around only its field list.
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'plain_group' !== ( $node['fieldset_semantics'] ?? null ) || null !== ( $node['parent'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			if ( ! self::projectable_plain_root_fieldset( $node, $nodes, $field_blocks ) ) {
				continue;
			}
			$class_tokens                 = preg_split( '/\s+/', trim( (string) ( $node['class'] ?? '' ) ) );
			$class_tokens                 = false === $class_tokens ? array() : array_values( array_filter( $class_tokens, static fn( string $class_name ): bool => 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ) );
			$form_classes                 = array_merge( $form_classes, array( 'ssi-source-root-fieldset' ), array_map( static fn( string $class_name ): string => 'ssi-source-root-fieldset--' . $class_name, array_slice( array_values( array_unique( $class_tokens ) ), 0, 8 ) ) );
			$represented_topology_nodes[] = $node['id'];
			$operations[]                 = array(
				'dimension'   => 'topology',
				'strategy'    => 'provider_plain_root_fieldset_projection',
				'target_hash' => hash( 'sha256', $node['id'] ),
			);
			break;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || '' === trim( (string) ( $node['class'] ?? '' ) ) ) {
				continue;
			}
			if ( 'fieldset' === ( $node['tag'] ?? null ) && 'plain_group' === ( $node['fieldset_semantics'] ?? null ) && null === ( $node['parent'] ?? null ) ) {
				continue;
			}
			$branch_controls = $collect_controls( $node );
			sort( $branch_controls );
			if ( $mapped_controls !== $branch_controls || count( $children[ $node['id'] ] ?? array() ) < 2 ) {
				continue;
			}
			$source_class = trim( (string) $node['class'] );
			$class_tokens = preg_split( '/\s+/', $source_class );
			if ( false === $class_tokens ) {
				$class_tokens = array();
			}
			$facts       = array_merge( $layout_nodes_by_id[ $node['id'] ]['provenance'] ?? array(), ...array_map( static fn ( array $variant ): array => is_array( $variant['provenance'] ?? null ) ? $variant['provenance'] : array(), $variants_by_node[ $node['id'] ] ?? array() ) );
			$class_owned = ! empty( $facts );
			foreach ( $facts as $fact ) {
				$selector = is_array( $fact ) && is_string( $fact['selector'] ?? null ) ? $fact['selector'] : '';
				if ( ! preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector ) || ! array_filter( $class_tokens, static fn ( string $class_name ): bool => '' !== $class_name && (bool) preg_match( '/\.' . preg_quote( $class_name, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) ) {
					$class_owned = false;
					break;
				}
			}
			if ( ! $class_owned && null === self::display_from_class_tokens( $class_tokens ) ) {
				continue;
			}
			$form_classes                 = array_merge( $form_classes, $class_tokens );
			$represented_layout_nodes[]   = $node['id'];
			$represented_topology_nodes[] = $node['id'];
			$operations[]                 = array(
				'dimension'   => 'topology',
				'strategy'    => 'provider_field_list_class_projection',
				'target_hash' => hash( 'sha256', $node['id'] ),
			);
			break;
		}
		$topology_by_id = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$topology_by_id[ $node['id'] ] = $node;
			}
		}
		$has_unconditional_proven_property = static function ( array $node, string $property ): bool {
			foreach ( $node['provenance'] ?? array() as $fact ) {
				if ( is_array( $fact ) && null === ( $fact['condition'] ?? null ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && in_array( $property, $fact['properties'] ?? array(), true ) ) {
					return true;
				}
			}
			return false;
		};
		$safe_percentage_variants          = static function ( array $variants ): bool {
			foreach ( $variants as $variant ) {
				$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				$width = $patch['width'] ?? null;
				if ( ! is_array( $variant ) || ! is_array( $variant['condition'] ?? null ) || 'media' !== ( $variant['condition']['kind'] ?? null ) || ! is_string( $variant['condition']['query'] ?? null ) || 1 !== preg_match( '/^\((?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)\)$/D', $variant['condition']['query'] ) || ! is_string( $width ) || 1 !== preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) || ( array( 'width' ) !== array_keys( $patch ) && array( 'display', 'width' ) !== array_keys( $patch ) ) || ( isset( $patch['display'] ) && 'block' !== $patch['display'] ) ) {
					return false;
				}

				$proven = false;
				foreach ( $variant['provenance'] ?? array() as $fact ) {
					if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === $variant['condition'] && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && ! array_diff( array_keys( $patch ), $fact['properties'] ?? array() ) ) {
						$proven = true;
						break;
					}
				}
				if ( ! $proven ) {
					return false;
				}
			}

			return true;
		};

		$grid_span_width       = static function ( mixed $columns, mixed $column ): ?string {
			$columns = preg_replace( '/\s+/', '', is_string( $columns ) ? $columns : '' );
			$column  = preg_replace( '/\s+/', '', is_string( $column ) ? $column : '' );
			if ( ! is_string( $columns ) || ! is_string( $column ) || ! preg_match( '/^repeat\(([1-9][0-9]*),1fr\)$/D', $columns, $column_count ) ) {
				return null;
			}
			if ( '1/-1' === $column ) {
				return '100%';
			}
			if ( ! preg_match( '/^(?:1\/)?span([1-9][0-9]*)$/D', $column, $span ) || (int) $span[1] > (int) $column_count[1] ) {
				return null;
			}
			return rtrim( rtrim( number_format( 100 * (int) $span[1] / (int) $column_count[1], 3, '.', '' ), '0' ), '.' ) . '%';
		};
		$grid_area_column_span = static function ( mixed $area ): ?string {
			$area = is_string( $area ) ? trim( $area ) : '';
			if ( ! preg_match( '/^(?:[0-9]+|auto)\s*\/\s*(?:[0-9]+|auto)\s*\/\s*span\s+[0-9]+\s*\/\s*span\s+([1-9][0-9]*)$/D', $area, $span ) ) {
				return null;
			}
			return 'span ' . $span[1];
		};
		// Jetpack's field shell exposes a real child slot only around the native value.
		// Preserve an evidenced full-span value by rebuilding that child wrapper rather
		// than collapsing the source grid tracks to a guessed width.
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'control' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) || ! is_int( $node['control'] ?? null ) || ! isset( $field_blocks[ $node['control'] ] ) || 'core/button' === ( $field_blocks[ $node['control'] ]['name'] ?? '' ) ) {
				continue;
			}
			$parent_id        = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$parent_layout    = $layout_nodes_by_id[ $parent_id ] ?? null;
			$control_layout   = $layout_nodes_by_id[ $node['id'] ] ?? null;
			$placement        = is_array( $control_layout ) ? ( $control_layout['layout']['column'] ?? $grid_area_column_span( $control_layout['layout']['area'] ?? null ) ) : null;
			$full_span        = is_array( $parent_layout ) ? $grid_span_width( $parent_layout['layout']['columns'] ?? null, $placement ) : null;
			$parent_proven    = is_array( $parent_layout ) && $has_unconditional_proven_property( $parent_layout, 'display' ) && $has_unconditional_proven_property( $parent_layout, 'grid-template-columns' );
			$placement_proven = is_array( $control_layout ) && ( $has_unconditional_proven_property( $control_layout, 'grid-column' ) || $has_unconditional_proven_property( $control_layout, 'grid-area' ) );
			if ( '100%' !== $full_span || ! $parent_proven || ! $placement_proven || ! isset( $wrapper_hooks[ $parent_id ] ) || ! empty( $variants_by_node[ $parent_id ] ) || ! empty( $variants_by_node[ $node['id'] ] ) ) {
				continue;
			}
			$child_hook = self::layout_node_class( self::layout_scope( $form ), $node['id'] );
			$field_blocks[ $node['control'] ]['attrs']['className'] = trim( (string) ( $field_blocks[ $node['control'] ]['attrs']['className'] ?? '' ) . ' ssi-source-fullspan-child--' . $child_hook );
			$provider_layout_targets[ $node['id'] ]                 = $child_hook . '-wrap';
			$overlay_node_targets[]                                 = array(
				'id'     => $node['id'],
				'layout' => $control_layout['layout'],
			);
			$operations[] = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_fullspan_grid_child',
				'target_hash' => hash( 'sha256', $node['id'] ),
			);
		}
		// A source grid can place a one-control branch rather than the control itself.
		// The reconstructed branch's first child is the native grid item; retain that
		// relationship so responsive grid facts stay on their source-owned elements.
		foreach ( $nodes as $grid_node ) {
			if ( ! is_array( $grid_node ) || 'wrapper' !== ( $grid_node['kind'] ?? null ) || ! is_string( $grid_node['id'] ?? null ) ) {
				continue;
			}
			$grid_id         = $grid_node['id'];
			$grid_layout     = $layout_nodes_by_id[ $grid_id ] ?? null;
			$grid_controls   = array_values( array_filter( $collect_controls( $grid_node ), static fn ( int $index ): bool => isset( $field_blocks[ $index ] ) && 'core/button' !== ( $field_blocks[ $index ]['name'] ?? '' ) ) );
			$grid_proven     = is_array( $grid_layout ) && $has_unconditional_proven_property( $grid_layout, 'display' ) && $has_unconditional_proven_property( $grid_layout, 'grid-template-columns' );
			$grid_variants   = $variants_by_node[ $grid_id ] ?? array();
			$variants_stable = true;
			foreach ( $grid_variants as $variant ) {
				$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				if ( isset( $patch['columns'] ) ) {
					$variants_stable = false;
					break;
				}
			}
			if ( 1 !== count( $grid_controls ) || ! $grid_proven || ! $variants_stable || ! isset( $wrapper_hooks[ $grid_id ] ) ) {
				continue;
			}
			foreach ( $children[ $grid_id ] ?? array() as $grid_child ) {
				$child_id         = 'wrapper' === ( $grid_child['kind'] ?? null ) ? $grid_child['id'] : '';
				$child_layout     = '' !== $child_id ? $layout_nodes_by_id[ $child_id ] ?? null : null;
				$child_controls   = '' !== $child_id ? array_values( array_filter( $collect_controls( $grid_child ), static fn ( int $index ): bool => isset( $field_blocks[ $index ] ) ) ) : array();
				$placement        = is_array( $child_layout ) ? ( $child_layout['layout']['column'] ?? $grid_area_column_span( $child_layout['layout']['area'] ?? null ) ) : null;
				$full_span        = $grid_span_width( $grid_layout['layout']['columns'] ?? null, $placement );
				$placement_proven = is_array( $child_layout ) && ( $has_unconditional_proven_property( $child_layout, 'grid-column' ) || $has_unconditional_proven_property( $child_layout, 'grid-area' ) );
				if ( '' === $child_id || $grid_controls !== $child_controls || '100%' !== $full_span || ! $placement_proven || ! isset( $wrapper_hooks[ $child_id ] ) ) {
					continue;
				}
				$child_variants_stable = true;
				foreach ( $variants_by_node[ $child_id ] ?? array() as $variant ) {
					$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
					if ( isset( $patch['column'] ) || isset( $patch['area'] ) ) {
						$child_variants_stable = false;
						break;
					}
				}
				if ( ! $child_variants_stable ) {
					continue;
				}
				$provider_layout_targets[ $grid_id ]  = $wrapper_hooks[ $grid_id ];
				$provider_layout_targets[ $child_id ] = $wrapper_hooks[ $child_id ];
				$operations[]                         = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_fullspan_grid_branch',
					'target_hash' => hash( 'sha256', $child_id ),
				);
				break;
			}
		}
		foreach ( $nodes as $node ) {
			$id               = is_array( $node ) && 'wrapper' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			$branch_controls  = '' !== $id ? $collect_controls( $node ) : array();
			$control_index    = 1 === count( $branch_controls ) ? $branch_controls[0] : null;
			$layout_node      = $layout_nodes_by_id[ $id ] ?? null;
			$layout_parent    = is_array( $layout_node ) && is_string( $layout_node['parent'] ?? null ) ? $layout_nodes_by_id[ $layout_node['parent'] ] ?? null : null;
			$column           = is_array( $layout_node ) ? ( $layout_node['layout']['column'] ?? $grid_area_column_span( $layout_node['layout']['area'] ?? null ) ) : null;
			$width            = is_array( $layout_node ) && is_array( $layout_parent ) ? $grid_span_width( $layout_parent['layout']['columns'] ?? null, $column ) : null;
			$placement_proven = is_array( $layout_node ) && ( $has_unconditional_proven_property( $layout_node, 'grid-column' ) || $has_unconditional_proven_property( $layout_node, 'grid-area' ) );
			$parent_proven    = is_array( $layout_parent ) && $has_unconditional_proven_property( $layout_parent, 'grid-template-columns' );
			if ( ! is_int( $control_index ) || 'core/button' !== ( $field_blocks[ $control_index ]['name'] ?? '' ) || null === $width || ! $placement_proven || ! $parent_proven ) {
				continue;
			}
			$target_variants = array();
			$variants_safe   = true;
			foreach ( $variants_by_node[ $id ] ?? array() as $variant ) {
				$patch         = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				$variant_width = array( 'column' ) === array_keys( $patch ) ? $grid_span_width( $layout_parent['layout']['columns'] ?? null, $patch['column'] ) : null;
				$proven        = false;
				foreach ( $variant['provenance'] ?? array() as $fact ) {
					if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === ( $variant['condition'] ?? null ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && in_array( 'grid-column', $fact['properties'] ?? array(), true ) ) {
						$proven = true;
						break;
					}
				}
				if ( null === $variant_width || ! is_array( $variant['condition'] ?? null ) || 'media' !== ( $variant['condition']['kind'] ?? null ) || ! $proven ) {
					$variants_safe = false;
					break;
				}
				$variant['node']         = 'control-' . $control_index;
				$variant['layout_patch'] = array( 'width' => $variant_width );
				$target_variants[]       = $variant;
			}
			if ( ! $variants_safe ) {
				continue;
			}
			$overlay_node_targets[]     = array(
				'id'     => 'control-' . $control_index,
				'layout' => array( 'width' => $width ),
			);
			$responsive_variant_targets = array_merge( $responsive_variant_targets, $target_variants );
			$represented_layout_nodes[] = $id;
			$operations[]               = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_grid_span_submit',
				'target_hash' => hash( 'sha256', $id ),
			);
		}
		foreach ( $layout_nodes_by_id as $id => $layout_node ) {
			$sizing    = $layout_node['sizing'] ?? null;
			$container = is_array( $sizing ) ? $layout_nodes_by_id[ $sizing['container'] ?? '' ] ?? null : null;
			$tracks    = is_array( $container ) ? preg_split( '/\s+/', trim( (string) ( $container['layout']['columns'] ?? '' ) ) ) : false;
			$column    = is_array( $sizing ) ? trim( (string) ( $sizing['grid_column'] ?? '' ) ) : '';
			$track     = is_array( $tracks ) && ctype_digit( $column ) ? $tracks[ (int) $column - 1 ] ?? null : null;

			$variant_sensitive = false;
			foreach ( $variants_by_node[ $id ] ?? array() as $variant ) {
				if ( isset( $variant['layout_patch']['column'] ) ) {
					$variant_sensitive = true;
					break;
				}
			}
			foreach ( $variants_by_node[ $sizing['container'] ?? '' ] ?? array() as $variant ) {
				if ( isset( $variant['layout_patch']['columns'] ) ) {
					$variant_sensitive = true;
					break;
				}
			}
			if ( ! is_array( $sizing ) || 'grid_track' !== ( $sizing['kind'] ?? null ) || 'inline' !== ( $sizing['axis'] ?? null ) || ! preg_match( '/^control-([0-9]+)$/D', $id, $control ) || ! isset( $field_blocks[ (int) $control[1] ] ) || ! is_string( $track ) || ! preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em)$/D', $track ) || $variant_sensitive ) {
				continue;
			}
			$overlay_node_targets[] = array(
				'id'     => $id,
				'layout' => array(
					'width' => $track,
					'flex'  => '0 1 auto',
				),
			);
			$operations[]           = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_grid_track_control_width',
				'target_hash' => hash( 'sha256', $id ),
			);
		}
		foreach ( $nodes as $node ) {
			$id = is_array( $node ) && 'control' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			if ( '' === $id || 'none' !== ( $layout_by_node[ $id ]['display'] ?? null ) ) {
				continue;
			}
			$control_index = $node['control'] ?? null;
			if ( ! is_int( $control_index ) || ! isset( $field_blocks[ $control_index ] ) || 'core/button' === ( $field_blocks[ $control_index ]['name'] ?? '' ) ) {
				continue;
			}
			$parent         = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$layout_node    = $layout_nodes_by_id[ $id ] ?? null;
			$source_classes = is_array( $layout_node['source']['classes'] ?? null ) ? $layout_node['source']['classes'] : array();
			$class_proven   = ! empty( $source_classes ) && is_array( $layout_node ) && $has_unconditional_proven_property( $layout_node, 'display' );
			foreach ( $layout_node['provenance'] ?? array() as $fact ) {
				if ( ! is_array( $fact ) || ! in_array( 'display', $fact['properties'] ?? array(), true ) ) {
					continue;
				}
				$selector       = is_string( $fact['selector'] ?? null ) ? $fact['selector'] : '';
				$matches_source = false;
				if ( null === ( $fact['condition'] ?? null ) && preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector ) ) {
					foreach ( $source_classes as $source_class ) {
						if ( is_string( $source_class ) && '' !== $source_class && preg_match( '/\.' . preg_quote( $source_class, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) {
							$matches_source = true;
							break;
						}
					}
				}
				if ( ! $matches_source ) {
					$class_proven = false;
					break;
				}
			}
			$replacement_proven = '' !== $parent
				&& in_array( $parent, $represented_layout_nodes, true )
				&& 1 === count( $children[ $parent ] ?? array() )
				&& 'none' !== ( $layout_by_node[ $parent ]['display'] ?? null )
				&& ! isset( $variants_by_node[ $parent ] )
				&& ! isset( $variants_by_node[ $id ] )
				&& $class_proven;
			if ( ! $replacement_proven ) {
				$losses[] = array(
					'dimension'   => 'topology',
					'reason_code' => 'provider_native_control_visibility_unrepresentable',
					'node_hash'   => hash( 'sha256', $id ),
				);
				continue;
			}
			$native_visibility_targets[] = $id;
			$operations[]                = array(
				'dimension' => 'layout',
				'strategy'  => 'provider_native_control_visibility',
				'node_hash' => hash( 'sha256', $id ),
			);
		}
		$percentage_width_parents = array();
		if ( 'generic/computed-layout-graph/v2' === ( $form['layout_graph']['schema'] ?? null ) ) {
			foreach ( $children as $parent => $siblings ) {
				if ( '$root' === $parent || count( $siblings ) < 2 || isset( $variants_by_node[ $parent ] ) ) {
					continue;
				}
				$indexes             = array();
				$widths              = array();
				$branches            = array();
				$row_variant_targets = array();
				foreach ( $siblings as $sibling ) {
					$id              = $sibling['id'];
					$layout_node     = $layout_nodes_by_id[ $id ] ?? null;
					$layout          = is_array( $layout_node ) && is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
					$width           = is_string( $layout['width'] ?? null ) ? trim( $layout['width'] ) : '';
					$width_proven    = is_array( $layout_node ) && $has_unconditional_proven_property( $layout_node, 'width' );
					$branch_controls = $collect_controls( $sibling );
					$value           = 1 === preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) ? (float) substr( $width, 0, -1 ) : 0.0;
					$variants        = $variants_by_node[ $id ] ?? array();
					if ( ! $safe_percentage_variants( $variants ) || ! $width_proven || 0 >= $value || 100 < $value || 1 !== count( $branch_controls ) || ! isset( $field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
						$indexes = array();
						break;
					}
					$indexes[]  = $branch_controls[0];
					$widths[]   = $value;
					$branches[] = $id;
					foreach ( $variants as $variant ) {
						$variant['node']       = 'control-' . $branch_controls[0];
						$row_variant_targets[] = $variant;
					}
				}
				if ( empty( $indexes ) || 0.001 < abs( array_sum( $widths ) - 100.0 ) ) {
					continue;
				}
				$responsive_variant_targets = array_merge( $responsive_variant_targets, $row_variant_targets );
				foreach ( $indexes as $offset => $control_index ) {
					$field_blocks[ $control_index ]['attrs']['width'] = round( $widths[ $offset ], 3 );
				}
				$represented_layout_nodes            = array_merge( $represented_layout_nodes, $branches );
				$percentage_width_parents[ $parent ] = true;
				$operations[]                        = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_percentage_width_fields',
					'target_hash' => hash( 'sha256', $parent ),
					'field_count' => count( $indexes ),
					'widths_hash' => hash( 'sha256', (string) wp_json_encode( $widths ) ),
				);

				$table_chain  = array( $parent );
				$table_tags   = array( 'tr', 'tbody', 'table' );
				$cursor       = $parent;
				$table_proven = true;
				foreach ( $table_tags as $expected_tag ) {
					$current            = $topology_by_id[ $cursor ] ?? null;
					$layout             = $layout_by_node[ $cursor ] ?? array();
					$allows_table_width = 'table' === $expected_tag && array( 'width' ) === array_keys( $layout ) && '100%' === ( $layout['width'] ?? null ) && isset( $layout_nodes_by_id[ $cursor ] ) && $has_unconditional_proven_property( $layout_nodes_by_id[ $cursor ], 'width' );
					if ( ! is_array( $current ) || ( $current['tag'] ?? 'div' ) !== $expected_tag || ( $layout_nodes_by_id[ $cursor ]['source']['tag'] ?? '' ) !== $expected_tag || ( 'tr' !== $expected_tag && ! empty( $layout ) && ! $allows_table_width ) ) {
						$table_proven = false;
						break;
					}
					$cursor = $current['parent'] ?? null;
					if ( is_string( $cursor ) ) {
						$table_chain[] = $cursor;
					}
				}
				foreach ( $branches as $branch ) {
					if ( 'td' !== ( $topology_by_id[ $branch ]['tag'] ?? 'div' ) || 'td' !== ( $layout_nodes_by_id[ $branch ]['source']['tag'] ?? '' ) || array( 'width' ) !== array_keys( $layout_by_node[ $branch ] ?? array() ) ) {
						$table_proven = false;
					}
				}
				if ( $table_proven ) {
					$represented_topology_nodes = array_merge( $represented_topology_nodes, $branches, array_slice( $table_chain, 0, 3 ) );
					if ( array( 'width' ) === array_keys( $layout_by_node[ $table_chain[2] ] ?? array() ) ) {
						$represented_layout_nodes[] = $table_chain[2];
					}
				}
			}
		}
		foreach ( $nodes as $node ) {
			$id               = is_array( $node ) ? ( $node['id'] ?? null ) : null;
			$layout_node      = is_string( $id ) ? ( $layout_nodes_by_id[ $id ] ?? null ) : null;
			$omitted_controls = is_array( $node ) ? $collect_controls( $node ) : array();
			if ( 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $id ) || array( 'display' => 'none' ) !== ( $layout_by_node[ $id ] ?? array() ) || isset( $variants_by_node[ $id ] ) || ! is_array( $layout_node ) || ! $has_unconditional_proven_property( $layout_node, 'display' ) || empty( $omitted_controls ) || array_filter( $omitted_controls, static fn( int $index ): bool => self::control_carries_authored_content( strtolower( trim( (string) ( $controls[ $index ]['type'] ?? $controls[ $index ]['tag'] ?? '' ) ) ) ) ) ) {
				continue;
			}
			$represented_layout_nodes[]   = $id;
			$represented_topology_nodes[] = $id;
			$operations[]                 = array(
				'dimension'     => 'topology',
				'strategy'      => 'provider_omitted_runtime_controls',
				'target_hash'   => hash( 'sha256', $id ),
				'control_count' => count( $omitted_controls ),
			);
		}
		foreach ( $children as $parent => $siblings ) {
			if ( '$root' === $parent || isset( $percentage_width_parents[ $parent ] ) || count( $siblings ) < 2 ) {
				continue;
			}
			$layout = $layout_by_node[ $parent ] ?? array();
			if ( array_intersect( array_keys( $layout ), array( 'item_placement', 'column', 'row', 'area' ) ) ) {
				continue;
			}
			$parent_variants = $variants_by_node[ $parent ] ?? array();
			$columns         = preg_replace( '/\s+/', '', (string) ( $layout['columns'] ?? '' ) );
			$class_tokens    = preg_split( '/\s+/', trim( (string) ( $topology_nodes_by_id[ $parent ]['class'] ?? '' ) ) );
			$class_tokens    = false === $class_tokens ? array() : array_values( array_filter( $class_tokens ) );
			$display         = $layout['display'] ?? null;
			if ( 'grid' !== $display ) {
				$from_class = self::display_from_class_tokens( $class_tokens );
				if ( 'grid' === $from_class ) {
					$display = 'grid';
				}
			}
			// Column count comes from the resolved track list, not from how many
			// field boxes occupy those tracks. A 2-column grid of eight fields is
			// four visual rows of Jetpack `width: 50`, not an 8-column row.
			$column_count        = self::equal_fraction_column_count( $columns );
			$class_token_columns = false;
			$widening_query      = null;
			if ( null === $column_count && '' === $columns && in_array( 'grid', $class_tokens, true ) ) {
				// Resolved tracks are absent (layered utility CSS often never
				// becomes a layout-graph node). A field-row grid of single-field
				// siblings is the 2-column pairing Jetpack `width: 50` represents;
				// 3- and 4-column rows still require a resolved track list.
				$column_count        = 2;
				$class_token_columns = true;
			}
			$equal_grid = empty( $parent_variants ) && 'grid' === $display && null !== $column_count;
			// A mobile-first source stacks these boxes by default and only bands
			// them into equal columns at a wider breakpoint (Tailwind's own
			// `grid-cols-1 md:grid-cols-2` or `grid sm:grid-cols-2` shape). Jetpack
			// field width is a single, non-responsive value, so accept exactly one
			// proven min-width widening of the track count and materialize that
			// widened state instead; any other variant shape (more than one variant,
			// a narrowing, a max-width query, or a patch touching more than the
			// column tracks) keeps the existing wrapper-layout decline.
			if ( ! $equal_grid && 'grid' === $display && 1 === count( $parent_variants ) ) {
				$variant       = $parent_variants[0];
				$condition     = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
				$patch         = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				$widened       = preg_replace( '/\s+/', '', (string) ( $patch['columns'] ?? '' ) );
				$widened_count = self::equal_fraction_column_count( $widened );
				if ( array( 'columns' ) === array_keys( $patch )
					&& is_array( $condition ) && 'media' === ( $condition['kind'] ?? null )
					&& is_string( $condition['query'] ?? null )
					&& self::is_min_width_media_query( $condition['query'] )
					&& null !== $widened_count
				) {
					foreach ( $variant['provenance'] ?? array() as $fact ) {
						if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === $condition && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && in_array( 'grid-template-columns', $fact['properties'] ?? array(), true ) ) {
							$equal_grid     = true;
							$column_count   = $widened_count;
							$widening_query = $condition['query'];
							break;
						}
					}
					$class_tokens = preg_split( '/\s+/', trim( (string) ( $topology_nodes_by_id[ $parent ]['class'] ?? '' ) ) );
					if ( ! $equal_grid && in_array( 'grid', is_array( $class_tokens ) ? $class_tokens : array(), true ) ) {
						$equal_grid     = true;
						$column_count   = $widened_count;
						$widening_query = $condition['query'];
					}
				}
			}
			if ( ! $equal_grid || null === $column_count ) {
				continue;
			}
			$indexes = array();
			foreach ( $siblings as $sibling ) {
				$branch_controls = $collect_controls( $sibling );
				if ( 1 !== count( $branch_controls ) || ! isset( $field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
					$indexes = array();
					break;
				}
				$indexes[] = $branch_controls[0];
			}
			if ( empty( $indexes ) ) {
				continue;
			}
			$non_submit_indexes = array_values(
				array_filter(
					array_keys( $field_blocks ),
					static fn ( int $index ): bool => 'core/button' !== ( $field_blocks[ $index ]['name'] ?? '' )
				)
			);
			$paired_indexes     = $indexes;
			sort( $non_submit_indexes );
			sort( $paired_indexes );
			if ( $paired_indexes === $non_submit_indexes ) {
				continue;
			}
			$width       = self::provider_field_width( 1 / $column_count );
			$gap         = is_string( $layout['gap'] ?? null ) && '' !== trim( $layout['gap'] )
				? trim( $layout['gap'] )
				: ( is_string( $layout['column_gap'] ?? null ) && '' !== trim( $layout['column_gap'] ) ? trim( $layout['column_gap'] ) : '1.5rem' );
			$track       = self::equal_fraction_track_size( $column_count, $gap );
			$paired      = array_fill_keys( $indexes, true );
			$stack_query = is_string( $widening_query ) ? self::inverted_min_width_media_query( $widening_query ) : null;
			if ( null === $stack_query && $class_token_columns ) {
				$stack_query = self::equal_width_stack_query_from_cascade_facts( $parent, $variants_by_node, $layout_nodes_by_id );
				if ( null === $stack_query ) {
					// Layered source utilities never become graph variants, so the
					// widening query is missing. The 50% overlay is more specific than
					// Jetpack's own `@media (max-width: 480px)` wrap stack and would
					// otherwise keep the row two-up at every width.
					$stack_query = '(max-width: 480px)';
				}
			}
			foreach ( $indexes as $control_index ) {
				$field_blocks[ $control_index ]['attrs']['width'] = $width;
				$overlay_node_targets[]                           = array(
					'id'        => 'field-' . $control_index,
					'layout'    => array(
						'width'              => $track,
						'flex_grow'          => '0',
						'flex_shrink'        => '0',
						'flex_basis'         => $track,
						'margin_block_start' => '0',
					),
					// A source sibling-stacking utility (e.g. Tailwind `space-y-*`) is
					// authored against the ORIGINAL sibling relationships and is carried
					// onto the provider form unscoped. Once flattening makes these two
					// fields adjacent provider siblings, that rule can still match them
					// with a selector more specific than this reset, since the reset's
					// only leverage over an unbounded source selector is the cascade
					// origin, not specificity. Forcing the reset wins regardless of the
					// source rule's specificity, which is what a value of literal `0` on
					// this synthetic flattening seam is always for.
					'important' => array( 'margin_block_start' ),
				);
				if ( is_string( $stack_query ) ) {
					$responsive_variant_targets[] = array(
						'node'         => 'field-' . $control_index,
						'condition'    => array(
							'kind'  => 'media',
							'query' => $stack_query,
						),
						'layout_patch' => array(
							'flex'  => '1 1 100%',
							'width' => '100%',
						),
					);
				}
			}
			$represented_layout_nodes[] = $parent;
			$operations[]               = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_equal_width_fields',
				'target_hash' => hash( 'sha256', $parent ),
				'width'       => $width,
			);
			foreach ( $field_blocks as $control_index => $field_block ) {
				if ( isset( $paired[ $control_index ] ) ) {
					continue;
				}
				// A submit control is never routed through Jetpack's grunion field
				// renderer, so it never receives the `-wrap` class suffix the `field-N`
				// id resolves through; address its own generated node hook instead.
				// It still sits as a flex item beside the flattened fields in the same
				// `space-y-*`-classed container, so it needs the identical reset: the
				// container's own flex `gap` already reproduces the source spacing,
				// and the carried sibling-margin would otherwise double it.
				$overlay_node_targets[] = array(
					'id'        => ( 'core/button' === ( $field_block['name'] ?? '' ) ? 'control-' : 'field-' ) . $control_index,
					'layout'    => array(
						'margin_block_start' => '0',
					),
					'important' => array( 'margin_block_start' ),
				);
			}
		}
		// Every source box that kept its own element can carry its own layout, so the
		// facts are transposed onto that element's generated hook instead of being
		// declared unrepresentable. A box whose facts are not fully proven by source
		// provenance keeps its loss.
		$layout_css_properties = Static_Site_Importer_Provider_Layout_Overlay::layout_property_map();
		$variant_proven        = static function ( array $variant, string $property ): bool {
			foreach ( $variant['provenance'] ?? array() as $fact ) {
				if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === ( $variant['condition'] ?? null ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && in_array( $property, $fact['properties'] ?? array(), true ) ) {
					return true;
				}
			}
			return false;
		};
		$node_facts_proven     = static function ( string $node_id ) use ( $layout_by_node, $layout_nodes_by_id, $variants_by_node, $layout_css_properties, $has_unconditional_proven_property, $variant_proven ): bool {
			$layout_node = $layout_nodes_by_id[ $node_id ] ?? null;
			$proven      = is_array( $layout_node );
			foreach ( array_keys( $layout_by_node[ $node_id ] ?? array() ) as $fact ) {
				$proven = $proven && isset( $layout_css_properties[ $fact ] ) && $has_unconditional_proven_property( $layout_node, $layout_css_properties[ $fact ] );
			}
			foreach ( $variants_by_node[ $node_id ] ?? array() as $variant ) {
				foreach ( array_keys( is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array() ) as $fact ) {
					$proven = $proven && isset( $layout_css_properties[ $fact ] ) && $variant_proven( $variant, $layout_css_properties[ $fact ] );
				}
			}
			return $proven;
		};
		// Native wrapper projection is all-or-nothing and handled by
		// exact_native_div_topology() below. The legacy partial-tree projector used
		// a second serializer contract and could silently flatten parent edges.
		// Source boxes that hold every mapped control become the provider's own form
		// element. Their container layout is what positions the fields, so it is merged
		// onto that element. A nested box declaring a full-width value repeats the box it
		// fills rather than contradicting it; any other disagreement fails closed.
		$resolve_fact = static function ( mixed $current, mixed $value, string $property ): mixed {
			if ( null === $current || $current === $value ) {
				return $value;
			}
			if ( 'width' === $property && '100%' === $value ) {
				return $current;
			}
			if ( 'width' === $property && '100%' === $current ) {
				return $value;
			}
			return null;
		};
		$item_facts   = array( 'column', 'row', 'area', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self' );
		$form_boxes   = array();
		$form_base    = $layout_by_node['form'] ?? array();
		$form_patches = array();
		foreach ( $variants_by_node['form'] ?? array() as $variant ) {
			$form_patches[ (string) wp_json_encode( $variant['condition'] ?? null ) ] = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
		}
		// A submit control is never a member of the source field-group box a provider
		// forms its own field container from: Jetpack always renders a submit as a
		// sibling of that container, never a descendant of it, regardless of what the
		// source DOM does. A box that groups every other mapped control still states
		// the field container's own layout, so a submit's absence alone does not make
		// that box's branch a partial (and therefore unrepresentable) one.
		$non_submit_mapped_controls = array_values(
			array_filter(
				$mapped_controls,
				static fn ( int $index ): bool => 'submit' !== strtolower( trim( (string) ( $controls[ $index ]['type'] ?? '' ) ) )
			)
		);
		foreach ( $nodes as $node ) {
			$node_id = is_array( $node ) && 'wrapper' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			$branch  = '' !== $node_id ? array_values( array_filter( $collect_controls( $node ), static fn ( int $index ): bool => isset( $field_blocks[ $index ] ) ) ) : array();
			sort( $branch );
			if ( '' === $node_id || count( $mapped_controls ) < 2 || isset( $wrapper_hooks[ $node_id ] ) ) {
				continue;
			}
			if ( $branch !== $mapped_controls && ( count( $non_submit_mapped_controls ) < 2 || $branch !== $non_submit_mapped_controls ) ) {
				continue;
			}
			$form_boxes[] = $node;
		}
		usort( $form_boxes, static fn ( array $left, array $right ): int => (int) ( $left['depth'] ?? 0 ) <=> (int) ( $right['depth'] ?? 0 ) );
		$merged_base     = array();
		$merged_patches  = array();
		$merged_boxes    = array();
		$merged_variants = array();
		foreach ( $form_boxes as $box ) {
			$box_id  = (string) $box['id'];
			$base    = $layout_by_node[ $box_id ] ?? array();
			$patches = array();
			foreach ( $variants_by_node[ $box_id ] ?? array() as $variant ) {
				$patches[] = $variant;
			}
			if ( ! $node_facts_proven( $box_id ) || array_intersect_key( $base, array_flip( $item_facts ) ) ) {
				continue;
			}
			if ( ! isset( $base['display'] ) ) {
				$class_tokens = preg_split( '/\s+/', trim( (string) ( $box['class'] ?? '' ) ) );
				$from_class   = self::display_from_class_tokens( false === $class_tokens ? array() : $class_tokens );
				if ( is_string( $from_class ) ) {
					$base['display'] = $from_class;
				}
			}
			$box_base    = $merged_base;
			$box_patches = $merged_patches;
			$accepted    = true;
			foreach ( $base as $property => $value ) {
				$resolved              = $resolve_fact( $form_base[ $property ] ?? ( $box_base[ $property ] ?? null ), $value, (string) $property );
				$accepted              = $accepted && null !== $resolved;
				$box_base[ $property ] = $resolved;
			}
			foreach ( $patches as $variant ) {
				$condition = (string) wp_json_encode( $variant['condition'] ?? null );
				$patch     = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				if ( array_intersect_key( $patch, array_flip( $item_facts ) ) ) {
					$accepted = false;
					break;
				}
				// A variant that patches *only* the column track count is the same
				// fact the guarded mobile-first grid-row rule above governs, so it
				// is held to that same conservative shape here: exactly one proven
				// min-width-equivalent widening. A second such variant on this box
				// or a narrowing query keeps this box out of the merge, so it stays
				// an unrepresented wrapper and the existing decline stands. A patch
				// that establishes the column tracks together with other facts in
				// the same declaration (for example a box whose grid only exists
				// from a single breakpoint up) is a different, already-proven
				// shape and is unaffected.
				if ( array( 'columns' ) === array_keys( $patch ) ) {
					$condition_fact      = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
					$widens_only_columns = 1 === count( $patches )
						&& is_array( $condition_fact ) && 'media' === ( $condition_fact['kind'] ?? null )
						&& is_string( $condition_fact['query'] ?? null )
						&& self::is_min_width_media_query( $condition_fact['query'] );
					if ( ! $widens_only_columns ) {
						$accepted = false;
						break;
					}
				}
				foreach ( $patch as $property => $value ) {
					$merged_patch = $box_patches[ $condition ]['patch'] ?? array();
					$current      = $form_patches[ $condition ][ $property ] ?? ( $merged_patch[ $property ] ?? null );

					$resolved                                        = $resolve_fact( $current, $value, (string) $property );
					$accepted                                        = $accepted && null !== $resolved;
					$box_patches[ $condition ]['condition']          = $variant['condition'] ?? null;
					$box_patches[ $condition ]['patch'][ $property ] = $resolved;
				}
			}
			if ( ! $accepted ) {
				continue;
			}
			$box_classes = preg_split( '/\s+/', trim( (string) ( $box['class'] ?? '' ) ) );
			if ( false !== $box_classes ) {
				$form_classes = array_merge( $form_classes, array_values( array_filter( $box_classes ) ) );
			}
			$merged_base    = $box_base;
			$merged_patches = $box_patches;
			$merged_boxes[] = $box_id;
		}
		// Only facts the provider's form element does not already declare are emitted, so
		// a box that merely repeats the form's own value adds no competing declaration.
		$transposed_layout = array_merge( $form_base, $merged_base );
		$merged_base       = array_filter( $merged_base, static fn ( $value, $property ): bool => ( $form_base[ $property ] ?? null ) !== $value, ARRAY_FILTER_USE_BOTH );
		foreach ( $merged_patches as $condition => $entry ) {
			$patch = array_filter( $entry['patch'], static fn ( $value, $property ): bool => ( $form_patches[ $condition ][ $property ] ?? null ) !== $value, ARRAY_FILTER_USE_BOTH );
			if ( ! empty( $patch ) ) {
				$merged_variants[] = array(
					'node'         => 'form',
					'condition'    => $entry['condition'] ?? null,
					'layout_patch' => $patch,
				);
			}
		}
		if ( ! empty( $merged_boxes ) ) {
			/** @var array<int,int|string> $sibling_submit_indexes */
			$sibling_submit_indexes = array();
			foreach ( $controls as $control_index => $control ) {
				if ( 'submit' === strtolower( trim( (string) ( $control['type'] ?? '' ) ) ) && '$root' === ( $control_parents[ $control_index ] ?? '$root' ) ) {
					$sibling_submit_indexes[] = $control_index;
				}
			}
			// Jetpack paints fields and submit as siblings of one field list. A source
			// submit that sits beside that list must not become a member of it: the
			// authored top margin (mt-9) would sit inside the list gap, or a gap
			// cancel would overwrite it. Keep the list's display/gap on a dedicated
			// inner wrapper and leave the submit outside.
			$use_field_list = ! empty( $sibling_submit_indexes );
			if ( $use_field_list ) {
				$form_classes[]     = 'ssi-source-field-list';
				$field_list_layout  = $merged_base;
				$field_list_row_gap = self::layout_row_gap( $transposed_layout );
				if ( is_string( $field_list_row_gap ) ) {
					$gap_key                       = isset( $transposed_layout['row_gap'] ) ? 'row_gap' : 'gap';
					$field_list_layout[ $gap_key ] = $field_list_row_gap;
					if ( ( $form_base[ $gap_key ] ?? null ) === $field_list_row_gap ) {
						$suppressed_layout_properties['form'] = array_values( array_unique( array_merge( $suppressed_layout_properties['form'] ?? array(), array( $gap_key ) ) ) );
					}
				}
				if ( ! empty( $field_list_layout ) ) {
					$overlay_node_targets[] = array(
						'id'     => 'field-list',
						'layout' => $field_list_layout,
					);
				}
				foreach ( $merged_variants as $variant ) {
					$responsive_variant_targets[] = array(
						'node'         => 'field-list',
						'condition'    => $variant['condition'] ?? null,
						'layout_patch' => $variant['layout_patch'],
					);
				}
			} else {
				if ( ! empty( $merged_base ) ) {
					$overlay_node_targets[] = array(
						'id'     => 'form',
						'layout' => $merged_base,
					);
				}
				$responsive_variant_targets = array_merge( $responsive_variant_targets, $merged_variants );
			}
			foreach ( $merged_boxes as $box_id ) {
				$overlay_represented_nodes[] = $box_id;
				$operations[]                = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_form_box_transposition',
					'target_hash' => hash( 'sha256', $box_id ),
				);
			}
			// The gap cancel this replaced only ever applied to a sibling submit,
			// and a sibling submit is exactly what puts the fields in their own
			// field-list wrapper. Once that wrapper carries the list's gap the
			// submit sits outside it, so there is no transposed gap left to cancel
			// against its authored margin (#1738).
		}
		foreach ( $wrapper_hooks as $node_id => $hook ) {
			$layout_node = $layout_nodes_by_id[ $node_id ] ?? null;
			$proven      = is_array( $layout_node );
			foreach ( array_keys( $layout_by_node[ $node_id ] ?? array() ) as $fact ) {
				$proven = $proven && isset( $layout_css_properties[ $fact ] ) && $has_unconditional_proven_property( $layout_node, $layout_css_properties[ $fact ] );
			}
			foreach ( $variants_by_node[ $node_id ] ?? array() as $variant ) {
				foreach ( array_keys( is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array() ) as $fact ) {
					$proven = $proven && isset( $layout_css_properties[ $fact ] ) && $variant_proven( $variant, $layout_css_properties[ $fact ] );
				}
			}
			// A wrapper already represented by carrying its own source class name
			// (so the enqueued source stylesheet paints its base fact directly)
			// still needs its own overlay target when it also carries a responsive
			// variant under a different, unrepresented class - the base-class
			// carry never inspects variants, so it cannot promise those too.
			$base_represented_only = in_array( $node_id, $represented_layout_nodes, true ) && empty( $variants_by_node[ $node_id ] ?? array() );
			if ( ! $proven || $base_represented_only ) {
				continue;
			}
			$provider_layout_targets[ $node_id ] = $hook;
			$overlay_represented_nodes[]         = $node_id;
			$operations[]                        = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_source_box_transposition',
				'target_hash' => hash( 'sha256', $node_id ),
			);
		}
		$represented = array_fill_keys( array_merge( $represented_layout_nodes, $overlay_represented_nodes ), true );
		foreach ( $layout_by_node as $node_id => $layout ) {
			if ( ! preg_match( '/^wrapper-[0-9]+$/D', $node_id ) || isset( $represented[ $node_id ] ) || ( empty( $layout ) && ! isset( $variants_by_node[ $node_id ] ) ) ) {
				continue;
			}
			$losses[] = array(
				'dimension'   => 'topology',
				'reason_code' => 'provider_wrapper_layout_unrepresentable',
				'node_hash'   => hash( 'sha256', $node_id ),
			);
		}
		$build = static function ( string $parent_node ) use ( &$build, $children, $field_blocks, $controls, $suppressed_controls, $provider_controls, &$losses ): array {
			$blocks = array();
			foreach ( $children[ $parent_node ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$control_index = $node['control'] ?? -1;
					if ( isset( $field_blocks[ $control_index ] ) ) {
						$blocks[] = $field_blocks[ $control_index ];
					} elseif ( isset( $suppressed_controls[ $control_index ] ) ) {
						continue;
					} elseif ( isset( $controls[ $control_index ] ) ) {
						$type = strtolower( trim( (string) ( $controls[ $control_index ]['type'] ?? $controls[ $control_index ]['tag'] ?? '' ) ) );
						if ( isset( $provider_controls[ $control_index ] ) || ! self::control_carries_authored_content( $type ) ) {
							continue;
						}
						$losses[] = array(
							'dimension'         => 'topology',
							'reason_code'       => 'unsupported_control_unrepresentable',
							'node_hash'         => hash( 'sha256', $node['id'] ),
							'control_index'     => $control_index,
							'control_type_hash' => hash( 'sha256', $type ),
						);
					}
					continue;
				}
				$inner_blocks = $build( $node['id'] );
				$blocks       = array_merge( $blocks, $inner_blocks );
			}
			return $blocks;
		};
		// A wrapper that also earned its own overlay target (because carrying its
		// source class name alone cannot promise a responsive variant under a
		// different, unrepresented class) must keep its graph node so that target
		// map can still address it; "represented by source class" is no longer
		// the operative claim once an overlay target exists for the same node.
		$represented_layout_nodes = array_values( array_diff( array_map( 'strval', $represented_layout_nodes ), array_keys( $provider_layout_targets ) ) );
		return array(
			'blocks'                       => $build( '$root' ),
			'losses'                       => $losses,
			'operations'                   => $operations,
			'represented_layout_nodes'     => array_values( array_unique( array_map( 'strval', $represented_layout_nodes ) ) ),
			'represented_topology_nodes'   => array_values( array_unique( array_map( 'strval', $represented_topology_nodes ) ) ),
			'suppressed_layout_properties' => $suppressed_layout_properties,
			'overlay_node_targets'         => $overlay_node_targets,
			'responsive_variant_targets'   => $responsive_variant_targets,
			'native_visibility_targets'    => array_values( array_unique( array_map( 'strval', $native_visibility_targets ) ) ),
			'form_classes'                 => array_values( array_unique( $form_classes ) ),
			'provider_layout_targets'      => $provider_layout_targets,
			'phone_popup_targets'          => $phone_popup_targets,
		);
	}

	/**
	 * Recover the control tree when the producer emitted layout nodes but no
	 * separate topology. The layout graph is sufficient only when every visible
	 * control and its ancestor chain survived capture; otherwise retain the
	 * existing fail-closed flat projection.
	 *
	 * @param array<string,mixed>              $form
	 * @param array<int,array<string,mixed>>   $controls
	 * @return array<string,mixed>|null
	 */
	private static function derive_control_topology_from_layout_graph( array $form, array $controls ): ?array {
		$graph = $form['layout_graph'] ?? null;
		if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) ) {
			return null;
		}

		$source_nodes = array();
		foreach ( $graph['nodes'] as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$source_nodes[ $node['id'] ] = $node;
		}
		if ( empty( $source_nodes ) ) {
			return null;
		}

		$nodes       = array();
		$control_ids = array();
		foreach ( $source_nodes as $id => $node ) {
			$parent = is_string( $node['parent'] ?? null ) ? $node['parent'] : null;
			if ( null !== $parent && ! isset( $source_nodes[ $parent ] ) ) {
				continue;
			}
			$source        = is_array( $node['source'] ?? null ) ? $node['source'] : array();
			$control_match = array();
			if ( 1 === preg_match( '/^control-([0-9]+)$/D', $id, $control_match ) ) {
				$control_index                 = (int) $control_match[1];
				$control_ids[ $control_index ] = true;
				$nodes[]                       = array(
					'id'      => $id,
					'kind'    => 'control',
					'parent'  => $parent,
					'order'   => (int) ( $node['order'] ?? 0 ),
					'depth'   => (int) ( $node['depth'] ?? 0 ),
					'control' => $control_index,
				);
				continue;
			}
			$classes = is_array( $source['classes'] ?? null ) ? $source['classes'] : array();
			$nodes[] = array(
				'id'     => $id,
				'kind'   => 'wrapper',
				'parent' => $parent,
				'order'  => (int) ( $node['order'] ?? 0 ),
				'depth'  => (int) ( $node['depth'] ?? 0 ),
				'tag'    => is_string( $source['tag'] ?? null ) ? strtolower( $source['tag'] ) : 'div',
				'class'  => implode( ' ', array_filter( array_map( 'strval', $classes ) ) ),
			);
		}
		foreach ( $controls as $index => $control ) {
			$type = strtolower( trim( (string) ( $control['type'] ?? $control['tag'] ?? '' ) ) );
			if ( ! isset( $control_ids[ $index ] ) && 'hidden' !== $type ) {
				return null;
			}
		}
		if ( empty( $nodes ) ) {
			return null;
		}
		usort(
			$nodes,
			static function ( array $left, array $right ): int {
				$depth = (int) $left['depth'] <=> (int) $right['depth'];

				return 0 !== $depth ? $depth : ( (int) $left['order'] <=> (int) $right['order'] );
			}
		);

		return array(
			'schema'    => 'generic/form-control-topology/v1',
			'max_depth' => (int) ( $graph['limits']['depth'] ?? 16 ),
			'max_nodes' => (int) ( $graph['limits']['nodes'] ?? 128 ),
			'truncated' => ! empty( $graph['truncated'] ),
			'nodes'     => $nodes,
		);
	}

	/**
	 * Preserve a complete source div subtree before provider field-shell projection.
	 *
	 * Jetpack owns the markup below a mapped control. A source container can therefore
	 * receive the full layout capability set only when a physical core/group remains at
	 * that exact source parent edge. This path deliberately accepts the whole connected
	 * tree or none of it; partial trees continue through the constrained legacy mapping.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @param array<string,array<int,array<string,mixed>>> $children
	 * @param array<int,array<string,mixed>> $field_blocks
	 * @param array<int,bool> $suppressed_controls
	 * @param array<string,array<string,mixed>> $layout_nodes
	 * @param array<string,array<string,mixed>> $layouts
	 * @param array<string,array<int,array<string,mixed>>> $variants
	 * @return array{blocks:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>,represented_layout_nodes:array<int,string>,represented_topology_nodes:array<int,string>,suppressed_layout_properties:array<string,array<int,string>>,overlay_node_targets:array<int,array<string,mixed>>,responsive_variant_targets:array<int,array<string,mixed>>,native_visibility_targets:array<int,string>,form_classes:array<int,string>,provider_layout_targets:array<string,string>,phone_popup_targets:array<int,int>}|null
	 */
	private static function exact_native_div_topology( array $nodes, array $children, array $field_blocks, array $suppressed_controls, array $layout_nodes, array $layouts, array $variants, string $scope ): ?array {
		$wrappers = array();
		foreach ( $nodes as $node ) {
			if ( ! is_string( $node['id'] ?? null ) ) {
				return null;
			}
			if ( 'wrapper' === ( $node['kind'] ?? null ) ) {
				if ( 'div' !== ( $node['tag'] ?? null ) ) {
					return null;
				}
				$wrappers[ $node['id'] ] = $node;
			} elseif ( 'control' !== ( $node['kind'] ?? null ) || ! is_int( $node['control'] ?? null ) || ( ! isset( $field_blocks[ $node['control'] ] ) && ! isset( $suppressed_controls[ $node['control'] ] ) ) ) {
				return null;
			}
		}
		if ( empty( $wrappers ) ) {
			return null;
		}

		$property_map = array(
			'display'             => 'display',
			'width'               => 'width',
			'height'              => 'height',
			'columns'             => 'grid-template-columns',
			'rows'                => 'grid-template-rows',
			'gap'                 => 'gap',
			'row_gap'             => 'row-gap',
			'column_gap'          => 'column-gap',
			'direction'           => 'flex-direction',
			'wrap'                => 'flex-wrap',
			'align_items'         => 'align-items',
			'align_content'       => 'align-content',
			'justify_content'     => 'justify-content',
			'align_self'          => 'align-self',
			'justify_self'        => 'justify-self',
			'order'               => 'order',
			'flex'                => 'flex',
			'flex_grow'           => 'flex-grow',
			'flex_shrink'         => 'flex-shrink',
			'flex_basis'          => 'flex-basis',
			'column'              => 'grid-column',
			'row'                 => 'grid-row',
			'area'                => 'grid-area',
			'margin_block_start'  => 'margin-block-start',
			'margin_block_end'    => 'margin-block-end',
			'margin_inline_start' => 'margin-inline-start',
			'margin_inline_end'   => 'margin-inline-end',
		);
		$proven       = static function ( array $facts, mixed $condition, array $layout ) use ( $property_map ): bool {
			foreach ( array_keys( $layout ) as $fact ) {
				if ( ! isset( $property_map[ $fact ] ) ) {
					return false;
				}
				$found = false;
				foreach ( $facts as $entry ) {
					if ( is_array( $entry ) && ( $entry['condition'] ?? null ) === $condition && is_string( $entry['source_path'] ?? null ) && is_string( $entry['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $entry['source_sha256'] ) && is_string( $entry['selector'] ?? null ) && in_array( $property_map[ $fact ], $entry['properties'] ?? array(), true ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					return false;
				}
			}
			return true;
		};
		foreach ( $wrappers as $id => $wrapper ) {
			$layout_node     = $layout_nodes[ $id ] ?? null;
			$parent          = is_string( $wrapper['parent'] ?? null ) ? $wrapper['parent'] : '$root';
			$expected_parent = '$root' === $parent ? 'form' : $parent;
			if ( ! is_array( $layout_node ) || 'div' !== ( $layout_node['source']['tag'] ?? null ) || ( $layout_node['parent'] ?? null ) !== $expected_parent || ! is_array( $layouts[ $id ] ?? null ) || 'flex' !== ( $layouts[ $id ]['display'] ?? null ) || ! in_array( $layouts[ $id ]['direction'] ?? null, array( 'row', 'column' ), true ) || ! Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( $layouts[ $id ] ) || ! $proven( $layout_node['provenance'] ?? array(), null, $layouts[ $id ] ) ) {
				return null;
			}
			foreach ( $variants[ $id ] ?? array() as $variant ) {
				$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				if ( empty( $patch ) || ! is_array( $variant['condition'] ?? null ) || ! Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( $patch ) || ! $proven( $variant['provenance'] ?? array(), $variant['condition'], $patch ) ) {
					return null;
				}
			}
		}
		foreach ( $layout_nodes as $id => $layout_node ) {
			if ( preg_match( '/^wrapper-[0-9]+$/D', $id ) && ! isset( $wrappers[ $id ] ) ) {
				return null;
			}
		}

		$hooks           = array();
		$native_variants = array();
		foreach ( $wrappers as $id => $wrapper ) {
			$hooks[ $id ]    = self::layout_node_class( $scope, $id );
			$native_variants = array_merge( $native_variants, $variants[ $id ] ?? array() );
		}
		$build = static function ( string $parent_node ) use ( &$build, $children, $field_blocks, $wrappers, $layouts, $hooks ): array {
			$blocks = array();
			foreach ( $children[ $parent_node ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$index = $node['control'];
					if ( isset( $field_blocks[ $index ] ) ) {
						$blocks[] = $field_blocks[ $index ];
					}
					continue;
				}
				$id       = $node['id'];
				$classes  = preg_split( '/\s+/', trim( (string) ( $wrappers[ $id ]['class'] ?? '' ) ) );
				$classes  = false === $classes ? array() : array_values( array_filter( $classes ) );
				$blocks[] = array(
					'name'        => 'core/group',
					'attrs'       => array(
						'className' => trim( implode( ' ', array_merge( $classes, array( $hooks[ $id ] ) ) ) ),
						'layout'    => array(
							'type'        => 'flex',
							'orientation' => 'row' === ( $layouts[ $id ]['direction'] ?? null ) ? 'horizontal' : 'vertical',
						),
					),
					'innerBlocks' => $build( $id ),
				);
			}
			return $blocks;
		};
		return array(
			'blocks'                       => $build( '$root' ),
			'losses'                       => array(),
			'operations'                   => array_map( static fn( string $id ): array => array(
				'dimension'   => 'topology',
				'strategy'    => 'native_div_subtree_projection',
				'target_hash' => hash( 'sha256', $id ),
			), array_keys( $wrappers ) ),
			'represented_layout_nodes'     => array_keys( $wrappers ),
			'represented_topology_nodes'   => array_keys( $wrappers ),
			'suppressed_layout_properties' => array(),
			'overlay_node_targets'         => array_map( static fn( string $id ): array => array(
				'id'     => $id,
				'layout' => $layouts[ $id ],
			), array_keys( $wrappers ) ),
			'responsive_variant_targets'   => $native_variants,
			'native_visibility_targets'    => array(),
			'form_classes'                 => array(),
			'provider_layout_targets'      => $hooks,
			// Every topology result carries the same shape. This projection owns no
			// phone popup placement, so it reports an explicit empty set instead of
			// leaving the key absent for its consumers.
			'phone_popup_targets'          => array(),
		);
	}

	/**
	 * Jetpack retains an empty error element in every field. It remains a flex
	 * item, so a source-owned field gap is applied to it unless it is removed
	 * while inactive. The runtime adds `has-errors` when validation needs it.
	 *
	 * The runtime also rebuilds a proven source label/control row as the field
	 * shell's own sole child (`.ssi-field-row`, see
	 * Static_Site_Importer_Provider_Form_Runtime::project_wrapper_classes()).
	 * When the shell itself resolves to `display: grid` from captured source
	 * facts (a mobile-only track layout is one such source), an item that is
	 * not explicitly placed on the grid's column axis auto-places into a
	 * single implicit track instead of spanning the shell's own tracks, so
	 * both the row and every control nested inside it collapse to that one
	 * track's width. `grid-column` has no effect on a non-grid-item element,
	 * so this is scoped to only the forms that proved a field shell's own
	 * `display: grid` (detected from the shell's already-compiled `-wrap`
	 * rule), leaving every flex- or block-shell form's compiled CSS, and the
	 * existing tests that assert its exact shape, unchanged.
	 *
	 * @param array<string,mixed> $overlay Compiled provider overlay.
	 * @param string              $scope Provider form scope.
	 * @param array<int,string>  $mapped_types Materialized field types by source index.
	 * @return array<string,mixed>
	 */
	public static function collapse_inactive_provider_errors( array $overlay, string $scope, array $mapped_types ): array {
		if ( ! isset( $overlay['overlay'] ) || ! is_array( $overlay['overlay'] ) || ! is_string( $overlay['css'] ?? null ) || ! preg_match( '/^ssi-form-[a-f0-9]{12}$/D', $scope ) ) {
			return $overlay;
		}

		if ( empty( $mapped_types ) ) {
			return $overlay;
		}
		$field_row_span               = 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}-wrap\{[^}]*\bdisplay:grid\b/', $overlay['css'] )
			? '.' . $scope . ' .grunion-field-wrap > .ssi-field-row{grid-column:1 / -1}' . "\n"
			: '';
		$css                          = rtrim( $overlay['css'] ) . "\n." . $scope . ' .grunion-field-wrap .contact-form__input-error:not(.has-errors){display:none}' . "\n" . '.' . $scope . ' .grunion-field-wrap .contact-form__field-hints{display:contents}' . "\n" . '.' . $scope . ' .grunion-field-wrap .contact-form__field-format{display:none}' . "\n" . '.' . $scope . ' .grunion-field-wrap .ssi-field-row > label{margin-block-end:0}' . "\n" . '.' . $scope . ' .grunion-field-wrap .grunion-field::placeholder{color:revert}' . "\n" . $field_row_span;
		$overlay['css']               = $css;
		$overlay['overlay']['css']    = $css;
		$overlay['overlay']['sha256'] = hash( 'sha256', $css );
		$overlay['overlay']['bytes']  = strlen( $css );

		return $overlay;
	}

	/**
	 * Separate the source form's own box from the container it establishes.
	 *
	 * The provider renders a block wrapper around its form element, so the source
	 * form's placement inside the page belongs to that wrapper while the layout it
	 * establishes for its fields belongs to the form element itself.
	 *
	 * @param array<string, mixed> $graph Provider layout graph.
	 * @return array<string, mixed>
	 */
	public static function split_form_box( array $graph ): array {
		$item_facts = array( 'column', 'row', 'area', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self' );
		$nodes      = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			$layout = is_array( $node ) && is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			$box    = 'form' === ( $node['id'] ?? '' ) ? array_intersect_key( $layout, array_flip( $item_facts ) ) : array();
			if ( ! empty( $box ) ) {
				$node['layout'] = array_diff_key( $layout, $box );
				$nodes[]        = array(
					'id'     => 'form-box',
					'kind'   => 'container',
					'layout' => $box,
				);
			}
			$nodes[] = $node;
		}
		$variants = array();
		foreach ( $graph['variants'] ?? array() as $variant ) {
			$patch = is_array( $variant ) && is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
			$box   = 'form' === ( $variant['node'] ?? '' ) ? array_intersect_key( $patch, array_flip( $item_facts ) ) : array();
			if ( ! empty( $box ) ) {
				$variant['layout_patch']     = array_diff_key( $patch, $box );
				$box_variant                 = $variant;
				$box_variant['node']         = 'form-box';
				$box_variant['layout_patch'] = $box;
				$variants[]                  = $box_variant;
			}
			if ( ! empty( $variant['layout_patch'] ) ) {
				$variants[] = $variant;
			}
		}
		if ( ! array_filter( $nodes, static fn ( $node ): bool => is_array( $node ) && 'form-box' === ( $node['id'] ?? '' ) ) && array_filter( $variants, static fn ( $variant ): bool => is_array( $variant ) && 'form-box' === ( $variant['node'] ?? '' ) ) ) {
			$nodes[] = array(
				'id'     => 'form-box',
				'kind'   => 'container',
				'layout' => array(),
			);
		}
		$graph['nodes']    = $nodes;
		$graph['variants'] = $variants;
		return $graph;
	}

	/**
	 * Source wrappers the provider form replaces are the page-grid item the
	 * provider container now occupies. Their classes still address that role
	 * in the source stylesheet, so they belong on the provider block wrapper.
	 *
	 * Inner field-row shells (a `grid sm:grid-cols-2` name+phone pair) are not
	 * the host. Those map through `provider_equal_width_fields` onto Jetpack
	 * field widths instead of being copied onto the form container.
	 *
	 * @return array{classes:array<int,string>,operations:array<int,array<string,mixed>>}
	 */
	public static function host_wrapper_projection( array $form ): array {
		$form_classes = preg_split( '/\s+/', trim( (string) ( $form['form']['class'] ?? '' ) ) );
		$form_classes = false === $form_classes ? array() : array_values( array_filter( $form_classes ) );
		$form_owned   = array_fill_keys( $form_classes, true );
		$classes      = array();
		$operations   = array();
		foreach ( self::replaced_wrapper_class_lists( $form ) as $wrapper_classes ) {
			$source = array();
			foreach ( $wrapper_classes as $class_name ) {
				if ( isset( $form_owned[ $class_name ] ) || ! self::is_source_host_class( $class_name ) ) {
					continue;
				}
				$source[] = $class_name;
			}
			if ( empty( $source ) ) {
				continue;
			}
			$classes      = array_merge( $classes, $source );
			$operations[] = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_host_wrapper_class_projection',
				'target_hash' => hash( 'sha256', implode( ' ', $source ) ),
			);
		}
		return array(
			'classes'    => array_values( array_unique( $classes ) ),
			'operations' => $operations,
		);
	}

	/**
	 * Class lists of source wrappers the binding search replaces, outermost first.
	 *
	 * @return array<int,array<int,string>>
	 */
	private static function replaced_wrapper_class_lists( array $form ): array {
		$markups    = array();
		$candidates = is_array( $form['bindings'] ?? null ) ? $form['bindings'] : array();
		if ( is_array( $form['binding'] ?? null ) ) {
			array_unshift( $candidates, $form['binding'] );
		}
		foreach ( $candidates as $binding ) {
			if ( is_array( $binding ) && is_string( $binding['search_block_markup'] ?? null ) && '' !== trim( $binding['search_block_markup'] ) ) {
				$markups[] = $binding['search_block_markup'];
			}
		}
		$lists = array();
		foreach ( $markups as $markup ) {
			foreach ( self::layout_shell_wrapper_class_lists( $markup ) as $list ) {
				$lists[] = $list;
			}
		}
		return $lists;
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	private static function layout_shell_wrapper_class_lists( string $markup ): array {
		$markup = ltrim( $markup );
		if ( ! preg_match( '/^<!-- wp:(?:[a-z][a-z0-9-]*\/)?layout-shell\s+/', $markup, $header ) ) {
			return array();
		}
		$start = strlen( $header[0] );
		if ( '{' !== ( $markup[ $start ] ?? '' ) ) {
			return array();
		}
		$depth = 0;
		$end   = strlen( $markup );
		$json  = '';
		for ( $index = $start; $index < $end && $index - $start < 8192; ++$index ) {
			$character = $markup[ $index ];
			$depth    += '{' === $character ? 1 : ( '}' === $character ? -1 : 0 );
			if ( 0 === $depth ) {
				$json = substr( $markup, $start, $index - $start + 1 );
				break;
			}
		}
		$attrs = json_decode( $json, true );
		if ( ! is_array( $attrs ) || ! is_array( $attrs['wrappers'] ?? null ) || ! array_is_list( $attrs['wrappers'] ) ) {
			return array();
		}
		$lists = array();
		foreach ( $attrs['wrappers'] as $wrapper ) {
			$class   = is_array( $wrapper ) && is_array( $wrapper['attributes'] ?? null ) && is_string( $wrapper['attributes']['class'] ?? null ) ? $wrapper['attributes']['class'] : '';
			$tokens  = preg_split( '/\s+/', trim( $class ) );
			$lists[] = false === $tokens ? array() : array_values( array_filter( $tokens ) );
		}
		return $lists;
	}

	private static function is_source_host_class( string $class_name ): bool {
		if ( '' === $class_name
			|| str_starts_with( $class_name, 'wp-block-' )
			|| str_starts_with( $class_name, 'blocks-engine-' )
			|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_.:-]{0,79}$/D', $class_name )
		) {
			return false;
		}
		if ( in_array( $class_name, array( 'grid', 'flex', 'inline-flex' ), true )
			|| 1 === preg_match( '/(?:^|:)(?:grid-cols-|gap-)/', $class_name )
		) {
			return false;
		}
		return true;
	}

	/** Stable generated classes are provider hooks, never source presentation hooks. */
	public static function layout_scope( array $form ): string {
		$identity = $form['fallback_identity'] ?? '';
		if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity ) ) {
			return 'ssi-form-' . substr( $identity, 0, 12 );
		}
		return 'ssi-form-' . substr( hash( 'sha256', (string) ( $form['source_path'] ?? '' ) . "\n" . (string) ( $form['selector'] ?? '' ) ), 0, 12 );
	}

	public static function layout_node_class( string $scope, string $node ): string {
		return 'ssi-node-' . substr( hash( 'sha256', $scope . "\n" . $node ), 0, 12 );
	}

	private static function presentation_node_class( string $scope, int $index, string $role ): string {
		return self::layout_node_class( $scope, 'presentation-' . $index . '-' . $role );
	}

	private static function presentation_destination_class( string $scope, int $index, string $role ): string {
		return self::presentation_node_class( $scope, $index, 'control-' . $role ) . '-destination-' . $role;
	}

	/** @return array<int,array<string,bool>> */
	public static function presentation_roles( array $graph ): array {
		$roles = array();
		foreach ( $graph['controls'] ?? array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['index'] ?? null ) ) {
				continue;
			}
			foreach ( array( 'control', 'label', 'required_marker' ) as $role ) {
				if ( isset( $row[ $role ] ) ) {
					$roles[ $row['index'] ][ $role ] = true;
				}
			}
		}
		foreach ( $graph['control_containers'] ?? array() as $container ) {
			if ( is_array( $container ) && is_int( $container['index'] ?? null ) ) {
				$roles[ $container['index'] ]['control_container'] = true;
			}
		}
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_int( $variant['index'] ?? null ) && in_array( $variant['role'] ?? null, array( 'control', 'label', 'required_marker', 'control_container' ), true ) ) {
				$roles[ $variant['index'] ][ $variant['role'] ] = true;
			}
		}
		ksort( $roles, SORT_NUMERIC );
		return $roles;
	}

	/** Build only a topology-owned source-captured empty-country group. */
	public static function empty_country_visual_state( array $form, string $scope, array $phone_popup_targets ): array {
		$parts    = is_array( $form['presentation_graph']['visual_parts'] ?? null ) ? $form['presentation_graph']['visual_parts'] : array();
		$groups   = is_array( $form['presentation_graph']['visual_groups'] ?? null ) ? $form['presentation_graph']['visual_groups'] : array();
		$by_index = array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && is_int( $part['index'] ?? null ) ) {
				$by_index[ $part['index'] ][] = $part;
			}
		}
		foreach ( $phone_popup_targets as $auxiliary_index => $phone_index ) {
			$group = $by_index[ $auxiliary_index ] ?? array();
			if ( ! is_int( $auxiliary_index ) || ! is_int( $phone_index ) || count( $group ) < 1 || count( $group ) > 32 ) {
				continue;
			}
			$state_parts = array();
			$seen        = array();
			foreach ( $group as $part ) {
				if ( ! is_string( $part['id'] ?? null ) || isset( $seen[ $part['id'] ] ) || ! is_string( $part['markup'] ?? null ) || ! Static_Site_Importer_Provider_Form_Runtime_V1::valid_inline_svg( $part['markup'] ) ) {
					return array( 'diagnostics' => array( 'visual_state_rejected' ) );
				}
				$seen[ $part['id'] ] = true;
				$state_parts[]       = array(
					'id'     => $part['id'],
					'class'  => 'ssi-fvs-' . substr( hash( 'sha256', $scope . "\n" . $part['id'] ), 0, 12 ),
					'markup' => $part['markup'],
				);
			}
			$part_ids    = array_column( $state_parts, 'id' );
			$state_group = null;
			foreach ( $groups as $candidate ) {
				if ( is_array( $candidate ) && ( $candidate['part_ids'] ?? null ) === $part_ids && is_string( $candidate['id'] ?? null ) ) {
					$state_group = array(
						'id'    => $candidate['id'],
						'class' => 'ssi-fvg-' . substr( hash( 'sha256', $scope . "\n" . $candidate['id'] ), 0, 12 ),
					);
					break;
				}
			}
			if ( null === $state_group ) {
				return array( 'diagnostics' => array( 'visual_state_group_geometry_gap' ) );
			}
			$css = self::empty_country_visual_css( $scope, $state_group, $state_parts, $group, $groups, $form['presentation_graph']['variants'] ?? array() );
			return array(
				'trigger_class' => self::presentation_destination_class( $scope, $auxiliary_index, 'country-trigger' ),
				'state'         => array(
					'schema'        => 'static-site-importer/form-visual-state/v1',
					'field_id'      => $scope . '-field-' . $phone_index,
					'trigger_class' => self::presentation_destination_class( $scope, $auxiliary_index, 'country-trigger' ),
					'group'         => $state_group,
					'parts'         => $state_parts,
					'css'           => $css,
				),
				'diagnostics'   => array(),
			);
		}
		return array();
	}

	/** Compile only validated visual-part CSS facts into the declared state wrapper. */
	private static function empty_country_visual_css( string $scope, array $state_group, array $state_parts, array $parts, array $groups, array $variants ): string {
		$classes = array_column( $state_parts, 'class', 'id' );
		$rules   = array();
		$map     = Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys() + array(
			'align_items'     => 'align-items',
			'flex_direction'  => 'flex-direction',
			'gap'             => 'gap',
			'justify_content' => 'justify-content',
		);
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ( $state_group['id'] ?? null ) !== ( $group['id'] ?? null ) || ! is_array( $group['source_css']['styles'] ?? null ) ) {
				continue;
			}
			$declarations = array();
			foreach ( $group['source_css']['styles'] as $key => $value ) {
				if ( isset( $map[ $key ] ) && is_string( $value ) ) {
					$declarations[] = $map[ $key ] . ':' . $value . '!important';
				}
			}
			if ( ! empty( $declarations ) ) {
				$rules[] = '.' . $scope . ' .ssi-form-visual-state:not([hidden]).' . $state_group['class'] . '{' . implode( ';', $declarations ) . '}';
			}
		}
		foreach ( $parts as $part ) {
			$styles = $part['source_css']['styles'] ?? array();
			if ( ! is_array( $styles ) || ! isset( $classes[ $part['id'] ?? '' ] ) ) {
				continue;
			}
			$declarations = array();
			foreach ( $styles as $key => $value ) {
				if ( isset( $map[ $key ] ) && is_string( $value ) ) {
					$declarations[] = $map[ $key ] . ':' . $value . '!important';
				}
			}
			if ( ! empty( $declarations ) ) {
				$rules[] = '.' . $scope . ' .ssi-form-visual-state .' . $classes[ $part['id'] ] . '{' . implode( ';', $declarations ) . '}';
			}
		}
		foreach ( $variants as $variant ) {
			if ( 'visual_group' === ( $variant['role'] ?? null ) && ( $state_group['id'] ?? null ) === ( $variant['group_id'] ?? null ) && is_array( $variant['style_patch'] ?? null ) && is_array( $variant['condition'] ?? null ) && 'media' === ( $variant['condition']['kind'] ?? null ) && is_string( $variant['condition']['query'] ?? null ) ) {
				$declarations = array();
				foreach ( $variant['style_patch'] as $key => $value ) {
					if ( isset( $map[ $key ] ) && is_string( $value ) ) {
						$declarations[] = $map[ $key ] . ':' . $value . '!important';
					}
				}
				if ( ! empty( $declarations ) ) {
					$rules[] = '@media ' . $variant['condition']['query'] . '{.' . $scope . ' .ssi-form-visual-state:not([hidden]).' . $state_group['class'] . '{' . implode( ';', $declarations ) . '}}';
				}
			}
			if ( 'visual_part' !== ( $variant['role'] ?? null ) || ! isset( $classes[ $variant['part_id'] ?? '' ] ) || ! is_array( $variant['style_patch'] ?? null ) || ! is_array( $variant['condition'] ?? null ) || 'media' !== ( $variant['condition']['kind'] ?? null ) || ! is_string( $variant['condition']['query'] ?? null ) ) {
				continue;
			}
			$declarations = array();
			foreach ( $variant['style_patch'] as $key => $value ) {
				if ( isset( $map[ $key ] ) && is_string( $value ) ) {
					$declarations[] = $map[ $key ] . ':' . $value . '!important';
				}
			}
			if ( ! empty( $declarations ) ) {
				$rules[] = '@media ' . $variant['condition']['query'] . '{.' . $scope . ' .ssi-form-visual-state .' . $classes[ $variant['part_id'] ] . '{' . implode( ';', $declarations ) . '}}';
			}
		}
		return implode( "\n", array_values( array_unique( $rules ) ) );
	}

	/**
		 * Jetpack owns this composite's rendered roles. Captured input presentation
		 * belongs to its value input; the additional provider shell is structural.
		 * Prefix chrome between a reconstructed source flex parent and the trigger
		 * stretches and centres so the trigger keeps the source inner offset.
	 */
	private static function phone_presentation_destinations( string $scope, int $index ): array {
		return array(
			array(
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'shell' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'shell' ),
				'properties' => array(),
				'resets'     => array(
					'padding'     => '0',
					'border'      => '0',
					'background'  => 'transparent',
					'text-indent' => '0',
					'gap'         => '0',
				),
				'priority'   => 'important',
			),
			array(
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'primary' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'primary' ),
				'properties' => array_keys( Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys() ),
				'priority'   => 'important',
			),
			array(
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'carrier' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'carrier' ),
				'properties' => array(),
				'resets'     => array(
					'flex'      => '1 1 0',
					'min-width' => '0',
				),
				'priority'   => 'important',
			),
			array(
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'prefix' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'prefix' ),
				'properties' => array(),
				'resets'     => array(
					'display'     => 'flex',
					'align-items' => 'center',
					'height'      => '100%',
				),
				'priority'   => 'important',
			),
		);
	}

	/** Jetpack fields default to flex:1 1 100%; preserve a source fixed width's default flex behavior. */
	public static function fixed_width_uses_default_flex( array $node ): bool {
		$layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : ( is_array( $node['layout_patch'] ?? null ) ? $node['layout_patch'] : array() );
		$width  = $layout['width'] ?? null;
		if ( ! is_string( $width ) || ! preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em)$/D', $width ) || array_intersect( array( 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' ), array_keys( $layout ) ) ) {
			return false;
		}
		foreach ( $node['provenance'] ?? array() as $fact ) {
			if ( is_array( $fact ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && in_array( 'width', $fact['properties'] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve source flex stretch into the generated Button wrapper's layout.
	 *
	 * The graph deliberately omits controls with no authored declarations, so a
	 * submit can be present only in topology. This recognizes that narrow case
	 * without adding browser-computed geometry to the producer contract.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>}
	 */
	public static function form_layout_intent( array $form ): array {
		$graph    = is_array( $form['layout_graph'] ?? null ) ? $form['layout_graph'] : array();
		$controls = is_array( $form['controls'] ?? null ) ? $form['controls'] : array();
		$nodes    = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = $node;
			}
		}
		$form_layout  = is_array( $nodes['form']['layout'] ?? null ) ? $nodes['form']['layout'] : array();
		$intent_nodes = array();
		$variants     = array();
		$operations   = array();
		$conditions   = array();
		foreach ( $form['control_topology']['nodes'] ?? array() as $topology_node ) {
			$index = is_array( $topology_node ) ? ( $topology_node['control'] ?? null ) : null;
			if ( ! is_int( $index ) || null !== ( $topology_node['parent'] ?? null ) || 'submit' !== strtolower( (string) ( $controls[ $index ]['type'] ?? '' ) ) ) {
				continue;
			}
			$control_id     = 'control-' . $index;
			$control_layout = is_array( $nodes[ $control_id ]['layout'] ?? null ) ? $nodes[ $control_id ]['layout'] : array();
			if ( isset( $nodes[ $control_id ] ) || ! self::submit_allows_flex_stretch( $control_layout ) ) {
				continue;
			}
			$base_stretches = self::is_stretching_column_flex( $form_layout );
			foreach ( $graph['variants'] ?? array() as $variant ) {
				if ( ! is_array( $variant ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
					continue;
				}
				if ( 'form' === ( $variant['node'] ?? null ) && array_intersect( array( 'display', 'direction', 'align_items' ), array_keys( $variant['layout_patch'] ) ) && ! self::is_stretching_column_flex( array_merge( $form_layout, $variant['layout_patch'] ) ) ) {
					$base_stretches = false;
				}
				if ( ( $variant['node'] ?? null ) === $control_id && ! self::submit_allows_flex_stretch( array_merge( $control_layout, $variant['layout_patch'] ) ) ) {
					$base_stretches = false;
				}
			}
			if ( $base_stretches ) {
				$intent_nodes[] = array(
					'id'     => $control_id,
					'layout' => array( 'align_self' => 'stretch' ),
				);
				$operations[]   = array(
					'dimension' => 'layout',
					'strategy'  => 'form_layout_intent_flex_stretch_submit',
					'node_hash' => hash( 'sha256', $control_id ),
				);
			}
			foreach ( $graph['variants'] ?? array() as $variant ) {
				if ( ! is_array( $variant ) || 'form' !== ( $variant['node'] ?? null ) || ! is_array( $variant['condition'] ?? null ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
					continue;
				}
				$condition_key = (string) wp_json_encode( $variant['condition'] );
				if ( isset( $conditions[ $condition_key ] ) ) {
					continue;
				}
				$conditions[ $condition_key ] = true;
				$parent_patch                 = array();
				foreach ( $graph['variants'] as $parent_variant ) {
					if ( is_array( $parent_variant ) && 'form' === ( $parent_variant['node'] ?? null ) && wp_json_encode( $parent_variant['condition'] ?? null ) === $condition_key && is_array( $parent_variant['layout_patch'] ?? null ) ) {
						$parent_patch = array_merge( $parent_patch, $parent_variant['layout_patch'] );
					}
				}
				$parent_layout = array_merge( $form_layout, $parent_patch );
				if ( ! self::is_stretching_column_flex( $parent_layout ) ) {
					continue;
				}
				$control_patch = array();
				foreach ( $graph['variants'] as $control_variant ) {
					if ( is_array( $control_variant ) && ( $control_variant['node'] ?? null ) === $control_id && wp_json_encode( $control_variant['condition'] ?? null ) === $condition_key && is_array( $control_variant['layout_patch'] ?? null ) ) {
						$control_patch = array_merge( $control_patch, $control_variant['layout_patch'] );
					}
				}
				if ( ! self::submit_allows_flex_stretch( array_merge( $control_layout, $control_patch ) ) ) {
					continue;
				}
				$variants[]   = array(
					'node'         => $control_id,
					'condition'    => $variant['condition'],
					'layout_patch' => array( 'align_self' => 'stretch' ),
				);
				$operations[] = array(
					'dimension'  => 'layout',
					'strategy'   => 'form_layout_intent_flex_stretch_submit',
					'node_hash'  => hash( 'sha256', $control_id ),
					'responsive' => true,
				);
			}
		}
		return array(
			'nodes'      => $intent_nodes,
			'variants'   => $variants,
			'operations' => $operations,
		);
	}

	private static function is_stretching_column_flex( array $layout ): bool {
		return 'flex' === ( $layout['display'] ?? null ) && 'column' === ( $layout['direction'] ?? null ) && in_array( $layout['align_items'] ?? 'stretch', array( 'stretch' ), true );
	}

	private static function submit_allows_flex_stretch( array $layout ): bool {
		return ! array_intersect( array( 'width', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' ), array_keys( $layout ) ) && in_array( $layout['align_self'] ?? 'auto', array( 'auto', 'stretch' ), true );
	}

	public static function presentation_descriptor( string $scope, int $index, string $type, array $roles ): array {
		$control_class      = isset( $roles['control'] ) ? self::presentation_node_class( $scope, $index, 'control' ) : '';
		$label_class        = isset( $roles['label'] ) || isset( $roles['required_marker'] ) ? self::presentation_node_class( $scope, $index, 'label' ) : '';
		$phone_destinations = array();
		$destinations       = array();

		if ( '' !== $control_class ) {
			if ( in_array( $type, array( 'phone', 'tel' ), true ) ) {
				$phone_destinations = self::phone_presentation_destinations( $scope, $index );
				$destinations       = $phone_destinations;
			} else {
				$properties   = array_keys( 'submit' === $type ? Static_Site_Importer_Provider_Layout_Overlay::positioned_control_presentation_property_keys() : Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys() );
				$inner_suffix = 'submit' === $type ? ' > .wp-block-button__link' : ( 'select' === $type ? ' select' : '' );
				if ( '' !== $inner_suffix ) {
					$wrapper_properties = array( 'display', 'width', 'min_width' );
					if ( 'submit' === $type ) {
						// Gutenberg's `is-layout-flex > * { margin:0 }` zeros a submit
						// wrapper's authored class margin. Carry vertical spacing onto
						// that wrapper through the overlay so it outranks the layout
						// reset, and keep it off the inner link (which is also zeroed).
						$wrapper_properties = array_merge(
							$wrapper_properties,
							array( 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end' )
						);
					}
					$wrapper = array(
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class,
						// A nested native control's wrapper shrink-wraps unless source
						// display, width, and min-width reach it. The authored box belongs
						// on the inner control, the same way submit facts reach the link.
						'properties' => $wrapper_properties,
					);
					if ( 'select' === $type ) {
						// Jetpack parks input className on the select wrapper and paints
						// that wrapper as a second box. Neutralize it so only the inner
						// control carries the authored padding, border, and background.
						$wrapper['resets'] = array(
							'padding'    => '0',
							'border'     => '0',
							'background' => 'transparent',
						);
					}
					$destinations[] = $wrapper;
					$properties     = array_values( array_diff( $properties, $wrapper_properties ) );
				}
				$destination = array(
					'role'       => 'control',
					'selector'   => '.' . $scope . ' .' . $control_class . $inner_suffix,
					'properties' => $properties,
					// Native fields revert unowned typography to the browser control
					// default. A submit is painted as wp-element-button, so the same
					// reset would resolve to UA `normal` and drop document-authored
					// line-height the source button inherited. Inherit instead; an
					// explicit source declaration still wins at this destination.
					'resets'     => array_merge(
						array(
							'font-family' => 'submit' === $type ? 'inherit' : 'revert',
							'line-height' => 'submit' === $type ? 'inherit' : 'revert',
						),
						'submit' === $type ? array( 'min-height' => '0' ) : array(),
						// Jetpack renders the native control with `appearance: none`,
						// which removes the platform chevron the authored select had.
						// Reverting to `auto` restores it alongside the authored
						// padding and border this destination already carries.
						'select' === $type ? array( 'appearance' => 'auto' ) : array()
					),
				);
				if ( 'select' === $type ) {
					// Jetpack forces `border:0!important` on the nested `<select>`.
					$destination['priority'] = 'important';
				}
				$destinations[] = $destination;
				if ( 'submit' === $type ) {
					// A submit control sits as a bare direct child of its source
					// container, unlike every other field, which is wrapped in its own
					// box. A sibling-stacking utility (Tailwind's `space-y-*`) that
					// matches direct children therefore captures a real vertical margin
					// fact against the button itself. The provider's own field gap
					// already reproduces that inter-sibling spacing structurally (the
					// layout graph's own `margin-block-start` reset on this same node
					// already neutralizes the layout-level half of that gap); carrying
					// the captured vertical margin onto the rendered link as well would
					// double it a second time inside the button's own wrapper. The
					// margin properties stay listed above so a captured fact is still
					// represented (not a receipt loss); this unconditional, later,
					// `!important` reset is what actually wins the cascade.
					$destinations[] = array(
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class . ' > .wp-block-button__link',
						'properties' => array(),
						'resets'     => array(
							'margin' => '0',
						),
						'priority'   => 'important',
					);
				}
			}
		}
		if ( isset( $roles['control_container'] ) ) {
			$destinations[] = array(
				'role'       => 'control_container',
				// The source chrome owns the provider field wrapper, not its nested input.
				// The layout hook is placed on that wrapper for every Jetpack field type.
				'selector'   => '.' . $scope . ' .' . self::layout_node_class( $scope, 'control-' . $index ),
				'properties' => array( 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius' ),
			);
		}
		if ( isset( $roles['label'] ) ) {
			$destinations[] = array(
				'role'       => 'label',
				'selector'   => '.' . $scope . ' .' . $label_class,
				'properties' => array_keys( Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys() ),
				'resets'     => array( 'font-weight' => 'inherit' ),
			);
		}
		if ( isset( $roles['required_marker'] ) ) {
			$destinations[] = array(
				'role'       => 'required_marker',
				'selector'   => '.' . $scope . ' .' . $label_class . ' > .grunion-label-required',
				'properties' => array_keys( Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys() ),
				'resets'     => array( 'font-size' => 'inherit' ),
				'priority'   => 'important',
			);
		}

		return array(
			'has_presentation'   => ! empty( $roles ),
			'control_class'      => $control_class,
			'label_class'        => $label_class,
			'phone_destinations' => $phone_destinations,
			'destinations'       => $destinations,
		);
	}

	/** Same source facts, explicit destinations for Jetpack's editable DOM. */
	public static function editor_layout_target_map( array $map, string $scope ): array {
		foreach ( $map['targets'] as &$target ) {
			if ( 'form' === $target['node'] ) {
				$target['selector'] = '.' . $scope . ' > div.jetpack-contact-form';
			} elseif ( preg_match( '/^field-([0-9]+)$/D', $target['node'], $match ) ) {
				$target['selector'] = '.' . $scope . ' .' . self::layout_node_class( $scope, 'control-' . $match[1] ) . ' > div.jetpack-field__control';
			}
		}
		unset( $target );
		foreach ( $map['presentation_targets'] as &$target ) {
			$extra = array();
			foreach ( $target['destinations'] as &$destination ) {
				if ( 'label' === $destination['role'] ) {
					$layout_properties                = array_values( array_filter( $destination['properties'], static fn( string $key ): bool => str_starts_with( $key, 'margin' ) || str_starts_with( $key, 'padding' ) || in_array( $key, array( 'width', 'max_width', 'min_width', 'box_sizing' ), true ) ) );
					$extra[]                          = array(
						'role'       => 'label',
						'selector'   => $destination['selector'],
						'properties' => $layout_properties,
						'resets'     => array(
							'display' => 'block',
						),
					);
					$destination['properties']        = array_values( array_diff( $destination['properties'], $layout_properties ) );
					$destination['selector']         .= ' > label';
					$destination['resets']['display'] = 'block';
					$destination['resets']['margin']  = '0';
					$destination['resets']['padding'] = '0';
				}
				if ( 'control' === $destination['role'] && preg_match( '/ \.ssi-node-[a-f0-9]{12}$/D', $destination['selector'] ) ) {
					$extra[] = array(
						'role'       => 'control',
						'selector'   => $destination['selector'] . '::placeholder',
						'properties' => array(),
						'resets'     => array(
							'color'   => 'revert',
							'opacity' => 'revert',
						),
					);
				}
				if ( str_ends_with( $destination['selector'], ' > .wp-block-button__link' ) ) {
					unset( $destination['resets']['font-family'], $destination['resets']['line-height'] );
					$destination['resets']['font'] = '-webkit-small-control';
				}
			}
			unset( $destination );
			$target['destinations'] = array_merge( $target['destinations'], $extra );
		}
		unset( $target );
		return $map;
	}

	/**
	 * Drop grid placement a provider cannot honor.
	 *
	 * Siblings that declare the same source grid row are separated by offsets
	 * outside the layout graph's vocabulary. A provider that gives each control
	 * its own row addresses different controls with those row indexes, so the
	 * placement would reorder the form instead of reproducing it.
	 *
	 * Placement is judged against the complete source graph, because a partly
	 * represented sibling set can look like an ordered sequence on its own.
	 *
	 * @param array<string,mixed> $graph
	 * @param array<string,mixed>|null $source_graph
	 * @return array<string,mixed>
	 */
	public static function without_shared_source_grid_rows( array $graph, ?array $source_graph = null ): array {
		$shared = self::shared_source_grid_row_nodes( is_array( $source_graph ) ? $source_graph : $graph );
		if ( empty( $shared ) ) {
			return $graph;
		}
		// Track indexes describe the same rows being dropped. Keeping them would
		// give every field its own row and undo the column membership the provider
		// states for itself. The authored display mode must still reach the field
		// list: Jetpack paints `display:flex` on `.wp-block-jetpack-contact-form`
		// unless `is-layout-flex` is present, which outranks a class like `.grid`.
		// Column templates stay with the source stylesheet.
		// The source container may already be collapsed onto the provider's own form
		// element, so the track definition can sit on either node.
		$containers = array( 'form' => true );
		foreach ( ( is_array( $source_graph ) ? $source_graph : $graph )['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && isset( $shared[ (string) ( $node['id'] ?? '' ) ] ) && is_string( $node['parent'] ?? null ) ) {
				$containers[ $node['parent'] ] = true;
			}
		}
		foreach ( $graph['nodes'] ?? array() as $index => $node ) {
			if ( ! is_array( $node ) || ! is_array( $node['layout'] ?? null ) ) {
				continue;
			}
			if ( isset( $shared[ (string) ( $node['id'] ?? '' ) ] ) ) {
				$graph['nodes'][ $index ]['layout'] = array_diff_key( $node['layout'], array_flip( array( 'area', 'row' ) ) );
			}
			if ( isset( $containers[ (string) ( $node['id'] ?? '' ) ] ) && 'grid' === ( $node['layout']['display'] ?? '' ) ) {
				$graph['nodes'][ $index ]['layout'] = array_diff_key( $graph['nodes'][ $index ]['layout'], array_flip( array( 'columns', 'rows' ) ) );
			}
		}
		foreach ( $graph['variants'] ?? array() as $index => $variant ) {
			$patch        = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
			$drops_tracks = (bool) array_intersect( array( 'columns', 'rows' ), array_keys( $patch ) );
			$grid_display = 'grid' === ( $patch['display'] ?? '' );
			if ( ! is_array( $variant ) || ! isset( $containers[ (string) ( $variant['node'] ?? '' ) ] ) || empty( $patch ) || ( ! $grid_display && ! $drops_tracks ) ) {
				continue;
			}
			$patch = array_diff_key( $patch, array_flip( array( 'columns', 'rows' ) ) );
			if ( empty( $patch ) ) {
				unset( $graph['variants'][ $index ] );
				continue;
			}
			$graph['variants'][ $index ]['layout_patch'] = $patch;
		}
		foreach ( $graph['variants'] ?? array() as $index => $variant ) {
			if ( ! is_array( $variant ) || ! isset( $shared[ (string) ( $variant['node'] ?? '' ) ] ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
				continue;
			}
			$patch = array_diff_key( $variant['layout_patch'], array_flip( array( 'area', 'row' ) ) );
			if ( empty( $patch ) ) {
				unset( $graph['variants'][ $index ] );
				continue;
			}
			$graph['variants'][ $index ]['layout_patch'] = $patch;
		}
		if ( isset( $graph['variants'] ) && is_array( $graph['variants'] ) ) {
			$graph['variants'] = array_values( $graph['variants'] );
		}
		return $graph;
	}

	/**
	 * Groups of sibling boxes that a source grid places on one row.
	 *
	 * Only sized boxes qualify: a row is a band of columns when its members each
	 * declare how much of it they occupy. Their offsets are outside the layout
	 * graph's vocabulary, so the proportion comes from the widths themselves.
	 *
	 * @param array<string,array<string,mixed>> $layout_nodes_by_id
	 * @param array<string,array<int,array<string,mixed>>> $variants_by_node
	 * @return array<int,array<string,float>>
	 */
	private static function source_grid_row_bands( array $layout_nodes_by_id, array $variants_by_node ): array {
		$rows = array();
		foreach ( $layout_nodes_by_id as $id => $node ) {
			if ( ! preg_match( '/^wrapper-[0-9]+$/D', (string) $id ) ) {
				continue;
			}
			$layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			$width  = self::declared_pixel_width( $layout );
			$row    = self::declared_grid_row( $layout );
			foreach ( $variants_by_node[ $id ] ?? array() as $variant ) {
				$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				$row   = '' === $row ? self::declared_grid_row( $patch ) : $row;
				$width = 0.0 === $width ? self::declared_pixel_width( $patch ) : $width;
			}
			if ( '' === $row || 0.0 === $width ) {
				continue;
			}
			$rows[ (string) ( $node['parent'] ?? '' ) . "\n" . $row ][ (string) $id ] = $width;
		}

		return array_values( array_filter( $rows, static fn ( array $band ): bool => count( $band ) > 1 ) );
	}

	/**
	 * Whether a media condition widens at a single fixed breakpoint: the legacy
	 * `(min-width: 768px)` form and the modern CSS range syntax `(width>=768px)`
	 * Tailwind CSS v4 emits for its own `md:`/`lg:`/etc. prefixes.
	 */
	private static function is_min_width_media_query( string $query ): bool {
		return 1 === preg_match( '/^\((?:min-width:\s?[0-9]+(?:\.[0-9]+)?(?:px|em|rem)|width\s*>=\s*[0-9]+(?:\.[0-9]+)?(?:px|em|rem))\)$/D', $query );
	}

	/**
	 * Invert a proven min-width widening into the max-range the overlay applies
	 * below that breakpoint. `(min-width: 768px)` and `(width>=40rem)` both
	 * include the bound, so the stacked state is the strict less-than range.
	 */
	private static function inverted_min_width_media_query( string $query ): ?string {
		if ( 1 === preg_match( '/^\(min-width:\s?([0-9]+(?:\.[0-9]+)?)(px|em|rem)\)$/D', $query, $match ) ) {
			return '(width<' . $match[1] . $match[2] . ')';
		}
		if ( 1 === preg_match( '/^\(width\s*>=\s*([0-9]+(?:\.[0-9]+)?)(px|em|rem)\)$/D', $query, $match ) ) {
			return '(width<' . $match[1] . $match[2] . ')';
		}
		return null;
	}

	/**
	 * Recover a stacking query from a captured cascade fact when the equal-width
	 * row came from the class-token fallback rather than a graph variant.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $variants_by_node
	 * @param array<string,array<string,mixed>>            $layout_nodes_by_id
	 */
	private static function equal_width_stack_query_from_cascade_facts( string $parent_id, array $variants_by_node, array $layout_nodes_by_id ): ?string {
		foreach ( $variants_by_node[ $parent_id ] ?? array() as $variant ) {
			$condition = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
			if ( ! is_array( $condition ) || 'media' !== ( $condition['kind'] ?? null ) || ! is_string( $condition['query'] ?? null ) || ! self::is_min_width_media_query( $condition['query'] ) ) {
				continue;
			}
			$inverted = self::inverted_min_width_media_query( $condition['query'] );
			if ( is_string( $inverted ) ) {
				return $inverted;
			}
		}
		foreach ( ( $layout_nodes_by_id[ $parent_id ]['provenance'] ?? array() ) as $fact ) {
			$condition = is_array( $fact ) && is_array( $fact['condition'] ?? null ) ? $fact['condition'] : null;
			if ( ! is_array( $condition ) || 'media' !== ( $condition['kind'] ?? null ) || ! is_string( $condition['query'] ?? null ) || ! self::is_min_width_media_query( $condition['query'] ) || ! in_array( 'grid-template-columns', $fact['properties'] ?? array(), true ) ) {
				continue;
			}
			$inverted = self::inverted_min_width_media_query( $condition['query'] );
			if ( is_string( $inverted ) ) {
				return $inverted;
			}
		}
		return null;
	}

	/** @param array<int,string> $tokens */
	private static function display_from_class_tokens( array $tokens ): ?string {
		$map     = array(
			'grid'        => 'grid',
			'flex'        => 'flex',
			'inline-flex' => 'inline-flex',
			'block'       => 'block',
			'contents'    => 'contents',
			'hidden'      => 'none',
		);
		$display = null;
		foreach ( $tokens as $token ) {
			if ( isset( $map[ $token ] ) ) {
				$display = $map[ $token ];
			}
		}
		return $display;
	}

	/**
	 * Equal-fraction track count Jetpack can express as a field `width` of
	 * 50 / 33 / 25. Null when the value is not that shape.
	 */
	private static function equal_fraction_column_count( string $columns ): ?int {
		foreach ( array( 2, 3, 4 ) as $count ) {
			if ( self::is_equal_fraction_columns( $columns, $count ) ) {
				return $count;
			}
		}
		return null;
	}

	/**
	 * Whether a resolved `grid-template-columns` value is exactly $count equal
	 * fractional tracks. Accepts the plain `1fr` form and the `minmax(0,1fr)`
	 * form utility frameworks such as Tailwind emit for their grid-cols-N classes.
	 */
	private static function is_equal_fraction_columns( string $columns, int $count ): bool {
		if ( 'repeat(' . $count . ',1fr)' === $columns || str_repeat( '1fr', $count ) === $columns ) {
			return true;
		}
		return 1 === preg_match( '/^repeat\(' . $count . ',minmax\(0(?:px)?,1fr\)\)$/D', $columns );
	}

	/** Jetpack subtracts a whole gap from each field; the source share is gap * (count-1)/count. */
	private static function equal_fraction_track_size( int $count, string $gap ): string {
		$share     = 100 / $count;
		$share_css = rtrim( rtrim( number_format( $share, 3, '.', '' ), '0' ), '.' );
		if ( 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)(px|rem|em)$/D', trim( $gap ), $match ) ) {
			$match = array( '1.5rem', '1.5', 'rem' );
		}
		$portion = (float) $match[1] * ( $count - 1 ) / $count;
		$gap_css = rtrim( rtrim( number_format( $portion, 4, '.', '' ), '0' ), '.' ) . $match[2];
		return 'calc(' . $share_css . '% - ' . $gap_css . ')';
	}

	/** @param array<string,mixed> $layout */
	private static function declared_pixel_width( array $layout ): float {
		return 1 === preg_match( '/^([0-9]+(?:\.[0-9]+)?)px$/D', trim( (string) ( $layout['width'] ?? '' ) ), $match ) ? (float) $match[1] : 0.0;
	}

	/** The provider states column membership in fixed steps, so snap to the nearest. */
	private static function provider_field_width( float $share ): int {
		$closest = 100;
		foreach ( array( 25, 33, 50, 75, 100 ) as $step ) {
			if ( abs( $share - ( $step / 100 ) ) < abs( $share - ( $closest / 100 ) ) ) {
				$closest = $step;
			}
		}

		return $closest;
	}

	/**
	 * Source boxes whose grid row placement cannot be transposed to a provider.
	 *
	 * @param array<string,mixed> $graph
	 * @return array<string,bool>
	 */
	private static function shared_source_grid_row_nodes( array $graph ): array {
		$parents = array();
		$rows    = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$parents[ $node['id'] ] = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$row                    = self::declared_grid_row( is_array( $node['layout'] ?? null ) ? $node['layout'] : array() );
			if ( '' !== $row ) {
				$rows[ $node['id'] ][ $row ] = true;
			}
		}
		foreach ( $graph['variants'] ?? array() as $variant ) {
			$id = is_array( $variant ) && is_string( $variant['node'] ?? null ) ? $variant['node'] : '';
			if ( '' === $id || ! isset( $parents[ $id ] ) ) {
				continue;
			}
			$row = self::declared_grid_row( is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array() );
			if ( '' !== $row ) {
				$rows[ $id ][ $row ] = true;
			}
		}
		$by_parent = array();
		foreach ( $rows as $id => $declared ) {
			$parent                        = $parents[ $id ] ?? '';
			$by_parent[ $parent ]['boxes'] = ( $by_parent[ $parent ]['boxes'] ?? 0 ) + 1;
			foreach ( array_keys( $declared ) as $row ) {
				$by_parent[ $parent ]['rows'][ $row ] = true;
			}
		}
		$scrambled = array();
		foreach ( $by_parent as $parent => $summary ) {
			$distinct = count( $summary['rows'] );
			// One row for every box transposes as an ordered sequence, and a single
			// shared row transposes as one band. Any other mix means the provider's
			// own row sequence no longer lines up with these row indexes.
			if ( 1 < $distinct && $summary['boxes'] !== $distinct ) {
				$scrambled[ $parent ] = true;
			}
		}
		$shared = array();
		foreach ( $parents as $id => $parent ) {
			if ( isset( $scrambled[ $parent ] ) ) {
				$shared[ $id ] = true;
			}
		}
		return $shared;
	}

	/** @param array<string,mixed> $layout */
	private static function layout_row_gap( array $layout ): ?string {
		foreach ( array( 'row_gap', 'gap' ) as $property ) {
			if ( is_string( $layout[ $property ] ?? null ) && '' !== trim( $layout[ $property ] ) ) {
				return trim( $layout[ $property ] );
			}
		}
		return null;
	}

	/** @param array<string,mixed> $layout */
	private static function declared_grid_row( array $layout ): string {
		$area = trim( (string) ( $layout['area'] ?? '' ) );
		if ( '' !== $area ) {
			$parts = preg_split( '#\s*/\s*#', $area );
			return is_array( $parts ) ? trim( $parts[0] ) : '';
		}
		return trim( (string) ( $layout['row'] ?? '' ) );
	}

	public static function provider_layout_target_map( array $form, string $scope, array $presentation_descriptors, array $box_targets = array(), array $phone_popup_targets = array(), string $country_trigger_class = '' ): array {
		$selector_scope = '.' . $scope;
		$targets        = array();
		foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$id = $node['id'];
			if ( 'form' !== $id && 'form-box' !== $id && 'field-list' !== $id && ! isset( $box_targets[ $id ] ) && ! preg_match( '/^(?:control|field)-[0-9]+$/D', $id ) ) {
				continue;
			}
			if ( 'form-box' === $id ) {
				// The provider renders its block wrapper at the source form's own position,
				// so that wrapper is the element carrying the form box's page placement.
				$selector     = $selector_scope;
				$capabilities = array( 'direct_child_layout', 'item_layout', 'responsive_layout' );
			} elseif ( 'field-list' === $id ) {
				$selector     = $selector_scope . ' .ssi-source-field-list';
				$capabilities = array( 'container_layout', 'responsive_layout' );
			} elseif ( 'form' === $id ) {
				// Jetpack renders its own form element for a page's first contact form
				// and lays later ones out directly in the block wrapper. Address both,
				// so the source container layout reaches the element that actually
				// positions the fields instead of leaving the runtime default in place.
				$selector = $selector_scope . ' > form.jetpack-contact-form__form, ' . $selector_scope . ':not(:has(> form.jetpack-contact-form__form))';
				// Jetpack's contact-form root includes hidden and error nodes, so it cannot
				// promise source direct-child relationships. Generated node hooks can.
				$capabilities = array( 'container_layout', 'responsive_layout' );
			} elseif ( preg_match( '/^field-([0-9]+)$/D', $id, $matches ) ) {
				$selector     = $selector_scope . ' .' . self::layout_node_class( $scope, 'control-' . $matches[1] ) . '-wrap';
				$capabilities = array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' );
			} else {
				$selector     = $selector_scope . ' .' . ( $box_targets[ $id ] ?? self::layout_node_class( $scope, $id ) );
				$capabilities = array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' );
			}
			$targets[] = array(
				'node'         => $id,
				'selector'     => $selector,
				'capabilities' => $capabilities,
			);
		}
		$presentation_targets = array();
		foreach ( $presentation_descriptors as $index => $descriptor ) {
			if ( ! $descriptor['has_presentation'] ) {
				continue;
			}
			$target = array(
				'index'        => $index,
				'destinations' => $descriptor['destinations'],
			);
			if ( '' !== $descriptor['control_class'] && isset( $phone_popup_targets[ $index ] ) && '' !== $country_trigger_class ) {
				$target['destinations'] = array_values( array_filter( $target['destinations'], static fn( array $destination ): bool => 'control' !== ( $destination['role'] ?? null ) ) );
				array_unshift( $target['destinations'], array(
					'role'       => 'control',
					'selector'   => $selector_scope . ' .' . $country_trigger_class,
					'properties' => array_keys( Static_Site_Importer_Provider_Layout_Overlay::positioned_control_presentation_property_keys() ),
					'priority'   => 'important',
				) );
			}
			foreach ( $target['destinations'] as &$destination ) {
				unset( $destination['class'] );
			}
			unset( $destination );
			$presentation_targets[] = $target;
		}
		return array(
			'schema'               => Static_Site_Importer_Provider_Layout_Overlay::MAP_SCHEMA,
			'provider'             => Static_Site_Importer_Form_Seeder::PROVIDER_ID,
			'scope'                => $selector_scope,
			'targets'              => $targets,
			'presentation_targets' => $presentation_targets,
		);
	}
}
