<?php
/**
 * Smoke test: visual repair artifacts drive generated theme CSS.
 *
 * Run from the repository root:
 * php tests/smoke-visual-repair-css.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ), '-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$failures, &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$artifacts = array(
	'visual_repair' => array(
		'schema' => 'blocks-engine/php-transformer/visual-repair-artifacts/v1',
		'css'    => '.compiled-site-repair { display: block; }',
		'styles' => array(
			array(
				'schema'  => 'blocks-engine/php-transformer/visual-repair-css/v1',
				'target'  => 'frontend',
				'path'    => 'assets/css/visual-repair.css',
				'content' => "/* Blocks Engine: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
			),
			array(
				'schema'  => 'blocks-engine/php-transformer/visual-repair-css/v1',
				'target'  => 'editor',
				'path'    => 'assets/css/visual-repair-editor.css',
				'content' => "/* Blocks Engine: editor visual repair artifacts. */\n.editor-styles-wrapper .glow-orb { opacity: 1 !important; }",
			),
			array(
				'target'  => 'frontend',
				'content' => "/* Blocks Engine: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
			),
			array(
				'target'  => 'unknown',
				'content' => '.should-not-appear { color: red; }',
			),
		),
	),
);

$collector = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'visual_repair_styles_from_artifacts' );
$styles    = $collector->invoke( null, $artifacts );

$writes     = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/visual-repair-smoke', 'Visual Repair Smoke', '.hero-shell{display:grid}', array(), $styles );
$style_css  = (string) ( $writes['/tmp/visual-repair-smoke/style.css'] ?? '' );
$editor_css = (string) ( $writes['/tmp/visual-repair-smoke/assets/css/editor-style.css'] ?? '' );

$assert( 2 === count( $styles['frontend'] ?? array() ), 'collector-dedupes-frontend-repair-css' );
$assert( 2 === count( $styles['editor'] ?? array() ), 'collector-reads-editor-repair-css' );
$assert( str_contains( $style_css, 'Blocks Engine: visual repair artifacts.' ), 'style-includes-frontend-repair-comment', $style_css );
$assert( str_contains( $style_css, '.wp-block-group.hero-shell { gap: 0; }' ), 'style-includes-frontend-repair-rule', $style_css );
$assert( str_contains( $style_css, '.compiled-site-repair { display: block; }' ), 'style-includes-compiled-site-repair-css', $style_css );
$assert( ! str_contains( $style_css, 'editor visual repair artifacts' ), 'style-excludes-editor-repair-css', $style_css );
$assert( str_contains( $editor_css, 'Blocks Engine: editor visual repair artifacts.' ), 'editor-includes-editor-repair-comment', $editor_css );
$assert( str_contains( $editor_css, '.editor-styles-wrapper .glow-orb { opacity: 1 !important; }' ), 'editor-includes-editor-repair-rule', $editor_css );
$assert( str_contains( $editor_css, '.compiled-site-repair { display: block; }' ), 'editor-includes-compiled-site-repair-css', $editor_css );
$assert( ! str_contains( $editor_css, '.should-not-appear' ), 'unknown-target-repair-css-is-ignored', $editor_css );

$documents      = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'documents_from_compiled_site_pages' );
$missing_source = $documents->invoke(
	null,
	array(
		array(
			'slug' => 'home',
		),
	),
	array()
);
$assert( $missing_source instanceof WP_Error, 'compiled-site-page-source-is-required' );
$assert( 'static_site_importer_compiled_site_page_missing_source' === ( $missing_source instanceof WP_Error ? $missing_source->get_error_code() : '' ), 'compiled-site-page-source-error-code' );

$template_part_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/visual-repair-smoke',
	array(
		'site'           => array(
			'schema'               => 'blocks-engine/php-transformer/materialization-plan/v1',
			'template_part_writes' => array(
				array(
					'type'        => 'wp_template_part',
					'source_path' => 'parts/header.html',
					'slug'        => 'header',
					'area'        => 'header',
					'content'     => '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">Plan Header</header><!-- /wp:group -->',
				),
			),
		),
		'template_parts' => array(
			array(
				'slug'         => 'header',
				'area'         => 'header',
				'block_markup' => '<!-- wp:paragraph --><p>Fallback Header</p><!-- /wp:paragraph -->',
			),
		),
	)
);
$assert( is_array( $template_part_result ), 'materialization-plan-template-part-writes-succeed' );
$template_part_writes = is_array( $template_part_result ) ? $template_part_result['writes'] : array();
$template_part_reports = is_array( $template_part_result ) ? $template_part_result['reports'] : array();
$assert( str_contains( (string) ( $template_part_writes['/tmp/visual-repair-smoke/parts/header.html'] ?? '' ), 'Plan Header' ), 'materialization-plan-template-part-write-is-used' );
$assert( ! str_contains( (string) ( $template_part_writes['/tmp/visual-repair-smoke/parts/header.html'] ?? '' ), 'Fallback Header' ), 'fallback-template-part-is-ignored-when-plan-writes-exist' );
$assert( array( 'parts/header.html' ) === ( $template_part_reports[0]['source_paths'] ?? array() ), 'materialization-plan-template-part-source-path-is-reported' );

$navigation_part_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/visual-repair-smoke',
	array(
		'site' => array(
			'schema'     => 'blocks-engine/php-transformer/materialization-plan/v1',
			'navigation' => array(
				array(
					'source_path'  => 'website/index.html#main-nav',
					'title'        => 'Main Navigation',
					'block_markup' => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"About","url":"/about/"} /--><!-- /wp:navigation -->',
				),
			),
		),
	)
);
$assert( is_array( $navigation_part_result ), 'materialization-plan-navigation-rows-succeed' );
$navigation_part_writes = is_array( $navigation_part_result ) ? $navigation_part_result['writes'] : array();
$navigation_part_reports = is_array( $navigation_part_result ) ? $navigation_part_result['reports'] : array();
$assert( str_contains( (string) ( $navigation_part_writes['/tmp/visual-repair-smoke/parts/header.html'] ?? '' ), 'wp:navigation-link' ), 'materialization-plan-navigation-row-is-used-for-header' );
$assert( array( 'website/index.html#main-nav' ) === ( $navigation_part_reports[0]['source_paths'] ?? array() ), 'materialization-plan-navigation-source-path-is-reported' );

$empty_template_part_result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/visual-repair-smoke',
	array()
);
$assert( is_array( $empty_template_part_result ), 'empty-template-part-input-succeeds' );
$empty_template_part_writes = is_array( $empty_template_part_result ) ? $empty_template_part_result['writes'] : array();
$empty_template_part_reports = is_array( $empty_template_part_result ) ? $empty_template_part_result['reports'] : array();
$assert( array() === $empty_template_part_writes, 'empty-template-part-input-does-not-generate-header' );
$assert( array() === $empty_template_part_reports, 'empty-template-part-input-does-not-report-header' );

$missing_navigation_content = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/visual-repair-smoke',
	array(
		'site' => array(
			'schema'     => 'blocks-engine/php-transformer/materialization-plan/v1',
			'navigation' => array(
				array(
					'source_path' => 'website/index.html#main-nav',
				),
			),
		),
	)
);
$assert( $missing_navigation_content instanceof WP_Error, 'materialization-plan-navigation-content-is-required' );
$assert( 'static_site_importer_materialization_plan_navigation_content_missing' === ( $missing_navigation_content instanceof WP_Error ? $missing_navigation_content->get_error_code() : '' ), 'materialization-plan-navigation-content-error-code' );

$missing_content = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/visual-repair-smoke',
	array(
		'site' => array(
			'schema'               => 'blocks-engine/php-transformer/materialization-plan/v1',
			'template_part_writes' => array(
				array(
					'type' => 'wp_template_part',
					'slug' => 'header',
				),
			),
		),
	)
);
$assert( $missing_content instanceof WP_Error, 'materialization-plan-template-part-content-is-required' );
$assert( 'static_site_importer_materialization_plan_template_part_content_missing' === ( $missing_content instanceof WP_Error ? $missing_content->get_error_code() : '' ), 'materialization-plan-template-part-content-error-code' );

$source_pages = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'website_artifact_source_pages' );
$native_pages = $source_pages->invoke(
	null,
	array(
		'artifacts' => array(
			'site'      => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path'  => 'website/index.html',
						'post_type'    => 'page',
						'slug'         => 'planned-page-slug',
						'title'        => 'Planned Page Title',
						'entrypoint'   => true,
						'block_markup' => '<!-- wp:paragraph --><p>Native page</p><!-- /wp:paragraph -->',
					),
				),
				'routes' => array(
					array(
						'source_path' => 'website/index.html',
						'path'        => '/',
						'route_key'   => 'home-route',
						'post_type'   => 'page',
						'slug'        => 'home-canonical',
						'title'       => 'Home Canonical',
					),
				),
			),
			'documents' => array(
				array(
					'source_path'  => 'website/index.html',
					'slug'         => 'compiled-site-home',
					'title'        => 'Compiled Site Home',
					'block_markup' => '<!-- wp:paragraph --><p>Compiled site page</p><!-- /wp:paragraph -->',
				),
			),
		),
	)
);
$assert( is_array( $native_pages ), 'materialization-plan-pages-create-source-pages' );
$native_page = is_array( $native_pages ) ? ( $native_pages['website/index.html'] ?? null ) : null;
$assert( $native_page instanceof Static_Site_Importer_Source_Page, 'materialization-plan-page-source-key-is-used' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'materialization_plan_page' === $native_page->type(), 'materialization-plan-page-type-is-native' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'home-canonical' === $native_page->metadata_value( 'slug' ), 'materialization-plan-route-slug-wins-over-page-and-compiled-site-document' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'Home Canonical' === $native_page->metadata_value( 'title' ), 'materialization-plan-route-title-wins-over-page-and-compiled-site-document' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'home-route' === $native_page->metadata_value( 'route_key' ), 'materialization-plan-route-key-is-preserved' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && str_contains( $native_page->body(), 'Native page' ), 'materialization-plan-page-body-wins-over-compiled-site-document' );

$document_backed_native_pages = $source_pages->invoke(
	null,
	array(
		'artifacts' => array(
			'site'      => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path' => 'website/about.html',
						'post_type'   => 'page',
						'slug'        => 'about',
						'title'       => 'About',
					),
				),
			),
			'documents' => array(
				array(
					'source_path'  => 'website/about.html',
					'block_markup' => '<!-- wp:paragraph --><p>Document backed native page</p><!-- /wp:paragraph -->',
				),
			),
		),
	)
);
$document_backed_native_page = is_array( $document_backed_native_pages ) ? ( $document_backed_native_pages['website/about.html'] ?? null ) : null;
$assert( $document_backed_native_page instanceof Static_Site_Importer_Source_Page, 'materialization-plan-page-can-use-document-artifact-markup' );
$assert( $document_backed_native_page instanceof Static_Site_Importer_Source_Page && str_contains( $document_backed_native_page->body(), 'Document backed native page' ), 'materialization-plan-page-body-comes-from-document-artifact' );

$target_route_pages = $source_pages->invoke(
	null,
	array(
		'artifacts' => array(
			'site' => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path'  => 'website/nested/index.html',
						'post_type'    => 'page',
						'slug'         => 'index',
						'title'        => 'Nested Home',
						'entrypoint'   => true,
						'block_markup' => '<!-- wp:paragraph --><p>Nested home</p><!-- /wp:paragraph -->',
						'metadata'     => array(
							'slug' => 'index',
						),
					),
				),
				'routes' => array(
					array(
						'kind'            => 'route',
						'source_path'     => 'website/nested/index.html',
						'target_path'     => '/',
						'target_slug'     => 'index',
						'title'           => 'Nested Home Route',
						'source_relation' => 'entrypoint',
					),
				),
			),
		),
	)
);
$target_route_page = is_array( $target_route_pages ) ? ( $target_route_pages['website/nested/index.html'] ?? null ) : null;
$assert( $target_route_page instanceof Static_Site_Importer_Source_Page, 'materialization-plan-target-route-page-source-key-is-used' );
$assert( $target_route_page instanceof Static_Site_Importer_Source_Page && 'home' === $target_route_page->metadata_value( 'slug' ), 'materialization-plan-target-root-route-normalizes-home-slug' );
$assert( $target_route_page instanceof Static_Site_Importer_Source_Page && '1' === $target_route_page->metadata_value( 'entrypoint' ), 'materialization-plan-target-root-route-preserves-entrypoint' );
$assert( $target_route_page instanceof Static_Site_Importer_Source_Page && str_contains( $target_route_page->body(), 'Nested home' ), 'materialization-plan-target-route-page-body-is-preserved' );

$site_title_from_artifact = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'site_title_from_website_artifact' );
$site_title               = $site_title_from_artifact->invoke(
	null,
	array(
		'entrypoint' => 'website/nested/index.html',
		'files'      => array(
			array(
				'path'    => 'website/nested/index.html',
				'content' => '<!doctype html><html><head><title>Northline Plumbing &amp; Heating | Grand Rapids, MI</title></head><body></body></html>',
			),
		),
	)
);
$assert( 'Northline Plumbing & Heating' === $site_title, 'website-artifact-entrypoint-title-infers-site-title' );

$malformed_routes = $source_pages->invoke(
	null,
	array(
		'artifacts' => array(
			'site' => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path'  => 'website/index.html',
						'slug'         => 'home',
						'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
					),
				),
				'routes' => array( 'not-a-route-row' ),
			),
		),
	)
);
$assert( $malformed_routes instanceof WP_Error, 'materialization-plan-route-row-must-be-array' );
$assert( 'static_site_importer_materialization_plan_route_invalid' === ( $malformed_routes instanceof WP_Error ? $malformed_routes->get_error_code() : '' ), 'materialization-plan-route-row-error-code' );

$missing_page_content = $source_pages->invoke(
	null,
	array(
		'artifacts' => array(
			'site' => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path' => 'website/index.html',
						'slug'        => 'home',
					),
				),
			),
		),
	)
);
$assert( $missing_page_content instanceof WP_Error, 'materialization-plan-page-content-is-required' );
$assert( 'static_site_importer_materialization_plan_page_empty_content' === ( $missing_page_content instanceof WP_Error ? $missing_page_content->get_error_code() : '' ), 'materialization-plan-page-content-error-code' );

$asset_theme_dir = sys_get_temp_dir() . '/ssi-materialization-plan-assets-' . uniqid( '', true );
$asset_result    = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$asset_theme_dir,
	'https://example.test/wp-content/themes/generated',
	array(
		'site'  => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'theme'  => array(
				'font_materialization' => array(
					'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
					'provider'    => 'google_fonts',
					'stylesheets' => array(
						array(
							'path'      => 'assets/css/fonts.css',
							'role'      => 'stylesheet',
							'mime_type' => 'text/css',
							'content'   => '@import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&display=swap");' . "\n",
						),
					),
				),
			),
			'assets' => array(
				array(
					'path'    => 'assets/site.css',
					'role'    => 'stylesheet',
					'media'   => 'screen',
					'content' => '@font-face{font-family:NativePlan;src:url("../fonts/native.woff2") format("woff2")}.native-plan{color:green}',
				),
				array(
					'path'      => 'assets/app.js',
					'role'      => 'script',
					'kind'      => 'js',
					'type'      => 'module',
					'placement' => 'head',
					'defer'     => true,
					'content'   => 'window.nativePlanOrder = ["app"];',
				),
				array(
					'path'    => 'assets/vendor.js',
					'role'    => 'script',
					'kind'    => 'js',
					'async'   => true,
					'content' => 'window.nativePlanOrder.push("vendor");',
				),
				array(
					'path'           => 'fonts/native.woff2',
					'role'           => 'font',
					'mime_type'      => 'font/woff2',
					'content_base64' => base64_encode( 'font-data' ),
				),
				array(
					'path'           => 'assets/logo.png',
					'role'           => 'image',
					'mime_type'      => 'image/png',
					'content_base64' => base64_encode( "\x89PNG\r\n\x1a\n" ),
				),
				array(
					'path'    => 'website/3-artist-music/merch.html',
					'kind'    => 'html',
					'role'    => 'document',
					'content' => '<!doctype html><html><body><main><h1>Merch</h1></main></body></html>',
				),
			),
		),
		'files' => array(
			array(
				'path'    => 'assets/site.css',
				'kind'    => 'css',
				'content' => '.top-level-artifact{color:red}',
			),
		),
	)
);
$assert( is_array( $asset_result ), 'materialization-plan-assets-succeed' );
$asset_result = is_array( $asset_result ) ? $asset_result : array( 'css' => '', 'assets' => array(), 'scripts' => array() );
$assert( str_contains( (string) $asset_result['css'], '.native-plan' ), 'materialization-plan-asset-css-wins' );
$assert( str_contains( (string) $asset_result['css'], 'assets/materialized/fonts/native.woff2' ), 'materialization-plan-css-font-url-is-rewritten' );
$assert( ! str_contains( (string) $asset_result['css'], '.top-level-artifact' ), 'top-level-css-is-ignored-when-native-plan-assets-have-payloads' );
$assert( file_exists( $asset_theme_dir . '/assets/materialized/assets/logo.png' ), 'materialization-plan-binary-asset-is-written' );
$assert( file_exists( $asset_theme_dir . '/assets/materialized/assets/app.js' ), 'materialization-plan-script-asset-is-written' );
$assert( file_exists( $asset_theme_dir . '/assets/materialized/assets/vendor.js' ), 'materialization-plan-second-script-asset-is-written' );
$assert( file_exists( $asset_theme_dir . '/assets/materialized/fonts/native.woff2' ), 'materialization-plan-font-asset-is-written' );
$assert( ! file_exists( $asset_theme_dir . '/assets/materialized/website/3-artist-music/merch.html' ), 'materialization-plan-html-document-is-not-written-as-asset' );
$assert( 'materialization_plan.assets' === ( $asset_result['assets']['assets/logo.png']['origin'] ?? '' ), 'materialization-plan-asset-origin-is-reported' );
$assert( ! isset( $asset_result['assets']['website/3-artist-music/merch.html'] ), 'materialization-plan-html-document-is-not-reported-as-asset' );
$assert( file_exists( $asset_theme_dir . '/assets/css/fonts.css' ), 'font-materialization-stylesheet-is-written' );
$assert( str_contains( (string) file_get_contents( $asset_theme_dir . '/assets/css/fonts.css' ), 'fonts.googleapis.com/css2?family=Open+Sans' ), 'font-materialization-preserves-google-font-import' );
$assert( 'theme.font_materialization' === ( $asset_result['assets']['assets/css/fonts.css']['origin'] ?? '' ), 'font-materialization-origin-is-reported' );
$assert( 'assets/css/fonts.css' === ( $asset_result['assets']['assets/css/fonts.css']['theme_path'] ?? '' ), 'font-materialization-theme-path-is-not-nested' );
$assert( str_ends_with( (string) ( $asset_result['assets']['assets/logo.png']['final_url'] ?? '' ), '/assets/materialized/assets/logo.png' ), 'materialization-plan-asset-final-url-shape' );
$assert( str_ends_with( (string) ( $asset_result['assets']['assets/css/fonts.css']['final_url'] ?? '' ), '/assets/css/fonts.css' ), 'font-materialization-final-url-shape' );
$assert( 'screen' === ( $asset_result['assets']['assets/site.css']['media'] ?? '' ), 'materialization-plan-style-metadata-is-preserved' );
$assert( 'font' === ( $asset_result['assets']['fonts/native.woff2']['role'] ?? '' ), 'materialization-plan-font-role-is-preserved' );
$assert( 'font/woff2' === ( $asset_result['assets']['fonts/native.woff2']['mime_type'] ?? '' ), 'materialization-plan-font-mime-is-preserved' );
$assert( '' === (string) ( $asset_result['js'] ?? '' ), 'materialization-plan-scripts-are-not-concatenated' );
$assert( 2 === count( $asset_result['scripts'] ?? array() ), 'materialization-plan-script-rows-are-preserved' );
$assert( 1 === count( $asset_result['stylesheets'] ?? array() ), 'font-materialization-stylesheet-row-is-preserved' );
$assert( 'assets/app.js' === ( $asset_result['scripts'][0]['path'] ?? '' ), 'materialization-plan-script-order-first' );
$assert( 'assets/vendor.js' === ( $asset_result['scripts'][1]['path'] ?? '' ), 'materialization-plan-script-order-second' );
$assert( true === ( $asset_result['scripts'][0]['defer'] ?? false ), 'materialization-plan-script-defer-is-preserved' );
$assert( 'module' === ( $asset_result['scripts'][0]['type'] ?? '' ), 'materialization-plan-script-type-is-preserved' );
$assert( true === ( $asset_result['scripts'][1]['async'] ?? false ), 'materialization-plan-script-async-is-preserved' );

$source_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'website/3-artist-music/index.html',
		'slug'         => 'home',
		'title'        => 'Home',
		'block_markup' => '<!-- wp:paragraph --><p><a href="merch.html">Merch</a><img src="assets/logo.png" alt="Logo"></p><!-- /wp:paragraph -->',
	)
);
$assert( $source_page instanceof Static_Site_Importer_Source_Page, 'source-page-for-link-rewrite-is-created' );
$page_artifacts = $source_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts(
	array( 'website/3-artist-music/index.html' => $source_page ),
	'fixture-theme',
	array(
		'website/3-artist-music/merch.html' => array( 'final_url' => 'https://example.test/wp-content/themes/generated/assets/materialized/website/3-artist-music/merch.html' ),
		'website/3-artist-music/assets/logo.png' => array( 'final_url' => 'https://example.test/wp-content/themes/generated/assets/materialized/website/3-artist-music/assets/logo.png' ),
	),
	array(
		'website/3-artist-music/merch.html' => 'https://example.test/merch/',
		'merch.html'                       => 'https://example.test/merch/',
	)
) : array( 'contents' => array() );
$rewritten_content = (string) ( $page_artifacts['contents']['website/3-artist-music/index.html'] ?? '' );
$assert( str_contains( $rewritten_content, 'href="https://example.test/merch/"' ), 'html-page-link-rewrites-to-imported-page-permalink' );
$assert( ! str_contains( $rewritten_content, 'href="https://example.test/wp-content/themes/generated/assets/materialized/website/3-artist-music/merch.html"' ), 'html-page-link-does-not-rewrite-to-materialized-html-asset' );
$assert( str_contains( $rewritten_content, 'src="https://example.test/wp-content/themes/generated/assets/materialized/website/3-artist-music/assets/logo.png"' ), 'non-html-asset-link-still-rewrites-to-materialized-asset' );

$base_writes   = Static_Site_Importer_Theme_Materializer::base_theme_writes( $asset_theme_dir, 'fixture-theme', 'Fixture Theme', (string) $asset_result['css'], false, false, $asset_result['scripts'], $asset_result['stylesheets'] );
$functions_php = (string) ( $base_writes[ $asset_theme_dir . '/functions.php' ] ?? '' );
$assert( str_contains( $functions_php, '/assets/css/fonts.css' ), 'font-materialization-stylesheet-is-enqueued' );
$assert( str_contains( $functions_php, "wp_enqueue_style( 'fixture-theme-style', get_stylesheet_uri(), array (\n  0 => 'fixture-theme-asset-assets-css-fonts',\n), wp_get_theme()->get( 'Version' ) );" ), 'theme-style-depends-on-font-materialization-stylesheet' );
$assert( str_contains( $functions_php, "wp_enqueue_style( 'fixture-theme-editor-style', get_template_directory_uri() . '/assets/css/editor-style.css', array (\n  0 => 'fixture-theme-asset-assets-css-fonts',\n), wp_get_theme()->get( 'Version' ) );" ), 'editor-style-depends-on-font-materialization-stylesheet' );
$assert( str_contains( $functions_php, '/assets/materialized/assets/app.js' ), 'materialization-plan-script-is-enqueued' );
$assert( str_contains( $functions_php, "wp_script_add_data( 'fixture-theme-asset-assets-materialized-assets-app', 'defer', true );" ), 'materialization-plan-script-defer-is-enqueued' );
$assert( str_contains( $functions_php, "wp_script_add_data( 'fixture-theme-asset-assets-materialized-assets-app', 'type', 'module' );" ), 'materialization-plan-script-type-is-enqueued' );
$assert( str_contains( $functions_php, "wp_script_add_data( 'fixture-theme-asset-assets-materialized-assets-vendor', 'async', true );" ), 'materialization-plan-script-async-is-enqueued' );

$metadata_only = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	sys_get_temp_dir() . '/ssi-materialization-plan-assets-metadata-only-' . uniqid( '', true ),
	'https://example.test/wp-content/themes/generated',
	array(
		'site'  => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'assets' => array(
				array(
					'path' => 'assets/site.css',
					'role' => 'stylesheet',
				),
			),
		),
		'files' => array(
			array(
				'path'    => 'assets/site.css',
				'kind'    => 'css',
				'content' => '.top-level-metadata-only{color:blue}',
			),
		),
	)
);
$assert( is_array( $metadata_only ), 'metadata-only-materialization-plan-assets-fall-back-to-top-level-assets' );
$assert( str_contains( (string) ( is_array( $metadata_only ) ? $metadata_only['css'] : '' ), '.top-level-metadata-only' ), 'top-level-assets-used-when-native-plan-assets-have-no-payload-contract' );

$bad_asset = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	sys_get_temp_dir() . '/ssi-materialization-plan-assets-bad-' . uniqid( '', true ),
	'https://example.test/wp-content/themes/generated',
	array(
		'site' => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'assets' => array(
				array(
					'path'           => 'assets/bad.png',
					'content_base64' => 'not-valid-base64',
				),
			),
		),
	)
);
$assert( $bad_asset instanceof WP_Error, 'malformed-materialization-plan-asset-fails-explicitly' );
$assert( 'static_site_importer_materialization_plan_asset_base64_invalid' === ( $bad_asset instanceof WP_Error ? $bad_asset->get_error_code() : '' ), 'malformed-materialization-plan-asset-error-code' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: visual repair CSS smoke passed (' . $assertions . " assertions)\n";
