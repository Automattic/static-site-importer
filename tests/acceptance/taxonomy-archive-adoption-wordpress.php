<?php
/** Isolated real WordPress adoption/rejection/rollback transaction proof. */
if ( ! defined( 'SSI_TAXONOMY_DISPOSABLE_TEST' ) || true !== SSI_TAXONOMY_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Run taxonomy adoption acceptance only in the declared disposable runtime.' );
}
wp_set_current_user( 1 );
$bundle = json_decode( (string) file_get_contents( '/wordpress/wp-content/uploads/ssi-taxonomy-evidence/taxonomy-adoption-plan.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the host-mounted accepted producer plan in this disposable runtime.
if ( ! is_array( $bundle ) || ! is_array( $bundle['plan'] ?? null ) || ! is_array( $bundle['args'] ?? null ) ) {
	throw new RuntimeException( 'The paired producer plan artifact could not be loaded.' );
}
$autoload = require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';
$engine   = '/wordpress/wp-content/plugins/blocks-engine-candidate';
$autoload->setPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', $engine . '/src/', true );
if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
	require_once $engine . '/php-transformer.php';
}
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion output is evidence, not rendered HTML.
	}
};
$plan     = $bundle['plan'];
$args     = $bundle['args'];
$archive  = array_values( array_filter( $plan['pages'] ?? array(), static fn( array $page ): bool => 'archives/personal.html' === ( $page['source_path'] ?? '' ) ) )[0] ?? array();
$route    = trim( (string) ( $plan['taxonomy_entities'][0]['archive']['source_route'] ?? '' ), '/' );
$segments = explode( '/', $route );
$parent   = 0;
$parent_path = '';
foreach ( array_slice( $segments, 0, -1 ) as $segment ) {
	$parent_path = '' === $parent_path ? $segment : $parent_path . '/' . $segment;
	$parent_plan = array_values( array_filter( $plan['pages'] ?? array(), static fn( array $page ): bool => trim( (string) ( $page['route']['path'] ?? '' ), '/' ) === $parent_path ) )[0] ?? array();
	$assert( is_string( $parent_plan['reconciliation_identity'] ?? null ), 'The producer plan must contain a canonical reconciled archive ancestor at ' . $parent_path );
	$parent = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ucfirst( $segment ), 'post_name' => $segment, 'post_parent' => $parent ), true );
	$assert( $parent > 0, 'Could not create the prior archive path ancestry.' );
	update_post_meta( $parent, '_static_site_importer_reconciliation_identity', $parent_plan['reconciliation_identity'] );
}
$frozen_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Prior frozen archive', 'post_name' => (string) end( $segments ), 'post_parent' => $parent, 'post_content' => 'Prior archive body must be restored byte-for-byte.' ), true );
$assert( $frozen_id > 0 && is_string( $archive['reconciliation_identity'] ?? null ), 'Prior archive page or its source reconciliation identity is unavailable.' );
$identity = (string) $archive['reconciliation_identity'];
update_post_meta( $frozen_id, '_static_site_importer_reconciliation_identity', $identity );
$route_conflicts = array();
foreach ( $plan['pages'] ?? array() as $planned_page ) {
	$planned_route = trim( (string) ( $planned_page['route']['path'] ?? '' ), '/' );
	$planned_type  = (string) ( $planned_page['post_type'] ?? 'page' );
	$existing_page = '' === $planned_route ? null : get_page_by_path( $planned_route, OBJECT, $planned_type );
	if ( $existing_page && (string) get_post_meta( $existing_page->ID, '_static_site_importer_reconciliation_identity', true ) !== (string) ( $planned_page['reconciliation_identity'] ?? '' ) ) {
		$route_conflicts[] = array( 'route' => $planned_route, 'type' => $planned_type, 'post_id' => (int) $existing_page->ID, 'status' => get_post_status( $existing_page->ID ), 'source' => $planned_page['source_path'] ?? '' );
	}
}
$before = get_post( $frozen_id, ARRAY_A );
$failure_args = $args;
$failure_args['slug'] = 'taxonomy-adoption-late-failure';
$failure_args['inject_materialization_failure'] = 'theme_write_short';
$failure = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $failure_args );
$after = get_post( $frozen_id, ARRAY_A );
$assert( 'partial' === ( $failure['status'] ?? '' ) && 'theme_write_failed' === ( $failure['errors'][0]['code'] ?? '' ), 'Late failure did not exercise transactional rollback; pre-existing non-reconciled routes: ' . wp_json_encode( $route_conflicts ) . '; receipt: ' . wp_json_encode( array( 'status' => $failure['status'] ?? null, 'errors' => $failure['errors'] ?? array() ) ) );
$assert( 'publish' === get_post_status( $frozen_id ) && $before['post_title'] === $after['post_title'] && $before['post_content'] === $after['post_content'] && (int) $before['post_parent'] === (int) $after['post_parent'] && $before['post_name'] === $after['post_name'] && $identity === get_post_meta( $frozen_id, '_static_site_importer_reconciliation_identity', true ), 'Late failure did not restore prior frozen archive content, route ancestry, status, and reconciliation ownership.' );

$adopted = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $args );
$assert( 'completed' === ( $adopted['status'] ?? '' ) && 'draft' === get_post_status( $frozen_id ) && ! metadata_exists( 'post', $frozen_id, '_static_site_importer_reconciliation_identity' ), 'Reconciliation-matched frozen archive page was not retired on successful adoption.' );
$owner_body = 'Destination-owned archive remains untouched.';
wp_update_post( array( 'ID' => $frozen_id, 'post_status' => 'publish', 'post_content' => $owner_body ) );
$rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $args );
$assert( 'rejected' === ( $rejected['status'] ?? '' ) && 'taxonomy_archive_route_conflict' === ( $rejected['errors'][0]['code'] ?? '' ), 'Destination-owned archive route was not rejected: ' . wp_json_encode( $rejected ) );
$assert( 'publish' === get_post_status( $frozen_id ) && $owner_body === get_post_field( 'post_content', $frozen_id ) && ! metadata_exists( 'post', $frozen_id, '_static_site_importer_reconciliation_identity' ), 'Destination-owned archive changed during conflict rejection.' );
echo 'WordPress ' . esc_html( get_bloginfo( 'version' ) ) . "\n";
echo "Taxonomy archive adoption, destination conflict, and exact late rollback acceptance passed.\n";
