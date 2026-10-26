<?php
/** Regression for #2294: source-owned select appearance suppresses provider chrome. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/native-select-form.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$overlay     = $row['provider_layout_overlay_css']['css'];
$checks      = array(
	'fixture maps' => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'overlay restores native appearance' => (bool) preg_match( '/\.ssi-node-[a-f0-9]{12} select\{[^}]*appearance:auto!important/', $overlay ),
	'overlay validates' => null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $row['provider_layout_overlay_css'] ),
);
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( array( 'overlay' => $overlay, 'markup' => $row['block_markup'] ) );
	exit;
}
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
