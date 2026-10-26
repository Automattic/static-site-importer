<?php
/** Regression for #2271: source-owned submit colours remain live stylesheet rules. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/quote-form.html' ) ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$css = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$overlay = $row['provider_layout_overlay_css']['css'];
$markup = $row['block_markup'];
preg_match( '/<!-- wp:(?:core\/)?button (.*?) -->/', $markup, $match );
$attrs = json_decode( $match[1] ?? '{}', true );
preg_match( '/<div class="wp-block-button[^>]*>.*?<\/div>/s', $markup, $match );
$button = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $match[0] ?? '', array( 'attrs' => $attrs ) );
$checks = array(
	'fixture maps' => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'base colour binds to primary in source stylesheet' => str_contains( $css, 'background-color:var(--wp--preset--color--primary,' ),
	'overlay does not freeze class-owned colours' => ! preg_match( '/wp-block-button__link\{[^}]*(?:[;{])(?:background-color|color):/', $overlay ),
	'hover utility reaches submit control' => (bool) preg_match( '/<button[^>]*class="[^"]*hover:bg-blue-700/', $button ),
	'base utilities reach submit control' => (bool) preg_match( '/<button[^>]*class="[^"]*bg-blue-600/', $button ),
);
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'overlay', 'button' ) );
	exit;
}
// Only independently class-owned facts may leave the overlay. In particular,
// inline, tag, ancestor-dependent and overlapping shorthand facts stay captured.
foreach ( array( '.paint' => false, 'button' => true, '[style]' => true, '.ancestor .paint' => true ) as $selector => $retained ) {
	$graph = array( 'controls' => array( array( 'index' => 0, 'control' => array(
		'styles' => array( 'background_color' => '#123456', 'padding' => '12px' ),
		'provenance' => array( array( 'selector' => $selector, 'properties' => array( 'background-color' ), 'condition' => null ) ),
	) ) ) );
	$projected = Static_Site_Importer_Form_Presentation::without_class_carried_submit_colors( $graph, array( array( 'type' => 'submit', 'class' => 'paint' ) ) );
	$checks[ 'colour ownership: ' . $selector ] = isset( $projected['controls'][0]['control']['styles']['background_color'] ) === $retained
		&& '12px' === $projected['controls'][0]['control']['styles']['padding'];
}
$graph['controls'][0]['control']['styles']['background'] = '#ffffff';
$graph['controls'][0]['control']['provenance'] = array(
	array( 'selector' => 'button', 'properties' => array( 'background' ), 'condition' => null ),
	array( 'selector' => '.paint', 'properties' => array( 'background-color' ), 'condition' => null ),
);
$projected = Static_Site_Importer_Form_Presentation::without_class_carried_submit_colors( $graph, array( array( 'type' => 'submit', 'class' => 'paint' ) ) );
$checks['overlapping background ownership stays together'] = $projected === $graph;
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( $failures ? 1 : 0 );
