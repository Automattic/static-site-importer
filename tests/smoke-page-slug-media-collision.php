<?php
/**
 * A page keeps its source-route slug when a Media Library image shares its basename.
 *
 * Media resolves before pages materialize, and WordPress gives top-level pages
 * and attachments one slug namespace. The page owns the route.
 *
 * @package StaticSiteImporter
 */

require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion evidence.
	}
};
$png      = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixture image bytes.
$basename = 'photo-' . strtolower( wp_generate_password( 6, false, false ) );
$record   = array(
	'source_urls'       => array( "https://example.org/{$basename}.png" ),
	'resolved_original' => "https://example.org/{$basename}.png",
	'original'          => array( 'source_url' => "https://example.org/{$basename}.png", 'content_type' => 'image/png', 'width' => 20, 'height' => 20, 'alpha' => false, 'sha256' => hash( 'sha256', $png ) ),
	'references'        => array( array( 'kind' => 'content', 'document_url' => "https://example.org/{$basename}.html", 'location' => 'img:0', 'source_url' => "https://example.org/{$basename}.png" ) ),
);
$artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array( 'path' => 'website/index.html', 'content' => "<html><head><title>Home</title></head><body><main><h1>Home</h1><p><a href=\"{$basename}.html\">Photo</a></p></main></body></html>" ),
		array( 'path' => "website/{$basename}.html", 'content' => "<html><head><title>Photo</title></head><body><main><h1>Photo</h1><p><img src=\"{$basename}.png\" width=\"120\" height=\"160\" alt=\"\"></p></main></body></html>" ),
		array( 'path' => "website/{$basename}.png", 'content_base64' => base64_encode( $png ) ),
		array( 'path' => 'image-assets.json', 'content' => wp_json_encode( array( 'version' => 1, 'images' => array( $record ) ) ) ),
	),
);
$args     = array( 'slug' => 'ssi-slug-' . strtolower( wp_generate_password( 8, false, false ) ), 'name' => 'Slug collision fixture', 'activate' => false, 'defer_materialization_commit' => true );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $compiled ), 'Compilation failed: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
try {
	$assert( 'completed' === $receipt['status'], 'Import failed: ' . wp_json_encode( $receipt ) );
	$state       = $receipt['transaction']->state;
	$page        = (int) ( $state['source_ids'][ "website/{$basename}.html" ] ?? 0 );
	$attachments = $state['applied']['attachments'] ?? array();
	$assert( 1 === count( $attachments ), 'The page image must still become one Media Library attachment.' );
	$assert( $basename === get_post_field( 'post_name', $page ), 'The page must keep its source-route slug, got ' . get_post_field( 'post_name', $page ) . '.' );
	$assert( $basename . '-2' === get_post_field( 'post_name', $attachments[0] ), 'The attachment yields the slug as it would to an existing page.' );
	$assert( str_contains( get_post_field( 'post_content', $page ), 'wp-image-' . $attachments[0] ), 'The page must reference its attachment.' );
} finally {
	Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, 'fixture_cleanup' );
}
echo "Page slug media collision passed.\n";
