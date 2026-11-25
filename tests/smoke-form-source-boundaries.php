<?php
/** Public compiler/materializer/runtime regressions for #3307. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

function materialize_source_boundaries( string $source ): array {
	$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<!doctype html><html><head><style>.title,.required{display:block}</style></head><body><main>' . $source . '</main></body></html>' ) ) )->toArray();
	$plan = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
	$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
	if ( 'mapped' !== $row['status'] || ! empty( $validated['errors'] ) ) {
		throw new RuntimeException( 'Fixture must map' );
	}
	return $row;
}

$checks = array();
foreach ( array( 'comment-form', 'survey' ) as $class ) {
	$row = materialize_source_boundaries( '<form class="' . $class . '"><textarea name="message"></textarea></form><button type="button">Preview</button><button disabled>Comments Restricted</button>' );
	$checks[ $class . ' has no saved submit' ] = ! str_contains( $row['block_markup'], 'form-button-submit' ) && str_contains( $row['block_markup'], 'ssi-source-no-submit' );
	// Model Jetpack's legacy fallback, passing the saved class marker through its public HTML hook.
	$html = '<div class="ssi-source-no-submit"><form><p class="contact-submit"><button type=\'submit\' class=\'pushbutton-wide\'>Submit</button><input type="hidden" name="action" value="grunion-contact-form"></p></form></div>';
	$projected = Static_Site_Importer_Provider_Form_Runtime_V1::project_source_submit_controls( $html );
	$checks[ $class . ' removes fallback but keeps hidden inputs' ] = ! str_contains( $projected, '<button' ) && str_contains( $projected, 'name="action"' );
}
$row = materialize_source_boundaries( '<form><input name="name"><button type="submit">Send</button><button type="submit">Save</button></form>' );
$checks['source submits survive'] = ! str_contains( $row['block_markup'], 'ssi-source-no-submit' ) && str_contains( $row['block_markup'], '>Send</button>' ) && str_contains( $row['block_markup'], '>Save</button>' );
$plain = '<form><p class="contact-submit"><button type="submit">Submit</button></p></form>';
$checks['unmarked forms untouched'] = $plain === Static_Site_Importer_Provider_Form_Runtime_V1::project_source_submit_controls( $plain );
$row = materialize_source_boundaries( '<form><label for="first"><span class="title">First Name</span><span class="description required">(required)</span></label><input id="first" name="first" required><button>Send</button></form>' );
$checks['block marker keeps text boundary'] = str_contains( $row['block_markup'], '"label":"First Name "' );
$row = materialize_source_boundaries( '<form><label for="note">Note <span aria-hidden="true">*</span></label><input id="note" name="note" required><button>Send</button></form>' );
$checks['inline source whitespace survives'] = str_contains( $row['block_markup'], '"label":"Note "' );
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
	}
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
