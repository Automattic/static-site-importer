<?php
/**
 * Textarea row sizing policy for provider form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "A textarea renders N source rows", resolved once per textarea control.
 *
 * A source textarea sized by its own `rows` attribute (rather than an authored
 * CSS height) carries no CSS declaration a source-CSS-cascade compiler could
 * capture. blocks-engine still reports this row count (`effectiveRows()`,
 * always populated, defaulting to the browser's own unset-`rows` default of 2).
 * Jetpack's renderer hardcodes `rows='20'` and paints
 * `:where(.contact-form textarea){height:200px}`, and its editor stylesheet
 * floors the editor textarea at `min-height:200px`, so the authored count
 * never reaches either surface on its own.
 *
 * This policy resolves every textarea fact once, from the normalized control
 * and its captured styles, and each surface only realises them:
 *
 * - the row count, carried as the `ssi-textarea-rows-N` marker class on
 *   `jetpack/input` and rewritten onto the rendered control's `rows` attribute
 *   by the provider form runtime (the front end);
 * - the outer display and provider height release, projected into the
 *   presentation graph by the seeder;
 * - the editor box height and min-height release, applied by the editor
 *   surface to the part(s) that address the control.
 */
final class Static_Site_Importer_Form_Textarea_Rows {
	/** Transport class prefix the provider form runtime rewrites onto `rows`. */
	public const MARKER_PREFIX = 'ssi-textarea-rows-';

	/**
	 * Resolve the policy for one textarea control.
	 *
	 * @param int|null    $rows               Source row count, or null when out of range.
	 * @param string|null $display            Outer display to restore, or null when captured or rows-less.
	 * @param bool        $release_height     Whether the provider's fixed height is released to `auto`.
	 * @param string|null $editor_height      Editor border-box height of the rows, or null.
	 * @param bool        $release_min_height Whether the editor's `min-height` floor is released.
	 * @param bool        $has_editor_box     Whether the control carries a presentation box to size.
	 */
	private function __construct(
		public readonly ?int $rows,
		public readonly ?string $display,
		public readonly bool $release_height,
		public readonly ?string $editor_height,
		public readonly bool $release_min_height,
		public readonly bool $has_editor_box
	) {
	}

	/**
	 * Read a textarea's authored row count, defaulting to the HTML unset-rows
	 * value of 2. A count outside 1..50 is not carried.
	 *
	 * @param array<string,mixed> $control Source control metadata.
	 */
	public static function row_count( array $control ): ?int {
		$rows = isset( $control['rows'] ) && is_scalar( $control['rows'] ) ? (int) $control['rows'] : 2;
		return ( $rows >= 1 && $rows <= 50 ) ? $rows : null;
	}

	/**
	 * The `jetpack/input` transport class carrying a control's row count, or
	 * an empty string when it carries none.
	 *
	 * @param array<string,mixed> $control Source control metadata.
	 */
	public static function marker_class( array $control ): string {
		$rows = self::row_count( $control );
		return null === $rows ? '' : self::MARKER_PREFIX . $rows;
	}

	/**
	 * Resolve the policy for every textarea control of a form.
	 *
	 * The captured styles are the control's `presentation_graph.controls` row
	 * (the first row with its index); the source parent is the `control-<index>`
	 * node's parent in the form's layout graph.
	 *
	 * @param array<string,mixed> $form     Provider form manifest row.
	 * @param array<mixed>        $controls Normalized controls list.
	 * @return array<int,self> Policies keyed by control index.
	 */
	public static function resolve_form( array $form, array $controls ): array {
		$rows      = is_array( $form['presentation_graph']['controls'] ?? null ) ? $form['presentation_graph']['controls'] : array();
		$textareas = array();
		foreach ( $controls as $control_index => $control ) {
			if ( ! is_int( $control_index ) || ! is_array( $control ) || 'textarea' !== strtolower( trim( (string) ( $control['tag'] ?? '' ) ) ) ) {
				continue;
			}
			$styles = null;
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && ( $row['index'] ?? null ) === $control_index ) {
					$styles = is_array( $row['control']['styles'] ?? null ) ? $row['control']['styles'] : null;
					break;
				}
			}
			$textareas[ $control_index ] = self::resolve( $control, $styles, self::source_parent_blockifies_control( $form, $control_index ) );
		}
		return $textareas;
	}

	/**
	 * Resolve the policy for one textarea control.
	 *
	 * Rows-sized: Jetpack's fixed 200px height is released to `auto` so the
	 * browser sizes N line boxes using the padding, border, font-size, and
	 * line-height already carried onto the same control. A captured height is
	 * authoritative. A captured min-height constrains the rows but does not
	 * replace the provider height, so it is kept and the height still released.
	 *
	 * Display: the HTML platform renders an authored-less textarea inline-level,
	 * so its field row keeps the control's baseline descent; the provider paints
	 * it block-level. Without a captured `display`, the platform default is
	 * restored: `inline-block` in a block-flow parent, `block` when the source
	 * parent is a grid or flex container (the item is blockified there).
	 *
	 * Editor: the editor textarea has no rows attribute and Jetpack floors it at
	 * `min-height:200px`. Unless the source declares its own minimum the floor
	 * is released; a rows-sized control (resolved height `auto`) gets the height
	 * of N line boxes plus its carried block padding and border, which is the
	 * box the front end's `rows` attribute produces.
	 *
	 * @param array<string,mixed>      $control           Normalized textarea control.
	 * @param array<string,mixed>|null $captured_styles   Captured control styles, or null when none were captured.
	 * @param bool                     $parent_blockifies Whether the source parent is a grid or flex container.
	 */
	public static function resolve( array $control, ?array $captured_styles, bool $parent_blockifies ): self {
		$rows = self::row_count( $control );
		if ( null === $rows ) {
			return new self( null, null, false, null, null !== $captured_styles && ! isset( $captured_styles['min_height'] ), null !== $captured_styles );
		}
		$styles   = $captured_styles ?? array();
		$display  = isset( $styles['display'] ) ? null : ( $parent_blockifies ? 'block' : 'inline-block' );
		$released = ! isset( $styles['height'] );
		$height   = $released || 'auto' === $styles['height'] ? self::rows_height( $rows, $styles ) : null;
		return new self( $rows, $display, $released, $height, ! isset( $styles['min_height'] ), true );
	}

	/**
	 * The captured styles with the resolved display and height release applied.
	 *
	 * @param array<string,mixed> $styles Captured control styles.
	 * @return array<string,mixed>
	 */
	public function presentation_styles( array $styles ): array {
		if ( null !== $this->display ) {
			$styles['display'] = $this->display;
		}
		if ( $this->release_height ) {
			$styles['height'] = 'auto';
		}
		return $styles;
	}

	/**
	 * The editor box resets realising the rows: the released `min-height`
	 * floor, then the rows' border-box height.
	 *
	 * @return array<string,string>
	 */
	public function editor_resets(): array {
		if ( ! $this->has_editor_box ) {
			return array();
		}
		$resets = $this->release_min_height ? array( 'min-height' => '0' ) : array();
		if ( null !== $this->editor_height ) {
			$resets['height'] = $this->editor_height;
		}
		return $resets;
	}

	/**
	 * Whether the control's source parent is a grid or flex container, which
	 * blockifies the control's outer display.
	 *
	 * The parent comes from the form's layout graph (`control-<index>` node's
	 * `parent`); its display is read from `layout.display`, else the resolved
	 * `presentation.styles.display`. An unknown parent is treated as block flow.
	 *
	 * @param array<string,mixed> $form          Provider form manifest row.
	 * @param int                 $control_index Control index.
	 */
	private static function source_parent_blockifies_control( array $form, int $control_index ): bool {
		$nodes = $form['layout_graph']['nodes'] ?? null;
		if ( ! is_array( $nodes ) ) {
			return false;
		}
		$nodes_by_id = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes_by_id[ $node['id'] ] = $node;
			}
		}
		$parent_id = $nodes_by_id[ 'control-' . $control_index ]['parent'] ?? null;
		$parent    = is_string( $parent_id ) ? ( $nodes_by_id[ $parent_id ] ?? null ) : null;
		if ( ! is_array( $parent ) ) {
			return false;
		}
		$display = $parent['layout']['display'] ?? ( $parent['presentation']['styles']['display'] ?? null );

		return is_string( $display ) && in_array( strtolower( trim( $display ) ), array( 'grid', 'inline-grid', 'flex', 'inline-flex' ), true );
	}

	/**
	 * The border-box height of N line boxes under the carried block padding and
	 * border, or null when any of those edges is not a resolvable length.
	 *
	 * @param int                 $rows   Source row count.
	 * @param array<string,mixed> $styles Captured control styles in cascade order.
	 */
	private static function rows_height( int $rows, array $styles ): ?string {
		$edges = array(
			'padding_top'    => null,
			'padding_bottom' => null,
			'border_top'     => null,
			'border_bottom'  => null,
		);
		$width = static function ( string $token ): ?string {
			$keywords = array(
				'thin'   => '1px',
				'medium' => '3px',
				'thick'  => '5px',
			);
			if ( isset( $keywords[ $token ] ) ) {
				return $keywords[ $token ];
			}
			return self::is_block_length( $token ) ? $token : null;
		};
		foreach ( $styles as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$tokens = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevelWhitespace( trim( $value ) );
			$first  = $tokens[0] ?? '';
			if ( in_array( $key, array( 'padding', 'padding_block' ), true ) ) {
				$edges['padding_top']    = self::is_block_length( $first ) ? $first : null;
				$bottom                  = $tokens[ 'padding' === $key ? 2 : 1 ] ?? $first;
				$edges['padding_bottom'] = self::is_block_length( $bottom ) ? $bottom : null;
			} elseif ( in_array( $key, array( 'padding_top', 'padding_block_start' ), true ) ) {
				$edges['padding_top'] = self::is_block_length( $first ) ? $first : null;
			} elseif ( in_array( $key, array( 'padding_bottom', 'padding_block_end' ), true ) ) {
				$edges['padding_bottom'] = self::is_block_length( $first ) ? $first : null;
			} elseif ( 'border' === $key ) {
				// The shorthand's omitted width is `medium`; a `none` or `hidden` style has none.
				$border = '3px';
				foreach ( $tokens as $token ) {
					$border = $width( $token ) ?? $border;
				}
				$edges['border_top']    = in_array( 'none', $tokens, true ) || in_array( 'hidden', $tokens, true ) ? '0' : $border;
				$edges['border_bottom'] = $edges['border_top'];
			} elseif ( 'border_width' === $key ) {
				$edges['border_top']    = $width( $first );
				$edges['border_bottom'] = $width( $tokens[2] ?? $first );
			} elseif ( 'border_top_width' === $key ) {
				$edges['border_top'] = $width( $first );
			} elseif ( 'border_bottom_width' === $key ) {
				$edges['border_bottom'] = $width( $first );
			}
		}
		if ( in_array( null, $edges, true ) ) {
			return null;
		}
		$lines = $rows . 'lh';
		if ( 'content-box' === ( $styles['box_sizing'] ?? null ) ) {
			return 'calc(' . $lines . ')';
		}
		return 'calc(' . $lines . ' + ' . implode( ' + ', $edges ) . ')';
	}

	/**
	 * Whether a token is a non-negative length that resolves without a percentage basis.
	 *
	 * Utility frameworks carry lengths as `calc()` expressions over their spacing
	 * scale (a captured `py-3` is `calc(0.75rem)`); those resolve the same way
	 * when they contain no percentage and no nested declaration syntax.
	 *
	 * @param string $token One CSS value token.
	 */
	private static function is_block_length( string $token ): bool {
		return (bool) preg_match( '/^(?:0|(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|ch|ex|lh|vw|vh|vmin|vmax|svh|dvh|lvh)|calc\([^%;{}()]*(?:\([^%;{}()]*\)[^%;{}()]*)*\))$/D', $token );
	}
}
