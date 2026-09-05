<?php
/**
 * Smoke test: source normalization preserves all source HTML.
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

$html       = '<main>Authored content</main><div id="footer-signup-container">Source chrome</div>';
$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$normalized = Static_Site_Importer_Source_Normalizer::normalize_html( $html, 'https://example.test/' );
$assert( $html === $normalized['html'], 'normalization-preserves-source-html' );
$assert( array() === $normalized['diagnostics'], 'unchanged-source-reports-no-diagnostics' );
$assert( ! array_key_exists( 'exclusions', $normalized ), 'normalizer-no-longer-emits-exclusion-receipts' );

// Arbitrary caller options can never opt into source removal.
$with_options = Static_Site_Importer_Source_Normalizer::normalize_html( $html, 'https://example.test/', array( 'source_provider_policy' => array( 'provider' => 'anything', 'verified' => true ) ) );
$assert( $html === $with_options['html'], 'caller-options-cannot-remove-source-html' );

// Cloudflare email obfuscation stays: it decodes an unreadable address into the
// address the source document intended to publish. Nothing is removed.
$obfuscated       = bin2hex( "\x10" . ( 'a@b.co' ^ str_repeat( "\x10", 6 ) ) );
$email_html       = '<a href="/cdn-cgi/l/email-protection#' . $obfuscated . '">Mail</a>';
$email_normalized = Static_Site_Importer_Source_Normalizer::normalize_html( $email_html, 'https://example.test/' );
$assert( str_contains( $email_normalized['html'], 'href="mailto:a@b.co"' ), 'cloudflare-email-link-decoded' );
$assert( 'cloudflare_email_link_decoded' === ( $email_normalized['diagnostics'][0]['reason_code'] ?? '' ), 'cloudflare-decode-is-diagnosed' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Source normalizer smoke passed (%d assertions).\n", $assertions );
