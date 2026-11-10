<?php
/** A control's authored background longhand is a provider-paintable fact, not a reason to drop the form. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html   = (string) file_get_contents( __DIR__ . '/fixtures/form-control-background-image-reset.html' );
$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $html ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$failures    = array();
if ( ! is_array( $declaration ) ) {
	$failures[] = 'the producer must declare the source form';
} else {
	$entities  = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	if ( array() !== $validated['errors'] || 1 !== count( $validated['forms'] ) ) {
		$failures[] = 'the form must survive provider validation: ' . json_encode( $validated['errors'] );
	} else {
		$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
		if ( ! str_contains( (string) ( $row['block_markup'] ?? '' ), '<!-- wp:jetpack/contact-form' ) ) {
			$failures[] = 'the form must seed a Jetpack contact form';
		}
		if ( ! preg_match( '/\{[^}]*background-image:none[^}]*\}/', (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ) ) ) {
			$failures[] = 'the overlay must keep the authored background-image reset';
		}
	}
}
foreach ( $failures as $failure ) {
	fwrite( STDERR, "FAIL: {$failure}\n" );
}
exit( empty( $failures ) ? 0 : 1 );
