<?php
/**
 * Smoke coverage for header/footer template-part shell dedupe.
 *
 * Run from the repository root:
 * php tests/smoke-template-part-shell-dedupe.php
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

$header = '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><p>Global Header</p></header><!-- /wp:group -->';
$footer = '<!-- wp:group {"tagName":"footer","className":"site-footer"} --><footer class="wp-block-group site-footer"><p>Global Footer</p></footer><!-- /wp:group -->';
$body   = '<!-- wp:paragraph --><p>Article body remains.</p><!-- /wp:paragraph -->';
$article_header = '<!-- wp:group {"tagName":"header","className":"article-header"} --><header class="wp-block-group article-header"><h2>Article Header</h2></header><!-- /wp:group -->';
$article_footer = '<!-- wp:group {"tagName":"footer","className":"article-footer"} --><footer class="wp-block-group article-footer"><p>Article Footer</p></footer><!-- /wp:group -->';

$page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'index.html',
		'title'        => 'Home',
		'block_markup' => $header . "\n" . $body . "\n" . $footer,
	)
);

$template_part_writes = array(
	'/tmp/ssi-theme/parts/header.html' => $header,
	'/tmp/ssi-theme/parts/footer.html' => $footer,
);

$artifacts = $page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'index.html' => $page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$content   = (string) ( $artifacts['contents']['index.html'] ?? '' );

$assert( ! str_contains( $content, 'Global Header' ), 'leading-header-shell-removed' );
$assert( ! str_contains( $content, 'Global Footer' ), 'trailing-footer-shell-removed' );
$assert( str_contains( $content, 'Article body remains.' ), 'page-body-preserved' );
$assert( 'template_part_shell_deduped' === ( $artifacts['diagnostics'][0]['type'] ?? '' ), 'dedupe-diagnostic-emitted' );
$assert( 1 === ( $artifacts['diagnostics'][0]['removed']['leading_blocks'] ?? 0 ), 'diagnostic-leading-count' );
$assert( 1 === ( $artifacts['diagnostics'][0]['removed']['trailing_blocks'] ?? 0 ), 'diagnostic-trailing-count' );
$assert( 1 === ( $artifacts['diagnostics'][0]['removed_header_blocks'] ?? 0 ), 'diagnostic-removed-header-blocks' );
$assert( 1 === ( $artifacts['diagnostics'][0]['removed_footer_blocks'] ?? 0 ), 'diagnostic-removed-footer-blocks' );
$assert( 'parts/header.html' === ( $artifacts['diagnostics'][0]['matched_header_template_part'] ?? '' ), 'diagnostic-header-template-part-match' );
$assert( 'parts/footer.html' === ( $artifacts['diagnostics'][0]['matched_footer_template_part'] ?? '' ), 'diagnostic-footer-template-part-match' );
$assert( 0 === ( $artifacts['diagnostics'][0]['preserved_local_header_count'] ?? -1 ), 'diagnostic-no-local-header-after-global-dedupe' );
$assert( 0 === ( $artifacts['diagnostics'][0]['preserved_local_footer_count'] ?? -1 ), 'diagnostic-no-local-footer-after-global-dedupe' );

$local_shell_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'local-shell.html',
		'title'        => 'Local Shell',
		'block_markup' => $header . "\n" . $article_header . "\n" . $body . "\n" . $article_footer . "\n" . $footer,
	)
);

$local_shell_artifacts = $local_shell_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'local-shell.html' => $local_shell_page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$local_shell_content   = (string) ( $local_shell_artifacts['contents']['local-shell.html'] ?? '' );

$assert( ! str_contains( $local_shell_content, 'Global Header' ), 'local-shell-global-header-removed' );
$assert( ! str_contains( $local_shell_content, 'Global Footer' ), 'local-shell-global-footer-removed' );
$assert( str_contains( $local_shell_content, 'Article Header' ), 'local-shell-local-header-preserved' );
$assert( str_contains( $local_shell_content, 'Article Footer' ), 'local-shell-local-footer-preserved' );
$assert( 1 === ( $local_shell_artifacts['diagnostics'][0]['preserved_local_header_count'] ?? 0 ), 'diagnostic-local-header-count-preserved' );
$assert( 1 === ( $local_shell_artifacts['diagnostics'][0]['preserved_local_footer_count'] ?? 0 ), 'diagnostic-local-footer-count-preserved' );

$local_page     = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'article.html',
		'title'        => 'Article',
		'block_markup' => $body . "\n" . $article_header . "\n" . $article_footer . "\n" . $body,
	)
);

$local_artifacts = $local_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'article.html' => $local_page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$local_content   = (string) ( $local_artifacts['contents']['article.html'] ?? '' );

$assert( str_contains( $local_content, 'Article Header' ), 'article-local-header-preserved' );
$assert( str_contains( $local_content, 'Article Footer' ), 'article-local-footer-preserved' );
$assert( array() === ( $local_artifacts['diagnostics'] ?? array() ), 'article-local-no-dedupe-diagnostic' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS smoke-template-part-shell-dedupe.php (' . $assertions . " assertions)\n";
