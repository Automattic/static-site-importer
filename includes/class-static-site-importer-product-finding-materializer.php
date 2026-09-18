<?php
/**
 * Materializes product findings into provider-owned catalog rows.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Entity_Materializer_Registry' ) ) {
	require_once __DIR__ . '/class-static-site-importer-entity-materializer-registry.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-projection.php';
}

/** Seeds provider products from report findings and grafts cart shortcodes. */
final class Static_Site_Importer_Product_Finding_Materializer {
	/**
	 * Resolve the page content key that owns a fallback region.
	 *
	 * A resolvable source page exclusively owns the fallback region. Only source-less
	 * or unresolvable legacy findings scan page contents in their supplied order.
	 *
	 * @param string               $source_path   Finding source path.
	 * @param string               $region        Serialized readable fallback region.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename.
	 * @return string|null
	 */
	public static function form_fallback_page_content_key( string $source_path, string $region, array $page_contents ): ?string {
		$source_keys = array();
		if ( '' !== $source_path ) {
			foreach ( array_keys( $page_contents ) as $key ) {
				if ( self::form_source_paths_match( $source_path, (string) $key ) ) {
					$source_keys[] = (string) $key;
				}
			}
		}

		if ( ! empty( $source_keys ) ) {
			foreach ( $source_keys as $key ) {
				if ( str_contains( (string) $page_contents[ $key ], $region ) ) {
					return $key;
				}
			}

			return null;
		}

		foreach ( $page_contents as $key => $content ) {
			if ( str_contains( (string) $content, $region ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * Compare a finding source path against a page content key.
	 *
	 * @param string $source_path Finding source path.
	 * @param string $key         Page content key.
	 * @return bool
	 */
	public static function form_source_paths_match( string $source_path, string $key ): bool {
		if ( $source_path === $key ) {
			return true;
		}

		$normalized_source = self::normalize_form_source_path( $source_path );
		$normalized_key    = self::normalize_form_source_path( $key );
		if ( '' !== $normalized_source && $normalized_source === $normalized_key ) {
			return true;
		}

		$source_base = basename( $source_path );
		return '' !== $source_base && basename( $key ) === $source_base;
	}

	/**
	 * Normalize a source path for form-fallback page matching.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	public static function normalize_form_source_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path ) {
			return '';
		}
		if ( str_starts_with( $path, 'website/' ) ) {
			$path = substr( $path, strlen( 'website/' ) );
		}
		return trim( $path, '/' );
	}

	/**
	 * Serialize a transformer-readable anchor with the WordPress runtime.
	 *
	 * Graft anchors compare byte-for-byte with post_content, so Core owns all
	 * Gutenberg delimiter and attribute escaping.
	 *
	 * @param array<int,array<string,mixed>> $readable_blocks Readable fallback block tree.
	 * @return string
	 */
	public static function serialize_readable_graft_anchor( array $readable_blocks ): string {
		if ( ! function_exists( 'serialize_blocks' ) ) {
			return '';
		}

		/**
		 * The transformer emits Core's parsed-block shape. This explicit boundary
		 * preserves the broad diagnostic input type while Core serializes the tree.
		 *
		 * @var array<int|string,array{blockName:string|null,attrs:array,innerBlocks:array<array>,innerHTML:string,innerContent:array}> $core_blocks
		 */
		$core_blocks = $readable_blocks;

		return serialize_blocks( $core_blocks );
	}

	/**
	 * Materialize detected product-grid fallbacks through the configured shop provider.
	 *
	 * Collects every `html_product_grid_fallback` finding and materializes only
	 * producer-declared product rows (slug + regular_price already present) through
	 * the shop adapter's manifest validator + seeder. Stamps the runtime-mapped /
	 * acceptable-preservation signal onto each finding whose products were actually
	 * seeded. Findings whose products could not be seeded (for example because
	 * WooCommerce is unavailable) keep no signal and stay an unacceptable parity
	 * loss, which lets the existing commerce dependency gate report the missing
	 * runtime.
	 *
	 * @param Static_Site_Importer_Import_Report  $report        Import report (mutated in place).
	 * @param array<string,mixed>  $args          Import args.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return array<string,mixed> The recorded product_finding_seeding report.
	 */
	public static function materialize_product_findings( Static_Site_Importer_Import_Report $report, array $args = array(), array &$page_contents = array() ): array {
		$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();

		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$indexes     = self::product_grid_finding_indexes( $diagnostics );

		if ( empty( $indexes ) ) {
			$seeding                           = Static_Site_Importer_Entity_Materializer_Registry::new_entity_report( $adapter );
			$seeding['status']                 = 'skipped';
			$seeding['reason']                 = 'no_product_findings';
			$report['product_finding_seeding'] = $seeding;
			return $seeding;
		}
		if ( empty( $adapter ) ) {
			$seeding                           = array(
				'status'        => 'skipped',
				'reason'        => 'configured_shop_provider_unsupported',
				'provider'      => Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ),
				'finding_count' => count( $indexes ),
				'product_count' => 0,
				'mapped_count'  => 0,
				'counts'        => array(
					'created' => 0,
					'updated' => 0,
					'skipped' => 0,
					'error'   => 0,
				),
				'products'      => array(),
			);
			$report['product_finding_seeding'] = $seeding;
			return $seeding;
		}

		$manifest_products = array();
		$finding_slugs     = array();
		foreach ( $indexes as $index ) {
			$diagnostic = $report['diagnostics'][ $index ];
			$products   = isset( $diagnostic['products'] ) && is_array( $diagnostic['products'] ) ? $diagnostic['products'] : array();
			$container  = isset( $diagnostic['container_selector'] ) && is_scalar( $diagnostic['container_selector'] )
				? (string) $diagnostic['container_selector']
				: ( isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '' );

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$row = self::product_finding_manifest_row( $product, $container );
				if ( null === $row ) {
					continue;
				}

				$manifest_products[]       = $row;
				$finding_slugs[ $index ][] = $row['slug'];
			}
		}

		$manifest   = array(
			'schema_version' => 1,
			'products'       => $manifest_products,
		);
		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest( $adapter, $manifest );
		$validated  = $validation['products'];

		$seeding = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, array( 'products' => $validated ), $args );
		if ( $seeding instanceof WP_Error ) {
			$error             = $seeding;
			$seeding           = Static_Site_Importer_Entity_Materializer_Registry::new_entity_report( $adapter );
			$seeding['status'] = 'error';
			$seeding['reason'] = 'materialization_failed';
			$seeding['errors'] = array(
				array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				),
			);
		}

		$seeding['provider']      = (string) ( $adapter['provider'] ?? '' );
		$seeding['finding_count'] = count( $indexes );
		$seeding['product_count'] = count( $manifest_products );
		$seeding['mapped_count']  = 0;
		$seeding['waived']        = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? 'allow_missing_woocommerce' ) ] );
		$seeding['manifest']      = $manifest;
		if ( ! empty( $validation['errors'] ) ) {
			$seeding['validation_errors'] = $validation['errors'];
		}

		$seeded_products_by_slug = array();
		foreach ( ( isset( $seeding['products'] ) && is_array( $seeding['products'] ) ? $seeding['products'] : array() ) as $product_row ) {
			if ( ! is_array( $product_row ) ) {
				continue;
			}
			if ( in_array( (string) ( $product_row['status'] ?? '' ), array( 'created', 'updated' ), true ) ) {
				$slug = (string) ( $product_row['slug'] ?? '' );
				if ( '' !== $slug ) {
					$seeded_products_by_slug[ $slug ] = $product_row;
				}
			}
		}

		$seeding['shortcode_grafted_count'] = 0;

		foreach ( $indexes as $index ) {
			$slugs = $finding_slugs[ $index ] ?? array();
			foreach ( $slugs as $slug ) {
				if ( isset( $seeded_products_by_slug[ $slug ] ) ) {
					++$seeding['mapped_count'];
					$report->replace_diagnostic( $index, self::mark_product_finding_mapped( $report->diagnostics()[ $index ], $seeding['provider'] ) );
					break;
				}
			}

			if ( ! empty( $page_contents ) ) {
				$graft = self::graft_product_add_to_cart_shortcodes_into_page_contents( $adapter, $report->diagnostics()[ $index ], $seeded_products_by_slug, $page_contents );
				$report->replace_diagnostic( $index, $graft['finding'] );
				if ( $graft['grafted'] ) {
					++$seeding['shortcode_grafted_count'];
				}
				if ( is_array( $graft['diagnostic'] ) ) {
					$report->append_diagnostic( $graft['diagnostic'] );
				}
			}
		}

		$report['product_finding_seeding'] = $seeding;
		return $seeding;
	}

	/**
	 * Replace plain static product-card add-to-cart buttons with adapter-owned markup.
	 *
	 * This prototype only rewrites serialized Gutenberg button fallbacks. Raw HTML
	 * runtime controls are left in place so SSI never embeds block markup inside an
	 * arbitrary HTML island or fakes cart state.
	 *
	 * @param array<string,mixed>        $finding                 Product-grid finding.
	 * @param array<string,array<mixed>> $seeded_products_by_slug Seeded Woo rows keyed by slug.
	 * @param array<string,string>       $page_contents           Materialized page post_content keyed by source filename.
	 * @return array{grafted:bool,finding:array<string,mixed>,diagnostic:?array<string,mixed>}
	 */
	public static function graft_product_add_to_cart_shortcodes_into_page_contents( array $adapter, array $finding, array $seeded_products_by_slug, array &$page_contents ): array {
		$source_path = Static_Site_Importer_Diagnostic_Projection::first_scalar( $finding, array( 'graft_source_path', 'source_path', 'source' ) );
		$selector    = isset( $finding['selector'] ) && is_scalar( $finding['selector'] ) ? (string) $finding['selector'] : '';

		$unanchorable = static function ( string $reason ) use ( &$finding, $source_path, $selector ): array {
			$finding['product_shortcode_grafted'] = false;
			return array(
				'grafted'    => false,
				'finding'    => $finding,
				'diagnostic' => array(
					'type'            => 'product_add_to_cart_graft_unanchorable',
					'reason'          => $reason,
					'source_path'     => $source_path,
					'selector'        => $selector,
					'diagnostic_code' => 'html_product_add_to_cart_graft_unanchorable',
					'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
					'message'         => 'Provider add-to-cart markup could not be safely anchored to plain serialized button controls; the static controls were left in place.',
				),
			);
		};

		$products = self::shortcode_graft_products( $adapter, $finding, $seeded_products_by_slug );
		if ( empty( $products ) ) {
			return $unanchorable( 'no_safe_plain_add_to_cart_products' );
		}

		$readable = isset( $finding['readable_blocks'] ) && is_array( $finding['readable_blocks'] ) ? $finding['readable_blocks'] : array();
		$region   = self::serialize_readable_graft_anchor( $readable );
		if ( '' === $region ) {
			return $unanchorable( 'no_readable_fallback_blocks' );
		}

		$key = self::form_fallback_page_content_key( $source_path, $region, $page_contents );
		if ( null === $key ) {
			return $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		$shortcoded_region = self::replace_plain_cart_button_blocks_with_shortcodes( $region, $products );
		if ( null === $shortcoded_region ) {
			return $unanchorable( 'plain_serialized_button_controls_not_matched' );
		}

		$content  = (string) ( $page_contents[ $key ] ?? '' );
		$position = strpos( $content, $region );
		if ( false === $position ) {
			return $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		$page_contents[ $key ]                    = substr( $content, 0, $position ) . $shortcoded_region . substr( $content, $position + strlen( $region ) );
		$finding['product_shortcode_grafted']     = true;
		$finding['grafted_post_content_key']      = $key;
		$finding['grafted_add_to_cart_shortcode'] = true;

		return array(
			'grafted'    => true,
			'finding'    => $finding,
			'diagnostic' => null,
		);
	}

	/**
	 * Resolve products eligible for plain add-to-cart shortcode grafting.
	 *
	 * @param array<string,mixed>        $finding                 Product-grid finding.
	 * @param array<string,mixed>        $adapter                 Selected shop adapter.
	 * @param array<string,array<mixed>> $seeded_products_by_slug Seeded provider rows keyed by slug.
	 * @return array<int,array{binding_markup:string,slug:string}>
	 */
	public static function shortcode_graft_products( array $adapter, array $finding, array $seeded_products_by_slug ): array {
		$products = isset( $finding['products'] ) && is_array( $finding['products'] ) ? $finding['products'] : array();
		$eligible = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) || ! self::is_plain_add_to_cart_product( $product ) ) {
				return array();
			}

			$row    = self::product_finding_manifest_row( $product, '' );
			$slug   = is_array( $row ) ? (string) ( $row['slug'] ?? '' ) : '';
			$result = is_array( $seeded_products_by_slug[ $slug ] ?? null ) ? $seeded_products_by_slug[ $slug ] : array();
			$markup = Static_Site_Importer_Entity_Materializer_Registry::binding_block_markup( $adapter, $row, $result );
			if ( '' === $slug || '' === $markup ) {
				return array();
			}

			$eligible[] = array(
				'binding_markup' => $markup,
				'slug'           => $slug,
			);
		}

		return $eligible;
	}

	/**
	 * Determine whether a detected product card has only a plain add-to-cart action.
	 *
	 * @param array<string,mixed> $product Detected product data.
	 * @return bool
	 */
	public static function is_plain_add_to_cart_product( array $product ): bool {
		if ( true !== ( $product['has_cart_control'] ?? false ) ) {
			return false;
		}

		foreach ( array( 'has_quantity_control', 'has_option_control', 'has_variant_control', 'has_custom_state', 'requires_options' ) as $flag ) {
			if ( true === ( $product[ $flag ] ?? false ) ) {
				return false;
			}
		}

		foreach ( array( 'quantity', 'options', 'variants', 'attributes', 'custom_state', 'form_controls' ) as $field ) {
			if ( ! empty( $product[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replace one serialized core/buttons add-to-cart control per product.
	 *
	 * @param string                   $region   Serialized fallback block region.
	 * @param array<int,array{binding_markup:string}> $products Eligible seeded products in card order.
	 * @return string|null
	 */
	public static function replace_plain_cart_button_blocks_with_shortcodes( string $region, array $products ): ?string {
		$pattern = '/<!--\s+wp:buttons\b.*?-->(?:(?!<!--\s+\/wp:buttons\s+-->).)*<!--\s+\/wp:buttons\s+-->/is';
		$matches = array();
		preg_match_all( $pattern, $region, $matches, PREG_OFFSET_CAPTURE );

		$cart_controls = array_values(
			array_filter(
				$matches[0],
				static fn( array $control_match ): bool => 1 === preg_match( '/\b(?:add\s+to\s+cart|buy\s+now|purchase|order\s+now)\b/i', wp_strip_all_tags( (string) $control_match[0] ) )
			)
		);

		if ( count( $cart_controls ) !== count( $products ) ) {
			return null;
		}

		$rewritten = '';
		$cursor    = 0;
		foreach ( $cart_controls as $index => $control_match ) {
			$block      = (string) $control_match[0];
			$position   = (int) $control_match[1];
			$rewritten .= substr( $region, $cursor, $position - $cursor );
			$rewritten .= $products[ $index ]['binding_markup'];
			$cursor     = $position + strlen( $block );
		}

		return $rewritten . substr( $region, $cursor );
	}

	/**
	 * Return diagnostic indexes for every detected product-grid fallback finding.
	 *
	 * @param array<int,mixed> $diagnostics Report diagnostics.
	 * @return array<int,int>
	 */
	public static function product_grid_finding_indexes( array $diagnostics ): array {
		$indexes = array();
		foreach ( $diagnostics as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$code = (string) ( $diagnostic['diagnostic_code'] ?? $diagnostic['code'] ?? '' );
			if ( '' === $code ) {
				$code = (string) ( $diagnostic['kind'] ?? '' );
			}

			if ( 'html_product_grid_fallback' === $code ) {
				$indexes[] = (int) $index;
			}
		}

		return $indexes;
	}

	/**
	 * Normalize active product-grid findings into the Woo manifest row contract.
	 *
	 * This is intentionally limited to the Blocks Engine product-grid discriminator.
	 * Callers own how the rows are declared or materialized; this helper owns only
	 * the source-finding to validated-product data bridge.
	 *
	 * @param array<int,mixed> $diagnostics Plan or report diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	public static function product_grid_manifest_products( array $diagnostics ): array {
		$products = array();
		foreach ( self::product_grid_finding_indexes( $diagnostics ) as $index ) {
			$finding = $diagnostics[ $index ] ?? array();
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$container = isset( $finding['container_selector'] ) && is_scalar( $finding['container_selector'] )
				? (string) $finding['container_selector']
				: ( isset( $finding['selector'] ) && is_scalar( $finding['selector'] ) ? (string) $finding['selector'] : '' );
			foreach ( is_array( $finding['products'] ?? null ) ? $finding['products'] : array() as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}
				$row = self::product_finding_manifest_row( $product, $container );
				if ( null !== $row ) {
					$products[] = $row;
				}
			}
		}
		return $products;
	}

	/**
	 * Derive a shared `generic/block-binding/v1` anchor per product for every
	 * detected product-grid finding, keyed by the same slug the finding's own
	 * seeded manifest row uses.
	 *
	 * A product-grid finding carries no per-product canonical block-replacement
	 * anchor (the finding-to-manifest bridge intentionally does not infer one
	 * from a selector), but it does carry the exact preserved fallback markup
	 * the whole grid region already compiles to (`readable_blocks`). Every
	 * product detected inside that same grid safely shares that one exact,
	 * already-serialized region as its binding anchor, with role
	 * `commerce_collection` so the entity/binding registry resolves the shared
	 * anchor to one native product-display block instead of racing N
	 * single-product replacements against the same source-page occurrence.
	 *
	 * @param array<int,mixed> $diagnostics Plan or report diagnostics.
	 * @return array<string,array{source_path:string,search_block_markup:string}>
	 */
	public static function product_grid_binding_anchors( array $diagnostics ): array {
		$anchors = array();
		foreach ( self::product_grid_finding_indexes( $diagnostics ) as $index ) {
			$finding = $diagnostics[ $index ] ?? array();
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$readable = isset( $finding['readable_blocks'] ) && is_array( $finding['readable_blocks'] ) ? $finding['readable_blocks'] : array();
			$region   = self::serialize_readable_graft_anchor( $readable );
			if ( '' === $region ) {
				continue;
			}
			$source_path = Static_Site_Importer_Diagnostic_Projection::first_scalar( $finding, array( 'graft_source_path', 'source_path', 'source' ) );
			if ( '' === $source_path ) {
				continue;
			}
			$container = isset( $finding['container_selector'] ) && is_scalar( $finding['container_selector'] )
				? (string) $finding['container_selector']
				: ( isset( $finding['selector'] ) && is_scalar( $finding['selector'] ) ? (string) $finding['selector'] : '' );
			foreach ( is_array( $finding['products'] ?? null ) ? $finding['products'] : array() as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}
				$row  = self::product_finding_manifest_row( $product, $container );
				$slug = is_array( $row ) ? (string) ( $row['slug'] ?? '' ) : '';
				if ( '' === $slug || isset( $anchors[ $slug ] ) ) {
					// A slug already anchored by another grid stays with its first,
					// unambiguous anchor rather than being silently reassigned.
					continue;
				}
				$anchors[ $slug ] = array(
					'source_path'         => $source_path,
					'search_block_markup' => $region,
				);
			}
		}
		return $anchors;
	}

	/**
	 * Copy one producer-declared product into a products-manifest/v1 row.
	 *
	 * @param array<string,mixed> $product           Producer product declaration.
	 * @param string              $container_selector Owning grid container selector.
	 * @return array<string,mixed>|null
	 */
	public static function product_finding_manifest_row( array $product, string $container_selector ): ?array {
		$name = isset( $product['name'] ) && is_scalar( $product['name'] ) ? trim( (string) $product['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		$slug = isset( $product['slug'] ) && is_scalar( $product['slug'] ) ? trim( (string) $product['slug'] ) : '';
		if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return null;
		}

		$regular_price = isset( $product['regular_price'] ) && is_scalar( $product['regular_price'] ) ? trim( (string) $product['regular_price'] ) : '';
		if ( '' === $regular_price || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{2})?$/', $regular_price ) ) {
			return null;
		}

		$row = array(
			'name'          => $name,
			'slug'          => $slug,
			'regular_price' => $regular_price,
		);

		$sale_price = isset( $product['sale_price'] ) && is_scalar( $product['sale_price'] ) ? trim( (string) $product['sale_price'] ) : '';
		$sale_price = 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{2})?$/', $sale_price ) ? $sale_price : '';
		if ( '' !== $sale_price ) {
			$row['sale_price'] = $sale_price;
		}

		if ( isset( $product['description'] ) && is_scalar( $product['description'] ) && '' !== trim( (string) $product['description'] ) ) {
			$row['description'] = (string) $product['description'];
		}

		$image = self::product_image_src( $product['image'] ?? null );
		if ( '' !== $image ) {
			$row['image'] = $image;
		}
		$image_alt = self::product_image_alt( $product['image'] ?? null );
		if ( '' !== $image_alt ) {
			$row['image_alt'] = $image_alt;
		}

		$selectors = array();
		if ( isset( $product['source_selector'] ) && is_scalar( $product['source_selector'] ) && '' !== trim( (string) $product['source_selector'] ) ) {
			$selectors[] = trim( (string) $product['source_selector'] );
		}
		if ( '' !== $container_selector ) {
			$selectors[] = $container_selector;
		}
		$selectors = array_values( array_unique( $selectors ) );
		if ( ! empty( $selectors ) ) {
			$row['source_selectors'] = $selectors;
		}

		return $row;
	}

	/**
	 * Resolve the product image source from a string or {src, alt} object.
	 *
	 * @param mixed $image Detected product image.
	 * @return string
	 */
	public static function product_image_src( mixed $image ): string {
		if ( is_string( $image ) ) {
			return trim( $image );
		}

		if ( is_array( $image ) ) {
			$src = $image['src'] ?? '';
			return is_scalar( $src ) ? trim( (string) $src ) : '';
		}

		return '';
	}

	/**
	 * Resolve the product image alt text from an {src, alt} object.
	 *
	 * A bare string image carries no alt text of its own.
	 *
	 * @param mixed $image Detected product image.
	 * @return string
	 */
	public static function product_image_alt( mixed $image ): string {
		if ( ! is_array( $image ) ) {
			return '';
		}

		$alt = $image['alt'] ?? '';
		return is_scalar( $alt ) ? trim( (string) $alt ) : '';
	}

	/**
	 * Normalize a human-readable currency price into a decimal manifest string.
	 *
	 * Generic and locale-tolerant: strips currency symbols, whitespace, and other
	 * non-numeric characters, then resolves the decimal separator from the digit
	 * grouping itself rather than any site or locale setting. Handles US grouping
	 * ("$1,299.00" => "1299.00"), European grouping ("1.299,00 €" => "1299.00"),
	 * symbol-only integers ("$24" => "24", "€18" => "18"), and bare decimals
	 * ("18.00" => "18.00"). The fractional part is normalized to exactly two
	 * decimals; integers stay integers so the manifest validator accepts both.
	 *
	 * @param string $price Raw price text.
	 * @return string Decimal price string, or '' when no digits are present.
	 */
	public static function normalize_product_price( string $price ): string {
		$clean = preg_replace( '/[^0-9.,]/', '', trim( $price ) );
		$clean = is_string( $clean ) ? $clean : '';
		if ( '' === $clean ) {
			return '';
		}

		$comma_count = substr_count( $clean, ',' );
		$dot_count   = substr_count( $clean, '.' );

		$decimal_sep = '';
		if ( 0 < $comma_count && 0 < $dot_count ) {
			// When both separators appear, the rightmost one is the decimal point.
			$comma_position = strrpos( $clean, ',' );
			$dot_position   = strrpos( $clean, '.' );
			$decimal_sep    = false !== $comma_position && false !== $dot_position && $comma_position > $dot_position ? ',' : '.';
		} elseif ( 1 === $comma_count ) {
			$decimal_sep = self::is_decimal_tail( $clean, ',' ) ? ',' : '';
		} elseif ( 1 === $dot_count ) {
			$decimal_sep = self::is_decimal_tail( $clean, '.' ) ? '.' : '';
		}
		// Repeated single separators (e.g. "1,234,567") are digit grouping only.

		if ( '' !== $decimal_sep ) {
			$parts    = explode( $decimal_sep, $clean );
			$fraction = (string) array_pop( $parts );
			$integer  = (string) preg_replace( '/[^0-9]/', '', implode( '', $parts ) );
			$fraction = (string) preg_replace( '/[^0-9]/', '', $fraction );
		} else {
			$integer  = (string) preg_replace( '/[^0-9]/', '', $clean );
			$fraction = '';
		}

		$integer = ltrim( $integer, '0' );
		if ( '' === $integer ) {
			$integer = '0';
		}

		if ( '' === $fraction ) {
			return $integer;
		}

		if ( strlen( $fraction ) > 2 ) {
			return number_format( (float) ( $integer . '.' . $fraction ), 2, '.', '' );
		}

		return $integer . '.' . str_pad( $fraction, 2, '0' );
	}

	/**
	 * Decide whether a single separator's trailing digits read as a decimal part.
	 *
	 * @param string $clean Digit-and-separator string.
	 * @param string $sep   Candidate decimal separator.
	 * @return bool
	 */
	public static function is_decimal_tail( string $clean, string $sep ): bool {
		$pos = strrpos( $clean, $sep );
		if ( false === $pos ) {
			return false;
		}

		$length = strlen( substr( $clean, $pos + 1 ) );
		return $length >= 1 && $length <= 2;
	}

	/**
	 * Stamp the runtime-mapped / acceptable-preservation signal onto a product finding.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic to mark.
	 * @param string              $provider   Resolved shop provider id.
	 * @return array<string,mixed>
	 */
	public static function mark_product_finding_mapped( array $diagnostic, string $provider ): array {
		$diagnostic['runtime_mapped']  = true;
		$diagnostic['mapped_provider'] = '' !== $provider ? $provider : 'woocommerce';
		$diagnostic['acceptability']   = 'acceptable_preservation';
		$diagnostic['block_name']      = isset( $diagnostic['block_name'] ) && is_scalar( $diagnostic['block_name'] ) && '' !== (string) $diagnostic['block_name']
			? (string) $diagnostic['block_name']
			: 'woocommerce/product-collection';

		return $diagnostic;
	}
}
