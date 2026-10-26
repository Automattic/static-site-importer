<?php
/**
 * Shorthand/longhand presentation ordering regression (#2223).
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

foreach ( array( false, true ) as $reverse ) {
	$css       = $reverse
		? 'button{background:#171717}.ctx .submit{background-color:white}'
		: 'button{background-color:transparent}.ctx .submit{background:#171717}';
	$html      = '<style>' . $css . '</style><section class="ctx"><form><input name="email"><button class="submit" type="submit">Send</button></form></section>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'contact.html',
			'files'      => array( 'contact.html' => $html ),
		)
	)->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
	$overlay   = $row['provider_layout_overlay_css']['css'];
	if ( ! $reverse && str_contains( $overlay, 'background-color:transparent' ) ) {
		throw new RuntimeException( 'A winning shorthand must suppress the stale longhand.' );
	}
	if ( $reverse && ! str_contains( $overlay, 'background:#171717;background-color:white' ) ) {
		throw new RuntimeException( 'A winning longhand must follow the shorthand without discarding its other components.' );
	}
}
// Exercise the overlay independently of the producer's alphabetic style keys.
$resolve = new ReflectionMethod( Static_Site_Importer_Form_Presentation_Overlay::class, 'resolve_presentation_cascade' );
foreach ( array( false, true ) as $longhand_wins ) {
	$styles   = $resolve->invoke(
		null,
		array(
			'background'       => '#171717',
			'background_color' => 'transparent',
		),
		array(),
		array(
			'background'       => array(
				'important'    => false,
				'specificity'  => 10,
				'source_order' => $longhand_wins ? 1 : 2,
			),
			'background-color' => array(
				'important'    => false,
				'specificity'  => 10,
				'source_order' => $longhand_wins ? 2 : 1,
			),
		)
	);
	$expected = $longhand_wins
		? array(
			'background'       => '#171717',
			'background_color' => 'transparent',
		)
		: array( 'background' => '#171717' );
	if ( $expected !== $styles ) {
		throw new RuntimeException( 'Overlay must resolve shorthand/longhand source order in either direction.' );
	}
}
echo "PASS: presentation shorthand cascade\n";
