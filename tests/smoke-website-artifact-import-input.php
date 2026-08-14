<?php
/**
 * Contract coverage for website artifact import input normalization.
 *
 * Run from the repository root:
 * php tests/smoke-website-artifact-import-input.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( trim( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $directory ) {
		return is_dir( $directory ) || mkdir( $directory, 0777, true );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000001';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		unset( $hook_name, $args );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ) {
		unset( $hook_name );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback ) {
		unset( $hook_name, $callback );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_args = array();

		public static function import_website_artifact( array $artifact, array $args = array() ): array {
			self::$last_args = $args;
			return array( 'quality' => array( 'pass' => true ) );
		}
	}
}

if ( ! function_exists( 'static_site_importer_source_runtime' ) ) {
	function static_site_importer_source_runtime( array $source ): array {
		return array(
			'artifact'        => array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint' => (string) ( $source['entrypoint'] ?? '' ),
				'files'      => isset( $source['files'] ) && is_array( $source['files'] ) ? $source['files'] : array(),
			),
			'source_metadata' => array(),
			'provider'        => 'test',
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-website-artifact-import-input.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-import-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-validation-runtime.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$input = array(
	'slug'                                 => 'contract-theme',
	'name'                                 => 'Contract Theme',
	'site_title'                           => 'Contract Site',
	'stale_page_action'                    => 'draft',
	'activate'                             => true,
	'overwrite'                            => true,
	'disable_smilies'                      => true,
	'fail_on_quality'                      => true,
	'allow_missing_woocommerce'            => true,
	'allow_missing_jetpack'                => true,
	'materialize_dependencies'             => false,
	'runtime_lifecycle_phase'              => 'resume',
	'runtime_lifecycle_request_id'         => 'prepared-request',
	'require_proven_dynamic_client_assets' => false,
	'seed_entities'                        => true,
	'products_manifest'                    => array( 'products' => array() ),
	'commerce_context'                     => array( 'currency' => 'USD' ),
	'write_theme_report_artifacts'         => true,
	'asset_materialization_policy'         => 'use_map',
	'asset_map'                            => array( 'logo.svg' => 'https://example.test/logo.svg' ),
	'compiler_options'                     => array( 'include_conversion_report' => false ),
	'source_metadata'                      => array( 'request_id' => 'contract-1' ),
	'validation_artifacts'                 => array( 'visual_diff' => array( 'path' => '/tmp/diff.png' ) ),
	'client_script_policy'                 => 'isolated_preview',
	'client_script_provenance'             => array( 'ref' => 'contract:preview' ),
	'client_script_isolated'               => true,
	'theme_materialization'        => 'classic',
);
$direct = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );

// disable_smilies (issue #780) defaults on so ordinary imports keep literal text.
$default_input = Static_Site_Importer_Website_Artifact_Import_Input::normalize( array( 'slug' => 'default-theme' ) );
$assert( true === $default_input['disable_smilies'], 'disable-smilies-defaults-true' );
$assert( true === Static_Site_Importer_Website_Artifact_Import_Input::normalize( array( 'disable_smilies' => '1' ) )['disable_smilies'], 'disable-smilies-coerces-true-string' );
$assert( false === Static_Site_Importer_Website_Artifact_Import_Input::normalize( array( 'disable_smilies' => '0' ) )['disable_smilies'], 'disable-smilies-coerces-false-string' );

static_site_importer_ability_import( array_merge( $input, array( 'source' => array( 'type' => 'files', 'files' => array() ) ) ) );
$direct_entrypoint = Static_Site_Importer_Theme_Generator::$last_args;

foreach ( array_keys( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES ) as $field ) {
	$assert( array_key_exists( $field, $direct ), 'normalizer-covers-schema-' . $field );
	$assert( $input[ $field ] === $direct[ $field ], 'normalizer-forwards-' . $field );
	$assert( $direct[ $field ] === $direct_entrypoint[ $field ], 'direct-equivalent-' . $field );
}
$assert( ! isset( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES['report'] ), 'remote-schema-omits-report-destination' );
$assert( ! array_key_exists( 'report', Static_Site_Importer_Website_Artifact_Import_Input::normalize( array( 'report' => '/tmp/report.json' ) ) ), 'remote-normalizer-rejects-report-destination' );
$rejected_report = static_site_importer_ability_import( array( 'report' => '/tmp/report.json', 'source' => array( 'type' => 'files', 'files' => array() ) ) );
$assert( 'static_site_importer_report_destination_forbidden' === ( $rejected_report['error']['code'] ?? '' ), 'ability-rejects-report-destination' );
$cli_report = sys_get_temp_dir() . '/ssi-cli-report-' . uniqid( '', true ) . '.json';
$cli_result = static_site_importer_cli_import( array_merge( $input, array( 'report' => $cli_report, 'source' => array( 'type' => 'files', 'files' => array() ) ) ) );
$assert( ! empty( $cli_result['success'] ), 'cli-report-output-seam-succeeds' );
$assert( $cli_report === ( Static_Site_Importer_Theme_Generator::$last_args['report'] ?? '' ), 'cli-report-output-seam-forwards-only-internally' );

$url_method = new ReflectionMethod( Static_Site_Importer_URL_Import_Runtime::class, 'import_args' );
$url = $url_method->invoke( null, $input, array( 'provider' => 'contract-provider', 'source_metadata' => array( 'provider_id' => 'source-1' ) ) );
foreach ( array_keys( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES ) as $field ) {
	if ( 'source_metadata' !== $field ) {
		$assert( $direct[ $field ] === $url[ $field ], 'url-equivalent-' . $field );
	}
}
$assert( 'contract-1' === ( $url['source_metadata']['request_id'] ?? '' ), 'url-preserves-caller-metadata' );
$assert( 'source-1' === ( $url['source_metadata']['provider_id'] ?? '' ), 'url-preserves-provider-metadata' );

$artifact = array( 'schema' => 'test/artifact/v1', 'files' => array(), 'provenance' => array( 'provider' => 'figma' ) );
$figma    = Static_Site_Importer_Figma_Import::import_input( $input, $artifact );
foreach ( array_keys( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES ) as $field ) {
	if ( 'source_metadata' !== $field ) {
		$assert( $direct[ $field ] === $figma[ $field ], 'figma-equivalent-' . $field );
	}
}
$assert( 'contract-1' === ( $figma['source_metadata']['request_id'] ?? '' ), 'figma-preserves-caller-metadata' );
$assert( 'figma' === ( $figma['source_metadata']['provider'] ?? '' ), 'figma-adds-provider-metadata' );

$artifact_dir = sys_get_temp_dir() . '/ssi-import-input-' . uniqid( '', true );
$validation   = Static_Site_Importer_Validation_Runtime::validate_artifact( array_merge( $input, array( 'artifact' => $artifact, 'artifact_dir' => $artifact_dir ) ) );
$validation_args = Static_Site_Importer_Theme_Generator::$last_args;
foreach ( array_keys( Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES ) as $field ) {
	if ( 'source_metadata' !== $field ) {
		$assert( $direct[ $field ] === $validation_args[ $field ], 'validation-equivalent-' . $field );
	}
}
$assert( str_ends_with( $validation_args['report'], '/import-report.json' ), 'validation-owns-report-path' );
$assert( 'contract-1' === ( $validation_args['source_metadata']['request_id'] ?? '' ), 'validation-preserves-caller-metadata' );
$assert( 'static-site-importer/current-runtime' === ( $validation_args['source_metadata']['validation_provider'] ?? '' ), 'validation-adds-provider-metadata' );

if ( is_dir( $artifact_dir ) ) {
	rmdir( $artifact_dir );
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Website artifact import input smoke passed (%d assertions).\n", $assertions );
