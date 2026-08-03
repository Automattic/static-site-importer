<?php
/**
 * Conservative computed-layout graph strategies for form block trees.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Static_Site_Importer_Computed_Layout_Strategy {
	public const RECEIPT_SCHEMA = 'static-site-importer/computed-layout-receipt/v1';

	/** @return array{blocks:array<int,array<string,mixed>>,receipt:array<string,mixed>} */
	public static function apply( array $form, array $blocks ): array {
		$graph   = $form['layout_graph'] ?? null;
		$receipt = array(
			'schema'           => self::RECEIPT_SCHEMA,
			'status'           => 'skipped',
			'graph_hash'       => '',
			'operation_count'  => 0,
			'loss_count'       => 0,
			'operations_total' => 0,
			'losses_total'     => 0,
			'truncated'        => false,
			'operations'       => array(),
			'losses'           => array(),
		);
		foreach ( $form['topology_losses'] ?? array() as $loss ) {
			if ( is_array( $loss ) ) {
				$receipt['losses'][] = $loss;
			}
		}
		if ( ! is_array( $graph ) ) {
			$receipt['status'] = empty( $receipt['losses'] ) ? 'skipped' : 'deferred';
			return array(
				'blocks'  => $blocks,
				'receipt' => self::bound_receipt( $receipt ),
			);
		}
		$receipt['graph_hash'] = self::json_hash( $graph );
		$variants_by_node      = array();
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_string( $variant['node'] ?? null ) ) {
				$variants_by_node[ $variant['node'] ][] = $variant;
			}
		}
		foreach ( self::semantic_wrapper_losses( $form, $blocks ) as $loss ) {
			$receipt['losses'][] = $loss;
		}
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$layout        = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			$node_hash     = hash( 'sha256', (string) ( $node['id'] ?? '' ) );
			$node_variants = $variants_by_node[ $node['id'] ?? '' ] ?? array();
			if ( ! empty( $node_variants ) ) {
				$receipt['losses'][] = array(
					'dimension'     => 'layout',
					'reason_code'   => 'responsive_layout_ownership',
					'node_hash'     => $node_hash,
					'variant_count' => count( $node_variants ),
					'variant_hash'  => self::json_hash( $node_variants ),
				);
				continue;
			}
			if ( array() === $layout ) {
				continue;
			}
			if ( ! preg_match( '/^wrapper-[0-9]+$/D', (string) ( $node['id'] ?? '' ) ) ) {
				$receipt['losses'][] = array(
					'dimension'   => 'layout',
					'reason_code' => 'layout_target_unrepresentable',
					'node_hash'   => $node_hash,
				);
				continue;
			}
			$loss = self::flex_loss( $layout );
			if ( null !== $loss ) {
				$receipt['losses'][] = array(
					'dimension'   => 'layout',
					'reason_code' => $loss,
					'node_hash'   => $node_hash,
				);
				continue;
			}
			$target  = $node['id'];
			$matched = false;
			$blocks  = self::apply_flex( $blocks, $target, $layout, $matched );
			if ( ! $matched ) {
				$receipt['losses'][] = array(
					'dimension'   => 'layout',
					'reason_code' => 'layout_target_unrepresentable',
					'node_hash'   => $node_hash,
				);
				continue;
			}
			$receipt['operations'][] = array(
				'dimension'   => 'layout',
				'strategy'    => 'core_group_flex_equivalent',
				'target_hash' => hash( 'sha256', $target ),
				'direction'   => $layout['direction'],
			);
		}
		$receipt['status'] = empty( $receipt['operations'] ) ? ( empty( $receipt['losses'] ) ? 'skipped' : 'deferred' ) : 'applied';
		return array(
			'blocks'  => $blocks,
			'receipt' => self::bound_receipt( $receipt ),
		);
	}

	private static function bound_receipt( array $receipt ): array {
		$receipt['operations_total'] = count( $receipt['operations'] );
		$receipt['losses_total']     = count( $receipt['losses'] );
		$gate_overflow               = array_values( array_filter( array_slice( $receipt['losses'], 32 ), array( self::class, 'receipt_loss_requires_gate' ) ) );
		if ( ! empty( $gate_overflow ) ) {
			$receipt['gate_required_loss_overflow_count'] = count( $gate_overflow );
			$receipt['gate_required_loss_overflow_hash']  = self::json_hash( $gate_overflow );
		}
		$receipt['operation_count'] = min( 32, $receipt['operations_total'] );
		$receipt['loss_count']      = min( 32, $receipt['losses_total'] );
		$receipt['truncated']       = $receipt['operations_total'] > 32 || $receipt['losses_total'] > 32;
		$receipt['operations']      = array_slice( $receipt['operations'], 0, 32 );
		$receipt['losses']          = array_slice( $receipt['losses'], 0, 32 );
		return $receipt;
	}

	private static function json_hash( mixed $value ): string {
		$json = wp_json_encode( $value );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function receipt_loss_requires_gate( array $loss ): bool {
		return 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' )
			|| 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' )
			|| in_array( $loss['dimension'] ?? '', array( 'semantic', 'topology' ), true )
			|| in_array( $loss['reason_code'] ?? '', array( 'provider_structure_mismatch', 'direct_child_relationship_unrepresentable' ), true );
	}

	private static function flex_loss( array $layout ): ?string {
		if ( ! empty( $layout['item_placement'] ) || isset( $layout['column'], $layout['row'] ) || array_key_exists( 'area', $layout ) ) {
			return 'unsupported_item_placement';
		}
		if ( 'flex' !== ( $layout['display'] ?? null ) || ! in_array( $layout['direction'] ?? null, array( 'row', 'column' ), true ) ) {
			return 'layout_target_unrepresentable';
		}
		if ( isset( $layout['row_gap'], $layout['column_gap'] ) && $layout['row_gap'] !== $layout['column_gap'] ) {
			return 'conflicting_axis_gaps';
		}
		if ( array_key_exists( 'row_gap', $layout ) || array_key_exists( 'column_gap', $layout ) ) {
			return 'axis_gap_unrepresentable';
		}
		if ( array_key_exists( 'wrap', $layout ) && ! in_array( $layout['wrap'], array( 'wrap', 'nowrap' ), true ) ) {
			return 'unsupported_flex_wrap';
		}
		if ( array_key_exists( 'gap', $layout ) && ! self::safe_gap( $layout['gap'] ) ) {
			return 'unsupported_gap';
		}
		if ( array_key_exists( 'align_items', $layout ) && null === self::alignment_attr( $layout['direction'], 'align_items', $layout['align_items'] ) ) {
			return 'unsupported_alignment';
		}
		if ( array_key_exists( 'justify_content', $layout ) && null === self::alignment_attr( $layout['direction'], 'justify_content', $layout['justify_content'] ) ) {
			return 'unsupported_justification';
		}
		foreach ( array( 'columns', 'rows', 'align_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area', 'item_placement' ) as $fact ) {
			if ( array_key_exists( $fact, $layout ) ) {
				return ! empty( $layout['item_placement'] ) || in_array( $fact, array( 'column', 'row', 'area' ), true ) ? 'unsupported_item_placement' : 'equivalence_unproven_layout';
			}
		}
		return null;
	}

	private static function safe_gap( mixed $gap ): bool {
		return is_string( $gap ) && preg_match( '/^(?:0|(?:[0-9]+(?:\.[0-9]+)?)(?:px|em|rem|%|vh|vw|vmin|vmax)|var:preset\|spacing\|[a-z0-9-]+)$/D', $gap );
	}

	/** @return array{0:string,1:string}|null */
	private static function alignment_attr( string $direction, string $fact, mixed $value ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 'row' === $direction && 'align_items' === $fact ) {
			$map = array(
				'flex-start' => 'top',
				'center'     => 'center',
				'flex-end'   => 'bottom',
				'stretch'    => 'stretch',
			);
		} elseif ( 'row' === $direction ) {
			$map = array(
				'flex-start'    => 'left',
				'center'        => 'center',
				'flex-end'      => 'right',
				'space-between' => 'space-between',
			);
		} elseif ( 'align_items' === $fact ) {
			$map = array(
				'flex-start' => 'left',
				'center'     => 'center',
				'flex-end'   => 'right',
				'stretch'    => 'stretch',
			);
		} else {
			$map = array(
				'flex-start'    => 'top',
				'center'        => 'center',
				'flex-end'      => 'bottom',
				'space-between' => 'space-between',
			);
		}
		if ( ! isset( $map[ $value ] ) ) {
			return null;
		}
		return array( ( 'row' === $direction ) === ( 'align_items' === $fact ) ? 'verticalAlignment' : 'justifyContent', $map[ $value ] );
	}

	private static function semantic_wrapper_losses( array $form, array $blocks ): array {
		$serialized = self::serialized_topology_blocks( $blocks );
		$losses     = array();
		foreach ( $form['control_topology']['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$source_tag = $node['tag'] ?? 'div';
			if ( 'div' === $source_tag ) {
				continue;
			}
			$block = $serialized[ $node['id'] ] ?? null;
			if ( ! is_array( $block ) || ( $block['topologySourceTag'] ?? null ) !== $source_tag || ( $block['attrs']['tagName'] ?? 'div' ) !== $source_tag ) {
				$losses[] = array(
					'dimension'   => 'semantic',
					'reason_code' => 'unsupported_semantic_wrapper',
					'node_hash'   => hash( 'sha256', $node['id'] ),
				);
			}
		}
		return $losses;
	}

	private static function serialized_topology_blocks( array $blocks ): array {
		$serialized = array();
		foreach ( $blocks as $block ) {
			if ( is_string( $block['topologyId'] ?? null ) ) {
				$serialized[ $block['topologyId'] ] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$serialized += self::serialized_topology_blocks( $block['innerBlocks'] );
			}
		}
		return $serialized;
	}

	private static function apply_flex( array $blocks, string $target, array $source, bool &$matched ): array {
		foreach ( $blocks as &$block ) {
			if ( ( $block['topologyId'] ?? '' ) === $target ) {
				$layout = array(
					'type'        => 'flex',
					'orientation' => 'row' === $source['direction'] ? 'horizontal' : 'vertical',
				);
				if ( array_key_exists( 'wrap', $source ) ) {
					$layout['flexWrap'] = $source['wrap'];
				}
				foreach ( array( 'align_items', 'justify_content' ) as $fact ) {
					if ( array_key_exists( $fact, $source ) ) {
						$mapped               = self::alignment_attr( $source['direction'], $fact, $source[ $fact ] );
						$layout[ $mapped[0] ] = $mapped[1]; }
				}
				$block['attrs']['layout'] = $layout;
				if ( array_key_exists( 'gap', $source ) ) {
					$block['attrs']['style']['spacing']['blockGap'] = $source['gap'];
				}
				$matched = true;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::apply_flex( $block['innerBlocks'], $target, $source, $matched );
			}
		}
		unset( $block );
		return $blocks;
	}
}
