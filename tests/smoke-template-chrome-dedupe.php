<?php
/**
 * Smoke coverage for page chrome dedupe when theme template parts exist.
 *
 * Run from the repository root:
 * php tests/smoke-template-chrome-dedupe.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );
		return $value;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( $title ) ) ?? '', '-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
if ( ! is_readable( $parser ) || ! is_readable( $blocks ) ) {
	fwrite( STDERR, "SKIP: WordPress parser/serializer files are unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 0 );
}

require_once $parser;
require_once $blocks;
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$chrome_markup = implode(
	'',
	array(
		'<!-- wp:paragraph {"className":"skip-link"} --><p class="skip-link"><a href="#main">Skip to content</a></p><!-- /wp:paragraph -->',
		'<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><!-- wp:navigation --><!-- wp:navigation-link {"label":"Product","url":"#product"} /--><!-- /wp:navigation --></header><!-- /wp:group -->',
		'<!-- wp:group {"className":"hero"} --><div class="wp-block-group hero"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Every launch.</h1><!-- /wp:heading --></div><!-- /wp:group -->',
		'<!-- wp:group {"tagName":"footer","className":"site-footer"} --><footer class="wp-block-group site-footer"><!-- wp:paragraph --><p>Relay Atlas</p><!-- /wp:paragraph --></footer><!-- /wp:group -->',
	)
);

$page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'index.html',
		'title'        => 'Relay Atlas',
		'slug'         => 'home',
		'block_markup' => $chrome_markup,
	)
);

$assert( ! is_wp_error( $page ), 'source-page-created', is_wp_error( $page ) ? $page->get_error_message() : '' );

if ( ! is_wp_error( $page ) ) {
	$without_template_parts = Static_Site_Importer_Page_Materializer::page_artifacts( array( 'index.html' => $page ), 'relay-atlas' );
	$assert( str_contains( $without_template_parts['contents']['index.html'], 'tagName":"header' ), 'keeps-header-without-template-part' );
	$assert( str_contains( $without_template_parts['contents']['index.html'], 'tagName":"footer' ), 'keeps-footer-without-template-part' );

	$with_template_parts = Static_Site_Importer_Page_Materializer::page_artifacts(
		array( 'index.html' => $page ),
		'relay-atlas',
		array(),
		array(),
		array(),
		array(
			'strip_template_header' => true,
			'strip_template_footer' => true,
		)
	);

	$content = $with_template_parts['contents']['index.html'];
	$assert( ! str_contains( $content, 'tagName":"header' ), 'strips-leading-header-when-template-part-exists' );
	$assert( ! str_contains( $content, 'tagName":"footer' ), 'strips-trailing-footer-when-template-part-exists' );
	$assert( str_contains( $content, 'Skip to content' ), 'keeps-leading-accessibility-skip-link' );
	$assert( str_contains( $content, 'Every launch.' ), 'keeps-page-body-content' );

	$parts = array_values( array_filter( array_map( static fn( array $diagnostic ): string => (string) ( $diagnostic['part'] ?? '' ), $with_template_parts['diagnostics'] ) ) );
	$assert( array( 'header', 'footer' ) === $parts, 'reports-deduped-template-parts', wp_json_encode( $parts ) ?: '' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "OK: {$assertions} assertions\n" );
