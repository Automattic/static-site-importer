<?php
/**
 * Smoke test: media-scoped source selectors transpose to generated block wrappers.
 *
 * Run from the repository root:
 * php tests/smoke-css-selector-transposition.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$css = <<<'CSS'
.feature-row { display: flex; }
.feature-row div { display: grid; }
.feature-row span { color: blue; }
.gallery img:first-child { border-radius: 24px; }
@media (max-width: 560px) {
	.contact-actions .btn { width: 100%; display: block; }
	.hours-table div { grid-template-columns: 1fr; }
	.gallery img:first-child { width: 100%; }
}
@supports (display: grid) {
	.feature-row strong { font-weight: 700; }
}
CSS;

$method = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'source_block_selector_transposition_bridge_css' );
$bridge = (string) $method->invoke( null, $css );

$button_method = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'button_style_bridge_css' );
$button_bridge = (string) $button_method->invoke( null, $css, array( 'btn' ) );

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( str_contains( $bridge, '@media (max-width: 560px)' ), 'media-scope-preserved', $bridge );
$assert( str_contains( $bridge, '@supports (display: grid)' ), 'supports-scope-preserved', $bridge );
$assert( str_contains( $bridge, '.wp-block-group.feature-row { display: flex; }' ), 'group-root-selector-transposed', $bridge );
$assert( str_contains( $bridge, '.wp-block-group.hours-table .wp-block-group { grid-template-columns: 1fr; }' ), 'row-descendant-selector-transposed', $bridge );
$assert( str_contains( $bridge, '.wp-block-group.feature-row p span, .wp-block-group.feature-row .wp-block-group span { color: blue; }' ), 'inline-descendant-selector-transposed', $bridge );
$assert( str_contains( $bridge, '.wp-block-group.gallery .wp-block-image:first-child, .wp-block-group.gallery .wp-block-image img:first-child { border-radius: 24px; }' ), 'image-selector-transposed', $bridge );
$assert( str_contains( $button_bridge, '@media (max-width: 560px)' ), 'button-media-scope-preserved', $button_bridge );
$assert( str_contains( $button_bridge, '.contact-actions .wp-block-button.btn > .wp-block-button__link { width: 100%; display: block; }' ), 'button-link-selector-transposed', $button_bridge );
$assert( str_contains( $button_bridge, '.contact-actions .wp-block-button.btn { width: 100% }' ), 'button-wrapper-layout-selector-transposed', $button_bridge );

$media_count = substr_count( $bridge, '@media (max-width: 560px)' );
$assert( 1 === $media_count, 'media-scope-deduped', 'count=' . $media_count . "\n" . $bridge );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: CSS selector transposition smoke passed (' . $assertions . " assertions)\n";
