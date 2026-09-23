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
		array( 'path' => 'website/index.html', 'mime_type' => 'text/html', 'content' => '<link rel="preload" href="assets/app.js" as="script"><link rel="modulepreload" href="assets/module.mjs"><link rel="stylesheet" href="assets/site.css"><link rel="preload" href="assets/font.woff2" as="font"><main>Safe content</main><script>window.inline=true</script><script src="assets/app.js"></script><script src="https://cdn.example.test/app.js"></script><script type="module" src="assets/module.mjs"></script><script type="application/ld+json">{"@type":"Organization"}</script><script src="data:text/javascript,alert(1)"></script><script>window.gtag("config", "UA-test")</script>' ),
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
$assert( ! str_contains( $inert_html, 'modulepreload' ) && ! str_contains( $inert_html, 'as="script"' ) && str_contains( $inert_html, 'stylesheet' ) && str_contains( $inert_html, 'as="font"' ), 'inert-removes-script-preloads-only' );
$assert( array( 'data', 'data', 'inline', 'local', 'local', 'local', 'module', 'preload', 'preload', 'remote', 'telemetry' ) === $classes, 'inert-classifies-inline-local-remote-module-data-telemetry-and-preloads' );
$assert( 2 === count( $inert['report']['quarantined'] ) && 'data' === $inert['report']['quarantined'][0]['class'], 'data-is-quarantined-and-never-executed' );

$unproven = Static_Site_Importer_Client_Script_Policy::apply( $artifact, array( 'client_script_policy' => 'isolated_preview', 'client_script_provenance' => array( 'ref' => 'upload:sha256:abc123' ) ) );
$assert( 'inert' === $unproven['report']['policy'] && empty( $unproven['report']['preserved'] ), 'isolated-policy-without-runtime-isolation-remains-inert' );

$preview      = Static_Site_Importer_Client_Script_Policy::apply( $artifact, array( 'client_script_policy' => 'isolated_preview', 'client_script_isolated' => true, 'client_script_provenance' => array( 'ref' => 'upload:sha256:abc123' ) ) );
$preview_html = (string) ( $preview['artifact']['files'][0]['content'] ?? '' );
$assert( 'isolated_preview' === $preview['report']['policy'] && 'untrusted_imported_code' === $preview['report']['trust'] && 'upload:sha256:abc123' === $preview['report']['provenance'], 'isolated-policy-requires-and-records-provenance' );
$assert( str_contains( $preview_html, 'window.inline=true' ) && str_contains( $preview_html, 'modulepreload' ) && 11 === count( $preview['report']['preserved'] ) && empty( $preview['report']['dropped'] ) && empty( $preview['report']['quarantined'] ), 'isolated-preview-preserves-scripts-and-preloads-without-granting-trust' );

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

$dynamic_html     = '<main>Dynamic assets</main><script src="js/runtime.js"></script><script>window.inlineDynamic = document.createElement("script"); document.body.appendChild(document.createElement("img"));</script><script src="js/proven.js"></script>';
$dynamic_script   = 'var img = document.createElement("img"); img.src = "assets/built.png"; document.body.appendChild(img);';
$dynamic_artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array( 'path' => 'website/index.html', 'mime_type' => 'text/html', 'content' => $dynamic_html ),
		array( 'path' => 'website/js/runtime.js', 'mime_type' => 'application/javascript', 'content' => $dynamic_script ),
		array( 'path' => 'website/js/proven.js', 'mime_type' => 'application/javascript', 'content' => 'window.proven = true;' ),
	),
);
$dynamic_plan    = array(
	'reference_semantics' => array( 'dynamic_client_assets' => array( 'status' => 'not_proven' ) ),
	'reference_tokens'    => array( array( 'token' => 'asset-aaaaaaaaaaaaaaaa', 'target_path' => 'assets/js/runtime.js' ) ),
	'assets'              => array( array( 'target_path' => 'assets/js/runtime.js', 'source_path' => 'website/js/runtime.js' ) ),
	'pages'               => array(
		array(
			'source_path'        => 'website/index.html',
			'document_metadata'  => array(
				'scripts' => array(
					array( 'order' => 0, 'asset_reference' => '{{wordpress-site-plan:asset:asset-aaaaaaaaaaaaaaaa}}' ),
					array( 'order' => 1 ),
					array( 'order' => 2, 'asset_reference' => '{{wordpress-site-plan:asset:asset-bbbbbbbbbbbbbbbb}}' ),
				),
			),
		),
	),
	'diagnostics'         => array(
		array( 'code' => 'wordpress_site_plan_script_dynamic_references', 'source_path' => 'website/index.html#0' ),
		array( 'code' => 'wordpress_site_plan_script_dynamic_references', 'source_path' => 'website/index.html#1' ),
		array( 'code' => 'wordpress_site_plan_script_external_unproven', 'source_path' => 'website/index.html#2' ),
	),
);
$dynamic_loss    = Static_Site_Importer_Client_Script_Policy::drop_unproven_dynamic_scripts( $dynamic_artifact, $dynamic_plan );
$dynamic_files   = array_column( $dynamic_loss['artifact']['files'], null, 'path' );
$filtered_html   = (string) ( $dynamic_files['website/index.html']['content'] ?? '' );
$loss_rows       = $dynamic_loss['dropped'];
$asset_rows      = array_values( array_filter( $loss_rows, static fn( array $row ): bool => 'asset' === ( $row['type'] ?? '' ) ) );
$inline_rows     = array_values( array_filter( $loss_rows, static fn( array $row ): bool => 'inline' === ( $row['type'] ?? '' ) ) );

$assert( ! isset( $dynamic_files['website/js/runtime.js'] ) && isset( $dynamic_files['website/js/proven.js'] ), 'loss-pass-removes-only-the-unproven-script-file' );
$assert( ! str_contains( $filtered_html, 'runtime.js' ) && ! str_contains( $filtered_html, 'inlineDynamic' ) && str_contains( $filtered_html, 'js/proven.js' ) && str_contains( $filtered_html, 'Dynamic assets' ), 'loss-pass-strips-only-the-unproven-script-tags' );
$assert( 1 === count( $asset_rows ) && 'website/js/runtime.js' === $asset_rows[0]['path'] && 'website/index.html' === $asset_rows[0]['source_document'] && 'js/runtime.js' === $asset_rows[0]['src'] && hash( 'sha256', $dynamic_script ) === $asset_rows[0]['sha256'], 'loss-pass-reports-the-unproven-asset-row' );
$assert( 1 === count( $inline_rows ) && 'website/index.html' === $inline_rows[0]['path'] && 'inline' === $inline_rows[0]['type'], 'loss-pass-reports-the-unproven-inline-row' );
$assert( array( 'path', 'class', 'type', 'source_document', 'sha256' ) === array_keys( $inline_rows[0] ), 'loss-rows-keep-the-closed-report-row-shape' );

$proven_plan       = array( 'reference_semantics' => array( 'dynamic_client_assets' => array( 'status' => 'proven' ) ) );
$proven_plan_input = Static_Site_Importer_Client_Script_Policy::drop_unproven_dynamic_scripts( $dynamic_artifact, $proven_plan );
$assert( $dynamic_artifact === $proven_plan_input['artifact'] && array() === $proven_plan_input['dropped'], 'proven-plans-take-no-loss' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Client script policy smoke passed (%d assertions).\n", $assertions );
