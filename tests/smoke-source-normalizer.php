<?php
/**
 * Smoke test: source platform exclusions require verified provider policy.
 *
 * Run from the repository root:
 * php tests/smoke-source-normalizer.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-normalizer.php';

$html = '<main>Authored content</main><div id="weebly-footer-signup-container-v3">Platform chrome</div>';
$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$default = Static_Site_Importer_Source_Normalizer::normalize_html( $html, 'https://example.test/' );
$assert( $html === $default['html'] && array() === $default['exclusions'], 'default-policy-preserves-platform-chrome' );

$weebly = Static_Site_Importer_Source_Normalizer::normalize_html( $html, 'https://example.test/', array( 'source_provider_policy' => array( 'provider' => 'weebly', 'verified' => true ) ) );
$receipt = $weebly['exclusions'][0] ?? array();
$assert( ! str_contains( $weebly['html'], 'weebly-footer-signup-container-v3' ), 'verified-weebly-policy-removes-platform-chrome' );
$assert( 1 === count( $weebly['exclusions'] ) && hash( 'sha256', $html ) === ( $receipt['source_sha256'] ?? '' ) && hash( 'sha256', $weebly['html'] ) === ( $receipt['normalized_sha256'] ?? '' ) && 64 === strlen( (string) ( $receipt['removed_sha256'] ?? '' ) ), 'weebly-removal-retains-hash-bound-receipt' );

$unrelated = Static_Site_Importer_Source_Normalizer::normalize_html( $html, 'https://example.test/', array( 'source_provider_policy' => array( 'provider' => 'squarespace', 'verified' => true ) ) );
$assert( $html === $unrelated['html'] && array() === $unrelated['exclusions'], 'unrelated-provider-policy-preserves-platform-chrome' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Source normalizer smoke passed (%d assertions).\n", $assertions );
