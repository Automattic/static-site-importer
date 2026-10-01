<?php
/** Independent disposable-runtime oracle for native branding application. */
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Disposable fixture creates explicit core query contexts to exercise native block and PHP template rendering.
if ( ! defined( 'SSI_NATIVE_IDENTITY_DISPOSABLE_TEST' ) || true !== SSI_NATIVE_IDENTITY_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Run this fixture only in the declared disposable WordPress workload' );
}
wp_set_current_user( 1 );
require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';
if ( defined( 'SSI_NATIVE_TEMPLATE_ORACLE' ) && SSI_NATIVE_TEMPLATE_ORACLE ) {
	if ( ! is_readable( '/wordpress/wp-content/plugins/owning-compiler/vendor/autoload.php' ) ) {
		throw new RuntimeException( 'Owning compiler dependency overlay must be mounted' );
	}
	require_once '/wordpress/wp-content/plugins/owning-compiler/vendor/autoload.php';
}
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

function identity_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}
function identity_png_chunk( string $type, string $bytes ): string {
	return pack( 'N', strlen( $bytes ) ) . $type . $bytes . pack( 'N', crc32( $type . $bytes ) );
}
$png                             = "\x89PNG\r\n\x1a\n"
	. identity_png_chunk( 'IHDR', pack( 'NNCCCCC', 512, 512, 8, 2, 0, 0, 0 ) )
	. identity_png_chunk( 'IDAT', gzcompress( str_repeat( "\0" . str_repeat( "\x14\x28\x3c", 512 ), 512 ) ) )
	. identity_png_chunk( 'IEND', '' );
$artifact                        = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><html><head><title>Identity Fixture</title><link rel="manifest" href="app.webmanifest"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Identity Fixture","logo":"assets/brand.png","slogan":"Care & clarity"}</script><meta name="description" content="This description is not a tagline"></head><body><header><a href="/">Identity Fixture</a></header><main><h1>Identity fixture content</h1></main></body></html>',
		),
		array(
			'path'    => 'website/app.webmanifest',
			'content' => '{"name":"Identity Fixture","icons":[{"src":"assets/brand.png","sizes":"512x512","type":"image/png"}]}',
		),
		array(
			'path'           => 'website/assets/brand.png',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary PNG fixture uses the artifact's portable base64 content transport.
			'content_base64' => base64_encode( $png ),
		),
	),
);
$artifact['files'][0]['content'] = str_replace( '</body>', '<footer><p>Shared fixture footer</p></footer></body>', $artifact['files'][0]['content'] );
$artifact['files'][]             = array(
	'path'    => 'website/about.html',
	'content' => str_replace( '<h1>Identity fixture content</h1>', '<h1>About fixture content</h1>', $artifact['files'][0]['content'] ),
);
delete_option( 'site_logo' );
delete_option( 'site_icon' );
update_option( 'blogdescription', '' );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, array(
	'slug'     => 'native-identity-oracle',
	'activate' => true,
) );
identity_assert( ! is_wp_error( $compiled ), 'Identity artifact must compile: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_code() . ' ' . $compiled->get_error_message() : '' ) );
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
identity_assert( 'completed' === ( $receipt['status'] ?? '' ), 'Identity materialization must complete: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
$logo = (int) get_option( 'site_logo' );
$icon = (int) get_option( 'site_icon' );
identity_assert( $logo > 0 && 'attachment' === get_post_type( $logo ), 'Native site_logo must point at a real attachment' );
identity_assert( $icon > 0 && 'attachment' === get_post_type( $icon ), 'Manifest icon must become a real site_icon attachment' );
identity_assert( $logo === $icon, 'Identical logo/icon bytes must share an attachment' );
identity_assert( (int) get_theme_mod( 'custom_logo' ) === $logo, 'Core native logo setting must reach theme_mod_custom_logo' );
identity_assert( '' !== get_custom_logo(), 'Core custom logo renderer must produce image markup' );
identity_assert( '' !== get_site_icon_url(), 'Core site icon renderer must expose the icon URL' );
identity_assert( 'Care & clarity' === html_entity_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES | ENT_HTML5 ), 'Explicit slogan must become blogdescription' );

if ( defined( 'SSI_NATIVE_TEMPLATE_ORACLE' ) && SSI_NATIVE_TEMPLATE_ORACLE ) {
	$compiler_source = ( new ReflectionClass( Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::class ) )->getFileName();
	identity_assert( str_starts_with( $compiler_source, '/wordpress/wp-content/plugins/owning-compiler/' ), 'Template oracle must exercise the owning compiler candidate' );
	$theme = get_stylesheet();
	foreach ( array( 'single', 'archive', '404' ) as $slug ) {
		identity_assert( null !== get_block_template( $theme . '//' . $slug ), 'Native lifecycle template must be discoverable: ' . $slug );
	}
	function identity_render_template( string $slug, array $query ): string {
		global $wp_query, $post;
		$wp_query = new WP_Query( $query );
		$post     = $wp_query->post;
		if ( $post ) {
			setup_postdata( $post );
		}
		$template = get_block_template( get_stylesheet() . '//' . $slug );
		return do_blocks( $template->content );
	}
	$category = wp_insert_term( 'Identity Archive', 'category' );
	identity_assert( ! is_wp_error( $category ), 'Archive fixture category must exist' );
	$native_post = wp_insert_post( array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'Native future post title',
		'post_content'  => '<!-- wp:paragraph --><p>Native future post body oracle.</p><!-- /wp:paragraph -->',
		'post_category' => array( $category['term_id'] ),
	) );
	$other_post  = wp_insert_post( array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Other archive excluded title',
		'post_content' => 'Other archive excluded body.',
	) );
	$single_html = identity_render_template( 'single', array( 'p' => $native_post ) );
	identity_assert( str_contains( $single_html, 'Native future post title' ) && str_contains( $single_html, 'Native future post body oracle.' ), 'Native future post must render both its title and stored body' );
	$archive_html = identity_render_template( 'archive', array( 'cat' => $category['term_id'] ) );
	identity_assert( str_contains( $archive_html, 'Native future post title' ) && ! str_contains( $archive_html, 'Other archive excluded title' ), 'Archive must inherit the current category instead of querying all posts' );
	identity_assert( str_contains( $archive_html, 'Identity Archive' ), 'Archive must show a contextual native title' );
	$empty_html = identity_render_template( 'archive', array(
		'cat'      => $category['term_id'],
		'post__in' => array( PHP_INT_MAX ),
	) );
	identity_assert( str_contains( $empty_html, 'No posts found.' ), 'Empty inherited query must render no-results content' );
	$missing_html = identity_render_template( '404', array( 'p' => PHP_INT_MAX ) );
	identity_assert( str_contains( $missing_html, 'Page not found' ) && str_contains( $missing_html, 'type="search"' ) && ! str_contains( $missing_html, 'Native future post body oracle.' ), 'Missing route must render native search recovery rather than post content or a listing' );
	identity_assert( str_contains( $missing_html, 'Shared fixture footer' ) && str_contains( $archive_html, 'Shared fixture footer' ), 'Source-proven shared chrome must survive native fallback routes' );
	echo "Native WordPress single/archive/404 rendering passed.\n";

	$classic = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, array(
		'slug'                  => 'native-identity-classic',
		'activate'              => true,
		'theme_materialization' => 'classic',
	) );
	identity_assert( ! is_wp_error( $classic ), 'Classic artifact must compile' );
	$classic_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $classic['plan'], $classic['args'] );
	identity_assert( 'completed' === ( $classic_receipt['status'] ?? '' ), 'Classic lifecycle materialization must complete' );
	require_once get_stylesheet_directory() . '/functions.php';
	function identity_render_classic( string $file, array $query ): string {
		global $wp_query, $post;
		// Model the fresh frontend request after switching themes; WordPress's
		// cached template directories otherwise still name the earlier theme.
		wp_set_template_globals();
		$wp_query = new WP_Query( $query );
		$post     = $wp_query->post;
		ob_start();
		include get_stylesheet_directory() . '/' . $file;
		return (string) ob_get_clean();
	}
	// Core loads header/footer with require_once within one PHP request. Check
	// captured chrome on the first render, before probing other query bodies.
	$classic_home = identity_render_classic( 'front-page.php', array( 'page_id' => (int) get_option( 'page_on_front' ) ) );
	identity_assert( str_contains( $classic_home, 'Identity fixture content' ) && str_contains( $classic_home, 'Shared fixture footer' ), 'Classic captured homepage must retain its captured content/chrome' );
	$classic_single = identity_render_classic( 'single.php', array( 'p' => $native_post ) );
	identity_assert( str_contains( $classic_single, 'Native future post title' ) && str_contains( $classic_single, 'Native future post body oracle.' ), 'Classic native future post must render its title and body' );
	$classic_archive = identity_render_classic( 'archive.php', array( 'cat' => $category['term_id'] ) );
	identity_assert( str_contains( $classic_archive, 'Identity Archive' ) && str_contains( $classic_archive, 'Native future post title' ) && ! str_contains( $classic_archive, 'Other archive excluded title' ), 'Classic archive must preserve contextual title and inherited category scope' );
	$classic_missing = identity_render_classic( '404.php', array( 'p' => PHP_INT_MAX ) );
	identity_assert( str_contains( $classic_missing, 'Page not found' ) && str_contains( $classic_missing, 'type="search"' ) && ! str_contains( $classic_missing, 'Native future post body oracle.' ), 'Classic missing route must provide real search recovery' );
	echo "Classic native content/archive/404 and captured homepage rendering passed.\n";
}
$before_ids = get_posts( array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'numberposts' => -1,
	'fields'      => 'ids',
) );
$retry      = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
identity_assert( 'completed' === ( $retry['status'] ?? '' ), 'Identity retry must reconcile' );
identity_assert( get_posts( array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'numberposts' => -1,
	'fields'      => 'ids',
) ) === $before_ids, 'Retry must not duplicate identity attachments' );

// A second source must preserve the owner's chosen identity.
update_option( 'blogdescription', 'Owner tagline' );
$compiled_owner = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, array(
	'slug'     => 'native-identity-owner',
	'activate' => true,
) );
$owner_receipt  = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled_owner['plan'], $compiled_owner['args'] );
identity_assert( 'completed' === ( $owner_receipt['status'] ?? '' ), 'Owner-preserving import must complete' );
identity_assert( (int) get_option( 'site_logo' ) === $logo && (int) get_option( 'site_icon' ) === $icon, 'Existing owner-selected images must remain' );
identity_assert( 'Owner tagline' === get_option( 'blogdescription' ), 'Existing owner tagline must remain' );

// No description-to-tagline inference; activation failure restores native values.
delete_option( 'site_logo' );
delete_option( 'site_icon' );
update_option( 'blogdescription', '' );
$compiled_rollback = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, array(
	'slug'                           => 'native-identity-rollback',
	'activate'                       => true,
	'inject_materialization_failure' => 'after_blogname',
) );
$rollback_receipt  = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled_rollback['plan'], $compiled_rollback['args'] );
identity_assert( 'completed' !== ( $rollback_receipt['status'] ?? '' ), 'Injected failure must reject completion' );
identity_assert( 0 === (int) get_option( 'site_logo', 0 ) && 0 === (int) get_option( 'site_icon', 0 ) && '' === get_option( 'blogdescription' ), 'Rollback must restore native branding options' );
identity_assert( get_posts( array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'numberposts' => -1,
	'fields'      => 'ids',
) ) === $before_ids, 'Rollback must remove only newly created identity attachments' );
echo wp_json_encode( array(
	'status'             => 'passed',
	'logo_id'            => $logo,
	'icon_id'            => $icon,
	'deduplicated'       => true,
	'native_rendering'   => true,
	'owner_preservation' => true,
	'rollback'           => true,
) ) . "\n";
