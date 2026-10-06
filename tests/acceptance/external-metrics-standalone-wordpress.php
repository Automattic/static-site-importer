<?php
/** Re-render exported metrics after deactivating SSI and without Blocks Engine. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric standalone test requires disposable WordPress.' ); }
$assert         = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} };
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$content        = (string) get_post_field( 'post_content', $metric_post_id );
$hash           = hash( 'sha256', $content );
$rendered       = do_blocks( $content );
$assert( ! is_plugin_active( 'static-site-importer/static-site-importer.php' ), 'SSI is inactive on the second disposable site.' );
$assert( ! class_exists( 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\RuntimeDeclarations' ), 'Blocks Engine PHP transformer runtime is absent.' );
$assert( is_string( $rendered ) && '' !== $rendered, 'Standalone companion renders the imported page.' );
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
foreach ( array( 'project-count', 'active-installs', 'all-time-downloads', 'project-version', 'project-ratings' ) as $metric_id ) {
	$receipt = $receipts[ $metric_id ] ?? array();
	$assert( 'fresh' === ( $receipt['status'] ?? '' ) && ! empty( $receipt['value'] ), 'Standalone provider fetches and renders ' . $metric_id . ' without SSI or Blocks Engine.' );
}
$assert( hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) ) === $hash, 'Standalone fresh fetch preserves imported serialized post content.' );
echo wp_json_encode(
	array(
		'status'         => 'standalone_verified',
		'core'           => get_bloginfo( 'version' ),
		'post_id'        => $metric_post_id,
		'companion'      => get_option( 'static_site_importer_active_companion_plugin', '' ),
		'render_sha256'  => hash( 'sha256', $rendered ),
		'content_sha256' => $hash,
		'receipts'       => $receipts,
	)
) . "\n";
