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

	/** Block metadata key on the archive template naming its source page's reconciliation identity. */
	public const ARCHIVE_DOCUMENT_KEY = 'staticSiteImporterArchiveDocument';

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
		add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( self::class, 'variation_placeholder' ) );
		add_filter( 'woocommerce_attribute_label', array( self::class, 'variation_label' ), 10, 3 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( self::class, 'submit_text' ), 10, 2 );
		add_action( 'woocommerce_before_add_to_cart_quantity', array( self::class, 'quantity_caption' ) );
		add_action( 'woocommerce_before_variations_form', array( self::class, 'open_variation_form' ) );
		add_action( 'woocommerce_after_variations_form', array( self::class, 'close_variation_form' ) );
		add_filter( 'blocks_engine_document_id', array( self::class, 'archive_document_id' ), 20 );
		add_filter( 'blocks_engine_served_route', array( self::class, 'served_route' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'render_slot' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-price', array( self::class, 'render_field_presentation' ), 20, 2 );
		add_filter( 'render_block_woocommerce/breadcrumbs', array( self::class, 'render_field_presentation' ), 20, 2 );
		add_filter( 'render_block_woocommerce/product-image-gallery', array( self::class, 'gallery_identity' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-collection', array( self::class, 'flatten_collection' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_layout' ), 20 );
	}

	/** Supply source identity only for imported products without an existing route. */
	public static function served_route( mixed $route, mixed $document ): mixed {
		if ( null !== $route || ! is_int( $document ) || $document <= 0 || 'product' !== get_post_type( $document ) ) {
			return $route;
		}
		$source = get_post_meta( $document, '_static_site_importer_source_route', true );
		if ( ! is_string( $source ) || '' === trim( $source, '/' ) || preg_match( '/%(?![a-f0-9]{2})/i', $source ) ) {
			return $route;
		}
		// Check decoded syntax too: escaped separators or traversal must not bypass validation.
		$path = rawurldecode( $source );
		if ( preg_match( '/[\x00-\x20\x7f\\\\:?#%]|\/\/|\.\./', $path ) ) {
			return $route;
		}
		return '/' . trim( $source, '/' );
	}

	/** Read only validated plain text, including in generated companion runtimes. */
	private static function variation_wording( mixed $product, string $attribute ): array {
		$key = sanitize_title( $attribute );
		// Woo prefixes global attribute taxonomies; source controls name the attribute.
		return self::stored_wording( $product, '_static_site_importer_variation_wording', array( $key, str_starts_with( $key, 'pa_' ) ? substr( $key, 3 ) : $key ) );
	}

	/** The source's wording for a purchase control (`quantity` or `submit`). */
	private static function purchase_wording( mixed $product, string $role ): array {
		return self::stored_wording( $product, '_static_site_importer_purchase_wording', array( $role ) );
	}

	/** Woo keeps rendering and escaping the native button; only its wording is the source's. */
	public static function submit_text( mixed $text, mixed $product = null ): mixed {
		return self::purchase_wording( $product, 'submit' )['label'] ?? $text;
	}

	/**
	 * Restore the source's visible quantity caption above the native quantity
	 * control. Woo already names the input for assistive technology, so the
	 * caption is presentational and hidden from it.
	 */
	public static function quantity_caption(): void {
		global $product;
		$label = self::purchase_wording( $product, 'quantity' )['label'] ?? '';
		if ( '' !== $label ) {
			echo '<span class="ssi-purchase-quantity-label" aria-hidden="true">' . esc_html( $label ) . '</span>';
		}
	}

	/**
	 * The first stored row among `$keys`, accepted only as canonical plain text.
	 *
	 * @param array<int,string> $keys
	 */
	private static function stored_wording( mixed $product, string $meta_key, array $keys ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}
		$wording = get_post_meta( $product->get_id(), $meta_key, true );
		$row     = array();
		foreach ( $keys as $key ) {
			if ( is_array( $wording ) && isset( $wording[ $key ] ) ) {
				$row = $wording[ $key ];
				break;
			}
		}
		if ( ! is_array( $row ) ) {
			return array();
		}
		foreach ( $row as $key => $text ) {
			if ( ! in_array( $key, array( 'label', 'placeholder' ), true ) || ! is_string( $text ) || '' === $text || trim( $text ) !== $text || preg_match( '/[<>\x00-\x1f\x7f]|\s{2,}/u', $text ) || html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $text ) {
				return array();
			}
		}
		return $row;
	}

	/** Woo owns option escaping and selection behavior. */
	public static function variation_placeholder( array $args ): array {
		$row = self::variation_wording( $args['product'] ?? null, (string) ( $args['attribute'] ?? '' ) );
		if ( isset( $row['placeholder'] ) ) {
			$args['show_option_none'] = $row['placeholder'];
		}
		return $args;
	}

	/** True only while Woo renders the native variations form. */
	private static bool $in_variation_form = false;

	public static function open_variation_form(): void {
		self::$in_variation_form = true;
	}

	public static function close_variation_form(): void {
		self::$in_variation_form = false;
	}

	/** Only the product's own native variations form may rename labels. */
	public static function variation_label( string $label, string $attribute, mixed $label_product ): string {
		global $product;
		if ( ! self::$in_variation_form || ( $label_product && ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || $label_product->get_id() !== $product->get_id() ) ) ) {
			return $label;
		}
		return self::variation_wording( $product, $attribute )['label'] ?? $label;
	}

	/** Apply captured wording only to explicitly bound imported field blocks. */
	public static function render_field_presentation( string $content, array $block ): string {
		$source = $block['attrs']['metadata']['staticSiteImporterFieldPresentation'] ?? null;
		if ( ! is_array( $source ) || ! function_exists( 'wc_get_product' ) ) {
			return $content;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product instanceof WC_Product ) {
			return $content;
		}
		if ( 'woocommerce/product-price' === ( $block['blockName'] ?? '' ) && isset( $source['prefix'], $source['suffix'] ) && is_string( $source['prefix'] ) && is_string( $source['suffix'] ) ) {
			$price = $product instanceof WC_Product_Variable ? wc_price( (float) $product->get_variation_price( 'min' ) ) : $product->get_price_html();
			$price = esc_html( $source['prefix'] ) . $price . esc_html( $source['suffix'] );
			// Preserve Woo's outer block and its source presentation supports.
			return (string) preg_replace_callback( '/^(\s*<([a-z][a-z0-9]*)\b[^>]*>).*?(<\/\2>\s*)$/is', static fn( array $parts ): string => $parts[1] . $price . $parts[3], $content );
		}
		if ( 'woocommerce/breadcrumbs' !== ( $block['blockName'] ?? '' ) || ! in_array( $source['separator'] ?? '', array( '>', '›', '»', '/', '→' ), true ) || ! isset( $source['home'] ) || ! is_bool( $source['home'] ) || ! class_exists( 'WC_Breadcrumb' ) ) {
			return $content;
		}
		$breadcrumb = new WC_Breadcrumb();
		if ( $source['home'] ) {
			$breadcrumb->add_crumb( __( 'Home', 'static-site-importer' ), home_url() );
		}
		$crumbs = $breadcrumb->generate();
		$parts  = array();
		foreach ( $crumbs as $index => $crumb ) {
			$parts[] = ! empty( $crumb[1] ) && $index < count( $crumbs ) - 1 ? '<a href="' . esc_url( $crumb[1] ) . '">' . esc_html( $crumb[0] ) . '</a>' : esc_html( $crumb[0] );
		}
		$body    = implode( ' ' . esc_html( $source['separator'] ) . ' ', $parts );
		$content = (string) preg_replace_callback( '/(<nav\b[^>]*>).*?(<\/nav>)/is', static fn( array $parts ): string => $parts[1] . $body . $parts[2], $content );
		$size    = $source['fontSize'] ?? '';
		if ( is_string( $size ) && preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%)$/D', $size ) ) {
			$tags = new WP_HTML_Tag_Processor( $content );
			if ( $tags->next_tag( array( 'tag_name' => 'NAV' ) ) ) {
				$tags->set_attribute( 'style', rtrim( (string) $tags->get_attribute( 'style' ), ';' ) . ';font-size:' . $size );
			}
			$content = $tags->get_updated_html();
		}
		return $content;
	}

	/** Restore inert source identity on the native gallery's outer block only. */
	public static function gallery_identity( string $content, array $block ): string {
		$identity = $block['attrs']['metadata']['staticSiteImporterGalleryIdentity'] ?? array();
		if ( ! is_array( $identity ) || ! $identity ) {
			return $content;
		}
		$tags = new WP_HTML_Tag_Processor( $content );
		if ( $tags->next_tag() ) {
			foreach ( $identity as $key => $value ) {
				if ( preg_match( '/^data-(?!wp-)[a-z0-9_-]+$/D', $key ) && is_string( $value ) ) {
					$tags->set_attribute( $key, $value );
				}
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Resolve the archive's portable source identity against the imported page records.
	 *
	 * @param mixed $id Default document ID.
	 */
	public static function archive_document_id( mixed $id ): mixed {
		if ( ! function_exists( 'is_shop' ) || ! function_exists( 'is_product_taxonomy' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
			return $id;
		}
		// The theme asks once per page-scoped stylesheet; resolve once per request.
		static $resolved = array();
		$path            = function_exists( 'get_theme_file_path' ) ? get_theme_file_path( 'templates/archive-product.html' ) : '';
		if ( ! array_key_exists( $path, $resolved ) ) {
			$resolved[ $path ] = 0;
			$markup            = '' !== $path && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the active theme's archive identity.
			// Block attribute JSON may contain whitespace around the identity separator.
			if ( is_string( $markup ) && preg_match( '/"' . self::ARCHIVE_DOCUMENT_KEY . '"\s*:\s*"([a-f0-9]{64})"/', $markup, $match ) ) {
				$pages             = get_posts(
					array(
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_key'       => '_blocks_engine_reconciliation_identity', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Imported document identity lookup.
						'meta_value'     => $match[1], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Imported document identity lookup.
					)
				);
				$resolved[ $path ] = empty( $pages ) ? 0 : (int) $pages[0];
			}
		}
		return 0 === $resolved[ $path ] ? $id : $resolved[ $path ];
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
			'price'      => $product instanceof WC_Product ? $product->get_price() : '',
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
		$rendered = self::template_values( (int) ( $source['product_id'] ?? 0 ), $content, 'field' );
		foreach ( array( 'permalink', 'images', 'price' ) as $key ) {
			if ( empty( $source[ $key ] ) ) {
				$source[ $key ] = $rendered[ $key ];
			}
		}
		$ids   = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
		$index = 0;
		$tags  = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_token() ) {
			if ( '#text' === $tags->get_token_type() && ! in_array( $tags->get_tag(), array( 'SCRIPT', 'STYLE' ), true ) && ! empty( $source['name'] ) ) {
				$text = $tags->get_modifiable_text();
				$text = self::replace_price_figure( $text, (string) ( $source['price'] ?? '' ), $product->get_price() );
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
	 * Replace only a complete price figure, preserving source currency placement.
	 * Derived amounts such as instalments are not recomputed.
	 *
	 * @param string $text Source text token.
	 * @param string $source Template product price.
	 * @param string $price Current product price.
	 */
	private static function replace_price_figure( string $text, string $source, string $price ): string {
		if ( ! is_numeric( $source ) || ! is_numeric( $price ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/[0-9]+(?:[.,\x{00a0}\x{202f}\x{0020}\x{0027}][0-9]+)*/u',
			static function ( array $figure ) use ( $source, $price ): string {
				foreach ( array( '.', ',' ) as $decimal ) {
					foreach ( array( '', '.', ',', ' ', "\u{00a0}", "\u{202f}", "'" ) as $thousands ) {
						if ( $decimal === $thousands ) {
							continue;
						}
						for ( $places = 0; $places <= 4; ++$places ) {
							if ( number_format( (float) $source, $places, $decimal, $thousands ) === $figure[0] ) {
								return number_format( (float) $price, $places, $decimal, $thousands );
							}
						}
					}
				}
				return $figure[0];
			},
			$text
		);
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
		return '.ssi-purchase-quantity-label{display:block;}'
			. $loop . ',' . $loop . ' > .wc-block-components-notices{display:contents;}'
			// A store notice spans the whole grid row instead of taking a card's cell.
			. $loop . ' > .wc-block-components-notices > *{grid-column:1/-1;}';
	}
}
