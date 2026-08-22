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
				'description'         => __( 'Apply a canonical Blocks Engine WordPress site plan to this WordPress runtime.', 'static-site-importer' ),
				'category'            => STATIC_SITE_IMPORTER_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plan'            => array( 'type' => 'object' ),
						'slug'            => array( 'type' => 'string' ),
						'activate'        => array( 'type' => 'boolean' ),
						'site_title'      => array( 'type' => 'string' ),
						'overwrite'       => array( 'type' => 'boolean' ),
						'disable_smilies' => array( 'type' => 'boolean' ),
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
				'description'         => __( 'Plan or apply pasted HTML, website files, a ZIP archive, or a public URL through one canonical import contract.', 'static-site-importer' ),
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
		return Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array(), $input );
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
		if ( array_key_exists( 'report', $input ) ) {
			return static_site_importer_ability_error( 'static_site_importer_report_destination_forbidden', 'Report destinations are owned by the importer and are not accepted through Abilities.' );
		}
		$source    = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		$type      = (string) ( $source['type'] ?? '' );
		$operation = (string) ( $input['operation'] ?? 'apply' );
		if ( ! in_array( $operation, array( 'plan', 'apply' ), true ) ) {
			return static_site_importer_ability_error( 'static_site_importer_invalid_import_operation', 'operation must be plan or apply.' );
		}

		if ( 'apply' === $operation && isset( $input['plan'] ) && is_array( $input['plan'] ) ) {
			return static_site_importer_ability_apply_approved_plan( $input );
		}

		if ( ! in_array( $type, array( 'html', 'files', 'zip', 'url' ), true ) ) {
			return static_site_importer_ability_error( 'static_site_importer_invalid_import_source', 'source.type must be html, files, zip, or url.' );
		}

		$provenance = array( 'type' => $type );
		$reference  = (string) ( $source['ref'] ?? '' );
		if ( 'zip' === $type && ! empty( $source['zip']['staged_path'] ) ) {
			return static_site_importer_ability_error( 'static_site_importer_staged_archive_forbidden', 'Staged archive paths must come from a server-owned opaque reference resolver.' );
		}
		if ( '' !== $reference ) {
			$resolved = apply_filters( 'static_site_importer_resolve_source_reference', null, $reference, $type, $input );
			if ( ! is_array( $resolved ) ) {
				return static_site_importer_ability_error( 'static_site_importer_source_reference_unresolved', 'The opaque source reference was not resolved by a server-owned resolver.' );
			}

			$resolved_source = isset( $resolved['source'] ) && is_array( $resolved['source'] ) ? $resolved['source'] : $resolved;
			if ( isset( $resolved_source['type'] ) && $type !== (string) $resolved_source['type'] ) {
				return static_site_importer_ability_error( 'static_site_importer_source_reference_type_mismatch', 'The resolved source type does not match the requested source type.' );
			}
			$source     = array_merge( $source, $resolved_source, array( 'type' => $type ) );
			$provenance = array_merge(
				$provenance,
				array( 'ref' => $reference ),
				isset( $resolved['provenance'] ) && is_array( $resolved['provenance'] ) ? $resolved['provenance'] : array()
			);
		}

		if ( 'url' === $type ) {
			if ( 'apply' === $operation ) {
				return static_site_importer_ability_error( 'static_site_importer_url_apply_requires_plan', 'Apply a completed URL import by supplying its approved canonical plan.' );
			}

			return static_site_importer_ability_import_url_operation( $input, $source );
		}

		$runtime_source = array(
			'entrypoint' => (string) ( $source['entrypoint'] ?? '' ),
			'metadata'   => isset( $source['metadata'] ) && is_array( $source['metadata'] ) ? $source['metadata'] : array(),
		);
		if ( 'html' === $type ) {
			$runtime_source['html'] = (string) ( $source['html'] ?? '' );
		} elseif ( 'files' === $type ) {
			$runtime_source['files'] = isset( $source['files'] ) && is_array( $source['files'] ) ? $source['files'] : array();
		} elseif ( ! empty( $source['zip']['staged_path'] ) ) {
			$runtime_source['files'] = static_site_importer_staged_archive_files( $source['zip'], true );
			if ( is_wp_error( $runtime_source['files'] ) ) {
				return static_site_importer_ability_error( (string) $runtime_source['files']->get_error_code(), $runtime_source['files']->get_error_message(), $runtime_source['files']->get_error_data() );
			}
			if ( 'apply' === $operation ) {
				$payload_reader = static_site_importer_staged_archive_payload_reader( $source['zip'] );
				if ( is_wp_error( $payload_reader ) ) {
					return static_site_importer_ability_error( (string) $payload_reader->get_error_code(), $payload_reader->get_error_message(), $payload_reader->get_error_data() );
				}
			}
		} else {
			$runtime_source['archive'] = isset( $source['zip'] ) && is_array( $source['zip'] ) ? $source['zip'] : array();
		}

		if ( ! function_exists( 'static_site_importer_source_runtime' ) ) {
			return static_site_importer_ability_error( 'static_site_importer_source_normalizer_unavailable', 'The canonical source normalizer is unavailable.' );
		}
		$runtime = static_site_importer_source_runtime( $runtime_source );
		if ( is_wp_error( $runtime ) ) {
			return static_site_importer_ability_error( (string) $runtime->get_error_code(), $runtime->get_error_message(), $runtime->get_error_data() );
		}
		$artifact = $runtime['artifact'];
		if ( empty( $artifact ) ) {
			return static_site_importer_ability_error( 'static_site_importer_missing_website_artifact', 'The source did not normalize to a website artifact.' );
		}
		$provenance = array_merge(
			$provenance,
			array(
				'provider'        => $runtime['provider'],
				'source_metadata' => $runtime['source_metadata'],
			)
		);

		$args = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
		if ( isset( $payload_reader ) ) {
			$args['_static_site_importer_payload_reader'] = $payload_reader;
		}
		if ( '' !== $args['runtime_lifecycle_phase'] ) {
			$args['runtime_lifecycle_invocation_id'] = wp_generate_uuid4();
		}
		if ( isset( $GLOBALS['_static_site_importer_cli_report_destination'] ) ) {
			$args['report'] = (string) $GLOBALS['_static_site_importer_cli_report_destination'];
		}
		if ( 'plan' === $operation ) {
			return static_site_importer_ability_plan_artifact( $artifact, $args, $type, $provenance );
		}

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return static_site_importer_ability_import_success( $result, $input );
	}
}

if ( ! function_exists( 'static_site_importer_cli_import' ) ) {
	/** Run an import with the explicit, local WP-CLI report output seam. */
	function static_site_importer_cli_import( array $input ): array {
		$report = isset( $input['report'] ) ? (string) $input['report'] : '';
		unset( $input['report'] );
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		if ( 'url' === ( $source['type'] ?? '' ) ) {
			$input['report'] = $report;
			return static_site_importer_ability_import_url_operation( $input, $source );
		}

		$GLOBALS['_static_site_importer_cli_report_destination'] = $report;
		try {
			return static_site_importer_ability_import( $input );
		} finally {
			unset( $GLOBALS['_static_site_importer_cli_report_destination'] );
		}
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

if ( ! function_exists( 'static_site_importer_ability_plan_artifact' ) ) {
	/** Hash a structured handoff deterministically, including every normalized arg. */
	function static_site_importer_ability_handoff_hash( array $value ): string {
		return hash( 'sha256', (string) wp_json_encode( static_site_importer_ability_handoff_hashable( $value ) ) );
	}

	/** @return array<array-key,mixed> */
	function static_site_importer_ability_handoff_hashable( array $value ): array {
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$item = static_site_importer_ability_handoff_hashable( $item );
			}
		}
		unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/** @return array<string,mixed> */
	function static_site_importer_ability_plan_artifact( array $artifact, array $args, string $type, array $provenance ): array {
		$compiled = Static_Site_Importer_Theme_Generator::compile_website_artifact( $artifact, $args );
		if ( is_wp_error( $compiled ) ) {
			return static_site_importer_ability_error( (string) $compiled->get_error_code(), $compiled->get_error_message(), $compiled->get_error_data() );
		}

		$response = array(
			'success'     => true,
			'operation'   => 'plan',
			'plan'        => $compiled['plan'],
			'diagnostics' => $compiled['plan']['diagnostics'] ?? array(),
			'quality'     => $compiled['plan']['quality'] ?? array(),
			'source'      => array(
				'type'       => $type,
				'identity'   => hash( 'sha256', (string) wp_json_encode( $artifact ) ),
				'provenance' => $provenance,
			),
		);
		if ( 'classic' === ( $compiled['args']['theme_materialization'] ?? '' ) ) {
			$encoded_artifact                    = wp_json_encode( $compiled['artifact'] );
			$encoded_projection                  = wp_json_encode( $compiled['args']['classic_theme_projection'] ?? array() );
			$response['classic_materialization'] = array(
				'schema'          => 'static-site-importer/classic-plan-input/v1',
				'plan_hash'       => hash( 'sha256', (string) wp_json_encode( $compiled['plan'] ) ),
				'artifact_hash'   => hash( 'sha256', false !== $encoded_artifact ? $encoded_artifact : '' ),
				'projection_hash' => hash( 'sha256', false !== $encoded_projection ? $encoded_projection : '' ),
				'args_hash'       => static_site_importer_ability_handoff_hash( $compiled['args'] ),
				'artifact'        => $compiled['artifact'],
				'projection'      => $compiled['args']['classic_theme_projection'],
				'normalized_args' => $compiled['args'],
			);
		}

		return $response;
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_url_operation' ) ) {
	/** @param array<string,mixed> $input @param array<string,mixed> $source @return array<string,mixed> */
	function static_site_importer_ability_import_url_operation( array $input, array $source ): array {
		$url_input = array_merge(
			$input,
			array(
				'url'       => (string) ( $source['url'] ?? '' ),
				'import_id' => (string) ( $source['import_id'] ?? '' ),
			)
		);
		$result    = Static_Site_Importer_URL_Import_Runtime::run_operation( $url_input );
		if ( is_wp_error( $result ) ) {
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		$continuation = array(
			'success'               => true,
			'operation'             => (string) ( $input['operation'] ?? 'apply' ),
			'import_id'             => (string) ( $result['import_id'] ?? '' ),
			'continuation'          => ! empty( $result['continuation'] ),
			'continuation_reason'   => (string) ( $result['continuation_reason'] ?? '' ),
			'import_report_summary' => is_array( $result['import_report_summary'] ?? null ) ? $result['import_report_summary'] : array(),
			'url_batch_run'         => is_array( $result['url_batch_run'] ?? null ) ? $result['url_batch_run'] : array(),
		);
		if ( ! empty( $result['continuation'] ) ) {
			return $continuation;
		}

		$terminal = is_array( $result['terminal_batch_result'] ?? null ) ? $result['terminal_batch_result'] : array();
		if ( 'plan' !== ( $input['operation'] ?? 'apply' ) ) {
			return array_merge( static_site_importer_ability_import_success( $result, $input ), $continuation );
		}
		if ( ! is_array( $terminal['plan'] ?? null ) ) {
			return static_site_importer_ability_error( 'static_site_importer_url_plan_missing', 'The completed URL acquisition did not produce a canonical plan.' );
		}

		$response = array_merge(
			$continuation,
			array(
				'plan'        => $terminal['plan'],
				'diagnostics' => array_merge(
					is_array( $continuation['url_batch_run']['diagnostics'] ?? null ) ? $continuation['url_batch_run']['diagnostics'] : array(),
					is_array( $terminal['diagnostics'] ?? null ) ? $terminal['diagnostics'] : array()
				),
				'quality'     => is_array( $terminal['quality'] ?? null ) ? $terminal['quality'] : array(),
				'source'      => array(
					'type'       => 'url',
					'identity'   => hash( 'sha256', (string) wp_json_encode( $terminal['plan'] ) ),
					'provenance' => array(
						'url'           => (string) ( $source['url'] ?? '' ),
						'import_id'     => (string) ( $result['import_id'] ?? '' ),
						'url_batch_run' => $continuation['url_batch_run'],
					),
				),
			)
		);
		$args     = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
		if ( 'classic' === $args['theme_materialization'] && is_array( $terminal['artifact'] ?? null ) ) {
			$projection = Static_Site_Importer_Classic_Theme_Projection::build( $terminal['artifact'], $terminal['plan'] );
			if ( is_wp_error( $projection ) ) {
				return static_site_importer_ability_error( (string) $projection->get_error_code(), $projection->get_error_message(), $projection->get_error_data() );
			}
			$response['classic_materialization'] = array(
				'schema'          => 'static-site-importer/classic-plan-input/v1',
				'plan_hash'       => hash( 'sha256', (string) wp_json_encode( $terminal['plan'] ) ),
				'artifact_hash'   => hash( 'sha256', (string) wp_json_encode( $terminal['artifact'] ) ),
				'projection_hash' => hash( 'sha256', (string) wp_json_encode( $projection ) ),
				'args_hash'       => static_site_importer_ability_handoff_hash( $args ),
				'artifact'        => $terminal['artifact'],
				'projection'      => $projection,
				'normalized_args' => $args,
			);
		}
		return $response;
	}
}

if ( ! function_exists( 'static_site_importer_ability_apply_approved_plan' ) ) {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	function static_site_importer_ability_apply_approved_plan( array $input ): array {
		$approved       = $input['plan'];
		$plan           = isset( $approved['plan'] ) && is_array( $approved['plan'] ) ? $approved['plan'] : $approved;
		$payload_reader = static_site_importer_ability_approved_plan_payload_reader( $input, $approved );
		if ( is_wp_error( $payload_reader ) ) {
			return static_site_importer_ability_error( (string) $payload_reader->get_error_code(), $payload_reader->get_error_message(), $payload_reader->get_error_data() );
		}
		$classic = isset( $approved['classic_materialization'] ) && is_array( $approved['classic_materialization'] ) ? $approved['classic_materialization'] : ( isset( $input['classic_materialization'] ) && is_array( $input['classic_materialization'] ) ? $input['classic_materialization'] : null );
		if ( is_array( $classic ) ) {
			$artifact   = $classic['artifact'] ?? null;
			$projection = $classic['projection'] ?? null;
			$args       = $classic['normalized_args'] ?? null;
			if ( 'static-site-importer/classic-plan-input/v1' !== ( $classic['schema'] ?? '' ) || ! is_array( $args ) || 'classic' !== ( $args['theme_materialization'] ?? '' ) || ! is_array( $artifact ) || ! is_array( $projection ) || hash( 'sha256', (string) wp_json_encode( $plan ) ) !== ( $classic['plan_hash'] ?? '' ) || hash( 'sha256', (string) wp_json_encode( $artifact ) ) !== ( $classic['artifact_hash'] ?? '' ) || hash( 'sha256', (string) wp_json_encode( $projection ) ) !== ( $classic['projection_hash'] ?? '' ) || static_site_importer_ability_handoff_hash( $args ) !== ( $classic['args_hash'] ?? '' ) ) {
				return static_site_importer_ability_error( 'static_site_importer_classic_plan_input_changed', 'The approved classic artifact or projection does not match its immutable plan input.' );
			}
			$projection_hash = $classic['projection_hash'];
			$rebuilt         = Static_Site_Importer_Classic_Theme_Projection::build( $artifact, $plan );
			if ( is_wp_error( $rebuilt ) || hash( 'sha256', (string) wp_json_encode( $rebuilt ) ) !== $projection_hash ) {
				return static_site_importer_ability_error( 'static_site_importer_classic_projection_changed', 'The approved classic projection could not be reproduced from its immutable artifact.' );
			}
			$args['approved_classic_plan_hash']       = (string) $classic['plan_hash'];
			$args['approved_classic_projection_hash'] = (string) $projection_hash;
			if ( is_object( $payload_reader ) ) {
				$args['_static_site_importer_payload_reader'] = $payload_reader;
			}
			$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
			if ( is_wp_error( $result ) ) {
				return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
			}
			return array(
				'success'           => true,
				'operation'         => 'apply',
				'plan'              => $plan,
				'applied_plan'      => $plan,
				'applied_plan_hash' => $classic['plan_hash'],
				'result'            => $result,
				'error'             => null,
			);
		}
		if ( is_object( $payload_reader ) ) {
			$input['_static_site_importer_payload_reader'] = $payload_reader;
		}
		$receipt = static_site_importer_ability_materialize_wordpress_site_plan( $input );
		$success = 'completed' === ( $receipt['status'] ?? '' );

		return array(
			'success'   => $success,
			'operation' => 'apply',
			'plan'      => $plan,
			'result'    => $receipt,
			'error'     => $success ? null : ( $receipt['errors'][0] ?? array(
				'code'    => 'static_site_importer_materialization_failed',
				'message' => 'The approved plan could not be materialized.',
			) ),
		);
	}
}

if ( ! function_exists( 'static_site_importer_ability_approved_plan_payload_reader' ) ) {
	/** Resolve the opaque staged ZIP again only for the transient apply reader. */
	function static_site_importer_ability_approved_plan_payload_reader( array $input, array $approved ) {
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : ( isset( $approved['source'] ) && is_array( $approved['source'] ) ? $approved['source'] : array() );
		if ( 'zip' !== (string) ( $source['type'] ?? '' ) ) {
			return null;
		}
		$reference = (string) ( $source['ref'] ?? ( $source['provenance']['ref'] ?? '' ) );
		if ( '' === $reference ) {
			return null;
		}
		$resolved = apply_filters( 'static_site_importer_resolve_source_reference', null, $reference, 'zip', $input );
		if ( ! is_array( $resolved ) ) {
			return new WP_Error( 'static_site_importer_source_reference_unresolved', 'The opaque source reference was not resolved by a server-owned resolver.' );
		}
		$resolved_source = isset( $resolved['source'] ) && is_array( $resolved['source'] ) ? $resolved['source'] : $resolved;
		$archive         = isset( $resolved_source['zip'] ) && is_array( $resolved_source['zip'] ) ? $resolved_source['zip'] : array();
		if ( empty( $archive['staged_path'] ) ) {
			return new WP_Error( 'static_site_importer_staged_archive_invalid', 'The approved plan requires a resolver-owned staged ZIP archive.' );
		}
		return static_site_importer_staged_archive_payload_reader( $archive );
	}
}

if ( ! function_exists( 'static_site_importer_ability_import_success' ) ) {
	/**
	 * Build the success envelope for a completed website artifact import.
	 *
	 * Mirrors the failure envelope (`static_site_importer_ability_error`) so consumers
	 * can read the same `static-site-importer/import-diagnostics/v1` contract whether an
	 * import succeeded or failed: warnings, quality counts, blocks-engine conversion stats,
	 * semantic parity, and runtime-dependency gaps are all surfaced on success too.
	 *
	 * @param array<string,mixed> $result Import result from Static_Site_Importer_Theme_Generator::import_website_artifact().
	 * @param array<string,mixed> $input  Original ability input.
	 * @return array<string,mixed>
	 */
	function static_site_importer_ability_import_success( array $result, array $input ): array {
		$contract = static_site_importer_success_diagnostics_contract( $result );

		/**
		 * Fires after a website artifact import completes successfully, once the
		 * import-diagnostics contract has been built. Consumers can read the contract
		 * without reconstructing it from the raw result.
		 *
		 * @param array<string,mixed> $contract The static-site-importer/import-diagnostics/v1 contract.
		 * @param array<string,mixed> $result   The raw import result.
		 * @param array<string,mixed> $input    The original ability input.
		 */
		if ( function_exists( 'do_action' ) ) {
			do_action( 'static_site_importer_import_completed', $contract, $result, $input );
		}

		return array(
			'success'             => true,
			'result'              => $result,
			'diagnostics'         => isset( $contract['diagnostics'] ) && is_array( $contract['diagnostics'] ) ? $contract['diagnostics'] : array(),
			'fixture_diagnostics' => $contract,
		);
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
		$import_validation_result = isset( $result['import_validation_result'] ) && is_array( $result['import_validation_result'] ) ? $result['import_validation_result'] : array();
		$quality                  = isset( $result['quality'] ) && is_array( $result['quality'] ) ? $result['quality'] : array();
		$validation_diagnostics   = isset( $import_validation_result['diagnostics'] ) && is_array( $import_validation_result['diagnostics'] ) ? $import_validation_result['diagnostics'] : array();
		$import_report            = isset( $result['import_report'] ) && is_array( $result['import_report'] ) ? $result['import_report'] : array();
		if ( empty( $import_report ) ) {
			$import_report = array(
				'quality'     => $quality,
				'diagnostics' => $validation_diagnostics,
			);
		}

		$contract_input = array(
			'success'                  => true,
			'status'                   => isset( $result['import_report_summary']['status'] ) && is_scalar( $result['import_report_summary']['status'] ) ? (string) $result['import_report_summary']['status'] : 'completed',
			'slug'                     => isset( $result['theme_slug'] ) ? (string) $result['theme_slug'] : '',
			'name'                     => isset( $result['theme_name'] ) ? (string) $result['theme_name'] : '',
			'import_validation_result' => $import_validation_result,
			'import_report'            => $import_report,
			'materialization_receipt'  => isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] ) ? $result['materialization_receipt'] : array(),
		);

		return class_exists( 'Static_Site_Importer_Diagnostic_Contract' ) ? Static_Site_Importer_Diagnostic_Contract::build( $contract_input ) : array( 'diagnostics' => $validation_diagnostics );
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
		$import_report_summary = is_array( $data ) && isset( $data['import_report_summary'] ) && is_array( $data['import_report_summary'] ) ? $data['import_report_summary'] : static_site_importer_failure_report_summary( $code, $message );
		$diagnostics           = static_site_importer_error_diagnostics( $code, $message, $data, $import_report_summary );
		$fixture_diagnostics   = class_exists( 'Static_Site_Importer_Diagnostic_Contract' ) ? Static_Site_Importer_Diagnostic_Contract::build(
			array(
				'success'                  => false,
				'status'                   => 'failed',
				'diagnostics'              => $diagnostics,
				'import_validation_result' => is_array( $data ) && isset( $data['import_validation_result'] ) && is_array( $data['import_validation_result'] ) ? $data['import_validation_result'] : array(),
				'import_report'            => is_array( $data ) && isset( $data['import_report'] ) && is_array( $data['import_report'] ) ? $data['import_report'] : array(),
			)
		) : array( 'diagnostics' => $diagnostics );

		$payload = array(
			'success'               => false,
			'error'                 => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
			'import_report_summary' => $import_report_summary,
			'diagnostics'           => $diagnostics,
			'errors'                => $diagnostics,
			'fixture_diagnostics'   => $fixture_diagnostics,
		);

		if ( is_array( $data ) && isset( $data['import_validation_result'] ) && is_array( $data['import_validation_result'] ) ) {
			$payload['import_validation_result'] = $data['import_validation_result'];
		}
		if ( is_array( $data ) && isset( $data['finding_packets'] ) && is_array( $data['finding_packets'] ) ) {
			$payload['finding_packets'] = $data['finding_packets'];
		}

		return $payload;
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
		$candidates = array(
			is_array( $data ) && isset( $data['import_validation_result']['diagnostics'] ) && is_array( $data['import_validation_result']['diagnostics'] ) ? $data['import_validation_result']['diagnostics'] : array(),
			isset( $import_report_summary['diagnostics'] ) && is_array( $import_report_summary['diagnostics'] ) ? $import_report_summary['diagnostics'] : array(),
		);

		foreach ( $candidates as $candidate ) {
			$diagnostics = array_values( array_filter( $candidate, 'static_site_importer_is_actionable_error_diagnostic' ) );
			if ( ! empty( $diagnostics ) ) {
				return $diagnostics;
			}
		}

		return array(
			array(
				'type'        => 'validation_error',
				'kind'        => 'validation_error',
				'severity'    => 'error',
				'code'        => $code,
				'reason_code' => $code,
				'reason'      => $code,
				'message'     => $message,
				'stage'       => 'validation',
				'owner'       => 'static-site-importer',
			),
		);
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
		if ( ! is_array( $diagnostic ) ) {
			return false;
		}

		foreach ( array( 'type', 'kind', 'code', 'reason_code', 'reason', 'error_code', 'source_path', 'path', 'source', 'selector' ) as $field ) {
			if ( ! isset( $diagnostic[ $field ] ) || ! is_scalar( $diagnostic[ $field ] ) ) {
				continue;
			}

			$value = trim( (string) $diagnostic[ $field ] );
			if ( '' !== $value && ! preg_match( '/^\d+$/', $value ) ) {
				return true;
			}
		}

		return false;
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
