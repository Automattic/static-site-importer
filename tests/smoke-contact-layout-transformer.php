<?php
/**
 * Smoke coverage for generic contact layout decomposition.
 *
 * Run from the repository root:
 * php tests/smoke-contact-layout-transformer.php
 *
 * @package StaticSiteImporter
 */

$transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php';
require_once $transformer_bootstrap;

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$collect_block_names = static function ( array $blocks ) use ( &$collect_block_names ): array {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$names[] = (string) ( $block['blockName'] ?? '' );
		$names   = array_merge( $names, $collect_block_names( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) );
	}
	return $names;
};

$static_contact_html = '<section class="contact-layout"><div class="contact-info"><h2>Contact</h2><p>Email <a href="mailto:hello@example.com">hello@example.com</a></p><p>Call <a href="tel:+15551234567">+1 555 123 4567</a></p><p>Follow <a href="https://example.com/social">Instagram</a></p></div></section>';
$static_result       = blocks_engine_php_transformer_transform_html( $static_contact_html, array( 'include_conversion_report' => true ) );
$static_names        = $collect_block_names( $static_result['blocks'] ?? array() );
$static_fallbacks    = $static_result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();

$assert( in_array( 'core/group', $static_names, true ), 'static-contact-layout-group' );
$assert( in_array( 'core/heading', $static_names, true ), 'static-contact-layout-heading' );
$assert( in_array( 'core/paragraph', $static_names, true ), 'static-contact-layout-paragraphs' );
$assert( ! in_array( 'core/html', $static_names, true ), 'static-contact-layout-no-core-html' );
$assert( array() === $static_fallbacks, 'static-contact-layout-no-fallback-diagnostics' );

$form_contact_html = '<section class="contact-layout"><div class="contact-info"><h2>Contact</h2><p>Email <a href="mailto:hello@example.com">hello@example.com</a></p></div><div class="contact-form"><form class="contact" action="/contact" method="post"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><button type="submit">Send</button></form></div></section>';
$form_result       = blocks_engine_php_transformer_transform_html( $form_contact_html, array( 'include_conversion_report' => true ) );
$form_names        = $collect_block_names( $form_result['blocks'] ?? array() );
$form_fallbacks    = $form_result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$form_fallback     = $form_fallbacks[0] ?? array();

$assert( in_array( 'core/group', $form_names, true ), 'form-contact-layout-keeps-native-wrapper' );
$assert( in_array( 'core/paragraph', $form_names, true ), 'form-contact-layout-keeps-native-copy' );
$assert( 1 === count( array_filter( $form_names, static fn ( string $name ): bool => 'core/html' === $name ) ), 'form-contact-layout-single-runtime-html-island' );
$assert( 1 === count( $form_fallbacks ), 'form-contact-layout-single-provider-finding' );
$assert( 'html_form_fallback' === ( $form_fallback['diagnostic_code'] ?? '' ), 'form-contact-layout-provider-diagnostic-code' );
$assert( 'form_requires_runtime' === ( $form_fallback['reason'] ?? '' ), 'form-contact-layout-provider-runtime-reason' );
$assert( 'form' === ( $form_fallback['suggested_primitive'] ?? '' ), 'form-contact-layout-provider-primitive' );
$assert( 3 === ( $form_fallback['control_count'] ?? 0 ), 'form-contact-layout-controls-preserved' );

if ( array() !== $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo 'OK smoke-contact-layout-transformer (' . $assertions . ' assertions)' . PHP_EOL;
