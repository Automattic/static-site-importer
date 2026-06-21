<?php
/**
 * Smoke test: BAC visual repair artifacts drive generated theme CSS.
 *
 * Run from the repository root:
 * php tests/smoke-bac-visual-repair-css.php
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

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
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
		'schema' => 'block-artifact-compiler/visual-repair-artifacts/v1',
		'css'    => '.compiled-site-repair { display: block; }',
		'styles' => array(
			array(
				'schema'  => 'block-artifact-compiler/visual-repair-css/v1',
				'target'  => 'frontend',
				'path'    => 'assets/css/visual-repair.css',
				'content' => "/* Block Artifact Compiler: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
			),
			array(
				'schema'  => 'block-artifact-compiler/visual-repair-css/v1',
				'target'  => 'editor',
				'path'    => 'assets/css/visual-repair-editor.css',
				'content' => "/* Block Artifact Compiler: editor visual repair artifacts. */\n.editor-styles-wrapper .glow-orb { opacity: 1 !important; }",
			),
			array(
				'target'  => 'frontend',
				'content' => "/* Block Artifact Compiler: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
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

$writes     = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/bac-visual-repair-smoke', 'BAC Visual Repair Smoke', '.hero-shell{display:grid}', $styles );
$style_css  = (string) ( $writes['/tmp/bac-visual-repair-smoke/style.css'] ?? '' );
$editor_css = (string) ( $writes['/tmp/bac-visual-repair-smoke/assets/css/editor-style.css'] ?? '' );

$assert( 2 === count( $styles['frontend'] ?? array() ), 'collector-dedupes-frontend-repair-css' );
$assert( 2 === count( $styles['editor'] ?? array() ), 'collector-reads-editor-repair-css' );
$assert( str_contains( $style_css, 'Block Artifact Compiler: visual repair artifacts.' ), 'style-includes-bac-frontend-repair-comment', $style_css );
$assert( str_contains( $style_css, '.wp-block-group.hero-shell { gap: 0; }' ), 'style-includes-bac-frontend-repair-rule', $style_css );
$assert( str_contains( $style_css, '.compiled-site-repair { display: block; }' ), 'style-includes-compiled-site-repair-css', $style_css );
$assert( ! str_contains( $style_css, 'editor visual repair artifacts' ), 'style-excludes-editor-repair-css', $style_css );
$assert( str_contains( $editor_css, 'Block Artifact Compiler: editor visual repair artifacts.' ), 'editor-includes-bac-editor-repair-comment', $editor_css );
$assert( str_contains( $editor_css, '.editor-styles-wrapper .glow-orb { opacity: 1 !important; }' ), 'editor-includes-bac-editor-repair-rule', $editor_css );
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
	'/tmp/bac-visual-repair-smoke',
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
				'block_markup' => '<!-- wp:paragraph --><p>Legacy Header</p><!-- /wp:paragraph -->',
			),
		),
	)
);
$assert( is_array( $template_part_result ), 'materialization-plan-template-part-writes-succeed' );
$template_part_writes = is_array( $template_part_result ) ? $template_part_result['writes'] : array();
$template_part_reports = is_array( $template_part_result ) ? $template_part_result['reports'] : array();
$assert( str_contains( (string) ( $template_part_writes['/tmp/bac-visual-repair-smoke/parts/header.html'] ?? '' ), 'Plan Header' ), 'materialization-plan-template-part-write-is-used' );
$assert( ! str_contains( (string) ( $template_part_writes['/tmp/bac-visual-repair-smoke/parts/header.html'] ?? '' ), 'Legacy Header' ), 'legacy-template-part-is-ignored-when-plan-writes-exist' );
$assert( array( 'parts/header.html' ) === ( $template_part_reports[0]['source_paths'] ?? array() ), 'materialization-plan-template-part-source-path-is-reported' );

$missing_content = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes(
	'/tmp/bac-visual-repair-smoke',
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

$source_pages = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'bac_document_pages' );
$native_pages = $source_pages->invoke(
	null,
	array(
		'wordpress_artifacts' => array(
			'site'      => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(
					array(
						'source_path'  => 'website/index.html',
						'post_type'    => 'page',
						'slug'         => 'home-canonical',
						'title'        => 'Home Canonical',
						'entrypoint'   => true,
						'block_markup' => '<!-- wp:paragraph --><p>Native page</p><!-- /wp:paragraph -->',
					),
				),
			),
			'documents' => array(
				array(
					'source_path'  => 'website/index.html',
					'slug'         => 'legacy-home',
					'title'        => 'Legacy Home',
					'block_markup' => '<!-- wp:paragraph --><p>Legacy page</p><!-- /wp:paragraph -->',
				),
			),
		),
	)
);
$assert( is_array( $native_pages ), 'materialization-plan-pages-create-source-pages' );
$native_page = is_array( $native_pages ) ? ( $native_pages['website/index.html'] ?? null ) : null;
$assert( $native_page instanceof Static_Site_Importer_Source_Page, 'materialization-plan-page-source-key-is-used' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'materialization_plan_page' === $native_page->type(), 'materialization-plan-page-type-is-native' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && 'home-canonical' === $native_page->metadata_value( 'slug' ), 'materialization-plan-page-slug-wins-over-legacy-document' );
$assert( $native_page instanceof Static_Site_Importer_Source_Page && str_contains( $native_page->body(), 'Native page' ), 'materialization-plan-page-body-wins-over-legacy-document' );

$missing_page_content = $source_pages->invoke(
	null,
	array(
		'wordpress_artifacts' => array(
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
			'assets' => array(
				array(
					'path'    => 'assets/site.css',
					'role'    => 'stylesheet',
					'content' => '.native-plan{color:green}',
				),
				array(
					'path'           => 'assets/logo.png',
					'role'           => 'image',
					'mime_type'      => 'image/png',
					'content_base64' => base64_encode( "\x89PNG\r\n\x1a\n" ),
				),
			),
		),
		'files' => array(
			array(
				'path'    => 'assets/site.css',
				'kind'    => 'css',
				'content' => '.legacy-artifact{color:red}',
			),
		),
	)
);
$assert( is_array( $asset_result ), 'materialization-plan-assets-succeed' );
$asset_result = is_array( $asset_result ) ? $asset_result : array( 'css' => '', 'assets' => array() );
$assert( str_contains( (string) $asset_result['css'], '.native-plan' ), 'materialization-plan-asset-css-wins' );
$assert( ! str_contains( (string) $asset_result['css'], '.legacy-artifact' ), 'legacy-css-is-ignored-when-native-plan-assets-have-payloads' );
$assert( file_exists( $asset_theme_dir . '/assets/materialized/assets/logo.png' ), 'materialization-plan-binary-asset-is-written' );
$assert( 'materialization_plan.assets' === ( $asset_result['assets']['assets/logo.png']['origin'] ?? '' ), 'materialization-plan-asset-origin-is-reported' );
$assert( str_ends_with( (string) ( $asset_result['assets']['assets/logo.png']['final_url'] ?? '' ), '/assets/materialized/assets/logo.png' ), 'materialization-plan-asset-final-url-shape' );

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
				'content' => '.legacy-metadata-only{color:blue}',
			),
		),
	)
);
$assert( is_array( $metadata_only ), 'metadata-only-materialization-plan-assets-fall-back-to-legacy' );
$assert( str_contains( (string) ( is_array( $metadata_only ) ? $metadata_only['css'] : '' ), '.legacy-metadata-only' ), 'legacy-assets-used-when-native-plan-assets-have-no-payload-contract' );

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

echo 'OK: BAC visual repair CSS smoke passed (' . $assertions . " assertions)\n";
