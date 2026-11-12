<?php
/**
 * Provider label defaults must not replace inherited source weights (#2890),
 * and the reset must not replace a source label's class-owned weight.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

foreach ( array( 'inherited 400' => 'body{font-weight:400}', 'authored 600' => 'body{font-weight:400}.ctx label{font-weight:600}' ) as $name => $css ) {
	$html      = '<style>' . $css . '</style><section class="ctx"><form><label for="e">Email Address</label><input id="e" name="email" type="email"><button type="submit">Send</button></form></section>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'contact.html',
			'files'      => array( 'contact.html' => $html ),
		)
	)->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
	$overlay   = (string) ( $row['provider_layout_overlay_css']['css'] ?? '' );
	// More specific than Jetpack's .contact-form :where(label), but less
	// specific than the authored .ssi-form-* .ssi-node-* carriage below.
	if ( 1 !== preg_match( '/^(\.ssi-form-[a-f0-9]{12} label[^{]*)\{font-weight:inherit\}$/m', $overlay, $reset ) ) {
		throw new RuntimeException( $name . ': provider label weight must yield to source inheritance within the form scope.' );
	}
	$parsed = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse( $reset[1] );
	if ( 11 !== Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::specificity( $parsed ) ) {
		throw new RuntimeException( 'The label reset must weigh (0,1,1).' );
	}
	// Provider and classless labels reset; a source label keeps its own class rule.
	$doc = new DOMDocument();
	$doc->loadHTML( '<div class="' . substr( strtok( $reset[1], ' ' ), 1 ) . '"><label class="grunion-field-label email">a</label><label class="jetpack-field-rating__label">b</label><label>c</label><label class="_label_ys16b_2">d</label></div>' );
	$matched = array();
	foreach ( $doc->getElementsByTagName( 'label' ) as $label ) {
		$matched[] = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches( $label, $parsed )['matches'];
	}
	if ( array( true, true, true, false ) !== $matched ) {
		throw new RuntimeException( 'The label reset must reach provider and classless labels only: ' . wp_json_encode( $matched ) );
	}
	if ( 'authored 600' === $name && 1 !== preg_match( '/\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? \.ssi-node-[a-f0-9]{12}\{[^}]*font-weight:600(?:[;}])/', $overlay ) ) {
		throw new RuntimeException( 'An authored label weight must retain its higher-specificity node carriage.' );
	}
}

echo "PASS: provider label weight inheritance\n";
