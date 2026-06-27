<?php
/**
 * Smoke test: URL imports use the provider boundary and return report envelopes.
 *
 * Run from the repository root:
 * php tests/smoke-url-import-runtime.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

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

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000000';
	}
}

$GLOBALS['ssi_url_import_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback ): void {
		$GLOBALS['ssi_url_import_filters'][ $hook_name ][] = $callback;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback ): void {
		$GLOBALS['ssi_url_import_filters'][ $hook_name ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['ssi_url_import_filters'][ $hook_name ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		return false;
	}
}

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_artifact = array();
		public static array $last_args     = array();

		public static function import_website_artifact( array $artifact, array $args = array() ): array {
			self::$last_artifact = $artifact;
			self::$last_args     = $args;

			return array(
				'theme_slug'            => $args['slug'] ?? '',
				'report_path'           => '/tmp/import-report.json',
				'import_report_summary' => array(
					'status'       => 'completed',
					'quality_pass' => true,
				),
			);
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-import-runtime.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$missing = Static_Site_Importer_URL_Import_Runtime::import_url( array() );
$assert( is_wp_error( $missing ), 'missing-url-errors' );
$assert( 'static_site_importer_missing_url' === $missing->get_error_code(), 'missing-url-error-code' );

add_filter(
	'static_site_importer_url_import_provider',
	static function ( mixed $output, array $request ): array {
		return array(
			'provider'        => 'test-private-runtime',
			'artifact'        => array(
				'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
				'files'  => array(
					array(
						'path'    => 'website/index.html',
						'content' => '<main><h1>Imported privately</h1></main>',
					),
				),
			),
			'source_metadata' => array(
				'source_url' => $request['url'],
				'visibility' => 'private',
			),
		);
	}
);

$result = Static_Site_Importer_URL_Import_Runtime::import_url(
	array(
		'url'             => 'https://private.example.test/',
		'slug'            => 'private-import',
		'overwrite'       => true,
		'source_metadata' => array( 'requested_by' => 'external-caller' ),
	)
);

$assert( ! is_wp_error( $result ), 'provider-import-succeeds' );
$assert( 'private-import' === ( $result['theme_slug'] ?? '' ), 'result-passes-through' );
$assert( 'website/index.html' === ( Static_Site_Importer_Theme_Generator::$last_artifact['files'][0]['path'] ?? '' ), 'provider-artifact-imported' );
$assert( true === ( Static_Site_Importer_Theme_Generator::$last_args['overwrite'] ?? null ), 'import-args-preserved' );
$assert( 'external-caller' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['requested_by'] ?? '' ), 'caller-metadata-preserved' );
$assert( 'private' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['visibility'] ?? '' ), 'provider-metadata-merged' );
$assert( 'test-private-runtime' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['url_import_provider'] ?? '' ), 'provider-recorded' );

$ability = static_site_importer_ability_import_url(
	array(
		'url' => 'https://private.example.test/',
		'slug' => 'ability-import',
	)
);

$assert( true === ( $ability['success'] ?? false ), 'ability-succeeds' );
$assert( 'completed' === ( $ability['import_report_summary']['status'] ?? '' ), 'ability-returns-report-summary' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "URL import runtime smoke passed (%d assertions).\n", $assertions );
