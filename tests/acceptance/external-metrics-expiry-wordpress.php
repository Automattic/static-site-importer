<?php
/** A separate PHP request proves hourly cache expiry triggers a public refetch. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric expiry proof requires its disposable site.' ); }
$assert         = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} };
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$content_hash   = hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) );
$facts          = get_option( 'ssi_external_metric_acceptance_facts', array() );
$plugin_file    = (string) get_option( 'static_site_importer_active_companion_plugin', '' );
$runtime_file   = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/includes/external-metric-runtime.php';
$source         = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated local companion runtime source for the acceptance assertion.
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $class_match );
$runtime_class = $class_match[1] ?? '';
$assert( '' !== $runtime_class && class_exists( $runtime_class ), 'Standalone companion runtime loaded in a fresh PHP request.' );
$expires_after = time() + 3601;
$value         = call_user_func( array( $runtime_class, 'value' ), 'active-installs', array_column( $facts, null, 'id' ), null, $expires_after );
$assert( is_string( $value ) && '' !== $value, 'Expired WordPress.org hourly recipe cache triggers a fresh request.' );
$receipt = get_option( 'static_site_importer_external_metric_receipts', array() )['active-installs'] ?? array();
$assert( 'fresh' === ( $receipt['status'] ?? '' ) && ( $receipt['fetched_at'] ?? null ) === $expires_after, 'Expiry-triggered refetch writes a new fresh timestamped receipt.' );
$github_expires = time() + 86401;
$github_value   = call_user_func( array( $runtime_class, 'value' ), 'github-stars', array_column( $facts, null, 'id' ), null, $github_expires );
$github_receipt = get_option( 'static_site_importer_external_metric_receipts', array() )['github-stars'] ?? array();
$assert( is_string( $github_value ) && 'fresh' === ( $github_receipt['status'] ?? '' ) && ( $github_receipt['fetched_at'] ?? null ) === $github_expires, 'Expired GitHub daily recipe cache refreshes through the same source lifecycle.' );
$neutral_expires = time() + 601;
$neutral_value   = call_user_func( array( $runtime_class, 'value' ), 'neutral-score', array_column( $facts, null, 'id' ), null, $neutral_expires );
$neutral_receipt = get_option( 'static_site_importer_external_metric_receipts', array() )['neutral-score'] ?? array();
$assert( is_string( $neutral_value ) && 'fresh' === ( $neutral_receipt['status'] ?? '' ) && ( $neutral_receipt['fetched_at'] ?? null ) === $neutral_expires, 'Expired neutral ten-minute source recipe refreshes without a source-specific core branch.' );
$assert( hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) ) === $content_hash, 'Expiry-triggered fetch did not rewrite source post content.' );
echo wp_json_encode(
	array(
		'status'          => 'expiry_refetched',
		'value'           => $value,
		'receipt'         => $receipt,
		'github_receipt'  => $github_receipt,
		'neutral_receipt' => $neutral_receipt,
		'content_sha256'  => $content_hash,
	)
) . "\n";
