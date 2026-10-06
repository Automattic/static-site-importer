<?php
/** Prove public fetch, native binding render and no post rewrite. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric verification must run in its disposable WordPress site.' ); }
$assert         = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} };
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$facts          = get_option( 'ssi_external_metric_acceptance_facts', array() );
$assert( $metric_post_id > 0 && is_array( $facts ) && 5 === count( $facts ), 'A completed five-fact imported page is persisted.' );
$post_content = (string) get_post_field( 'post_content', $metric_post_id );
$before_hash  = hash( 'sha256', $post_content );
$rendered     = do_blocks( $post_content );
$assert( is_string( $rendered ) && '' !== $rendered, 'WordPress renders persisted native text blocks.' );
foreach ( $facts as $fact ) {
	$assert( ! str_contains( $rendered, (string) $fact['fallback']['text'] ), 'Fresh WordPress.org value replaces fallback for ' . $fact['id'] ); }
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
foreach ( $facts as $fact ) {
	$row = $receipts[ $fact['id'] ] ?? array();
	$assert( 'fresh' === ( $row['status'] ?? '' ) && is_int( $row['fetched_at'] ?? null ) && ! empty( $row['value'] ), 'Timestamped fresh provider receipt exists for ' . $fact['id'] ); }
$plugin_file  = (string) get_option( 'static_site_importer_active_companion_plugin', '' );
$runtime_file = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/includes/external-metric-runtime.php';
$source       = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated local companion runtime source for the acceptance assertion.
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $class_match );
$runtime_class = $class_match[1] ?? '';
$assert( '' !== $runtime_class && class_exists( $runtime_class ), 'Generated companion owns its independent provider runtime.' );
$assert( hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) ) === $before_hash, 'Fetch and cache expiry refresh leave saved post content unchanged.' );
echo wp_json_encode(
	array(
		'status'         => 'verified',
		'core'           => get_bloginfo( 'version' ),
		'post_id'        => $metric_post_id,
		'companion'      => $plugin_file,
		'content_sha256' => $before_hash,
		'receipts'       => $receipts,
	)
) . "\n";
