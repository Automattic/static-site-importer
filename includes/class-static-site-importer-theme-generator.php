<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- The generator keeps localized assignment alignment; PHPCBF exhausts memory on this large file.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

if ( ! class_exists( 'Static_Site_Importer_Block_Document_Reporter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-block-document-reporter.php';
}

/**
 * Generates a block theme from a static HTML document.
 */
class Static_Site_Importer_Theme_Generator {

	/**
	 * Scoped conversion quality report for the active import.
	 *
	 * @var array<string, mixed>
	 */
	private static array $conversion_report = array();

	/**
	 * Generated theme URI for import-scoped asset references.
	 *
	 * @var string
	 */
	private static string $active_theme_uri = '';

	/**
	 * CSS classes that identify decorative empty layers.
	 *
	 * @var array<string, true>
	 */
	private static array $decorative_empty_group_classes = array();

	/**
	 * Import a website artifact bundle as a block theme.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		$compiler_class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists( $compiler_class ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to import a website artifact.' );
		}
		// site_title (blogname) intentionally stays restricted to an explicit arg
		// or a real extracted document title; it never falls back to the host or
		// generic constant the way the theme name/slug do.
		if ( empty( $args['site_title'] ) ) {
			$site_title = Static_Site_Importer_Site_Identity::title_from_website_artifact( $artifact );
			if ( '' !== $site_title ) {
				$args['site_title'] = $site_title;
			}
		}
		$identity = Static_Site_Importer_Site_Identity::resolve(
			array(
				'site_title' => isset( $args['site_title'] ) ? (string) $args['site_title'] : '',
				'name'       => isset( $args['name'] ) ? (string) $args['name'] : '',
				'slug'       => isset( $args['slug'] ) ? (string) $args['slug'] : '',
				'artifact'   => $artifact,
				'url'        => isset( $args['url'] ) ? (string) $args['url'] : '',
			)
		);
		if ( empty( $args['name'] ) ) {
			$args['name'] = $identity['name'];
		}
		if ( empty( $args['slug'] ) ) {
			$args['slug'] = $identity['slug'];
		}
		if ( empty( $args['source_artifact_reference'] ) ) {
			$args['source_artifact_reference'] = self::source_artifact_reference_from_artifact( $artifact, $args );
		}

		$compiler_options = isset( $args['compiler_options'] ) && is_array( $args['compiler_options'] ) ? $args['compiler_options'] : array();
		$compiled = ( new $compiler_class() )->compile( $artifact, array_merge( array( 'include_conversion_report' => true ), $compiler_options ) )->toArray();
		if ( ! is_array( $compiled ) ) {
			return new WP_Error( 'static_site_importer_invalid_transformer_result', 'Blocks Engine php-transformer returned an invalid result.' );
		}
		$plan = isset( $compiled['source_reports']['wordpress_site_plan'] ) && is_array( $compiled['source_reports']['wordpress_site_plan'] ) ? $compiled['source_reports']['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			$diagnostics = isset( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) && is_array( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) ? wp_json_encode( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) : '';
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce a WordPress site plan.' . ( false !== $diagnostics ? ' ' . $diagnostics : '' ), $compiled );
		}
		if ( ! empty( $args['fail_on_quality'] ) && empty( $plan['quality']['pass'] ) ) {
			return new WP_Error( 'static_site_importer_quality_gate_failed', 'Website artifact did not pass the canonical plan quality gate.', array( 'quality' => $plan['quality'] ?? array(), 'diagnostics' => $plan['diagnostics'] ?? array() ) );
		}
		$lifecycle = self::prepare_wordpress_site_plan_lifecycle( $plan, $args );
		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}

		$theme_dir = trailingslashit( get_theme_root() ) . $args['slug'];
		$report_destinations = array( $theme_dir . '/static-site-importer-manifest.json' );
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$report_destinations = array_merge( $report_destinations, array( $theme_dir . '/import-report.json', $theme_dir . '/import-validation-result.json', $theme_dir . '/finding-packets.json' ) );
		}
		if ( ! empty( $args['report'] ) ) {
			$report_destinations[] = (string) $args['report'];
			$report_destinations[] = trailingslashit( dirname( (string) $args['report'] ) ) . 'import-validation-result.json';
			$report_destinations[] = trailingslashit( dirname( (string) $args['report'] ) ) . 'finding-packets.json';
		}
		$args['report_destinations'] = $report_destinations;
		$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			$receipt = isset( $prepared['receipt'] ) && is_array( $prepared['receipt'] ) ? $prepared['receipt'] : array();
			$error   = $receipt['errors'][0] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan destination preflight failed.' ), $receipt );
		}
		$binding_preflight = self::preflight_runtime_entity_binding_anchors( $prepared['resolved'] ?? array(), $lifecycle, $args );
		if ( is_wp_error( $binding_preflight ) ) {
			return $binding_preflight;
		}
		$dependencies = self::materialize_prepared_dependencies( $lifecycle, $args );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}
		$entity_result = self::materialize_prepared_entities( $lifecycle, $args );
		$entities      = $entity_result['reports'];
		if ( null !== $entity_result['error'] ) {
			$error = $entity_result['error'];
			return new WP_Error( $error['code'], $error['message'], array( 'status' => 'partial', 'runtime_lifecycle' => $lifecycle, 'dependencies' => $dependencies, 'entities' => $entities ) );
		}
		$bindings = self::runtime_entity_bindings( $lifecycle, $entities );
		if ( is_wp_error( $bindings ) ) {
			return new WP_Error( $bindings->get_error_code(), $bindings->get_error_message(), array( 'status' => 'partial', 'runtime_lifecycle' => $lifecycle, 'dependencies' => $dependencies, 'entities' => $entities ) );
		}
		$prepared['args']['runtime_entity_bindings'] = $bindings;
		$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $prepared );
		$receipt['completed']['runtime_declarations']['dependencies'] = $dependencies;
		$receipt['completed']['runtime_declarations']['entities'] = $entities;
		$receipt['runtime_lifecycle'] = $lifecycle;
		if ( 'completed' !== $receipt['status'] ) {
			$error = $receipt['errors'][0] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan materialization failed.' ), $receipt );
		}
		try {
			return self::public_result_from_wordpress_site_plan_receipt( $receipt, $args, $lifecycle, $dependencies, $entities );
		} catch ( Throwable $error ) {
			$receipt['status'] = 'partial';
			$receipt['errors'][] = array( 'code' => 'static_site_importer_projection_write_failed', 'message' => $error->getMessage() );
			return new WP_Error( 'static_site_importer_projection_write_failed', 'Website materialization completed partially because a public projection could not be written.', $receipt );
		}
	}

	/**
	 * Project canonical materialization facts into the established public result envelope.
	 *
	 * @param array<string,mixed> $receipt  Materialization receipt.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function public_result_from_wordpress_site_plan_receipt( array $receipt, array $args, array $lifecycle = array(), array $dependencies = array(), array $entities = array() ): array {
		$plan        = $receipt['plan'];
		$theme        = $receipt['theme'];
		$diagnostics  = isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array();
		$quality      = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$entity_lifecycle = array( 'status' => $lifecycle['status'] ?? 'not_requested', 'entities' => $entities, 'dependencies' => $dependencies );
		$diagnostics = array_merge( $diagnostics, $lifecycle['diagnostics'] ?? array() );
		$report       = array(
			'schema'         => 'static-site-importer/import-report/v1',
			'import_run_id'  => self::import_run_id( $args ),
			'blocks_engine'  => array(
				'transformer'         => self::transformer_provenance(),
				'wordpress_site_plan' => $plan,
			),
			'quality'        => $quality,
			'diagnostics'    => $diagnostics,
			'entity_lifecycle' => $entity_lifecycle,
			'generated_theme' => array(
				'wordpress_site_plan' => $plan,
				'document_metadata'   => self::document_metadata_from_plan_receipt( $plan ),
				'template_parts'      => array_map( static fn( array $part ): array => array( 'path' => 'parts/' . $part['slug'] . '.html', 'content' => $part['resolved_block_markup'] ), $plan['template_parts'] ),
				'block_documents'     => array_map( static function ( array $page ) use ( $receipt ): array {
					$materialized = $receipt['completed']['materialized_pages'][ $page['source_path'] ]['block_markup'] ?? $page['resolved_block_markup'];
					$document = array( 'path' => 'posts/page-' . ( ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'] ) . '.post_content', 'content' => $materialized );
					if ( isset( $page['core_html_block_count'] ) ) {
						$document['core_html_block_count'] = $page['core_html_block_count'];
					}
					return $document;
				}, $plan['pages'] ),
			),
			'source_documents' => array(
				'source'                       => 'blocks_engine',
				'blocks_engine_document_count' => count( $plan['pages'] ),
				'blocks_engine_documents'      => array_map( static fn( array $page ): array => array( 'source_path' => $page['source_path'], 'slug' => ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'], 'permalink' => ! empty( $page['entrypoint'] ) ? '/' : '/' . $page['slug'] . '/' ), $plan['pages'] ),
				'counts_by_format'             => array( 'html' => count( $plan['pages'] ), 'markdown' => 0, 'mdx' => 0 ),
			),
		);
		$report['source_artifact'] = array( 'hash' => (string) ( $args['artifact_hash'] ?? $plan['source']['source_hash'] ) );
		$artifact = array_merge(
			isset( $args['source_artifact_reference'] ) && is_array( $args['source_artifact_reference'] ) ? $args['source_artifact_reference'] : array(),
			array_filter( array( 'schema' => $plan['source']['schema'] ?? null, 'source_hash' => $plan['source']['source_hash'] ?? null, 'entry_path' => $plan['source']['entry_path'] ?? null ) )
		);
		$artifact['hash'] = (string) ( $args['artifact_hash'] ?? $artifact['hash'] ?? $plan['source']['source_hash'] );
		$manifest = array(
			'schema'        => 'static-site-importer/source-of-truth-manifest/v1',
			'version'       => 1,
			'import_run_id' => $report['import_run_id'],
			'artifact'      => array_merge( $artifact, array( 'provenance' => $plan['source']['provenance'] ) ),
			'manifest_path' => 'static-site-importer-manifest.json',
			'generated_theme' => array( 'slug' => $theme['slug'], 'dir' => $theme['dir'] ),
			'desired'       => array( 'pages' => array(), 'files' => array_merge( array_map( static fn( array $write ): array => array( 'path' => $write['target_path'], 'kind' => $write['kind'] ), $plan['writes'] ), array( array( 'path' => 'static-site-importer-manifest.json', 'kind' => 'ssi_manifest' ) ) ), 'assets' => array_map( static fn( array $asset ): array => array( 'source_path' => $asset['source_path'], 'theme_path' => $asset['target_path'] ), $plan['assets'] ) ),
		);
		foreach ( $plan['pages'] as $page ) {
			$source_path = $page['source_path'];
			$id = (int) ( $receipt['completed']['pages'][ $source_path ] ?? 0 );
			$match = null;
			foreach ( $receipt['existing_matches']['pages'] ?? array() as $candidate ) {
				if ( $source_path === ( $candidate['source_path'] ?? '' ) ) {
					$match = $candidate;
					break;
				}
			}
			$manifest['desired']['pages'][] = array(
				'source_path'               => $source_path,
				'materialized_post_id'      => $id,
				'reconciliation_identity'   => $page['reconciliation_identity'],
				'content_hash'              => $receipt['completed']['materialized_pages'][ $source_path ]['content_hash'] ?? $page['content_hash'],
				'route'                     => $page['route']['path'],
				'permalink'                 => $match['permalink'] ?? $page['route']['path'],
				'slug'                      => $page['slug'],
				'post_type'                 => $page['post_type'],
				'protected'                 => ! empty( $match['protected'] ),
				'provenance_meta_key'       => ! empty( $match['protected'] ) ? '' : '_static_site_importer_provenance',
			);
		}
		$manifest['existing_matches'] = $receipt['existing_matches'] ?? array( 'pages' => array() );
		$cleanup = self::cleanup_stale_generated_theme_files( $theme['dir'], $manifest, $args );
		if ( is_wp_error( $cleanup ) ) {
			throw new RuntimeException( $cleanup->get_error_message() );
		}
		$manifest['cleanup'] = $cleanup;
		$report['source_of_truth'] = $manifest;
		$visual_parity = array( 'schema' => 'static-site-importer/visual-parity-artifacts/v1', 'status' => 'pending', 'owner' => 'codebox_runtime', 'artifacts' => array( 'import_report' => array( 'status' => 'captured', 'ref' => array( 'artifact_name' => 'import-report.json' ) ), 'source_screenshot' => array( 'status' => 'pending' ), 'visual_diff' => array( 'capture_state' => 'not_captured' ) ) );
		$report['visual_parity_artifacts'] = $visual_parity;
		$validation = array( 'schema' => 'blocks-engine/import-validation-result/v1', 'artifact_type' => 'ImportValidationResult', 'status' => ! empty( $quality['pass'] ) ? 'passed' : 'failed', 'diagnostics' => $diagnostics, 'quality' => $quality, 'visual_parity_artifacts' => $visual_parity );
		$findings   = array( 'schema' => 'blocks-engine/finding-packets/v1', 'artifact_type' => 'FindingPacketSet', 'findings' => $diagnostics );
		$theme_dir  = $theme['dir'];
		$manifest_path = $theme_dir . '/static-site-importer-manifest.json';
		self::write_plan_projection( $manifest_path, $manifest );
		$report_path = '';
		$validation_path = '';
		$findings_path = '';
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$report_path = $theme_dir . '/import-report.json';
			$validation_path = $theme_dir . '/import-validation-result.json';
			$findings_path = $theme_dir . '/finding-packets.json';
			self::write_plan_projection( $report_path, $report );
			self::write_plan_projection( $validation_path, $validation );
			self::write_plan_projection( $findings_path, $findings );
		}
		$external_report_path = '';
		$external_validation_result_path = '';
		$external_finding_packets_path = '';
		if ( '' !== trim( (string) ( $args['report'] ?? '' ) ) ) {
			$external_report_path = (string) $args['report'];
			$external_dir = dirname( $external_report_path );
			$external_validation_result_path = trailingslashit( $external_dir ) . 'import-validation-result.json';
			$external_finding_packets_path = trailingslashit( $external_dir ) . 'finding-packets.json';
			self::write_plan_projection( $external_report_path, $report );
			self::write_plan_projection( $external_validation_result_path, $validation );
			self::write_plan_projection( $external_finding_packets_path, $findings );
		}
		return array(
			'theme_slug'               => $theme['slug'],
			'theme_name'               => isset( $args['name'] ) ? (string) $args['name'] : $theme['slug'],
			'theme_dir'                => $theme['dir'],
			'report_path'              => $report_path,
			'validation_result_path'   => $validation_path,
			'finding_packets_path'     => $findings_path,
			'external_report_path'     => $external_report_path,
			'external_validation_result_path' => $external_validation_result_path,
			'external_finding_packets_path' => $external_finding_packets_path,
			'manifest_path'            => $manifest_path,
			'pages'                    => $receipt['completed']['pages'],
			'import_report'            => $report,
			'import_report_summary'    => array( 'status' => $receipt['status'], 'diagnostic_count' => count( $diagnostics ) ),
			'import_validation_result' => $validation,
			'finding_packets'          => $findings,
			'quality'                  => $quality,
			'source_of_truth'          => $manifest,
			'progress_events'          => array(
				array( 'schema' => 'wp-codebox/live-progress-event/v1', 'phase' => 'ssi.materialization.completed', 'progress' => array( 'percent' => 100 ) ),
				array( 'schema' => 'wp-codebox/live-progress-event/v1', 'phase' => 'ssi.reporting.completed', 'progress' => array( 'percent' => 100 ) ),
				array( 'schema' => 'wp-codebox/live-progress-event/v1', 'phase' => 'ssi.saved.completed', 'progress' => array( 'percent' => 100 ) ),
			),
			'materialization_receipt'  => $receipt,
		);
	}

	/**
	 * Report the installed Blocks Engine compiler identity without projecting its result.
	 *
	 * @return array{package:string,version:string,reference:string}
	 */
	private static function transformer_provenance(): array {
		$package   = 'automattic/blocks-engine-php-transformer';
		$version   = '';
		$reference = '';
		$class     = '\\Composer\\InstalledVersions';

		if ( class_exists( $class ) && $class::isInstalled( $package ) ) {
			try {
				$version = (string) ( $class::getPrettyVersion( $package ) ?: $version );
				if ( method_exists( $class, 'getReference' ) ) {
					$reference = (string) ( $class::getReference( $package ) ?: $reference );
				}
			} catch ( Throwable ) {
				// Missing Composer metadata remains absent so downstream evidence is incomplete.
			}
		}

		return array(
			'package'   => $package,
			'version'   => $version,
			'reference' => $reference,
		);
	}

	/**
	 * Normalize and validate every SSI-owned runtime declaration before WordPress writes.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function prepare_wordpress_site_plan_lifecycle( array $plan, array $args ) {
		$lifecycle = array( 'status' => 'not_requested', 'dependencies' => array(), 'entities' => array(), 'diagnostics' => array() );
		$declarations = isset( $plan['runtime_declarations'] ) && is_array( $plan['runtime_declarations'] ) ? $plan['runtime_declarations'] : array();
		foreach ( $declarations as $declaration ) {
			if ( ! is_array( $declaration ) ) {
				continue;
			}
			$kind = (string) ( $declaration['kind'] ?? '' );
			$key = (string) ( $declaration['reconciliation_identity'] ?? '' );
			if ( 'asset_publication' === $kind ) {
				continue;
			}
			$name = (string) ( $declaration[ 'entity_collection' === $kind ? 'type' : 'capability' ] ?? '' );
			$capability = self::runtime_declaration_capability( $kind, $name );
			$required = self::runtime_declaration_is_required( $declaration, $declarations );
			if ( '' === $capability ) {
				if ( $required ) {
					return new WP_Error( 'static_site_importer_unsupported_required_runtime_declaration', 'SSI cannot materialize required runtime declaration: ' . $name . '.', array( 'status' => 'rejected', 'declaration_id' => $key ) );
				}
				$lifecycle['diagnostics'][] = array( 'code' => 'unsupported_optional_runtime_declaration', 'severity' => 'warning', 'reconciliation_identity' => $key, 'message' => 'SSI has no configured adapter for optional declaration ' . $name . '.' );
				continue;
			}
			$adapter = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( $capability );
			if ( empty( $adapter ) ) {
				return new WP_Error( 'static_site_importer_runtime_provider_unavailable', 'SSI has no configured provider for runtime capability: ' . $capability . '.', array( 'status' => 'rejected', 'declaration_id' => $key ) );
			}
			if ( 'dependency' === $kind ) {
				$lifecycle['dependencies'][ $key ] = array( 'adapter' => $adapter, 'declaration' => $declaration, 'required' => $required );
				continue;
			}
			if ( 'entity_collection' === $kind ) {
				$entities = isset( $declaration['payload']['entities'] ) && is_array( $declaration['payload']['entities'] ) ? $declaration['payload']['entities'] : array();
				$manifest = 'shop' === $capability ? array( 'schema_version' => 1, 'products' => $entities ) : array( 'forms' => $entities );
				$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, $manifest );
				if ( ! empty( $validation['errors'] ) ) {
					return new WP_Error( 'static_site_importer_runtime_entity_invalid', 'Runtime entity declaration failed SSI provider validation.', array( 'status' => 'rejected', 'declaration_id' => $key, 'errors' => $validation['errors'] ) );
				}
				$lifecycle['entities'][ $key ] = array( 'adapter' => $adapter, 'manifest' => $manifest, 'declaration' => $declaration, 'required' => $required );
			}
		}
		if ( isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) && ! empty( $args['products_manifest'] ) ) {
			$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
			$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, $args['products_manifest'] );
			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error( 'static_site_importer_products_manifest_invalid', 'Caller products_manifest failed SSI provider validation.', array( 'status' => 'rejected', 'errors' => $validation['errors'] ) );
			}
			$lifecycle['dependencies']['caller_override'] = array( 'adapter' => $adapter, 'declaration' => array( 'reconciliation_identity' => 'caller_override', 'kind' => 'dependency' ) );
			$lifecycle['entities']['caller_override'] = array( 'adapter' => $adapter, 'manifest' => $args['products_manifest'], 'declaration' => array( 'reconciliation_identity' => 'caller_override', 'kind' => 'entity_collection' ) );
			$lifecycle['status'] = 'caller_override';
		} elseif ( ! empty( $lifecycle['dependencies'] ) || ! empty( $lifecycle['entities'] ) ) {
			$lifecycle['status'] = 'runtime_declarations';
		}
		return $lifecycle;
	}

	private static function runtime_declaration_is_required( array $declaration, array $declarations ): bool {
		$key = (string) ( $declaration['kind'] ?? '' ) . ':' . (string) ( $declaration['type'] ?? $declaration['capability'] ?? '' );
		if ( ! empty( $declaration['required_for'] ) ) {
			return true;
		}
		foreach ( $declarations as $candidate ) {
			if ( is_array( $candidate ) && in_array( $key, $candidate['required_for'] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function runtime_declaration_capability( string $kind, string $name ): string {
		$name = strtolower( $name );
		if ( 'dependency' === $kind && in_array( $name, array( 'shop', 'form' ), true ) ) {
			return $name;
		}
		if ( 'entity_collection' === $kind && in_array( $name, array( 'product', 'products' ), true ) ) {
			return 'shop';
		}
		if ( 'entity_collection' === $kind && in_array( $name, array( 'form', 'forms' ), true ) ) {
			return 'form';
		}
		return '';
	}

	/** @return array<string,mixed>|WP_Error */
	private static function materialize_prepared_dependencies( array $lifecycle, array $args ) {
		$reports = array();
		foreach ( $lifecycle['dependencies'] as $id => $prepared ) {
			$adapter = $prepared['adapter'];
			$waived = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] );
			if ( $waived ) {
				$reports[ $id ] = array( 'status' => 'waived', 'provider' => $adapter['provider'] ?? '' );
				continue;
			}
			if ( empty( $args['materialize_dependencies'] ) && ! Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter ) && ! empty( $prepared['required'] ) ) {
				return new WP_Error( 'static_site_importer_required_runtime_dependency_missing', 'A required runtime dependency is unavailable and dependency materialization is disabled.', array( 'status' => 'rejected', 'declaration_id' => $id ) );
			}
			$reports[ $id ] = ! empty( $args['materialize_dependencies'] ) ? Static_Site_Importer_Entity_Materializer_Registry::materialize_plugin_dependencies( $adapter ) : array( 'status' => 'available' );
			if ( ! Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter ) && ! empty( $prepared['required'] ) ) {
				return new WP_Error( 'static_site_importer_required_runtime_dependency_missing', 'SSI could not prepare a required runtime dependency.', array( 'status' => 'partial', 'completed_declaration_ids' => array_keys( $reports ) ) );
			}
		}
		return $reports;
	}

	/** @return array{reports:array<string,mixed>,error:?array{code:string,message:string}} */
	private static function materialize_prepared_entities( array $lifecycle, array $args ): array {
		$reports = array();
		$required = array_filter( $lifecycle['entities'], static fn( array $prepared ): bool => ! empty( $prepared['required'] ) );
		if ( empty( $args['seed_entities'] ) && empty( $required ) ) {
			return array( 'reports' => $reports, 'error' => null );
		}
		foreach ( $lifecycle['entities'] as $id => $prepared ) {
			$adapter = $prepared['adapter'];
			if ( ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] ) ) {
				$reports[ $id ] = array( 'status' => 'waived', 'provider' => $adapter['provider'] ?? '' );
				continue;
			}
			$report = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $prepared['manifest'] );
			if ( is_wp_error( $report ) ) {
				$reports[ $id ] = array( 'status' => 'error', 'reason' => $report->get_error_code() );
				return array( 'reports' => $reports, 'error' => array( 'code' => (string) $report->get_error_code(), 'message' => $report->get_error_message() ) );
			}
			$reports[ $id ] = $report;
			$counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
			$expected = count( isset( $prepared['manifest']['products'] ) && is_array( $prepared['manifest']['products'] ) ? $prepared['manifest']['products'] : ( $prepared['manifest']['forms'] ?? array() ) );
			$completed = array_sum( array_map( 'intval', array_intersect_key( $counts, array_flip( array( 'created', 'updated', 'mapped', 'skipped' ) ) ) ) );
			if ( in_array( $report['status'] ?? '', array( 'failed', 'error' ), true ) || ! empty( $counts['failed'] ) || ! empty( $counts['error'] ) || ( ! empty( $prepared['required'] ) && $completed < $expected ) ) {
				$code = isset( $report['code'] ) && is_scalar( $report['code'] ) ? (string) $report['code'] : 'static_site_importer_entity_materialization_failed';
				$message = isset( $report['error'] ) && is_scalar( $report['error'] ) ? (string) $report['error'] : ( isset( $report['reason'] ) && is_scalar( $report['reason'] ) && '' !== (string) $report['reason'] ? (string) $report['reason'] : 'Runtime entity materialization failed for declaration: ' . $id . '.' );
				return array( 'reports' => $reports, 'error' => array( 'code' => $code, 'message' => $message ) );
			}
		}
		return array( 'reports' => $reports, 'error' => null );
	}

	/** Build exact provider-owned block replacements without consulting diagnostics. */
	private static function runtime_entity_bindings( array $lifecycle, array $reports ) {
		$bindings = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$report   = isset( $reports[ $declaration_id ] ) && is_array( $reports[ $declaration_id ] ) ? $reports[ $declaration_id ] : array();
			if ( 'waived' === ( $report['status'] ?? '' ) ) {
				continue;
			}
			$entity_key = isset( $manifest['products'] ) ? 'products' : 'forms';
			$manifest_entities = isset( $manifest[ $entity_key ] ) && is_array( $manifest[ $entity_key ] ) ? $manifest[ $entity_key ] : array();
			$result_entities = isset( $report[ $entity_key ] ) && is_array( $report[ $entity_key ] ) ? $report[ $entity_key ] : array();
			$results = array();
			foreach ( $result_entities as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}
				$key = 'products' === $entity_key ? (string) ( $result['slug'] ?? '' ) : (string) ( $result['source_path'] ?? '' ) . "\n" . (string) ( $result['selector'] ?? '' );
				$results[ $key ] = $result;
			}
			foreach ( $manifest_entities as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_bindings = isset( $entity['bindings'] ) && is_array( $entity['bindings'] ) ? $entity['bindings'] : array();
				if ( empty( $entity_bindings ) ) {
					continue;
				}
				$key = 'products' === $entity_key ? (string) ( $entity['slug'] ?? '' ) : (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				$result = $results[ $key ] ?? array();
				$replacement = Static_Site_Importer_Entity_Materializer_Registry::binding_block_markup( $prepared['adapter'], $entity, $result );
				if ( '' === $replacement ) {
					return new WP_Error( 'static_site_importer_runtime_binding_unresolved', 'A required provider entity did not produce binding block markup.', array( 'declaration_id' => $declaration_id, 'entity_key' => $key ) );
				}
				foreach ( $entity_bindings as $binding ) {
					$bindings[] = array(
						'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
						'source_path'              => $binding['source_path'],
						'search_block_markup'      => $binding['search_block_markup'],
						'replacement_block_markup' => $replacement,
						'occurrence'               => $binding['occurrence'],
						'role'                     => $binding['role'],
						'declaration_id'           => $declaration_id,
						'reconciliation_identity'  => hash( 'sha256', "static-site-importer/runtime-entity-binding/v1\n{$declaration_id}\n{$binding['source_path']}\n{$binding['occurrence']}\n" . hash( 'sha256', $binding['search_block_markup'] ) ),
					);
				}
			}
		}
		return $bindings;
	}

	/** Verify every declared source anchor before providers create or update entities. */
	private static function preflight_runtime_entity_binding_anchors( array $plan, array $lifecycle, array $args ) {
		$pages = array();
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			if ( is_array( $page ) && is_string( $page['source_path'] ?? null ) ) {
				$pages[ $page['source_path'] ] = $page;
			}
		}
		$claims = array();
		$ranges = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$entities = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() );
			foreach ( $entities as $entity ) {
				$entity_bindings = is_array( $entity ) && is_array( $entity['bindings'] ?? null ) ? $entity['bindings'] : array();
				foreach ( $entity_bindings as $binding ) {
					if ( empty( $binding ) ) {
						continue;
					}
					$claim = $binding['source_path'] . "\n" . hash( 'sha256', $binding['search_block_markup'] ) . "\n" . $binding['occurrence'];
					if ( isset( $claims[ $claim ] ) ) {
						return new WP_Error( 'static_site_importer_runtime_binding_claim_conflict', 'Two provider entities claim the same canonical source-page binding occurrence.', array( 'status' => 'rejected', 'declaration_id' => $declaration_id ) );
					}
					$claims[ $claim ] = true;
					$page = $pages[ $binding['source_path'] ] ?? array();
					if ( ! empty( $page['skip_materialization'] ) ) {
						return new WP_Error( 'static_site_importer_runtime_binding_target_protected', 'A provider binding targets a protected page that cannot be materialized.', array( 'status' => 'rejected', 'declaration_id' => $declaration_id ) );
					}
					$matches = substr_count( (string) ( $page['resolved_block_markup'] ?? '' ), (string) $binding['search_block_markup'] );
					if ( $matches < (int) $binding['occurrence'] ) {
						return new WP_Error( 'static_site_importer_runtime_binding_cardinality_mismatch', 'A canonical provider binding does not have its declared source-page occurrence.', array( 'status' => 'rejected', 'declaration_id' => $declaration_id ) );
					}
					$content = (string) $page['resolved_block_markup'];
					$position = 0;
					for ( $occurrence = 0; $occurrence < (int) $binding['occurrence']; ++$occurrence ) {
						$position = strpos( $content, $binding['search_block_markup'], $position );
						if ( $occurrence + 1 < (int) $binding['occurrence'] ) {
							$position += strlen( $binding['search_block_markup'] );
						}
					}
					$end = $position + strlen( $binding['search_block_markup'] );
					foreach ( $ranges[ $binding['source_path'] ] ?? array() as $range ) {
						if ( $position < $range['end'] && $end > $range['start'] ) {
							return new WP_Error( 'static_site_importer_runtime_binding_claim_conflict', 'Provider entity bindings claim overlapping canonical source-page ranges.', array( 'status' => 'rejected', 'declaration_id' => $declaration_id ) );
						}
					}
					$ranges[ $binding['source_path'] ][] = array( 'start' => $position, 'end' => $end );
				}
			}
		}
		return true;
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	private static function document_metadata_from_plan_receipt( array $plan ): array {
		foreach ( $plan['pages'] as $page ) {
			if ( ! empty( $page['entrypoint'] ) && isset( $page['document_metadata'] ) && is_array( $page['document_metadata'] ) ) {
				$metadata = array_merge( array( 'schema' => 'static-site-importer/document-metadata/v1' ), $page['document_metadata'] );
				foreach ( array( 'links' => 'href', 'scripts' => 'src' ) as $kind => $field ) {
					if ( ! isset( $metadata[ $kind ] ) || ! is_array( $metadata[ $kind ] ) ) {
						continue;
					}
					foreach ( $metadata[ $kind ] as &$declaration ) {
						if ( is_array( $declaration ) && isset( $declaration['resolved_url'] ) ) {
							$declaration[ $field ] = $declaration['resolved_url'];
						}
					}
					unset( $declaration );
				}
				return $metadata;
			}
		}
		return array( 'schema' => 'static-site-importer/document-metadata/v1' );
	}

	/** @param array<string,mixed> $payload */
	private static function write_plan_projection( string $path, array $payload ): void {
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json || false === file_put_contents( $path, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes preflighted public import artifacts.
			throw new RuntimeException( 'Failed to write a preflighted import artifact.' );
		}
	}

	/**
	 * Materialize a compiled website artifact directly into WordPress theme artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	/**
	 * Build the canonical progress timeline returned to host chat/Codebox callers.
	 *
	 * @param string              $import_run_id Import run id.
	 * @param string              $theme_slug    Theme slug.
	 * @param array<string,int>   $page_ids      Materialized page IDs.
	 * @param array<string,string> $writes        Theme file writes.
	 * @param array<string,mixed>  $quality       Quality summary.
	 * @param array<string,mixed>  $validation    Validation result.
	 * @param string              $report_path   External report path.
	 * @return array<int,array<string,mixed>>
	 */
	private static function import_progress_events( string $import_run_id, string $theme_slug, array $page_ids, array $writes, array $quality, array $validation, string $report_path ): array {
		$now               = gmdate( 'c' );
		$page_count        = count( $page_ids );
		$file_count        = count( $writes );
		$diagnostic_count  = isset( $validation['diagnostics'] ) && is_array( $validation['diagnostics'] ) ? count( $validation['diagnostics'] ) : 0;
		$quality_passed    = empty( $quality['fail_import'] );
		$review_pending    = ! $quality_passed;
		$common            = array(
			'schema'        => 'wp-codebox/live-progress-event/v1',
			'run_id'        => $import_run_id,
			'source_schema' => 'static-site-importer/materialization-progress/v1',
			'timestamp'     => $now,
		);

		return array(
			array_merge(
				$common,
				array(
					'phase'    => 'ssi.materialization.completed',
					'status'   => 'succeeded',
					'label'    => 'Materialized WordPress content',
					'progress' => array(
						'current'   => $page_count,
						'completed' => $page_count,
						'total'     => $page_count,
						'percent'   => 100,
						'unit'      => 'pages',
					),
					'detail'   => array(
						'theme_slug' => $theme_slug,
						'file_count' => $file_count,
					),
				)
			),
			array_merge(
				$common,
				array(
					'phase'       => 'ssi.validation.completed',
					'status'      => $quality_passed ? 'succeeded' : 'failed',
					'label'       => $quality_passed ? 'Validation passed' : 'Validation needs review',
					'diagnostics' => array(
						'count' => $diagnostic_count,
					),
				)
			),
			array_merge(
				$common,
				array(
					'phase'     => $review_pending ? 'ssi.review.pending' : 'ssi.saved.completed',
					'status'    => $review_pending ? 'running' : 'succeeded',
					'label'     => $review_pending ? 'Review pending' : 'Saved to WordPress',
					'artifacts' => array_filter(
						array(
							'import_report' => '' !== $report_path ? array(
								'path' => $report_path,
								'kind' => 'json',
							) : null,
						)
					),
				)
			),
		);
	}

	/**
	 * Remove generated theme files from the previous SSI manifest when absent from the new desired manifest.
	 *
	 * @param string              $theme_dir        Theme directory.
	 * @param array<string,mixed> $current_manifest Current source-of-truth manifest.
	 * @param array<string,mixed> $args             Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function cleanup_stale_generated_theme_files( string $theme_dir, array $current_manifest, array $args = array() ) {
		$previous_manifest_path = trailingslashit( $theme_dir ) . 'static-site-importer-manifest.json';
		$cleanup                = array(
			'enabled'                => true,
			'policy'                 => 'previous_manifest_file_targets_only',
			'previous_manifest_path' => 'static-site-importer-manifest.json',
			'deleted'                => array(),
			'skipped'                => array(),
			'pages'                  => array(
				'enabled'     => true,
				'policy'      => 'previous_manifest_provenance_report_first',
				'action'      => self::stale_page_action( $args ),
				'stale_pages' => array(),
				'skipped'     => array(),
				'counts'      => array(
					'stale_pages'   => 0,
					'pages_drafted' => 0,
					'pages_deleted' => 0,
					'skipped'       => 0,
				),
				'notes'       => array( 'Stale SSI-owned pages are reported by default. Drafting requires explicit stale_page_action=draft; deletion is not supported here.' ),
			),
			'counts'                 => array(
				'deleted'       => 0,
				'skipped'       => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
			),
			'protected'              => array(
				'pages_deleted' => 0,
				'pages_drafted' => 0,
				'notes'         => array( 'Page deletion is intentionally disabled; this cleanup only removes prior SSI-generated theme files and assets.' ),
			),
		);

		if ( ! is_file( $previous_manifest_path ) ) {
			$cleanup['skipped'][] = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_missing',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}

		$previous_manifest_json = file_get_contents( $previous_manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned local manifest file.
		if ( false === $previous_manifest_json ) {
			return new WP_Error( 'static_site_importer_previous_manifest_read_failed', 'Failed to read the previous Static Site Importer manifest.' );
		}

		$previous_manifest = json_decode( $previous_manifest_json, true );
		if ( ! is_array( $previous_manifest ) || 'static-site-importer/source-of-truth-manifest/v1' !== (string) ( $previous_manifest['schema'] ?? '' ) ) {
			$cleanup['skipped'][] = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_invalid',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}

		$page_reconciliation = self::reconcile_stale_manifest_pages( $previous_manifest, $current_manifest, $cleanup['pages']['action'] );
		if ( is_wp_error( $page_reconciliation ) ) {
			return $page_reconciliation;
		}
		$cleanup['pages']                         = $page_reconciliation;
		$cleanup['protected']['pages_deleted']    = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['protected']['pages_drafted']    = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );
		$cleanup['counts']['pages_deleted']       = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['counts']['pages_drafted']       = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );

		$current_paths  = self::manifest_theme_file_paths( $current_manifest );
		$previous_paths = self::manifest_theme_file_paths( $previous_manifest );
		$stale_paths    = array_values( array_diff( array_keys( $previous_paths ), array_keys( $current_paths ) ) );

		foreach ( $stale_paths as $relative ) {
			$path = trailingslashit( $theme_dir ) . $relative;
			if ( ! file_exists( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'already_missing',
				);
				continue;
			}

			if ( ! is_file( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'not_a_file',
				);
				continue;
			}

			if ( ! wp_delete_file( $path ) ) {
				return new WP_Error( 'static_site_importer_stale_generated_file_delete_failed', sprintf( 'Failed to delete stale generated theme file: %s', $relative ) );
			}

			$cleanup['deleted'][] = array(
				'path'   => $relative,
				'reason' => 'absent_from_current_manifest',
			);
		}

		$cleanup['counts']['deleted'] = count( $cleanup['deleted'] );
		$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );

		return $cleanup;
	}

	/**
	 * Resolve the explicitly requested stale page action.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return string
	 */
	private static function stale_page_action( array $args ): string {
		$action = isset( $args['stale_page_action'] ) && is_scalar( $args['stale_page_action'] ) ? sanitize_key( (string) $args['stale_page_action'] ) : '';
		if ( '' === $action ) {
			$option = get_option( 'static_site_importer_stale_page_action', '' );
			$action = is_scalar( $option ) ? sanitize_key( (string) $option ) : '';
		}

		return 'draft' === $action ? 'draft' : 'report_only';
	}

	/**
	 * Report or draft SSI-owned pages present in the previous manifest but absent from the current desired pages.
	 *
	 * @param array<string,mixed> $previous_manifest Previous source-of-truth manifest.
	 * @param array<string,mixed> $current_manifest  Current source-of-truth manifest.
	 * @param string              $action            Reconciliation action.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function reconcile_stale_manifest_pages( array $previous_manifest, array $current_manifest, string $action ) {
		$reconciliation = array(
			'enabled'     => true,
			'policy'      => 'previous_manifest_provenance_report_first',
			'action'      => 'draft' === $action ? 'draft' : 'report_only',
			'stale_pages' => array(),
			'skipped'     => array(),
			'counts'      => array(
				'stale_pages'   => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
				'skipped'       => 0,
			),
			'notes'       => array( 'Only pages with valid Static Site Importer provenance meta are eligible. Protected pages and pages without SSI provenance are never mutated.' ),
		);

		$current_sources = array();
		$current_posts   = array();
		foreach ( self::manifest_pages( $current_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( '' !== $source_path ) {
				$current_sources[ $source_path ] = true;
			}
			if ( $post_id > 0 ) {
				$current_posts[ $post_id ] = true;
			}
		}

		foreach ( self::manifest_pages( $previous_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( '' !== $source_path && isset( $current_sources[ $source_path ] ) ) {
				continue;
			}
			if ( $post_id <= 0 || isset( $current_posts[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'reason'      => 'post_missing',
				);
				continue;
			}

			if ( Static_Site_Importer_Page_Materializer::is_protected_page( $post ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'protected_page',
				);
				continue;
			}

			$provenance = self::page_provenance( $post_id );
			if ( empty( $provenance ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'missing_static_site_importer_provenance',
				);
				continue;
			}

			$row = array(
				'post_id'         => $post_id,
				'post_type'       => (string) $post->post_type,
				'slug'            => (string) $post->post_name,
				'source_path'     => $source_path,
				'previous_status' => (string) $post->post_status,
				'action'          => 'report_only',
			);

			if ( 'draft' === $reconciliation['action'] ) {
				if ( 'draft' !== $post->post_status ) {
					$result = wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'draft',
						),
						true
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					++$reconciliation['counts']['pages_drafted'];
				}
				$row['action']     = 'drafted';
				$row['new_status'] = 'draft';
			}

			$reconciliation['stale_pages'][] = $row;
		}

		$reconciliation['counts']['stale_pages'] = count( $reconciliation['stale_pages'] );
		$reconciliation['counts']['skipped']     = count( $reconciliation['skipped'] );

		return $reconciliation;
	}

	/**
	 * Extract desired pages from a manifest.
	 *
	 * @param array<string,mixed> $manifest Source-of-truth manifest.
	 * @return array<int,array<string,mixed>>
	 */
	private static function manifest_pages( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$pages   = isset( $desired['pages'] ) && is_array( $desired['pages'] ) ? $desired['pages'] : array();

		return array_values( array_filter( $pages, 'is_array' ) );
	}

	/**
	 * Read valid Static Site Importer page provenance meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private static function page_provenance( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, '_static_site_importer_provenance', true );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$provenance = json_decode( $raw, true );
		if ( ! is_array( $provenance ) || 'static-site-importer/page-provenance/v1' !== (string) ( $provenance['schema'] ?? '' ) ) {
			return array();
		}

		return $provenance;
	}

	/**
	 * Extract safe theme-relative file paths from a source-of-truth manifest.
	 *
	 * @param array<string,mixed> $manifest Source-of-truth manifest.
	 * @return array<string,true>
	 */
	private static function manifest_theme_file_paths( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$paths   = array();

		foreach ( $desired['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$relative = self::normalize_manifest_theme_relative_path( isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}

		foreach ( $desired['assets'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$relative = self::normalize_manifest_theme_relative_path( isset( $asset['theme_path'] ) && is_scalar( $asset['theme_path'] ) ? (string) $asset['theme_path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}

		return $paths;
	}

	/**
	 * Normalize a manifest theme-relative path for safe file cleanup.
	 *
	 * @param string $path Manifest path.
	 * @return string
	 */
	private static function normalize_manifest_theme_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		if ( '' === $path || str_contains( $path, "\0" ) || str_starts_with( $path, '../' ) || str_contains( $path, '/../' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Export an imported or active block theme as a website artifact.
	 *
	 * @param array $args Export args.
	 * @return array{website_artifact:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to export a website artifact.' );
		}

		$theme_slug = isset( $args['theme_slug'] ) && '' !== trim( (string) $args['theme_slug'] ) ? sanitize_title( (string) $args['theme_slug'] ) : self::active_theme_slug();
		if ( '' === $theme_slug ) {
			return new WP_Error( 'static_site_importer_missing_theme_slug', 'A theme_slug input is required when no active theme can be detected.' );
		}

		$theme_dir = self::export_theme_dir( $theme_slug );
		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'static_site_importer_theme_not_found', sprintf( 'Theme directory not found for %s.', $theme_slug ) );
		}

		$entrypoint      = self::export_artifact_path( isset( $args['entrypoint'] ) ? (string) $args['entrypoint'] : 'website/index.html', 'website/index.html' );
		$root            = self::export_artifact_root( isset( $args['root'] ) ? (string) $args['root'] : '', $entrypoint );
		$include_pages   = $args['include_pages'] ?? true;
		$source_metadata = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$diagnostics     = array();
		$files           = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir, $root );
		if ( null !== $stylesheet ) {
			$files[] = $stylesheet;
		}

		$pages = self::export_pages( $include_pages );
		if ( empty( $pages ) ) {
			$diagnostics[] = array(
				'level'   => 'warning',
				'code'    => 'static_site_importer_export_no_pages',
				'message' => 'No published pages were available to export; generated an entrypoint from theme templates only.',
			);
			$files[] = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page' ), $theme_slug, null !== $stylesheet ),
				'document',
				'entrypoint'
			);
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			foreach ( $pages as $page ) {
				$page_id   = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front  = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$path      = $is_front ? $entrypoint : self::export_page_artifact_path( $page, $root );
				$template  = $is_front ? 'front-page' : 'page';
				$page_html = self::blocks_to_html( isset( $page->post_content ) ? (string) $page->post_content : '' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
					'document',
					$is_front ? 'entrypoint' : 'page',
					array(
						'post_id'   => $page_id,
						'post_name' => isset( $page->post_name ) ? (string) $page->post_name : '',
					)
				);

				$first = false;
			}
		}

		$files = array_merge( $files, self::export_theme_asset_files( $theme_dir, $root, $diagnostics ) );

		$import_report = self::read_theme_import_report( $theme_dir );
		if ( ! empty( $import_report ) ) {
			$files[] = self::export_file_entry(
				$root . '/import-report.json',
				self::json_encode_pretty( $import_report ),
				'metadata',
				'report',
				array(
					'source' => array(
						'type' => 'static-site-importer-import-report',
					),
				)
			);

			$source_documents = isset( $import_report['source_documents'] ) && is_array( $import_report['source_documents'] ) ? $import_report['source_documents'] : array();
			if ( ! empty( $source_documents ) ) {
				$files[] = self::export_file_entry(
					$root . '/source-documents.json',
					self::json_encode_pretty( $source_documents ),
					'metadata',
					'source-document',
					array(
						'source' => array(
							'type' => 'static-site-importer-source-documents',
						),
					)
				);
			}
		}

		$report = array(
			'status'          => 'completed',
			'theme_slug'      => $theme_slug,
			'theme_dir'       => $theme_dir,
			'root'            => $root,
			'entrypoint'      => $entrypoint,
			'file_count'      => count( $files ),
			'page_count'      => count( $pages ),
			'source_metadata' => $source_metadata,
			'diagnostics'     => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		$website_artifact = self::export_website_artifact( $theme_slug, $root, $entrypoint, $files, $report, $source_metadata );

		return array(
			'website_artifact' => $website_artifact,
		);
	}

	/**
	 * Resolve the active theme slug.
	 *
	 * @return string
	 */
	private static function active_theme_slug(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			return sanitize_title( (string) get_stylesheet() );
		}

		return '';
	}

	/**
	 * Resolve a theme directory for export.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function export_theme_dir( string $theme_slug ): string {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $theme_slug );
			if ( is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists() && method_exists( $theme, 'get_stylesheet_directory' ) ) {
				return (string) $theme->get_stylesheet_directory();
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			return trailingslashit( get_theme_root( $theme_slug ) ) . $theme_slug;
		}

		return '';
	}

	/**
	 * Get published pages selected by include_pages.
	 *
	 * @param mixed $include_pages Include pages argument.
	 * @return array<int,object>
	 */
	private static function export_pages( $include_pages ): array {
		if ( false === $include_pages || ! function_exists( 'get_posts' ) ) {
			$page = self::export_front_page();
			return null === $page ? array() : array( $page );
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $pages ) ) {
			return array();
		}

		if ( ! is_array( $include_pages ) || empty( $include_pages ) ) {
			return self::order_front_page_first( array_values( $pages ) );
		}

		$allowed = array_fill_keys( array_map( 'strval', $include_pages ), true );
		return self::order_front_page_first( array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $allowed ): bool {
					$page_id   = isset( $page->ID ) ? (string) $page->ID : '';
					$page_slug = isset( $page->post_name ) ? (string) $page->post_name : '';
					return isset( $allowed[ $page_id ] ) || isset( $allowed[ $page_slug ] );
				}
			)
		) );
	}

	/**
	 * Order exported pages so the configured front page becomes the entrypoint.
	 *
	 * @param array<int,object> $pages Pages.
	 * @return array<int,object>
	 */
	private static function order_front_page_first( array $pages ): array {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id <= 0 ) {
			return $pages;
		}

		usort(
			$pages,
			static function ( object $left, object $right ) use ( $front_page_id ): int {
				$left_is_front  = isset( $left->ID ) && (int) $left->ID === $front_page_id;
				$right_is_front = isset( $right->ID ) && (int) $right->ID === $front_page_id;
				if ( $left_is_front === $right_is_front ) {
					return 0;
				}

				return $left_is_front ? -1 : 1;
			}
		);

		return $pages;
	}

	/**
	 * Get the configured front page post.
	 *
	 * @return object|null
	 */
	private static function export_front_page(): ?object {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id > 0 && function_exists( 'get_post' ) ) {
			$page = get_post( $front_page_id );
			if ( is_object( $page ) ) {
				return $page;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		return $pages[0] ?? null;
	}

	/**
	 * Get the configured front page ID.
	 *
	 * @return int
	 */
	private static function export_front_page_id(): int {
		if ( ! function_exists( 'get_option' ) || 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return (int) get_option( 'page_on_front' );
	}

	/**
	 * Convert template parts around exported page content.
	 *
	 * @param string $theme_dir Theme directory.
	 * @param string $template  Template slug.
	 * @return array{before:string,after:string}
	 */
	private static function export_theme_chrome_html( string $theme_dir, string $template ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html' );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html' );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = self::blocks_to_html( $template_html );
			if ( '' !== trim( $converted_template ) && '' === trim( $before . $after ) ) {
				$before = $converted_template;
			}
		}

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Convert a block markup file to HTML.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function convert_theme_block_file_to_html( string $path ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : self::blocks_to_html( $content );
	}

	/**
	 * Render serialized block markup with Blocks Engine's native format bridge.
	 *
	 * @param string $block_markup Serialized blocks.
	 * @return string
	 */
	private static function blocks_to_html( string $block_markup ): string {
		$result = blocks_engine_php_transformer_convert_format( $block_markup, 'blocks', 'html' );
		return isset( $result['documents'][0]['content'] ) && is_scalar( $result['documents'][0]['content'] ) ? (string) $result['documents'][0]['content'] : '';
	}

	/**
	 * Read a file when available.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_file_if_readable( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
		$content = file_get_contents( $path );
		return false === $content ? '' : (string) $content;
	}

	/**
	 * Build a full static HTML document.
	 *
	 * @param string                    $page_html       Converted page body HTML.
	 * @param array{before:string,after:string} $chrome          Converted theme chrome.
	 * @param string                    $title           Document title.
	 * @param bool                      $include_styles  Whether to link exported CSS.
	 * @return string
	 */
	private static function export_html_document( string $page_html, array $chrome, string $title, bool $include_styles ): string {
		$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		if ( $include_styles ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="style.css">';
		}

		return '<!doctype html>' . "\n"
			. '<html><head>' . $head . '<title>' . esc_html( $title ) . '</title></head><body>' . "\n"
			. trim( (string) ( $chrome['before'] ?? '' ) . "\n" . $page_html . "\n" . ( $chrome['after'] ?? '' ) ) . "\n"
			. '</body></html>' . "\n";
	}

	/**
	 * Build an artifact file entry.
	 *
	 * @param string              $path        Artifact path.
	 * @param string              $content     File content.
	 * @param string              $kind        File kind.
	 * @param string              $role        File role.
	 * @param array<string,mixed> $diagnostics Optional diagnostics/metadata.
	 * @return array<string,mixed>
	 */
	private static function export_file_entry( string $path, string $content, string $kind, string $role, array $diagnostics = array() ): array {
		$encoding = self::is_binary_content( $content ) ? 'base64' : 'utf8';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact files are explicitly represented as base64 for transport.
		$body = 'base64' === $encoding ? base64_encode( $content ) : $content;
		$entry = array(
			'path'      => $path,
			'content'   => $body,
			'kind'      => $kind,
			'role'      => $role,
			'mime_type' => self::export_mime_type( $path ),
			'encoding'  => $encoding,
			'bytes'     => strlen( $content ),
			'sha256'    => hash( 'sha256', $content ),
		);
		if ( ! empty( $diagnostics ) ) {
			$entry = array_merge( $entry, $diagnostics );
		}

		return $entry;
	}

	/**
	 * Build the website artifact envelope consumed by Blocks Engine.
	 *
	 * @param string              $theme_slug      Theme slug.
	 * @param string              $root            Artifact root.
	 * @param string              $entrypoint      Entrypoint path.
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param array<string,mixed> $report          Export report.
	 * @param array<string,mixed> $source_metadata Source metadata.
	 * @return array<string,mixed>
	 */
	private static function export_website_artifact( string $theme_slug, string $root, string $entrypoint, array $files, array $report, array $source_metadata ): array {
		$generated_at = self::export_generated_at();
		$id           = 'website-artifact-' . $theme_slug . '-' . substr( hash( 'sha256', self::json_encode_pretty( array( $entrypoint, $files ) ) ), 0, 12 );

		return array(
			'schema'        => 'blocks-engine/php-transformer/site-artifact/v1',
			'artifact_type' => 'website',
			'version'       => 1,
			'id'            => $id,
			'generated_at'  => $generated_at,
			'theme_slug'    => $theme_slug,
			'root'          => $root,
			'entrypoint'    => $entrypoint,
			'files'         => $files,
			'report'        => $report,
			'reports'       => self::export_report_refs( $files ),
			'import'        => array(
				'status'      => empty( $report['diagnostics'] ) ? 'passed' : 'warning',
				'theme_slug'  => $theme_slug,
				'source_path' => $entrypoint,
				'warnings'    => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'warning' ),
				'errors'      => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'error' ),
			),
			'validation'    => array(
				'status'     => self::export_validation_status( $report['diagnostics'] ?? array() ),
				'checked_at' => $generated_at,
				'checks'     => array(
					array(
						'name'    => 'entrypoint-present',
						'status'  => self::export_has_file( $files, $entrypoint ) ? 'passed' : 'failed',
						'message' => 'The website artifact entrypoint is present in the exported file set.',
					),
				),
			),
			'provenance'    => array(
				'producer'          => 'static-site-importer',
				'source_metadata'   => $source_metadata,
				'materialized_from' => array(
					'type'       => 'wordpress-block-theme',
					'theme_slug' => $theme_slug,
				),
			),
		);
	}

	/**
	 * Export the theme stylesheet when present.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>|null
	 */
	private static function export_theme_stylesheet_file( string $theme_dir, string $root ): ?array {
		$content = self::read_file_if_readable( $theme_dir . '/style.css' );
		if ( '' === $content ) {
			return null;
		}

		return self::export_file_entry( $root . '/style.css', $content, 'asset', 'stylesheet' );
	}

	/**
	 * Export browser assets that can be replayed with the website artifact.
	 *
	 * @param string                    $theme_dir   Theme directory.
	 * @param string                    $root        Artifact root.
	 * @param array<int,array<string,mixed>> $diagnostics Export diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function export_theme_asset_files( string $theme_dir, string $root, array &$diagnostics ): array {
		$assets_dir = $theme_dir . '/assets';
		if ( ! is_dir( $assets_dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $assets_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item instanceof SplFileInfo || ! $item->isFile() || ! $item->isReadable() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $assets_dir ) ) ), '/' );
			$path     = self::export_artifact_path( $root . '/assets/' . $relative, '' );
			if ( '' === $path || ! self::export_is_supported_asset_path( $path ) ) {
				$diagnostics[] = array(
					'level'   => 'warning',
					'code'    => 'static_site_importer_export_asset_skipped',
					'message' => 'A theme asset was skipped because its path or type is not supported for static export.',
					'path'    => $relative,
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
			$content = file_get_contents( $item->getPathname() );
			if ( false === $content ) {
				continue;
			}

			$files[] = self::export_file_entry( $path, (string) $content, self::export_kind_from_path( $path ), self::export_role_from_path( $path ) );
		}

		usort(
			$files,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['path'] ?? '' ), (string) ( $right['path'] ?? '' ) );
			}
		);

		return $files;
	}

	/**
	 * Normalize an exported artifact path.
	 *
	 * @param string $path     Requested path.
	 * @param string $fallback Fallback path.
	 * @return string
	 */
	private static function export_artifact_path( string $path, string $fallback ): string {
		$path = self::normalize_route_path( $path );
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			return $fallback;
		}

		return $path;
	}

	/**
	 * Resolve the artifact root from input or entrypoint.
	 *
	 * @param string $root       Requested root.
	 * @param string $entrypoint Entrypoint path.
	 * @return string
	 */
	private static function export_artifact_root( string $root, string $entrypoint ): string {
		$root = self::normalize_route_path( $root );
		if ( '' !== $root && ! str_contains( $root, '/' ) ) {
			return $root;
		}

		$parts = explode( '/', $entrypoint );
		return '' !== ( $parts[0] ?? '' ) ? $parts[0] : 'website';
	}

	/**
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page, string $root ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		return self::export_artifact_path( $root . '/' . $slug . '/index.html', $root . '/page/index.html' );
	}

	/**
	 * Resolve a page title for export.
	 *
	 * @param object $page       Page object.
	 * @param string $theme_slug Fallback theme slug.
	 * @return string
	 */
	private static function export_page_title( object $page, string $theme_slug ): string {
		if ( isset( $page->post_title ) && '' !== trim( (string) $page->post_title ) ) {
			return (string) $page->post_title;
		}

		return $theme_slug;
	}

	/**
	 * Resolve a static export MIME type from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_mime_type( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'text/html',
			'css'         => 'text/css',
			'js', 'mjs'    => 'text/javascript',
			'json'        => 'application/json',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			default       => 'application/octet-stream',
		};
	}

	/**
	 * Infer an exported file kind from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_kind_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'document',
			'css'         => 'asset',
			'js', 'mjs'    => 'asset',
			'json'        => 'metadata',
			default       => 'asset',
		};
	}

	/**
	 * Infer a static artifact file role from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_role_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'css'        => 'stylesheet',
			'js', 'mjs'   => 'script',
			'json'       => 'metadata',
			default      => 'asset',
		};
	}

	/**
	 * Check whether an asset path is supported for static export.
	 *
	 * @param string $path Artifact path.
	 * @return bool
	 */
	private static function export_is_supported_asset_path( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'woff', 'woff2' ), true );
	}

	/**
	 * Detect binary content that should be inlined as base64.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_binary_content( string $content ): bool {
		return str_contains( $content, "\0" ) || ! preg_match( '//u', $content );
	}

	/**
	 * JSON encode with stable options and a PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	private static function json_encode_pretty( mixed $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Smoke tests load this class without WordPress helpers.
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded . "\n" : "{}\n";
	}

	/**
	 * Return the export timestamp.
	 *
	 * @return string
	 */
	private static function export_generated_at(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Build report file references from exported files.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @return array<int,array<string,string>>
	 */
	private static function export_report_refs( array $files ): array {
		$refs = array();
		foreach ( $files as $file ) {
			$role = (string) ( $file['role'] ?? '' );
			if ( in_array( $role, array( 'report', 'source-document' ), true ) ) {
				$refs[] = array(
					'role' => $role,
					'path' => (string) ( $file['path'] ?? '' ),
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract diagnostic messages by level/severity.
	 *
	 * @param mixed  $diagnostics Diagnostics.
	 * @param string $level       Level to collect.
	 * @return array<int,string>
	 */
	private static function export_diagnostic_messages( mixed $diagnostics, string $level ): array {
		if ( ! is_array( $diagnostics ) ) {
			return array();
		}

		$messages = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$diagnostic_level = (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) );
			if ( $level === $diagnostic_level ) {
				$messages[] = (string) ( $diagnostic['message'] ?? ( $diagnostic['code'] ?? '' ) );
			}
		}

		return array_values( array_filter( $messages ) );
	}

	/**
	 * Resolve validation status from diagnostics.
	 *
	 * @param mixed $diagnostics Diagnostics.
	 * @return string
	 */
	private static function export_validation_status( mixed $diagnostics ): string {
		if ( ! is_array( $diagnostics ) ) {
			return 'passed';
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && 'error' === (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) ) ) {
				return 'failed';
			}
		}

		return empty( $diagnostics ) ? 'passed' : 'warning';
	}

	/**
	 * Check whether a file path exists in the export set.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param string                        $path  Artifact path.
	 * @return bool
	 */
	private static function export_has_file( array $files, string $path ): bool {
		foreach ( $files as $file ) {
			if ( (string) ( $file['path'] ?? '' ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the import report bundled with an SSI-generated theme.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>
	 */
	private static function read_theme_import_report( string $theme_dir ): array {
		$report = self::read_file_if_readable( $theme_dir . '/import-report.json' );
		if ( '' === $report ) {
			return array();
		}

		$decoded = json_decode( $report, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build a stable import run id for report and provenance joins.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return string
	 */
	private static function import_run_id( array $args ): string {
		if ( isset( $args['import_run_id'] ) && is_scalar( $args['import_run_id'] ) && '' !== trim( (string) $args['import_run_id'] ) ) {
			return sanitize_key( (string) $args['import_run_id'] );
		}

		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'ssi-' . wp_generate_uuid4();
		}

		return 'ssi-' . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Whether to persist report/validation/finding JSON into the generated theme.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return bool
	 */
	private static function write_theme_report_artifacts_enabled( array $args ): bool {
		if ( ! array_key_exists( 'write_theme_report_artifacts', $args ) ) {
			return false;
		}

		return false !== filter_var( $args['write_theme_report_artifacts'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Extract artifact identity fields supplied with a source artifact.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function source_artifact_reference_from_artifact( array $artifact, array $args = array() ): array {
		$reference = array(
			'schema'     => isset( $artifact['schema'] ) && is_scalar( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'id'         => '',
			'hash'       => '',
			'hash_algo'  => '',
			'entrypoint' => isset( $artifact['entrypoint'] ) && is_scalar( $artifact['entrypoint'] ) ? (string) $artifact['entrypoint'] : '',
		);

		foreach ( array( 'artifact_id', 'id', 'run_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['id'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['id'] = (string) $artifact[ $key ];
				break;
			}
		}

		foreach ( array( 'artifact_hash', 'hash', 'sha256' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['hash'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['hash'] = (string) $artifact[ $key ];
				break;
			}
		}

		if ( isset( $args['artifact_hash_algo'] ) && is_scalar( $args['artifact_hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $args['artifact_hash_algo'];
		} elseif ( isset( $artifact['hash_algo'] ) && is_scalar( $artifact['hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $artifact['hash_algo'];
		} elseif ( isset( $artifact['sha256'] ) || isset( $args['sha256'] ) ) {
			$reference['hash_algo'] = 'sha256';
		}

		return array_filter(
			$reference,
			static fn ( $value ): bool => '' !== $value
		);
	}

	/**
	 * Extract artifact identity fields from the compiled result and import args.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function source_artifact_reference_from_compiled( array $compiled, array $args = array() ): array {
		$reference  = isset( $args['source_artifact_reference'] ) && is_array( $args['source_artifact_reference'] ) ? $args['source_artifact_reference'] : array();
		$provenance = isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array();
		$input      = isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array();

		foreach ( array(
			'id'        => array( 'artifact_id', 'id', 'run_id' ),
			'hash'      => array( 'artifact_hash', 'hash', 'sha256' ),
			'hash_algo' => array( 'artifact_hash_algo', 'hash_algo' ),
		) as $target => $keys ) {
			if ( isset( $reference[ $target ] ) && is_scalar( $reference[ $target ] ) && '' !== trim( (string) $reference[ $target ] ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				foreach ( array( $args, $provenance, $input ) as $source ) {
					if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
						$reference[ $target ] = (string) $source[ $key ];
						break 2;
					}
				}
			}
		}

		if ( ! isset( $reference['entrypoint'] ) && isset( $input['entry_path'] ) && is_scalar( $input['entry_path'] ) ) {
			$reference['entrypoint'] = (string) $input['entry_path'];
		}

		return array_filter(
			$reference,
			static fn ( $value ): bool => is_scalar( $value ) ? '' !== trim( (string) $value ) : ! empty( $value )
		);
	}

	/**
	 * Build the source-of-truth manifest embedded in reports and written to disk.
	 *
	 * @param string                       $import_run_id Import run id.
	 * @param array<string,mixed>          $artifact      Source artifact reference.
	 * @param string                       $theme_dir     Theme directory.
	 * @param string                       $theme_slug    Theme slug.
	 * @param array<string,array<string,mixed>> $page_targets Page target rows.
	 * @param array<string,int>            $page_ids      Page IDs keyed by source path.
	 * @param array<string,string>         $permalinks    Permalinks keyed by source path.
	 * @param array<string,string>         $writes        Theme writes.
	 * @param array<string,mixed>          $materialized  Materialized asset summary.
	 * @return array<string,mixed>
	 */
	private static function source_of_truth_manifest( string $import_run_id, array $artifact, string $theme_dir, string $theme_slug, array $page_targets, array $page_ids, array $permalinks, array $writes, array $materialized, bool $write_theme_report_artifacts = false ): array {
		$pages           = array();
		$existing_pages  = array();
		foreach ( $page_targets as $filename => $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}
			$post_id                         = (int) ( $page_ids[ $filename ] ?? 0 );
			$target['materialized_post_id']   = $post_id;
			$target['permalink']              = $permalinks[ $filename ] ?? '';
			$target['provenance_meta_key']    = ! empty( $target['protected'] ) ? '' : '_static_site_importer_provenance';
			$pages[]                          = $target;
			if ( ! empty( $target['existing_post_id'] ) ) {
				$existing_pages[] = array(
					'source_path' => (string) ( $target['source_path'] ?? $filename ),
					'post_id'     => (int) $target['existing_post_id'],
					'post_type'   => (string) ( $target['post_type'] ?? 'page' ),
					'slug'        => (string) ( $target['slug'] ?? '' ),
					'protected'   => ! empty( $target['protected'] ),
				);
			}
		}

		$files = array();
		foreach ( array_keys( $writes ) as $path ) {
			$relative = self::theme_relative_path( (string) $path, $theme_dir );
			if ( '' !== $relative ) {
				$files[] = array(
					'target_type' => 'theme_file',
					'path'        => $relative,
				);
			}
		}
		$managed_metadata_files = array( 'static-site-importer-manifest.json' );
		if ( $write_theme_report_artifacts ) {
			$managed_metadata_files = array_merge( $managed_metadata_files, array( 'import-report.json', 'import-validation-result.json', 'finding-packets.json' ) );
		}
		foreach ( $managed_metadata_files as $path ) {
			$files[] = array(
				'target_type' => 'theme_file',
				'path'        => $path,
			);
		}

		$assets = array();
		foreach ( $materialized['assets'] ?? array() as $source => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$assets[] = array(
				'source_path' => is_string( $source ) ? $source : (string) ( $asset['source'] ?? '' ),
				'target_type' => 'theme_asset',
				'theme_path'  => (string) ( $asset['theme_path'] ?? '' ),
				'url'         => (string) ( $asset['final_url'] ?? ( $asset['url'] ?? '' ) ),
				'policy'      => (string) ( $asset['policy'] ?? 'theme' ),
			);
		}

		return array(
			'schema'           => 'static-site-importer/source-of-truth-manifest/v1',
			'version'          => 1,
			'import_run_id'    => $import_run_id,
			'artifact'         => $artifact,
			'generated_theme'  => array(
				'slug' => $theme_slug,
			),
			'desired'          => array(
				'pages'  => $pages,
				'files'  => $files,
				'assets' => $assets,
			),
			'existing_matches' => array(
				'pages' => $existing_pages,
			),
			'manifest_path'    => 'static-site-importer-manifest.json',
			'cleanup'          => array(
				'enabled' => false,
				'notes'   => array( 'Stale cleanup/deletion is intentionally not part of this foundation layer.' ),
			),
		);
	}

	/**
	 * Convert an absolute generated theme file path to a theme-relative path.
	 *
	 * @param string $path      Absolute path.
	 * @param string $theme_dir Theme directory.
	 * @return string
	 */
	private static function theme_relative_path( string $path, string $theme_dir ): string {
		$prefix = trailingslashit( $theme_dir );
		if ( ! str_starts_with( $path, $prefix ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $prefix ) ), '/\\' );
	}

	/**
	 * Collect visual repair stylesheet artifacts by target.
	 *
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @return array{frontend:array<int,string>,editor:array<int,string>} Repair CSS content by stylesheet target.
	 */
	private static function visual_repair_styles_from_artifacts( array $artifacts ): array {
		$styles = array(
			'frontend' => array(),
			'editor'   => array(),
		);

		$visual_repair = isset( $artifacts['visual_repair'] ) && is_array( $artifacts['visual_repair'] ) ? $artifacts['visual_repair'] : array();
		$repair_styles = isset( $visual_repair['styles'] ) && is_array( $visual_repair['styles'] ) ? $visual_repair['styles'] : array();
		$repair_css    = isset( $visual_repair['css'] ) && is_scalar( $visual_repair['css'] ) ? trim( (string) $visual_repair['css'] ) : '';
		if ( '' !== $repair_css ) {
			$repair_styles[] = array(
				'target'  => 'frontend',
				'content' => $repair_css,
			);
			$repair_styles[] = array(
				'target'  => 'editor',
				'content' => $repair_css,
			);
		}
		foreach ( $repair_styles as $style ) {
			if ( ! is_array( $style ) || ! isset( $style['target'], $style['content'] ) || ! is_scalar( $style['target'] ) || ! is_scalar( $style['content'] ) ) {
				continue;
			}

			$target  = (string) $style['target'];
			$content = trim( (string) $style['content'] );
			if ( '' === $content || ! isset( $styles[ $target ] ) ) {
				continue;
			}

			$styles[ $target ][] = $content;
		}

		$styles['frontend'] = array_values( array_unique( $styles['frontend'] ) );
		$styles['editor']   = array_values( array_unique( $styles['editor'] ) );

		return $styles;
	}

	/**
	 * Mark empty absolute-positioned groups so editor CSS can hide only decorative placeholders.
	 *
	 * @param string $block_markup Serialized block markup.
	 * @return string Serialized block markup.
	 */
	private static function mark_empty_decorative_group_blocks( string $block_markup, string $source = '' ): string {
		if ( '' === trim( $block_markup ) || empty( self::$decorative_empty_group_classes ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $block_markup;
		}

		/** @var array<int, array<string, mixed>> $blocks */
		$blocks                 = parse_blocks( $block_markup );
		$changed                = false;
		$normalized_html_blocks = 0;
		self::normalize_decorative_html_blocks_in_tree( $blocks, $changed, $normalized_html_blocks );
		if ( $normalized_html_blocks > 0 && '' !== $source ) {
			self::clear_normalized_decorative_fallbacks( $source, $normalized_html_blocks );
		}
		self::mark_empty_decorative_group_blocks_in_tree( $blocks, $changed );

		// @phpstan-ignore-next-line argument.type -- Parsed blocks are normalized before serializing.
		return $changed ? serialize_blocks( $blocks ) : $block_markup;
	}

	/**
	 * Restore empty decorative divs when the converter drops them from card bodies.
	 *
	 * @param string $html         Source HTML fragment.
	 * @param string $block_markup Serialized block markup.
	 * @return string Serialized block markup.
	 */
	private static function restore_dropped_empty_decorative_groups( string $html, string $block_markup ): string {
		if ( '' === trim( $html ) || '' === trim( $block_markup ) || empty( self::$decorative_empty_group_classes ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $block_markup;
		}

		$restore = self::empty_decorative_groups_by_parent_class( $html );
		if ( empty( $restore ) ) {
			return $block_markup;
		}

		/** @var array<int, array<string, mixed>> $blocks */
		$blocks  = parse_blocks( $block_markup );
		$changed = false;
		self::restore_dropped_empty_decorative_groups_in_tree( $blocks, $restore, $changed );

		// @phpstan-ignore-next-line argument.type -- Parsed blocks are normalized before serializing.
		return $changed ? serialize_blocks( $blocks ) : $block_markup;
	}

	/**
	 * Extract decorative empty div blocks keyed by their parent class token.
	 *
	 * @param string $html Source HTML fragment.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function empty_decorative_groups_by_parent_class( string $html ): array {
		$doc     = self::load_fragment_document( $html );
		$restore = array();

		foreach ( $doc->getElementsByTagName( 'div' ) as $element ) {
			if ( ! self::is_empty_decorative_theme_part_element( $element ) || ! $element->parentNode instanceof DOMElement ) {
				continue;
			}

			$matched = false;
			$block   = self::decorative_group_block_from_element( $element, $matched );
			if ( null === $block || ! $matched ) {
				continue;
			}

			$parent_classes = preg_split( '/\s+/', trim( $element->parentNode->getAttribute( 'class' ) ) );
			$parent_classes = false === $parent_classes ? array() : $parent_classes;
			foreach ( $parent_classes as $parent_class ) {
				if ( '' !== $parent_class && self::class_token_looks_like_card_container( $parent_class ) ) {
					$restore[ $parent_class ][] = $block;
				}
			}
		}

		return $restore;
	}

	/**
	 * Parse an HTML fragment into a wrapper document.
	 *
	 * @param string $html HTML fragment.
	 * @return DOMDocument
	 */
	private static function load_fragment_document( string $html ): DOMDocument {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div data-static-site-importer-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $doc;
	}

	/**
	 * Check whether an empty element is CSS-declared decorative chrome.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function is_empty_decorative_theme_part_element( DOMElement $element ): bool {
		if ( 'div' !== strtolower( $element->tagName ) || empty( self::$decorative_empty_group_classes ) ) {
			return false;
		}

		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement || ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) ) {
				return false;
			}
		}

		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
		$classes = false === $classes ? array() : $classes;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a class token identifies a card-like container that can safely receive restored layers.
	 *
	 * @param string $class_name Class token.
	 * @return bool
	 */
	private static function class_token_looks_like_card_container( string $class_name ): bool {
		return str_contains( $class_name, 'card' ) || str_contains( $class_name, 'gallery' ) || str_contains( $class_name, 'product' ) || str_contains( $class_name, 'category' );
	}

	/**
	 * Restore decorative blocks inside matching generated group blocks.
	 *
	 * @param array<int, array<string, mixed>>              $blocks  Parsed blocks.
	 * @param array<string, array<int, array<string,mixed>>> $restore Blocks keyed by parent class.
	 * @param bool                                          $changed Whether any block changed.
	 * @return void
	 */
	private static function restore_dropped_empty_decorative_groups_in_tree( array &$blocks, array $restore, bool &$changed ): void {
		foreach ( $blocks as &$block ) {
			if ( 'core/group' === ( $block['blockName'] ?? '' ) ) {
				$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
				$classes = false === $classes ? array() : $classes;
				foreach ( $classes as $class ) {
					if ( ! isset( $restore[ $class ] ) || self::block_contains_any_decorative_group( $block, $restore[ $class ] ) ) {
						continue;
					}

					$inner_blocks          = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
					$block['innerBlocks']  = array_merge( $restore[ $class ], $inner_blocks );
					$block['innerContent'] = self::prepend_inner_content_placeholders( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array(), count( $restore[ $class ] ) );
					$changed               = true;
					break;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::restore_dropped_empty_decorative_groups_in_tree( $block['innerBlocks'], $restore, $changed );
			}
		}
		unset( $block );
	}

	/**
	 * Add innerContent placeholders for restored leading inner blocks.
	 *
	 * @param array<int, mixed> $inner_content Existing innerContent.
	 * @param int              $count         Number of leading blocks to insert.
	 * @return array<int, mixed>
	 */
	private static function prepend_inner_content_placeholders( array $inner_content, int $count ): array {
		$placeholders = array_fill( 0, max( 0, $count ), null );
		if ( empty( $inner_content ) ) {
			return $placeholders;
		}

		$first = array_shift( $inner_content );
		return array_merge( array( $first ), $placeholders, $inner_content );
	}

	/**
	 * Check whether a generated block already contains any decorative class slated for restore.
	 *
	 * @param array<string, mixed>             $block        Parsed block.
	 * @param array<int, array<string, mixed>> $restore_list Candidate restored blocks.
	 * @return bool
	 */
	private static function block_contains_any_decorative_group( array $block, array $restore_list ): bool {
		$haystack = wp_json_encode( $block, JSON_UNESCAPED_SLASHES );
		$haystack = is_string( $haystack ) ? $haystack : '';
		foreach ( $restore_list as $restore_block ) {
			$attrs      = is_array( $restore_block['attrs'] ?? null ) ? $restore_block['attrs'] : array();
			$class_name = (string) ( $attrs['className'] ?? '' );
			if ( '' !== $class_name && str_contains( $haystack, $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert raw HTML islands made only of decorative empty divs into native groups.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function normalize_decorative_html_blocks_in_tree( array &$blocks, bool &$changed, int &$normalized_html_blocks ): void {
		$block_total = count( $blocks );
		for ( $index = 0; $index < $block_total; ++$index ) {
			if ( ! empty( $blocks[ $index ]['innerBlocks'] ) && is_array( $blocks[ $index ]['innerBlocks'] ) ) {
				self::normalize_decorative_html_blocks_in_tree( $blocks[ $index ]['innerBlocks'], $changed, $normalized_html_blocks );
			}

			if ( 'core/html' !== ( $blocks[ $index ]['blockName'] ?? '' ) ) {
				continue;
			}

			$replacement = self::decorative_group_blocks_from_html( (string) ( $blocks[ $index ]['innerHTML'] ?? '' ) );
			if ( null === $replacement ) {
				continue;
			}

			array_splice( $blocks, $index, 1, $replacement );
			$replacement_count = count( $replacement );
			$index            += $replacement_count - 1;
			$block_total      += $replacement_count - 1;
			$changed           = true;
			++$normalized_html_blocks;
		}
	}

	/**
	 * Remove fallback diagnostics that were recovered as native decorative group blocks.
	 *
	 * @param string $source Source fragment label.
	 * @param int    $count  Number of normalized fallback blocks.
	 * @return void
	 */
	private static function clear_normalized_decorative_fallbacks( string $source, int $count ): void {
		self::$conversion_report['quality']['fallback_count'] = max( 0, (int) self::$conversion_report['quality']['fallback_count'] - $count );
		if ( isset( self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] ) ) {
			self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] = max( 0, (int) self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] - $count );
		}

		for ( $index = count( self::$conversion_report['diagnostics'] ) - 1; $index >= 0 && $count > 0; --$index ) {
			$diagnostic = self::$conversion_report['diagnostics'][ $index ];
			if ( 'unsupported_html_fallback' !== ( $diagnostic['type'] ?? '' ) || ( $diagnostic['source'] ?? '' ) !== $source ) {
				continue;
			}

			array_splice( self::$conversion_report['diagnostics'], $index, 1 );
			--$count;
		}
	}

	/**
	 * Convert an HTML fragment to group blocks when it only contains decorative empty div layers.
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array<string, mixed>>|null Replacement group blocks, or null when not safe.
	 */
	private static function decorative_group_blocks_from_html( string $html ): ?array {
		if ( '' === trim( $html ) || ! str_contains( $html, '<div' ) ) {
			return null;
		}

		$doc      = self::load_fragment_document( $html );
		$root     = $doc->documentElement;
		$blocks   = array();
		$matched  = false;
		$has_node = false;
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$has_node = true;
			$block    = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $block ) {
				return null;
			}

			$blocks[] = $block;
		}

		return $has_node && $matched ? $blocks : null;
	}

	/**
	 * Convert one empty decorative div tree to a parsed group block.
	 *
	 * @param DOMElement $element Source element.
	 * @param bool       $matched Whether a decorative class was found.
	 * @return array<string, mixed>|null Parsed block, or null when not safe.
	 */
	private static function decorative_group_block_from_element( DOMElement $element, bool &$matched ): ?array {
		if ( 'div' !== strtolower( $element->tagName ) ) {
			return null;
		}

		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$child_block = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $child_block ) {
				return null;
			}

			$children[] = $child_block;
		}

		$class_name = trim( $element->getAttribute( 'class' ) );
		$classes    = preg_split( '/\s+/', $class_name );
		$classes    = false === $classes ? array() : $classes;
		$is_layer   = false;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				$is_layer = true;
				$matched  = true;
				break;
			}
		}

		if ( empty( $children ) && $is_layer ) {
			$class_name = self::append_class_token( $class_name, 'static-site-importer-decorative-layer' );
		}

		$attrs = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}

		$class_attr    = esc_attr( trim( 'wp-block-group ' . $class_name ) );
		$inner_content = array( '<div class="' . $class_attr . '">' );
		foreach ( $children as $_child ) {
			$inner_content[] = null;
		}
		$inner_content[] = '</div>';

		if ( empty( $children ) ) {
			$inner_content = array( '<div class="' . $class_attr . '"></div>' );
		}

		return array(
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Recursively mark empty decorative group blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function mark_empty_decorative_group_blocks_in_tree( array &$blocks, bool &$changed ): void {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::mark_empty_decorative_group_blocks_in_tree( $block['innerBlocks'], $changed );
			}

			if ( 'core/group' !== ( $block['blockName'] ?? '' ) || ! self::is_empty_decorative_group_block( $block ) ) {
				continue;
			}

			$attrs              = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$attrs['className'] = self::append_class_token( (string) ( $attrs['className'] ?? '' ), 'static-site-importer-decorative-layer' );
			$block['attrs']     = $attrs;

			foreach ( array( 'innerHTML' ) as $key ) {
				if ( isset( $block[ $key ] ) && is_string( $block[ $key ] ) ) {
					$block[ $key ] = self::append_class_to_first_html_class_attribute( $block[ $key ], 'static-site-importer-decorative-layer' );
				}
			}

			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as &$content ) {
					if ( is_string( $content ) ) {
						$content = self::append_class_to_first_html_class_attribute( $content, 'static-site-importer-decorative-layer' );
					}
				}
				unset( $content );
			}

			$changed = true;
		}
		unset( $block );
	}

	/**
	 * Check whether a parsed group block is empty and styled as a decorative layer.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool Whether the block is an empty decorative group.
	 */
	private static function is_empty_decorative_group_block( array $block ): bool {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		if ( '' !== trim( wp_strip_all_tags( $inner_html ) ) ) {
			return false;
		}

		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		$classes = false === $classes ? array() : $classes;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Append a class to the first HTML class attribute in a serialized block fragment.
	 *
	 * @param string $html                 HTML fragment.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated HTML fragment.
	 */
	private static function append_class_to_first_html_class_attribute( string $html, string $class_name_to_append ): string {
		$updated = preg_replace_callback(
			'/class="([^"]*)"/',
			static function ( array $matches ) use ( $class_name_to_append ): string {
				return 'class="' . esc_attr( self::append_class_token( html_entity_decode( $matches[1], ENT_QUOTES ), $class_name_to_append ) ) . '"';
			},
			$html,
			1
		);

		return null === $updated ? $html : $updated;
	}

	/**
	 * Append a class token if it is not already present.
	 *
	 * @param string $classes Existing classes.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated classes.
	 */
	private static function append_class_token( string $classes, string $class_name_to_append ): string {
		$tokens = preg_split( '/\s+/', trim( $classes ) );
		$tokens = false === $tokens ? array() : $tokens;
		if ( ! in_array( $class_name_to_append, $tokens, true ) ) {
			$tokens[] = $class_name_to_append;
		}

		return trim( implode( ' ', array_filter( $tokens ) ) );
	}

	/**
	 * Record source document materialization details.
	 *
	 * @param array<int,mixed>                                   $documents  Document artifacts.
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Imported pages/posts.
	 * @param array<string,int>                                $page_ids   Imported post IDs keyed by source path.
	 * @param array<string,string>                             $permalinks Imported permalinks keyed by source path.
	 * @return void
	 */
	private static function record_source_documents_summary( array $documents, array $pages, array $page_ids, array $permalinks ): void {
		$records = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = self::normalize_route_path( isset( $document['source_path'] ) ? (string) $document['source_path'] : (string) ( $document['path'] ?? '' ) );
			if ( '' === $source_path ) {
				$source_path = self::normalize_route_path( isset( $document['slug'] ) ? (string) $document['slug'] : '' );
			}
			if ( '' === $source_path || ! isset( $pages[ $source_path ] ) ) {
				continue;
			}

			$page        = $pages[ $source_path ];
			$post_id     = (int) ( $page_ids[ $source_path ] ?? 0 );
			$post_type   = Static_Site_Importer_Page_Materializer::page_post_type( $page );
			$diagnostics = isset( $document['diagnostics'] ) && is_array( $document['diagnostics'] ) ? array_values( $document['diagnostics'] ) : array();

			$record = array(
				'source_path'  => $source_path,
				'post_id'      => $post_id,
				'post_type'    => $post_type,
				'slug'         => Static_Site_Importer_Page_Materializer::page_slug( $source_path, $page ),
				'title'        => Static_Site_Importer_Page_Materializer::page_title( $source_path, $page ),
				'status'       => Static_Site_Importer_Page_Materializer::page_status( $page ),
				'permalink'    => $permalinks[ $source_path ] ?? '',
				'diagnostics'  => $diagnostics,
				'materialized' => $post_id > 0,
			);

			$records[] = $record;
			foreach ( $diagnostics as $diagnostic ) {
				if ( is_array( $diagnostic ) ) {
					$diagnostic['source']                     = isset( $diagnostic['source'] ) && '' !== trim( (string) $diagnostic['source'] ) ? (string) $diagnostic['source'] : $source_path;
					self::$conversion_report['diagnostics'][] = $diagnostic;
				}
			}
		}

		if ( empty( $records ) ) {
			foreach ( $pages as $source_path => $page ) {
				$post_id   = (int) ( $page_ids[ $source_path ] ?? 0 );
				$records[] = array(
					'source_path'  => (string) $source_path,
					'post_id'      => $post_id,
					'post_type'    => Static_Site_Importer_Page_Materializer::page_post_type( $page ),
					'slug'         => Static_Site_Importer_Page_Materializer::page_slug( (string) $source_path, $page ),
					'title'        => Static_Site_Importer_Page_Materializer::page_title( (string) $source_path, $page ),
					'status'       => Static_Site_Importer_Page_Materializer::page_status( $page ),
					'permalink'    => $permalinks[ $source_path ] ?? '',
					'diagnostics'  => array(),
					'materialized' => $post_id > 0,
				);
			}
		}

		self::$conversion_report['source_documents'] = array_merge(
			self::$conversion_report['source_documents'],
			array(
				'source'                       => 'blocks_engine',
				'total_count'                  => count( $records ),
				'counts_by_format'             => self::source_document_counts_by_format( $records ),
				'blocks_engine_documents'      => $records,
				'blocks_engine_document_count' => count( $records ),
			)
		);
	}

	/**
	 * Count materialized source documents by source-path format.
	 *
	 * @param array<int,array<string,mixed>> $records Source document records.
	 * @return array<string,int>
	 */
	private static function source_document_counts_by_format( array $records ): array {
		$counts = array(
			'html'     => 0,
			'markdown' => 0,
			'mdx'      => 0,
		);

		foreach ( $records as $record ) {
			$source_path = isset( $record['source_path'] ) && is_scalar( $record['source_path'] ) ? strtolower( (string) $record['source_path'] ) : '';
			$extension   = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
			if ( 'mdx' === $extension ) {
				++$counts['mdx'];
			} elseif ( in_array( $extension, array( 'md', 'markdown' ), true ) ) {
				++$counts['markdown'];
			} else {
				++$counts['html'];
			}
		}

		return $counts;
	}

	/**
	 * Record an explicit products manifest supplied by the caller.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_products_manifest_from_import_args( array $args, array $compiled = array() ): void {
		$source = 'import_args.products_manifest';
		if ( isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) ) {
			$products = $args['products_manifest'];
		} elseif ( isset( $compiled['products_manifest'] ) && is_array( $compiled['products_manifest'] ) ) {
			$products = $compiled['products_manifest'];
			$source   = 'blocks-engine/php-transformer/reports';
		} else {
			return;
		}

		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest(
			Static_Site_Importer_Entity_Materializer_Registry::product_adapter(),
			array(
				'schema_version' => 1,
				'products'       => $products,
			)
		);

		if ( ! isset( self::$conversion_report['commerce'] ) || ! is_array( self::$conversion_report['commerce'] ) ) {
			self::$conversion_report['commerce'] = array();
		}

		self::$conversion_report['commerce']['products_manifest'] = array(
			'present'       => true,
			'source'        => $source,
			'contract'      => array(
				'schema'          => 'static-site-importer/products-manifest/v1',
				'schema_version'  => 1,
				'required_fields' => array( 'name', 'slug', 'regular_price' ),
				'optional_fields' => array( 'sale_price', 'description', 'short_description', 'categories', 'image', 'status', 'stock_status', 'stock_quantity', 'source_selectors' ),
			),
			'valid'         => empty( $validation['errors'] ),
			'product_count' => empty( $validation['errors'] ) ? count( $validation['products'] ) : 0,
			'products'      => $validation['products'],
			'errors'        => $validation['errors'],
		);

		if ( ! empty( self::$conversion_report['commerce']['products_manifest']['valid'] ) ) {
			return;
		}

		self::$conversion_report['diagnostics'][] = array(
			'code'     => 'products_manifest_invalid',
			'severity' => 'warning',
			'source'   => self::$conversion_report['commerce']['products_manifest']['source'],
			'message'  => 'products_manifest was supplied but does not match the importer product seeding contract.',
			'errors'   => self::$conversion_report['commerce']['products_manifest']['errors'],
		);
	}

	/**
	 * Record commerce context from an already validated manifest.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_commerce_context_summary( array $args ): void {
		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		$products = array();
		$source   = 'import_args';
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			$products = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : array();
			$source   = isset( $manifest['source'] ) && is_scalar( $manifest['source'] ) ? (string) $manifest['source'] : 'import_args.products_manifest';
		} elseif ( isset( $args['commerce_context']['products'] ) && is_array( $args['commerce_context']['products'] ) ) {
			$products = $args['commerce_context']['products'];
			$source   = isset( $args['commerce_context']['source'] ) ? (string) $args['commerce_context']['source'] : 'commerce_context';
		}

		if ( empty( $products ) ) {
			return;
		}

		self::$conversion_report['commerce_context'] = array(
			'supplied'       => true,
			'source'         => $source,
			'product_count'  => count( $products ),
			'selector_hints' => array(),
			'diagnostics'    => array(),
		);
	}

	/**
	 * Materialize plugins required by detected source intent.
	 *
	 * @param array<string, mixed> $args      Import args.
	 * @param array<string, mixed> $artifacts Compiled artifact envelope.
	 * @param string               $theme_slug Generated theme slug.
	 * @param string               $theme_name Generated theme name.
	 * @return void
	 */
	private static function materialize_required_plugins( array $args, array $artifacts, string $theme_slug, string $theme_name ): void {
		self::$conversion_report['plugin_materialization'] = array(
			'status'  => 'skipped',
			'plugins' => array(),
		);

		$adapters = array();
		if ( self::commerce_dependency_intent()['present'] ) {
			$adapters[] = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
		}
		if ( Static_Site_Importer_Report_Diagnostics::has_materializable_form_findings( self::$conversion_report ) ) {
			$adapters[] = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
		}

		$companion_payload = isset( $artifacts['companion_plugin_payload'] ) && is_array( $artifacts['companion_plugin_payload'] ) ? $artifacts['companion_plugin_payload'] : array();
		if ( ! empty( $companion_payload ) ) {
			$companion_payload['site_slug'] = '' !== (string) ( $companion_payload['site_slug'] ?? '' ) ? (string) $companion_payload['site_slug'] : $theme_slug;
			$companion_payload['site_name'] = '' !== (string) ( $companion_payload['site_name'] ?? '' ) ? (string) $companion_payload['site_name'] : $theme_name;
		}

		if ( empty( $adapters ) && empty( $companion_payload ) ) {
			self::$conversion_report['plugin_materialization']['reason'] = 'no_plugin_backed_intent';
			return;
		}
		$reports = array();
		if ( array_key_exists( 'materialize_dependencies', $args ) && false === (bool) $args['materialize_dependencies'] ) {
			if ( ! empty( $companion_payload ) ) {
				$dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $companion_payload );
				Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( self::$conversion_report, $dependency, false );
			}
			self::$conversion_report['plugin_materialization']['reason'] = 'dependency_materialization_disabled';
			return;
		}

		if ( ! empty( $companion_payload ) ) {
			$dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $companion_payload );
			$slug       = (string) ( $dependency['slug'] ?? '' );
			if ( '' !== $slug ) {
				$reports[ $slug ] = Static_Site_Importer_Entity_Materializer_Registry::materialize_companion_dependency( $dependency );
			}
			Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( self::$conversion_report, $dependency, false );
		}
		foreach ( $adapters as $adapter ) {
			$waiver_arg = (string) ( $adapter['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$reports = array_merge( $reports, Static_Site_Importer_Entity_Materializer_Registry::materialize_plugin_dependencies( $adapter ) );
		}

		if ( empty( $reports ) ) {
			self::$conversion_report['plugin_materialization']['reason'] = 'plugin_requirements_waived';
			return;
		}

		self::$conversion_report['plugin_materialization'] = array(
			'status'  => self::plugin_materialization_status( $reports ),
			'plugins' => $reports,
		);
	}

	/**
	 * Record WooCommerce product seeding results for an already-validated manifest.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_product_seeding_report( array $args ): void {
		$manifest = isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) ? $args['products_manifest'] : null;
		if ( null === $manifest ) {
			$report_manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
			if ( is_array( $report_manifest ) && true === ( $report_manifest['valid'] ?? false ) ) {
				$manifest = isset( $report_manifest['products'] ) && is_array( $report_manifest['products'] ) ? $report_manifest['products'] : array();
			}
		}

		if ( null === $manifest ) {
			self::$conversion_report['product_seeding']           = Static_Site_Importer_Entity_Materializer_Registry::new_entity_report( Static_Site_Importer_Entity_Materializer_Registry::product_adapter() );
			self::$conversion_report['product_seeding']['reason'] = 'no_validated_manifest';
			return;
		}

		self::$conversion_report['product_seeding'] = Static_Site_Importer_Entity_Materializer_Registry::materialize( Static_Site_Importer_Entity_Materializer_Registry::product_adapter(), $manifest );
	}

	/**
	 * Record the WooCommerce dependency check for commerce-bearing imports.
	 *
	 * Commerce intent is detected when the artifact import has a validated
	 * products manifest or compiler-supplied commerce context carrying at least
	 * one product. When intent is present, WooCommerce must be active or the
	 * caller must explicitly waive the requirement via allow_missing_woocommerce.
	 * Without intent, no commerce.dependencies shape is recorded.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_commerce_dependency_check( array $args ): void {
		$intent = self::commerce_dependency_intent();
		if ( ! $intent['present'] ) {
			return;
		}

		$adapter            = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
		$waived             = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? 'allow_missing_woocommerce' ) ] );
		$dependencies       = Static_Site_Importer_Entity_Materializer_Registry::dependency_rows( $adapter, $intent, $waived );
		$woocommerce_active = Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter );

		if ( ! isset( self::$conversion_report['commerce'] ) || ! is_array( self::$conversion_report['commerce'] ) ) {
			self::$conversion_report['commerce'] = array();
		}
		self::$conversion_report['commerce']['dependencies'] = $dependencies;

		if ( $woocommerce_active ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_present',
				'severity'      => 'info',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'WooCommerce is active; commerce-bearing import will seed products.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		if ( $waived ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_waived',
				'severity'      => 'warning',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'Commerce-bearing import proceeded without WooCommerce because allow_missing_woocommerce was set; products were not seeded.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		++self::$conversion_report['quality']['commerce_dependency_failures'];
		self::$conversion_report['diagnostics'][] = array(
			'code'          => 'woocommerce_missing',
			'severity'      => 'error',
			'source'        => 'commerce.dependencies.woocommerce',
			'message'       => 'WooCommerce is required for this import. The source declared products but WooCommerce is not active. Install and activate WooCommerce, or pass allow_missing_woocommerce to import the theme without seeding products.',
			'product_count' => $intent['product_count'],
			'sources'       => $intent['sources'],
		);

		if ( isset( self::$conversion_report['product_seeding'] ) && is_array( self::$conversion_report['product_seeding'] ) ) {
			self::$conversion_report['product_seeding']['reason'] = 'woocommerce_required_but_missing';
		}
	}

	/**
	 * Materialize detected form runtime islands through the configured provider.
	 *
	 * Mirrors the commerce path: preserved <form> findings carry the source form
	 * metadata, the configured form provider maps them into working form-provider
	 * blocks, and successfully mapped findings receive the runtime-mapped signal so
	 * the honest fixture gate counts them as acceptable preservation instead of a
	 * dead, unacceptable feature-parity loss. Forms that cannot be mapped keep no
	 * signal and stay unacceptable.
	 *
	 * The grafted contact-form markup is written into the page contents in place of
	 * each finding's readable fallback, so `write_page_contents()` persists the
	 * working form rather than the dead fallback.
	 *
	 * @param array<string, mixed>   $args          Import args.
	 * @param array<string, string>  $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return void
	 */
	private static function record_form_materialization( array $args, array &$page_contents ): void {
		Static_Site_Importer_Report_Diagnostics::materialize_form_findings( self::$conversion_report, $args, $page_contents );
	}

	/**
	 * Materialize detected product-grid fallbacks through the configured shop provider.
	 *
	 * Mirrors the form path: preserved `html_product_grid_fallback` findings carry
	 * the detected product list, the shop provider seeds them into real WooCommerce
	 * products, and successfully seeded findings receive the runtime-mapped signal so
	 * the honest fixture gate counts them as acceptable preservation instead of a
	 * dead, unacceptable feature-parity loss. Commerce intent detection picks up the
	 * same findings so the WooCommerce auto-install and dependency gate fire ahead of
	 * this seeding step. Products that cannot be seeded keep no signal and stay
	 * unacceptable.
	 *
	 * @param array<string, mixed>  $args          Import args.
	 * @param array<string, string> $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return void
	 */
	private static function record_product_materialization( array $args, array &$page_contents ): void {
		Static_Site_Importer_Report_Diagnostics::materialize_product_findings( self::$conversion_report, $args, $page_contents );
	}

	/**
	 * Collapse dependency reports to the legacy plugin materialization status.
	 *
	 * @param array<string,array<string,mixed>> $reports Dependency reports keyed by plugin slug.
	 * @return string
	 */
	private static function plugin_materialization_status( array $reports ): string {
		foreach ( $reports as $report ) {
			if ( is_array( $report ) && 'failed' === (string) ( $report['status'] ?? '' ) ) {
				return 'failed';
			}
		}

		return 'completed';
	}

	/**
	 * Detect commerce intent for the active import.
	 *
	 * @return array{present:bool,sources:array<int,string>,product_count:int}
	 */
	private static function commerce_dependency_intent(): array {
		$sources       = array();
		$product_count = 0;

		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			$manifest_count = (int) ( $manifest['product_count'] ?? 0 );
			if ( $manifest_count > 0 ) {
				$sources[]     = 'products_manifest';
				$product_count = $manifest_count;
			}
		}

		$context = self::$conversion_report['commerce_context'] ?? array();
		if ( is_array( $context ) && true === ( $context['supplied'] ?? false ) ) {
			$context_count = (int) ( $context['product_count'] ?? 0 );
			if ( $context_count > 0 ) {
				$source = (string) ( $context['source'] ?? 'commerce_context' );
				if ( '' === $source ) {
					$source = 'commerce_context';
				}
				if ( ! in_array( $source, $sources, true ) ) {
					$sources[] = $source;
				}
				if ( $context_count > $product_count ) {
					$product_count = $context_count;
				}
			}
		}

		$diagnostics     = isset( self::$conversion_report['diagnostics'] ) && is_array( self::$conversion_report['diagnostics'] ) ? self::$conversion_report['diagnostics'] : array();
		$finding_indexes = Static_Site_Importer_Report_Diagnostics::product_grid_finding_indexes( $diagnostics );
		if ( ! empty( $finding_indexes ) ) {
			$finding_product_count = 0;
			foreach ( $finding_indexes as $index ) {
				$diagnostic             = $diagnostics[ $index ] ?? array();
				$products               = is_array( $diagnostic ) && isset( $diagnostic['products'] ) && is_array( $diagnostic['products'] ) ? $diagnostic['products'] : array();
				$finding_product_count += count( $products );
			}
			if ( $finding_product_count > 0 ) {
				if ( ! in_array( 'html_product_grid_fallback', $sources, true ) ) {
					$sources[] = 'html_product_grid_fallback';
				}
				if ( $finding_product_count > $product_count ) {
					$product_count = $finding_product_count;
				}
			}
		}

		return array(
			'present'       => ! empty( $sources ),
			'sources'       => $sources,
			'product_count' => $product_count,
		);
	}

	/**
	 * Analyze generated theme block documents before writing the import report.
	 *
	 * Delegates to Static_Site_Importer_Block_Document_Reporter, threading the
	 * import conversion report by reference so recorded diagnostics persist.
	 *
	 * @param array<string,string> $writes    Generated files keyed by absolute path.
	 * @param string               $theme_dir Generated theme directory.
	 * @return void
	 */
	private static function analyze_generated_theme_block_documents( array $writes, string $theme_dir ): void {
		Static_Site_Importer_Block_Document_Reporter::analyze_generated_theme_block_documents( $writes, $theme_dir, self::$conversion_report );
	}
}
