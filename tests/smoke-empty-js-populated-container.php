<?php
/**
 * Smoke coverage for detecting empty client-side rendered containers.
 *
 * Run from the repository root:
 * php tests/smoke-empty-js-populated-container.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$detect = static function ( string $html ): array {
	$reflection = new ReflectionClass( Static_Site_Importer_Page_Materializer::class );
	$method     = $reflection->getMethod( 'find_empty_js_populated_containers' );

	return $method->invoke( null, $html );
};

$page_selector_of = static function ( array $containers ): array {
	return array_map(
		static fn ( array $c ): string => (string) $c['selector'],
		$containers
	);
};

// Data-* carriers are the strongest signal and are always flagged.
$found = $detect( '<div id="featuredGrid" data-slider-container></div>' );
$assert( 1 === count( $found ), 'flag-data-attr-carrier', print_r( $page_selector_of( $found ), true ) );

// App-shell id paired with a content-naming class is a flagged mount.
$found = $detect( '<div id="root" class="product-list"></div>' );
$assert( 1 === count( $found ), 'flag-app-shell-plus-content-name', print_r( $page_selector_of( $found ), true ) );

// An app-shell id alone is not enough to claim JS population.
$assert( array() === $detect( '<div id="__next"></div>' ), 'ignore-app-shell-id-alone' );

// The exact ticket reproduction combines a content name and collection shape.
$found = $detect( '<div id="featuredGrid" class="wp-block-group grid"></div>' );
$assert( 1 === count( $found ), 'flag-ticket-featured-grid', print_r( $page_selector_of( $found ), true ) );

// A single content name without a data-* hook is not enough.
$assert( array() === $detect( '<div id="products" class="wp-block-group"></div>' ), 'ignore-content-name-without-data-hook' );

// Legitimate empty spacer and layout divs must never be reported.
$assert( array() === $detect( '<div class="spacer"></div>' ), 'ignore-spacer' );
$assert( array() === $detect( '<div class="grid"></div>' ), 'ignore-layout-grid' );

// A non-empty container with a signal is not an absent-content gap.
$assert( array() === $detect( '<div id="featuredGrid" data-grid><p>Static item</p></div>' ), 'ignore-non-empty' );

// SSI-internal data attributes are excluded.
$assert( array() === $detect( '<div data-ssi-fragment-root="1"></div>' ), 'ignore-internal-data-attr' );

// Section and list containers are scanned too, not just divs.
$found = $detect( '<section id="products" data-products></section>' );
$assert( 1 === count( $found ), 'flag-section-data-attr', print_r( $page_selector_of( $found ), true ) );

// Empty sheet full of comments is still empty; a data hook still flags it.
$found = $detect( '<ul id="listings" data-tiles><!-- populated at runtime --></ul>' );
$assert( 1 === count( $found ), 'flag-comment-only-data-attr', print_r( $page_selector_of( $found ), true ) );

// Loss-class classification surfaces as unsupported loss.
$diagnostic = Static_Site_Importer_Page_Materializer::class;
$reflection = new ReflectionClass( $diagnostic );
$method     = $reflection->getMethod( 'empty_js_populated_container_diagnostic' );
$row        = $method->invoke( null, array( 'tag_name' => 'div', 'selector' => 'div#grid', 'id' => 'grid', 'class' => '', 'data_attributes_observed' => array( 'data-list' ) ), 'website/index.html' );
$assert( 'empty_js_populated_container' === $row['type'], 'diagnostic-type' );
$assert( 'info' === $row['severity'], 'diagnostic-severity' );
$assert( 'unsupported_loss' === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $row ), 'diagnostic-loss-class' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: empty client-side rendered container smoke passed (' . $assertions . " assertions)\n";
