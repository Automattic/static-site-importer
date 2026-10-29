<?php
/** Native route head projection: edits to WordPress owners win over provenance. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-route-head-metadata.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-route-document-metadata.php';
function get_registered_meta_keys( string $type, string $subtype = '' ): array {
	return $GLOBALS['registered_seo'] ?? array();
}
function get_post_type( int $id ): string {
	return 'page'; }
final class Static_Site_Importer_Site_Plan_Persistence {
	public static function write_post_meta( int $id, string $key, string $value ): bool {
		$GLOBALS['seo_written'][ $key ] = $value;
		return true;
	}
}
function is_singular(): bool {
	return true; }
function get_queried_object_id(): int {
	return 1; }
function get_post_meta( int $id, string $key, bool $single = true ): string {
	return '{"head_metadata":[{"key":"og:url","content":"https://source.example/"}]}'; }
function get_post_field( string $field, int $id ): string {
	return 'post_excerpt' === $field ? 'Edited description' : 'Edited title'; }
function get_the_title( int $id ): string {
	return 'Edited title'; }
function get_permalink( int $id ): string {
	return 'https://destination.example/about/'; }
function get_the_post_thumbnail_url( int $id, string $size ): string {
	return 'https://destination.example/uploads/social.png'; }
function has_action( string $hook, string $callback ): bool {
	return ! empty( $GLOBALS['platform'] ); }
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( string $value ): string {
	return $value; }
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
};
$page   = array(
	'document_metadata' => array(
		'meta' => array(
			array(
				'property' => 'og:description',
				'content'  => 'Social fallback',
			),
			array(
				'name'    => 'description',
				'content' => 'Authored &amp; plain',
			),
			array(
				'name'    => 'robots',
				'content' => 'noindex',
			),
		),
	),
);
$assert( 'Authored & plain' === Static_Site_Importer_Route_Head_Metadata::description_from_page( $page ), 'Description has deterministic precedence and entity decoding.' );
ob_start();
Static_Site_Importer_Route_Head_Metadata::print_tags();
$html = ob_get_clean();
$assert( str_contains( $html, 'Edited title' ) && str_contains( $html, 'Edited description' ), 'Native owners render, not stale provenance.' );
$assert( str_contains( $html, '/uploads/social.png' ) && ! str_contains( $html, 'source.example' ), 'Image and URL come from WordPress.' );
$assert( 1 === substr_count( $html, 'name="description"' ), 'One description.' );
$GLOBALS['static_site_importer_head_metadata_emitted'] = false;
$GLOBALS['platform']                                   = true;
ob_start();
Static_Site_Importer_Route_Head_Metadata::print_tags();
$assert( '' === ob_get_clean(), 'Platform remains sole emitter when registered.' );
$page['source_path']                = 'about.html';
$page['document_metadata']['title'] = 'Authored title';
$state                              = array(
	'ordered_pages' => array( $page ),
	'source_ids'    => array( 'about.html' => 1 ),
);
$GLOBALS['seo_written']             = array();
Static_Site_Importer_Route_Head_Metadata::materialize_platform_fields( $state );
$assert( array() === $GLOBALS['seo_written'], 'Absent SEO owners are not invented.' );
$GLOBALS['registered_seo'] = array(
	'advanced_seo_description' => array(),
	'jetpack_seo_html_title'   => array(),
);
Static_Site_Importer_Route_Head_Metadata::materialize_platform_fields( $state );
$assert(
	array(
		'jetpack_seo_html_title'   => 'Authored title',
		'advanced_seo_description' => 'Authored & plain',
	) === $GLOBALS['seo_written'],
	'Registered platform owners receive authored values, not content-generated descriptions.'
);
$assert( isset( $state['rollback']['route_seo'][1]['advanced_seo_description'] ), 'Platform fields participate in compensation.' );
echo "native route head metadata passed\n";
