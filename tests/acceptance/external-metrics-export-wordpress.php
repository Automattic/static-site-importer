<?php
/** Export native external metrics as producer-contract runtime declarations. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric export test requires disposable WordPress.' ); }
require_once __DIR__ . '/blocks-engine-source-autoloader.php';
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$export         = Static_Site_Importer_Theme_Exporter::export_theme(
	array(
		'theme_slug'    => get_stylesheet(),
		'include_pages' => array( $metric_post_id ),
	)
);
if ( is_wp_error( $export ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is caught by the disposable CLI runner; export details are not rendered as HTML.
	throw new RuntimeException( $export->get_error_message() ); }
$artifact     = $export['website_artifact'] ?? array();
$declarations = $artifact['runtime_declarations'] ?? array();
$external     = array();
foreach ( $declarations as $declaration ) {
	if ( is_array( $declaration ) && 'external_metrics' === ( $declaration['type'] ?? '' ) ) {
		$external = $declaration['payload']['entities'] ?? array(); }
}
if ( 10 !== count( $external ) ) {
	throw new RuntimeException(
		'SSI export did not preserve all ten source-recipe-bound native metric declarations: ' . wp_json_encode(
			array(
				'declaration_count' => count( $declarations ),
				'metric_count'      => count( $external ),
				'diagnostics'       => $artifact['report']['diagnostics'] ?? array(),
			)
		)
	); }
file_put_contents( '/evidence/export.json', wp_json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable export fixture retained in the mounted evidence directory.
echo wp_json_encode(
	array(
		'status'       => 'exported',
		'artifact_id'  => $artifact['id'] ?? '',
		'metric_count' => count( $external ),
		'source_paths' => array_values( array_unique( array_column( $external[0]['bindings'] ?? array(), 'source_path' ) ) ),
	)
) . "\n";
