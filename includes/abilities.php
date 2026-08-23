<?php
/**
 * WordPress Abilities API integration.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Website_Artifact_Import_Input' ) ) {
	require_once __DIR__ . '/class-static-site-importer-website-artifact-import-input.php';
}

if ( ! class_exists( 'Static_Site_Importer_Canonical_Import_Service' ) ) {
	require_once __DIR__ . '/class-static-site-importer-canonical-import-service.php';
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
		if ( function_exists( 'wp_get_ability_category' ) && wp_get_ability_category( STATIC_SITE_IMPORTER_ABILITY_CATEGORY ) ) {
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

if ( ! function_exists( 'static_site_importer_register_ability_once' ) ) {
	/**
	 * Register an ability unless it already exists in the current runtime.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 * @return void
	 */
	function static_site_importer_register_ability_once( string $name, array $args ): void {
		if ( function_exists( 'wp_get_ability' ) && wp_get_ability( $name ) ) {
			return;
		}

		wp_register_ability( $name, $args );
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

		$import_properties = Static_Site_Importer_Website_Artifact_Import_Input::SCHEMA_PROPERTIES;

		static_site_importer_register_ability_once(
			'static-site-importer/get-runtime-package-manifest',
			array(
				'label'               => __( 'Get Runtime Package Manifest', 'static-site-importer' ),
				'description'         => __( 'Return the capability-scoped runtime package profiles shipped with Static Site Importer.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_get_runtime_package_manifest',
				'permission_callback' => 'static_site_importer_ability_read_runtime_manifest_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		static_site_importer_register_ability_once(
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

		static_site_importer_register_ability_once(
			'static-site-importer/materialize-wordpress-site-plan',
			array(
				'label'               => __( 'Materialize WordPress Site Plan', 'static-site-importer' ),
				'description'         => __( 'Apply a canonical Blocks Engine WordPress site plan to this runtime using an intentional block or classic SSI materialization strategy.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plan'                   => array( 'type' => 'object' ),
						'slug'                   => array( 'type' => 'string' ),
						'activate'               => array( 'type' => 'boolean' ),
						'site_title'             => array( 'type' => 'string' ),
						'overwrite'              => array( 'type' => 'boolean' ),
						'disable_smilies'        => array( 'type' => 'boolean' ),
						'remove_default_content' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'plan', 'slug' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_materialize_wordpress_site_plan',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		static_site_importer_register_ability_once(
			'static-site-importer/import',
			array(
				'label'               => __( 'Import Static Site', 'static-site-importer' ),
				'description'         => __( 'Plan or apply pasted HTML, website files, a ZIP archive, or a public URL through one canonical SSI import contract with intentional block or classic materialization.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'operation' => array(
								'type' => 'string',
								'enum' => array( 'plan', 'apply' ),
							),
							'plan'      => array( 'type' => 'object' ),
							'source'    => array(
								'type'       => 'object',
								'properties' => array(
									'type'       => array(
										'type' => 'string',
										'enum' => array( 'html', 'files', 'zip', 'url' ),
									),
									'html'       => array( 'type' => 'string' ),
									'files'      => array(
										'type'  => 'array',
										'items' => array( 'type' => 'object' ),
									),
									'zip'        => array( 'type' => 'object' ),
									'url'        => array( 'type' => 'string' ),
									'entrypoint' => array( 'type' => 'string' ),
									'metadata'   => array( 'type' => 'object' ),
									'import_id'  => array( 'type' => 'string' ),
									'ref'        => array( 'type' => 'string' ),
								),
								'required'   => array( 'type' ),
							),
						),
						$import_properties
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_import',
				'permission_callback' => 'static_site_importer_ability_import_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		static_site_importer_register_ability_once(
			'static-site-importer/import-figma',
			array(
				'label'               => __( 'Import Figma Design', 'static-site-importer' ),
				'description'         => __( 'Convert a Figma runner request or scenegraph into a website artifact and import it as a WordPress block theme.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'artifact_bundle'   => array( 'type' => 'object' ),
							'figma'             => array( 'type' => 'object' ),
							'scenegraph'        => array( 'type' => 'object' ),
							'source'            => array( 'type' => 'object' ),
							'goal'              => array( 'type' => 'string' ),
							'transform_options' => array( 'type' => 'object' ),
							'validation'        => array( 'type' => 'object' ),
							'frame_id'          => array( 'type' => 'string' ),
						),
						$import_properties
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_import_figma',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		static_site_importer_register_ability_once(
			'static-site-importer/validate-artifact',
			array(
				'label'               => __( 'Validate Static Site Artifact', 'static-site-importer' ),
				'description'         => __( 'Validate a website artifact in the current WordPress runtime and return importer-owned diagnostics.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'artifact'            => array( 'type' => 'object' ),
							'generated_theme_ref' => array( 'type' => 'object' ),
							'theme_archive_ref'   => array( 'type' => 'object' ),
						),
						$import_properties
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'static_site_importer_ability_validate_artifact',
				'permission_callback' => 'static_site_importer_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_read_runtime_manifest_permission_callback' ) ) {
	/**
	 * Filter access to the non-sensitive runtime package manifest.
	 *
	 * @return bool
	 */
	function static_site_importer_ability_read_runtime_manifest_permission_callback(): bool {
		/**
		 * Filters whether the current request may inspect runtime package profiles.
		 *
		 * @param bool $allowed Whether manifest inspection is allowed.
		 */
		return (bool) apply_filters( 'static_site_importer_can_read_runtime_package_manifest', true );
	}
}

if ( ! function_exists( 'static_site_importer_ability_get_runtime_package_manifest' ) ) {
	/**
	 * Return the package manifest used by archive consumers.
	 *
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_get_runtime_package_manifest(): array {
		$path = STATIC_SITE_IMPORTER_PATH . 'runtime-package-manifest.json';
		if ( ! is_readable( $path ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'static_site_importer_runtime_package_manifest_missing',
					'message' => 'The Static Site Importer runtime package manifest is unavailable.',
				),
			);
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a plugin-owned immutable contract.
		if ( ! is_array( $manifest ) || 'static-site-importer/runtime-package-manifest/v1' !== ( $manifest['schema'] ?? null ) || ! isset( $manifest['profiles'] ) || ! is_array( $manifest['profiles'] ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'static_site_importer_runtime_package_manifest_invalid',
					'message' => 'The Static Site Importer runtime package manifest is invalid.',
				),
			);
		}

		return array(
			'success'  => true,
			'manifest' => $manifest,
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_materialize_wordpress_site_plan' ) ) {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	function static_site_importer_ability_materialize_wordpress_site_plan( array $input ): array {
		return Static_Site_Importer_Canonical_Import_Service::materialize_wordpress_site_plan( $input );
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

		$allowed = ! function_exists( 'current_user_can' ) || current_user_can( 'switch_themes' );

		/**
		 * Filters whether the current request may run import abilities.
		 *
		 * @param bool $allowed Whether the current request is allowed.
		 */
		return (bool) apply_filters( 'static_site_importer_can_manage_imports', $allowed );
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_permission_callback' ) ) {
	/** Permission callback that distinguishes read-only compilation from apply. */
	function static_site_importer_ability_import_permission_callback( array $input = array() ): bool {
		if ( defined( 'WP_CLI' ) ) {
			return true;
		}

		$planning = 'plan' === (string) ( $input['operation'] ?? 'apply' );
		$allowed  = ! function_exists( 'current_user_can' ) || current_user_can( $planning ? 'edit_posts' : 'switch_themes' );

		return (bool) apply_filters(
			$planning ? 'static_site_importer_can_plan_imports' : 'static_site_importer_can_manage_imports',
			$allowed,
			$input
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_validate_artifact' ) ) {
	/**
	 * Ability callback for importer-owned validation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_validate_artifact( array $input ): array {
		$result = Static_Site_Importer_Validation_Runtime::validate_artifact( $input );
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

if ( ! function_exists( 'static_site_importer_ability_import_figma' ) ) {
	/**
	 * Ability callback for Figma imports.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function static_site_importer_ability_import_figma( array $input ): array {
		return Static_Site_Importer_Figma_Import::import( $input );
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

if ( ! function_exists( 'static_site_importer_ability_import' ) ) {
	/** Canonical plan-first import dispatcher. */
	function static_site_importer_ability_import( array $input ): array {
		return Static_Site_Importer_Canonical_Import_Service::import( $input );
	}
}

if ( ! function_exists( 'static_site_importer_ability_files_source' ) ) {
	/** Convert an internal website artifact to the public files source shape. */
	function static_site_importer_ability_files_source( array $artifact ): array {
		$metadata = $artifact;
		unset( $metadata['schema'], $metadata['entrypoint'], $metadata['files'] );

		return array(
			'type'       => 'files',
			'entrypoint' => (string) ( $artifact['entrypoint'] ?? '' ),
			'files'      => isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array(),
			'metadata'   => $metadata,
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_apply_approved_plan' ) ) {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	function static_site_importer_ability_apply_approved_plan( array $input ): array {
		return Static_Site_Importer_Canonical_Import_Service::apply_approved_plan( $input );
	}
}

if ( ! function_exists( 'static_site_importer_success_diagnostics_contract' ) ) {
	/**
	 * Build the import-diagnostics contract from a successful import result.
	 *
	 * Maps the success result shape returned by
	 * Static_Site_Importer_Theme_Generator::import_website_artifact() into the keys the
	 * diagnostic contract reads. Fields the contract needs but the result does not carry
	 * are mapped from what the import returns rather than fabricated.
	 *
	 * @param array<string,mixed> $result Import result.
	 * @return array<string,mixed>
	 */
	function static_site_importer_success_diagnostics_contract( array $result ): array {
		return Static_Site_Importer_Canonical_Import_Service::success_diagnostics_contract( $result );
	}
}

if ( ! function_exists( 'static_site_importer_error_diagnostics' ) ) {
	/**
	 * Promote nested validation diagnostics to top-level ability fields.
	 *
	 * @param string              $code                  Error code.
	 * @param string              $message               Error message.
	 * @param mixed               $data                  Optional error data.
	 * @param array<string,mixed> $import_report_summary Import report summary.
	 * @return array<int,array<string,mixed>>
	 */
	function static_site_importer_error_diagnostics( string $code, string $message, $data, array $import_report_summary ): array {
		return Static_Site_Importer_Canonical_Import_Service::error_diagnostics( $code, $message, $data, $import_report_summary );
	}
}

if ( ! function_exists( 'static_site_importer_is_actionable_error_diagnostic' ) ) {
	/**
	 * Check whether an error diagnostic has machine-actionable identity or source context.
	 *
	 * @param mixed $diagnostic Candidate diagnostic.
	 * @return bool
	 */
	function static_site_importer_is_actionable_error_diagnostic( $diagnostic ): bool {
		return Static_Site_Importer_Canonical_Import_Service::is_actionable_error_diagnostic( $diagnostic );
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
		return Static_Site_Importer_Canonical_Import_Service::failure_report_summary( $code, $message );
	}
}

// Public Ability helper names remain callable compatibility adapters.
if ( ! function_exists( 'static_site_importer_ability_error' ) ) {
	function static_site_importer_ability_error( string $code, string $message, $data = null ): array {
		return Static_Site_Importer_Canonical_Import_Service::error( $code, $message, $data );
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_success' ) ) {
	function static_site_importer_ability_import_success( array $result, array $input ): array {
		return Static_Site_Importer_Canonical_Import_Service::success( $result, $input );
	}
}

if ( ! function_exists( 'static_site_importer_ability_handoff_hash' ) ) {
	function static_site_importer_ability_handoff_hash( array $value ): string {
		return Static_Site_Importer_Canonical_Import_Service::handoff_hash( $value );
	}
}

if ( ! function_exists( 'static_site_importer_ability_plan_artifact' ) ) {
	function static_site_importer_ability_plan_artifact( array $artifact, array $args, string $type, array $provenance ): array {
		return Static_Site_Importer_Canonical_Import_Service::plan_artifact( $artifact, $args, $type, $provenance );
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_url_operation' ) ) {
	function static_site_importer_ability_import_url_operation( array $input, array $source ): array {
		return Static_Site_Importer_Canonical_Import_Service::import_url_operation( $input, $source );
	}
}

if ( ! function_exists( 'static_site_importer_ability_approved_plan_payload_reader' ) ) {
	function static_site_importer_ability_approved_plan_payload_reader( array $input, array $approved ) {
		return Static_Site_Importer_Canonical_Import_Service::approved_plan_payload_reader( $input, $approved );
	}
}

require_once __DIR__ . '/cli.php';

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
