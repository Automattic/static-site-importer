<?php
/**
 * Smoke test: the REST import adapter forwards normalized importer input.
 *
 * Run inside a WordPress site:
 * wp eval-file tests/smoke-rest-import-normalization.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$options = array(
	'slug'                         => 'REST Import!!!',
	'name'                         => '<strong>REST Import</strong>',
	'site_title'                   => 'REST Site',
	'stale_page_action'            => 'draft',
	'activate'                     => true,
	'overwrite'                    => true,
	'fail_on_quality'              => true,
	'allow_missing_woocommerce'    => true,
	'allow_missing_jetpack'        => true,
	'materialize_dependencies'     => false,
	'seed_entities'                => true,
	'products_manifest'            => array( 'products' => array( array( 'sku' => 'rest-product' ) ) ),
	'commerce_context'             => array( 'currency' => 'USD' ),
	'write_theme_report_artifacts' => true,
	'asset_materialization_policy' => 'use_map',
	'asset_map'                    => array( 'logo.svg' => 'https://example.test/logo.svg' ),
	'compiler_options'             => array( 'include_conversion_report' => false ),
	'source_metadata'              => array( 'request_id' => 'rest-normalization-smoke', 'source' => 'caller' ),
	'validation_artifacts'         => array( 'visual_diff' => array( 'ref' => 'rest-diff' ) ),
);
$artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array( 'path' => 'website/index.html', 'content' => '<main><h1>REST smoke</h1></main>' ),
	),
);
$captured = array();

add_filter( 'static_site_importer_can_manage_imports', '__return_true' );
add_filter(
	'static_site_importer_import_disposition',
	static function ( $disposition, array $seen_artifact, array $seen_input, array $context ) use ( &$captured ) {
		$captured = array(
			'artifact' => $seen_artifact,
			'input'    => $seen_input,
			'context'  => $context,
		);

		return array( 'success' => true, 'mode' => 'captured' );
	},
	10,
	4
);

do_action( 'rest_api_init' );
$request = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array_merge( $options, array( 'source' => array( 'artifact' => $artifact ) ) ) ) );
$response = rest_get_server()->dispatch( $request );

$assert( 200 === $response->get_status(), 'rest-response-success', (string) $response->get_status() );
$assert( $artifact === ( $captured['artifact'] ?? null ), 'disposition-receives-provided-artifact' );
$assert( 'playground' === ( $captured['context']['mode'] ?? '' ), 'rest-defaults-to-playground-mode' );
$assert( $artifact === ( $captured['context']['source']['artifact'] ?? null ), 'rest-context-preserves-source' );

foreach ( array_keys( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES ) as $field ) {
	if ( in_array( $field, array( 'slug', 'name', 'source_metadata' ), true ) ) {
		continue;
	}
	$assert( $options[ $field ] === ( $captured['input'][ $field ] ?? null ), 'canonical-option-' . $field );
}

$assert( 'rest-import' === ( $captured['input']['slug'] ?? '' ), 'slug-is-rest-sanitized' );
$assert( 'REST Import' === ( $captured['input']['name'] ?? '' ), 'name-is-rest-sanitized' );
$assert( 'rest-normalization-smoke' === ( $captured['input']['source_metadata']['request_id'] ?? '' ), 'caller-source-metadata-is-preserved' );
$assert( 'static_site_importer_block' === ( $captured['input']['source_metadata']['source'] ?? '' ), 'rest-source-metadata-is-applied' );
$assert( ! array_key_exists( 'report', $captured['input'] ?? array() ), 'rest-rejects-report-destination' );

$rejected_report_params = static_site_importer_rest_import_args( array( 'report' => '/tmp/report.json' ) );
$assert( ! array_key_exists( 'report', $rejected_report_params ), 'rest-schema-rejects-report-destination' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: REST import normalization smoke passed (' . $assertions . " assertions)\n";
