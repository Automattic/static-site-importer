<?php
/**
 * Generated themes ship only the runtime files and blocks the site uses (#3108).
 *
 * Run: php tests/smoke-runtime-package-usage.php
 *
 * @package StaticSiteImporter
 */

define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';

foreach ( array( 'core/group', 'core/heading', 'core/paragraph', 'core/html', 'core/buttons', 'core/button', 'core/navigation', 'core/navigation-link', 'core/template-part', 'core/post-content', 'core/image' ) as $block_name ) {
	if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
		WP_Block_Type_Registry::get_instance()->register( $block_name );
	}
}

$import = static function ( string $slug, string $body ) use ( $assert ): array {
	$artifact = array(
		'entrypoint' => 'index.html',
		'files'      => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Home</title></head><body><main>' . $body . '</main></body></html>',
			),
		),
	);
	$result   = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, array( 'slug' => $slug ) );
	$assert( ! is_wp_error( $result ), $slug . ' imports: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
	$assert( 'completed' === $result['materialization_receipt']['status'], $slug . ' materializes' );
	$dir   = $result['materialization_receipt']['theme']['dir'];
	$files = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/ssi-runtime', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$files[] = 'ssi-runtime/' . substr( $file->getPathname(), strlen( $dir . '/ssi-runtime/' ) );
	}
	sort( $files );
	return array(
		'dir'     => $dir,
		'files'   => $files,
		'runtime' => $result['import_report']['theme_runtime'] ?? array(),
	);
};

$feature_includes = array(
	'ssi-runtime/includes/provider-form-runtime-v1.php',
	'ssi-runtime/includes/internal-link-runtime.php',
	'ssi-runtime/includes/external-metric-runtime.php',
	'ssi-runtime/includes/ip-classifier.php',
	'ssi-runtime/includes/product-grid-runtime.php',
	'ssi-runtime/includes/product-listing-runtime.php',
	'ssi-runtime/includes/post-source-route-runtime.php',
	'ssi-runtime/editor/external-metric-controls.js',
);

// A site without forms, products, metrics, routes or runtime blocks.
$plain = $import( 'runtime-usage-plain', '<h1>Portfolio</h1><p>Photographs of the coast.</p>' );
$expected_base = array( 'ssi-runtime/README.md', 'ssi-runtime/companion.json', 'ssi-runtime/editor/imported-media-replace.js', 'ssi-runtime/runtime.php' );
$assert( $expected_base === $plain['files'], 'plain site ships only the base runtime: ' . wp_json_encode( $plain['files'] ) );
$assert( array() === array_intersect( $feature_includes, $plain['files'] ), 'plain site ships no feature runtime' );
$runtime_php = (string) file_get_contents( $plain['dir'] . '/ssi-runtime/runtime.php' );
$assert( ! str_contains( $runtime_php, "/includes/" ), 'plain runtime.php requires no feature include' );
$recorded = $plain['runtime']['runtime_files'] ?? null;
$assert( is_array( $recorded ), 'import report records the shipped runtime files' );
sort( $recorded );
$assert( $plain['files'] === $recorded, 'recorded runtime files match the installed files' );
$assert( 4 === ( $plain['runtime']['runtime_file_count'] ?? null ), 'import report counts four shipped runtime files' );
$config = json_decode( (string) file_get_contents( $plain['dir'] . '/ssi-runtime/companion.json' ), true );
$assert( array() === ( $config['block_directories'] ?? null ), 'plain site ships no runtime block directories' );

// A site that uses a content-driven runtime ships that runtime and only it.
$member = $import( 'runtime-usage-member', '<h1>Members</h1><p><a href="/members/" data-blocks-engine-capture-member-login>Sign in</a></p>' );
$assert( in_array( 'ssi-runtime/includes/internal-link-runtime.php', $member['files'], true ), 'member sign-in site ships the internal link runtime: ' . wp_json_encode( $member['files'] ) );
$assert( array( 'ssi-runtime/includes/internal-link-runtime.php' ) === array_values( array_intersect( $feature_includes, $member['files'] ) ), 'member sign-in site ships no unrelated runtime' );
$assert( str_contains( (string) file_get_contents( $member['dir'] . '/ssi-runtime/runtime.php' ), "require_once __DIR__ . '/includes/internal-link-runtime.php';" ), 'member runtime.php loads the shipped runtime' );
$assert( count( $member['files'] ) === ( $member['runtime']['runtime_file_count'] ?? null ), 'member import report counts its shipped runtime files' );

// Declared runtime blocks no document uses are not shipped, nor are their scoped scripts.
$payload = array(
	'schema'       => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'    => 'usage',
	'site_name'    => 'Usage',
	'blocks'       => array(
		array( 'name' => 'motion-sequence', 'block_json' => array( 'name' => 'ssi-usage/motion-sequence', 'title' => 'Motion' ) ),
		array( 'name' => 'live-clock', 'block_json' => array( 'name' => 'ssi-usage/live-clock', 'title' => 'Clock' ) ),
	),
	'preserved_js' => array( array( 'handle' => 'motion', 'content' => 'void 0;', 'block' => 'ssi-usage/motion-sequence' ) ),
);
$scoped  = Static_Site_Importer_Companion_Plugin::scoped_to_usage( $payload, array( 'pages' => array( array( 'resolved_block_markup' => '<!-- wp:ssi-usage/live-clock /-->' ) ) ), array() );
$package = Static_Site_Importer_Generated_Runtime_Package::theme( $scoped, 'usage' );
$assert( ! is_wp_error( $package ) && array( 'ssi-usage/live-clock' ) === $package['block_names'], 'only the used runtime block ships' );
$assert( array() === $scoped['preserved_js'] && array() === $scoped['runtime_features'], 'scripts scoped to unused blocks and unused runtimes are dropped' );

echo "PASS: runtime package usage\n";
