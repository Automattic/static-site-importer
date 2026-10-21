<?php
/**
 * Exact native form topology projection for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves a complete source container subtree as native provider groups.
 */
final class Static_Site_Importer_Form_Native_Topology {
	/**
	 * Preserve a complete source container subtree before provider field-shell projection.
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
	 * @return array{blocks:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>,represented_layout_nodes:array<int,string>,represented_topology_nodes:array<int,string>,suppressed_layout_properties:array<string,array<int,string>>,suppressed_variant_properties?:array<string,array<int,string>>,submit_block_rows?:array<int,int>,whole_field_controls?:array<int,int>,overlay_node_targets:array<int,array<string,mixed>>,responsive_variant_targets:array<int,array<string,mixed>>,native_visibility_targets:array<int,string>,form_classes:array<int,string>,provider_layout_targets:array<string,string>,phone_popup_targets:array<int,int>,grid_span_submit_controls?:array<int,int>}|null
	 * @internal
	 */
	public static function exact_native_topology( array $nodes, array $children, array $field_blocks, array $suppressed_controls, array $layout_nodes, array $layouts, array $variants, string $scope ): ?array {
		$contains_label = static function ( array $block ) use ( &$contains_label ): bool {
			if ( ( 'jetpack/label' === ( $block['name'] ?? '' ) && false !== ( $block['attrs']['metadata']['blockVisibility'] ?? null ) ) || 'jetpack/option' === ( $block['name'] ?? '' ) || 'label' === ( $block['attrs']['tagName'] ?? '' ) ) {
				return true;
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				if ( $contains_label( $child ) ) {
					return true;
				}
			}
			return false;
		};
		$label_can_wrap = static function ( string $id ) use ( &$label_can_wrap, $children, $field_blocks, $contains_label ): bool {
			foreach ( $children[ $id ] ?? array() as $child ) {
				if ( 'wrapper' === ( $child['kind'] ?? '' ) ) {
					if ( 'label' === ( $child['tag'] ?? '' ) || ! $label_can_wrap( $child['id'] ) ) {
						return false;
					}
				} elseif ( isset( $field_blocks[ $child['control'] ?? -1 ] ) && $contains_label( $field_blocks[ $child['control'] ] ) ) {
					return false;
				}
			}
			return true;
		};
		$wrappers       = array();
		foreach ( $nodes as $node ) {
			if ( ! is_string( $node['id'] ?? null ) ) {
				return null;
			}
			// Hidden enhanced controls need the existing replacement-evidence path,
			// including its native-control visibility destination and loss gate.
			if ( 'control' === ( $node['kind'] ?? null ) ) {
				$visibility = array_merge( array( $layouts[ $node['id'] ] ?? array() ), array_column( $variants[ $node['id'] ] ?? array(), 'layout_patch' ) );
				foreach ( $visibility as $facts ) {
					if ( 'none' === ( $facts['display'] ?? null ) ) {
						return null;
					}
				}
			}
			if ( 'wrapper' === ( $node['kind'] ?? null ) ) {
				$tag = $node['tag'] ?? null;
				// A source label container can remain a literal label only when its
				// provider descendants have no label of their own. This retains the
				// source association and grouping without creating nested labels.
				if ( ! in_array( $tag, array( 'div', 'label' ), true ) || ( 'label' === $tag && ! $label_can_wrap( $node['id'] ) ) ) {
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
		// Preserve boxes as part of a declared container topology. A tree made
		// solely of ordinary block boxes still uses the existing field-shell and
		// submit-row projection, which supplies the provider's default placement.
		if ( ! array_filter( array_keys( $wrappers ), static fn( string $id ): bool => in_array( $layouts[ $id ]['display'] ?? null, array( 'flex', 'grid' ), true ) ) ) {
			return null;
		}

		foreach ( $wrappers as $id => $wrapper ) {
			$layout_node     = $layout_nodes[ $id ] ?? null;
			$parent          = is_string( $wrapper['parent'] ?? null ) ? $wrapper['parent'] : '$root';
			$expected_parent = '$root' === $parent ? 'form' : $parent;
			$layout          = $layouts[ $id ] ?? null;
			if ( ! is_array( $layout_node ) || ( $wrapper['tag'] ?? null ) !== ( $layout_node['source']['tag'] ?? null ) || ( $layout_node['parent'] ?? null ) !== $expected_parent || ! is_array( $layout ) || ! Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( $layout ) || ! Static_Site_Importer_Form_Layout_Projection::layout_facts_proven( $layout_node['provenance'] ?? array(), null, $layout ) ) {
				return null;
			}
			$flex = 'flex' === ( $layout['display'] ?? null ) && in_array( $layout['direction'] ?? 'row', array( 'row', 'column' ), true );
			$grid = 'grid' === ( $layout['display'] ?? null )
				&& ! array_diff( array_keys( $layout ), array( 'display', 'columns', 'gap' ) )
				&& Static_Site_Importer_Form_Layout_Projection::is_equal_fraction_columns( preg_replace( '/\s+/', '', (string) ( $layout['columns'] ?? '' ) ), 1 )
				&& 2 === count( $children[ $id ] ?? array() )
				&& 2 === count( array_filter( $children[ $id ], static fn( array $child ): bool => 'control' === ( $child['kind'] ?? null ) && isset( $field_blocks[ $child['control'] ?? -1 ] ) && 'core/button' !== ( $field_blocks[ $child['control'] ]['name'] ?? null ) ) )
				&& 1 === count( $variants[ $id ] ?? array() );
			if ( $grid ) {
				$variant   = $variants[ $id ][0];
				$condition = $variant['condition'] ?? null;
				$patch     = $variant['layout_patch'] ?? null;
				$grid      = Static_Site_Importer_Form_Layout_Projection::min_width_query( $condition )
					&& is_array( $patch ) && array( 'columns' ) === array_keys( $patch )
					&& Static_Site_Importer_Form_Layout_Projection::is_equal_fraction_columns( preg_replace( '/\s+/', '', (string) ( $patch['columns'] ?? '' ) ), 2 );
			}
			// A physical source box does not need to establish flex/grid. A
			// nested submit's height and width still have their own owner, and
			// a class-carrying ancestry box may have no unconditional facts.
			$box      = ! isset( $layout['display'] ) && ! array_diff( array_keys( $layout ), array_merge( array_keys( Static_Site_Importer_Provider_Layout_Overlay::box_property_map() ), array( 'width', 'height', 'flex', 'flex_basis', 'flex_grow', 'flex_shrink', 'align_self', 'justify_self', 'order' ) ) );
			$contents = array( 'display' => 'contents' ) === $layout;
			if ( ! $flex && ! $grid && ! $box && ! $contents ) {
				return null;
			}
			foreach ( $variants[ $id ] ?? array() as $variant ) {
				$patch = Static_Site_Importer_Form_Layout_Projection::layout_patch( $variant );
				if ( empty( $patch ) || ! is_array( $variant['condition'] ?? null ) || ! Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( $patch ) || ! Static_Site_Importer_Form_Layout_Projection::layout_facts_proven( $variant['provenance'] ?? array(), $variant['condition'], $patch ) ) {
					return null;
				}
			}
		}
		foreach ( $layout_nodes as $id => $layout_node ) {
			if ( preg_match( Static_Site_Importer_Form_Layout_Projection::WRAPPER_ID_PATTERN, $id ) && ! isset( $wrappers[ $id ] ) ) {
				return null;
			}
		}

		$hooks           = array();
		$native_variants = array();
		foreach ( $wrappers as $id => $wrapper ) {
			$hooks[ $id ]    = Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, $id );
			$native_variants = array_merge( $native_variants, $variants[ $id ] ?? array() );
		}
		$build = static function ( string $parent_node ) use ( &$build, $children, $field_blocks, $wrappers, $layouts, $hooks ): array {
			$blocks = array();
			foreach ( $children[ $parent_node ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$index = $node['control'];
					if ( isset( $field_blocks[ $index ] ) ) {
						$field = $field_blocks[ $index ];
						if ( in_array( $field['name'], array( 'jetpack/field-text', 'jetpack/field-email', 'jetpack/field-telephone', 'jetpack/field-number', 'jetpack/field-url', 'jetpack/field-date', 'jetpack/field-textarea' ), true ) ) {
							$field['attrs']['className'] = Static_Site_Importer_Form_Layout_Projection::append_class( $field['attrs']['className'] ?? '', 'ssi-native-control-shell' );
						}
						$blocks[] = $field;
					}
					continue;
				}
				$id      = $node['id'];
				$classes = Static_Site_Importer_Form_Layout_Projection::class_tokens( $wrappers[ $id ] ?? null );
				$attrs   = array( 'className' => trim( implode( ' ', array_merge( $classes, array( $hooks[ $id ] ) ) ) ) );
				if ( 'label' === $wrappers[ $id ]['tag'] ) {
					$attrs['tagName'] = 'label';
				}
				if ( 'flex' === ( $layouts[ $id ]['display'] ?? null ) ) {
					$attrs['layout'] = array(
						'type'        => 'flex',
						'orientation' => 'column' === ( $layouts[ $id ]['direction'] ?? null ) ? 'vertical' : 'horizontal',
					);
				}
				$blocks[] = array(
					'name'        => 'core/group',
					'attrs'       => $attrs,
					'innerBlocks' => $build( $id ),
				);
			}
			return $blocks;
		};
		return array_replace(
			Static_Site_Importer_Form_Layout_Projection::EMPTY_TOPOLOGY_RESULT,
			array(
				'blocks'                     => $build( '$root' ),
				'operations'                 => array_map( static fn( string $id ): array => array(
					'dimension'   => 'topology',
					'strategy'    => 'label' === $wrappers[ $id ]['tag'] ? 'native_label_subtree_projection' : 'native_div_subtree_projection',
					'target_hash' => hash( 'sha256', $id ),
				), array_keys( $wrappers ) ),
				'represented_layout_nodes'   => array_keys( $wrappers ),
				'represented_topology_nodes' => array_keys( $wrappers ),
				'overlay_node_targets'       => array_map( static fn( string $id ): array => array(
					'id'           => $id,
					'layout'       => $layouts[ $id ],
					'presentation' => $layout_nodes[ $id ]['presentation'] ?? array(),
				), array_keys( $wrappers ) ),
				'responsive_variant_targets' => $native_variants,
				'form_classes'               => array( 'ssi-native-form-topology' ),
				'provider_layout_targets'    => $hooks,
			)
		);
	}
}
