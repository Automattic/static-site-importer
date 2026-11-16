<?php
/** A captioned fieldset maps its radios to one provider radio field however each option is wrapped. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Squarespace shape: fieldset > legend, then div.option > label > input per option.
$html = '<main><form method="post" action="/intake"><label for="n">Name</label><input id="n" name="name">'
	. '<fieldset class="field radio"><legend><div class="title"><span>Is the property occupied?</span></div></legend>'
	. '<div class="option"><label><input type="radio" name="occupied" value="Yes"><span>Yes</span></label></div>'
	. '<div class="option"><label><input type="radio" name="occupied" value="No"><span>No</span></label></div>'
	. '</fieldset><button type="submit">Send</button></form></main>';
$plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];

$checks = array(
	'form maps without skipped controls' => 'mapped' === $row['status'] && array() === $row['skipped_types'],
	'one radio field carries both options' => 1 === substr_count( $row['block_markup'], '<!-- wp:jetpack/field-radio ' ) && str_contains( $row['block_markup'], '"label":"Yes"' ) && str_contains( $row['block_markup'], '"label":"No"' ),
	'legend names the radio field' => str_contains( $row['block_markup'], 'Is the property occupied?' ),
);
$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
foreach ( $failed as $name ) fwrite( STDERR, "FAIL: {$name}\n" );
if ( $failed ) {
	fwrite( STDERR, json_encode( array( 'status' => $row['status'], 'skipped' => $row['skipped_types'] ), JSON_PRETTY_PRINT ) . "\n" . substr( $row['block_markup'] ?? '', 0, 1500 ) . "\n" );
	exit( 1 );
}
echo "fieldset radio option wrappers: ok\n";
