<?php
/**
 * Captured product presentation at the WooCommerce ownership boundary.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Product_Presentation {
	private const SELECTORS = array(
		'variation' => 'select',
		'quantity'  => 'input.qty',
		'submit'    => '.single_add_to_cart_button',
		'gallery'   => '.woocommerce-product-gallery',
		'viewport'  => '.flex-viewport',
		'image'     => '.woocommerce-product-gallery__image img',
		'thumbs'    => '.flex-control-thumbs',
		'thumb'     => '.flex-control-thumbs li',
		'thumbimg'  => '.flex-control-thumbs img',
	);

	/** Project only presentation, never source selectors or purchase behavior. */
	public static function capture( array $entity ): array {
		$rules = array();
		foreach ( $entity['controls'] ?? array() as $index => $control ) {
			$tag  = strtolower( (string) ( $control['tag'] ?? '' ) );
			$type = strtolower( (string) ( $control['type'] ?? '' ) );
			$name = strtolower( (string) ( $control['name'] ?? '' ) );
			$role = 'select' === $tag ? 'variation' : ( 'input' === $tag && ( 'number' === $type || 'quantity' === $name ) ? 'quantity' : ( 'submit' === $type || ( 'button' === $tag && '' === $type ) ? 'submit' : '' ) );
			if ( '' === $role ) {
				continue;
			}
			$styles = $control['styles'] ?? self::block_styles( $control['presentation']['style'] ?? array() );
			foreach ( $entity['presentation_graph']['controls'] ?? array() as $row ) {
				if ( ( $row['index'] ?? null ) === $index ) {
					$styles = $row['control']['styles'] ?? $styles;
					break;
				}
			}
			$declarations = self::declarations( $styles );
			if ( $declarations ) {
				// Each variation may have its own captured design; do not flatten
				// several source selects onto the last control's presentation.
				$slot           = 'variation' === $role ? $role . ':' . count( array_filter( array_keys( $rules ), static fn( $key ) => str_starts_with( $key, 'variation:' ) ) ) : $role;
				$rules[ $slot ] = $declarations;
			}
		}
		return self::artifact( array_merge( $rules, self::gallery_rules( $entity['gallery'] ?? array() ) ) );
	}

	/** One deterministic scope and stylesheet artifact for all captured product rules. */
	private static function artifact( array $rules ): array {
		if ( ! $rules ) {
			return array();
		}
		$hash  = substr( hash( 'sha256', (string) json_encode( $rules ) ), 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic standalone contract.
		$class = 'ssi-product-' . $hash;
		$css   = '';
		foreach ( $rules as $slot => $declarations ) {
			$parts    = explode( ':', $slot );
			$selector = self::SELECTORS[ $parts[0] ];
			if ( 'variation' === $parts[0] ) {
				$selector = '.variations tr:nth-child(' . ( (int) $parts[1] + 1 ) . ') select';
			}
			$scope = '.' . $class . '.' . $class;
			$css  .= ( 'gallery' === $slot ? $scope . $selector . ',' : '' ) . $scope . ' ' . $selector . '{' . $declarations . '}';
		}
		return array(
			'schema'    => 'static-site-importer/product-presentation/v1',
			'className' => $class,
			'rules'     => $rules,
			'css'       => $css,
		);
	}

	/** Read serialized inline presentation, not source-platform selectors. */
	private static function inline_styles( string $html ): array {
		$styles = array();
		if ( preg_match( '/\bstyle=["\x27]([^"\x27]*)["\x27]/i', $html, $match ) ) {
			foreach ( explode( ';', html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) ) as $declaration ) {
				$pair = explode( ':', $declaration, 2 );
				if ( 2 === count( $pair ) ) {
					$styles[ trim( strtolower( $pair[0] ) ) ] = trim( $pair[1] );
				}
			}
		}
		return $styles;
	}

	/** Admit only captured lengths, never arbitrary CSS syntax. */
	private static function length( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh))$/D', $value ) ? $value : '';
	}

	/** Capture image boxes and strip arrangement before the source subtree is deleted. */
	public static function gallery_attrs( array $node ): array {
		$boxes = array();
		$gaps  = array();
		$walk  = static function ( array $block ) use ( &$walk, &$boxes, &$gaps ): void {
			$start = count( $boxes );
			$html  = $block['innerHTML'] ?? '';
			preg_match_all( '/<img\b[^>]*>/i', $html, $images );
			foreach ( $images[0] as $image ) {
				$css = self::inline_styles( $image );
				$box = array();
				foreach ( array( 'width', 'height' ) as $dimension ) {
					$value = $css[ $dimension ] ?? $block['attrs']['style']['dimensions'][ $dimension ] ?? $block['attrs'][ $dimension ] ?? '';
					if ( '' === $value && preg_match( '/\b' . $dimension . '=["\x27](\d+(?:\.\d+)?)["\x27]/i', $image, $match ) ) {
						$value = $match[1] . 'px';
					}
					$box[ $dimension ] = self::length( is_numeric( $value ) ? $value . 'px' : $value );
				}
				if ( str_ends_with( $box['width'], 'px' ) && str_ends_with( $box['height'], 'px' ) ) {
					$boxes[] = $box;
				}
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$walk( $child );
			}
			$css = self::inline_styles( $html );
			$gap = self::length( $css['gap'] ?? $block['attrs']['style']['spacing']['blockGap'] ?? '' );
			if ( '' !== $gap && count( $boxes ) > $start ) {
				$gaps[] = array(
					'boxes' => array_slice( $boxes, $start ),
					'gap'   => $gap,
				);
			}
		};
		$walk( $node );
		if ( ! $boxes ) {
			return array();
		}
		$first = $boxes[0];
		usort( $boxes, static fn( $a, $b ) => (float) $a['width'] <=> (float) $b['width'] );
		$main        = $boxes[ count( $boxes ) - 1 ];
		$thumb       = (float) $boxes[0]['width'] < (float) $main['width'] / 2 ? $boxes[0] : array();
		$root        = self::inline_styles( $node['innerHTML'] ?? '' );
		$direction   = $root['flex-direction'] ?? ( 'vertical' === ( $node['attrs']['layout']['orientation'] ?? '' ) ? 'column' : ( 'flex' === ( $node['attrs']['layout']['type'] ?? '' ) ? 'row' : '' ) );
		$thumb_first = $first === $thumb;
		if ( 'row-reverse' === $direction ) {
			$thumb_first = ! $thumb_first;
		}
		$thumbnail_gap = '';
		foreach ( $gaps as $group ) {
			if ( $thumb && count( $group['boxes'] ) > 1 && ! array_filter( $group['boxes'], static fn( $box ) => $box !== $thumb ) ) {
				$thumbnail_gap = $group['gap'];
				break;
			}
		}
		return array(
			'main'         => $main,
			'thumbnail'    => $thumb,
			'position'     => in_array( $direction, array( 'row', 'row-reverse' ), true ) ? ( $thumb_first ? 'left' : 'right' ) : 'below',
			'gap'          => self::length( $root['gap'] ?? $node['attrs']['style']['spacing']['blockGap'] ?? '' ),
			'thumbnailGap' => $thumbnail_gap,
		);
	}

	/** Gallery-only property allowlist; shared by capture and receipt validation. */
	private static function gallery_declarations( array $styles ): string {
		$css = self::declarations( $styles );
		foreach ( array( 'display', 'flex-direction', 'flex-shrink', 'flex-basis', 'float', 'gap', 'order', 'margin', 'object-fit' ) as $property ) {
			$value = $styles[ $property ] ?? '';
			if ( is_string( $value ) && ( self::length( $value ) || in_array( $value, array( 'flex', 'row', 'column', '0', '-1', 'none', 'contain' ), true ) ) ) {
				$css .= $property . ':' . $value . ' !important;';
			}
		}
		return $css;
	}

	/** Project captured geometry onto the native Woo gallery, never frozen markup. */
	private static function gallery_rules( array $gallery ): array {
		$width  = self::length( $gallery['main']['width'] ?? '' );
		$height = self::length( $gallery['main']['height'] ?? '' );
		if ( ! $width || ! $height ) {
			return array();
		}
		$side    = in_array( $gallery['position'] ?? '', array( 'left', 'right' ), true );
		$styles  = array(
			'gallery'  => array(
				'width'          => 'auto',
				'display'        => 'flex',
				'flex-direction' => $side ? 'row' : 'column',
				'float'          => 'none',
				'gap'            => self::length( $gallery['gap'] ?? '' ),
			),
			'viewport' => array(
				'width'      => $width,
				'height'     => $height,
				'max_width'  => '100%',
				'flex-basis' => $side ? $width : '',
			),
			'image'    => array(
				'width'      => '100%',
				'height'     => $height,
				'object-fit' => 'contain',
			),
		);
		$thumb_w = self::length( $gallery['thumbnail']['width'] ?? '' );
		$thumb_h = self::length( $gallery['thumbnail']['height'] ?? '' );
		if ( $thumb_w && $thumb_h ) {
			$styles['thumbs']   = array(
				'padding'        => '0',
				'display'        => 'flex',
				'flex-direction' => $side ? 'column' : 'row',
				'flex-shrink'    => '0',
				'gap'            => self::length( $gallery['thumbnailGap'] ?? '' ),
				'order'          => 'left' === ( $gallery['position'] ?? '' ) ? '-1' : '0',
				'margin'         => '0',
			);
			$styles['thumb']    = array(
				'width'  => $thumb_w,
				'height' => $thumb_h,
				'float'  => 'none',
				'margin' => '0',
			);
			$styles['thumbimg'] = array(
				'width'      => '100%',
				'height'     => '100%',
				'object-fit' => 'contain',
			);
		}
		return array_map( array( self::class, 'gallery_declarations' ), $styles );
	}

	/** Consume the producer's block-style presentation as well as graph facts. */
	private static function block_styles( array $style ): array {
		$styles = array();
		$groups = array(
			'color'      => array(
				'text'       => 'color',
				'background' => 'background_color',
			),
			'typography' => array(
				'fontSize'      => 'font_size',
				'fontFamily'    => 'font_family',
				'fontWeight'    => 'font_weight',
				'fontStyle'     => 'font_style',
				'lineHeight'    => 'line_height',
				'letterSpacing' => 'letter_spacing',
				'textTransform' => 'text_transform',
			),
			'border'     => array(
				'color'  => 'border_color',
				'width'  => 'border_width',
				'style'  => 'border_style',
				'radius' => 'border_radius',
			),
			'dimensions' => array(
				'width'     => 'width',
				'height'    => 'height',
				'minHeight' => 'min_height',
			),
		);
		foreach ( $groups as $group => $properties ) {
			foreach ( $properties as $key => $property ) {
				if ( isset( $style[ $group ][ $key ] ) && is_string( $style[ $group ][ $key ] ) ) {
					$styles[ $property ] = $style[ $group ][ $key ];
				}
			}
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $style['spacing']['padding'][ $side ] ) ) {
				$styles[ 'padding_' . $side ] = $style['spacing']['padding'][ $side ];
			}
		}
		return $styles;
	}

	/** Allowlisted computed CSS, with values unable to escape a declaration. */
	private static function declarations( array $styles ): string {
		$properties = array( 'width', 'height', 'min_width', 'max_width', 'min_height', 'max_height', 'box_sizing', 'border', 'border_width', 'border_style', 'border_color', 'border_radius', 'background', 'background_color', 'color', 'font_family', 'font_size', 'font_weight', 'font_style', 'line_height', 'letter_spacing', 'text_transform', 'text_align', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'box_shadow' );
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			foreach ( array( 'width', 'style', 'color' ) as $part ) {
				$properties[] = 'border_' . $side . '_' . $part;
			}
		}
		foreach ( array( 'top_left', 'top_right', 'bottom_left', 'bottom_right' ) as $corner ) {
			$properties[] = 'border_' . $corner . '_radius';
		}
		$css = '';
		foreach ( $properties as $property ) {
			$value = $styles[ $property ] ?? $styles[ str_replace( '_', '-', $property ) ] ?? null;
			if ( ! is_string( $value ) || '' === trim( $value ) || preg_match( '/[;{}<>\\\\]|\/\*|url\s*\(|expression\s*\(|@/i', $value ) ) {
				continue;
			}
			$css .= str_replace( '_', '-', $property ) . ':' . trim( $value ) . ' !important;';
		}
		return $css;
	}

	/** Rebuild a structured artifact before allowing stylesheet publication. */
	public static function validate_overlay( mixed $overlay ): ?array {
		if ( ! is_array( $overlay ) || 'static-site-importer/product-presentation/v1' !== ( $overlay['schema'] ?? '' ) || ! is_array( $overlay['rules'] ?? null ) ) {
			return null;
		}
		$controls = array();
		$gallery  = array();
		foreach ( $overlay['rules'] as $slot => $css ) {
			$is_gallery = in_array( $slot, array( 'gallery', 'viewport', 'image', 'thumbs', 'thumb', 'thumbimg' ), true );
			if ( ! is_string( $css ) || ( ! $is_gallery && ! preg_match( '/^(?:variation:\d+|quantity|submit)$/D', $slot ) ) ) {
				return null;
			}
			$styles = array();
			foreach ( explode( ';', $css ) as $declaration ) {
				if ( '' === $declaration ) {
					continue;
				}
				$pair = explode( ':', $declaration, 2 );
				if ( 2 !== count( $pair ) || ! str_ends_with( $pair[1], ' !important' ) ) {
					return null;
				}
				$styles[ $pair[0] ] = substr( $pair[1], 0, -11 );
			}
			if ( $is_gallery ) {
				$gallery[ $slot ] = self::gallery_declarations( $styles );
				continue;
			}
			$controls[] = array(
				'tag'    => str_starts_with( $slot, 'variation:' ) ? 'select' : 'input',
				'type'   => 'quantity' === $slot ? 'number' : 'submit',
				'styles' => $styles,
			);
		}
		$expected = self::capture( array( 'controls' => $controls ) );
		$expected = self::artifact( array_merge( $expected['rules'] ?? array(), $gallery ) );
		return $expected === $overlay ? $expected : null;
	}
}
