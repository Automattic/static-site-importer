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
				'description' => __( 'Static HTML site import capabilities.', 'static-site-importer' ),
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
			'static-site-importer/import-theme',
			array(
				'label'               => __( 'Import Static Site Theme', 'static-site-importer' ),
				'description'         => __( 'Import a static HTML site entry point as a WordPress block theme.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'html_path'                 => array( 'type' => 'string' ),
						'slug'                      => array( 'type' => 'string' ),
						'name'                      => array( 'type' => 'string' ),
						'activate'                  => array( 'type' => 'boolean' ),
						'overwrite'                 => array( 'type' => 'boolean' ),
						'keep_source'               => array( 'type' => 'boolean' ),
						'fail_on_quality'           => array( 'type' => 'boolean' ),
						'max_fallbacks'             => array( 'type' => 'integer' ),
						'allow_missing_woocommerce' => array( 'type' => 'boolean' ),
						'report'                    => array( 'type' => 'string' ),
						'asset_map'                 => array( 'type' => 'object' ),
						'source_metadata'           => array( 'type' => 'object' ),
					),
					'required'   => array( 'html_path' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_import_theme',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'static-site-importer/export-theme',
			array(
				'label'               => __( 'Export Static Site Theme', 'static-site-importer' ),
				'description'         => __( 'Export an imported or active block theme and page content as static-site artifacts.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'theme_slug'      => array( 'type' => 'string' ),
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

if ( ! function_exists( 'static_site_importer_ability_export_theme' ) ) {
	/**
	 * Ability callback for static site theme exports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_export_theme( array $input ): array {
		$args = array(
			'theme_slug'      => isset( $input['theme_slug'] ) ? (string) $input['theme_slug'] : '',
			'entrypoint'      => isset( $input['entrypoint'] ) ? (string) $input['entrypoint'] : 'static-site/index.html',
			'include_pages'   => $input['include_pages'] ?? true,
			'source_metadata' => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);

		$result = Static_Site_Importer_Theme_Generator::export_theme( $args );
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

if ( ! function_exists( 'static_site_importer_ability_import_theme' ) ) {
	/**
	 * Ability callback for static site theme imports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_import_theme( array $input ): array {
		$html_path = isset( $input['html_path'] ) ? trim( (string) $input['html_path'] ) : '';
		if ( '' === $html_path ) {
			return static_site_importer_ability_error( 'static_site_importer_missing_html_path', 'The html_path input is required.' );
		}

		$args = array(
			'slug'                      => isset( $input['slug'] ) ? (string) $input['slug'] : '',
			'name'                      => isset( $input['name'] ) ? (string) $input['name'] : '',
			'activate'                  => ! empty( $input['activate'] ),
			'overwrite'                 => ! empty( $input['overwrite'] ),
			'keep_source'               => ! empty( $input['keep_source'] ),
			'fail_on_quality'           => ! empty( $input['fail_on_quality'] ),
			'max_fallbacks'             => isset( $input['max_fallbacks'] ) ? (int) $input['max_fallbacks'] : null,
			'allow_missing_woocommerce' => ! empty( $input['allow_missing_woocommerce'] ),
			'report'                    => isset( $input['report'] ) ? (string) $input['report'] : '',
			'asset_map'                 => isset( $input['asset_map'] ) && is_array( $input['asset_map'] ) ? $input['asset_map'] : array(),
			'source_metadata'           => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme( $html_path, $args );
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
			'fallback_count'        => 0,
			'core_html_block_count' => 0,
			'freeform_block_count'  => 0,
			'invalid_block_count'   => 0,
			'content_loss_count'    => 0,
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
