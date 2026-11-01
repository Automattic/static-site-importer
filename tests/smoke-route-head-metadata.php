<?php
/** Route head metadata is written to WordPress owners at import time. */
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
function get_post_meta( int $id, string $key, bool $single = true ): array {
	return array(); }
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
$page['source_path']                = 'about.html';
$page['document_metadata']['title'] = 'Authored title';
$state                              = array(
	'ordered_pages' => array( $page ),
	'source_ids'    => array( 'about.html' => 1 ),
);
$GLOBALS['seo_written']             = array();
Static_Site_Importer_Route_Head_Metadata::materialize_platform_fields( $state );
$assert( array() === $GLOBALS['seo_written'], 'Absent SEO owners are not invented.' );
$assert( array( 'about.html' ) === ( $state['diagnostics'][0]['source_paths'] ?? null ) && 'route_description_unrendered' === $state['diagnostics'][0]['reason_code'], 'A description no owner renders is a named loss.' );
unset( $state['diagnostics'] );
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
$assert( ! isset( $state['diagnostics'] ), 'A registered owner renders the description, so nothing is lost.' );
echo "native route head metadata passed\n";
