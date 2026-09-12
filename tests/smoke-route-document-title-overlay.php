<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-route-document-metadata.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$result = Static_Site_Importer_Route_Document_Metadata::prepare_overlay(
	array( 'writes' => array() ),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => "<?php\n// Existing bootstrap.\n" ) ) )
);
$bootstrap = (string) ( $result['writes'][0]['content'] ?? '' );
$assert( 'materialized' === ( $result['status'] ?? '' ), 'Route document titles should always materialize into the portable theme bootstrap.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'Static Site Importer authored route document titles' ), 'The overlay should keep prior bootstrap code and add the title filter marker.' );
$assert( str_contains( $bootstrap, "add_filter( 'pre_get_document_title'" ) && str_contains( $bootstrap, 'is_front_page()' ) && str_contains( $bootstrap, '_static_site_importer_provenance' ), 'The portable theme bootstrap must project provenance document titles onto singular and front-page routes.' );

$repeat = Static_Site_Importer_Route_Document_Metadata::prepare_overlay(
	array(),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => $bootstrap ) ) )
);
$assert( 1 === substr_count( (string) ( $repeat['writes'][0]['content'] ?? '' ), 'Static Site Importer authored route document titles' ), 'The overlay should be idempotent.' );

echo "route document title overlay smoke passed\n";
