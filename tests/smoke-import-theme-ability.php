<?php
/**
 * Smoke test: static site theme import is exposed as an Ability.
 *
 * Run from the repository root:
 * php tests/smoke-import-theme-ability.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$registered_categories = array();
$registered_abilities  = array();
$actions               = array();

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $name, array $args ): void {
		$GLOBALS['registered_categories'][ $name ] = $args;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['registered_abilities'][ $name ] = $args;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return ! empty( $GLOBALS['current_user_can_switch_themes'] ) && 'switch_themes' === $capability;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return ! empty( $GLOBALS['doing_actions'][ $hook ] );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['did_actions'][ $hook ] ?? 0 );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable|string $callback ): void {
		$GLOBALS['actions'][ $hook ][] = $callback;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code, string $message, mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

class Static_Site_Importer_Theme_Generator {
	public static array $last_call = array();

	public static function import_theme( string $html_path, array $args = array() ): array|WP_Error {
		self::$last_call = array( $html_path, $args );

		return array(
			'theme_slug'            => $args['slug'],
			'theme_name'            => $args['name'],
			'report_path'           => '/tmp/import-report.json',
			'import_report_summary' => array(
				'status'       => 'completed',
				'quality_pass' => true,
			),
		);
	}

	public static function export_theme( array $args = array() ): array|WP_Error {
		self::$last_call = array( 'export', $args );

		return array(
			'artifact_set' => array(
				'schema'     => 'static-site-importer/static-site-artifact-set/v1',
				'entrypoint' => $args['entrypoint'],
				'files'      => array(
					array(
						'path'    => $args['entrypoint'],
						'content' => '<!doctype html><html><body>Fixture</body></html>',
						'kind'    => 'document',
						'role'    => 'entrypoint',
					),
				),
			),
			'files'        => array(
				array(
					'path'    => $args['entrypoint'],
					'content' => '<!doctype html><html><body>Fixture</body></html>',
					'kind'    => 'document',
					'role'    => 'entrypoint',
				),
			),
			'report'       => array( 'status' => 'completed' ),
		);
}
}
$GLOBALS['did_actions']['wp_abilities_api_categories_init'] = 1;
$GLOBALS['did_actions']['wp_abilities_api_init']            = 1;
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( isset( $registered_categories['static-site-importer'] ), 'category-registers-after-api-init' );
$assert( isset( $registered_abilities['static-site-importer/import-theme'] ), 'ability-registers-after-api-init' );
$assert( isset( $registered_abilities['static-site-importer/export-theme'] ), 'export-ability-registers-after-api-init' );

static_site_importer_register_ability_category();
static_site_importer_register_abilities();

$assert( isset( $registered_categories['static-site-importer'] ), 'category-registered' );
$assert( isset( $registered_abilities['static-site-importer/import-theme'] ), 'import-theme-ability-registered' );
$assert( isset( $registered_abilities['static-site-importer/export-theme'] ), 'export-theme-ability-registered' );

$ability = $registered_abilities['static-site-importer/import-theme'] ?? array();
$assert( 'static_site_importer_ability_import_theme' === ( $ability['execute_callback'] ?? '' ), 'execute-callback' );
$assert( 'static_site_importer_ability_permission_callback' === ( $ability['permission_callback'] ?? '' ), 'permission-callback' );
$GLOBALS['current_user_can_switch_themes'] = true;
$assert( static_site_importer_ability_permission_callback(), 'permission-allows-theme-manager' );
$GLOBALS['current_user_can_switch_themes'] = false;
$assert( ! static_site_importer_ability_permission_callback(), 'permission-denies-user-without-theme-cap' );
define( 'WP_CLI', true );
$assert( static_site_importer_ability_permission_callback(), 'permission-allows-wp-cli' );

$missing = static_site_importer_ability_import_theme( array() );
$assert( empty( $missing['success'] ), 'missing-html-path-fails' );
$assert( 'static_site_importer_missing_html_path' === ( $missing['error']['code'] ?? '' ), 'missing-html-path-error-code' );
$assert( 'failed' === ( $missing['import_report_summary']['status'] ?? '' ), 'missing-html-path-includes-report-summary' );
$assert( in_array( 'static_site_importer_missing_html_path', $missing['import_report_summary']['failure_reasons'] ?? array(), true ), 'missing-html-path-summary-carries-error-code' );

$result = static_site_importer_ability_import_theme(
	array(
		'html_path'       => '/tmp/source/index.html',
		'slug'            => 'fixture-theme',
		'name'            => 'Fixture Theme',
		'activate'        => true,
		'overwrite'       => true,
		'keep_source'               => true,
		'fail_on_quality'           => true,
		'max_fallbacks'             => 0,
		'allow_missing_woocommerce' => true,
		'report'                    => '/tmp/report.json',
		'asset_map'                 => array(
			'assets/hero.jpg' => array(
				'url'           => 'https://example.test/uploads/hero.jpg',
				'attachment_id' => 123,
			),
		),
		'source_metadata'           => array( 'final_url' => 'https://example.com/' ),
	)
);

$assert( ! empty( $result['success'] ), 'ability-import-succeeds' );
$assert( 'completed' === ( $result['result']['import_report_summary']['status'] ?? '' ), 'ability-result-includes-import-report-summary' );
$assert( '/tmp/source/index.html' === ( Static_Site_Importer_Theme_Generator::$last_call[0] ?? '' ), 'html-path-forwarded' );
$args = Static_Site_Importer_Theme_Generator::$last_call[1] ?? array();
$assert( 'fixture-theme' === ( $args['slug'] ?? '' ), 'slug-forwarded' );
$assert( true === ( $args['activate'] ?? false ), 'activate-forwarded' );
$assert( 0 === ( $args['max_fallbacks'] ?? null ), 'max-fallbacks-forwarded' );
$assert( true === ( $args['allow_missing_woocommerce'] ?? false ), 'allow-missing-woocommerce-forwarded' );
$assert( 123 === ( $args['asset_map']['assets/hero.jpg']['attachment_id'] ?? null ), 'asset-map-forwarded' );
$assert( 'https://example.com/' === ( $args['source_metadata']['final_url'] ?? '' ), 'source-metadata-forwarded' );

$export_ability = $registered_abilities['static-site-importer/export-theme'] ?? array();
$assert( 'static_site_importer_ability_export_theme' === ( $export_ability['execute_callback'] ?? '' ), 'export-execute-callback' );
$export = static_site_importer_ability_export_theme(
	array(
		'theme_slug'      => 'fixture-theme',
		'entrypoint'      => 'static-site/index.html',
		'include_pages'   => false,
		'source_metadata' => array( 'source' => 'smoke' ),
	)
);
$assert( ! empty( $export['success'] ), 'ability-export-succeeds' );
$assert( 'static-site/index.html' === ( $export['artifact_set']['entrypoint'] ?? '' ), 'ability-export-includes-entrypoint' );
$export_args = Static_Site_Importer_Theme_Generator::$last_call[1] ?? array();
$assert( 'fixture-theme' === ( $export_args['theme_slug'] ?? '' ), 'export-theme-slug-forwarded' );
$assert( false === ( $export_args['include_pages'] ?? true ), 'export-include-pages-forwarded' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import theme ability smoke passed (' . $assertions . " assertions)\n";
