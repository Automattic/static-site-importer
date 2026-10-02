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

// Native vector handoff exercises actual core rendering and attachment bytes.
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><defs><linearGradient id="brand"><stop offset="0" stop-color="#14283c"/><stop offset="1" stop-color="#b0d0f0"/></linearGradient></defs><rect width="512" height="512" fill="url(#brand)"/></svg>';

$svg_artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<html><head><title>Vector Identity</title><link rel="icon" href="brand.svg"><script type="application/ld+json">{"@type":"Organization","logo":"brand.svg"}</script></head><body><h1>Vector identity</h1></body></html>',
		),
		array(
			'path'      => 'website/brand.svg',
			'content'   => $svg,
			'mime_type' => 'image/svg+xml',
		),
	),
);

$svg_upload_policy = wp_check_filetype( 'unrelated.svg' );
$svg_compiled      = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $svg_artifact, array(
	'slug'     => 'vector-identity-oracle',
	'activate' => true,
) );
identity_assert( ! is_wp_error( $svg_compiled ), 'SVG identity must compile: ' . ( is_wp_error( $svg_compiled ) ? $svg_compiled->get_error_message() : '' ) );
$svg_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $svg_compiled['plan'], $svg_compiled['args'] );
identity_assert( 'completed' === ( $svg_receipt['status'] ?? '' ), 'SVG identity must materialize: ' . wp_json_encode( $svg_receipt['errors'] ?? array() ) );
$svg_logo = (int) get_option( 'site_logo' );
identity_assert( $svg_logo > 0 && (int) get_option( 'site_icon' ) === $svg_logo, 'SVG logo and icon must share a real attachment' );
identity_assert( 'image/svg+xml' === get_post_mime_type( $svg_logo ), 'SVG must remain a vector attachment' );
$svg_metadata = wp_get_attachment_metadata( $svg_logo );
identity_assert( 512 === $svg_metadata['width'] && 512 === $svg_metadata['height'], 'SVG attachment metadata must carry intrinsic dimensions' );
identity_assert( file_get_contents( get_attached_file( $svg_logo ) ) === $svg, 'Gradient/vector artwork must retain exact accepted bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable attachment readback.
identity_assert( str_contains( get_custom_logo(), '.svg' ), 'Core custom logo must render the SVG attachment' );
identity_assert( str_contains( do_blocks( '<!-- wp:site-logo /-->' ), '.svg' ), 'Core site-logo block must render the SVG attachment' );
identity_assert( str_contains( get_site_icon_url( 32 ), '.svg' ) && str_contains( get_site_icon_url( 512 ), '.svg' ), 'Native icon URLs must reference SVG at requested sizes' );
ob_start();
wp_site_icon();
$svg_icon_markup = (string) ob_get_clean();
identity_assert( str_contains( $svg_icon_markup, 'rel="icon"' ) && str_contains( $svg_icon_markup, '.svg' ), 'Core favicon markup must reference the native vector icon' );
identity_assert( wp_check_filetype( 'unrelated.svg' ) === $svg_upload_policy, 'Ordinary SVG upload policy must remain unchanged' );
$svg_query = array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'numberposts' => -1,
	'fields'      => 'ids',
);
$svg_ids   = get_posts( $svg_query );
delete_option( 'site_logo' );
delete_option( 'site_icon' );
$svg_reimport = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $svg_compiled['plan'], $svg_compiled['args'] );
identity_assert( 'completed' === ( $svg_reimport['status'] ?? '' ) && (int) get_option( 'site_logo' ) === $svg_logo, 'SVG reimport must reuse the attachment after settings are cleared' );
identity_assert( get_posts( $svg_query ) === $svg_ids, 'SVG reimport must not duplicate attachments' );
$svg_owner         = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $svg_artifact, array(
	'slug'     => 'vector-identity-owner',
	'activate' => true,
) );
$svg_owner_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $svg_owner['plan'], $svg_owner['args'] );
identity_assert( 'completed' === ( $svg_owner_receipt['status'] ?? '' ) && (int) get_option( 'site_logo' ) === $svg_logo, 'SVG import must preserve an owner-selected logo' );
delete_option( 'site_logo' );
delete_option( 'site_icon' );
$svg_failure        = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $svg_artifact, array(
	'slug'                           => 'vector-identity-rollback',
	'activate'                       => true,
	'inject_materialization_failure' => 'after_blogname',
) );
$svg_failed_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $svg_failure['plan'], $svg_failure['args'] );
identity_assert( 'completed' !== ( $svg_failed_receipt['status'] ?? '' ), 'SVG injected failure must roll back' );
identity_assert( 0 === (int) get_option( 'site_logo', 0 ) && 0 === (int) get_option( 'site_icon', 0 ), 'SVG rollback must restore native settings' );
identity_assert( get_posts( $svg_query ) === $svg_ids, 'SVG rollback must remove newly created attachments' );
identity_assert( wp_check_filetype( 'unrelated.svg' ) === $svg_upload_policy, 'Rollback must not leave SVG upload filters installed' );
echo "Native SVG logo/icon rendering, vector preservation, deduplication, owner preservation and rollback passed.\n";

// Real PNG-backed ICO: smaller frame first, with both directory reserved bytes zero.
$ico_frames = array();
foreach ( array( 16, 32 ) as $size ) {
	$ico_frames[] = "\x89PNG\r\n\x1a\n"
		. identity_png_chunk( 'IHDR', pack( 'NNCCCCC', $size, $size, 8, 6, 0, 0, 0 ) )
		. identity_png_chunk( 'IDAT', gzcompress( str_repeat( "\0" . str_repeat( "\x14\x28\x3c\xff", $size ), $size ) ) )
		. identity_png_chunk( 'IEND', '' );
}
$ico = pack( 'vvv', 0, 1, 2 )
	. pack( 'CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen( $ico_frames[0] ), 38 )
	. pack( 'CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen( $ico_frames[1] ), 38 + strlen( $ico_frames[0] ) )
	. implode( '', $ico_frames );
identity_assert( "\0" === $ico[9] && "\0" === $ico[25], 'ICO directory reserved bytes must be zero' );
$ico_artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<html><head><title>ICO Identity</title><link rel="icon" href="brand.ico"><script type="application/ld+json">{"@type":"Organization","logo":"brand.ico","slogan":"ICO source slogan"}</script></head><body><h1>ICO identity</h1></body></html>',
		),
		array(
			'path'           => 'website/brand.ico',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Real binary ICO artifact transport.
			'content_base64' => base64_encode( $ico ),
		),
	),
);

// Snapshot actual files, not just receipt claims, including uploads and theme assets.
function identity_file_snapshot( string $directory ): array {
	$files = array();
	if ( is_dir( $directory ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
	}
	ksort( $files );
	return $files;
}
function identity_option_snapshot(): array {
	$options = array();
	foreach ( array( 'stylesheet', 'template', 'show_on_front', 'page_on_front', 'use_smilies', 'blogname', 'blogdescription', 'site_logo', 'site_icon', 'theme_mods_' . get_stylesheet() ) as $name ) {
		// Core may return numeric scalars as strings after a database read and as
		// integers from the request cache. Compare their persisted representation.
		$value            = get_option( $name, null );
		$options[ $name ] = null === $value ? null : (string) maybe_serialize( $value );
	}
	return $options;
}
function identity_import_files_snapshot(): array {
	// Check actual asset/theme/plugin bytes. The runtime's physical SQLite file
	// records database allocation too; logical options and attachments are checked
	// separately and do not imply byte-identical database storage after rollback.
	$files = array();
	foreach ( array( 'themes', 'plugins', 'mu-plugins', 'uploads' ) as $root ) {
		$files += identity_file_snapshot( WP_CONTENT_DIR . '/' . $root );
	}
	ksort( $files );
	return $files;
}

delete_option( 'site_logo' );
delete_option( 'site_icon' );
update_option( 'blogdescription', '' );
$ico_ids_before = get_posts( $svg_query );
$ico_compiled   = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$ico_artifact,
	array(
		'slug'     => 'ico-identity-oracle',
		'activate' => true,
	)
);
identity_assert( ! is_wp_error( $ico_compiled ), 'ICO identity must compile: ' . ( is_wp_error( $ico_compiled ) ? $ico_compiled->get_error_message() : '' ) );
$ico_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_compiled['plan'], $ico_compiled['args'] );
identity_assert( 'completed' === ( $ico_receipt['status'] ?? '' ), 'ICO identity must materialize: ' . wp_json_encode( $ico_receipt['errors'] ?? array() ) );
identity_assert( 'ico-identity-oracle' === get_stylesheet(), 'ICO branding must follow generated-theme activation' );
require_once ABSPATH . 'wp-admin/includes/image.php';
foreach ( $ico_receipt['completed']['files'] ?? array() as $ico_file ) {
	if ( str_ends_with( (string) ( $ico_file['target_path'] ?? '' ), '/brand.ico' ) ) {
		$ico_source_file = get_stylesheet_directory() . '/' . $ico_file['target_path'];
		$ico_source_crop = wp_crop_image( $ico_source_file, 0, 0, 32, 32, 16, 16 );
		identity_assert( is_wp_error( $ico_source_crop ) && 'image_no_editor' === $ico_source_crop->get_error_code(), 'Actual core ICO source cropping must report image_no_editor' );
		identity_assert( file_get_contents( $ico_source_file ) === $ico, 'Failed source cropping must leave ICO bytes untouched' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable source readback.
		echo 'Observed core ICO source cropping: ' . esc_html( $ico_source_crop->get_error_code() ) . "\n";
		break;
	}
}
identity_assert( isset( $ico_source_crop ), 'ICO source cropping must exercise a real completed asset file' );
$ico_logo = (int) get_option( 'site_logo' );
identity_assert( $ico_logo > 0 && 'attachment' === get_post_type( $ico_logo ) && (int) get_option( 'site_icon' ) === $ico_logo, 'ICO logo/icon must share a real attachment: ' . wp_json_encode( $ico_receipt['completed']['site_identity'] ?? array() ) );
identity_assert( 1 === count( array_diff( get_posts( $svg_query ), $ico_ids_before ) ), 'Shared ICO identity must create exactly one attachment' );
identity_assert( 'applied' === ( $ico_receipt['completed']['site_identity']['site_logo']['status'] ?? '' ) && 'applied' === ( $ico_receipt['completed']['site_identity']['site_icon']['status'] ?? '' ), 'ICO receipt must confirm both native settings were applied' );
identity_assert( 'image/x-icon' === get_post_mime_type( $ico_logo ), 'Actual core ICO attachment MIME must be image/x-icon' );
$ico_metadata = wp_get_attachment_metadata( $ico_logo );
identity_assert( 32 === ( $ico_metadata['width'] ?? null ) && 32 === ( $ico_metadata['height'] ?? null ), 'ICO metadata must select the largest frame, not the first 16px frame' );
identity_assert( file_get_contents( get_attached_file( $ico_logo ) ) === $ico, 'ICO attachment must retain exact container/frame bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable attachment readback.
$ico_url = wp_get_attachment_url( $ico_logo );
identity_assert( is_string( $ico_url ) && str_ends_with( $ico_url, '.ico' ), 'ICO attachment URL must retain its format' );
identity_assert( (int) get_theme_mod( 'custom_logo' ) === $ico_logo && str_contains( get_custom_logo(), esc_url( $ico_url ) ), 'Core custom logo must render the ICO attachment URL' );
identity_assert( str_contains( do_blocks( '<!-- wp:site-logo /-->' ), esc_url( $ico_url ) ), 'Core site-logo block must render the ICO attachment URL' );
identity_assert( get_site_icon_url( 32 ) === $ico_url && get_site_icon_url( 512 ) === $ico_url, 'Core icon size requests must retain the original ICO URL without invented derivatives' );
ob_start();
wp_site_icon();
$ico_icon_markup = (string) ob_get_clean();
identity_assert( str_contains( $ico_icon_markup, 'rel="icon"' ) && str_contains( $ico_icon_markup, esc_url( $ico_url ) ), 'Core favicon markup must reference the actual ICO attachment' );
identity_assert( 'ICO source slogan' === get_option( 'blogdescription' ), 'ICO activation must seed the explicit source slogan' );

$ico_crop = wp_crop_image( $ico_logo, 0, 0, 32, 32, 16, 16 );
identity_assert( is_wp_error( $ico_crop ) && 'image_no_editor' === $ico_crop->get_error_code(), 'Actual core ICO cropping must report image_no_editor, not claim editor support' );
identity_assert( file_get_contents( get_attached_file( $ico_logo ) ) === $ico, 'Failed core cropping must leave ICO bytes untouched' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable attachment readback.
echo 'Observed core ICO cropping: ' . esc_html( $ico_crop->get_error_code() ) . "\n";

$ico_ids = get_posts( $svg_query );
delete_option( 'site_logo' );
delete_option( 'site_icon' );
$ico_reimport = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_compiled['plan'], $ico_compiled['args'] );
identity_assert( 'completed' === ( $ico_reimport['status'] ?? '' ) && (int) get_option( 'site_logo' ) === $ico_logo && (int) get_option( 'site_icon' ) === $ico_logo, 'ICO reimport after clearing settings must reuse the shared attachment' );
identity_assert( get_posts( $svg_query ) === $ico_ids, 'ICO reimport must not duplicate attachments' );

// A common favicon.ico + PNG touch-icon source must keep the declared ICO
// evidence authoritative instead of inheriting an unrelated early raster choice.
$ico_mixed_artifact                        = $ico_artifact;
$ico_mixed_artifact['files'][0]['content'] = str_replace( '</head>', '<link rel="apple-touch-icon" href="touch.png"></head>', $ico_mixed_artifact['files'][0]['content'] );
$ico_mixed_artifact['files'][]             = array(
	'path'           => 'website/touch.png',
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary touch-icon fixture uses the existing portable artifact transport.
	'content_base64' => base64_encode( $png ),
);
delete_option( 'site_logo' );
delete_option( 'site_icon' );
$ico_mixed_ids = get_posts( $svg_query );
$ico_mixed     = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $ico_mixed_artifact, array(
	'slug'     => 'ico-identity-mixed-icons',
	'activate' => true,
) );
identity_assert( ! is_wp_error( $ico_mixed ), 'Mixed ICO/PNG icon source must compile' );
$ico_mixed_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_mixed['plan'], $ico_mixed['args'] );
identity_assert( 'completed' === ( $ico_mixed_receipt['status'] ?? '' ), 'Mixed ICO/PNG icon source must materialize' );
$ico_mixed_icon = (int) get_option( 'site_icon' );
identity_assert( $ico_mixed_icon > 0 && (int) get_option( 'site_logo' ) === $ico_mixed_icon && 'image/x-icon' === get_post_mime_type( $ico_mixed_icon ), 'Declared ICO must remain authoritative when a separate PNG touch icon is present' );
identity_assert( 1 === count( array_diff( get_posts( $svg_query ), $ico_mixed_ids ) ), 'Mixed icon evidence must not create an unused fallback attachment' );

// Use different real attachments as owner values, so preservation cannot pass by coincidence.
update_option( 'site_logo', $logo );
update_option( 'site_icon', $svg_logo );
update_option( 'blogdescription', 'ICO owner slogan' );
$ico_owner = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$ico_artifact,
	array(
		'slug'     => 'ico-identity-owner',
		'activate' => true,
	)
);
identity_assert( ! is_wp_error( $ico_owner ), 'Owner-preserving ICO must compile' );
$ico_owner_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_owner['plan'], $ico_owner['args'] );
identity_assert( 'completed' === ( $ico_owner_receipt['status'] ?? '' ) && (int) get_option( 'site_logo' ) === $logo && (int) get_option( 'site_icon' ) === $svg_logo && 'ICO owner slogan' === get_option( 'blogdescription' ), 'ICO activation must preserve distinct owner images and slogan' );
foreach ( array( 'site_logo', 'site_icon', 'blogdescription' ) as $setting ) {
	identity_assert( 'preserved_owner_value' === ( $ico_owner_receipt['completed']['site_identity'][ $setting ]['status'] ?? '' ), 'ICO owner receipt must report preservation: ' . $setting );
}

// Empty settings ensure previews cannot pass merely by preserving an owner value.
delete_option( 'site_logo' );
delete_option( 'site_icon' );
update_option( 'blogdescription', '' );
$ico_boundary_options = identity_option_snapshot();
$ico_host_files       = identity_file_snapshot( get_stylesheet_directory() );
foreach ( array( 'preview', 'existing_theme' ) as $boundary ) {
	$ico_boundary = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
		$ico_artifact,
		array(
			'slug'        => 'ico-identity-' . $boundary,
			'activate'    => false,
			'destination' => 'preview' === $boundary ? 'generated_theme' : 'existing_theme',
		)
	);
	identity_assert( ! is_wp_error( $ico_boundary ), 'ICO boundary must compile: ' . $boundary );
	$ico_boundary_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_boundary['plan'], $ico_boundary['args'] );
	identity_assert( 'completed' === ( $ico_boundary_receipt['status'] ?? '' ), 'ICO boundary must complete: ' . $boundary . ' ' . wp_json_encode( $ico_boundary_receipt['errors'] ?? array() ) );
	identity_assert( identity_option_snapshot() === $ico_boundary_options, 'ICO boundary must not activate or change global identity/options: ' . $boundary );
	identity_assert( identity_file_snapshot( get_stylesheet_directory() ) === $ico_host_files, 'ICO boundary must leave active-theme files byte-identical: ' . $boundary );
}

$ico_forbidden_args             = $ico_boundary['args'];
$ico_forbidden_args['activate'] = true;
$ico_forbidden                  = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_boundary['plan'], $ico_forbidden_args );
identity_assert( 'completed' !== ( $ico_forbidden['status'] ?? '' ) && str_contains( wp_json_encode( $ico_forbidden['errors'] ?? array() ), 'destination_forbids_theme_activation' ), 'Existing-theme ICO import must reject requested activation' );
identity_assert( identity_option_snapshot() === $ico_boundary_options && identity_file_snapshot( get_stylesheet_directory() ) === $ico_host_files, 'Forbidden ICO activation must leave identity and active theme unchanged' );

// Force new ICO bytes so rollback must delete a newly created upload, not a reused one.
$ico_rollback_artifact = $ico_artifact;
// A valid ancillary PNG chunk changes the hash without changing frame dimensions.
$ico_rollback_frame = substr( $ico_frames[1], 0, -12 ) . identity_png_chunk( 'tEXt', "Comment\0Rollback fixture" ) . substr( $ico_frames[1], -12 );
$ico_rollback_bytes = pack( 'vvv', 0, 1, 2 )
	. pack( 'CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen( $ico_frames[0] ), 38 )
	. pack( 'CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen( $ico_rollback_frame ), 38 + strlen( $ico_frames[0] ) )
	. $ico_frames[0] . $ico_rollback_frame;
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary rollback fixture transport.
$ico_rollback_artifact['files'][1]['content_base64'] = base64_encode( $ico_rollback_bytes );
$ico_rollback                                        = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$ico_rollback_artifact,
	array(
		'slug'                           => 'ico-identity-rollback',
		'activate'                       => true,
		'site_title'                     => 'ICO rollback title',
		'inject_materialization_failure' => 'after_blogname',
	)
);
identity_assert( ! is_wp_error( $ico_rollback ), 'ICO rollback fixture must compile' );
set_theme_mod( 'custom_logo', $logo );
// Core synchronizes custom_logo into site_logo. Keep the old stored theme mod
// for the rollback proof, but explicitly model an empty global logo choice.
update_option( 'site_logo', 0 );
$ico_rollback_options = identity_option_snapshot();
$ico_rollback_ids     = get_posts( $svg_query );
$ico_rollback_files   = identity_import_files_snapshot();
$ico_before_failure   = array();
$ico_observe_failure  = static function () use ( &$ico_before_failure, $svg_query ): void {
	// Rollback updates blogname too; retain the first, forward-mutation snapshot.
	if ( array() !== $ico_before_failure ) {
		return;
	}
	$file               = get_attached_file( (int) get_option( 'site_logo' ) );
	$ico_before_failure = array(
		'logo'    => (int) get_option( 'site_logo' ),
		'icon'    => (int) get_option( 'site_icon' ),
		'ids'     => get_posts( $svg_query ),
		'tagline' => get_option( 'blogdescription' ),
		'hash'    => is_string( $file ) && is_file( $file ) ? hash_file( 'sha256', $file ) : null,
	);
};
add_action( 'update_option_blogname', $ico_observe_failure );
$ico_failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $ico_rollback['plan'], $ico_rollback['args'] );
remove_action( 'update_option_blogname', $ico_observe_failure );
identity_assert( 'completed' !== ( $ico_failed['status'] ?? '' ) && str_contains( wp_json_encode( $ico_failed['errors'] ?? array() ), 'injected_after_blogname_failure' ), 'ICO rollback must reach the injected post-identity failure' );
identity_assert(
	( $ico_before_failure['logo'] ?? 0 ) > 0 && $ico_before_failure['logo'] === $ico_before_failure['icon'] && 'ICO source slogan' === $ico_before_failure['tagline'] && 1 === count( array_diff( $ico_before_failure['ids'], $ico_rollback_ids ) ),
	'ICO rollback must actually undo new shared attachment and applied branding, not a no-op: ' . wp_json_encode(
		array(
			'observed'  => $ico_before_failure,
			'prior_ids' => $ico_rollback_ids,
		)
	)
);
identity_assert( hash( 'sha256', $ico_rollback_bytes ) === $ico_before_failure['hash'], 'ICO rollback must have created the exact unique upload before failure' );
identity_assert(
	identity_option_snapshot() === $ico_rollback_options,
	'ICO rollback must restore activation, native options and old theme mods exactly: ' . wp_json_encode(
		array(
			'expected' => $ico_rollback_options,
			'actual'   => identity_option_snapshot(),
		)
	)
);
identity_assert( get_posts( $svg_query ) === $ico_rollback_ids, 'ICO rollback must remove new attachments and preserve existing ones' );
$ico_files_after_rollback = identity_import_files_snapshot();
identity_assert(
	$ico_rollback_files === $ico_files_after_rollback,
	'ICO rollback must restore theme/upload/companion files byte-for-byte: ' . wp_json_encode(
		array(
			'added_or_changed'   => array_diff_assoc( $ico_files_after_rollback, $ico_rollback_files ),
			'removed_or_changed' => array_diff_assoc( $ico_rollback_files, $ico_files_after_rollback ),
		)
	)
);
echo "Native ICO MIME, largest-frame metadata, exact bytes, logo/block/favicon rendering, shared deduplication, owner preservation, activation boundaries and rollback passed.\n";
