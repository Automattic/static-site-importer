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

	/** Column count used when no captured layout suggests a better fit. */
	public const COLUMNS = 12;
	/** Largest column count considered when fitting the source's own grid. */
	public const MAX_COLUMNS = 24;
	/** Largest edge error (px, or share of the host width) a fitted grid may leave. */
	private const FIT_TOLERANCE_PX    = 4.0;
	private const FIT_TOLERANCE_SHARE = 0.01;

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
	 * Fitted grid per captured width: column count and horizontal gap (px).
	 * WordPress 7.1 lets a grid container change both per viewport
	 * (`style['@tablet'|'@mobile'].layout.columnCount` and `.spacing.blockGap`).
	 *
	 * @var array<int,array{columns:int,gap:float}>
	 */
	private array $fits = array();

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
		$this->fit_grid( $model );
	}

	/**
	 * Fit the column count and gaps to the source layout: the gaps are the
	 * smallest positive distances between neighbouring items at the base
	 * width, and the column count is the smallest N (up to MAX_COLUMNS) whose
	 * tracks put every item edge within tolerance at every captured width,
	 * else the N with the smallest worst-case error.
	 */
	private function fit_grid( array $model ): void {
		$host_model = is_array( $model['host'] ?? null ) ? $model['host'] : array();
		$widths     = Static_Site_Importer_Layout_Placement_Model::viewport_widths( $host_model );
		$runs       = array();
		foreach ( $widths as $width ) {
			$host_box = Static_Site_Importer_Layout_Placement_Model::box_at( $host_model, $width );
			if ( null === $host_box || 0.0 >= (float) $host_box['width'] ) {
				continue;
			}
			$boxes = array();
			foreach ( is_array( $model['items'] ?? null ) ? $model['items'] : array() as $item ) {
				$box = is_array( $item ) ? Static_Site_Importer_Layout_Placement_Model::box_at( $item, $width ) : null;
				if ( null !== $box && 1.0 <= (float) $box['width'] && 1.0 <= (float) $box['height'] ) {
					$boxes[] = array(
						'left'   => (float) $box['x'] - (float) $host_box['x'],
						'right'  => (float) $box['x'] + (float) $box['width'] - (float) $host_box['x'],
						'top'    => (float) $box['y'] - (float) $host_box['y'],
						'bottom' => (float) $box['y'] + (float) $box['height'] - (float) $host_box['y'],
					);
				}
			}
			$runs[ $width ] = array(
				'width' => (float) $host_box['width'],
				'boxes' => $boxes,
			);
		}
		if ( array() === $runs ) {
			return;
		}
		$this->fits = array();
		foreach ( $runs as $width => $run ) {
			$this->fits[ (int) $width ] = self::fit_width( $run );
		}
	}

	/**
	 * The fewest tracks (with the source's neighbour gap, or no gap) that put
	 * every item edge at this width within tolerance, else the smallest error.
	 *
	 * @param array{width:float,boxes:array<int,array<string,float>>} $run
	 * @return array{columns:int,gap:float}
	 */
	private static function fit_width( array $run ): array {
		$gaps = array_unique( array( self::neighbour_gap( $run['boxes'], 'left', 'right', 'top', 'bottom' ), 0.0 ) );
		$best = null;
		foreach ( $gaps as $gap ) {
			for ( $columns = 1; $columns <= self::MAX_COLUMNS; $columns++ ) {
				list( $within, $worst ) = self::fit_error( array( $run ), $columns, $gap );
				$rank                   = $within ? array( 0, $columns, $worst ) : array( 1, $worst, $columns );
				if ( null === $best || $rank < $best['rank'] ) {
					$best = array(
						'rank'    => $rank,
						'columns' => $columns,
						'gap'     => $gap,
					);
				}
			}
		}
		return array(
			'columns' => $best['columns'],
			'gap'     => $best['gap'],
		);
	}

	/**
	 * Fitted grid at one captured width.
	 *
	 * @return array{columns:int,gap:float}
	 */
	private function fit_at( int $width ): array {
		return $this->fits[ $width ] ?? array(
			'columns' => self::COLUMNS,
			'gap'     => 0.0,
		);
	}

	/**
	 * Whether every item edge lands within tolerance on N tracks with the
	 * given gap at every captured width, and the worst edge error.
	 *
	 * @param array<int|string,array{width:float,boxes:array<int,array<string,float>>}> $runs
	 * @return array{0:bool,1:float}
	 */
	private static function fit_error( array $runs, int $columns, float $gap ): array {
		$worst  = 0.0;
		$within = true;
		foreach ( $runs as $run ) {
			$pitch = ( $run['width'] + $gap ) / $columns;
			foreach ( $run['boxes'] as $box ) {
				$start = max( 0, min( $columns - 1, (int) round( $box['left'] / $pitch ) ) );
				$end   = max( $start + 1, min( $columns, (int) round( ( $box['right'] + $gap ) / $pitch ) ) );
				$error = max( abs( $box['left'] - $start * $pitch ), abs( $box['right'] - ( $end * $pitch - $gap ) ) );
				$worst = max( $worst, $error );
				if ( $error > max( self::FIT_TOLERANCE_PX, self::FIT_TOLERANCE_SHARE * $run['width'] ) ) {
					$within = false;
				}
			}
		}
		return array( $within, $worst );
	}

	/**
	 * Smallest positive distance between two boxes that sit side by side on
	 * one axis (overlapping on the other axis), or 0 when none do.
	 *
	 * @param array<int,array<string,float>> $boxes
	 */
	private static function neighbour_gap( array $boxes, string $near, string $far, string $cross_near, string $cross_far ): float {
		$gap = INF;
		foreach ( $boxes as $a ) {
			foreach ( $boxes as $b ) {
				$distance = $b[ $near ] - $a[ $far ];
				$overlaps = min( $a[ $cross_far ], $b[ $cross_far ] ) - max( $a[ $cross_near ], $b[ $cross_near ] ) > 1.0;
				if ( $overlaps && $distance > 0.5 && $distance < $gap ) {
					$gap = $distance;
				}
			}
		}
		return is_finite( $gap ) ? round( $gap ) : 0.0;
	}

	/** Fitted column count of the host being projected, at its widest width. */
	public function columns(): int {
		return array() === $this->fits ? self::COLUMNS : $this->fits[ max( array_keys( $this->fits ) ) ]['columns'];
	}

	/**
	 * Write the core grid layout attribute onto the host block.
	 */
	public function place_host( array &$host_block, array $model ): void {
		$tiers = self::placement_tiers( is_array( $model['host'] ?? null ) ? $model['host'] : array() );
		$attrs = is_array( $host_block['attrs'] ?? null ) ? $host_block['attrs'] : array();
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		foreach ( array( 'base', '@tablet', '@mobile' ) as $tier ) {
			if ( null === $tiers[ $tier ] ) {
				continue;
			}
			$fit = $this->fit_at( (int) $tiers[ $tier ] );
			// Vertical spacing stays with the items' own margins, as in the
			// source flow; a row gap would add to it.
			$gap = array(
				'top'  => '0px',
				'left' => self::px( $fit['gap'] ),
			);
			if ( 'base' === $tier ) {
				$attrs['layout']              = array(
					'type'        => 'grid',
					'columnCount' => $fit['columns'],
				);
				$style['spacing']             = is_array( $style['spacing'] ?? null ) ? $style['spacing'] : array();
				$style['spacing']['blockGap'] = $gap;
				continue;
			}
			$style[ $tier ]                        = is_array( $style[ $tier ] ?? null ) ? $style[ $tier ] : array();
			$style[ $tier ]['layout']              = array_merge( is_array( $style[ $tier ]['layout'] ?? null ) ? $style[ $tier ]['layout'] : array(), array( 'columnCount' => $fit['columns'] ) );
			$style[ $tier ]['spacing']             = is_array( $style[ $tier ]['spacing'] ?? null ) ? $style[ $tier ]['spacing'] : array();
			$style[ $tier ]['spacing']['blockGap'] = $gap;
		}
		$attrs['style']      = $style;
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
		$fit                 = $this->fit_at( $width );
		$pitch               = ( (float) $host_box['width'] + $fit['gap'] ) / $fit['columns'];
		$left                = (float) $item_box['x'] - (float) $host_box['x'];
		list( $start, $span ) = self::track_span( $left, $left + (float) $item_box['width'] + $fit['gap'], $pitch, $fit['columns'] );
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

	private static function px( float $value ): string {
		return ( 0.0 === $value ? '0' : (string) (int) round( $value ) ) . 'px';
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
