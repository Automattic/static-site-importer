<?php
/**
 * Regression for #3203: a source frame holding one control and a leading icon,
 * its caption outside, renders around the provider control alone.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$icon = '<svg class="lead" width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6"/></svg>';
$css  = '<style>.field{display:flex;flex-direction:column;gap:8px}.wrap{position:relative;display:flex;align-items:center}.lead{position:absolute;left:14px}.wrap input{width:100%;padding:13px 16px 13px 40px}</style>';
$seed = static function ( string $form ) use ( $css ): array {
	$compiled = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $css . '<main>' . $form . '</main>' ) ) )->toArray();
	$valid    = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $valid['forms'] ?? array() ) )['forms'][0] ?? array();
	$blocks   = ssi_role_box_parse_blocks( (string) ( $row['block_markup'] ?? '' ) );
	return array( (string) ( $row['block_markup'] ?? '' ), isset( $blocks[0] ) ? ssi_role_box_render_form( $blocks[0] ) : '' );
};

$checks = array();

list( $markup, $front ) = $seed( '<form><div class="field"><label for="n">Name</label><div class="wrap">' . $icon . '<input id="n" name="n"></div></div><button type="submit">Send</button></form>' );
$checks['the icon frame is marked in the saved form'] = str_contains( $markup, Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS );
$checks['the marker does not reach the front end'] = '' !== $front && ! str_contains( $front, Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS );
$checks['the caption renders before the frame'] = 1 === preg_match( '/<label[^>]*grunion-field-label[^>]*>Name<\/label>\s*<div class="wp-block-group wrap\b/', $front );
$checks['the frame holds the icon and the control alone'] = 1 === preg_match( '/<div class="wp-block-group wrap\b[^>]*><figure\b.*?<\/figure>\s*<input\b[^>]*name=\'g1-field-0\'[^>]*>\s*<\/div>/s', $front );
$checks['the field box generates no box of its own'] = 1 === preg_match( '/<div style="display:contents" class=\'[^\']*grunion-field-wrap/', $front );

list( $markup, $front ) = $seed( '<form><div class="field"><label for="n">Name</label><input id="n" name="n"></div><div class="field"><label for="p">Phone</label><div class="wrap">' . $icon . '<input id="p" name="p" type="tel"></div></div><button type="submit">Send</button></form>' );
$checks['a telephone frame holds the icon and the tel control alone'] = str_contains( $markup, Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS ) && 1 === preg_match( '/<div class="wp-block-group wrap\b[^>]*><figure\b.*?<\/figure>\s*<input\b[^>]*type=\'tel\'[^>]*>\s*<\/div>/s', $front );

list( $markup ) = $seed( '<form><div class="field"><div class="wrap">' . $icon . '<label for="n">Name</label><input id="n" name="n"></div></div><button type="submit">Send</button></form>' );
$checks['a frame holding authored copy is not marked'] = '' !== $markup && ! str_contains( $markup, Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS );

list( $markup ) = $seed( '<form><div class="field"><label for="n">Name</label><div class="wrap"><input id="n" name="n"></div></div><button type="submit">Send</button></form>' );
$checks['a frame without decoration is not marked'] = '' !== $markup && ! str_contains( $markup, Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS );

$plain = '<form><div class="x ' . Static_Site_Importer_Provider_Form_Runtime_V1::CONTROL_FRAME_CLASS . '"><p>No field</p></div></form>';
$checks['a frame without one provider field keeps its place'] = '<form><div class="x"><p>No field</p></div></form>' === Static_Site_Importer_Provider_Form_Runtime_V1::project_control_frames( $plain );
$checks['html without frames is untouched'] = '<form><div class="a"></div></form>' === Static_Site_Importer_Provider_Form_Runtime_V1::project_control_frames( '<form><div class="a"></div></form>' );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
if ( $failures ) {
	echo "\n--- front ---\n" . $front . "\n";
	exit( 1 );
}
echo "form decorated control frame passed\n";
