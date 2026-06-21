<?php
/**
 * Smoke coverage for the SSI Codebox validation product contract.
 *
 * Run from the repository root:
 * php tests/smoke-codebox-validation-contract.php
 *
 * @package StaticSiteImporter
 */

namespace {
	$GLOBALS['static_site_importer_test_filters'] = array();

	function add_filter( string $hook_name, callable $callback ): void {
		$GLOBALS['static_site_importer_test_filters'][ $hook_name ][] = $callback;
	}

	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['static_site_importer_test_filters'][ $hook_name ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function sanitize_title( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );

		return trim( (string) $value, '-' );
	}

	function sanitize_text_field( string $value ): string {
		return trim( wp_strip_all_tags( $value ) );
	}

	function sanitize_key( string $value ): string {
		$value = strtolower( $value );

		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?: '';
	}

	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
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

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-codebox-validation.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$blocked = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'slug'     => 'Fixture Import',
		)
	);

	$assert( ! is_wp_error( $blocked ), 'blocked-result-is-structured' );
	$assert( false === ( $blocked['success'] ?? true ), 'blocked-result-is-unsuccessful' );
	$assert( 'blocked' === ( $blocked['status'] ?? '' ), 'blocked-status' );
	$assert( 'static-site-importer/codebox-validation-result/v1' === ( $blocked['schema'] ?? '' ), 'result-schema' );
	$assert( 'static-site-importer/codebox-validation-artifacts/v1' === ( $blocked['artifacts']['schema'] ?? '' ), 'artifact-schema' );
	$assert( ! empty( $blocked['upstream_gaps'][0]['needed_shape'] ), 'upstream-gap-is-concrete' );

	add_filter(
		'static_site_importer_codebox_validation_result',
		static function ( $result, array $request ): array {
			unset( $result, $request );

			return array(
				'success'   => true,
				'summary'   => array( 'quality_pass' => true ),
				'runtime'   => array( 'provider' => 'test-codebox' ),
				'artifacts' => array(
					'generated_theme'         => array( 'artifact_ref' => 'hb-run://123/theme', 'path' => '/Users/local/theme' ),
					'theme_archive'           => array( 'url' => 'https://artifacts.example/theme.zip', 'sha256' => 'abc' ),
					'import_report'           => array( 'artifact_ref' => 'hb-run://123/import-report.json' ),
					'block_validation_result' => array( 'artifact_ref' => 'hb-run://123/block-validation.json' ),
					'browser_render_evidence' => array( 'artifact_ref' => 'hb-run://123/browser-render.json' ),
					'screenshots'             => array( array( 'artifact_ref' => 'hb-run://123/home.png' ) ),
					'diffs'                   => array( array( 'artifact_ref' => 'hb-run://123/home.diff.png' ) ),
				),
			);
		}
	);

	$provided = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'name'     => 'Fixture Import',
		)
	);

	$assert( true === ( $provided['success'] ?? false ), 'provider-success' );
	$assert( 'succeeded' === ( $provided['status'] ?? '' ), 'provider-status' );
	$assert( 'test-codebox' === ( $provided['runtime']['provider'] ?? '' ), 'runtime-provider' );
	$assert( 'hb-run://123/theme' === ( $provided['artifacts']['generated_theme']['artifact_ref'] ?? '' ), 'generated-theme-ref' );
	$assert( ! isset( $provided['artifacts']['generated_theme']['path'] ), 'local-path-removed-from-reviewer-artifact' );
	$assert( 'hb-run://123/import-report.json' === ( $provided['artifacts']['import_report']['artifact_ref'] ?? '' ), 'import-report-ref' );
	$assert( 'hb-run://123/block-validation.json' === ( $provided['artifacts']['block_validation_result']['artifact_ref'] ?? '' ), 'block-validation-ref' );
	$assert( 'hb-run://123/browser-render.json' === ( $provided['artifacts']['browser_render_evidence']['artifact_ref'] ?? '' ), 'browser-render-ref' );
	$assert( 1 === count( $provided['artifacts']['screenshots'] ?? array() ), 'screenshot-ref-count' );
	$assert( 1 === count( $provided['artifacts']['diffs'] ?? array() ), 'diff-ref-count' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: Codebox validation contract smoke passed (' . $assertions . " assertions)\n";
}
