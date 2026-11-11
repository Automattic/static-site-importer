<?php
/**
 * Regression for #2896: a repeated description must not consume the label.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html      = '<form><div class="field"><label for="em"><span>Email</span><span class="description required">(required)</span></label><p class="description">Email</p><input id="em" type="email" aria-required="true"></div><button type="submit">Send</button></form>';
$compiled  = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'c.html', 'files' => array( 'c.html' => $html ) ) )->toArray();
$fallback  = array_values( array_filter( $compiled['fallbacks'], static fn( array $form ): bool => ! empty( $form['controls'] ) ) )[0];
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$markup    = $row['block_markup'];
preg_match( '/wp:jetpack\/label (\{.*?\}) -->/', $markup, $match );
$label = json_decode( $match[1] ?? '{}', true );

$checks = array(
	'form maps'                  => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'email label keeps its text'  => 'Email' === trim( $label['label'] ?? '' ),
	'email label stays visible'   => false !== ( $label['metadata']['blockVisibility'] ?? true ),
	'description stays visible once' => ! str_contains( $markup, '"helpText":"Email"' ) && 1 === substr_count( $markup, '>Email</p>' ),
);

// Keep the existing appended-description behavior at the field-markup boundary.
$control = array( 'label' => 'Email Contact details', 'description' => 'Contact details' );
$block   = Static_Site_Importer_Form_Field_Markup::field_block_from_control( 'input', 'email', $control );
$checks['appended description is trimmed']    = 'Email' === ( $block['innerBlocks'][0]['attrs']['label'] ?? null );
$checks['trimmed description stays helpText'] = 'Contact details' === ( $block['attrs']['helpText'] ?? null );

$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . "\n" . $markup . "\n" );
	exit( 1 );
}
echo "form label equals description passed\n";
