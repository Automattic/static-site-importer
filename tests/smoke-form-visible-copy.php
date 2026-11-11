<?php
/**
 * Regression for #2898: visible field copy renders once, in source order.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cases = array(
	'label-description' => array( '<div class="field"><label for="em"><span>Email</span><span class="description required">(required)</span></label><p class="description">Email</p><input id="em" type="email" aria-required="true"></div>', array( 'Email', '(required)', 'Email', 'INPUT' ) ),
	'legend-description' => array( '<div class="field"><label for="n">Name</label><input id="n" type="text"></div><fieldset class="form-item phone"><legend id="ph-w"><div class="title"><span>Phone</span><span class="description required">(required)</span></div><p class="description">Phone</p></legend><div class="field"><label class="caption" for="ph"><div class="caption-text"></div></label><input id="ph" type="text" name="fullPhone" autocomplete="tel-national" aria-labelledby="ph-w" aria-required="true"></div></fieldset>', array( 'Name', 'INPUT', 'Phone', '(required)', 'Phone', 'INPUT' ) ),
);
foreach ( $cases as $name => [ $field, $expected ] ) {
	$html      = '<form>' . $field . '<button type="submit">Send</button></form>';
	$compiled  = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'c.html', 'files' => array( 'c.html' => $html ) ) )->toArray();
	$fallback  = array_values( array_filter( $compiled['fallbacks'], static fn( $f ) => ! empty( $f['controls'] ) ) )[0];
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
	$markup    = $row['block_markup'];
	// Model visible render order, including Jetpack help rendered after input.
	$copy = array();
	$help = array();
	preg_match_all( '/<!-- wp:([\w\/-]+) (\{.*?\}) \/?-->|>([^<>]+)(?=<)/s', $markup, $tokens, PREG_SET_ORDER );
	foreach ( $tokens as $token ) {
		if ( ! empty( $token[1] ) ) {
			$attrs = json_decode( $token[2], true ) ?: array();
			if ( isset( $attrs['helpText'] ) ) {
				$help[] = $attrs['helpText'];
			}
			if ( 'jetpack/label' === $token[1] ) {
				$copy[] = $attrs['label'] ?? '';
				if ( isset( $attrs['requiredText'] ) ) {
					$copy[] = $attrs['requiredText'];
				}
			}
			if ( in_array( $token[1], array( 'jetpack/input', 'jetpack/phone-input', 'jetpack/input-phone' ), true ) ) {
				$copy[] = 'INPUT';
				array_push( $copy, ...$help );
				$help = array();
			}
		} elseif ( '' !== trim( $token[3] ?? '' ) && 'Send' !== trim( $token[3] ) ) {
			$copy[] = trim( html_entity_decode( $token[3] ) );
		}
	}
	if ( $copy !== $expected || 'mapped' !== $row['status'] || ! empty( $validated['errors'] ) ) {
		fwrite( STDERR, "FAIL $name: " . json_encode( $copy ) . "\n" . $markup . "\n" );
		exit( 1 );
	}
}
echo "form visible copy passed\n";
