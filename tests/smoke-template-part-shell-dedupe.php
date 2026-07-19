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
$skip_link = '<!-- wp:html --><a class="skip-link" href="#main">Skip to content</a><!-- /wp:html -->';
$wrapped_skip_link = '<!-- wp:paragraph {"className":"skip-link"} --><p class="skip-link wp-block-paragraph"><a href="#main">Skip to content</a></p><!-- /wp:paragraph -->';

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

$skip_link_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'skip-link.html',
		'title'        => 'Skip Link',
		'block_markup' => $skip_link . "\n" . $header . "\n" . $body . "\n" . $footer,
	)
);

$skip_link_artifacts = $skip_link_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'skip-link.html' => $skip_link_page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$skip_link_content   = (string) ( $skip_link_artifacts['contents']['skip-link.html'] ?? '' );

$assert( str_contains( $skip_link_content, 'Skip to content' ), 'skip-link-preserved-before-global-header' );
$assert( ! str_contains( $skip_link_content, 'Global Header' ), 'skip-link-followed-global-header-removed' );
$assert( ! str_contains( $skip_link_content, 'Global Footer' ), 'skip-link-page-footer-removed' );
$assert( str_contains( $skip_link_content, 'Article body remains.' ), 'skip-link-page-body-preserved' );

$body_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'body-header.html',
		'title'        => 'Body Header',
		'block_markup' => $body . "\n" . $header . "\n" . $footer,
	)
);

$body_header_artifacts = $body_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'body-header.html' => $body_header_page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$body_header_content   = (string) ( $body_header_artifacts['contents']['body-header.html'] ?? '' );

$assert( str_contains( $body_header_content, 'Article body remains.' ), 'body-before-identical-header-preserved' );
$assert( str_contains( $body_header_content, 'Global Header' ), 'identical-header-after-body-preserved' );
$assert( ! str_contains( $body_header_content, 'Global Footer' ), 'body-header-page-footer-removed' );

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

$nonmatching_prelude_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'nonmatching-prelude.html',
		'title'        => 'Nonmatching Prelude',
		'block_markup' => $skip_link . "\n" . $article_header . "\n" . $body . "\n" . $footer,
	)
);

$nonmatching_prelude_artifacts = $nonmatching_prelude_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'nonmatching-prelude.html' => $nonmatching_prelude_page ),
	'ssi-theme',
	array(),
	array(),
	$template_part_writes
) : array();
$nonmatching_prelude_content   = (string) ( $nonmatching_prelude_artifacts['contents']['nonmatching-prelude.html'] ?? '' );

$assert( str_contains( $nonmatching_prelude_content, 'Skip to content' ), 'nonmatching-prelude-preserved' );
$assert( str_contains( $nonmatching_prelude_content, 'Article Header' ), 'nonmatching-header-preserved' );
$assert( ! str_contains( $nonmatching_prelude_content, 'Global Footer' ), 'nonmatching-prelude-footer-removed' );

$template_semantic_header = '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><nav><a href="/about">About</a></nav><a href="/back">Back home</a></header><!-- /wp:group -->';
$different_global_header = '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><nav><a href="/about">About</a></nav><a href="/try">Try the editor</a></header><!-- /wp:group -->';
$near_global_header      = str_replace( 'href="/about"', 'href="/journal"', $different_global_header );
$serialized_template_header = '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><!-- wp:navigation --><nav class="wp-block-navigation"><!-- wp:navigation-link {"label":"Home","url":"http:\/\/index.html"} /--><!-- wp:navigation-link {"label":"The Block Editor","url":"http:\/\/blocks.html"} /--><!-- /wp:navigation --></nav><a href="/back">Back home</a></header><!-- /wp:group -->';
$serialized_runtime_header = '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header"><!-- wp:navigation --><nav class="wp-block-navigation"><!-- wp:navigation-link {"label":"Home","url":"http:\/\/example.test\/home\/"} /--><!-- wp:navigation-link {"label":"The Block Editor","url":"http:\/\/example.test\/blocks\/"} /--><!-- /wp:navigation --></nav><a href="/try">Try the editor</a></header><!-- /wp:group -->';
$serialized_near_header = str_replace( 'example.test\\/blocks\\/', 'example.test\\/journal\\/', $serialized_runtime_header );
$role_global_header      = '<!-- wp:group {"tagName":"header","className":"route-chrome"} --><header class="wp-block-group route-chrome" role="banner"><p>Route-specific masthead</p></header><!-- /wp:group -->';
$different_local_header  = '<!-- wp:group {"tagName":"header","className":"article-header"} --><header class="wp-block-group article-header"><h1>Article title</h1></header><!-- /wp:group -->';
$semantic_template_part_writes = array(
	'/tmp/ssi-theme/parts/header.html' => $template_semantic_header,
);

$runtime_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'runtime-header.html',
		'title'        => 'Runtime Header',
		'block_markup' => $skip_link . "\n" . $different_global_header . "\n" . $body,
	)
);

$runtime_header_artifacts = $runtime_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'runtime-header.html' => $runtime_header_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes,
	array( 'strip_template_header' => true )
) : array();
$runtime_header_content   = (string) ( $runtime_header_artifacts['contents']['runtime-header.html'] ?? '' );
$runtime_header_diagnostic = $runtime_header_artifacts['diagnostics'][0] ?? array();

$assert( str_contains( $runtime_header_content, 'Skip to content' ), 'runtime-global-header-skip-link-preserved' );
$assert( ! str_contains( $runtime_header_content, 'Try the editor' ), 'runtime-different-global-header-removed' );
$assert( str_contains( $runtime_header_content, 'Article body remains.' ), 'runtime-global-header-body-preserved' );
$assert( 'semantic_header_identity_and_navigation_match' === ( $runtime_header_diagnostic['reason'] ?? '' ), 'runtime-global-header-diagnostic-reason' );
$assert( 1 === ( $runtime_header_diagnostic['removed_header_blocks'] ?? 0 ), 'runtime-global-header-diagnostic-count' );
$assert( 'parts/header.html' === ( $runtime_header_diagnostic['matched_header_template_part'] ?? '' ), 'runtime-global-header-template-match' );
$assert( 'stable_header_classes_and_navigation_destinations' === ( $runtime_header_diagnostic['header_match_signal'] ?? '' ), 'runtime-global-header-match-signal' );

$serialized_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'serialized-header.html',
		'title'        => 'Serialized Header',
		'block_markup' => $wrapped_skip_link . "\n" . $serialized_runtime_header . "\n" . $body,
	)
);
$serialized_header_artifacts = $serialized_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'serialized-header.html' => $serialized_header_page ),
	'ssi-theme',
	array(),
	array(),
	array( '/tmp/ssi-theme/parts/header.html' => $serialized_template_header )
) : array();
$serialized_header_content = (string) ( $serialized_header_artifacts['contents']['serialized-header.html'] ?? '' );

$assert( ! str_contains( $serialized_header_content, 'Try the editor' ), 'serialized-navigation-rewritten-routes-match' );
$assert( str_contains( $serialized_header_content, 'Skip to content' ), 'serialized-navigation-skip-link-preserved' );
$assert( 'semantic_header_identity_and_navigation_match' === ( $serialized_header_artifacts['diagnostics'][0]['reason'] ?? '' ), 'serialized-navigation-diagnostic-reason' );

$serialized_near_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'serialized-near-header.html',
		'title'        => 'Serialized Near Header',
		'block_markup' => $serialized_near_header . "\n" . $body,
	)
);
$serialized_near_artifacts = $serialized_near_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'serialized-near-header.html' => $serialized_near_page ),
	'ssi-theme',
	array(),
	array(),
	array( '/tmp/ssi-theme/parts/header.html' => $serialized_template_header )
) : array();
$serialized_near_content = (string) ( $serialized_near_artifacts['contents']['serialized-near-header.html'] ?? '' );

$assert( str_contains( $serialized_near_content, 'journal' ), 'serialized-navigation-different-route-preserved' );

$wrapped_skip_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'wrapped-skip-header.html',
		'title'        => 'Wrapped Skip Header',
		'block_markup' => $wrapped_skip_link . "\n" . $different_global_header . "\n" . $body,
	)
);

$wrapped_skip_artifacts = $wrapped_skip_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'wrapped-skip-header.html' => $wrapped_skip_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes
) : array();
$wrapped_skip_content = (string) ( $wrapped_skip_artifacts['contents']['wrapped-skip-header.html'] ?? '' );

$assert( str_contains( $wrapped_skip_content, 'Skip to content' ), 'wrapped-skip-link-preserved' );
$assert( ! str_contains( $wrapped_skip_content, 'Try the editor' ), 'wrapped-skip-link-followed-global-header-removed' );

$role_global_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'role-global-header.html',
		'title'        => 'Role Global Header',
		'block_markup' => $role_global_header . "\n" . $body,
	)
);

$role_global_header_artifacts = $role_global_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'role-global-header.html' => $role_global_header_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes,
	array( 'strip_template_header' => true )
) : array();
$role_global_header_content = (string) ( $role_global_header_artifacts['contents']['role-global-header.html'] ?? '' );

$assert( str_contains( $role_global_header_content, 'Route-specific masthead' ), 'runtime-unmatched-role-global-header-preserved' );

$near_global_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'near-global-header.html',
		'title'        => 'Near Global Header',
		'block_markup' => $near_global_header . "\n" . $body,
	)
);
$near_global_header_artifacts = $near_global_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'near-global-header.html' => $near_global_header_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes
) : array();
$near_global_header_content = (string) ( $near_global_header_artifacts['contents']['near-global-header.html'] ?? '' );

$assert( str_contains( $near_global_header_content, 'href="/journal"' ), 'runtime-near-global-header-preserved' );

$local_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'runtime-local-header.html',
		'title'        => 'Runtime Local Header',
		'block_markup' => $different_local_header . "\n" . $body,
	)
);

$local_header_artifacts = $local_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'runtime-local-header.html' => $local_header_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes,
	array( 'strip_template_header' => true )
) : array();
$local_header_content = (string) ( $local_header_artifacts['contents']['runtime-local-header.html'] ?? '' );

$assert( str_contains( $local_header_content, 'Article title' ), 'runtime-local-header-preserved' );
$assert( array() === ( $local_header_artifacts['diagnostics'] ?? array() ), 'runtime-local-header-no-dedupe-diagnostic' );

$body_global_header_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'body-global-header.html',
		'title'        => 'Body Global Header',
		'block_markup' => $body . "\n" . $different_global_header,
	)
);

$body_global_header_artifacts = $body_global_header_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'body-global-header.html' => $body_global_header_page ),
	'ssi-theme',
	array(),
	array(),
	$semantic_template_part_writes,
	array( 'strip_template_header' => true )
) : array();
$body_global_header_content = (string) ( $body_global_header_artifacts['contents']['body-global-header.html'] ?? '' );

$assert( str_contains( $body_global_header_content, 'Try the editor' ), 'runtime-global-header-after-body-preserved' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS smoke-template-part-shell-dedupe.php (' . $assertions . " assertions)\n";
