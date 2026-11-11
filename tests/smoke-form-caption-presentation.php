<?php
/** Source captions retain their presentation through the public compiler and seeder. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

$html = '<form><fieldset><legend id="phone-title"><div class="heading"><span>Phone</span></div></legend><div><label class="caption" for="ph"></label><input id="ph" type="text" name="phone" aria-labelledby="phone-title"></div></fieldset>'
	. '<label for="em" class="heading"><div class="caption-row"><span>Email</span><span class="description required">(required)</span></div></label><input id="em" type="email" required><button type="submit">Send</button></form>';
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array( 'index.html' => $html ),
) )->toArray();
$form = current( array_filter( $compiled['fallbacks'], static fn( array $row ): bool => ! empty( $row['controls'] ) ) );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
$markup = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0]['block_markup'];
preg_match_all( '/<!-- wp:jetpack\/label (\{.*?\}) \/?-->/s', $markup, $matches );
$labels = array_map( static fn( string $json ): array => json_decode( $json, true ), $matches[1] );
$phone = $labels[0] ?? array();
$email = $labels[1] ?? array();
$checks = array(
	'legend supplies caption classes, not the empty binding label' => 'heading' === ( $phone['className'] ?? '' ),
	'marker retains its typography hooks and spacing wrapper' => str_contains( $email['label'] ?? '', 'class="caption-row"' ) && str_contains( $email['label'] ?? '', 'class="description required"' ),
	'provider does not synthesize a duplicate required marker' => false === ( $email['requiredIndicator'] ?? null ) && ! isset( $email['requiredText'] ),
	'validation remains required' => str_contains( $markup, '"required":true' ),
);
foreach ( $checks as $name => $passed ) {
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
