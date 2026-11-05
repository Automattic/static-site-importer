<?php
/**
 * Shared product field bindings for cards and single-product documents.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Product_Field_Blocks {
	/** Carry only presentation supports to a replacement block. */
	public static function presentation_attrs( array $attrs, array $own ): array {
		return array_merge( array_intersect_key( $attrs, array_flip( array( 'className', 'textAlign', 'textColor', 'backgroundColor', 'fontSize', 'fontFamily', 'style', 'align' ) ) ), $own );
	}

	/** Build a dynamic block without frozen saved HTML. */
	public static function dynamic_block( string $name, array $attrs ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/** Convert a known field slot, preserving its presentation. */
	public static function field( array $node, string $role, bool $loop = false ): ?array {
		$own = array();
		switch ( $role ) {
			case 'name':
				if ( 'core/heading' !== $node['blockName'] ) {
					return null;
				}
				$name = 'core/post-title';
				$own  = array( 'level' => (int) ( $node['attrs']['level'] ?? 2 ) );
				break;
			case 'price':
				$name = 'woocommerce/product-price';
				$own  = array( 'isDescendentOfQueryLoop' => $loop );
				break;
			case 'description':
				if ( $loop ) {
					return null;
				}
				$name = 'core/post-content';
				break;
			case 'image':
				$name = 'woocommerce/product-image-gallery';
				break;
			case 'breadcrumb':
				$name = 'woocommerce/breadcrumbs';
				break;
			default:
				return null;
		}
		return self::dynamic_block( $name, self::presentation_attrs( $node['attrs'] ?? array(), $own ) );
	}

	/** Compare rendered text rather than source-platform selectors. */
	private static function text( string $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Compare text in standalone producer consumers too.
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Bind only fields backed by the admitted producer row. */
	public static function bind_document( string $markup, array $product ): string {
		$visit = static function ( array $node ) use ( &$visit, $product ): array {
			$html = $node['innerHTML'] ?? '';
			$text = self::text( $html );
			$role = '';
			$name = self::text( (string) ( $product['name'] ?? '' ) );
			if ( '' !== $name && $text === $name && 'core/heading' === $node['blockName'] ) {
				$role = 'name';
			}
			if ( in_array( $node['blockName'], array( 'core/paragraph', 'core/heading' ), true ) ) {
				foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
					$price = (string) ( $product[ $key ] ?? '' );
					if ( '' !== $price && preg_match( '/^(?:[^\d\s]+\s*)?([\d,.]+)(?:\s*[^\d\s]+)?$/u', $text, $match ) && (float) str_replace( ',', '', $match[1] ) === (float) $price ) {
						$role = 'price';
					}
				}
			}
			$description = self::text( (string) ( $product['description'] ?? '' ) );
			if ( '' !== $description && $text === $description && empty( $node['innerBlocks'] ) ) {
				$role = 'description';
			}
			if ( in_array( $node['blockName'], array( 'core/image', 'core/gallery' ), true ) ) {
				$images   = array_column( $product['images'] ?? array(), 'src' );
				$images[] = $product['image'] ?? '';
				preg_match_all( '/<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', serialize_block( $node ), $sources );
				if ( ! empty( $sources[1] ) && ! array_diff( array_map( static fn( $url ) => html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), $sources[1] ), $images ) ) {
					$role = 'image';
				}
			}
			$categories = $product['categories'] ?? $product['category_names'] ?? array();
			foreach ( (array) $categories as $category ) {
				$category = is_array( $category ) ? (string) ( $category['name'] ?? '' ) : (string) $category;
				if ( '' !== $name && '' !== $category && preg_match( '/(?:>|›|»|\/|→)/u', $text ) && str_contains( $text, $category ) && str_ends_with( $text, $name ) && empty( $node['innerBlocks'] ) ) {
					$role = 'breadcrumb';
				}
			}
			$replacement = self::field( $node, $role );
			if ( null !== $replacement ) {
				return $replacement;
			}
			$node['innerBlocks'] = array_map( $visit, $node['innerBlocks'] ?? array() );
			// Single-product context must inherit the current product, not the capture.
			if ( 'woocommerce/single-product' === $node['blockName'] ) {
				unset( $node['attrs']['productId'] );
			}
			return $node;
		};
		/** @var array<array{blockName: string|null, attrs: array, innerBlocks: array, innerHTML: string, innerContent: array}> $blocks */
		$blocks = array_map( $visit, parse_blocks( $markup ) );
		return serialize_blocks( $blocks );
	}
}
