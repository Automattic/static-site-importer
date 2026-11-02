<?php
/** Large choice lists use durable references, not oversized class names. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$options = array( array( 'label' => 'Afghanistan', 'value' => 'AF', 'selected' => false ) );
for ( $i = 1; $i < 245; ++$i ) {
	$options[] = array( 'label' => 'Choice ' . $i, 'value' => 'code-' . $i, 'selected' => 100 === $i );
}
$seed = static function ( array $options ): array {
	$html = '<!doctype html><html><head><title>Contact</title></head><body><form action="/contact" method="post"><label for="email">Email</label><input id="email" name="email" type="email"><label for="choices">Choices</label><select id="choices" name="choices">';
	foreach ( $options as $option ) {
		$html .= '<option value="' . htmlspecialchars( $option['value'] ) . '"' . ( $option['selected'] ? ' selected' : '' ) . '>' . htmlspecialchars( $option['label'] ) . '</option>';
	}
	$html .= '</select><button type="submit">Send</button></form></body></html>';
	$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
	$plan = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	return Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
};
$token = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options );
$row = $seed( $options );
$checks = array(
	'245 choices get a short stable reference' => 1 === preg_match( '/^ssi-choice-ref-[a-f0-9]{64}$/D', $token ) && $token === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options ),
	'large select maps without choice_values loss' => 'mapped' === $row['status'] && ! str_contains( json_encode( $row['receipt'] ?? $row ), 'choice_values' ),
);
// Core serialization preserves the registered className transport across saves.
$markup = serialize_blocks( parse_blocks( $row['block_markup'] ) );
$checks['reference survives block save'] = str_contains( $markup, $token );
$html = '<div class="' . $token . '-wrap"><select>';
foreach ( $options as $option ) {
	$html .= '<option value="provider" selected="selected">' . htmlspecialchars( $option['label'] ) . '</option>';
}
$html .= '</select></div>';
$projected = Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( $html );
$checks['reference projects native values'] = str_contains( $projected, '<option value="AF">Afghanistan</option>' );
$checks['reference preserves only source selected state'] = 1 === substr_count( $projected, 'selected="selected"' ) && str_contains( $projected, 'value="code-100" selected="selected">Choice 100</option>' );
foreach ( array( 'duplicate', 'two-selected' ) as $case ) {
	$invalid = $options;
	if ( 'duplicate' === $case ) {
		$invalid[1]['label'] = $invalid[0]['label'];
	} else {
		$invalid[0]['selected'] = true;
	}
	$declined = $seed( $invalid );
	$checks[ $case . ' declines choice preservation' ] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $invalid ) && str_contains( json_encode( $declined ), 'choice_values' );
}
$inline = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( array_slice( $options, 0, 2 ) );
$checks['small inline lists remain compatible'] = ! str_starts_with( $inline, 'ssi-choice-ref-' ) && str_contains( Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( '<div class="' . $inline . '-wrap"><select><option>Afghanistan</option><option>Choice 1</option></select></div>' ), '<option value="AF">Afghanistan</option>' );
$bounded = $options;
for ( $i = 245; $i < 300; ++$i ) {
	$bounded[] = array( 'label' => 'Choice ' . $i, 'value' => 'code-' . $i, 'selected' => false );
}
$checks['at least 300 choices are supported'] = '' !== Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $bounded );
$checks['option count remains bounded'] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( array_fill( 0, 501, $options[0] ) );
$too_long = $options;
$too_long[0]['value'] = str_repeat( 'x', 201 );
$checks['per-option length remains bounded'] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $too_long );
$key = 'ssi_choice_' . substr( $token, strlen( 'ssi-choice-ref-' ) );
$stored = get_option( $key );
$checks['durable data is stored once as validated triples'] = count( $stored ) === 245 && $stored[0] === array( 'Afghanistan', 'AF', false );
update_option( $key, array() );
$checks['missing or corrupt references leave provider HTML untouched'] = $html === Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( $html );
update_option( $key, $stored );
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
