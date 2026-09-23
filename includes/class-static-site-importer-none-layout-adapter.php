<?php
/**
 * No-op layout adapter that restores the original block tree.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-layout-adapter.php';
}

/**
 * Projects nothing: the block tree keeps its original form.
 *
 * Re-projecting with `none` reproduces a clean result from the original block
 * tree, which is also how `--adapter=none` restores the original content
 * snapshot taken by the layout CLI.
 */
final class Static_Site_Importer_None_Layout_Adapter extends Static_Site_Importer_Layout_Adapter {

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'none';
	}

	/**
	 * Adapter title.
	 */
	public function title(): string {
		return 'No layout projection';
	}

	/**
	 * Generated host class.
	 */
	public function host_class(): string {
		return '';
	}

	/**
	 * The no-op adapter never refuses a host.
	 */
	public function host_refusal( array $host_block, array $model ): ?string {
		unset( $host_block, $model );
		return null;
	}

	/**
	 * The no-op adapter never refuses an item.
	 */
	public function item_refusal( array $item_block ): ?string {
		unset( $item_block );
		return null;
	}

	/**
	 * Rewrite nothing.
	 */
	public function place_host( array &$host_block, array $model ): void {
		unset( $host_block, $model );
	}

	/**
	 * Rewrite nothing.
	 */
	public function place_item( array &$item_block, array $item_model, array $host_model ): void {
		unset( $item_block, $item_model, $host_model );
	}
}
