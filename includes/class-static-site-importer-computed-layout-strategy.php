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
		$graph = $form['layout_graph'] ?? null;
		$receipt = array( 'schema' => self::RECEIPT_SCHEMA, 'status' => 'skipped', 'graph_hash' => '', 'operation_count' => 0, 'loss_count' => 0, 'operations_total' => 0, 'losses_total' => 0, 'truncated' => false, 'operations' => array(), 'losses' => array() );
		if ( ! is_array( $graph ) ) return array( 'blocks' => $blocks, 'receipt' => $receipt );
		$receipt['graph_hash'] = hash( 'sha256', wp_json_encode( $graph ) );
		foreach ( $graph['nodes'] as $node ) {
			$layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			$node_hash = hash( 'sha256', (string) ( $node['id'] ?? '' ) );
			$source_tag = $node['source']['tag'] ?? '';
			if ( ! in_array( $source_tag, array( 'article', 'aside', 'button', 'div', 'footer', 'header', 'input', 'main', 'nav', 'section', 'select', 'textarea' ), true ) ) {
				$receipt['losses'][] = array( 'reason_code' => 'unsupported_semantic_wrapper', 'node_hash' => $node_hash );
			}
			if ( array() === $layout ) {
				$receipt['operations'][] = array( 'strategy' => 'structural_noop', 'target_hash' => $node_hash );
				continue;
			}
			if ( ! empty( $graph['variants'] ) ) {
				$receipt['losses'][] = array( 'reason_code' => 'responsive_layout_ownership', 'node_hash' => $node_hash );
				continue;
			}
			if ( ! empty( $layout['item_placement'] ) || ! empty( $layout['order'] ) || in_array( $layout['display'], array( 'grid', 'columns' ), true ) ) {
				$receipt['losses'][] = array( 'reason_code' => ! empty( $layout['item_placement'] ) ? 'unsupported_item_placement' : 'equivalence_unproven_layout', 'node_hash' => $node_hash );
				continue;
			}
			if ( 'flex' !== ( $layout['display'] ?? '' ) || ! in_array( $layout['direction'] ?? '', array( 'row', 'column' ), true ) || ! preg_match('/^wrapper-[0-9]+$/D',(string)($node['id'] ?? '')) ) {
				$receipt['losses'][] = array( 'reason_code' => 'layout_target_unrepresentable', 'node_hash' => $node_hash );
				continue;
			}
			$target = $node['id'];
			$matched = false;
			$blocks = self::apply_flex( $blocks, $target, $layout['direction'], $matched );
			if ( ! $matched ) {
				$receipt['losses'][] = array( 'reason_code' => 'target_mismatch', 'node_hash' => $node_hash );
				continue;
			}
			$receipt['operations'][] = array( 'strategy' => 'core_group_flex_equivalent', 'target_hash' => hash( 'sha256', $target ), 'direction' => $layout['direction'] );
		}
		$receipt['status'] = empty( $receipt['operations'] ) ? ( empty( $receipt['losses'] ) ? 'skipped' : 'deferred' ) : 'applied';
		$receipt['operations_total'] = count( $receipt['operations'] ); $receipt['losses_total'] = count( $receipt['losses'] );
		$receipt['operation_count'] = min( 32, $receipt['operations_total'] ); $receipt['loss_count'] = min( 32, $receipt['losses_total'] ); $receipt['truncated'] = $receipt['operations_total'] > 32 || $receipt['losses_total'] > 32;
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
