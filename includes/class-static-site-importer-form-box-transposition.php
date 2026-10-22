<?php
/**
 * Form box transposition for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transposes source form boxes onto the provider's form and field elements:
 * separating, splitting and finalizing the boxes a provider element carries.
 */
final class Static_Site_Importer_Form_Box_Transposition {
	/** Facts that position a box against its containing block. */
	private const POSITION_FACTS = array( 'position', 'top', 'right', 'bottom', 'left' );

	/** Facts that place a box as an item of its parent's grid or flex layout. */
	private const ITEM_FACTS = array( 'column', 'row', 'area', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self' );

	/**
	 * Separate a v3 graph's copy-only `context-N` boxes from the control graph and
	 * drop box facts that only restate a CSS initial value.
	 *
	 * Context boxes are materialized around the in-form context blocks they own
	 * (see Static_Site_Importer_Form_Field_Markup::context_blocks()), so the control
	 * topology, target map and computed-layout strategy never see them. A
	 * `padding: 0` or `min-height: auto` reset matches a bare element's own box,
	 * so it needs no provider target and does not make a wrapper unrepresentable.
	 */
	public static function separate_source_boxes( array $form ): array {
		$graph = $form['layout_graph'] ?? null;
		if ( ! is_array( $graph ) || 'generic/computed-layout-graph/v3' !== ( $graph['schema'] ?? null ) || ! is_array( $graph['nodes'] ?? null ) ) {
			return $form;
		}
		$box           = Static_Site_Importer_Provider_Layout_Overlay::box_property_map();
		$strip_initial = static function ( array $layout, array $provenance, array $base = array() ) use ( $box ): array {
			$removed = array();
			foreach ( $layout as $fact => $value ) {
				if ( isset( $box[ $fact ] ) && ! isset( $base[ $fact ] ) && is_string( $value ) && Static_Site_Importer_Form_Layout_Projection::is_initial_box_value( $fact, $value ) ) {
					unset( $layout[ $fact ] );
					$removed[] = $box[ $fact ];
				}
			}
			$kept = array();
			foreach ( $provenance as $fact ) {
				if ( is_array( $fact ) && is_array( $fact['properties'] ?? null ) ) {
					$fact['properties'] = array_values( array_diff( $fact['properties'], $removed ) );
					if ( array() === $fact['properties'] ) {
						continue;
					}
				}
				$kept[] = $fact;
			}
			return array( $layout, $kept );
		};

		$context = array(
			'nodes'    => array(),
			'variants' => array(),
		);

		$nodes = array();
		foreach ( $graph['nodes'] as $node ) {
			if ( is_array( $node ) && str_starts_with( (string) ( $node['id'] ?? '' ), 'context-' ) ) {
				$context['nodes'][] = $node;
				continue;
			}
			if ( is_array( $node ) && is_array( $node['layout'] ?? null ) ) {
				// A native control's own box is owned by the presentation graph, which
				// targets the control element itself; the layout target of a control is
				// the provider shell around it and must not receive the control's box.
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$node['layout'] = array_diff_key( $node['layout'], $box );
					$node['layout'] = array_diff_key( $node['layout'], array_flip( self::POSITION_FACTS ) );
				}
				[ $node['layout'], $node['provenance'] ] = $strip_initial( $node['layout'], is_array( $node['provenance'] ?? null ) ? $node['provenance'] : array() );
			}
			$nodes[] = $node;
		}
		// A conditional zero can reset a nonzero base box, not just restate
		// the initial value. Keep that fact and its conditional provenance.
		$base_layouts = array_column( $nodes, 'layout', 'id' );
		$variants     = array();
		foreach ( is_array( $graph['variants'] ?? null ) ? $graph['variants'] : array() as $variant ) {
			if ( is_array( $variant ) && str_starts_with( (string) ( $variant['node'] ?? '' ), 'context-' ) ) {
				$context['variants'][] = $variant;
				continue;
			}
			if ( is_array( $variant ) && is_array( $variant['layout_patch'] ?? null ) ) {
				if ( 1 === preg_match( '/^control-[0-9]+$/D', (string) ( $variant['node'] ?? '' ) ) ) {
					$variant['layout_patch'] = array_diff_key( $variant['layout_patch'], $box );
					$variant['layout_patch'] = array_diff_key( $variant['layout_patch'], array_flip( self::POSITION_FACTS ) );
				}
				[ $variant['layout_patch'], $variant['provenance'] ] = $strip_initial( $variant['layout_patch'], is_array( $variant['provenance'] ?? null ) ? $variant['provenance'] : array(), $base_layouts[ $variant['node'] ?? '' ] ?? array() );
				if ( array() === $variant['layout_patch'] ) {
					continue;
				}
				$variant['precedence'] = array_intersect_key( is_array( $variant['precedence'] ?? null ) ? $variant['precedence'] : array(), array_flip( array_map( static fn( string $fact ): string => Static_Site_Importer_Provider_Layout_Overlay::layout_property_map()[ $fact ] ?? $fact, array_keys( $variant['layout_patch'] ) ) ) );
			}
			$variants[] = $variant;
		}
		$graph['nodes']               = $nodes;
		$graph['variants']            = $variants;
		$form['layout_graph']         = $graph;
		$form['source_context_graph'] = $context;
		return $form;
	}

	/**
	 * Transpose proven source box layout onto generated element hooks.
	 *
	 * @internal
	 */
	public static function transpose_form_boxes( Static_Site_Importer_Form_Topology_State $s ): void {
		// Every source box that kept its own element can carry its own layout, so the
		// facts are transposed onto that element's generated hook instead of being
		// declared unrepresentable. A box whose facts are not fully proven by source
		// provenance keeps its loss.
		// Native wrapper projection is all-or-nothing and handled by
		// Static_Site_Importer_Form_Native_Topology. The legacy partial-tree projector used
		// a second serializer contract and could silently flatten parent edges.
		// Source boxes that hold every mapped control become the provider's own form
		// element. Their container layout is what positions the fields, so it is merged
		// onto that element. A nested box declaring a full-width value repeats the box it
		// fills rather than contradicting it; any other disagreement fails closed.
		// A relative box with no inset paints where a static box does, and an outer
		// relative box is already the containing block for everything inside an inner
		// static one, so the merged element can stay relative (#2005). A static box
		// that declares a non-zero inset is not neutral: static ignores the inset but
		// a merged relative element would apply it.
		$resolve_fact = static function ( mixed $current, mixed $value, string $property, array $current_facts = array(), array $value_facts = array() ): mixed {
			if ( null === $current || $current === $value ) {
				return $value;
			}
			if ( 'width' === $property && '100%' === $value ) {
				return $current;
			}
			if ( 'width' === $property && '100%' === $current ) {
				return $value;
			}
			if ( 'position' === $property && in_array( array( $current, $value ), array( array( 'static', 'relative' ), array( 'relative', 'static' ) ), true ) ) {
				$static_facts = 'static' === $current ? $current_facts : $value_facts;
				return self::declares_nonzero_inset( $static_facts ) ? null : 'relative';
			}
			return null;
		};
		$item_facts   = self::ITEM_FACTS;
		$form_boxes   = array();
		$form_base    = $s->layout_by_node['form'] ?? array();
		$form_patches = array();
		foreach ( $s->variants_by_node['form'] ?? array() as $variant ) {
			$form_patches[ (string) wp_json_encode( $variant['condition'] ?? null ) ] = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
		}
		// A submit control is never a member of the source field-group box a provider
		// forms its own field container from: Jetpack always renders a submit as a
		// sibling of that container, never a descendant of it, regardless of what the
		// source DOM does. A box that groups every other mapped control still states
		// the field container's own layout, so a submit's absence alone does not make
		// that box's branch a partial (and therefore unrepresentable) one.
		$non_submit_mapped_controls = array_values(
			array_filter(
				$s->mapped_controls,
				static fn ( int $index ): bool => 'submit' !== Static_Site_Importer_Form_Layout_Projection::control_type( $s->controls[ $index ] ?? null, false )
			)
		);
		foreach ( $s->nodes as $node ) {
			$node_id = is_array( $node ) && 'wrapper' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			$branch  = '' !== $node_id ? $s->mapped_branch( $node ) : array();
			sort( $branch );
			if ( '' === $node_id || count( $s->mapped_controls ) < 2 || isset( $s->wrapper_hooks[ $node_id ] ) ) {
				continue;
			}
			if ( $branch !== $s->mapped_controls && ( count( $non_submit_mapped_controls ) < 2 || $branch !== $non_submit_mapped_controls ) ) {
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
			$base    = $s->layout_by_node[ $box_id ] ?? array();
			$patches = array();
			foreach ( $s->variants_by_node[ $box_id ] ?? array() as $variant ) {
				$patches[] = $variant;
			}
			if ( ! self::node_layout_proven( $s, $box_id ) || array_intersect_key( $base, array_flip( $item_facts ) ) ) {
				continue;
			}
			if ( ! isset( $base['display'] ) ) {
				$from_class = Static_Site_Importer_Form_Layout_Projection::display_from_class_tokens( Static_Site_Importer_Form_Layout_Projection::class_tokens( $box ) );
				if ( is_string( $from_class ) ) {
					$base['display'] = $from_class;
				}
			}
			$box_base    = $merged_base;
			$box_patches = $merged_patches;
			$accepted    = true;
			foreach ( $base as $property => $value ) {
				$resolved              = $resolve_fact( $form_base[ $property ] ?? ( $box_base[ $property ] ?? null ), $value, (string) $property, array_merge( $box_base, $form_base ), $base );
				$accepted              = $accepted && null !== $resolved;
				$box_base[ $property ] = $resolved;
			}
			foreach ( $patches as $variant ) {
				$condition = (string) wp_json_encode( $variant['condition'] ?? null );
				$patch     = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
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
					$widens_only_columns = 1 === count( $patches ) && Static_Site_Importer_Form_Layout_Projection::min_width_query( $variant['condition'] ?? null );
					if ( ! $widens_only_columns ) {
						$accepted = false;
						break;
					}
				}
				foreach ( $patch as $property => $value ) {
					$merged_patch = $box_patches[ $condition ]['patch'] ?? array();
					$current      = $form_patches[ $condition ][ $property ] ?? ( $merged_patch[ $property ] ?? null );

					$resolved                                        = $resolve_fact( $current, $value, (string) $property, array_merge( $box_base, $form_base, $merged_patch, $form_patches[ $condition ] ?? array() ), array_merge( $base, $patch ) );
					$accepted                                        = $accepted && null !== $resolved;
					$box_patches[ $condition ]['condition']          = $variant['condition'] ?? null;
					$box_patches[ $condition ]['patch'][ $property ] = $resolved;
				}
			}
			if ( ! $accepted ) {
				continue;
			}
			$s->form_classes = array_merge( $s->form_classes, Static_Site_Importer_Form_Layout_Projection::class_tokens( $box ) );
			$merged_base     = $box_base;
			$merged_patches  = $box_patches;
			$merged_boxes[]  = $box_id;
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
			foreach ( $s->controls as $control_index => $control ) {
				if ( 'submit' === Static_Site_Importer_Form_Layout_Projection::control_type( $control, false ) && '$root' === ( $s->control_parents[ $control_index ] ?? '$root' ) ) {
					$sibling_submit_indexes[] = $control_index;
				}
			}
			// Jetpack paints fields and submit as siblings of one field list. A source
			// submit that sits beside that list must not become a member of it: the
			// authored top margin (mt-9) would sit inside the list gap, or a gap
			// cancel would overwrite it. Keep the list's display/gap on a dedicated
			// inner wrapper and leave the submit outside.
			// A source may keep several grid rows inside a column-oriented form.
			// Jetpack's fields need their own row-oriented list while its submit
			// remains a sibling, regardless of whether the source nested the submit.
			$use_field_list = ! empty( $sibling_submit_indexes ) || ! empty( $s->grid_span_gap_variants );
			if ( $use_field_list && $s->grid_span_active ) {
				$s->grid_span_container = 'field-list';
			}
			if ( $use_field_list ) {
				$s->form_classes[]  = 'ssi-source-field-list';
				$field_list_layout  = $merged_base;
				$field_list_row_gap = self::layout_row_gap( $transposed_layout );
				if ( is_string( $field_list_row_gap ) ) {
					$gap_key                       = isset( $transposed_layout['row_gap'] ) ? 'row_gap' : 'gap';
					$field_list_layout[ $gap_key ] = $field_list_row_gap;
					if ( ( $form_base[ $gap_key ] ?? null ) === $field_list_row_gap ) {
						$s->suppressed_layout_properties['form'] = array_values( array_unique( array_merge( $s->suppressed_layout_properties['form'] ?? array(), array( $gap_key ) ) ) );
					}
				}
				if ( ! empty( $field_list_layout ) ) {
					$s->overlay_node_targets[] = array(
						'id'     => 'field-list',
						'layout' => $field_list_layout,
					);
				}
				foreach ( $merged_variants as $variant ) {
					$patch = $variant['layout_patch'];
					if ( ! empty( $s->grid_span_gap_variants ) && 'column' === ( $patch['direction'] ?? null ) && 'flex' === ( $patch['display'] ?? null ) ) {
						// The original column gap is between source rows. Once fields
						// are flattened into the provider list it becomes a row gap;
						// each grid row's separately proven column gap is projected below.
						$patch['direction'] = 'row';
						$patch['wrap']      = 'wrap';
						if ( isset( $patch['gap'] ) ) {
							$patch['row_gap'] = $patch['gap'];
							unset( $patch['gap'] );
						}
					}
					$s->responsive_variant_targets[] = array(
						'node'         => 'field-list',
						'condition'    => $variant['condition'] ?? null,
						'layout_patch' => $patch,
					);
				}
			} else {
				if ( ! empty( $merged_base ) ) {
					$s->overlay_node_targets[] = array(
						'id'     => 'form',
						'layout' => $merged_base,
					);
				}
				$s->responsive_variant_targets = array_merge( $s->responsive_variant_targets, $merged_variants );
			}
			foreach ( $merged_boxes as $box_id ) {
				$s->overlay_represented_nodes[] = $box_id;
				$s->record( 'layout', 'provider_form_box_transposition', $box_id );
			}
			// The gap cancel this replaced only ever applied to a sibling submit,
			// and a sibling submit is exactly what puts the fields in their own
			// field-list wrapper. Once that wrapper carries the list's gap the
			// submit sits outside it, so there is no transposed gap left to cancel
			// against its authored margin (#1738).
		}
	}

	/**
	 * Finalize gap, wrapper-hook and unrepresented layout targets.
	 *
	 * @internal
	 */
	public static function finalize_overlay_targets( Static_Site_Importer_Form_Topology_State $s ): void {
		if ( $s->grid_span_active && is_string( $s->grid_span_gap ) ) {
			$s->overlay_node_targets[] = array(
				'id'     => $s->grid_span_container,
				'layout' => array(
					'display'   => 'flex',
					'direction' => 'row',
					'wrap'      => 'wrap',
					'gap'       => $s->grid_span_gap,
				),
			);
		}
		foreach ( $s->grid_span_gap_variants as $variant ) {
			$s->responsive_variant_targets[] = array(
				'node'         => $s->grid_span_container,
				'condition'    => $variant['condition'],
				'layout_patch' => array( 'column_gap' => $variant['gap'] ),
			);
		}
		foreach ( $s->wrapper_hooks as $node_id => $hook ) {
			$proven = self::node_layout_proven( $s, $node_id );
			// A wrapper already represented by carrying its own source class name
			// (so the enqueued source stylesheet paints its base fact directly)
			// still needs its own overlay target when it also carries a responsive
			// variant under a different, unrepresented class - the base-class
			// carry never inspects variants, so it cannot promise those too.
			$base_represented_only = in_array( $node_id, $s->represented_layout_nodes, true ) && empty( $s->variants_by_node[ $node_id ] ?? array() );
			if ( ! $proven || $base_represented_only ) {
				continue;
			}
			$s->provider_layout_targets[ $node_id ] = $hook;
			$s->overlay_represented_nodes[]         = $node_id;
			$s->record( 'layout', 'provider_source_box_transposition', $node_id );
		}
		$represented = array_fill_keys( array_merge( $s->represented_layout_nodes, $s->overlay_represented_nodes ), true );
		foreach ( $s->layout_by_node as $node_id => $layout ) {
			if ( ! preg_match( Static_Site_Importer_Form_Layout_Projection::WRAPPER_ID_PATTERN, $node_id ) || isset( $represented[ $node_id ] ) || ( empty( $layout ) && ! isset( $s->variants_by_node[ $node_id ] ) ) ) {
				continue;
			}
			// A wrapper whose only unrepresented facts are its own box (padding,
			// min-height) still arranges its controls exactly as the provider does;
			// that is a named box loss, not a topology the provider cannot express.
			// `display: block` is that same block flow, so it adds no fact.
			$parent_id     = $s->layout_nodes_by_id[ $node_id ]['parent'] ?? '';
			$parent_layout = $s->layout_by_node[ $parent_id ] ?? array();
			$facts         = self::non_block_flow_facts( $layout, $parent_layout );
			foreach ( $s->variants_by_node[ $node_id ] ?? array() as $variant ) {
				$facts = array_merge( $facts, self::non_block_flow_facts( array_replace( $layout, Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant ) ), $parent_layout ) );
			}
			if ( array() === $facts ) {
				continue;
			}
			$box_only    = array() === array_diff( $facts, array_keys( Static_Site_Importer_Provider_Layout_Overlay::box_property_map() ) );
			$s->losses[] = array(
				'dimension'   => $box_only ? 'layout' : 'topology',
				'reason_code' => $box_only ? 'provider_wrapper_box_unrepresentable' : 'provider_wrapper_layout_unrepresentable',
				'node_hash'   => hash( 'sha256', $node_id ),
			);
		}
	}

	/**
	 * Layout fact names other than plain block flow.
	 *
	 * `display: block` is a block box's default flow. `width: 100%` on a
	 * block-flow box that declares no horizontal margin, padding or border is
	 * that same box's default content width, so neither adds a fact the
	 * provider's own stacked field flow cannot express.
	 *
	 * @param array<string,mixed> $facts
	 * @param array<string,mixed> $parent_layout Parent wrapper's layout facts.
	 * @return list<string>
	 * @internal
	 */
	public static function non_block_flow_facts( array $facts, array $parent_layout = array() ): array {
		// Flex/grid items are blockified (CSS Display §2.7). Their inline
		// outer display adds no topology, but flex/grid inner layout still does.
		if ( in_array( $parent_layout['display'] ?? null, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true ) ) {
			$blockified = array(
				'inline'       => 'block',
				'inline-block' => 'block',
				'inline-flex'  => 'flex',
				'inline-grid'  => 'grid',
			);
			$display    = $facts['display'] ?? null;
			if ( is_string( $display ) && isset( $blockified[ $display ] ) ) {
				$facts['display'] = $blockified[ $display ];
			}
		}
		if ( 'block' === ( $facts['display'] ?? null ) ) {
			unset( $facts['display'] );
		}
		if ( isset( $facts['width'] ) && ! isset( $facts['display'] ) && is_string( $facts['width'] ) && '100%' === strtolower( trim( $facts['width'] ) ) && ! self::declares_horizontal_box( $facts ) ) {
			unset( $facts['width'] );
		}
		// Relative positioning without offsets does not move a block out of flow.
		if ( 'relative' === ( $facts['position'] ?? null ) && ! array_intersect( array_keys( $facts ), array( 'top', 'right', 'bottom', 'left', 'z_index' ) ) ) {
			unset( $facts['position'] );
		}
		return array_keys( $facts );
	}

	/**
	 * Whether layout facts declare a horizontal margin, padding or border that
	 * would make a declared `width: 100%` differ from block flow's default
	 * content width.
	 *
	 * @param array<string,mixed> $facts
	 */
	private static function declares_horizontal_box( array $facts ): bool {
		foreach ( array_keys( $facts ) as $fact ) {
			if ( in_array( $fact, array( 'margin_left', 'margin_right', 'margin_inline_start', 'margin_inline_end', 'padding', 'padding_left', 'padding_right', 'padding_inline_start', 'padding_inline_end' ), true ) || str_starts_with( (string) $fact, 'border' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether layout facts declare a top/right/bottom/left offset that would move a
	 * relatively positioned box.
	 *
	 * @param array<string,mixed> $facts
	 */
	private static function declares_nonzero_inset( array $facts ): bool {
		foreach ( Static_Site_Importer_Form_Layout_Projection::INSET_SIDES as $inset ) {
			if ( ! array_key_exists( $inset, $facts ) ) {
				continue;
			}
			$value = is_string( $facts[ $inset ] ) || is_int( $facts[ $inset ] ) || is_float( $facts[ $inset ] ) ? strtolower( trim( (string) $facts[ $inset ] ) ) : null;
			if ( null === $value || ( 'auto' !== $value && 1 !== preg_match( '/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:px|r?em|%|v[wh]|v(?:min|max)|ch|ex)?$/D', $value ) ) ) {
				return true;
			}
		}
		return false;
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
		$item_facts = self::ITEM_FACTS;
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
			$patch = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
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
	 * Leave the source form's own box to its container presentation.
	 *
	 * When the producer records the form's box (`generic/form-container-presentation/v1`),
	 * the overlay paints it once on the provider container, base and conditional
	 * facts alike. The layout graph can carry the same box facts on the form node;
	 * compiled onto the provider form element as well, they apply the source
	 * padding a second time inside the painted box.
	 *
	 * @param array<string,mixed> $graph     Provider layout graph.
	 * @param array<string,mixed> $container Producer container presentation.
	 * @return array<string,mixed>
	 */
	public static function without_container_box_facts( array $graph, array $container ): array {
		if ( 'generic/form-container-presentation/v1' !== ( $container['schema'] ?? null ) ) {
			return $graph;
		}
		$declared = is_array( $container['styles'] ?? null ) ? array_keys( $container['styles'] ) : array();
		foreach ( is_array( $container['variants'] ?? null ) ? $container['variants'] : array() as $variant ) {
			$declared = array_merge( $declared, is_array( $variant['styles'] ?? null ) ? array_keys( $variant['styles'] ) : array() );
		}
		// A declared shorthand (`margin`, `padding`) owns every longhand of its box,
		// which is how the layout graph records the same facts on the form node.
		$owned = array_filter(
			Static_Site_Importer_Provider_Layout_Overlay::box_property_map(),
			static function ( string $fact ) use ( $declared ): bool {
				foreach ( $declared as $property ) {
					if ( $fact === $property || str_starts_with( $fact, $property . '_' ) ) {
						return true;
					}
				}
				return false;
			},
			ARRAY_FILTER_USE_KEY
		);
		if ( array() === $owned ) {
			return $graph;
		}
		foreach ( $graph['nodes'] ?? array() as $index => $node ) {
			if ( is_array( $node ) && 'form' === ( $node['id'] ?? null ) && is_array( $node['layout'] ?? null ) ) {
				$graph['nodes'][ $index ]['layout'] = array_diff_key( $node['layout'], $owned );
			}
		}
		$variants = array();
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && 'form' === ( $variant['node'] ?? null ) && is_array( $variant['layout_patch'] ?? null ) ) {
				$variant['layout_patch'] = array_diff_key( $variant['layout_patch'], $owned );
				if ( array() === $variant['layout_patch'] ) {
					continue;
				}
				$variant['precedence'] = array_diff_key( is_array( $variant['precedence'] ?? null ) ? $variant['precedence'] : array(), array_flip( $owned ) );
			}
			$variants[] = $variant;
		}
		$graph['variants'] = $variants;
		return $graph;
	}

	/** Whether a node's base layout and every variant patch are proven by source. */
	private static function node_layout_proven( Static_Site_Importer_Form_Topology_State $s, string $node_id ): bool {
		$layout_node = $s->layout_nodes_by_id[ $node_id ] ?? null;
		if ( ! is_array( $layout_node ) || ! Static_Site_Importer_Form_Layout_Projection::layout_facts_proven( $layout_node['provenance'] ?? array(), null, $s->layout_by_node[ $node_id ] ?? array() ) ) {
			return false;
		}
		foreach ( $s->variants_by_node[ $node_id ] ?? array() as $variant ) {
			if ( ! Static_Site_Importer_Form_Layout_Projection::layout_facts_proven( $variant['provenance'] ?? array(), $variant['condition'] ?? null, Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant ) ) ) {
				return false;
			}
		}
		return true;
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
}
