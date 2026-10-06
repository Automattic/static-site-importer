<?php
/** Reimport an SSI export on a second disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) { throw new RuntimeException( 'External metric reimport test requires disposable WordPress.' ); }
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/vendor/autoload.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/static-site-importer.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-theme-generator.php';
$artifact = json_decode( (string) file_get_contents( '/evidence/export.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads task-generated export artifact from the evidence mount.
if ( ! is_array( $artifact ) || empty( $artifact['runtime_declarations'] ) ) { throw new RuntimeException( 'SSI export lost external metric runtime declarations.' ); }
$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, array( 'slug' => 'ssi-external-metrics-reimport', 'name' => 'External Metrics Reimport', 'activate' => true, 'overwrite' => true ) );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) ); }
$receipt = $result['materialization_receipt'] ?? $result['receipt'] ?? array();
if ( 'completed' !== ( $receipt['status'] ?? '' ) ) { throw new RuntimeException( 'Second-site imported materialization receipt did not complete.' ); }
$pages = array_values( array_filter( $receipt['completed']['pages'] ?? array(), static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
if ( 1 !== count( $pages ) ) { throw new RuntimeException( 'Second-site receipt lacks its imported projects page.' ); }
update_option( 'ssi_external_metric_acceptance_post_id', $pages[0], false );
echo wp_json_encode( array( 'status' => 'reimported', 'core' => get_bloginfo( 'version' ), 'post_id' => $pages[0], 'companion' => get_option( 'static_site_importer_active_companion_plugin', '' ), 'runtime_declarations' => count( $artifact['runtime_declarations'] ) ) ) . "\n";
