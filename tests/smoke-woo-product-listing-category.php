<?php
/**
 * Smoke coverage for listing category terms and membership.
 *
 * Uses WordPress Core parsing and formatting with in-memory catalog API stubs.
 * Verifies planned page identities, empty categories, appended membership,
 * slug reuse, and rollback without disturbing pre-import catalog data.
 *
 * Run: php tests/smoke-woo-product-listing-category.php
 * Uses STATIC_SITE_IMPORTER_WP_ROOT or HOMEBOY_WORDPRESS_CORE_ROOT when set;
 * otherwise provisions the pinned source-only Core fixture (curl and tar required).
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting -- Minimal in-memory WordPress API stubs.
// phpcs:disable WordPress.Files.FileName -- Standalone smoke contains API stubs.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- WP_Post constructor promotes Core's ID property.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs match WordPress API signatures.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone fixture, not a WordPress request.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Stub object identifiers.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- A standalone smoke test stubs the WordPress and WooCommerce APIs it exercises.

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Use the same source-only Core fixture as the manifest runner, including when
// this harness is invoked directly without a configured WordPress installation.
$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
if ( ! $wp_root ) {
	$wp_root = getenv( 'HOMEBOY_WORDPRESS_CORE_ROOT' );
}
if ( ! $wp_root ) {
	$wp_root = dirname( __DIR__ ) . '/vendor/wordpress-core';
	if ( ! is_readable( $wp_root . '/wp-includes/blocks.php' ) ) {
		passthru( 'bash ' . escapeshellarg( dirname( __DIR__ ) . '/tools/prepare-wordpress-core-fixture.sh' ), $fixture_status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- Standalone CLI test provisions the pinned source-only fixture.
		if ( 0 !== $fixture_status ) {
			exit( 1 );
		}
	}
}
define( 'ABSPATH', rtrim( $wp_root, '/\\' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'OBJECT', 'OBJECT' );

// Block parsing/serialization and the HTML API come from WordPress Core.
$wp_includes = ABSPATH . WPINC . '/';
if ( ! is_readable( $wp_includes . 'blocks.php' ) ) {
	fwrite( STDERR, "FAIL: WordPress Core is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI failure report.
	exit( 1 );
}
foreach ( array( 'plugin.php', 'compat.php', 'functions.php', 'utf8.php', 'compat-utf8.php', 'l10n.php', 'formatting.php', 'kses.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $wp_file ) {
	// These UTF-8 helpers were split out of compat.php after the pinned 6.8 fixture.
	if ( in_array( $wp_file, array( 'utf8.php', 'compat-utf8.php' ), true ) && ! is_readable( $wp_includes . $wp_file ) ) {
		continue;
	}
	require_once $wp_includes . $wp_file;
}

class WC_Product {}
class WP_Post {
	public function __construct( public int $ID, public int $menu_order = 0 ) {}
}
class WP_Term {
	public function __construct( public int $term_id, public string $name, public string $slug ) {}
}
function wc_attribute_taxonomy_id_by_name(): int {
	return 0;
}
function get_page_by_path( string $slug ): ?WP_Post {
	$id = array(
		'first-item'  => 11,
		'second-item' => 12,
	)[ $slug ] ?? 0;
	return $id ? new WP_Post( $id ) : null;
}
function get_post( int $id ): WP_Post {
	return new WP_Post( $id );
}
function wp_update_post( array $post ): int {
	return $post['ID'];
}
function is_wp_error( mixed $value ): bool {
	return false;
}
$GLOBALS['ssi_category_terms']       = array( 70 => new WP_Term( 70, 'Existing category', 'existing' ) );
$GLOBALS['ssi_category_memberships'] = array( 11 => array( 70, 99 ) );
function get_term_by( string $field, string $slug, string $taxonomy ): WP_Term|false {
	foreach ( $GLOBALS['ssi_category_terms'] as $term ) {
		if ( $slug === $term->slug ) {
			return $term;
		}
	}
	return false;
}
function wp_insert_term( string $name, string $taxonomy, array $args ): array {
	$id                                   = max( array_keys( $GLOBALS['ssi_category_terms'] ) ) + 1;
	$GLOBALS['ssi_category_terms'][ $id ] = new WP_Term( $id, $name, $args['slug'] );
	return array( 'term_id' => $id );
}
function has_term( int $id, string $taxonomy, int $object ): bool {
	return in_array( $id, $GLOBALS['ssi_category_memberships'][ $object ] ?? array(), true );
}
function wp_set_object_terms( int $object, int $id, string $taxonomy, bool $append ): array {
	if ( 'product_cat' !== $taxonomy || ! $append ) {
		fwrite( STDERR, "FAIL: membership must append product categories.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic.
		exit( 1 );
	}
	if ( ! has_term( $id, $taxonomy, $object ) ) {
		$GLOBALS['ssi_category_memberships'][ $object ][] = $id;
	}
	return array( $id );
}
function wp_remove_object_terms( int $object, int $id, string $taxonomy ): bool {
	$GLOBALS['ssi_category_memberships'][ $object ] = array_values( array_diff( $GLOBALS['ssi_category_memberships'][ $object ], array( $id ) ) );
	return true;
}
function wp_delete_term( int $id, string $taxonomy ): bool {
	unset( $GLOBALS['ssi_category_terms'][ $id ] );
	return true;
}
add_filter( 'sanitize_title', 'sanitize_title_with_dashes', 10, 3 );
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-grid-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-listing-materializer.php';

$assert   = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic.
		exit( 1 );
	}
};
$pages    = array();
$listings = array();
foreach ( array(
	'supplies' => 'Garden Supplies',
	'empty'    => 'Empty Collection',
	'existing' => 'Existing Page',
) as $slug => $title ) {
	$source     = '/collections/' . $slug . '.html';
	$pages[]    = array(
		'source_path' => $source,
		'title'       => $title,
		'slug'        => $slug,
	);
	$listings[] = array( 'source_path' => $source );
}
$prepared = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions(
	array(
		'entities' => array(
			array(
				'adapter'  => array( 'entity_collection' => 'product_listings' ),
				'manifest' => array( 'product_listings' => $listings ),
			),
		),
	),
	array( 'pages' => $pages )
);
$listings = $prepared['entities'][0]['manifest']['product_listings'];
$assert(
	array(
		'name' => 'Garden Supplies',
		'slug' => 'supplies',
	) === $listings[0]['category'],
	'Category uses the planned page identity.'
);
$assert( 'Empty Collection' === $listings[1]['category']['name'], 'Empty listings receive a category.' );
$grid                    = array( 'items' => array( array( 'product' => 'first-item' ), array( 'product' => 'second-item' ) ) );
$listings[0]['grid']     = $grid;
$listings[0]['bindings'] = array( array() );
$listings[2]['grid']     = $grid;
$listings[2]['bindings'] = array( array() );
$before                  = $GLOBALS['ssi_category_memberships'];
$report                  = Static_Site_Importer_Woo_Product_Listing_Materializer::materialize( array( 'product_listings' => $listings ) );
$rows                    = $report['product_listings'];
$assert( 3 === count( $GLOBALS['ssi_category_terms'] ), 'One term per listing, reusing the existing slug.' );
$assert( 'Garden Supplies' === $GLOBALS['ssi_category_terms'][ $rows[0]['term_id'] ]->name && $rows[0]['term_created'], 'Creates the page-named term.' );
$assert( array( 11, 12 ) === $rows[0]['assigned_product_ids'] && has_term( $rows[0]['term_id'], 'product_cat', 11 ) && has_term( $rows[0]['term_id'], 'product_cat', 12 ), 'Assigns both products.' );
$assert( $rows[1]['term_created'] && array() === $rows[1]['assigned_product_ids'], 'Empty listing ensures a term without products.' );
$assert( 70 === $rows[2]['term_id'] && ! $rows[2]['term_created'], 'Reuses existing slug.' );
$rolled = Static_Site_Importer_Woo_Product_Listing_Materializer::rollback( $report );
$assert( 'rolled_back' === $rolled['status'] && array( 70 ) === array_keys( $GLOBALS['ssi_category_terms'] ), 'Rollback deletes only created terms.' );
$assert( $before[11] === $GLOBALS['ssi_category_memberships'][11] && array() === $GLOBALS['ssi_category_memberships'][12], 'Rollback removes new assignments and preserves prior memberships.' );
echo "Woo product listing category smoke passed.\n";
