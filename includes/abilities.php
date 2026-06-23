<?php
/**
 * WordPress Abilities API integration.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'STATIC_SITE_IMPORTER_ABILITY_CATEGORY' ) ) {
	define( 'STATIC_SITE_IMPORTER_ABILITY_CATEGORY', 'static-site-importer' );
}

if ( ! function_exists( 'static_site_importer_register_ability_category' ) ) {
	/**
	 * Register the Static Site Importer ability category.
	 *
	 * @return void
	 */
	function static_site_importer_register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
			array(
				'label'       => __( 'Static Site Importer', 'static-site-importer' ),
				'description' => __( 'Website artifact materialization capabilities.', 'static-site-importer' ),
			)
		);
	}
}

if ( ! function_exists( 'static_site_importer_register_abilities' ) ) {
	/**
	 * Register Static Site Importer abilities when the Abilities API is present.
	 *
	 * @return void
	 */
	function static_site_importer_register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'static-site-importer/export-theme',
			array(
				'label'               => __( 'Export Website Artifact', 'static-site-importer' ),
				'description'         => __( 'Export an imported or active block theme and page content as a Blocks Engine website artifact.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'theme_slug'      => array( 'type' => 'string' ),
						'root'            => array( 'type' => 'string' ),
						'entrypoint'      => array( 'type' => 'string' ),
						'include_pages'   => array(
							'oneOf' => array(
								array( 'type' => 'boolean' ),
								array(
									'type'  => 'array',
									'items' => array( 'type' => array( 'integer', 'string' ) ),
								),
							),
						),
						'source_metadata' => array( 'type' => 'object' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_export_theme',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'static-site-importer/import-website-artifact',
			array(
				'label'               => __( 'Import Website Artifact', 'static-site-importer' ),
				'description'         => __( 'Compile a website artifact bundle through the Blocks Engine transformer and import it as a WordPress block theme.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'artifact'                     => array( 'type' => 'object' ),
						'slug'                         => array( 'type' => 'string' ),
						'name'                         => array( 'type' => 'string' ),
						'activate'                     => array( 'type' => 'boolean' ),
						'overwrite'                    => array( 'type' => 'boolean' ),
						'fail_on_quality'              => array( 'type' => 'boolean' ),
						'allow_missing_woocommerce'    => array( 'type' => 'boolean' ),
						'report'                       => array( 'type' => 'string' ),
						'asset_materialization_policy' => array(
							'type' => 'string',
							'enum' => array( 'copy_to_theme', 'use_map' ),
						),
						'asset_map'                    => array( 'type' => 'object' ),
						'compiler_options'             => array( 'type' => 'object' ),
						'source_metadata'              => array( 'type' => 'object' ),
						'validation_artifacts'         => array( 'type' => 'object' ),
					),
					'required'   => array( 'artifact' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_import_website_artifact',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'static-site-importer/import-url',
			array(
				'label'               => __( 'Import URL', 'static-site-importer' ),
				'description'         => __( 'Import a source URL through a URL extraction provider and return a Static Site Importer report.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'                          => array( 'type' => 'string' ),
						'provider'                     => array( 'type' => 'string' ),
						'provider_args'                => array( 'type' => 'object' ),
						'work_dir'                     => array( 'type' => 'string' ),
						'slug'                         => array( 'type' => 'string' ),
						'name'                         => array( 'type' => 'string' ),
						'activate'                     => array( 'type' => 'boolean' ),
						'overwrite'                    => array( 'type' => 'boolean' ),
						'fail_on_quality'              => array( 'type' => 'boolean' ),
						'allow_missing_woocommerce'    => array( 'type' => 'boolean' ),
						'report'                       => array( 'type' => 'string' ),
						'asset_materialization_policy' => array(
							'type' => 'string',
							'enum' => array( 'copy_to_theme', 'use_map' ),
						),
						'asset_map'                    => array( 'type' => 'object' ),
						'compiler_options'             => array( 'type' => 'object' ),
						'source_metadata'              => array( 'type' => 'object' ),
						'validation_artifacts'         => array( 'type' => 'object' ),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_import_url',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'static-site-importer/validate-in-codebox',
			array(
				'label'               => __( 'Validate Import in Codebox', 'static-site-importer' ),
				'description'         => __( 'Validate a website artifact or generated import output in a disposable WP Codebox runtime and return durable artifact metadata.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'artifact'                     => array( 'type' => 'object' ),
						'generated_theme_ref'          => array( 'type' => 'object' ),
						'theme_archive_ref'            => array( 'type' => 'object' ),
						'slug'                         => array( 'type' => 'string' ),
						'name'                         => array( 'type' => 'string' ),
						'activate'                     => array( 'type' => 'boolean' ),
						'overwrite'                    => array( 'type' => 'boolean' ),
						'fail_on_quality'              => array( 'type' => 'boolean' ),
						'allow_missing_woocommerce'    => array( 'type' => 'boolean' ),
						'asset_materialization_policy' => array(
							'type' => 'string',
							'enum' => array( 'copy_to_theme', 'use_map' ),
						),
						'compiler_options'             => array( 'type' => 'object' ),
						'source_metadata'              => array( 'type' => 'object' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_validate_in_codebox',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_permission_callback' ) ) {
	/**
	 * Permission callback for site-mutating import abilities.
	 *
	 * @return bool
	 */
	function static_site_importer_ability_permission_callback(): bool {
		if ( defined( 'WP_CLI' ) ) {
			return true;
		}

		return ! function_exists( 'current_user_can' ) || current_user_can( 'switch_themes' );
	}
}

if ( ! function_exists( 'static_site_importer_ability_validate_in_codebox' ) ) {
	/**
	 * Ability callback for Codebox-backed validation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_validate_in_codebox( array $input ): array {
		$result = Static_Site_Importer_Codebox_Validation::validate( $input );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array_merge(
			array( 'success' => ! empty( $result['success'] ) ),
			$result
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_url' ) ) {
	/**
	 * Ability callback for URL imports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_import_url( array $input ): array {
		$result = Static_Site_Importer_URL_Import_Runtime::import_url( $input );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array(
			'success'               => true,
			'result'                => $result,
			'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_export_theme' ) ) {
	/**
	 * Ability callback for website artifact exports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_export_theme( array $input ): array {
		$args = array(
			'theme_slug'      => isset( $input['theme_slug'] ) ? (string) $input['theme_slug'] : '',
			'root'            => isset( $input['root'] ) ? (string) $input['root'] : '',
			'entrypoint'      => isset( $input['entrypoint'] ) ? (string) $input['entrypoint'] : 'website/index.html',
			'include_pages'   => $input['include_pages'] ?? true,
			'source_metadata' => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);

		$result = Static_Site_Importer_Theme_Exporter::export_theme( $args );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array_merge(
			array( 'success' => true ),
			$result
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_website_artifact' ) ) {
	/**
	 * Ability callback for website artifact imports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_import_website_artifact( array $input ): array {
		$artifact = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		if ( empty( $artifact ) ) {
			return static_site_importer_ability_error( 'static_site_importer_missing_website_artifact', 'The artifact input is required.' );
		}

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
			'source_metadata'              => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
			'validation_artifacts'         => isset( $input['validation_artifacts'] ) && is_array( $input['validation_artifacts'] ) ? $input['validation_artifacts'] : array(),
		);

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_error' ) ) {
	/**
	 * Build a structured ability error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_error( string $code, string $message, $data = null ): array {
		return array(
			'success'               => false,
			'error'                 => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
			'import_report_summary' => static_site_importer_failure_report_summary( $code, $message ),
		);
	}
}

if ( ! function_exists( 'static_site_importer_failure_report_summary' ) ) {
	/**
	 * Build a minimal report summary for failures that happen before a report file exists.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	function static_site_importer_failure_report_summary( string $code, string $message ): array {
		return array(
			'status'                => 'failed',
			'quality_pass'          => false,
			'fail_import'           => true,
			'failure_reasons'       => array( $code ),
			'core_html_block_count' => 0,
			'freeform_block_count'  => 0,
			'invalid_block_count'   => 0,
			'diagnostic_count'      => 1,
			'error'                 => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}

if ( doing_action( 'wp_abilities_api_categories_init' ) || did_action( 'wp_abilities_api_categories_init' ) ) {
	static_site_importer_register_ability_category();
} elseif ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
	add_action( 'wp_abilities_api_categories_init', 'static_site_importer_register_ability_category' );
}

if ( doing_action( 'wp_abilities_api_init' ) || did_action( 'wp_abilities_api_init' ) ) {
	static_site_importer_register_abilities();
} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
	add_action( 'wp_abilities_api_init', 'static_site_importer_register_abilities' );
}
