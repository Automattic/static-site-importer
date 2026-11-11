<?php
/** A legend-captioned control's provider label takes the legend title's classes. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

$html = '<form><fieldset><legend id="phone-title"><div class="heading"><span>Phone</span></div></legend><div><label class="caption" for="ph"></label><input id="ph" type="text" name="phone" aria-labelledby="phone-title"></div></fieldset><button type="submit">Send</button></form>';
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array( 'index.html' => $html ),
) )->toArray();
$form = current( array_filter( $compiled['fallbacks'], static fn( array $row ): bool => ! empty( $row['controls'] ) ) );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
$markup = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0]['block_markup'];
preg_match( '/<!-- wp:jetpack\/label (\{.*?\}) \/?-->/s', $markup, $match );
$phone = json_decode( $match[1] ?? '{}', true );
$ok = 'Phone' === trim( $phone['label'] ?? '' ) && 'heading' === ( $phone['className'] ?? '' );
echo ( $ok ? 'PASS' : 'FAIL' ) . ' legend title supplies the caption classes, not the empty binding label: ' . json_encode( $phone ) . "\n";
exit( $ok ? 0 : 1 );
