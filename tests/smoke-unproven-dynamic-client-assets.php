<?php
/**
 * Smoke coverage for unproven dynamic client asset references (issue #1815).
 *
 * A site whose client script builds asset URLs at runtime cannot be proven by
 * the canonical plan, which used to fail materialization with "WordPress site
 * plan cannot prove dynamic client asset references." The import boundary now
 * drops exactly those scripts before compilation, recompiles a proven plan,
 * and reports each unproven reference as a typed `unproven_dynamic` loss in
 * the client-script policy report the materialization receipt projects.
 *
 * Run from the repository root:
 * php tests/smoke-unproven-dynamic-client-assets.php
 *
 * @package StaticSiteImporter
 */

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}

	function add_action( string $hook, callable|string $callback ): void {}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}

	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	function wp_strip_all_tags( string $text ): string {
		return trim( (string) preg_replace( '/<[^>]*>/', '', $text ) );
	}

	function sanitize_text_field( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
	}

	function sanitize_title( string $text ): string {
		return trim( (string) preg_replace( '/[^a-z0-9-_]+/', '-', strtolower( $text ) ), '-' );
	}

	function sanitize_key( string $text ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text ) );
	}

	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}

	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-compilation-preparation.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-client-script-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-website-artifact-import-input.php';

	use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

	$assertions = 0;
	$failures   = array();
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$runtime_script = <<<'JS'
	(function () {
		var image = document.createElement('img');
		image.src = 'assets/built-at-runtime.png';
		document.body.appendChild(image);
	})();
	JS;
	$safe_script = 'window.runtimeSiteSafe = true;';

	$artifact = array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => 'index.html',
		'files'      => array(
			array(
				'path'      => 'index.html',
				'mime_type' => 'text/html',
				'content'   => '<html><head><title>Runtime Asset Site</title><script src="js/safe.js"></script><script src="js/runtime.js"></script></head><body><main><h1>Runtime</h1></main></body></html>',
			),
			array(
				'path'      => 'js/runtime.js',
				'mime_type' => 'application/javascript',
				'content'   => $runtime_script,
			),
			array(
				'path'      => 'js/safe.js',
				'mime_type' => 'application/javascript',
				'content'   => $safe_script,
			),
		),
	);

	$import_args = static function ( bool $require_proven ): array {
		return array(
			'slug'                                 => 'runtime-asset-site',
			'name'                                 => 'Runtime Asset Site',
			'client_script_policy'                 => 'isolated_preview',
			'client_script_isolated'               => true,
			'client_script_provenance'             => array( 'ref' => 'smoke:unproven-dynamic-client-assets' ),
			'require_proven_dynamic_client_assets' => $require_proven,
		);
	};

	$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $import_args( false ) );
	$assert( ! is_wp_error( $compiled ), 'loss-policy-compile-prepares', is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
	if ( is_wp_error( $compiled ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	$plan = $compiled['plan'];
	$assert( 'proven' === ( $plan['reference_semantics']['dynamic_client_assets']['status'] ?? '' ), 'loss-policy-recompiles-a-proven-plan', (string) ( $plan['reference_semantics']['dynamic_client_assets']['status'] ?? '' ) );

	$report    = $compiled['args']['client_script_policy_report'] ?? array();
	$loss_rows = array_values( array_filter( $report['dropped'] ?? array(), static fn( array $row ): bool => 'unproven_dynamic' === ( $row['class'] ?? '' ) ) );
	$assert( 1 === count( $loss_rows ), 'receipt-reports-one-typed-loss-row', wp_json_encode( $loss_rows ) ?: '' );
	if ( 1 === count( $loss_rows ) ) {
		$row = $loss_rows[0];
		$assert( 'js/runtime.js' === ( $row['path'] ?? null ) && 'asset' === ( $row['type'] ?? null ) && 'index.html' === ( $row['source_document'] ?? null ) && 'js/runtime.js' === ( $row['src'] ?? null ), 'loss-row-names-the-unproven-reference', wp_json_encode( $row ) ?: '' );
		$assert( hash( 'sha256', $runtime_script ) === ( $row['sha256'] ?? '' ), 'loss-row-hashes-the-dropped-script-bytes' );
	}

	$filtered_files = array();
	$filtered_html  = '';
	foreach ( $compiled['artifact']['files'] as $file ) {
		$filtered_files[] = (string) ( $file['path'] ?? '' );
		if ( 'index.html' === (string) ( $file['path'] ?? '' ) ) {
			$filtered_html = (string) ( $file['content'] ?? '' );
		}
	}
	$assert( ! in_array( 'js/runtime.js', $filtered_files, true ), 'unproven-script-file-is-removed-from-the-artifact' );
	$assert( in_array( 'js/safe.js', $filtered_files, true ), 'proven-script-file-is-retained' );
	$assert( ! str_contains( $filtered_html, 'runtime.js' ) && str_contains( $filtered_html, 'js/safe.js' ), 'unproven-script-tag-is-stripped-while-proven-tags-survive', $filtered_html );

	try {
		( new WordPressSitePlanResolver() )->resolve(
			$plan,
			array(
				'theme_uri'                            => 'https://smoke.example.test/wp-content/themes/runtime-asset-site',
				'require_proven_dynamic_client_assets' => true,
				'runtime_capabilities'                 => array( 'asset_materialization' ),
			)
		);
		$assert( true, 'loss-applied-plan-passes-strict-resolution' );
	} catch ( InvalidArgumentException $error ) {
		$assert( false, 'loss-applied-plan-passes-strict-resolution', $error->getMessage() );
	}

	// Fail-closed is the default: normalized import input requires proven dynamic client assets.
	$defaults = Static_Site_Importer_Website_Artifact_Import_Input::normalize( array( 'artifact' => $artifact ) );
	$assert( ! is_wp_error( $defaults ) && true === ( $defaults['require_proven_dynamic_client_assets'] ?? null ), 'default-import-input-requires-proven-dynamic-client-assets', is_wp_error( $defaults ) ? $defaults->get_error_message() : wp_json_encode( $defaults['require_proven_dynamic_client_assets'] ?? null ) );

	$strict = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $import_args( true ) );
	$assert( ! is_wp_error( $strict ), 'strict-policy-compile-prepares', is_wp_error( $strict ) ? $strict->get_error_message() : '' );
	if ( ! is_wp_error( $strict ) ) {
		$assert( 'not_proven' === ( $strict['plan']['reference_semantics']['dynamic_client_assets']['status'] ?? '' ), 'strict-policy-keeps-the-unproven-plan-for-rejection' );
		$strict_rows = array_values( array_filter( $strict['args']['client_script_policy_report']['dropped'] ?? array(), static fn( array $row ): bool => 'unproven_dynamic' === ( $row['class'] ?? '' ) ) );
		$assert( array() === $strict_rows, 'strict-policy-reports-no-loss-rows' );
		$strict_files = array();
		foreach ( $strict['artifact']['files'] as $file ) {
			$strict_files[] = (string) ( $file['path'] ?? '' );
		}
		$assert( in_array( 'js/runtime.js', $strict_files, true ), 'strict-policy-keeps-the-unproven-script-in-the-artifact' );
	}

	if ( $failures ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo sprintf( "Unproven dynamic client assets smoke passed (%d assertions).\n", $assertions );
}
