<?php
/**
 * Grid placement projection for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects source grid placement, spans and percentage widths onto provider
 * fields, one named pass at a time.
 */
final class Static_Site_Importer_Form_Grid_Placement {
	/**
	 * Project source grid placement, spans and percentage widths onto provider fields.
	 *
	 * @internal
	 */
	public static function project_grid_placement( Static_Site_Importer_Form_Topology_State $s ): void {
		self::project_fullspan_grid_children( $s );
		self::project_fullspan_grid_branches( $s );
		self::project_grid_span_submits( $s );
		self::project_grid_track_control_widths( $s );
		self::project_native_control_visibility( $s );
		self::project_percentage_width_fields( $s );
		self::project_omitted_runtime_controls( $s );
	}

	/** Rebuild the child slot around an evidenced full-span grid value. */
	private static function project_fullspan_grid_children( Static_Site_Importer_Form_Topology_State $s ): void {
		// Jetpack's field shell exposes a real child slot only around the native value.
		// Preserve an evidenced full-span value by rebuilding that child wrapper rather
		// than collapsing the source grid tracks to a guessed width.
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'control' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) || ! is_int( $node['control'] ?? null ) || ! isset( $s->field_blocks[ $node['control'] ] ) || 'core/button' === ( $s->field_blocks[ $node['control'] ]['name'] ?? '' ) ) {
				continue;
			}
			$parent_id        = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$parent_layout    = $s->layout_nodes_by_id[ $parent_id ] ?? null;
			$control_layout   = $s->layout_nodes_by_id[ $node['id'] ] ?? null;
			$placement        = self::grid_placement( $control_layout );
			$full_span        = is_array( $parent_layout ) ? self::grid_column_span_width( $parent_layout['layout']['columns'] ?? null, $placement ) : null;
			$parent_proven    = is_array( $parent_layout ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $parent_layout, 'display' ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $parent_layout, 'grid-template-columns' );
			$placement_proven = self::grid_placement_proven( $control_layout );
			if ( '100%' !== $full_span || ! $parent_proven || ! $placement_proven || ! isset( $s->wrapper_hooks[ $parent_id ] ) || ! empty( $s->variants_by_node[ $parent_id ] ) || ! empty( $s->variants_by_node[ $node['id'] ] ) ) {
				continue;
			}
			$child_hook = Static_Site_Importer_Form_Layout_Projection::layout_node_class( Static_Site_Importer_Form_Layout_Projection::layout_scope( $s->form ), $node['id'] );
			$s->field_blocks[ $node['control'] ]['attrs']['className'] = Static_Site_Importer_Form_Layout_Projection::append_class( $s->field_blocks[ $node['control'] ]['attrs']['className'] ?? '', 'ssi-source-fullspan-child--' . $child_hook );
			$s->provider_layout_targets[ $node['id'] ]                 = $child_hook . '-wrap';
			$s->overlay_node_targets[]                                 = array(
				'id'     => $node['id'],
				'layout' => $control_layout['layout'],
			);
			$s->record( 'layout', 'provider_fullspan_grid_child', $node['id'] );
		}
	}

	/** Keep a full-span one-control grid branch on its source-owned elements. */
	private static function project_fullspan_grid_branches( Static_Site_Importer_Form_Topology_State $s ): void {
		// A source grid can place a one-control branch rather than the control itself.
		// The reconstructed branch's first child is the native grid item; retain that
		// relationship so responsive grid facts stay on their source-owned elements.
		foreach ( $s->nodes as $grid_node ) {
			if ( ! is_array( $grid_node ) || 'wrapper' !== ( $grid_node['kind'] ?? null ) || ! is_string( $grid_node['id'] ?? null ) ) {
				continue;
			}
			$grid_id         = $grid_node['id'];
			$grid_layout     = $s->layout_nodes_by_id[ $grid_id ] ?? null;
			$grid_controls   = array_values( array_filter( ( $s->collect_controls )( $grid_node ), static fn ( int $index ): bool => isset( $s->field_blocks[ $index ] ) && 'core/button' !== ( $s->field_blocks[ $index ]['name'] ?? '' ) ) );
			$grid_proven     = is_array( $grid_layout ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $grid_layout, 'display' ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $grid_layout, 'grid-template-columns' );
			$grid_variants   = $s->variants_by_node[ $grid_id ] ?? array();
			$variants_stable = true;
			foreach ( $grid_variants as $variant ) {
				$patch = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
				if ( isset( $patch['columns'] ) ) {
					$variants_stable = false;
					break;
				}
			}
			if ( 1 !== count( $grid_controls ) || ! $grid_proven || ! $variants_stable || ! isset( $s->wrapper_hooks[ $grid_id ] ) ) {
				continue;
			}
			foreach ( $s->children[ $grid_id ] ?? array() as $grid_child ) {
				$child_id         = 'wrapper' === ( $grid_child['kind'] ?? null ) ? $grid_child['id'] : '';
				$child_layout     = '' !== $child_id ? $s->layout_nodes_by_id[ $child_id ] ?? null : null;
				$child_controls   = '' !== $child_id ? $s->mapped_branch( $grid_child ) : array();
				$placement        = self::grid_placement( $child_layout );
				$full_span        = self::grid_column_span_width( $grid_layout['layout']['columns'] ?? null, $placement );
				$placement_proven = self::grid_placement_proven( $child_layout );
				if ( '' === $child_id || $grid_controls !== $child_controls || '100%' !== $full_span || ! $placement_proven || ! isset( $s->wrapper_hooks[ $child_id ] ) ) {
					continue;
				}
				$child_variants_stable = true;
				foreach ( $s->variants_by_node[ $child_id ] ?? array() as $variant ) {
					$patch = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
					if ( isset( $patch['column'] ) || isset( $patch['area'] ) ) {
						$child_variants_stable = false;
						break;
					}
				}
				if ( ! $child_variants_stable ) {
					continue;
				}
				$s->provider_layout_targets[ $grid_id ]  = $s->wrapper_hooks[ $grid_id ];
				$s->provider_layout_targets[ $child_id ] = $s->wrapper_hooks[ $child_id ];
				$s->record( 'layout', 'provider_fullspan_grid_branch', $child_id );
				break;
			}
		}
	}

	/** Carry a grid-spanned submit wrapper's placement on the button width. */
	private static function project_grid_span_submits( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->nodes as $node ) {
			$id               = is_array( $node ) && 'wrapper' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			$branch_controls  = '' !== $id ? ( $s->collect_controls )( $node ) : array();
			$control_index    = 1 === count( $branch_controls ) ? $branch_controls[0] : null;
			$layout_node      = $s->layout_nodes_by_id[ $id ] ?? null;
			$layout_parent    = is_array( $layout_node ) && is_string( $layout_node['parent'] ?? null ) ? $s->layout_nodes_by_id[ $layout_node['parent'] ] ?? null : null;
			$column           = self::grid_placement( $layout_node );
			$width            = is_array( $layout_node ) && is_array( $layout_parent ) ? self::grid_column_span_width( $layout_parent['layout']['columns'] ?? null, $column ) : null;
			$placement_proven = self::grid_placement_proven( $layout_node );
			$parent_proven    = is_array( $layout_parent ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_parent, 'grid-template-columns' );
			if ( ! is_int( $control_index ) || 'core/button' !== ( $s->field_blocks[ $control_index ]['name'] ?? '' ) || null === $width || ! $placement_proven || ! $parent_proven ) {
				continue;
			}
			$target_variants = array();
			$variants_safe   = true;
			foreach ( $s->variants_by_node[ $id ] ?? array() as $variant ) {
				$patch         = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
				$variant_width = array( 'column' ) === array_keys( $patch ) ? self::grid_column_span_width( $layout_parent['layout']['columns'] ?? null, $patch['column'] ) : null;
				$proven        = Static_Site_Importer_Form_Layout_Projection::has_source_fact( $variant['provenance'] ?? array(), $variant['condition'] ?? null, array( 'grid-column' ), false );
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
			$s->overlay_node_targets[]      = array(
				'id'     => 'control-' . $control_index,
				'layout' => array( 'width' => $width ),
			);
			$s->grid_span_submit_controls[] = $control_index;
			$submit_parent                  = is_string( $layout_node['parent'] ?? null ) ? $layout_node['parent'] : '';
			if ( '' !== $submit_parent ) {
				$s->grid_span_submit_parents[ $submit_parent ] = true;
			}
			$s->responsive_variant_targets = array_merge( $s->responsive_variant_targets, $target_variants );
			// Placement is carried by the button's width. Any other fact the source
			// wrapper owns (its minimum height or padding) stays on the wrapper's own
			// provider element through source box transposition below.
			if ( array() === array_diff_key( $layout_node['layout'] ?? array(), array_flip( array( 'column', 'row', 'area' ) ) ) ) {
				$s->represented_layout_nodes[] = $id;
			}
			$s->record( 'layout', 'provider_grid_span_submit', $id );
		}
		foreach ( array_keys( $s->grid_span_submit_parents ) as $submit_parent ) {
			$saw_submit_control = false;
			$submit_parent_done = true;
			foreach ( $s->children[ $submit_parent ] ?? array() as $submit_child ) {
				$submit_branch = $s->mapped_branch( $submit_child );
				if ( empty( $submit_branch ) ) {
					continue;
				}
				$saw_submit_control = true;
				$submit_child_id    = $submit_child['id'];
				if ( '' === $submit_child_id || ! in_array( $submit_child_id, $s->represented_layout_nodes, true ) ) {
					$submit_parent_done = false;
					break;
				}
			}
			if ( $saw_submit_control && $submit_parent_done ) {
				$s->represented_layout_nodes[] = $submit_parent;
			}
		}
	}

	/** Size a control from the fixed grid track that contains it. */
	private static function project_grid_track_control_widths( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->layout_nodes_by_id as $id => $layout_node ) {
			$sizing    = $layout_node['sizing'] ?? null;
			$container = is_array( $sizing ) ? $s->layout_nodes_by_id[ $sizing['container'] ?? '' ] ?? null : null;
			$tracks    = is_array( $container ) ? preg_split( '/\s+/', trim( (string) ( $container['layout']['columns'] ?? '' ) ) ) : false;
			$column    = is_array( $sizing ) ? trim( (string) ( $sizing['grid_column'] ?? '' ) ) : '';
			$track     = is_array( $tracks ) && ctype_digit( $column ) ? $tracks[ (int) $column - 1 ] ?? null : null;

			$variant_sensitive = false;
			foreach ( $s->variants_by_node[ $id ] ?? array() as $variant ) {
				if ( isset( $variant['layout_patch']['column'] ) ) {
					$variant_sensitive = true;
					break;
				}
			}
			foreach ( $s->variants_by_node[ $sizing['container'] ?? '' ] ?? array() as $variant ) {
				if ( isset( $variant['layout_patch']['columns'] ) ) {
					$variant_sensitive = true;
					break;
				}
			}
			if ( ! is_array( $sizing ) || 'grid_track' !== ( $sizing['kind'] ?? null ) || 'inline' !== ( $sizing['axis'] ?? null ) || ! preg_match( '/^control-([0-9]+)$/D', $id, $control ) || ! isset( $s->field_blocks[ (int) $control[1] ] ) || ! is_string( $track ) || ! preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em)$/D', $track ) || $variant_sensitive ) {
				continue;
			}
			$s->overlay_node_targets[] = array(
				'id'     => $id,
				'layout' => array(
					'width' => $track,
					'flex'  => '0 1 auto',
				),
			);
			$s->record( 'layout', 'provider_grid_track_control_width', $id );
		}
	}

	/** Hide a native control only where its source replacement is proven. */
	private static function project_native_control_visibility( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->nodes as $node ) {
			$id = is_array( $node ) && 'control' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			if ( '' === $id || 'none' !== ( $s->layout_by_node[ $id ]['display'] ?? null ) ) {
				continue;
			}
			$control_index = $node['control'] ?? null;
			if ( ! is_int( $control_index ) || ! isset( $s->field_blocks[ $control_index ] ) || 'core/button' === ( $s->field_blocks[ $control_index ]['name'] ?? '' ) ) {
				continue;
			}
			$parent         = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$layout_node    = $s->layout_nodes_by_id[ $id ] ?? null;
			$source_classes = is_array( $layout_node['source']['classes'] ?? null ) ? $layout_node['source']['classes'] : array();
			$class_proven   = ! empty( $source_classes ) && is_array( $layout_node ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_node, 'display' );
			foreach ( $layout_node['provenance'] ?? array() as $fact ) {
				if ( ! is_array( $fact ) || ! in_array( 'display', $fact['properties'] ?? array(), true ) ) {
					continue;
				}
				$selector = is_string( $fact['selector'] ?? null ) ? $fact['selector'] : '';
				if ( null !== ( $fact['condition'] ?? null ) || ! Static_Site_Importer_Form_Layout_Projection::class_only_selector_names( $selector, $source_classes ) ) {
					$class_proven = false;
					break;
				}
			}
			// A custom-drawn checkbox's box carries the source rules that hide the
			// provider input and draw its replacement on the sibling label.
			$choice_box         = Static_Site_Importer_Form_Presentation_Descriptor::source_choice_label( $s->form, $control_index );
			$box_proven         = '' !== $parent && ( $choice_box['id'] ?? null ) === $parent && 'label' !== ( $choice_box['tag'] ?? '' ) && isset( $s->provider_layout_targets[ $parent ] );
			$replacement_proven = '' !== $parent
				&& ( in_array( $parent, $s->represented_layout_nodes, true ) || $box_proven )
				&& 1 === count( $s->children[ $parent ] ?? array() )
				&& 'none' !== ( $s->layout_by_node[ $parent ]['display'] ?? null )
				&& ! isset( $s->variants_by_node[ $parent ] )
				&& ! isset( $s->variants_by_node[ $id ] )
				&& ( $class_proven || $box_proven );
			if ( ! $replacement_proven ) {
				$s->losses[] = array(
					'dimension'   => 'topology',
					'reason_code' => 'provider_native_control_visibility_unrepresentable',
					'node_hash'   => hash( 'sha256', $id ),
				);
				continue;
			}
			$s->native_visibility_targets[] = $id;
			$s->operations[]                = array(
				'dimension' => 'layout',
				'strategy'  => 'provider_native_control_visibility',
				'node_hash' => hash( 'sha256', $id ),
			);
		}
	}

	/** Project proven percentage sibling widths onto provider field widths. */
	private static function project_percentage_width_fields( Static_Site_Importer_Form_Topology_State $s ): void {
		$safe_percentage_variants = static function ( array $variants ): bool {
			foreach ( $variants as $variant ) {
				$patch = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
				$width = $patch['width'] ?? null;
				if ( ! is_array( $variant ) || ! is_array( $variant['condition'] ?? null ) || 'media' !== ( $variant['condition']['kind'] ?? null ) || ! is_string( $variant['condition']['query'] ?? null ) || 1 !== preg_match( '/^\((?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)\)$/D', $variant['condition']['query'] ) || ! is_string( $width ) || 1 !== preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) || ( array( 'width' ) !== array_keys( $patch ) && array( 'display', 'width' ) !== array_keys( $patch ) ) || ( isset( $patch['display'] ) && 'block' !== $patch['display'] ) ) {
					return false;
				}

				if ( ! Static_Site_Importer_Form_Layout_Projection::has_source_fact( $variant['provenance'] ?? array(), $variant['condition'], array_keys( $patch ) ) ) {
					return false;
				}
			}

			return true;
		};

		if ( in_array( $s->form['layout_graph']['schema'] ?? null, array( 'generic/computed-layout-graph/v2', 'generic/computed-layout-graph/v3' ), true ) ) {
			foreach ( $s->children as $parent => $siblings ) {
				if ( '$root' === $parent || count( $siblings ) < 2 || isset( $s->variants_by_node[ $parent ] ) ) {
					continue;
				}
				$indexes             = array();
				$widths              = array();
				$branches            = array();
				$row_variant_targets = array();
				foreach ( $siblings as $sibling ) {
					$id              = $sibling['id'];
					$layout_node     = $s->layout_nodes_by_id[ $id ] ?? null;
					$layout          = is_array( $layout_node ) && is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
					$width           = is_string( $layout['width'] ?? null ) ? trim( $layout['width'] ) : '';
					$width_proven    = is_array( $layout_node ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_node, 'width' );
					$branch_controls = ( $s->collect_controls )( $sibling );
					$value           = 1 === preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) ? (float) substr( $width, 0, -1 ) : 0.0;
					$variants        = $s->variants_by_node[ $id ] ?? array();
					if ( ! $safe_percentage_variants( $variants ) || ! $width_proven || 0 >= $value || 100 < $value || 1 !== count( $branch_controls ) || ! isset( $s->field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $s->field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
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
				$s->responsive_variant_targets = array_merge( $s->responsive_variant_targets, $row_variant_targets );
				foreach ( $indexes as $offset => $control_index ) {
					$s->field_blocks[ $control_index ]['attrs']['width'] = round( $widths[ $offset ], 3 );
				}
				$s->represented_layout_nodes            = array_merge( $s->represented_layout_nodes, $branches );
				$s->percentage_width_parents[ $parent ] = true;
				$s->record(
					'layout',
					'provider_percentage_width_fields',
					$parent,
					array(
						'field_count' => count( $indexes ),
						'widths_hash' => hash( 'sha256', (string) wp_json_encode( $widths ) ),
					)
				);

				$table_chain  = array( $parent );
				$table_tags   = array( 'tr', 'tbody', 'table' );
				$cursor       = $parent;
				$table_proven = true;
				foreach ( $table_tags as $expected_tag ) {
					$current            = $s->topology_nodes_by_id[ $cursor ] ?? null;
					$layout             = $s->layout_by_node[ $cursor ] ?? array();
					$allows_table_width = 'table' === $expected_tag && array( 'width' ) === array_keys( $layout ) && '100%' === ( $layout['width'] ?? null ) && isset( $s->layout_nodes_by_id[ $cursor ] ) && Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $s->layout_nodes_by_id[ $cursor ], 'width' );
					if ( ! is_array( $current ) || ( $current['tag'] ?? 'div' ) !== $expected_tag || ( $s->layout_nodes_by_id[ $cursor ]['source']['tag'] ?? '' ) !== $expected_tag || ( 'tr' !== $expected_tag && ! empty( $layout ) && ! $allows_table_width ) ) {
						$table_proven = false;
						break;
					}
					$cursor = $current['parent'] ?? null;
					if ( is_string( $cursor ) ) {
						$table_chain[] = $cursor;
					}
				}
				foreach ( $branches as $branch ) {
					if ( 'td' !== ( $s->topology_nodes_by_id[ $branch ]['tag'] ?? 'div' ) || 'td' !== ( $s->layout_nodes_by_id[ $branch ]['source']['tag'] ?? '' ) || array( 'width' ) !== array_keys( $s->layout_by_node[ $branch ] ?? array() ) ) {
						$table_proven = false;
					}
				}
				if ( $table_proven ) {
					$s->represented_topology_nodes = array_merge( $s->represented_topology_nodes, $branches, array_slice( $table_chain, 0, 3 ) );
					if ( array( 'width' ) === array_keys( $s->layout_by_node[ $table_chain[2] ] ?? array() ) ) {
						$s->represented_layout_nodes[] = $table_chain[2];
					}
				}
			}
		}
	}

	/** Represent a hidden wrapper whose controls carry no authored content. */
	private static function project_omitted_runtime_controls( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->nodes as $node ) {
			$id               = is_array( $node ) ? ( $node['id'] ?? null ) : null;
			$layout_node      = is_string( $id ) ? ( $s->layout_nodes_by_id[ $id ] ?? null ) : null;
			$omitted_controls = is_array( $node ) ? ( $s->collect_controls )( $node ) : array();
			if ( 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $id ) || array( 'display' => 'none' ) !== ( $s->layout_by_node[ $id ] ?? array() ) || isset( $s->variants_by_node[ $id ] ) || ! is_array( $layout_node ) || ! Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_node, 'display' ) || empty( $omitted_controls ) || array_filter( $omitted_controls, static fn( int $index ): bool => Static_Site_Importer_Form_Layout_Projection::control_carries_authored_content( Static_Site_Importer_Form_Layout_Projection::control_type( $s->controls[ $index ] ?? null ) ) ) ) {
				continue;
			}
			$s->represented_layout_nodes[]   = $id;
			$s->represented_topology_nodes[] = $id;
			$s->record( 'topology', 'provider_omitted_runtime_controls', $id, array( 'control_count' => count( $omitted_controls ) ) );
		}
	}

	/** A grid item's column placement: its grid-column, else its grid-area column span. */
	private static function grid_placement( mixed $layout_node ): mixed {
		return is_array( $layout_node ) ? ( $layout_node['layout']['column'] ?? self::grid_area_column_span( $layout_node['layout']['area'] ?? null ) ) : null;
	}

	/** Whether an unconditional source rule proves a grid item's placement. */
	private static function grid_placement_proven( mixed $layout_node ): bool {
		return is_array( $layout_node ) && ( Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_node, 'grid-column' ) || Static_Site_Importer_Form_Layout_Projection::has_unconditional_proven_property( $layout_node, 'grid-area' ) );
	}

	/** The column span a `row / column / span R / span C` grid-area places, or null. */
	private static function grid_area_column_span( mixed $area ): ?string {
		$area = is_string( $area ) ? trim( $area ) : '';
		if ( ! preg_match( '/^(?:[0-9]+|auto)\s*\/\s*(?:[0-9]+|auto)\s*\/\s*span\s+[0-9]+\s*\/\s*span\s+([1-9][0-9]*)$/D', $area, $span ) ) {
			return null;
		}
		return 'span ' . $span[1];
	}

	/**
	 * Percentage width of a proven `grid-column` span on equal `1fr` tracks.
	 * `A / span B` is the same share as `span B`; the start only places it.
	 */
	private static function grid_column_span_width( mixed $columns, mixed $column ): ?string {
		$placement = Static_Site_Importer_Form_Layout_Projection::grid_column_placement( $columns, $column );
		if ( null === $placement ) {
			return null;
		}
		return rtrim( rtrim( number_format( 100 * $placement['span'] / $placement['columns'], 3, '.', '' ), '0' ), '.' ) . '%';
	}
}
