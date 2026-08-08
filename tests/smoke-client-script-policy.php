<?php
/**
 * Client-script policy contract coverage.
 *
 * Run from the repository root:
 * php tests/smoke-client-script-policy.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-client-script-policy.php';

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array( 'path' => 'website/index.html', 'mime_type' => 'text/html', 'content' => '<main>Safe content</main><script>window.inline=true</script><script src="assets/app.js"></script><script src="https://cdn.example.test/app.js"></script><script type="module" src="assets/module.mjs"></script><script type="application/ld+json">{"@type":"Organization"}</script><script src="data:text/javascript,alert(1)"></script><script>window.gtag("config", "UA-test")</script>' ),
		array( 'path' => 'website/assets/app.js', 'mime_type' => 'application/javascript', 'content' => 'window.local=true;' ),
		array( 'path' => 'website/assets/module.mjs', 'mime_type' => 'text/javascript', 'content' => 'export default true;' ),
		array( 'path' => 'website/assets/site.css', 'mime_type' => 'text/css', 'content' => 'main{color:green}' ),
	),
);

$inert      = Static_Site_Importer_Client_Script_Policy::apply( $artifact, array() );
$inert_html = (string) ( $inert['artifact']['files'][0]['content'] ?? '' );
$inert_rows = array_merge( $inert['report']['dropped'], $inert['report']['quarantined'] );
$classes    = array_column( $inert_rows, 'class' );
sort( $classes, SORT_STRING );
$paths = array_column( $inert['artifact']['files'], 'path' );

$assert( 'inert' === $inert['report']['policy'], 'default-is-inert' );
$assert( str_contains( $inert_html, 'Safe content' ) && ! str_contains( $inert_html, '<script' ), 'inert-removes-executable-and-data-markup' );
$assert( ! in_array( 'website/assets/app.js', $paths, true ) && ! in_array( 'website/assets/module.mjs', $paths, true ) && in_array( 'website/assets/site.css', $paths, true ), 'inert-removes-local-script-assets-only' );
$assert( array( 'data', 'data', 'inline', 'local', 'local', 'local', 'module', 'remote', 'telemetry' ) === $classes, 'inert-classifies-inline-local-remote-module-data-and-telemetry' );
$assert( 2 === count( $inert['report']['quarantined'] ) && 'data' === $inert['report']['quarantined'][0]['class'], 'data-is-quarantined-and-never-executed' );

$unproven = Static_Site_Importer_Client_Script_Policy::apply( $artifact, array( 'client_script_policy' => 'isolated_preview', 'client_script_provenance' => array( 'ref' => 'upload:sha256:abc123' ) ) );
$assert( 'inert' === $unproven['report']['policy'] && empty( $unproven['report']['preserved'] ), 'isolated-policy-without-runtime-isolation-remains-inert' );

$preview      = Static_Site_Importer_Client_Script_Policy::apply( $artifact, array( 'client_script_policy' => 'isolated_preview', 'client_script_isolated' => true, 'client_script_provenance' => array( 'ref' => 'upload:sha256:abc123' ) ) );
$preview_html = (string) ( $preview['artifact']['files'][0]['content'] ?? '' );
$assert( 'isolated_preview' === $preview['report']['policy'] && 'untrusted_imported_code' === $preview['report']['trust'] && 'upload:sha256:abc123' === $preview['report']['provenance'], 'isolated-policy-requires-and-records-provenance' );
$assert( str_contains( $preview_html, 'window.inline=true' ) && 9 === count( $preview['report']['preserved'] ) && empty( $preview['report']['dropped'] ) && empty( $preview['report']['quarantined'] ), 'isolated-preview-preserves-scripts-without-granting-trust' );

$base64_html     = '<main>Base64 content</main><script src="js/main.js"></script>';
$base64_script   = 'window.base64Script=true;';
$base64_artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array( 'path' => 'website/index.html', 'mime_type' => 'text/html', 'content_base64' => base64_encode( $base64_html ) ),
		array( 'path' => 'website/js/main.js', 'mime_type' => 'application/javascript', 'content_base64' => base64_encode( $base64_script ) ),
	),
);
$base64_inert    = Static_Site_Importer_Client_Script_Policy::apply( $base64_artifact, array() );
$base64_files    = array_column( $base64_inert['artifact']['files'], null, 'path' );
$base64_dropped  = array_column( $base64_inert['report']['dropped'], null, 'path' );
$base64_filtered = base64_decode( (string) ( $base64_files['website/index.html']['content_base64'] ?? '' ), true );

$assert( is_string( $base64_filtered ) && str_contains( $base64_filtered, 'Base64 content' ) && ! str_contains( $base64_filtered, '<script' ) && ! isset( $base64_files['website/index.html']['content'] ), 'inert-filters-base64-html-in-place' );
$assert( ! isset( $base64_files['website/js/main.js'] ), 'inert-removes-base64-script-assets' );
$assert( hash( 'sha256', $base64_script ) === ( $base64_dropped['website/js/main.js']['sha256'] ?? '' ), 'base64-script-report-hashes-decoded-bytes' );

$base64_preview = Static_Site_Importer_Client_Script_Policy::apply( $base64_artifact, array( 'client_script_policy' => 'isolated_preview', 'client_script_isolated' => true, 'client_script_provenance' => 'fixture:base64' ) );
$assert( $base64_artifact['files'] === $base64_preview['artifact']['files'], 'isolated-preview-preserves-base64-artifact-bytes' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Client script policy smoke passed (%d assertions).\n", $assertions );
