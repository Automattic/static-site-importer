<?php
/**
 * Front-end runtime for product loops that repeat an imported card.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the imported card of a WooCommerce product loop for each product,
 * and lays those cards out with the source grid CSS.
 *
 * The product grid materializer saves the card as the loop's product template,
 * marking each template slot with its role. This runtime fills each slot for
 * the product being rendered, and flattens WooCommerce's collection, list and
 * item wrappers so every card is a cell of the source grid element exactly as
 * its static card was.
 *
 * The saved pages depend on it, so it travels with the imported site: this
 * file is self-contained and generated companions ship a renamed copy.
 */
final class Static_Site_Importer_Product_Grid_Runtime {
	/** Block metadata key that marks a template slot the render filter fills. */
	public const SLOT_KEY = 'staticSiteImporterItemSlot';

	/** Class on the product collection whose loop wrappers are flattened. */
	public const LOOP_CLASS = 'ssi-item-template-loop';

	/** Register the shared runtime hooks once. */
	public static function register(): void {
		// Every copy (the importer's own, each generated companion's renamed one)
		// shares this constant, so only the first copy to register fills slots.
		if ( defined( 'STATIC_SITE_IMPORTER_PRODUCT_GRID_RUNTIME_REGISTERED' ) ) {
			return;
		}
		define( 'STATIC_SITE_IMPORTER_PRODUCT_GRID_RUNTIME_REGISTERED', true );
		add_filter( 'render_block', array( self::class, 'render_slot' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-collection', array( self::class, 'flatten_collection' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_layout' ), 20 );
	}

	/**
	 * Fill one template slot for the product the loop is rendering.
	 *
	 * @param string              $content Rendered slot.
	 * @param array<string,mixed> $block Parsed block.
	 */
	public static function render_slot( string $content, array $block ): string {
		$slot = $block['attrs']['metadata'][ self::SLOT_KEY ] ?? null;
		if ( ! is_array( $slot ) ) {
			return $content;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		if ( ! $product instanceof WC_Product ) {
			return $content;
		}
		$role = (string) ( $slot['role'] ?? '' );
		if ( 'field' === $role ) {
			$markup = $slot['values'][ (string) $product->get_id() ] ?? null;
			if ( ! is_string( $markup ) ) {
				return empty( $slot['optional'] ) ? self::render_uncaptured( $content, $slot['template'] ?? array(), $product ) : '';
			}
			$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( array $item ): bool => is_string( $item['blockName'] ?? null ) ) );
			return 1 === count( $blocks ) ? render_block( $blocks[0] ) : '';
		}
		if ( 'description' === $role ) {
			$text = trim( wp_strip_all_tags( '' !== $product->get_short_description() ? $product->get_short_description() : $product->get_description() ) );
			return (string) preg_replace( '/^(\s*<[a-zA-Z][^>]*>).*(<\/[a-zA-Z0-9]+>\s*)$/s', '${1}' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), esc_html( $text ) ) . '${2}', $content );
		}
		if ( 'add_to_cart' === $role && $product->is_purchasable() && $product->is_in_stock() && $product->supports( 'ajax_add_to_cart' ) ) {
			$tags = new WP_HTML_Tag_Processor( $content );
			while ( $tags->next_tag() ) {
				if ( ! in_array( $tags->get_tag(), array( 'BUTTON', 'A' ), true ) ) {
					continue;
				}
				// Source data-* hooks drive the source script's own cart.
				foreach ( (array) $tags->get_attribute_names_with_prefix( 'data-' ) as $name ) {
					$tags->remove_attribute( $name );
				}
				$tags->add_class( 'add_to_cart_button' );
				$tags->add_class( 'ajax_add_to_cart' );
				$tags->set_attribute( 'data-product_id', (string) $product->get_id() );
				$tags->set_attribute( 'data-quantity', '1' );
				if ( 'A' === $tags->get_tag() ) {
					$tags->set_attribute( 'href', $product->add_to_cart_url() );
				}
				return $tags->get_updated_html();
			}
		}
		return $content;
	}

	/**
	 * Capture source values, rather than relying on mutable catalog data later.
	 *
	 * @param int    $product_id Template product id.
	 * @param string $markup Source slot markup.
	 * @param string $role Slot role.
	 * @return array<string,mixed> Portable source values for the companion runtime.
	 */
	public static function template_values( int $product_id, string $markup, string $role ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$source  = array(
			'product_id' => $product_id,
			'name'       => $product instanceof WC_Product ? $product->get_name() : '',
			'permalink'  => '',
			'images'     => array(),
		);
		$tags    = new WP_HTML_Tag_Processor( $markup );
		while ( $tags->next_tag() ) {
			if ( 'A' === $tags->get_tag() && '' === $source['permalink'] ) {
				$source['permalink'] = (string) $tags->get_attribute( 'href' );
			}
			if ( 'IMG' === $tags->get_tag() ) {
				$image = array();
				foreach ( array( 'src', 'srcset', 'sizes', 'alt', 'width', 'height' ) as $attribute ) {
					$image[ $attribute ] = (string) $tags->get_attribute( $attribute );
				}
				$source['images'][] = $image;
			}
		}
		if ( 'name' === $role ) {
			$source['name'] = trim( html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return $source;
	}

	/**
	 * Fill an uncaptured required slot without replacing bytes inside HTML syntax.
	 *
	 * @param string              $content Rendered template slot.
	 * @param array<string,mixed> $source Recorded template values.
	 * @param WC_Product          $product Current product.
	 */
	private static function render_uncaptured( string $content, array $source, WC_Product $product ): string {
		if ( empty( $source ) ) {
			// Legacy markers have no source identity to map safely.
			return $content;
		}
		$ids   = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
		$index = 0;
		$tags  = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_token() ) {
			if ( '#text' === $tags->get_token_type() && ! in_array( $tags->get_tag(), array( 'SCRIPT', 'STYLE' ), true ) && ! empty( $source['name'] ) ) {
				$text = $tags->get_modifiable_text();
				$tags->set_modifiable_text( str_replace( $source['name'], $product->get_name(), $text ) );
				continue;
			}
			foreach ( array( 'href', 'title', 'aria-label' ) as $attribute ) {
				$value = $tags->get_attribute( $attribute );
				if ( ! is_string( $value ) ) {
					continue;
				}
				$replacements = array();
				if ( ! empty( $source['permalink'] ) ) {
					$replacements[ $source['permalink'] ] = $product->get_permalink();
				}
				if ( ! empty( $source['name'] ) ) {
					$replacements[ $source['name'] ] = $product->get_name();
				}
				$tags->set_attribute( $attribute, strtr( $value, $replacements ) );
			}
			if ( 'IMG' !== $tags->get_tag() ) {
				continue;
			}
			$image_index = $index;
			foreach ( $source['images'] ?? array() as $source_index => $source_image ) {
				if ( $tags->get_attribute( 'src' ) === $source_image['src'] ) {
					$image_index = $source_index;
					break;
				}
			}
			$image_id = $ids[ $image_index ] ?? 0;
			$original = $source['images'][ $image_index ] ?? array();
			++$index;
			// Ask for the source dimensions when available; Core selects the matching size.
			$size   = ! empty( $original['width'] ) && ! empty( $original['height'] ) ? array( (int) $original['width'], (int) $original['height'] ) : 'full';
			$image  = $image_id ? wp_get_attachment_image_src( $image_id, $size ) : false;
			$srcset = $image ? wp_get_attachment_image_srcset( $image_id, $size ) : false;
			$sizes  = $srcset ? wp_get_attachment_image_sizes( $image_id, $size ) : false;
			$tags->set_attribute( 'src', $image ? $image[0] : wc_placeholder_img_src() );
			$tags->set_attribute( 'alt', $image ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : $product->get_name() );
			foreach ( array(
				'srcset' => $srcset,
				'sizes'  => $sizes,
			) as $attribute => $value ) {
				if ( $value ) {
					$tags->set_attribute( $attribute, $value );
				} else {
					$tags->remove_attribute( $attribute );
				}
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Unwrap only imported loops, before an HTML tree builder can repair nested
	 * li cards. Tag Processor deliberately tokenizes the literal WooCommerce
	 * ProductTemplate.php output, rather than applying HTML's implicit li ends.
	 * Archive templates can be arbitrarily deep inside the source listing.
	 *
	 * @param string              $content Rendered collection.
	 * @param array<string,mixed> $block Parsed collection block.
	 */
	public static function flatten_collection( string $content, array $block ): string {
		$classes = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ) );
		if ( ! in_array( self::LOOP_CLASS, $classes ? $classes : array(), true ) ) {
			return $content;
		}

		// Keep this adapter inside the runtime: companions rename only this file's
		// runtime class. Offsets refer to the original bytes, not a repaired DOM.
		$tags        = new class( $content ) extends WP_HTML_Tag_Processor {
			/**
			 * Current tag's original byte span, via the supported bookmark API.
			 *
			 * @return array{int,int} Byte offset and length.
			 */
			public function span(): array {
				$this->set_bookmark( 'ssi-current' );
				$span = $this->bookmarks['ssi-current'];
				return array( $span->start, $span->length );
			}
		};
		$edits       = array();
		$list_depth  = 0;
		$item_depth  = 0;
		$item        = null;
		$needs_card  = false;
		$direct_list = false;
		$first       = true;
		$outer_end   = strrpos( $content, '</div>' );
		while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag   = $tags->get_tag();
			$close = $tags->is_tag_closer();
			$span  = $tags->span();
			if ( $first ) {
				$first = false;
				// Hand-picked collections sit *inside* the source grid. Archives
				// instead wrap the listing, and must keep that outer wrapper.
				$direct_list = empty( $block['attrs']['query']['inherit'] ) && 'DIV' === $tag && false !== $outer_end;
				if ( $direct_list ) {
					$edits[] = array( $span[0], $span[1], '' );
					$edits[] = array( $outer_end, 6, '' );
					continue;
				}
			}
			if ( 0 === $list_depth ) {
				if ( ! $close && 'UL' === $tag && $tags->has_class( 'wc-block-product-template' ) ) {
					$list_depth = 1;
					$edits[]    = array( $span[0], $span[1], '' );
				}
				continue;
			}
			if ( 'UL' === $tag ) {
				$list_depth += $close ? -1 : 1;
				if ( 0 === $list_depth ) {
					$edits[] = array( $span[0], $span[1], '' );
					continue;
				}
			}
			if ( 0 === $item_depth && ! $close && 'LI' === $tag && $tags->has_class( 'wc-block-product' ) ) {
				$item = new WP_HTML_Tag_Processor( substr( $content, $span[0], $span[1] ) );
				$item->next_tag();
				$item_depth = 1;
				$needs_card = true;
				$edits[]    = array( $span[0], $span[1], '' );
				continue;
			}
			if ( $needs_card && ! $close && null !== $item ) {
				$card = new WP_HTML_Tag_Processor( substr( $content, $span[0], $span[1] ) );
				$card->next_tag();
				$product_classes = preg_split( '/\s+/', (string) $item->get_attribute( 'class' ) );
				foreach ( false === $product_classes ? array() : $product_classes as $class ) {
					// Retain product/post classes, not the retired layout wrapper class.
					if ( 'wc-block-product' !== $class && '' !== $class ) {
						$card->add_class( $class );
					}
				}
				foreach ( $item->get_attribute_names_with_prefix( 'data-wp-' ) ?? array() as $name ) {
					$card->set_attribute( $name, $item->get_attribute( $name ) );
				}
				$edits[]    = array( $span[0], $span[1], $card->get_updated_html() );
				$needs_card = false;
			}
			if ( $item_depth > 0 && 'LI' === $tag ) {
				$item_depth += $close ? -1 : 1;
				if ( 0 === $item_depth ) {
					$edits[] = array( $span[0], $span[1], '' );
				}
			}
		}
		// Apply backwards so modifying a card cannot invalidate later offsets.
		usort( $edits, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );
		foreach ( $edits as $change ) {
			$content = substr_replace( $content, $change[2], $change[0], $change[1] );
		}
		return $content;
	}

	/**
	 * Attach the layout CSS after WooCommerce's block styles, so the flattening
	 * follows them in the cascade.
	 */
	public static function enqueue_layout(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_admin() ) {
			return;
		}
		$handle = 'static-site-importer-product-grid';
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			$after = array_values( array_filter( array( 'wc-blocks-style', 'woocommerce-general' ), static fn( string $style ): bool => wp_style_is( $style, 'registered' ) ) );
			wp_register_style( $handle, false, $after, '1' );
		}
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, self::layout_css() );
	}

	/**
	 * WooCommerce wraps a product loop in its own collection, list and item
	 * elements. A loop that repeats an imported card sits inside the source grid
	 * element (or, for a store listing, wraps the listing whose grid element
	 * holds the list), so those wrappers are flattened.
	 */
	public static function layout_css(): string {
		// Keep the collection and notices boxless where they wrap the listing.
		$loop = '.' . self::LOOP_CLASS . '.' . self::LOOP_CLASS;
		// The card list may sit deeper than the loop: a store listing's loop
		// wraps the whole listing and the list sits in its grid element.
		return $loop . ',' . $loop . ' > .wc-block-components-notices{display:contents;}'
			// A store notice spans the whole grid row instead of taking a card's cell.
			. $loop . ' > .wc-block-components-notices > *{grid-column:1/-1;}';
	}
}
