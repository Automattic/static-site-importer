<?php
/**
 * Shared product field bindings for cards and single-product documents.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-static-site-importer-product-presentation.php';

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
		$attrs = self::presentation_attrs( $node['attrs'] ?? array(), $own );
		if ( 'image' === $role ) {
			$capture = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( $node ) ) );
			if ( $capture ) {
				$attrs['className'] = trim( ( $attrs['className'] ?? '' ) . ' ' . $capture['className'] );
			}
		}
		return self::dynamic_block( $name, $attrs );
	}

	/** Compare rendered text rather than source-platform selectors. */
	private static function text( string $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Compare text in standalone producer consumers too.
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Exact resource aliases from the producer plan and its attachment projection. */
	public static function image_identities( array $state ): array {
		$aliases = array();
		foreach ( $state['plan']['assets'] ?? array() as $asset ) {
			$source     = (string) $asset['source_path'];
			$identity   = '/' . ltrim( $source, '/' );
			$record     = $state['args']['image_asset_records'][ $source ] ?? array();
			$urls       = array_merge( $record['source_urls'] ?? array(), array( $source, $identity, '{{wordpress-site-plan:asset:' . $asset['token'] . '}}', rtrim( (string) ( $state['theme']['uri'] ?? '' ), '/' ) . '/' . $asset['target_path'] ) );
			$attachment = $state['resolved']['resolution']['attachment_resolutions'][ $asset['token'] ] ?? array();
			if ( ! empty( $attachment['url'] ) ) {
				$urls[] = $attachment['url'];
			}
			foreach ( $urls as $url ) {
				$aliases[ html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ] = $identity;
			}
		}
		return $aliases;
	}

	/** Bind only fields backed by the admitted producer row. */
	public static function bind_document( string $markup, array $product, ?array &$presentations = null, array $image_identities = array() ): string {
		$identity        = static function ( string $url ) use ( $image_identities ): string {
			$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			if ( isset( $image_identities[ $url ] ) ) {
				return $image_identities[ $url ];
			}
			$id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
			if ( $id && function_exists( 'get_post_meta' ) ) {
				$source = (string) get_post_meta( $id, '_static_site_importer_source_image', true );
				if ( '' !== $source ) {
					return $image_identities[ $source ] ?? $source;
				}
			}
			return $url;
		};
		$blocks          = parse_blocks( $markup );
		$images          = array_column( $product['images'] ?? array(), 'src' );
		$images[]        = $product['image'] ?? '';
		$images          = array_unique( array_filter( array_map( $identity, $images ) ) );
		$gallery_path    = null;
		$gallery_size    = PHP_INT_MAX;
		$has_gallery     = false;
		$gallery_capture = false;
		// Select before rewriting: a slide containing only one product image is
		// not a gallery. Prefer the smallest complete set, including custom groups.
		$select = static function ( array $nodes, string $parent_path = '' ) use ( &$select, $images, $identity, &$gallery_path, &$gallery_size, &$has_gallery, &$gallery_capture ): void {
			foreach ( $nodes as $index => $node ) {
				$path        = $parent_path . '/' . $index;
				$has_gallery = $has_gallery || 'woocommerce/product-image-gallery' === $node['blockName'];
				$select( $node['innerBlocks'] ?? array(), $path );
				$html = serialize_block( $node );
				preg_match_all( '/<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', $html, $sources );
				$sources  = array_unique( array_map( $identity, $sources[1] ) );
				$captured = ! empty( Static_Site_Importer_Product_Presentation::gallery_attrs( $node )['thumbnail'] );
				if ( null !== $node['blockName'] && $images && ! array_diff( $images, $sources ) && ! array_diff( $sources, $images ) && ( ( $captured && ! $gallery_capture ) || ( $captured === $gallery_capture && strlen( $html ) < $gallery_size ) ) ) {
					$gallery_path    = $path;
					$gallery_size    = strlen( $html );
					$gallery_capture = $captured;
				}
			}
		};
		$select( $blocks );
		$visit = static function ( array $node, string $path ) use ( &$visit, $product, $gallery_path, $has_gallery, &$presentations ): array {
			if ( ! $has_gallery && $path === $gallery_path ) {
				$capture = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( $node ) ) );
				if ( $capture ) {
					$presentations[] = $capture;
				}
				return self::field( $node, 'image' );
			}
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
			foreach ( $node['innerBlocks'] ?? array() as $index => $child ) {
				$node['innerBlocks'][ $index ] = $visit( $child, $path . '/' . $index );
			}
			// Single-product context must inherit the current product, not the capture.
			if ( 'woocommerce/single-product' === $node['blockName'] ) {
				unset( $node['attrs']['productId'] );
			}
			return $node;
		};
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] = $visit( $block, '/' . $index );
		}
		/** @var array<array{blockName: string|null, attrs: array, innerBlocks: array, innerHTML: string, innerContent: array}> $blocks */
		return serialize_blocks( $blocks );
	}
}
