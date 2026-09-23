<?php
/**
 * Core 12-column grid layout adapter.
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
 * Projects placement models onto a core blocks 12-column grid.
 *
 * The host keeps its own block name, gains the core grid layout attribute and
 * the generated release class, and each item gains a core `columnSpan` plus
 * `@tablet` and `@mobile` overrides when those viewports were captured.
 */
final class Static_Site_Importer_Core_Grid_Layout_Adapter extends Static_Site_Importer_Layout_Adapter {

	private const ROW_TOLERANCE = 8.0;

	public const COLUMNS = 12;

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'core-grid';
	}

	/**
	 * Adapter title.
	 */
	public function title(): string {
		return 'Core blocks 12-column grid';
	}

	/**
	 * Generated host class.
	 */
	public function host_class(): string {
		return 'ssi-layout-core-grid-host';
	}

	/**
	 * Report whether the host block can carry a core grid.
	 */
	public function host_refusal( array $host_block, array $model ): ?string {
		unset( $model );
		if ( ! is_string( $host_block['blockName'] ?? null ) || '' === $host_block['blockName'] ) {
			return 'host_block_missing';
		}
		if ( empty( $host_block['innerBlocks'] ) || ! is_array( $host_block['innerBlocks'] ) ) {
			return 'host_without_items';
		}
		return null;
	}

	/**
	 * Report whether one item block can carry a column span.
	 */
	public function item_refusal( array $item_block ): ?string {
		$name = $item_block['blockName'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			return 'item_block_missing';
		}
		return self::shared_reference_refusal( $item_block );
	}

	/**
	 * Row bands per captured viewport width: distinct visible item top edges.
	 *
	 * @var array<int,array<int,float>>
	 */
	private array $row_bands = array();

	/**
	 * Derive grid rows from every item's top edge, per viewport, so each item's
	 * row start and span line up with its siblings.
	 */
	public function prepare( array $model ): void {
		$this->row_bands = array();
		$host_model      = is_array( $model['host'] ?? null ) ? $model['host'] : array();
		foreach ( Static_Site_Importer_Layout_Placement_Model::viewport_widths( $host_model ) as $width ) {
			$host_box = Static_Site_Importer_Layout_Placement_Model::box_at( $host_model, $width );
			$tops     = array();
			foreach ( is_array( $model['items'] ?? null ) ? $model['items'] : array() as $item ) {
				$box = is_array( $item ) ? Static_Site_Importer_Layout_Placement_Model::box_at( $item, $width ) : null;
				if ( null !== $box && null !== $host_box && self::ROW_TOLERANCE <= (float) $box['width'] && self::ROW_TOLERANCE <= (float) $box['height'] ) {
					$tops[] = (float) $box['y'] - (float) $host_box['y'];
				}
			}
			sort( $tops );
			$bands = array();
			foreach ( $tops as $top ) {
				if ( array() === $bands || $top - end( $bands ) > self::ROW_TOLERANCE ) {
					$bands[] = $top;
				}
			}
			$this->row_bands[ $width ] = $bands;
		}
	}

	/**
	 * Write the core grid layout attribute onto the host block.
	 */
	public function place_host( array &$host_block, array $model ): void {
		unset( $model );
		$attrs              = is_array( $host_block['attrs'] ?? null ) ? $host_block['attrs'] : array();
		$attrs['layout']    = array(
			'type'        => 'grid',
			'columnCount' => self::COLUMNS,
		);
		$host_block['attrs'] = $attrs;
	}

	/**
	 * Write WordPress 7.1 child placement onto one item: the base viewport in
	 * `style.layout`, narrower viewports in `style['@tablet'|'@mobile'].layout`
	 * (the keys `wp_get_layout_child_values()` reads per breakpoint).
	 */
	public function place_item( array &$item_block, array $item_model, array $host_model ): void {
		$tiers = self::placement_tiers( $host_model );
		if ( null === $tiers['base'] ) {
			return;
		}

		$attrs = is_array( $item_block['attrs'] ?? null ) ? $item_block['attrs'] : array();
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		foreach ( array( 'base', '@tablet', '@mobile' ) as $tier ) {
			$width = $tiers[ $tier ];
			if ( null === $width ) {
				continue;
			}
			$placement = $this->child_placement( $item_model, $host_model, $width );
			if ( null === $placement ) {
				continue;
			}
			if ( 'base' === $tier ) {
				$style['layout'] = array_merge( is_array( $style['layout'] ?? null ) ? $style['layout'] : array(), $placement );
			} else {
				$style[ $tier ]           = is_array( $style[ $tier ] ?? null ) ? $style[ $tier ] : array();
				$style[ $tier ]['layout'] = $placement;
			}
		}

		$attrs['style']      = $style;
		$item_block['attrs'] = $attrs;
	}

	/**
	 * Column and row placement of one item at one captured width.
	 *
	 * @return array<string,int>|null
	 */
	private function child_placement( array $item_model, array $host_model, int $width ): ?array {
		$item_box = Static_Site_Importer_Layout_Placement_Model::box_at( $item_model, $width );
		$host_box = Static_Site_Importer_Layout_Placement_Model::box_at( $host_model, $width );
		if ( null === $item_box || null === $host_box || 0.0 >= (float) $host_box['width'] ) {
			return null;
		}
		$track               = (float) $host_box['width'] / self::COLUMNS;
		$left                = (float) $item_box['x'] - (float) $host_box['x'];
		list( $start, $span ) = self::track_span( $left, $left + (float) $item_box['width'], $track, self::COLUMNS );
		$top                 = (float) $item_box['y'] - (float) $host_box['y'];
		$bands               = $this->row_bands[ $width ] ?? array();
		$row_start           = self::band_index( $bands, $top );
		$row_end             = self::band_index( $bands, $top + (float) $item_box['height'] - 1.0 );
		return array(
			'columnStart' => $start,
			'columnSpan'  => $span,
			'rowStart'    => $row_start,
			'rowSpan'     => max( 1, $row_end - $row_start + 1 ),
		);
	}

	/**
	 * One-based index of the last band whose top edge is at or above `$y`.
	 *
	 * @param array<int,float> $bands Band top edges, ascending.
	 */
	private static function band_index( array $bands, float $y ): int {
		$row = 1;
		foreach ( $bands as $index => $top ) {
			if ( $y >= $top - self::ROW_TOLERANCE ) {
				$row = $index + 1;
			}
		}
		return $row;
	}
}
