<?php
/**
 * Provider label defaults must not replace inherited source weights (#2890).
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
	if ( 1 !== preg_match( '/^\.ssi-form-[a-f0-9]{12} label\{font-weight:inherit\}$/m', $overlay ) ) {
		throw new RuntimeException( $name . ': provider label weight must yield to source inheritance within the form scope.' );
	}
	if ( 'authored 600' === $name && 1 !== preg_match( '/\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? \.ssi-node-[a-f0-9]{12}\{[^}]*font-weight:600(?:[;}])/', $overlay ) ) {
		throw new RuntimeException( 'An authored label weight must retain its higher-specificity node carriage.' );
	}
}

echo "PASS: provider label weight inheritance\n";
