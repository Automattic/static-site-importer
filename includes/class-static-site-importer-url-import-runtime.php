<?php
/**
 * URL import runtime/provider boundary.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_URL_Fetcher' ) ) {
	require_once __DIR__ . '/class-static-site-importer-url-fetcher.php';
}
if ( ! class_exists( 'Static_Site_Importer_URL_Site_Collector' ) ) {
	require_once __DIR__ . '/class-static-site-importer-url-site-collector.php';
}
if ( ! class_exists( 'Static_Site_Importer_URL_Batch_Import' ) ) {
	require_once __DIR__ . '/class-static-site-importer-url-batch-import.php';
}

if ( ! class_exists( 'Static_Site_Importer_Website_Artifact_Import_Input' ) ) {
	require_once __DIR__ . '/class-static-site-importer-website-artifact-import-input.php';
}

/**
 * Imports a source URL through a provider that returns a website artifact.
 */
class Static_Site_Importer_URL_Import_Runtime {

	/**
	 * Acquire a URL through the opaque resumable batch run for canonical imports.
	 *
	 * Unlike import_url(), this never accepts a provider-built shortcut: every
	 * canonical request has the same bounded, identity-bound continuation path.
	 *
	 * @param array<string,mixed> $input Canonical ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_operation( array $input ) {
		$url = isset( $input['url'] ) ? Static_Site_Importer_URL_Fetcher::normalize_url( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'static_site_importer_missing_url', 'The url input is required.' );
		}

		$input['url'] = $url;
		if ( ! empty( $input['import_id'] ) ) {
			return self::continue_batch_import( $url, $input );
		}

		return self::start_batch_import( $url, $input );
	}

	/**
	 * Import a URL and return the normal Static Site Importer result/report envelope.
	 *
	 * @param array<string,mixed> $input Ability-style input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_url( array $input ) {
		$url = isset( $input['url'] ) ? Static_Site_Importer_URL_Fetcher::normalize_url( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'static_site_importer_missing_url', 'The url input is required.' );
		}
		$input['url'] = $url;
		if ( ! empty( $input['import_id'] ) ) {
			return self::continue_batch_import( $url, $input );
		}
		$request         = self::provider_request( $url, $input );
		$provider_output = self::provider_output( $request );
		if ( is_wp_error( $provider_output ) ) {
			return $provider_output;
		}
		if ( is_array( $provider_output ) ) {
			$runtime = $provider_output;
		} else {
			return self::start_batch_import( $url, $input );
		}
		if ( empty( $runtime['artifact'] ) || ! is_array( $runtime['artifact'] ) ) {
			return new WP_Error( 'static_site_importer_url_provider_missing_artifact', 'The URL import provider did not return a website artifact.' );
		}

		$args = self::import_args( $input, $runtime );
		return Static_Site_Importer_Theme_Generator::import_website_artifact( $runtime['artifact'], $args );
	}

	/**
	 * Build a provider request envelope.
	 *
	 * @param string              $url   Source URL.
	 * @param array<string,mixed> $input Ability-style input.
	 * @return array<string,mixed>
	 */
	private static function provider_request( string $url, array $input ): array {
		return array(
			'url'             => $url,
			'provider'        => '',
			'provider_args'   => array(),
			'work_dir'        => self::default_work_dir(),
			'source_metadata' => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);
	}

	/** @return null|array<string,mixed>|WP_Error */
	private static function provider_output( array $request ) {
		/**
		 * Filters URL import provider output before the built-in public URL fetcher runs.
		 *
		 * Return WP_Error to fail the import, or an array with an `artifact` key to import
		 * a provider-built website artifact. Hosted/private runtimes should hook here
		 * rather than product code spawning local processes.
		 *
		 * @param null|array<string,mixed>|WP_Error $provider_output Provider output.
		 * @param array<string,mixed>               $request         Provider request.
		 */
		return apply_filters( 'static_site_importer_url_import_provider', null, $request );
	}

	/**
	 * Build import args for the normal website artifact importer.
	 *
	 * @param array<string,mixed> $input   Ability-style input.
	 * @param array<string,mixed> $runtime Runtime/provider output.
	 * @return array<string,mixed>
	 */
	private static function import_args( array $input, array $runtime ): array {
		$source_metadata = isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array();
		if ( isset( $runtime['source_metadata'] ) && is_array( $runtime['source_metadata'] ) ) {
			$source_metadata = array_merge( $source_metadata, $runtime['source_metadata'] );
		}
		$source_metadata['url_import_provider'] = isset( $runtime['provider'] ) ? (string) $runtime['provider'] : 'public-url-fetcher';

		$input['source_metadata'] = $source_metadata;

		return Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
	}

	/** @return array<string,mixed> */
	public static function batch_import_args( array $input, array $runtime ): array {
		return self::import_args( $input, $runtime );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function start_batch_import( string $url, array $input ) {
		$identity      = bin2hex( random_bytes( 32 ) );
		$contract      = self::batch_contract( $url, $input );
		$registry      = self::run_registry_path( $identity );
		$policy        = self::url_import_policy();
		$provider_args = apply_filters( 'static_site_importer_url_batch_import_args', self::batch_args( $policy ) );
		if ( ! is_array( $provider_args ) ) {
			$provider_args = self::batch_args( $policy );
		}
		$record = array(
			'schema'        => 'static-site-importer/url-import-run/v1',
			'identity'      => $identity,
			'contract'      => $contract,
			'workspace'     => self::run_workspace( $identity ),
			'policy'        => $policy,
			'provider_args' => $provider_args,
		);
		if ( ! wp_mkdir_p( dirname( $registry ) ) || false === file_put_contents( $registry, wp_json_encode( $record ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes an importer-owned opaque run registry.
			return new WP_Error( 'static_site_importer_url_import_run_unavailable', 'The URL import run workspace is unavailable.' );
		}

		return self::run_batch_import( $record, $input );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function continue_batch_import( string $url, array $input ) {
		$identity = (string) $input['import_id'];
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
			return new WP_Error( 'static_site_importer_invalid_url_import_id', 'The import_id is invalid.' );
		}
		$registry = self::run_registry_path( $identity );
		$raw      = is_file( $registry ) ? file_get_contents( $registry ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned opaque run registry.
		$record   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $record ) || 'static-site-importer/url-import-run/v1' !== ( $record['schema'] ?? '' ) || ( $record['identity'] ?? '' ) !== $identity || self::run_workspace( $identity ) !== ( $record['workspace'] ?? '' ) || ! self::is_url_import_policy( $record['policy'] ?? null ) || ! is_array( $record['provider_args'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_url_import_run_not_found', 'The URL import run was not found.' );
		}
		if ( self::canonical( self::batch_contract( $url, $input ) ) !== self::canonical( $record['contract'] ?? array() ) ) {
			return new WP_Error( 'static_site_importer_url_import_run_mismatch', 'The URL import identity does not match this URL, import options, or user.' );
		}

		return self::run_batch_import( $record, $input );
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|WP_Error */
	private static function run_batch_import( array $record, array $input ) {
		$workspace = (string) $record['workspace'];
		if ( ! wp_mkdir_p( $workspace ) ) {
			return new WP_Error( 'static_site_importer_url_import_run_unavailable', 'The URL import run workspace is unavailable.' );
		}
		$lock = fopen( trailingslashit( $workspace ) . 'continuation.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Holds an importer-owned per-run process lease.
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Serializes mutations for one opaque import run.
			if ( is_resource( $lock ) ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases an importer-owned process lease handle.
			}
			return new WP_Error( 'static_site_importer_url_import_in_progress', 'Another continuation is already processing this URL import.', array( 'status' => 409 ) );
		}

		try {
			return self::run_batch_import_locked( $record, $input );
		} finally {
			flock( $lock, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Releases an importer-owned per-run process lease.
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases an importer-owned process lease handle.
		}
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|WP_Error */
	private static function run_batch_import_locked( array $record, array $input ) {
		$request                  = self::provider_request( (string) $record['contract']['url'], $input );
		$request['work_dir']      = (string) $record['workspace'];
		$request['provider_args'] = $record['provider_args'];
		$fetcher                  = apply_filters( 'static_site_importer_url_batch_import_fetcher', null, $request, $input );
		$importer                 = apply_filters( 'static_site_importer_url_batch_importer', null, $request, $input );
		$result                   = Static_Site_Importer_URL_Batch_Import::import( $request, $input, is_callable( $fetcher ) ? $fetcher : null, is_callable( $importer ) ? $importer : null );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) ) {
				unset( $data['expired_manifest'], $data['archived_manifest'], $data['cleanup'] );
			}
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}
		$result['import_id'] = (string) $record['identity'];
		if ( isset( $result['url_batch_run'] ) && is_array( $result['url_batch_run'] ) ) {
			unset( $result['url_batch_run']['run_manifest'] );
			unset( $result['url_batch_run']['cleanup'], $result['url_batch_run']['legacy_cache_cleanup'] );
			$result['url_batch_run']['import_id'] = (string) $record['identity'];
		}

		return $result;
	}

	/** @return array<string,mixed> */
	private static function batch_contract( string $url, array $input ): array {
		$options = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
		return self::canonical(
			array(
				'url'       => $url,
				'operation' => (string) ( $input['operation'] ?? 'apply' ),
				'intent'    => (string) ( $input['source']['type'] ?? 'url' ),
				'options'   => $options,
				'user_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);
	}

	/** @return array<string,mixed> */
	public static function url_import_policy(): array {
		$defaults = array(
			'pages_per_invocation'       => 5,
			'batches_per_invocation'     => 1,
			'invocation_seconds'         => 20.0,
			'total_pages'                => 1000,
			'total_assets'               => 2000,
			'total_bytes'                => 268435456,
			'resource_bytes'             => 5242880,
			'fetch_timeout_seconds'      => 10.0,
			'request_delay_milliseconds' => 100,
			'include_scripts'            => false,
		);
		$filtered = apply_filters( 'static_site_importer_url_import_policy', $defaults );
		return self::normalize_url_import_policy( is_array( $filtered ) ? $filtered : array() );
	}

	/** @param array<string,mixed> $policy @return array<string,mixed> */
	private static function batch_args( array $policy ): array {
		return array(
			'collect_site'                         => true,
			'batch_pages'                          => $policy['pages_per_invocation'],
			'max_effective_batches_per_invocation' => $policy['batches_per_invocation'],
			'max_invocation_seconds'               => $policy['invocation_seconds'],
			'max_pages'                            => $policy['total_pages'],
			'max_assets'                           => $policy['total_assets'],
			'max_total_bytes'                      => $policy['total_bytes'],
			'max_bytes'                            => $policy['resource_bytes'],
			'timeout'                              => $policy['fetch_timeout_seconds'],
			'request_delay_ms'                     => $policy['request_delay_milliseconds'],
			'include_scripts'                      => $policy['include_scripts'],
		);
	}

	/** @param array<string,mixed> $policy @return array<string,mixed> */
	private static function normalize_url_import_policy( array $policy ): array {
		$defaults = array(
			'pages_per_invocation'       => 5,
			'batches_per_invocation'     => 1,
			'invocation_seconds'         => 20.0,
			'total_pages'                => 1000,
			'total_assets'               => 2000,
			'total_bytes'                => 268435456,
			'resource_bytes'             => 5242880,
			'fetch_timeout_seconds'      => 10.0,
			'request_delay_milliseconds' => 100,
			'include_scripts'            => false,
		);
		$integers = array( 'pages_per_invocation', 'batches_per_invocation', 'total_pages', 'total_assets', 'total_bytes', 'resource_bytes', 'request_delay_milliseconds' );
		foreach ( $integers as $key ) {
			if ( isset( $policy[ $key ] ) && is_int( $policy[ $key ] ) && $policy[ $key ] >= ( 'request_delay_milliseconds' === $key ? 0 : 1 ) ) {
				$defaults[ $key ] = $policy[ $key ];
			}
		}
		foreach ( array( 'invocation_seconds', 'fetch_timeout_seconds' ) as $key ) {
			if ( isset( $policy[ $key ] ) && ( is_int( $policy[ $key ] ) || is_float( $policy[ $key ] ) ) && $policy[ $key ] > 0 ) {
				$defaults[ $key ] = (float) $policy[ $key ];
			}
		}
		if ( isset( $policy['include_scripts'] ) && is_bool( $policy['include_scripts'] ) ) {
			$defaults['include_scripts'] = $policy['include_scripts'];
		}
		return $defaults;
	}

	private static function is_url_import_policy( $policy ): bool {
		if ( ! is_array( $policy ) || array_keys( self::normalize_url_import_policy( $policy ) ) !== array_keys( $policy ) ) {
			return false;
		}
		foreach ( array( 'pages_per_invocation', 'batches_per_invocation', 'total_pages', 'total_assets', 'total_bytes', 'resource_bytes', 'request_delay_milliseconds' ) as $key ) {
			if ( ! is_int( $policy[ $key ] ) ) {
				return false;
			}
		}
		foreach ( array( 'invocation_seconds', 'fetch_timeout_seconds' ) as $key ) {
			if ( ! is_int( $policy[ $key ] ) && ! is_float( $policy[ $key ] ) ) {
				return false;
			}
		}
		// Numeric policy values intentionally compare by value after normalization.
		return is_bool( $policy['include_scripts'] ) && self::normalize_url_import_policy( $policy ) == $policy; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	private static function run_registry_path( string $identity ): string {
		return trailingslashit( self::url_import_root() ) . 'runs/' . $identity . '.json';
	}

	private static function run_workspace( string $identity ): string {
		return trailingslashit( self::url_import_root() ) . 'workspaces/' . $identity;
	}

	private static function url_import_root(): string {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : sys_get_temp_dir();
		return trailingslashit( $base_dir ) . 'static-site-importer/url-imports';
	}

	/** @param array<array-key,mixed> $value @return array<array-key,mixed> */
	private static function canonical( array $value ): array {
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$item = self::canonical( $item );
			}
		}
		unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/**
	 * Build the default work directory for the built-in URL provider.
	 *
	 * @return string
	 */
	private static function default_work_dir(): string {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : sys_get_temp_dir();

		return trailingslashit( $base_dir ) . 'static-site-importer/url-import-' . wp_generate_uuid4();
	}
}
