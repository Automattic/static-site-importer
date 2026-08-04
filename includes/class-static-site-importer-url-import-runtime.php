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
if ( ! class_exists( 'Static_Site_Importer_Source_Normalizer' ) ) {
	require_once __DIR__ . '/class-static-site-importer-source-normalizer.php';
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
	 * Resolve a URL into a website artifact without importing it.
	 *
	 * @param array<string,mixed> $input Ability-style input.
	 * @return array<string,mixed>|WP_Error Runtime output containing artifact/source_metadata/provider.
	 */
	public static function website_artifact_from_url( array $input ) {
		$url = isset( $input['url'] ) ? Static_Site_Importer_URL_Fetcher::normalize_url( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'static_site_importer_missing_url', 'The url input is required.' );
		}

		$input['url'] = $url;
		$request      = self::provider_request( $url, $input );
		$runtime      = self::resolve_provider( $request );
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$artifact = isset( $runtime['artifact'] ) && is_array( $runtime['artifact'] ) ? $runtime['artifact'] : array();
		if ( empty( $artifact ) ) {
			return new WP_Error( 'static_site_importer_url_provider_missing_artifact', 'The URL import provider did not return a website artifact.' );
		}

		$runtime['artifact'] = $artifact;

		return $runtime;
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
			'provider'        => isset( $input['provider'] ) ? (string) $input['provider'] : '',
			'provider_args'   => isset( $input['provider_args'] ) && is_array( $input['provider_args'] ) ? $input['provider_args'] : array(),
			'work_dir'        => self::default_work_dir(),
			'source_metadata' => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);
	}

	/**
	 * Resolve the provider output.
	 *
	 * Providers return an array with an `artifact` key containing a website
	 * artifact, plus optional `source_metadata` and `provider` fields.
	 *
	 * @param array<string,mixed> $request Provider request envelope.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_provider( array $request ) {
		$provider_output = self::provider_output( $request );
		if ( is_wp_error( $provider_output ) || is_array( $provider_output ) ) {
			return $provider_output;
		}
		return self::fetch_public_url_provider( $request );
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
	 * Built-in generic public URL provider.
	 *
	 * @param array<string,mixed> $request Provider request envelope.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function fetch_public_url_provider( array $request ) {
		$provider_args = isset( $request['provider_args'] ) && is_array( $request['provider_args'] ) ? $request['provider_args'] : array();
		if ( ! empty( $provider_args['collect_site'] ) ) {
			$provider_args['require_complete_collection'] = true;
			return Static_Site_Importer_URL_Site_Collector::collect( (string) $request['url'], $provider_args );
		}

		$fetch = Static_Site_Importer_URL_Fetcher::fetch_to_work_dir(
			(string) $request['url'],
			(string) $request['work_dir'],
			$provider_args
		);
		if ( is_wp_error( $fetch ) ) {
			return $fetch;
		}

		$html = file_get_contents( $fetch['html_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the importer-owned fetched HTML artifact.
		if ( false === $html ) {
			return new WP_Error( 'static_site_importer_url_artifact_read_failed', 'Failed to read fetched URL HTML.' );
		}
		$normalized                    = Static_Site_Importer_Source_Normalizer::normalize_html( $html, (string) $request['url'], $provider_args );
		$html                          = $normalized['html'];
		$metadata                      = $fetch['metadata'];
		$metadata['source_exclusions'] = $normalized['exclusions'];
		$metadata['diagnostics']       = array_merge( is_array( $metadata['diagnostics'] ?? null ) ? $metadata['diagnostics'] : array(), $normalized['diagnostics'] );

		return array(
			'provider'        => 'public-url-fetcher',
			'artifact'        => array(
				'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
				'files'  => array(
					array(
						'path'    => 'website/index.html',
						'content' => $html,
					),
				),
			),
			'source_metadata' => $metadata,
		);
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
		$identity = bin2hex( random_bytes( 32 ) );
		$contract = self::batch_contract( $url, $input );
		$registry = self::run_registry_path( $identity );
		$record   = array(
			'schema'    => 'static-site-importer/url-import-run/v1',
			'identity'  => $identity,
			'contract'  => $contract,
			'workspace' => self::run_workspace( $identity ),
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
		if ( ! is_array( $record ) || 'static-site-importer/url-import-run/v1' !== ( $record['schema'] ?? '' ) || ( $record['identity'] ?? '' ) !== $identity || self::run_workspace( $identity ) !== ( $record['workspace'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_url_import_run_not_found', 'The URL import run was not found.' );
		}
		if ( self::canonical( self::batch_contract( $url, $input ) ) !== self::canonical( $record['contract'] ?? array() ) ) {
			return new WP_Error( 'static_site_importer_url_import_run_mismatch', 'The URL import identity does not match this URL, import options, or user.' );
		}

		return self::run_batch_import( $record, $input );
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|WP_Error */
	private static function run_batch_import( array $record, array $input ) {
		$request                  = self::provider_request( (string) $record['contract']['url'], $input );
		$request['work_dir']      = (string) $record['workspace'];
		$request['provider_args'] = self::batch_args();
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
				'url'     => $url,
				'options' => $options,
				'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);
	}

	/** @return array<string,mixed> */
	private static function batch_args(): array {
		$args = array(
			'collect_site'                         => true,
			'batch_pages'                          => 20,
			'max_effective_batches_per_invocation' => 1,
			'max_invocation_seconds'               => 20,
			'max_pages'                            => 20,
			'max_assets'                           => 2000,
			'max_total_bytes'                      => 268435456,
			'max_bytes'                            => 5242880,
			'timeout'                              => 10,
			'request_delay_ms'                     => 100,
			'include_scripts'                      => false,
		);
		/** @param array<string,mixed> $args Server-owned URL batch policy. */
		return apply_filters( 'static_site_importer_url_batch_import_args', $args );
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
