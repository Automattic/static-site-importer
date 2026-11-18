<?php
/**
 * Generated theme runtimes ship only what the imported site uses (#3108).
 *
 * Compiles a small source page through ArtifactCompiler, resolves its
 * WordPress site plan, and scopes the theme runtime payload exactly as the
 * import path does before packaging it.
 *
 * Run: php tests/smoke-runtime-package-usage.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion failures, not browser output.

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require dirname( __DIR__ ) . '/vendor/autoload.php';
// Reuse the companion scaffolder's standalone WordPress doubles.
define( 'SSI_COMPANION_DOUBLES_ONLY', true );
require __DIR__ . '/smoke-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-generated-runtime-package.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$compiled = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><title>Photography</title></head><body><header><a href="index.html">Studio</a></header><main><h1>Portfolio</h1><p>Weddings and portraits.</p></main><footer>Contact the studio</footer></body></html>',
		),
	)
)->toArray();
$plan     = $compiled['source_reports']['wordpress_site_plan'];
$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://example.test/wp-content/themes/photo-studio' ) );

// The producer declared runtime blocks the page never uses, as in the issue.
$declared_block = static fn ( string $name ): array => array(
	'name'       => $name,
	'block_json' => array(
		'apiVersion' => 3,
		'name'       => 'ssi-photo-studio/' . $name,
		'title'      => $name,
	),
	'render'     => '<div class="' . $name . '"></div>',
);
$payload        = array(
	'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => 'photo-studio',
	'site_name' => 'Photo studio',
	'blocks'    => array( $declared_block( 'motion-sequence' ), $declared_block( 'live-clock' ) ),
);

$optional = array(
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
$scoped  = Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $resolved ), array() );
$package = Static_Site_Importer_Generated_Runtime_Package::theme( $scoped, 'photo-studio' );
$assert( ! is_wp_error( $package ), 'unused-runtime-site-packages' );
$shipped = is_wp_error( $package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $package );
$runtime = is_wp_error( $package ) ? '' : $package['files']['ssi-runtime/runtime.php'];
$assert( array() === array_intersect( $optional, $shipped ), 'unused-site-ships-no-optional-runtime: ' . implode( ', ', array_intersect( $optional, $shipped ) ) );
$assert( ! str_contains( $runtime, "/includes/" ), 'unused-site-runtime-requires-no-optional-include' );
$assert( array() === preg_grep( '~^ssi-runtime/blocks/~', $shipped ), 'unused-site-ships-no-unused-blocks' );
$assert( ! is_wp_error( $package ) && array() === $package['block_names'], 'unused-site-registers-no-blocks' );
$assert( array( 'ssi-runtime/README.md', 'ssi-runtime/companion.json', 'ssi-runtime/editor/imported-media-replace.js', 'ssi-runtime/runtime.php' ) === $shipped, 'shipped-files-counter: ' . implode( ', ', $shipped ) );
$config = is_wp_error( $package ) ? array() : json_decode( $package['files']['ssi-runtime/companion.json'], true );
$assert( array() === ( $config['block_directories'] ?? null ), 'unused-site-config-declares-no-block-directories' );
// Same-request runtime loading stays valid with the scoped include set.
$lint         = array();
$lint_status  = 1;
$runtime_file = sys_get_temp_dir() . '/ssi-runtime-usage-' . getmypid() . '.php';
file_put_contents( $runtime_file, $runtime );
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $runtime_file ) . ' 2>&1', $lint, $lint_status );
unlink( $runtime_file );
$assert( 0 === $lint_status, 'scoped-runtime-is-valid-php' );

// Content and provider evidence ship exactly the runtimes that use it.
$used                                       = $resolved;
$used['pages'][0]['resolved_block_markup'] .= '<!-- wp:ssi-photo-studio/live-clock /--><!-- wp:tabs --><div class="wp-block-tabs"></div><!-- /wp:tabs -->';
$lifecycle                                  = array(
	'dependencies' => array( 'shop' => array( 'adapter' => array( 'capability' => 'shop' ) ) ),
);
$used_package = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $used ), $lifecycle ), 'photo-studio' );
$used_files   = is_wp_error( $used_package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $used_package );
$assert( in_array( 'ssi-runtime/includes/internal-link-runtime.php', $used_files, true ) && in_array( 'ssi-runtime/includes/product-grid-runtime.php', $used_files, true ) && in_array( 'ssi-runtime/includes/product-listing-runtime.php', $used_files, true ), 'used-features-ship-their-runtime' );
$assert( ! in_array( 'ssi-runtime/includes/provider-form-runtime-v1.php', $used_files, true ) && ! in_array( 'ssi-runtime/includes/external-metric-runtime.php', $used_files, true ), 'unused-features-stay-out-beside-used-ones' );
$assert( in_array( 'ssi-runtime/blocks/live-clock/block.json', $used_files, true ) && ! in_array( 'ssi-runtime/blocks/motion-sequence/block.json', $used_files, true ), 'only-referenced-blocks-ship' );
$used_runtime = is_wp_error( $used_package ) ? '' : $used_package['files']['ssi-runtime/runtime.php'];
$assert( str_contains( $used_runtime, "require_once __DIR__ . '/includes/internal-link-runtime.php';" ) && str_contains( $used_runtime, "require_once __DIR__ . '/includes/product-listing-runtime.php';" ) && ! str_contains( $used_runtime, 'provider-form-runtime-v1.php' ), 'runtime-requires-exactly-shipped-includes' );

// Payloads that do not declare runtime features keep the full runtime.
$legacy = Static_Site_Importer_Generated_Runtime_Package::theme( $payload, 'photo-studio' );
$assert( ! is_wp_error( $legacy ) && array() === array_diff( $optional, array_keys( $legacy['files'] ) ), 'undeclared-payload-keeps-full-runtime' );

if ( $failures ) {
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo 'PASS: generated theme runtime ships ' . count( $shipped ) . " files for a site that uses no optional runtime\n";
