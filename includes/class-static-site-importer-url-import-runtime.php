<?php
/**
 * URL import runtime/provider boundary.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-transformer-adapter.php';
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
		$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'static_site_importer_missing_url', 'The url input is required.' );
		}

		$request = self::provider_request( $url, $input );
		$runtime = self::resolve_provider( $request );
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$artifact = isset( $runtime['artifact'] ) && is_array( $runtime['artifact'] ) ? $runtime['artifact'] : array();
		if ( empty( $artifact ) ) {
			return new WP_Error( 'static_site_importer_url_provider_missing_artifact', 'The URL import provider did not return a website artifact.' );
		}

		$args = self::import_args( $input, $runtime );
		return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
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
			'work_dir'        => isset( $input['work_dir'] ) ? (string) $input['work_dir'] : self::default_work_dir(),
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
		$provider_output = apply_filters( 'static_site_importer_url_import_provider', null, $request );
		if ( is_wp_error( $provider_output ) ) {
			return $provider_output;
		}
		if ( is_array( $provider_output ) ) {
			return $provider_output;
		}

		return self::fetch_public_url_provider( $request );
	}

	/**
	 * Built-in generic public URL provider.
	 *
	 * @param array<string,mixed> $request Provider request envelope.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function fetch_public_url_provider( array $request ) {
		$fetch = Static_Site_Importer_URL_Fetcher::fetch_to_work_dir(
			(string) $request['url'],
			(string) $request['work_dir'],
			isset( $request['provider_args'] ) && is_array( $request['provider_args'] ) ? $request['provider_args'] : array()
		);
		if ( is_wp_error( $fetch ) ) {
			return $fetch;
		}

		$html = file_get_contents( $fetch['html_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the importer-owned fetched HTML artifact.
		if ( false === $html ) {
			return new WP_Error( 'static_site_importer_url_artifact_read_failed', 'Failed to read fetched URL HTML.' );
		}

		return array(
			'provider'        => 'public-url-fetcher',
			'artifact'        => array(
				'schema' => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
				'files'  => array(
					array(
						'path'    => 'website/index.html',
						'content' => $html,
					),
				),
			),
			'source_metadata' => $fetch['metadata'],
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

		$args = array(
			'slug'                         => isset( $input['slug'] ) ? (string) $input['slug'] : '',
			'name'                         => isset( $input['name'] ) ? (string) $input['name'] : '',
			'activate'                     => ! empty( $input['activate'] ),
			'overwrite'                    => ! empty( $input['overwrite'] ),
			'fail_on_quality'              => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce'    => ! empty( $input['allow_missing_woocommerce'] ),
			'materialize_dependencies'     => array_key_exists( 'materialize_dependencies', $input ) ? (bool) $input['materialize_dependencies'] : true,
			'report'                       => isset( $input['report'] ) ? (string) $input['report'] : '',
			'asset_materialization_policy' => isset( $input['asset_materialization_policy'] ) ? (string) $input['asset_materialization_policy'] : '',
			'asset_map'                    => isset( $input['asset_map'] ) && is_array( $input['asset_map'] ) ? $input['asset_map'] : array(),
			'compiler_options'             => isset( $input['compiler_options'] ) && is_array( $input['compiler_options'] ) ? $input['compiler_options'] : array(),
			'source_metadata'              => $source_metadata,
			'validation_artifacts'         => isset( $input['validation_artifacts'] ) && is_array( $input['validation_artifacts'] ) ? $input['validation_artifacts'] : array(),
		);

		return $args;
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
