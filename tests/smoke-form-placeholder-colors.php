<?php
/** Regression for #2296: author placeholder colours survive provider resets. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'     => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/placeholder-form.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$css         = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$overlay     = $row['provider_layout_overlay_css'];
$writes      = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/placeholder-theme', 'Placeholder', $css, array(), array(), array( $overlay ) );
$stylesheet  = $writes['/tmp/placeholder-theme/style.css'];
$existing    = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/placeholder-theme', 'Placeholder', '', array(), array(), array( $overlay ), array( '/tmp/placeholder-theme/style.css' => $css ) );
preg_match( '/\.ssi-form-[a-f0-9]{12}/', $overlay['css'], $match );
$scope       = ':is(' . str_repeat( $match[0], 5 ) . ' *)';
$repeat      = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/placeholder-theme', 'Placeholder', '', array(), array(), array( $overlay ), array( '/tmp/placeholder-theme/style.css' => $stylesheet ) );
$plain       = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/placeholder-theme', 'Placeholder', '', array(), array(), array(), array( '/tmp/placeholder-theme/style.css' => $css ) );
$fallback    = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/placeholder-theme', 'Placeholder', 'input,textarea{color:#123456}', array(), array(), array( $overlay ) );
$checks      = array(
	'fixture maps' => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'frontend author placeholder replay' => str_contains( $stylesheet, 'input' . $scope . '::placeholder' ),
	'editor author placeholder replay' => str_contains( $writes['/tmp/placeholder-theme/assets/css/editor-style.css'], 'textarea' . $scope . '::placeholder' ),
	'existing stylesheet path replays too' => str_contains( $existing['/tmp/placeholder-theme/style.css'], 'input' . $scope . '::placeholder' ),
	'repeated materialization does not duplicate source selectors' => substr_count( $repeat['/tmp/placeholder-theme/style.css'], $scope ) === substr_count( $stylesheet, $scope ),
	'without forms source remains unchanged' => $plain['/tmp/placeholder-theme/style.css'] === $css,
	'no authored placeholder keeps fallback' => ! str_contains( $fallback['/tmp/placeholder-theme/style.css'], $scope ) && str_contains( $fallback['/tmp/placeholder-theme/style.css'], '::placeholder{color:revert}' ),
);
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( array( 'css' => $stylesheet, 'overlay' => $overlay['css'], 'editor' => $writes['/tmp/placeholder-theme/assets/css/editor-style.css'], 'markup' => $row['block_markup'], 'fallback' => $fallback['/tmp/placeholder-theme/style.css'] ) );
	exit;
}
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( $failures ? 1 : 0 );
