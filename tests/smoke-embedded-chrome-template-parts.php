<?php
/**
 * Smoke coverage for embedded-chrome template part separability.
 *
 * Landmarks nested inside the page body root (`<main>`) stay in the page
 * markup, so synthesizing shared header/footer template parts from them
 * renders the same chrome twice. Only separable body-level landmarks are
 * reusable site chrome, and the entrypoint page is the authoritative source
 * for the synthesized fallback parts.
 *
 * Run from the repository root:
 * php tests/smoke-embedded-chrome-template-parts.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', $title ) ?? '', '-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ?? '' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

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

if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
	/**
	 * Deterministic converter stub: wraps the fragment in a core/html block so
	 * part synthesis can be asserted without the Blocks Engine transformer.
	 *
	 * @param string $body HTML markup.
	 * @param string $from Source format.
	 * @param string $to   Target format.
	 * @return array{serialized_blocks:string}
	 */
	function blocks_engine_php_transformer_convert_format( string $body, string $from, string $to ): array {
		return array( 'serialized_blocks' => '<!-- wp:html -->' . $body . '<!-- /wp:html -->' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// 1. Single-root page (Figma-transformer shape): every landmark lives inside
// `<main class="figma-root">`. Nothing is separable, so no fragments and no
// synthesized template parts may be produced.
$embedded_chrome_html = '<!DOCTYPE html><html><head><title>Embedded</title></head><body>'
	. '<main class="figma-root">'
	. '<header class="figma-node-1-header"><nav class="figma-node-1-navigation"><a href="#news">News</a></nav><p>Logo</p></header>'
	. '<section class="hero"><h1>Hero</h1></section>'
	. '<footer class="figma-node-1-footer"><p>Footer</p></footer>'
	. '</main>'
	. '</body></html>';

$embedded_fragments = ( new Static_Site_Importer_Document( $embedded_chrome_html ) )->fragments();
$assert( '' === $embedded_fragments['header'], 'embedded_header_not_extracted', $embedded_fragments['header'] );
$assert( '' === $embedded_fragments['footer'], 'embedded_footer_not_extracted', $embedded_fragments['footer'] );
$assert( str_contains( $embedded_fragments['main'], 'figma-node-1-header' ), 'embedded_header_stays_in_page_body' );
$assert( str_contains( $embedded_fragments['main'], 'figma-node-1-footer' ), 'embedded_footer_stays_in_page_body' );

$embedded_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/theme',
	array(
		'entry_path'   => 'index.html',
		'source_files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => $embedded_chrome_html,
			),
		),
	)
);
$assert( ! is_wp_error( $embedded_result ), 'embedded_result_not_error', is_wp_error( $embedded_result ) ? $embedded_result->get_error_message() : '' );
$assert( is_array( $embedded_result ) && array() === $embedded_result['writes'], 'embedded_chrome_produces_no_template_parts', is_array( $embedded_result ) ? implode( ',', array_keys( $embedded_result['writes'] ) ) : '' );

// 2. Classic page shape: body-level header/nav/footer siblings of `<main>` are
// separable shared chrome and must keep producing template parts.
$classic_html = '<!DOCTYPE html><html><head><title>Classic</title></head><body>'
	. '<header class="site-header"><nav class="site-nav"><a href="/">Home</a></nav></header>'
	. '<main><h1>Welcome</h1><p>Body copy.</p></main>'
	. '<footer class="site-footer"><p>Classic Footer</p></footer>'
	. '</body></html>';

$classic_fragments = ( new Static_Site_Importer_Document( $classic_html ) )->fragments();
$assert( str_contains( $classic_fragments['header'], 'site-header' ), 'classic_header_extracted', $classic_fragments['header'] );
$assert( str_contains( $classic_fragments['footer'], 'site-footer' ), 'classic_footer_extracted', $classic_fragments['footer'] );
$assert( ! str_contains( $classic_fragments['main'], 'site-header' ), 'classic_page_body_excludes_header' );

$classic_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/theme',
	array(
		'entry_path'   => 'index.html',
		'source_files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => $classic_html,
			),
		),
	)
);
$assert( ! is_wp_error( $classic_result ), 'classic_result_not_error', is_wp_error( $classic_result ) ? $classic_result->get_error_message() : '' );
$classic_writes = is_array( $classic_result ) ? $classic_result['writes'] : array();
$assert( isset( $classic_writes['/tmp/theme/parts/header.html'] ), 'classic_header_part_written', implode( ',', array_keys( $classic_writes ) ) );
$assert( isset( $classic_writes['/tmp/theme/parts/footer.html'] ), 'classic_footer_part_written', implode( ',', array_keys( $classic_writes ) ) );

// 3. Entrypoint priority: when multiple classic pages carry separable headers,
// the entrypoint page wins over alphabetically-earlier files.
$archive_html = str_replace( 'site-header', 'archive-header', str_replace( 'site-footer', 'archive-footer', $classic_html ) );
$entrypoint_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/theme',
	array(
		'entry_path'   => 'index.html',
		'source_files' => array(
			array(
				'path'    => 'website/archive.html',
				'content' => $archive_html,
			),
			array(
				'path'    => 'website/index.html',
				'content' => $classic_html,
			),
		),
	)
);
$assert( ! is_wp_error( $entrypoint_result ), 'entrypoint_result_not_error', is_wp_error( $entrypoint_result ) ? $entrypoint_result->get_error_message() : '' );
$entrypoint_writes = is_array( $entrypoint_result ) ? $entrypoint_result['writes'] : array();
$entrypoint_header = (string) ( $entrypoint_writes['/tmp/theme/parts/header.html'] ?? '' );
$assert( str_contains( $entrypoint_header, 'site-header' ), 'entrypoint_header_preferred', $entrypoint_header );
$assert( ! str_contains( $entrypoint_header, 'archive-header' ), 'non_entrypoint_header_skipped' );

// 4. Nested-chrome entrypoint with a separable-chrome secondary page: the
// entrypoint yields nothing, so the fallback continues to the next source page.
$mixed_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/theme',
	array(
		'entry_path'   => 'index.html',
		'source_files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => $embedded_chrome_html,
			),
			array(
				'path'    => 'website/about.html',
				'content' => $classic_html,
			),
		),
	)
);
$assert( ! is_wp_error( $mixed_result ), 'mixed_result_not_error', is_wp_error( $mixed_result ) ? $mixed_result->get_error_message() : '' );
$mixed_writes = is_array( $mixed_result ) ? $mixed_result['writes'] : array();
$mixed_header = (string) ( $mixed_writes['/tmp/theme/parts/header.html'] ?? '' );
$assert( str_contains( $mixed_header, 'site-header' ), 'mixed_fallback_uses_separable_page', $mixed_header );

if ( ! empty( $failures ) ) {
	echo implode( "\n", $failures ) . "\n";
	echo 'FAILED: ' . count( $failures ) . ' of ' . $assertions . " assertions\n";
	exit( 1 );
}

echo 'PASS: ' . $assertions . " assertions\n";
