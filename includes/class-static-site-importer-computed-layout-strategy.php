<?php
/**
 * Conservative computed-layout graph strategies for form block trees.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Static_Site_Importer_Computed_Layout_Strategy {
	public const RECEIPT_SCHEMA = 'static-site-importer/computed-layout-receipt/v1';

	/** @return array{blocks:array<int,array<string,mixed>>,receipt:array<string,mixed>} */
	public static function apply( array $form, array $blocks ): array {
		$graph = $form['computed_layout_graph'] ?? null;
		$receipt = array( 'schema' => self::RECEIPT_SCHEMA, 'status' => 'skipped', 'graph_hash' => '', 'operation_count' => 0, 'loss_count' => 0, 'operations' => array(), 'losses' => array() );
		if ( ! is_array( $graph ) ) return array( 'blocks' => $blocks, 'receipt' => $receipt );
		$receipt['graph_hash'] = hash( 'sha256', wp_json_encode( $graph ) );
		foreach ( $graph['nodes'] as $node ) {
			$layout = $node['layout'];
			if ( ! empty( $node['variants'] ) ) {
				$receipt['losses'][] = array( 'reason_code' => 'responsive_layout_ownership', 'node_hash' => hash( 'sha256', $node['id'] ) );
				continue;
			}
			if ( ! empty( $layout['placement'] ) || ! empty( $layout['reordered'] ) || in_array( $layout['display'], array( 'grid', 'columns' ), true ) ) {
				$receipt['losses'][] = array( 'reason_code' => ! empty( $layout['placement'] ) ? 'unsupported_item_placement' : 'equivalence_unproven_layout', 'node_hash' => hash( 'sha256', $node['id'] ) );
				continue;
			}
			if ( 'flex' !== $layout['display'] || ! in_array( $layout['axis'], array( 'row', 'column' ), true ) ) continue;
			$target = $node['target'];
			$matched = false;
			$blocks = self::apply_flex( $blocks, $target, $layout['axis'], $matched );
			if ( ! $matched ) {
				$receipt['losses'][] = array( 'reason_code' => 'target_mismatch', 'node_hash' => hash( 'sha256', $node['id'] ) );
				continue;
			}
			$receipt['operations'][] = array( 'strategy' => 'core_group_flex', 'target_hash' => hash( 'sha256', $target ), 'axis' => $layout['axis'] );
		}
		$receipt['status'] = empty( $receipt['operations'] ) ? ( empty( $receipt['losses'] ) ? 'skipped' : 'deferred' ) : 'applied';
		$receipt['operation_count'] = count( $receipt['operations'] );
		$receipt['loss_count'] = count( $receipt['losses'] );
		$receipt['operations'] = array_slice( $receipt['operations'], 0, 32 );
		$receipt['losses'] = array_slice( $receipt['losses'], 0, 32 );
		return array( 'blocks' => $blocks, 'receipt' => $receipt );
	}

	private static function apply_flex( array $blocks, string $target, string $axis, bool &$matched ): array {
		foreach ( $blocks as &$block ) {
			if ( $target === ( $block['topologyId'] ?? '' ) ) {
				$block['attrs']['layout'] = array( 'type' => 'flex', 'orientation' => 'row' === $axis ? 'horizontal' : 'vertical', 'flexWrap' => 'nowrap' );
				$matched = true;
			}
			if ( ! empty( $block['innerBlocks'] ) ) $block['innerBlocks'] = self::apply_flex( $block['innerBlocks'], $target, $axis, $matched );
		}
		unset( $block );
		return $blocks;
	}
}
