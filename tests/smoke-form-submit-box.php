<?php
/**
 * Regression for #2295: the submit control, not its wrapper, owns its height.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI fixture I/O.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/submit-box.html' ) ),
	)
)->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$css         = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$overlay     = $row['provider_layout_overlay_css']['css'];
$markup      = $row['block_markup'];
preg_match( '/<!-- wp:(?:core\/)?button (.*?) -->/', $markup, $match );
$attrs = json_decode( $match[1] ?? '{}', true );
preg_match( '/<div class="wp-block-button[^>]*>.*?<\/div>/s', $markup, $match );
$button = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $match[0] ?? '', array( 'attrs' => $attrs ) );
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'overlay', 'button' ) );
	exit;
}
preg_match_all( '/ssi-node-[a-f0-9]{12}/', $attrs['className'] ?? '', $hooks );
$wrapper_selector = '\\.(?:' . implode( '|', $hooks[0] ) . ')';
$checks           = array(
	'fixture maps'                       => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'wrapper has no fixed source height' => ! preg_match( '/' . $wrapper_selector . '\\{(?:[^}]*;)?height:/', $overlay ),
	'link owns base source height'       => (bool) preg_match( '/> \.wp-block-button__link\{[^}]*[;{]height:2\.25rem/', $overlay ),
	'link owns responsive source height' => (bool) preg_match( '/> \.wp-block-button__link\{(?:[^}]*;)?height:2rem/', $overlay ),
);
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
