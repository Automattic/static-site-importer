<?php
/**
 * Capture vocabulary lockstep with the locked Blocks Engine.
 *
 * SSI and Blocks Engine read the same capture evidence, published by Blocks
 * Engine as `Contract\CaptureEvidence` (Automattic/blocks-engine#2725). This
 * smoke fails when the locked php-transformer predates that contract, so SSI
 * can never ship the neutral names against a Blocks Engine that still reads
 * producer-specific ones, and when a name SSI reads is not one Blocks Engine
 * publishes, byte for byte, so the two layers can never disagree on a name.
 *
 * Run from the repository root:
 * php tests/smoke-capture-vocabulary-lockstep.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- Standalone CLI smoke; WordPress is not loaded.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-capture-evidence.php';

$contract = 'Automattic\\BlocksEngine\\PhpTransformer\\Contract\\CaptureEvidence';
$version  = (string) \Composer\InstalledVersions::getPrettyVersion( 'automattic/blocks-engine-php-transformer' );
$failures = array();

if ( ! class_exists( $contract ) ) {
	fwrite( STDERR, "FAIL: the locked blocks-engine-php-transformer $version has no Contract\\CaptureEvidence; require the Blocks Engine release with Automattic/blocks-engine#2725.\n" );
	exit( 1 );
}

$published = array_flip( array_filter( ( new ReflectionClass( $contract ) )->getConstants(), 'is_string' ) );
$names     = ( new ReflectionClass( Static_Site_Importer_Capture_Evidence::class ) )->getConstants();
foreach ( $names as $name => $value ) {
	if ( ! isset( $published[ $value ] ) ) {
		$failures[] = "Static_Site_Importer_Capture_Evidence::$name ($value) is not a name Blocks Engine publishes in Contract\\CaptureEvidence.";
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo 'smoke-capture-vocabulary-lockstep: ok (blocks-engine-php-transformer ' . $version . '; ' . count( $names ) . " names published by Blocks Engine)\n";
