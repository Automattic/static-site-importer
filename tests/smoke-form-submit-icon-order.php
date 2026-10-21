<?php
/** Regression for #2293: submit icons retain their side of the label. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/submit-icon-order.html' ) ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$rows = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'];
$css = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$forms = array();
$checks = array( 'manifest validates' => empty( $validated['errors'] ) && 3 === count( $rows ) );
foreach ( $rows as $index => $row ) {
	$markup = $row['block_markup'];
	preg_match( '/<!-- wp:(?:core\/)?button (.*?) -->/', $markup, $match );
	$attrs = json_decode( $match[1] ?? '{}', true );
	preg_match( '/<div class="wp-block-button[^>]*>.*?<\/div>/s', $markup, $match );
	$button = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $match[0] ?? '', array( 'attrs' => $attrs ) );
	$overlay = $row['provider_layout_overlay_css']['css'];
	$forms[] = compact( 'button', 'overlay' );
	preg_match_all( '/<svg\b|Send/', $button, $tokens );
	$expected = array( array( 'Send', '<svg' ), array( '<svg', 'Send' ), array( '<svg', 'Send', '<svg' ) );
	$checks[ 'source order ' . $index ] = 'mapped' === $row['status'] && $expected[$index] === $tokens[0];
}
// Declarations without fallback HTML keep the historical leading default.
$legacy = $validated['forms'][0];
unset( $legacy['html'] );
$legacy_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $legacy ) ) )['forms'][0];
$checks['missing HTML keeps leading icon'] = strpos( $legacy_row['block_markup'], '<svg' ) < strpos( $legacy_row['block_markup'], 'Send</button>' );
// Fallback HTML is ordering evidence only, never a new markup admission path.
$unsafe = $validated['forms'][0];
$unsafe['presentation_graph']['visual_parts'][0]['markup'] = '<svg onload="alert(1)"><path d="M5 12h14"/></svg>';
$unsafe_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unsafe ) ) )['forms'][0];
$checks['unsafe SVG is not reintroduced from HTML'] = ! str_contains( $unsafe_row['block_markup'], '<svg' );
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'forms' ) );
	exit;
}
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
