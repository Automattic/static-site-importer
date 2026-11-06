<?php
/**
 * Project serialized source presentation onto native commerce replacements.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Product_Presentation {
	/** Register delivery for both frontend and server-rendered editor previews. */
	public static function register(): void {
		add_filter( 'render_block', array( self::class, 'render' ), 20, 2 );
	}

	/** Read captured inline declarations; never copy arbitrary CSS into output. */
	private static function declarations( string $html ): array {
		preg_match( '/^\s*<[^>]+\bstyle=["\x27]([^"\x27]*)/i', $html, $match );
		$values = array();
		foreach ( explode( ';', html_entity_decode( $match[1] ?? '', ENT_QUOTES, 'UTF-8' ) ) as $entry ) {
			$pair = explode( ':', $entry, 2 );
			if ( 2 === count( $pair ) ) {
				$values[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}
		return $values;
	}

	/** Accept measured CSS lengths, not URLs, selectors, or executable CSS. */
	private static function length( mixed $value ): string {
		return is_scalar( $value ) && preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw)$/D', (string) $value ) ? (string) $value : '';
	}

	/** Preserve block supports and measured root styles, including HTML form anchors. */
	public static function attrs( array $node ): array {
		$attrs = Static_Site_Importer_Product_Field_Blocks::presentation_attrs( $node['attrs'] ?? array(), array() );
		$css   = self::declarations( $node['innerHTML'] ?? '' );
		foreach ( array( 'width', 'height', 'min-height' ) as $property ) {
			$value = self::length( $css[ $property ] ?? '' );
			if ( '' !== $value ) {
				$attrs['metadata']['ssiPresentation']['root'][ $property ] = $value;
			}
		}
		foreach ( array( 'color', 'background-color' ) as $property ) {
			if ( preg_match( '/^#[a-f0-9]{3,8}$/iD', $css[ $property ] ?? '' ) ) {
				$attrs['metadata']['ssiPresentation']['root'][ $property ] = $css[ $property ];
			}
		}
		// Dynamic Woo blocks do not all implement the same core style supports.
		foreach ( array(
			'font-size'        => array( 'typography', 'fontSize' ),
			'line-height'      => array( 'typography', 'lineHeight' ),
			'color'            => array( 'color', 'text' ),
			'background-color' => array( 'color', 'background' ),
			'border-radius'    => array( 'border', 'radius' ),
		) as $property => $path ) {
			$value = $attrs['style'][ $path[0] ][ $path[1] ] ?? $css[ $property ] ?? '';
			if ( '' !== self::length( $value ) || ( is_string( $value ) && preg_match( '/^#[a-f0-9]{3,8}$/iD', $value ) ) ) {
				$attrs['metadata']['ssiPresentation']['root'][ $property ] = $value;
			}
		}
		foreach ( array( 'padding', 'margin' ) as $spacing ) {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				$value = $attrs['style']['spacing'][ $spacing ][ $side ] ?? $css[ $spacing . '-' . $side ] ?? '';
				if ( '' !== self::length( $value ) ) {
					$attrs['metadata']['ssiPresentation']['root'][ $spacing . '-' . $side ] = $value;
				}
			}
		}
		return $attrs;
	}

	/** Capture image boxes and the thumbnail container's arrangement before deletion. */
	public static function gallery_attrs( array $node ): array {
		$attrs         = self::attrs( $node );
		$boxes         = array();
		$thumbnail_gap = '';
		$walk          = static function ( array $block ) use ( &$walk, &$boxes, &$thumbnail_gap ): void {
			$container = self::declarations( $block['innerHTML'] ?? '' );
			if ( 'column' === ( $container['flex-direction'] ?? '' ) ) {
				$thumbnail_gap = self::length( $container['gap'] ?? '' );
			}
			$html = $block['innerHTML'] ?? '';
			preg_match_all( '/<img\b[^>]*>/i', $html, $images );
			foreach ( $images[0] as $image ) {
				$css = self::declarations( $image );
				foreach ( array( 'width', 'height' ) as $dimension ) {
					if ( ! isset( $css[ $dimension ] ) && preg_match( '/\b' . $dimension . '=["\x27](\d+(?:\.\d+)?)["\x27]/i', $image, $match ) ) {
						$css[ $dimension ] = $match[1] . 'px';
					}
				}
				$width  = self::length( $css['width'] ?? $block['attrs']['width'] ?? '' );
				$height = self::length( $css['height'] ?? $block['attrs']['height'] ?? '' );
				if ( str_ends_with( $width, 'px' ) && str_ends_with( $height, 'px' ) ) {
					$boxes[] = array(
						'width'  => $width,
						'height' => $height,
					);
				}
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$walk( $child );
			}
		};
		$walk( $node );
		if ( count( $boxes ) < 2 ) {
			return $attrs;
		}
		usort( $boxes, static fn( $a, $b ) => (float) $a['width'] <=> (float) $b['width'] );
		$thumb = $boxes[0];
		$main  = $boxes[ count( $boxes ) - 1 ];
		if ( (float) $thumb['width'] >= (float) $main['width'] / 2 ) {
			return $attrs;
		}
		// DOM order and captured flex direction determine the strip's side.
		$root      = self::declarations( $node['innerHTML'] ?? '' );
		$direction = $root['flex-direction'] ?? ( ( $node['attrs']['layout']['orientation'] ?? '' ) === 'vertical' ? 'column' : ( ( $node['attrs']['layout']['type'] ?? '' ) === 'flex' ? 'row' : '' ) );
		$vertical  = in_array( $direction, array( 'row', 'row-reverse' ), true );
		$html      = serialize_block( $node );
		preg_match( '/<img\b[^>]*>/i', $html, $first );
		$first_css   = self::declarations( $first[0] ?? '' );
		$thumb_first = ( $first_css['width'] ?? '' ) === $thumb['width'];
		if ( 'row-reverse' === $direction ) {
			$thumb_first = ! $thumb_first;
		}
		$gap = self::length( $root['gap'] ?? $node['attrs']['style']['spacing']['blockGap'] ?? '' );
		$attrs['metadata']['ssiPresentation']['gallery'] = array(
			'main'         => $main,
			'thumbnail'    => $thumb,
			'position'     => $vertical ? ( $thumb_first ? 'left' : 'right' ) : 'below',
			'gap'          => $gap,
			'thumbnailGap' => $thumbnail_gap,
		);
		return $attrs;
	}

	/** Generate only allowlisted declarations, scoped by the exact source capture. */
	public static function render( string $html, array $block ): string {
		$facts = $block['attrs']['metadata']['ssiPresentation'] ?? array();
		if ( ! $facts || ! in_array( $block['blockName'] ?? '', array( 'woocommerce/product-image-gallery', 'woocommerce/add-to-cart-form', 'woocommerce/product-price', 'core/post-title', 'core/post-content', 'woocommerce/breadcrumbs' ), true ) ) {
			return $html;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable hashing also supports standalone consumers.
		$class = 'ssi-product-' . substr( hash( 'sha256', (string) json_encode( $facts ) ), 0, 16 );
		$scope = '.' . $class;
		$css   = '';
		foreach ( $facts['root'] ?? array() as $property => $value ) {
			if ( in_array( $property, array( 'width', 'height', 'min-height', 'font-size', 'line-height', 'border-radius', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' ), true ) && '' !== self::length( $value ) ) {
				$css .= $scope . '{' . $property . ':' . $value . ';max-width:100%}';
			} elseif ( in_array( $property, array( 'color', 'background-color' ), true ) && is_string( $value ) && preg_match( '/^#[a-f0-9]{3,8}$/iD', $value ) ) {
				$css .= $scope . '{' . $property . ':' . $value . '}';
			}
		}
		$gallery = $facts['gallery'] ?? array();
		if ( $gallery ) {
			$main_w        = self::length( $gallery['main']['width'] ?? '' );
			$main_h        = self::length( $gallery['main']['height'] ?? '' );
			$thumb_w       = self::length( $gallery['thumbnail']['width'] ?? '' );
			$thumb_h       = self::length( $gallery['thumbnail']['height'] ?? '' );
			$gap           = self::length( $gallery['gap'] ?? '' );
			$thumbnail_gap = self::length( $gallery['thumbnailGap'] ?? '' );
			if ( $main_w && $main_h && $thumb_w && $thumb_h ) {
				$side = in_array( $gallery['position'] ?? '', array( 'left', 'right' ), true );
				$css .= "$scope .woocommerce-product-gallery{display:flex;flex-direction:" . ( $side ? 'row' : 'column' ) . ';width:auto!important;float:none!important;' . ( $gap ? "gap:$gap;" : '' ) . '}';
				$css .= "$scope .flex-viewport{width:$main_w;max-width:100%;height:$main_h!important;flex:0 1 $main_w;}";
				$css .= "$scope .woocommerce-product-gallery__image img{width:100%;height:$main_h;object-fit:contain;}";
				$css .= "$scope .flex-control-thumbs{display:flex;flex-direction:" . ( $side ? 'column' : 'row' ) . ';flex-shrink:0;margin:0!important;padding:0!important;' . ( $thumbnail_gap ? "gap:$thumbnail_gap;" : '' ) . ( 'left' === ( $gallery['position'] ?? '' ) ? 'order:-1;' : '' ) . '}';
				$css .= "$scope .flex-control-thumbs li{width:$thumb_w!important;height:$thumb_h;float:none!important;margin:0!important;}";
				$css .= "$scope .flex-control-thumbs img{width:100%;height:100%;object-fit:contain;}";
			}
		}
		if ( '' === $css ) {
			return $html;
		}
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}
		$processor->add_class( $class );
		return '<style>' . $css . '</style>' . $processor->get_updated_html();
	}
}
