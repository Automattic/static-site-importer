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
$artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><html><head><title>Outcome fixture</title><meta name="description" content="Neutral imported fixture"></head><body><main><h1>Outcome fixture</h1><p>Imported WordPress content.</p></main></body></html>',
		),
	),
);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
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
// Explicit fixture-owned head policy, not production importer behavior. The
// check must measure it rather than claiming that current imports always pass.
$theme = get_stylesheet_directory();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture writes an intentionally controlled head policy.
file_put_contents( $theme . '/functions.php', '<?php add_action("wp_head", function() { $origin = home_url(); echo \'<link rel="canonical" href="\' . esc_url($origin . "/") . \'"><meta name="description" content="Neutral imported fixture"><meta property="og:title" content="Outcome fixture"><meta property="og:url" content="\' . esc_url($origin . "/") . \'"><meta property="og:image" content="\' . esc_url($origin . "/fixture.png") . \'">\'; }, 1); remove_action("wp_head", "wp_generator"); remove_action("wp_head", "rel_canonical"); add_filter("wp_robots", "__return_empty_array", PHP_INT_MAX);' );
echo wp_json_encode(
	array(
		'status' => 'imported',
		'theme'  => $theme,
	)
);
