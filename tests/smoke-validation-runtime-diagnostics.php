<?php
/**
 * Smoke coverage for validation-runtime diagnostic propagation.
 *
 * Run from the repository root:
 * php tests/smoke-validation-runtime-diagnostics.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );

		return trim( is_string( $title ) ? $title : '', '-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
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
	function wp_generate_uuid4() {
		static $sequence = 0;
		++$sequence;

		return '00000000-0000-4000-8000-' . str_pad( (string) $sequence, 12, '0', STR_PAD_LEFT );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	$wp_json_encode_calls = 0;
	function wp_json_encode( $value ) {
		global $wp_json_encode_calls;
		++$wp_json_encode_calls;
		return json_encode( $value );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_args = array();

		public static function import_website_artifact( array $artifact, array $args = array() ) {
			self::$last_args = $args;
			if ( 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
				return array(
					'status'        => 'dependencies_prepared',
					'fresh_runtime' => array( 'request_id' => $args['runtime_lifecycle_invocation_id'] ?? '', 'lifecycle_checkpoint_id' => 'checkpoint-id' ),
					'runtime_lifecycle_checkpoint' => 'checkpoint-id',
				);
			}
			if ( 'resume' === ( $args['runtime_lifecycle_phase'] ?? '' ) && ( $args['runtime_lifecycle_request_id'] ?? '' ) === ( $args['runtime_lifecycle_invocation_id'] ?? '' ) ) {
				return new WP_Error( 'static_site_importer_fresh_runtime_required' );
			}

			return array(
				'quality'    => array( 'pass' => true ),
				'theme_slug' => 'validation-theme',
			);
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-validation-runtime.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$artifact_dir = sys_get_temp_dir() . '/ssi-validation-runtime-diagnostics-' . uniqid( '', true );
mkdir( $artifact_dir, 0777, true );
$report_path = $artifact_dir . '/import-report.json';

$physical_artifact_root = $artifact_dir . '/physical';
$aliased_artifact_root  = $artifact_dir . '/aliased';
mkdir( $physical_artifact_root );
if ( function_exists( 'symlink' ) && symlink( $physical_artifact_root, $aliased_artifact_root ) ) {
	$artifact_dir_method = new ReflectionMethod( Static_Site_Importer_Validation_Runtime::class, 'artifact_dir' );
	$resolved_artifact_dir = $artifact_dir_method->invoke( null, array( 'artifact_dir' => $aliased_artifact_root . '/validation' ), 'fixture' );
	$assert( realpath( $physical_artifact_root . '/validation' ) === $resolved_artifact_dir, 'validation-artifact-directory-resolves-symlinked-ancestor' );
	rmdir( $physical_artifact_root . '/validation' );
	unlink( $aliased_artifact_root );
}
rmdir( $physical_artifact_root );

file_put_contents(
	$report_path,
	json_encode(
		array(
			'quality'       => array(
				'semantic_parity_failure_count' => 1,
			),
			'generated_theme' => array(
				'block_documents' => array(
					array( 'content' => '<!-- wp:group --><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:html --><div>Fallback</div><!-- /wp:html --><!-- /wp:group -->' ),
					array( 'block_count' => 2, 'core_html_block_count' => 0, 'freeform_block_count' => 1 ),
				),
			),
			'blocks_engine' => array(
				'transformer'         => array(
					'package'   => 'automattic/blocks-engine-php-transformer',
					'version'   => 'dev-trunk',
					'reference' => str_repeat( 'a', 40 ),
				),
				'wordpress_site_plan' => array(
					'schema' => 'blocks-engine/wordpress-site-plan/v2',
					'assets' => array(
						array(
							'target_path'    => 'assets/app.js',
							'payload_sha256' => 'asset-hash',
							'content_base64' => str_repeat( 'x', 4096 ),
						),
					),
				),
			),
			'diagnostics'   => array(
				array(
					'type'        => 'semantic_parity_navigation_missing',
					'severity'    => 'warning',
					'source_path' => 'website/index.html',
					'selector'    => 'footer nav',
					'reason'      => 'Source navigation menu was not represented as a core/navigation block.',
				),
			),
		),
		JSON_PRETTY_PRINT
	)
);

$method = new ReflectionMethod( Static_Site_Importer_Validation_Runtime::class, 'result_from_import' );
$result = $method->invoke(
	null,
	array(
		'external_report_path'    => $report_path,
		'quality'                 => array( 'pass' => false ),
		'theme_slug'              => 'ssi-fixture-theme',
		'materialization_receipt' => array(
			'schema'    => 'static-site-importer/materialization-receipt/v2',
			'status'    => 'completed',
			'plan_identity' => array( 'schema' => 'blocks-engine/wordpress-site-plan-identity/v1', 'hash' => str_repeat( 'a', 64 ) ),
			'completed' => array(
				'pages'           => array( 'index.html' => 1 ),
				'files'           => array( array( 'target_path' => 'style.css' ) ),
				'operations'      => array(),
				'declaration_ids' => array( 'runtime-app' ),
			),
		),
	),
	$artifact_dir,
	array(
		'slug' => 'fixture-22',
		'name' => 'Fixture 22',
	)
);

$assert( false === ( $result['success'] ?? true ), 'quality-failure-reflected' );
$assert( isset( $result['fixture_diagnostics']['diagnostics'] ), 'nested-fixture-diagnostics-present' );
$assert( 1 === count( $result['diagnostics'] ?? array() ), 'top-level-diagnostics-present' );
$assert( 'semantic_parity_navigation_missing' === ( $result['diagnostics'][0]['type'] ?? '' ), 'top-level-diagnostic-type-preserved' );
$assert( 'footer nav' === ( $result['diagnostics'][0]['selector'] ?? '' ), 'top-level-diagnostic-selector-preserved' );
$assert( 1 === ( $result['diagnostic_summary']['total'] ?? 0 ), 'top-level-diagnostic-summary-present' );
$assert( str_repeat( 'a', 40 ) === ( $result['fixture_diagnostics']['blocks_engine']['transformer']['reference'] ?? '' ), 'transformer-provenance-preserved' );
$assert( 1 === ( $result['fixture_diagnostics']['blocks_engine']['wordpress_site_plan']['asset_count'] ?? 0 ), 'site-plan-asset-count-preserved' );
$assert( ! isset( $result['fixture_diagnostics']['blocks_engine']['wordpress_site_plan']['assets'][0]['content_base64'] ), 'site-plan-asset-payload-omitted' );
$assert( 'completed' === ( $result['fixture_diagnostics']['materialization_receipt']['status'] ?? '' ), 'materialization-receipt-status-preserved' );
$assert( 1 === ( $result['fixture_diagnostics']['materialization_receipt']['page_count'] ?? 0 ), 'materialization-receipt-counts-preserved' );

$matrix_result = Static_Site_Importer_Validation_Runtime::fixture_matrix_result( $result );
$assert( Static_Site_Importer_Validation_Runtime::FIXTURE_MATRIX_RESULT_SCHEMA === ( $matrix_result['schema'] ?? '' ), 'fixture-matrix-schema' );
$assert( ! isset( $matrix_result['import_report'] ), 'fixture-matrix-omits-full-import-report' );
$assert( false === ( $matrix_result['quality']['pass'] ?? true ), 'fixture-matrix-keeps-quality-pass' );
$assert( 1 === count( $matrix_result['diagnostics'] ?? array() ), 'fixture-matrix-keeps-actionable-diagnostics' );
$assert( ! isset( $matrix_result['fixture_diagnostics']['diagnostics'] ), 'fixture-matrix-does-not-duplicate-diagnostics' );
$assert( 5 === ( $matrix_result['fixture_diagnostics']['quality_counts']['block_count'] ?? 0 ), 'fixture-matrix-derives-block-count' );
$assert( 1 === ( $matrix_result['fixture_diagnostics']['quality_counts']['core_html_block_count'] ?? 0 ), 'fixture-matrix-derives-core-html-count' );
$assert( 1 === ( $matrix_result['fixture_diagnostics']['quality_counts']['freeform_block_count'] ?? 0 ), 'fixture-matrix-derives-freeform-count' );
$assert( str_repeat( 'a', 40 ) === ( $matrix_result['fixture_diagnostics']['blocks_engine']['transformer']['reference'] ?? '' ), 'fixture-matrix-keeps-transformer-reference' );
$assert( 'blocks-engine/wordpress-site-plan/v2' === ( $matrix_result['fixture_diagnostics']['blocks_engine']['wordpress_site_plan']['schema'] ?? '' ), 'fixture-matrix-keeps-site-plan' );
$assert( 'completed' === ( $matrix_result['fixture_diagnostics']['materialization_receipt']['status'] ?? '' ), 'fixture-matrix-keeps-materialization-receipt' );

$default_artifact_dir = $artifact_dir . '/default-materialization';
$default_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'     => array( 'schema' => 'test/website-artifact/v1' ),
		'artifact_dir' => $default_artifact_dir,
		'slug'         => 'default-materialization',
	)
);
$assert( true === ( Static_Site_Importer_Theme_Generator::$last_args['materialize_dependencies'] ?? null ), 'validation-defaults-dependency-materialization-on' );
$assert( true === ( $default_result['request']['import_args']['materialize_dependencies'] ?? null ), 'validation-result-records-default-dependency-materialization' );

$override_artifact_dir = $artifact_dir . '/disabled-materialization';
$override_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'                 => array( 'schema' => 'test/website-artifact/v1' ),
		'artifact_dir'             => $override_artifact_dir,
		'slug'                     => 'disabled-materialization',
		'materialize_dependencies' => false,
	)
);
$assert( false === ( Static_Site_Importer_Theme_Generator::$last_args['materialize_dependencies'] ?? null ), 'validation-honors-disabled-dependency-materialization' );
$assert( false === ( $override_result['request']['import_args']['materialize_dependencies'] ?? null ), 'validation-result-records-disabled-dependency-materialization' );

$lifecycle_artifact = array( 'schema' => 'test/website-artifact/v1' );
$prepare_receipt    = Static_Site_Importer_Validation_Runtime::prepare_artifact_dependencies(
	array(
		'artifact' => $lifecycle_artifact,
		'slug'     => 'persistent-worker',
	)
);
$prepared_invocation = (string) ( $prepare_receipt['fresh_runtime']['request_id'] ?? '' );
$prepared_metadata   = Static_Site_Importer_Theme_Generator::$last_args['source_metadata'] ?? array();
$assert( '' !== $prepared_invocation, 'prepare-receipt-carries-invocation' );
$assert( 'checkpoint-id' === ( $prepare_receipt['fresh_runtime']['lifecycle_checkpoint_id'] ?? '' ) && 'checkpoint-id' === ( $prepare_receipt['runtime_lifecycle_checkpoint'] ?? '' ), 'prepare-receipt-carries-checkpoint-in-fresh-runtime-and-compatibility-field' );
$assert( 'static-site-importer/current-runtime' === ( $prepared_metadata['validation_provider'] ?? '' ), 'prepare-and-resume-share-validation-provider-metadata' );

$resume_artifact_dir = $artifact_dir . '/persistent-worker-resume';
$resume_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'                     => $lifecycle_artifact,
		'artifact_dir'                 => $resume_artifact_dir,
		'slug'                         => 'persistent-worker',
		'runtime_lifecycle_phase'      => 'resume',
		'runtime_lifecycle_request_id' => $prepare_receipt['fresh_runtime']['request_id'],
		'runtime_lifecycle_checkpoint' => $prepare_receipt['runtime_lifecycle_checkpoint'],
	)
);
$assert( ! is_wp_error( $resume_result ), 'distinct-invocation-resume-proceeds-in-one-process' );
$assert( $prepared_invocation !== ( Static_Site_Importer_Theme_Generator::$last_args['runtime_lifecycle_invocation_id'] ?? '' ), 'separate-runtime-calls-receive-distinct-invocations' );
$assert( $prepared_metadata === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata'] ?? array() ), 'prepare-and-resume-bind-identical-source-metadata' );

$same_invocation_artifact_dir = $artifact_dir . '/same-invocation-resume';
$same_invocation_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'                     => $lifecycle_artifact,
		'artifact_dir'                 => $same_invocation_artifact_dir,
		'slug'                         => 'same-invocation',
		'runtime_lifecycle_phase'      => 'resume',
		'runtime_lifecycle_request_id' => $prepare_receipt['fresh_runtime']['request_id'],
	),
	$prepared_invocation
);
$assert( is_wp_error( $same_invocation_result ) && 'static_site_importer_fresh_runtime_required' === $same_invocation_result->get_error_code(), 'same-invocation-resume-rejected' );

$lifecycle_artifact_path = $artifact_dir . '/lifecycle-artifact.json';
$lifecycle_artifact      = array( 'schema' => 'test/website-artifact/v1', 'files' => array( array( 'path' => 'website/index.html', 'content' => str_repeat( 'x', 8192 ) ) ) );
file_put_contents( $lifecycle_artifact_path, json_encode( $lifecycle_artifact ) );
$file_digest = Static_Site_Importer_Validation_Runtime::lifecycle_artifact_digest_from_file( $lifecycle_artifact_path );
$receipt     = array(
	'artifact_sha256' => hash( 'sha256', wp_json_encode( $lifecycle_artifact ) ),
	'artifact_digest' => $file_digest,
);
$wp_json_encode_calls = 0;
$assert( Static_Site_Importer_Validation_Runtime::lifecycle_receipt_matches_artifact( $receipt, $lifecycle_artifact_path, $lifecycle_artifact ) && 0 === $wp_json_encode_calls, 'versioned-cli-receipt-uses-streaming-file-digest-without-reencoding-artifact' );

$mismatched_receipt                                = $receipt;
$mismatched_receipt['artifact_digest']['sha256'] = str_repeat( '0', 64 );
$wp_json_encode_calls                              = 0;
$assert( ! Static_Site_Importer_Validation_Runtime::lifecycle_receipt_matches_artifact( $mismatched_receipt, $lifecycle_artifact_path, $lifecycle_artifact ) && 0 === $wp_json_encode_calls, 'mismatched-versioned-digest-is-rejected-without-legacy-fallback' );

$legacy_receipt       = array( 'artifact_sha256' => hash( 'sha256', wp_json_encode( $lifecycle_artifact ) ) );
$wp_json_encode_calls = 0;
$assert( Static_Site_Importer_Validation_Runtime::lifecycle_receipt_matches_artifact( $legacy_receipt, $lifecycle_artifact_path, $lifecycle_artifact ) && 1 === $wp_json_encode_calls, 'legacy-receipt-retains-canonical-artifact-sha256-semantics' );

$unknown_digest_receipt = $receipt;
$unknown_digest_receipt['artifact_digest']['schema'] = 'static-site-importer/lifecycle-artifact-digest/v2';
$wp_json_encode_calls = 0;
$assert( ! Static_Site_Importer_Validation_Runtime::lifecycle_receipt_matches_artifact( $unknown_digest_receipt, $lifecycle_artifact_path, $lifecycle_artifact ) && 0 === $wp_json_encode_calls, 'unknown-versioned-digest-is-rejected-without-changing-its-meaning' );

unlink( $report_path );
unlink( $lifecycle_artifact_path );
rmdir( $default_artifact_dir );
rmdir( $override_artifact_dir );
rmdir( $resume_artifact_dir );
rmdir( $same_invocation_artifact_dir );
rmdir( $artifact_dir );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: validation runtime diagnostics smoke passed (' . $assertions . " assertions)\n";
