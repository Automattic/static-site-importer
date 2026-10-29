<?php
/** Disposable imported fixture for the public acceptance runner outcome contract. */
$root = getenv( 'SSI_OUTCOME_REPOSITORY' );
if ( ! $root || '1' !== getenv( 'SSI_OUTCOME_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Explicit disposable runtime required.' );
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/static-site-importer.php';
require_once $root . '/includes/class-static-site-importer-compilation-preparation.php';
require_once $root . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
wp_set_current_user( 1 );
// Shared chrome: every route renders the same navigation anchor. The front
// page owns the anchor target; the about route deliberately does not, so the
// link-graph check can distinguish a path-qualified anchor (always resolves)
// from a bare shared-chrome anchor (resolves only where the target renders).
$nav         = '<nav><a id="fixture-nav" href="/#fixture-panel">Information</a></nav>';
$source_html = '<!doctype html><html><head><title>Outcome fixture</title><meta name="description" content="Neutral imported fixture"></head><body><main><h1>Outcome fixture</h1><p>Imported WordPress content.</p><details id="fixture-disclosure"><summary>More information</summary><p id="fixture-panel">Disclosure content</p></details>' . $nav . '<form id="fixture-form" method="post" action="/wp-admin/admin-post.php"><input type="hidden" name="action" value="ssi_outcome_form"><label>Email<input name="email" aria-label="Email" type="text"></label><button type="submit">Send</button></form></main></body></html>';
$about_html  = '<!doctype html><html><head><title>About the outcome fixture</title><meta name="description" content="Neutral imported fixture"></head><body><main><h1>About the outcome fixture</h1><p>Second imported route.</p>' . $nav . '</main></body></html>';
$artifact    = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => $source_html,
		),
		array(
			'path'    => 'website/about.html',
			'content' => $about_html,
		),
	),
);
$compiled    = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$artifact,
	array(
		'slug'     => 'outcome-fixture',
		'activate' => true,
	)
);
if ( is_wp_error( $compiled ) ) {
	throw new RuntimeException( esc_html( $compiled->get_error_message() ) );
}
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
if ( 'completed' !== $receipt['status'] ) {
	throw new RuntimeException( wp_json_encode( $receipt ) );
}
// Explicit fixture-owned stylesheet, submission endpoint and head stand-ins, not
// production importer behavior. Descriptions, social titles and og:url are
// materialized into native WordPress fields by the importer's own head-metadata
// emitter; the fixture adds only the canonical link and og:image that no native
// owner provides in this disposable runtime. The checks must measure them rather
// than claiming that current imports always pass.
$theme = get_stylesheet_directory();
if ( ! wp_mkdir_p( $theme . '/assets' ) ) {
	throw new RuntimeException( 'Fixture asset directory could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture writes an intentionally controlled stylesheet.
file_put_contents( $theme . '/assets/site.css', "main { color: #16324f; }\n" );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture writes an intentionally controlled head policy.
file_put_contents( $theme . '/functions.php', '<?php add_action("wp_enqueue_scripts", function() { wp_enqueue_style("outcome-fixture-site", get_stylesheet_directory_uri() . "/assets/site.css", array(), "1"); }); add_action("wp_head", function() { echo \'<link rel="canonical" href="\' . esc_url(get_permalink()) . \'"><meta property="og:image" content="\' . esc_url(home_url("/fixture.png")) . \'">\'; }, 1); remove_action("wp_head", "wp_generator"); remove_action("wp_head", "rel_canonical"); add_filter("wp_robots", "__return_empty_array", PHP_INT_MAX);' );
// The recovered fixture provider owns this local submission endpoint.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture handler.
file_put_contents( $theme . '/functions.php', ' add_action("admin_post_nopriv_ssi_outcome_form", function() { echo \'<p id="fixture-success">Submission received</p>\'; exit; });', FILE_APPEND );
echo wp_json_encode(
	array(
		'status'            => 'imported',
		'source_html'       => $source_html,
		'source_html_about' => $about_html,
		'theme'             => $theme,
	)
);
