<?php
/**
 * Smoke coverage for materializer dry-run mode.
 *
 * Run from the repository root:
 * php tests/smoke-theme-materializer-dry-run.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
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
		return preg_replace( '/[^a-z0-9_\-]+/', '-', $title ) ?? '';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$theme_dir = sys_get_temp_dir() . '/ssi-dry-run-' . bin2hex( random_bytes( 6 ) );
$result    = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'files' => array(
			array(
				'path'    => 'images/logo.svg',
				'kind'    => 'image',
				'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
			),
		),
	),
	false
);

$assert( ! is_wp_error( $result ), 'dry-run-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
$assert( ! is_dir( $theme_dir ), 'dry-run-does-not-create-theme-dir' );
$assert( 'assets/materialized/images/logo.svg' === ( $result['assets']['images/logo.svg']['theme_path'] ?? '' ), 'dry-run-reports-theme-path' );
$assert( 'image/svg+xml' === ( $result['assets']['images/logo.svg']['mime_type'] ?? '' ), 'dry-run-reports-mime-type' );
$assert( 'canonical' === ( $result['assets']['images/logo.svg']['source_role'] ?? '' ), 'dry-run-defaults-source-role-to-canonical' );
$assert( true === ( $result['assets']['images/logo.svg']['keep_source'] ?? false ), 'dry-run-keeps-canonical-source-by-default' );
$assert( false === ( $result['assets']['images/logo.svg']['deletion_allowed'] ?? true ), 'dry-run-does-not-allow-canonical-source-deletion' );

$guarded = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'files' => array(
			array(
				'path'        => 'images/canonical.svg',
				'kind'        => 'image',
				'content'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
				'keep_source' => false,
			),
		),
	),
	false
);
$assert( ! is_wp_error( $guarded ), 'canonical-source-guard-succeeds', is_wp_error( $guarded ) ? $guarded->get_error_message() : '' );
$assert( true === ( $guarded['assets']['images/canonical.svg']['keep_source'] ?? false ), 'canonical-source-guard-forces-keep-source' );
$assert( false === ( $guarded['assets']['images/canonical.svg']['deletion_allowed'] ?? true ), 'canonical-source-guard-blocks-deletion' );
$assert( 'website_artifact_source_retention_guard' === ( $guarded['diagnostics'][0]['type'] ?? '' ), 'canonical-source-guard-emits-diagnostic' );
$assert( 'canonical_source_retained' === ( $guarded['diagnostics'][0]['reason'] ?? '' ), 'canonical-source-guard-reports-reason' );

$missing_payload = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'files' => array(
			array(
				'path' => 'images/missing.svg',
				'kind' => 'image',
			),
		),
	),
	false
);
$assert( is_wp_error( $missing_payload ), 'artifact-file-missing-payload-errors' );
$assert( 'static_site_importer_materialization_plan_asset_content_missing' === ( is_wp_error( $missing_payload ) ? $missing_payload->get_error_code() : '' ), 'artifact-file-missing-payload-error-code' );

$ephemeral = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'files' => array(
			array(
				'path'        => 'images/tmp.svg',
				'kind'        => 'image',
				'content'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
				'source_role' => 'ephemeral',
				'keep_source' => false,
			),
		),
	),
	false
);
$assert( ! is_wp_error( $ephemeral ), 'ephemeral-source-succeeds', is_wp_error( $ephemeral ) ? $ephemeral->get_error_message() : '' );
$assert( 'ephemeral' === ( $ephemeral['assets']['images/tmp.svg']['source_role'] ?? '' ), 'ephemeral-source-role-is-preserved' );
$assert( false === ( $ephemeral['assets']['images/tmp.svg']['keep_source'] ?? true ), 'ephemeral-source-can-opt-out-of-retention' );
$assert( true === ( $ephemeral['assets']['images/tmp.svg']['deletion_allowed'] ?? false ), 'ephemeral-source-allows-deletion-semantics' );
$assert( array() === ( $ephemeral['diagnostics'] ?? array() ), 'ephemeral-source-does-not-warn' );

$theme_writes = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'imported-theme',
	'Imported Theme',
	'html, body { margin: 0; } body { font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important; color: #111; }',
	false,
	false
);
$theme_json   = json_decode( $theme_writes[ $theme_dir . '/theme.json' ] ?? '', true );
$front_page_template = (string) ( $theme_writes[ $theme_dir . '/templates/front-page.html' ] ?? '' );
$assert( is_array( $theme_json ), 'generated-theme-json-decodes' );
$assert( true === ( $theme_json['settings']['spacing']['blockGap'] ?? null ), 'generated-theme-enables-block-gap-support' );
$assert( str_contains( $front_page_template, '<!-- wp:post-content /-->' ), 'post-content-template-uses-neutral-wrapper' );
$assert( ! str_contains( $front_page_template, '"tagName":"main"' ), 'post-content-template-does-not-duplicate-source-main-selector' );
$assert(
	'"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' === ( $theme_json['styles']['typography']['fontFamily'] ?? '' ),
	'body-font-family-is-materialized-in-theme-json',
	(string) ( $theme_json['styles']['typography']['fontFamily'] ?? '' )
);

$unsafe_theme_writes = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'imported-theme',
	'Imported Theme',
	'body { font-family: url("https://example.test/font"); }',
	false,
	false
);
$unsafe_theme_json   = json_decode( $unsafe_theme_writes[ $theme_dir . '/theme.json' ] ?? '', true );
$assert( ! isset( $unsafe_theme_json['styles']['typography']['fontFamily'] ), 'unsafe-body-font-family-is-not-materialized' );

// Sources commonly apply the body face through a CSS custom property
// (`body { font-family: var(--font-body) }` defined in :root). theme.json must
// carry the resolved typeface stack so the body font actually applies, instead
// of an undefined `var(--font-body)` that silently falls back to the default.
$var_font_writes = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'imported-theme',
	'Imported Theme',
	":root { --font-body: 'Lora', Georgia, serif; } body { font-family: var(--font-body); }",
	false,
	false
);
$var_font_json   = json_decode( $var_font_writes[ $theme_dir . '/theme.json' ] ?? '', true );
$assert(
	"'Lora', Georgia, serif" === ( $var_font_json['styles']['typography']['fontFamily'] ?? '' ),
	'var-body-font-family-resolves-to-concrete-typeface-in-theme-json',
	(string) ( $var_font_json['styles']['typography']['fontFamily'] ?? '' )
);

// An unresolvable var() (no definition, no fallback) must never be written into
// theme.json as a dead reference.
$unresolved_var_writes = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'imported-theme',
	'Imported Theme',
	'body { font-family: var(--font-missing); }',
	false,
	false
);
$unresolved_var_json   = json_decode( $unresolved_var_writes[ $theme_dir . '/theme.json' ] ?? '', true );
$assert( ! isset( $unresolved_var_json['styles']['typography']['fontFamily'] ), 'unresolved-var-body-font-family-is-not-materialized' );

$token_theme_writes = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'imported-theme',
	'Imported Theme',
	'',
	false,
	false,
	array(),
	array(),
	array(
		'site' => array(
			'schema'        => 'blocks-engine/php-transformer/materialization-plan/v1',
			'design_tokens' => array(
				'colors'        => array(
					array(
						'slug'  => 'Brand Primary',
						'name'  => 'Brand Primary',
						'color' => '#0f766e',
					),
					array(
						'slug'  => 'unsafe-color',
						'name'  => 'Unsafe Color',
						'color' => 'url(https://example.test/bad.svg)',
					),
				),
				'font_families' => array(
					array(
						'slug'        => 'display',
						'name'        => 'Display',
						'font_family' => 'Inter, Arial, sans-serif',
					),
				),
				'layout'        => array(
					'contentSize' => '960px',
					'wideSize'    => '1280px',
				),
			),
			'theme_json'    => array(
				'settings' => array(
					'spacing' => array(
						'blockGap' => false,
						'units'    => array( 'px', 'rem', '%' ),
					),
				),
				'styles'   => array(
					'elements' => array(
						'link' => array(
							'color' => array(
								'text' => 'var(--wp--preset--color--brand-primary)',
							),
						),
					),
				),
			),
		),
	)
);
$token_theme_json   = json_decode( $token_theme_writes[ $theme_dir . '/theme.json' ] ?? '', true );
$token_palette      = $token_theme_json['settings']['color']['palette'] ?? array();
$token_fonts        = $token_theme_json['settings']['typography']['fontFamilies'] ?? array();
$assert( '#0f766e' === ( $token_palette[0]['color'] ?? '' ), 'materialization-plan-color-token-promotes-to-theme-json' );
$assert( 1 === count( $token_palette ), 'unsafe-materialization-plan-color-token-is-skipped' );
$assert( 'Inter, Arial, sans-serif' === ( $token_fonts[0]['fontFamily'] ?? '' ), 'materialization-plan-font-token-promotes-to-theme-json' );
$assert( '960px' === ( $token_theme_json['settings']['layout']['contentSize'] ?? '' ), 'materialization-plan-layout-token-overrides-content-size' );
$assert( false === ( $token_theme_json['settings']['spacing']['blockGap'] ?? null ), 'materialization-plan-theme-json-fragment-overrides-block-gap-support' );
$assert( array( 'px', 'rem', '%' ) === ( $token_theme_json['settings']['spacing']['units'] ?? array() ), 'materialization-plan-theme-json-fragment-merges-settings' );
$assert( 'var(--wp--preset--color--brand-primary)' === ( $token_theme_json['styles']['elements']['link']['color']['text'] ?? '' ), 'materialization-plan-theme-json-fragment-merges-styles' );

$supplemental_assets = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'site'  => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'assets' => array(
				array(
					'path'    => 'style.css',
					'kind'    => 'css',
					'content' => '.hero { background-image: url("images/hero.png"); }',
				),
			),
		),
		'files' => array(
			array(
				'path'           => 'images/hero.png',
				'kind'           => 'image',
				'content_base64' => base64_encode( 'png-bytes' ),
			),
		),
	),
	false
);
$assert( ! is_wp_error( $supplemental_assets ), 'supplemental-artifact-assets-succeed', is_wp_error( $supplemental_assets ) ? $supplemental_assets->get_error_message() : '' );
$assert( isset( $supplemental_assets['assets']['images/hero.png'] ), 'supplemental-artifact-asset-added-to-asset-map' );
$assert( str_contains( $supplemental_assets['css'] ?? '', 'assets/materialized/images/hero.png' ), 'supplemental-artifact-asset-rewrites-css-url' );

$template_writes = Static_Site_Importer_Theme_Materializer::template_artifact_writes(
	$theme_dir,
	array(
		'site' => array(
			'schema'          => 'blocks-engine/php-transformer/materialization-plan/v1',
			'template_writes' => array(
				array(
					'type'    => 'wp_template',
					'slug'    => 'archive',
					'content' => '<!-- wp:query --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
				),
				array(
					'type'    => 'wp_template',
					'path'    => 'templates/404.html',
					'content' => '<!-- wp:heading {"level":1} --><h1>Not found</h1><!-- /wp:heading -->',
				),
			),
		),
	)
);
$assert( ! is_wp_error( $template_writes ), 'template-artifact-writes-succeed', is_wp_error( $template_writes ) ? $template_writes->get_error_message() : '' );
$assert( isset( $template_writes['writes'][ $theme_dir . '/templates/archive.html' ] ), 'archive-template-write-materializes-from-slug' );
$assert( isset( $template_writes['writes'][ $theme_dir . '/templates/404.html' ] ), '404-template-write-materializes-from-path' );
$assert( 'templates/archive.html' === ( $template_writes['reports'][0]['path'] ?? '' ), 'template-write-report-records-path' );

$source_template_writes = Static_Site_Importer_Theme_Materializer::source_document_template_writes(
	$theme_dir,
	array(
		'archive.html'        => '<!-- wp:query --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
		'search-results.html' => '<!-- wp:search /-->',
		'about.html'          => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
	)
);
$assert( isset( $source_template_writes['writes'][ $theme_dir . '/templates/archive.html' ] ), 'archive-source-document-materializes-template' );
$assert( isset( $source_template_writes['writes'][ $theme_dir . '/templates/search.html' ] ), 'search-results-source-document-materializes-search-template' );
$assert( ! isset( $source_template_writes['writes'][ $theme_dir . '/templates/about.html' ] ), 'ordinary-source-document-does-not-materialize-template' );

$supplemental_asset = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files(
	$theme_dir,
	'https://example.test/wp-content/themes/imported',
	array(
		'site'  => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'assets' => array(
				array(
					'path'    => 'style.css',
					'kind'    => 'css',
					'content' => '.hero{background-image:url("assets/hero.jpg")}',
				),
			),
		),
		'files' => array(
			array(
				'path'           => 'assets/hero.jpg',
				'kind'           => 'image',
				'content_base64' => base64_encode( 'fake-jpeg' ),
			),
		),
	),
	false
);
$assert( ! is_wp_error( $supplemental_asset ), 'supplemental-artifact-asset-succeeds', is_wp_error( $supplemental_asset ) ? $supplemental_asset->get_error_message() : '' );
$assert( isset( $supplemental_asset['assets']['assets/hero.jpg'] ), 'supplemental-artifact-asset-is-reported' );
$assert( str_contains( (string) ( $supplemental_asset['css'] ?? '' ), 'assets/materialized/assets/hero.jpg' ), 'supplemental-artifact-asset-rewrites-css-url' );

$unsafe_template_writes = Static_Site_Importer_Theme_Materializer::template_artifact_writes(
	$theme_dir,
	array(
		'site' => array(
			'schema'          => 'blocks-engine/php-transformer/materialization-plan/v1',
			'template_writes' => array(
				array(
					'type'    => 'wp_template',
					'path'    => '../404.html',
					'content' => '<!-- wp:post-content /-->',
				),
			),
		),
	)
);
$assert( is_wp_error( $unsafe_template_writes ), 'unsafe-template-path-errors' );
$assert( 'static_site_importer_template_unsupported' === ( is_wp_error( $unsafe_template_writes ) ? $unsafe_template_writes->get_error_code() : '' ), 'unsafe-template-path-error-code' );

$source_template_writes = Static_Site_Importer_Theme_Materializer::source_document_template_writes(
	$theme_dir,
	array(
		'archive.html' => '<!-- wp:query --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
		'404.html'     => '<!-- wp:heading {"level":1} --><h1>Not found</h1><!-- /wp:heading -->',
		'index.html'   => '<!-- wp:post-content /-->',
	)
);
$assert( isset( $source_template_writes['writes'][ $theme_dir . '/templates/archive.html' ] ), 'source-archive-document-materializes-archive-template' );
$assert( isset( $source_template_writes['writes'][ $theme_dir . '/templates/404.html' ] ), 'source-404-document-materializes-404-template' );
$assert( ! isset( $source_template_writes['writes'][ $theme_dir . '/templates/index.html' ] ), 'source-index-document-does-not-override-base-index-template' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: theme materializer dry-run smoke passed (' . $assertions . " assertions)\n";
