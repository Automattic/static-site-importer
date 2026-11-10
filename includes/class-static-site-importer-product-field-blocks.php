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
				$name = $loop ? 'woocommerce/product-image' : 'woocommerce/product-image-gallery';
				$own  = $loop ? self::loop_image_attrs( $node ) : array();
				break;
			case 'breadcrumb':
				$name = 'woocommerce/breadcrumbs';
				break;
			default:
				return null;
		}
		$attrs = self::presentation_attrs( $node['attrs'] ?? array(), $own );
		// Of the bound Woo fields, only breadcrumbs declares a block.json fontSize default.
		if ( 'woocommerce/breadcrumbs' === $name && ! isset( $attrs['fontSize'] ) ) {
			$attrs['fontSize'] = '';
		}
		if ( 'image' === $role && ! $loop ) {
			if ( str_ends_with( (string) ( $node['blockName'] ?? '' ), '/authored-carousel' ) ) {
				$source  = $node['attrs'] ?? array();
				$classes = preg_split( '/\s+/', trim( ( $attrs['className'] ?? '' ) . ' ' . ( $source['sourcePresentationClasses'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
				if ( is_array( $classes ) ) {
					$attrs['className'] = implode( ' ', array_unique( $classes ) );
				}
				foreach ( $source['sourceIdentityAttributes'] ?? array() as $key => $value ) {
					if ( preg_match( '/^data-(?!wp-)[a-z0-9_-]+$/D', $key ) && is_string( $value ) ) {
						$attrs['metadata']['staticSiteImporterGalleryIdentity'][ $key ] = $value;
					}
				}
			}
			$capture = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( $node ) ) );
			if ( $capture ) {
				$attrs['className'] = trim( ( $attrs['className'] ?? '' ) . ' ' . $capture['className'] );
			}
		}
		return self::dynamic_block( $name, $attrs );
	}

	/** Keep the source card crop on WooCommerce's linked loop image. */
	private static function loop_image_attrs( array $node ): array {
		$source = $node['attrs'] ?? array();
		$attrs  = array_merge(
			array(
				'isDescendentOfQueryLoop' => true,
				'isLink'                  => true,
			),
			array_intersect_key( $source, array_flip( array( 'aspectRatio', 'scale' ) ) )
		);
		// Native image attributes normally carry the compiled crop. Also admit
		// inline evidence from the image or linked wrapper before it is removed.
		$tags = new WP_HTML_Tag_Processor( $node['innerHTML'] ?? '' );
		while ( $tags->next_tag() ) {
			$style = (string) $tags->get_attribute( 'style' );
			if ( ! isset( $source['aspectRatio'] ) && preg_match( '/(?:^|;)\s*aspect-ratio\s*:\s*(\d+(?:\.\d+)?\s*\/\s*\d+(?:\.\d+)?)\s*(?:;|$)/i', $style, $match ) ) {
				$attrs['aspectRatio'] = $match[1];
			}
			if ( ! isset( $source['scale'] ) && preg_match( '/(?:^|;)\s*object-fit\s*:\s*(cover|contain)\s*(?:;|$)/i', $style, $match ) ) {
				$attrs['scale'] = strtolower( $match[1] );
			}
		}
		return $attrs;
	}

	/** Capture wording before the source purchase subtree is replaced. */
	public static function variation_wording( string $markup ): array {
		if ( ! str_contains( $markup, '/authored-select' ) ) {
			return array();
		}
		$wording = array();
		$walk    = static function ( array $nodes, string $previous = '' ) use ( &$walk, &$wording ): void {
			foreach ( $nodes as $node ) {
				$attrs = $node['attrs'] ?? array();
				if ( str_ends_with( (string) $node['blockName'], '/authored-select' ) && preg_match( '/^(?:variant-option-(.+)-select|attribute_(.+))$/D', $attrs['name'] ?? '', $match ) ) {
					$key = sanitize_title( '' !== $match[1] ? $match[1] : $match[2] );
					$row = array();
					if ( '' !== $previous ) {
						$row['label'] = $previous;
					}
					foreach ( $attrs['options'] ?? array() as $option ) {
						if ( '' === ( $option['value'] ?? null ) && ! empty( $option['valueDeclared'] ) ) {
							$row['placeholder'] = self::text( (string) ( $option['label'] ?? '' ) );
							break;
						}
					}
					if ( $row ) {
						$wording[ $key ] = $row;
					}
				}
				// A text-free wrapper does not interrupt adjacency to its first child.
				// Controls and other non-text content do: their captions are not labels.
				$leading = self::label_text( (string) ( $node['innerContent'][0] ?? '' ) );
				$walk( $node['innerBlocks'] ?? array(), '' === $leading ? $previous : ( $leading ?? '' ) );
				$previous = empty( $node['innerBlocks'] ) ? ( self::label_text( $node['innerHTML'] ?? '' ) ?? '' ) : '';
			}
		};
		$walk( parse_blocks( $markup ) );
		return $wording;
	}

	/** Only textual markup can supply a label; controls/media break adjacency. */
	private static function label_text( string $html ): ?string {
		if ( preg_match( '/<(?!\/?(?:p|div|section|article|main|aside|header|footer|fieldset|legend|span|label|h[1-6]|strong|em|b|i|u|s|small|sup|sub|br)\b)[^>]*>/i', $html ) ) {
			return null;
		}
		return self::text( $html );
	}

	/** Compare rendered text rather than source-platform selectors. */
	private static function text( string $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Compare text in standalone producer consumers too.
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Exact resource aliases from the producer plan and its attachment projection. */
	public static function image_identities( array $state ): array {
		$aliases     = array();
		$attachments = array();
		/** @var array<string,string> $parents */
		$parents   = array();
		$canonical = static function ( string $identity ) use ( &$parents ): string {
			while ( isset( $parents[ $identity ] ) ) {
				$identity = $parents[ $identity ];
			}
			return $identity;
		};
		$root      = '';
		foreach ( $state['plan']['pages'] ?? array() as $page ) {
			if ( ! empty( $page['entrypoint'] ) ) {
				$directory = dirname( $page['source_path'] );
				$root      = '.' === $directory ? '' : trim( $directory, '/' ) . '/';
				break;
			}
		}
		foreach ( $state['plan']['assets'] ?? array() as $asset ) {
			$source   = (string) $asset['source_path'];
			$identity = '/' . ltrim( $source, '/' );
			$record   = $state['args']['image_asset_records'][ $source ] ?? array();
			$urls     = array_merge( $record['source_urls'] ?? array(), array( $source, $identity, '{{wordpress-site-plan:asset:' . $asset['token'] . '}}', rtrim( (string) ( $state['theme']['uri'] ?? '' ), '/' ) . '/' . $asset['target_path'] ) );
			// Browser-root paths are rooted at the declared entry document, not
			// the artifact wrapper. Never infer identity from a basename or suffix.
			if ( '' !== $root && str_starts_with( $source, $root ) ) {
				$urls[] = '/' . substr( $source, strlen( $root ) );
			}
			$attachment = $state['resolved']['resolution']['attachment_resolutions'][ $asset['token'] ] ?? array();
			$id         = (int) ( $attachment['id'] ?? 0 );
			if ( $id ) {
				if ( isset( $attachments[ $id ] ) ) {
					$parents[ $identity ] = $canonical( $attachments[ $id ] );
				}
				$attachments[ $id ] = $identity;
			}
			if ( ! empty( $attachment['url'] ) ) {
				$urls[] = $attachment['url'];
			}
			foreach ( $urls as $url ) {
				$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
				// Exact aliases (notably shared attachment URLs) are equivalence
				// edges. Last-write-wins leaves earlier tokens and paths stranded.
				if ( isset( $aliases[ $url ] ) ) {
					$left  = $canonical( $identity );
					$right = $canonical( $aliases[ $url ] );
					if ( $left !== $right ) {
						$parents[ $left ] = $right;
					}
				}
				$aliases[ $url ] = $identity;
			}
		}
		// References retain the full candidate list and the source element's
		// selector. Only co-membership in an img srcset proves that distinct
		// captured resources are renditions of one photo.
		$elements = array();
		foreach ( $state['plan']['assets'] ?? array() as $asset ) {
			foreach ( $asset['references'] ?? array() as $reference ) {
				if ( 'img' !== ( $reference['element'] ?? '' ) || ! in_array( $reference['attribute'] ?? '', array( 'src', 'srcset' ), true ) ) {
					continue;
				}
				$key = ( $reference['source_path'] ?? '' ) . "\n" . ( $reference['selector'] ?? '' );
				// Without an element locator, candidate lists still prove their
				// own membership, but cannot be paired with another img's src.
				if ( empty( $reference['selector'] ) ) {
					$key .= "\n" . ( $reference['value'] ?? '' );
				}
				$elements[ $key ][ $reference['attribute'] ][] = $reference['value'] ?? '';
			}
		}
		foreach ( $elements as $attributes ) {
			if ( empty( $attributes['srcset'] ) ) {
				continue;
			}
			$urls = $attributes['src'] ?? array();
			foreach ( array_unique( $attributes['srcset'] ) as $srcset ) {
				foreach ( explode( ',', html_entity_decode( $srcset, ENT_QUOTES, 'UTF-8' ) ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					if ( false !== $parts ) {
						$urls[] = $parts[0];
					}
				}
			}
			$identity = null;
			foreach ( $urls as $url ) {
				$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
				if ( ! isset( $aliases[ $url ] ) ) {
					continue;
				}
				$right = $canonical( $aliases[ $url ] );
				if ( null !== $identity && $identity !== $right ) {
					$parents[ $right ] = $identity;
				}
				$identity = $identity ?? $right;
			}
		}
		return array_map( $canonical, $aliases );
	}

	/** Known resources without display evidence are not expected gallery slides. */
	public static function undisplayed_image_identities( array $state, array $aliases ): array {
		$known     = array();
		$displayed = array();
		foreach ( $state['plan']['assets'] ?? array() as $asset ) {
			$identity = $aliases[ $asset['source_path'] ] ?? null;
			if ( null === $identity ) {
				continue;
			}
			$known[] = $identity;
			foreach ( $asset['references'] ?? array() as $reference ) {
				if ( in_array( $reference['element'] ?? '', array( 'img', 'picture', 'source' ), true ) ) {
					$displayed[] = $identity;
					break;
				}
			}
		}
		// Evaluate after alias/rendition joining: any displayed member makes
		// the whole photo expected, even if another member is only a link.
		return array_values( array_diff( array_unique( $known ), $displayed ) );
	}

	/** Keep wording separate from the currency and live numeric value. */
	private static function source_field_presentation( array $node, string $role, string $text ): array {
		if ( 'price' === $role && preg_match( '/^([^\d]*?)[\d]+(?:[,.][\d]+)*([^\d]*)$/u', $text, $parts ) ) {
			$prefix = preg_replace( '/\p{Sc}\s*$/u', '', $parts[1] );
			$suffix = preg_replace( '/^\s*\p{Sc}/u', '', $parts[2] );
			return '' !== trim( $prefix . $suffix ) ? array(
				'prefix' => $prefix,
				'suffix' => $suffix,
			) : array();
		}
		if ( 'breadcrumb' !== $role || ! preg_match( '/[>›»\/→]/u', $text, $separator ) ) {
			return array();
		}
		$presentation = array(
			'separator' => $separator[0],
			'home'      => (bool) preg_match( '/^Home\s*[>›»\/→]/iu', $text ),
		);
		$font_size    = self::source_font_size( $node );
		if ( '' !== $font_size ) {
			$presentation['fontSize'] = $font_size;
		}
		return $presentation;
	}

	/** Prefer the container typography, then a captured link's presentation. */
	private static function source_font_size( array $node ): string {
		$size = $node['attrs']['style']['typography']['fontSize'] ?? '';
		if ( is_string( $size ) && '' !== $size ) {
			return $size;
		}
		if ( preg_match_all( '/\bstyle=["\x27]([^"\x27]*)["\x27]/i', $node['innerHTML'] ?? '', $styles ) ) {
			foreach ( $styles[1] as $style ) {
				if ( preg_match( '/(?:^|;)\s*font-size\s*:\s*([^;]+)/i', $style, $match ) ) {
					return trim( $match[1] );
				}
			}
		}
		foreach ( $node['innerBlocks'] ?? array() as $child ) {
			$size = self::source_font_size( $child );
			if ( '' !== $size ) {
				return $size;
			}
		}
		return '';
	}

	/** Bind only fields backed by the admitted producer row. */
	public static function bind_document( string $markup, array $product, ?array &$presentations = null, array $image_identities = array(), array $undisplayed_images = array() ): string {
		$identity = static function ( string $url ) use ( $image_identities ): string {
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
				// Page-media attachments carry capture aliases rather than the
				// product seeder's source-image meta (including duplicate uploads).
				$record = get_post_meta( $id, '_static_site_importer_asset_record', true );
				foreach ( is_array( $record ) ? ( $record['source_urls'] ?? array() ) : array() as $alias ) {
					if ( isset( $image_identities[ $alias ] ) ) {
						return $image_identities[ $alias ];
					}
				}
			}
			return $url;
		};
		$blocks   = parse_blocks( $markup );
		$images   = array_column( $product['images'] ?? array(), 'src' );
		$images[] = $product['image'] ?? '';
		// Rendered markup is also positive display evidence (older plans may
		// omit references). Check the whole document, not just the carousel.
		preg_match_all( '/<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', $markup, $displayed_sources );
		$undisplayed_images = array_diff( $undisplayed_images, array_map( $identity, $displayed_sources[1] ) );
		$images             = array_diff( array_unique( array_filter( array_map( $identity, $images ) ) ), $undisplayed_images );
		$gallery_path       = null;
		$gallery_size       = PHP_INT_MAX;
		$has_gallery        = false;
		$gallery_capture    = false;
		// Select before rewriting: a slide containing only one product image is
		// not a gallery. Prefer the smallest complete set, including custom groups.
		$select = static function ( array $nodes, string $parent_path = '' ) use ( &$select, $images, $identity, &$gallery_path, &$gallery_size, &$has_gallery, &$gallery_capture ): void {
			foreach ( $nodes as $index => $node ) {
				$path        = $parent_path . '/' . $index;
				$has_gallery = $has_gallery || 'woocommerce/product-image-gallery' === $node['blockName'];
				// Overlays are duplicates, never the primary gallery candidate.
				if ( preg_match( '/lightbox/i', (string) ( $node['attrs']['className'] ?? '' ) ) ) {
					continue;
				}
				$select( $node['innerBlocks'] ?? array(), $path );
				$html = serialize_block( $node );
				preg_match_all( '/<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', $html, $sources );
				$sources  = array_unique( array_map( $identity, $sources[1] ) );
				$captured = ! empty( $node['attrs']['thumbnails'] ) || ! empty( Static_Site_Importer_Product_Presentation::gallery_attrs( $node )['thumbnail'] );
				if ( null !== $node['blockName'] && $images && ! array_diff( $images, $sources ) && ! array_diff( $sources, $images ) && ( ( $captured && ! $gallery_capture ) || ( $captured === $gallery_capture && strlen( $html ) < $gallery_size ) ) ) {
					$gallery_path    = $path;
					$gallery_size    = strlen( $html );
					$gallery_capture = $captured;
				}
			}
		};
		$select( $blocks );
		$visit = static function ( array $node, string $path ) use ( &$visit, $product, $gallery_path, $has_gallery, &$presentations, $images, $identity ): ?array {
			if ( ( $has_gallery || null !== $gallery_path ) && preg_match( '/lightbox/i', (string) ( $node['attrs']['className'] ?? '' ) ) ) {
				preg_match_all( '/<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', serialize_block( $node ), $sources );
				if ( $sources[1] && ! array_diff( array_map( $identity, $sources[1] ), $images ) ) {
					return null;
				}
			}
			if ( ! $has_gallery && $path === $gallery_path ) {
				$capture = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( $node ) ) );
				if ( $capture ) {
					$presentations[] = $capture;
				}
				return self::field( $node, 'image' );
			}
			$html = $node['innerHTML'] ?? '';
			$text = self::text( empty( $node['innerBlocks'] ) ? $html : serialize_block( $node ) );
			$role = '';
			$name = self::text( (string) ( $product['name'] ?? '' ) );
			if ( '' !== $name && $text === $name && 'core/heading' === $node['blockName'] ) {
				$role = 'name';
			}
			if ( in_array( $node['blockName'], array( 'core/paragraph', 'core/heading' ), true ) ) {
				foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
					$price = (string) ( $product[ $key ] ?? '' );
					if ( '' !== $price && preg_match( '/^[^\d]*?([\d]+(?:[,.][\d]+)*)[^\d]*$/u', $text, $match ) && (float) str_replace( ',', '', $match[1] ) === (float) $price ) {
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
				if ( '' !== $name && '' !== $category && preg_match( '/(?:>|›|»|\/|→)/u', $text ) && str_contains( $text, $category ) && str_ends_with( $text, $name ) && ( empty( $node['innerBlocks'] ) || preg_match( '/^(?:Home\s*(?:>|›|»|\/|→)\s*)?' . preg_quote( $category, '/' ) . '\s*(?:>|›|»|\/|→)\s*' . preg_quote( $name, '/' ) . '$/u', $text ) ) ) {
					$role = 'breadcrumb';
				}
			}
			$replacement = self::field( $node, $role );
			if ( null !== $replacement ) {
				$presentation = self::source_field_presentation( $node, $role, $text );
				if ( $presentation ) {
					$replacement['attrs']['metadata']['staticSiteImporterFieldPresentation'] = $presentation;
				}
				return $replacement;
			}
			foreach ( $node['innerBlocks'] ?? array() as $index => $child ) {
				$node['innerBlocks'][ $index ] = $visit( $child, $path . '/' . $index );
			}
			$index = 0;
			foreach ( $node['innerContent'] ?? array() as $slot => $content ) {
				if ( null === $content && null === $node['innerBlocks'][ $index++ ] ) {
					$node['innerContent'][ $slot ] = '';
				}
			}
			$node['innerBlocks'] = array_values( array_filter( $node['innerBlocks'] ?? array() ) );
			// Single-product context must inherit the current product, not the capture.
			if ( 'woocommerce/single-product' === $node['blockName'] ) {
				unset( $node['attrs']['productId'] );
			}
			return $node;
		};
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] = $visit( $block, '/' . $index );
		}
		/** @var array<array{blockName: string|null, attrs: array, innerBlocks: array, innerHTML: string, innerContent: array}|null> $blocks */
		return serialize_blocks( array_values( array_filter( $blocks ) ) );
	}
}
