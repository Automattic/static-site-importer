<?php
/**
 * Regression for #2412: a required control the source keeps hidden (a
 * conditional field that only script reveals) is left out of the Jetpack form
 * and handed off with its reason. A file input is handed off as an upload, and
 * the receipt names every control it does not map.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression, using filesystem and output without WordPress.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$form        = '<form action="/contact" method="post">'
	. '<label for="n">Name</label><input id="n" name="name" required>'
	. '<label for="svc">Service</label><select id="svc" name="service"><option value="a">A</option><option value="onsite">On site</option></select>'
	. '<div class="cond" hidden data-show-when-service="onsite"><label for="addr">Intervention address</label><input id="addr" name="service_address" required></div>'
	. '<div class="cond" hidden><label for="extra">Extra</label><input id="extra" name="extra"></div>'
	. '<label for="photo">Photo</label><input id="photo" name="photo" type="file" accept="image/*" required>'
	. '<input type="hidden" name="upload_token" value="t1"><button type="submit">Send</button></form>';
$html        = '<!doctype html><html><body><main>' . $form . '</main></body></html>';
$result      = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$decisions   = array();
foreach ( $row['field_contract'] as $entry ) {
	$decisions[ $entry['source']['name'] ?: $entry['source']['type'] ] = $entry['decision'] . ':' . ( $entry['capability'] ?? $entry['reason_code'] ?? $entry['provider_block'] ?? '' );
}
$handoffs = array_column( array_filter( $row['handoff_items'], static fn( array $item ): bool => 'control' === $item['kind'] ), 'capability', 'name' );

$checks = array(
	'fixture maps'                            => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'every control has a decision'            => 6 === count( $row['field_contract'] ),
	'hidden required field is handed off'     => 'handoff:conditional_visibility' === ( $decisions['service_address'] ?? '' ) && 'conditional_visibility' === ( $handoffs['service_address'] ?? '' ),
	'hidden optional field is suppressed'     => 'suppressed:unconditionally_hidden_control' === ( $decisions['extra'] ?? '' ),
	'file input is handed off as an upload'   => 'handoff:file_upload' === ( $decisions['photo'] ?? '' ) && 'file_upload' === ( $handoffs['photo'] ?? '' ),
	'hidden fields are not seeded'            => ! str_contains( $row['block_markup'], 'Intervention address' ) && ! str_contains( $row['block_markup'], '"label":"Extra"' ),
	'visible fields are seeded'               => 'mapped:jetpack/field-text' === ( $decisions['name'] ?? '' ) && 'mapped:jetpack/field-select' === ( $decisions['service'] ?? '' ),
);
$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . "\n" . wp_json_encode( $decisions ) . "\n" );
	exit( 1 );
}
echo "form hidden controls passed\n";
