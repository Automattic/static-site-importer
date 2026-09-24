<?php
/**
 * Canvas absolute layout adapter.
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
 * Projects placement models into a Canvas (`tabor/canvas`) block.
 *
 * The host block keeps its own name and markup; its placed children move into
 * one `tabor/canvas` child, each carrying Canvas's own `canvas` attribute:
 * `{desktop|tablet|mobile: {column,row,columnSpan,rowSpan,gridColumns}}`.
 * Canvas uses 12 columns at default alignment and a row pitch of
 * `canvas width * 0.0215` at every viewport. Images carry `frameRatio`
 * (width / height); `core/buttons` keep Canvas's 4-column, 2-row minimum.
 * Children Canvas cannot hold stay in the host, after the canvas, in flow.
 */
final class Static_Site_Importer_Canvas_Layout_Adapter extends Static_Site_Importer_Layout_Adapter {

	public const HOST_BLOCK       = 'tabor/canvas';
	public const GRID_COLUMNS     = 12;
	public const ROW_PITCH_FACTOR = 0.0215;
	public const MAX_ROWS         = 500;
	/** Canvas's reference width when the theme sets no content size. */
	private const DEFAULT_REFERENCE_WIDTH = 1340.0;

	/** Blocks Canvas accepts as items, and inside grouped items. */
	private const ITEM_BLOCKS    = array( 'core/heading', 'core/paragraph', 'core/image', 'core/buttons', 'core/group' );
	private const NESTED_BLOCKS  = array( 'core/heading', 'core/paragraph', 'core/image', 'core/buttons', 'core/button', 'core/group' );
	private const PLACED_FLAG    = 'ssi_canvas_placed';
	private const MODES          = array( 'base' => 'desktop', '@tablet' => 'tablet', '@mobile' => 'mobile' );

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'canvas';
	}

	/**
	 * Adapter title.
	 */
	public function title(): string {
		return 'Canvas';
	}

	/**
	 * Generated host class.
	 */
	public function host_class(): string {
		return 'ssi-layout-canvas-host';
	}

	/**
	 * Block types that must be registered before this adapter may project.
	 *
	 * @return array<int,string>
	 */
	public function required_block_types(): array {
		return array( self::HOST_BLOCK );
	}

	/**
	 * Canvas renders each placed child inside its own grid item wrapper.
	 */
	public function rendered_item_selector(): string {
		return '.wp-block-tabor-canvas > .canvas__grid > *';
	}

	/**
	 * Report whether the host block can carry a canvas.
	 */
	public function host_refusal( array $host_block, array $model ): ?string {
		unset( $model );
		if ( ! is_string( $host_block['blockName'] ?? null ) || '' === $host_block['blockName'] ) {
			return 'host_block_missing';
		}
		if ( self::HOST_BLOCK === $host_block['blockName'] ) {
			return 'host_already_canvas';
		}
		if ( empty( $host_block['innerBlocks'] ) || ! is_array( $host_block['innerBlocks'] ) ) {
			return 'host_without_items';
		}
		return null;
	}

	/**
	 * Report whether one item block can live inside a canvas.
	 */
	public function item_refusal( array $item_block ): ?string {
		$name = $item_block['blockName'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			return 'item_block_missing';
		}
		$shared = self::shared_reference_refusal( $item_block );
		if ( null !== $shared ) {
			return $shared;
		}
		if ( ! in_array( $name, self::ITEM_BLOCKS, true ) || ! self::descendants_allowed( $item_block ) ) {
			return 'item_not_canvas_eligible';
		}
		return null;
	}

	/**
	 * Write Canvas placements onto one item block.
	 */
	public function place_item( array &$item_block, array $item_model, array $host_model ): void {
		$tiers  = self::placement_tiers( $host_model );
		$canvas = array();
		foreach ( self::MODES as $tier => $mode ) {
			$width = $tiers[ $tier ];
			if ( null === $width ) {
				continue;
			}
			$placement = self::placement( $item_block, $item_model, $host_model, $width, 'desktop' === $mode ? self::reference_width() : null );
			if ( null !== $placement ) {
				$canvas[ $mode ] = $placement;
			}
		}
		if ( array() === $canvas ) {
			return;
		}
		$attrs                           = is_array( $item_block['attrs'] ?? null ) ? $item_block['attrs'] : array();
		$attrs['canvas']                 = $canvas;
		$item_block['attrs']             = $attrs;
		$item_block['attrs_dirty']       = true;
		$item_block[ self::PLACED_FLAG ] = true;
	}

	/**
	 * Move the placed children into one `tabor/canvas` child of the host.
	 */
	public function place_host( array &$host_block, array $model ): void {
		unset( $model );
		$placed    = array();
		$remaining = array();
		foreach ( $host_block['innerBlocks'] as $child ) {
			if ( ! empty( $child[ self::PLACED_FLAG ] ) ) {
				unset( $child[ self::PLACED_FLAG ] );
				$placed[] = $child;
			} else {
				$remaining[] = $child;
			}
		}
		if ( array() === $placed ) {
			return;
		}

		$rows = array();
		foreach ( $placed as $child ) {
			foreach ( $child['attrs']['canvas'] as $mode => $placement ) {
				$rows[ $mode ] = max( $rows[ $mode ] ?? 1, $placement['row'] + $placement['rowSpan'] - 1 );
			}
		}
		$canvas = array(
			'blockName'    => self::HOST_BLOCK,
			'attrs'        => array(
				'desktopRows' => $rows['desktop'] ?? 12,
				'tabletRows'  => $rows['tablet'] ?? 1,
				'mobileRows'  => $rows['mobile'] ?? 1,
				// Measured placements already include the source gaps.
				'style'       => array(
					'spacing' => array(
						'blockGap' => array(
							'top'  => '0px',
							'left' => '0px',
						),
					),
				),
			),
			'innerBlocks'  => $placed,
			'innerContent' => array_fill( 0, count( $placed ), null ),
		);

		$strings = array_values( array_filter( $host_block['innerContent'], 'is_string' ) );
		$open    = $strings[0] ?? '';
		$close   = count( $strings ) > 1 ? end( $strings ) : '';
		$host_block['innerBlocks']  = array_merge( array( $canvas ), $remaining );
		$host_block['innerContent'] = array_merge( array( $open ), array_fill( 0, count( $remaining ) + 1, null ), array( $close ) );
		$host_block['attrs_dirty']  = true;
		foreach ( $host_block['innerBlocks'] as $index => $child ) {
			$host_block['innerBlocks'][ $index ]['attrs_dirty'] = true;
		}
	}

	/**
	 * Canvas placement of one item at one captured width, relative to the host.
	 *
	 * @return array<string,int|float>|null
	 */
	private static function placement( array $item_block, array $item_model, array $host_model, int $width, ?float $reference_width = null ): ?array {
		$item_box = Static_Site_Importer_Layout_Placement_Model::box_at( $item_model, $width );
		$host_box = Static_Site_Importer_Layout_Placement_Model::box_at( $host_model, $width );
		if ( null === $item_box || null === $host_box || 0.0 >= (float) $host_box['width'] ) {
			return null;
		}
		$host_width = (float) $host_box['width'];
		$left       = (float) $item_box['x'] - (float) $host_box['x'];
		$top        = (float) $item_box['y'] - (float) $host_box['y'];
		list( $column, $column_span ) = self::track_span( $left, $left + (float) $item_box['width'], $host_width / self::GRID_COLUMNS, self::GRID_COLUMNS );
		list( $row, $row_span )       = self::track_span( $top, $top + (float) $item_box['height'], max( 1.0, $host_width * self::ROW_PITCH_FACTOR ), self::MAX_ROWS );

		$name = (string) ( $item_block['blockName'] ?? '' );
		if ( 'core/buttons' === $name ) {
			$column_span = max( 4, $column_span );
			$row_span    = max( 2, $row_span );
			$column      = min( $column, self::GRID_COLUMNS - $column_span + 1 );
		}
		$placement = array(
			'column'      => $column,
			'row'         => $row,
			'columnSpan'  => $column_span,
			'rowSpan'     => $row_span,
			'gridColumns' => self::GRID_COLUMNS,
		);
		if ( 'core/image' === $name && 0.0 < (float) $item_box['height'] ) {
			$placement['frameRatio'] = (float) sprintf( '%.6g', (float) $item_box['width'] / (float) $item_box['height'] );
		}
		// An exact frame keeps the measured position and size instead of
		// snapping to whole cells. Canvas measures desktop frames against the
		// centered reference canvas (the theme content width, capped at the
		// canvas width) and other viewports against the canvas width; x and
		// width are fractions of that box (outside [0, 1] is allowed), y is in
		// row pitches from the top, and ratio is width / height.
		if ( 1.0 <= (float) $item_box['width'] && 1.0 <= (float) $item_box['height'] ) {
			$bounds_width      = null !== $reference_width ? min( $host_width, $reference_width ) : $host_width;
			$bounds_left       = ( $host_width - $bounds_width ) / 2;
			$placement['free'] = array(
				'x'     => round( ( $left - $bounds_left ) / $bounds_width, 6 ),
				'y'     => round( $top / max( 1.0, $host_width * self::ROW_PITCH_FACTOR ), 6 ),
				'width' => max( 0.000001, round( (float) $item_box['width'] / $bounds_width, 6 ) ),
				'ratio' => max( 0.000001, round( (float) $item_box['width'] / (float) $item_box['height'], 6 ) ),
			);
		}
		return $placement;
	}

	/**
	 * The reference width Canvas measures desktop frames against, in px: the
	 * theme's content size, or Canvas's 1340px fallback when it is unset.
	 * Null when the content size is not a plain length (for example a
	 * clamp()), in which case desktop frames fall back to the canvas width.
	 */
	private static function reference_width(): ?float {
		$size = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'layout', 'contentSize' ) ) : null;
		if ( ! is_string( $size ) || '' === trim( $size ) ) {
			return self::DEFAULT_REFERENCE_WIDTH;
		}
		if ( 1 !== preg_match( '/^\s*([0-9]+(?:\.[0-9]+)?)(px|rem|em)\s*$/', $size, $match ) ) {
			return null;
		}
		return (float) $match[1] * ( 'px' === $match[2] ? 1.0 : 16.0 );
	}
	/**
	 * Whether every nested block of a grouped item is one Canvas can hold.
	 */
	private static function descendants_allowed( array $block ): bool {
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner ) {
			if ( ! in_array( (string) ( $inner['blockName'] ?? '' ), self::NESTED_BLOCKS, true ) || ! self::descendants_allowed( $inner ) ) {
				return false;
			}
		}
		return true;
	}
}
