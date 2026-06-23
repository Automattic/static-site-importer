<?php
/**
 * Smoke coverage for entity materializer registry Woo dependency behavior.
 *
 * Run from the repository root:
 * php tests/smoke-entity-materializer-registry.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( string $post_type ): bool {
			unset( $post_type );
			return false;
		}
	}

	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( string $taxonomy ): bool {
			unset( $taxonomy );
			return false;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$intent  = array(
		'present'       => true,
		'sources'       => array( 'products_manifest' ),
		'product_count' => 1,
	);

	$dependencies = Static_Site_Importer_Entity_Materializer_Registry::dependency_rows( $adapter, $intent, false );
	$assert( isset( $dependencies['woocommerce'] ), 'woocommerce-dependency-row-key-preserved' );
	$assert( false === ( $dependencies['woocommerce']['active'] ?? true ), 'missing-woocommerce-reports-inactive' );
	$assert( false === ( $dependencies['woocommerce']['waived'] ?? true ), 'missing-woocommerce-not-waived-by-default' );
	$assert( array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ) === ( $dependencies['woocommerce']['missing_apis'] ?? array() ), 'missing-woocommerce-api-list-preserved' );
	$assert( array( 'products_manifest' ) === ( $dependencies['woocommerce']['sources'] ?? array() ), 'dependency-sources-preserved' );
	$assert( 1 === ( $dependencies['woocommerce']['product_count'] ?? 0 ), 'dependency-product-count-preserved' );
	$assert( false === Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter ), 'adapter-dependencies-not-available-without-woo' );

	$waived_dependencies = Static_Site_Importer_Entity_Materializer_Registry::dependency_rows( $adapter, $intent, true );
	$assert( true === ( $waived_dependencies['woocommerce']['waived'] ?? false ), 'waived-row-records-waiver' );

	$seeding = Static_Site_Importer_Entity_Materializer_Registry::materialize(
		$adapter,
		array(
			'products' => array(
				array(
					'name'          => 'Rye Loaf',
					'slug'          => 'rye-loaf',
					'regular_price' => '12.00',
				),
			),
		)
	);
	$assert( 'skipped' === ( $seeding['status'] ?? '' ), 'missing-woocommerce-skips-seeding' );
	$assert( 'woocommerce_inactive' === ( $seeding['reason'] ?? '' ), 'missing-woocommerce-skip-reason-preserved' );
	$assert( 1 === ( $seeding['counts']['skipped'] ?? 0 ), 'missing-woocommerce-skipped-count-preserved' );
	$assert( 'woocommerce_inactive' === ( $seeding['products'][0]['reason'] ?? '' ), 'missing-woocommerce-product-row-reason-preserved' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: entity materializer registry smoke passed (' . $assertions . " assertions)\n";
}
