<?php
/**
 * Layout adapter contract shared by every layout projection adapter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Layout_Placement_Model' ) ) {
	require_once __DIR__ . '/class-static-site-importer-layout-placement-model.php';
}

/**
 * One projection contract for host layout adapters.
 *
 * An adapter owns its provider vocabulary. It receives the vendor-neutral
 * placement model and writes only its own host block facts and its own item
 * placement attributes; the projector owns block tree traversal, path
 * resolution, and loss reporting.
 */
abstract class Static_Site_Importer_Layout_Adapter {

	/**
	 * Adapter id, also its provider id in the layout capability.
	 */
	abstract public function id(): string;

	/**
	 * Human-readable adapter title for receipts.
	 */
	abstract public function title(): string;

	/**
	 * Generated class the host layout release styles for this adapter.
	 */
	abstract public function host_class(): string;

	/**
	 * Report whether the resolved host block can carry this layout.
	 *
	 * @param array<string,mixed> $host_block Resolved host block.
	 * @param array<string,mixed> $model      Normalized placement model.
	 * @return string|null Refusal reason, or null when eligible.
	 */
	abstract public function host_refusal( array $host_block, array $model ): ?string;

	/**
	 * Report whether one item block can carry a placement.
	 *
	 * @param array<string,mixed> $item_block Item block.
	 * @return string|null Loss reason, or null when eligible.
	 */
	abstract public function item_refusal( array $item_block ): ?string;

	/**
	 * Write adapter-owned host facts onto the host block.
	 *
	 * @param array<string,mixed> $host_block Host block, by reference.
	 * @param array<string,mixed> $model      Normalized placement model.
	 * @return void
	 */
	abstract public function place_host( array &$host_block, array $model ): void;

	/**
	 * Write adapter-owned placement attributes onto one item block.
	 *
	 * @param array<string,mixed> $item_block  Item block, by reference.
	 * @param array<string,mixed> $item_model  Normalized item row of the model.
	 * @param array<string,mixed> $host_model  Normalized host row of the model.
	 * @return void
	 */
	abstract public function place_item( array &$item_block, array $item_model, array $host_model ): void;

	/**
	 * Receive the whole normalized model before any item is placed, for
	 * adapters whose placement depends on sibling boxes.
	 *
	 * @param array<string,mixed> $model Normalized placement model.
	 */
	public function prepare( array $model ): void {
		unset( $model );
	}

	/**
	 * CSS selector, relative to the rendered host element, whose matches are
	 * the rendered placed items in placement order. Empty when the placed
	 * items stay direct children of the host (resolve them by block path).
	 */
	public function rendered_item_selector(): string {
		return '';
	}

	/**
	 * Block types that must be registered before this adapter may project.
	 *
	 * @return array<int,string>
	 */
	public function required_block_types(): array {
		return array();
	}

	/**
	 * Shared item refusal for blocks that reference other content.
	 *
	 * Synced patterns and template parts are shared across pages, so placing
	 * them inside one page section would reposition every reference.
	 *
	 * @param array<string,mixed> $item_block Item block.
	 * @return string|null Loss reason, or null when eligible.
	 */
	protected static function shared_reference_refusal( array $item_block ): ?string {
		$name = $item_block['blockName'] ?? null;
		return is_string( $name ) && in_array( $name, array( 'core/block', 'core/template-part' ), true ) ? 'item_shared_reference' : null;
	}

	/**
	 * Snap a pixel run onto grid tracks: one-based start line and span, both
	 * edges rounded to the nearest track and clamped inside the grid.
	 *
	 * @return array{0:int,1:int}
	 */
	protected static function track_span( float $start, float $end, float $track, int $tracks ): array {
		if ( 0.0 >= $track ) {
			return array( 1, 1 );
		}
		$first = max( 0, min( $tracks - 1, (int) round( $start / $track ) ) );
		$last  = max( $first + 1, min( $tracks, (int) round( $end / $track ) ) );
		return array( $first + 1, $last - $first );
	}

	/**
	 * Placement tiers of a host row: the widest viewport is the base, the next
	 * two carry the responsive override keys, widest first.
	 *
	 * @param array<string,mixed> $host_model Normalized host row of the model.
	 * @return array<string,int|null>
	 */
	protected static function placement_tiers( array $host_model ): array {
		$widths = Static_Site_Importer_Layout_Placement_Model::viewport_widths( $host_model );
		return array(
			'base'    => $widths[0] ?? null,
			'@tablet' => $widths[1] ?? null,
			'@mobile' => $widths[2] ?? null,
		);
	}
}
