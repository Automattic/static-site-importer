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

if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-transformer-adapter.php';
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
		if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer_adapter', 'Static Site Importer transformer adapter is required to import a website artifact.' );
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
		$compiled         = ( new Static_Site_Importer_Transformer_Adapter() )->compile_website_artifact( $artifact, array_merge( array( 'include_conversion_report' => true ), $compiler_options ) );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$document_pages   = self::website_artifact_source_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		if ( 'failed' === (string) ( $compiled['status'] ?? '' ) || empty( $document_pages ) ) {
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce materializable document pages.', $compiled );
		}

		return self::import_compiled_website_artifact( $compiled, $args );
	}

	/**
	 * Materialize a compiled website artifact directly into WordPress theme artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function import_compiled_website_artifact( array $compiled, array $args = array() ) {
		$artifacts    = isset( $compiled['artifacts'] ) && is_array( $compiled['artifacts'] ) ? $compiled['artifacts'] : array();
		$identity     = Static_Site_Importer_Site_Identity::resolve(
			array(
				'site_title' => isset( $args['site_title'] ) ? (string) $args['site_title'] : '',
				'name'       => isset( $args['name'] ) ? (string) $args['name'] : '',
				'slug'       => isset( $args['slug'] ) ? (string) $args['slug'] : '',
			)
		);
		$theme_name   = $identity['name'];
		$theme_slug   = $identity['slug'];
		$site_title   = isset( $args['site_title'] ) && '' !== trim( (string) $args['site_title'] ) ? sanitize_text_field( (string) $args['site_title'] ) : '';

		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;
		if ( file_exists( $theme_dir ) && empty( $args['overwrite'] ) ) {
			return new WP_Error( 'static_site_importer_theme_exists', sprintf( 'Theme already exists: %s', $theme_slug ) );
		}

		$import_run_id             = self::import_run_id( $args );
		$source_artifact_reference = self::source_artifact_reference_from_compiled( $compiled, $args );
		$source_metadata           = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$source_metadata['source'] = 'website_artifact';
		$html_path                 = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );
		self::$conversion_report   = Static_Site_Importer_Report_Diagnostics::new_conversion_report( $html_path, $source_metadata );
		self::$conversion_report['import_run_id']            = $import_run_id;
		self::$conversion_report['source_artifact']           = $source_artifact_reference;
		self::$conversion_report['source_of_truth']['import_run_id'] = $import_run_id;
		self::$conversion_report['source_of_truth']['artifact']      = $source_artifact_reference;
		Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( self::$conversion_report, $compiled );
		Static_Site_Importer_Report_Diagnostics::record_direct_website_artifact_source_summary( self::$conversion_report, $compiled );
		self::record_products_manifest_from_import_args( $args, $compiled );
		self::record_commerce_context_summary( $args );

		self::$active_theme_uri = trailingslashit( get_theme_root_uri( $theme_slug ) ) . $theme_slug;
		$asset_policy = Static_Site_Importer_Asset_Reporter::initialize_report( self::$conversion_report, $args );
		if ( is_wp_error( $asset_policy ) ) {
			return $asset_policy;
		}

		$document_pages = self::website_artifact_source_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		$page_targets = Static_Site_Importer_Page_Materializer::page_targets( $document_pages );
		$page_ids     = Static_Site_Importer_Page_Materializer::create_page_shells( $document_pages );
		if ( is_wp_error( $page_ids ) ) {
			return $page_ids;
		}

		$permalinks     = Static_Site_Importer_Page_Materializer::page_permalinks( $page_ids );
		$materialized = self::materialize_website_artifact_files_to_theme( $theme_dir, $artifacts, false );
		if ( is_wp_error( $materialized ) ) {
			return $materialized;
		}

		$page_artifacts = Static_Site_Importer_Page_Materializer::page_artifacts( $document_pages, $theme_slug, $materialized['assets'], $permalinks );
		foreach ( $page_artifacts['diagnostics'] as $diagnostic ) {
			self::$conversion_report['diagnostics'][] = $diagnostic;
		}

		Static_Site_Importer_Document_Metadata_Reporter::record( self::$conversion_report, $artifacts );

		$template_part_writes = self::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $template_part_writes ) ) {
			return $template_part_writes;
		}
		$has_header_part      = isset( $template_part_writes[ $theme_dir . '/parts/header.html' ] );
		$has_footer_part      = isset( $template_part_writes[ $theme_dir . '/parts/footer.html' ] );

		$visual_repair_styles = self::visual_repair_styles_from_artifacts( $artifacts );

		$stylesheet_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes(
			$theme_dir,
			$theme_name,
			$materialized['css'],
			$materialized['assets'],
			$visual_repair_styles
		);

		$writes = array_merge(
			$stylesheet_writes,
			Static_Site_Importer_Theme_Materializer::base_theme_writes( $theme_dir, $theme_slug, $theme_name, $materialized['css'], $has_header_part, $has_footer_part, $materialized['scripts'], $materialized['stylesheets'] )
		);
		$writes = array_merge( $writes, $template_part_writes );
		self::analyze_imported_page_content_documents( $document_pages, $page_artifacts['contents'] );

		self::record_source_documents_summary( $artifacts['documents'] ?? array(), $document_pages, $page_ids, $permalinks );
		foreach ( array_keys( $page_artifacts['patterns'] ) as $filename ) {
			$page = $document_pages[ $filename ] ?? null;
			$slug = $page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_slug( $filename, $page ) : Static_Site_Importer_Page_Materializer::page_slug( $filename );
			if ( '' === $slug ) {
				continue;
			}

			$writes[ $theme_dir . '/templates/page-' . $slug . '.html' ] = Static_Site_Importer_Theme_Materializer::content_template( '', $has_header_part, $has_footer_part );
		}

		// Preserved island JS is theme-independent: it rides the generated
		// companion plugin (scoped, enqueued from the plugin), never the theme.
		// The theme deliberately no longer materializes an `assets/site.js`
		// blob — that anti-pattern dies on theme switch. Legitimate per-asset
		// site scripts still ride the theme via $materialized['scripts'].

		self::analyze_generated_theme_block_documents( $writes, $theme_dir );
		self::$conversion_report['theme_slug'] = $theme_slug;
		self::materialize_required_plugins( $args );
		self::record_product_seeding_report( $args );
		self::record_commerce_dependency_check( $args );
		self::record_form_materialization( $args );
		self::record_product_materialization( $args );
		$source_of_truth_manifest                    = self::source_of_truth_manifest( $import_run_id, $source_artifact_reference, $theme_dir, $theme_slug, $page_targets, $page_ids, $permalinks, $writes, $materialized );
		self::$conversion_report['source_of_truth'] = $source_of_truth_manifest;
		$quality                                    = Static_Site_Importer_Report_Diagnostics::finalize_report( self::$conversion_report, $args );
		$validation_result      = self::$conversion_report['import_validation_result'] ?? array();
		$finding_packets        = self::$conversion_report['finding_packets'] ?? array();
		if ( ! empty( $quality['fail_import'] ) ) {
			return new WP_Error(
				'static_site_importer_quality_gate_failed',
				'Import failed quality gates; materialization was not completed.',
				array(
					'status'                   => 422,
					'theme_slug'               => $theme_slug,
					'theme_name'               => $theme_name,
					'quality'                  => $quality,
					'import_report_summary'    => self::$conversion_report['compact_summary'] ?? array(),
					'import_validation_result' => $validation_result,
					'finding_packets'          => $finding_packets,
					'source_documents'         => self::$conversion_report['source_documents'] ?? array(),
				)
			);
		}

		$cleanup = self::cleanup_stale_generated_theme_files( $theme_dir, $source_of_truth_manifest, $args );
		if ( is_wp_error( $cleanup ) ) {
			return $cleanup;
		}
		$source_of_truth_manifest['cleanup']       = $cleanup;
		self::$conversion_report['source_of_truth'] = $source_of_truth_manifest;

		$report_json            = wp_json_encode( self::$conversion_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$validation_result_json = wp_json_encode( $validation_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$finding_packets_json   = wp_json_encode( $finding_packets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $report_json ) {
			return new WP_Error( 'static_site_importer_report_encode_failed', 'Failed to encode import report JSON.' );
		}
		if ( false === $validation_result_json ) {
			return new WP_Error( 'static_site_importer_validation_result_encode_failed', 'Failed to encode import validation result JSON.' );
		}
		if ( false === $finding_packets_json ) {
			return new WP_Error( 'static_site_importer_finding_packets_encode_failed', 'Failed to encode finding packets JSON.' );
		}

		$result = Static_Site_Importer_Theme_Materializer::ensure_dirs( $theme_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$materialized_write = self::materialize_website_artifact_files_to_theme( $theme_dir, $artifacts, true, false );
		if ( is_wp_error( $materialized_write ) ) {
			return $materialized_write;
		}

		$result = Static_Site_Importer_Page_Materializer::write_page_contents( $document_pages, $page_ids, $page_artifacts['contents'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = Static_Site_Importer_Page_Materializer::record_page_provenance( $document_pages, $page_ids, $page_targets, $source_of_truth_manifest );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$manifest_json = wp_json_encode( $source_of_truth_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $manifest_json ) {
			return new WP_Error( 'static_site_importer_manifest_encode_failed', 'Failed to encode source-of-truth manifest JSON.' );
		}
		$writes[ $theme_dir . '/static-site-importer-manifest.json' ] = $manifest_json . "\n";
		$writes[ $theme_dir . '/import-report.json' ]            = $report_json . "\n";
		$writes[ $theme_dir . '/import-validation-result.json' ] = $validation_result_json . "\n";
		$writes[ $theme_dir . '/finding-packets.json' ]          = $finding_packets_json . "\n";
		foreach ( $writes as $path => $content ) {
			$result = Static_Site_Importer_Theme_Materializer::write_file( $path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$external_report_path            = '';
		$external_validation_result_path = '';
		$external_finding_packets_path   = '';
		if ( isset( $args['report'] ) && '' !== trim( (string) $args['report'] ) ) {
			$external_report_path = (string) $args['report'];
			$result               = Static_Site_Importer_Theme_Materializer::write_external_report( $external_report_path, $report_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$external_report_dir              = dirname( $external_report_path );
			$external_validation_result_path = trailingslashit( $external_report_dir ) . 'import-validation-result.json';
			$external_finding_packets_path   = trailingslashit( $external_report_dir ) . 'finding-packets.json';
			$result                          = Static_Site_Importer_Theme_Materializer::write_external_report( $external_validation_result_path, $validation_result_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = Static_Site_Importer_Theme_Materializer::write_external_report( $external_finding_packets_path, $finding_packets_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			$front_page_id = self::front_page_id( $page_ids );
			if ( 0 === $front_page_id && ! empty( $page_ids ) ) {
				$front_page_id = (int) reset( $page_ids );
			}

			switch_theme( $theme_slug );
			if ( '' !== $site_title ) {
				update_option( 'blogname', $site_title );
			}
			if ( 0 !== $front_page_id ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}
		}

		return array(
			'theme_slug'                      => $theme_slug,
			'theme_name'                      => $theme_name,
			'theme_dir'                       => $theme_dir,
			'report_path'                     => $theme_dir . '/import-report.json',
			'validation_result_path'          => $theme_dir . '/import-validation-result.json',
			'finding_packets_path'            => $theme_dir . '/finding-packets.json',
			'manifest_path'                   => $theme_dir . '/static-site-importer-manifest.json',
			'external_report_path'            => $external_report_path,
			'external_validation_result_path' => $external_validation_result_path,
			'external_finding_packets_path'   => $external_finding_packets_path,
			'import_report_summary'           => self::$conversion_report['compact_summary'],
			'import_validation_result'        => $validation_result,
			'finding_packets'                 => $finding_packets,
			'source_of_truth'                 => $source_of_truth_manifest,
			'pages'                           => $page_ids,
			'quality'                         => $quality,
			'source_documents'                => self::$conversion_report['source_documents'],
			'progress_events'                 => self::import_progress_events( $import_run_id, $theme_slug, $page_ids, $writes, $quality, $validation_result, $external_report_path ),
		);
	}

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
		$review_pending    = ! $quality_passed || $diagnostic_count > 0;
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
		$transformer = new Static_Site_Importer_Transformer_Adapter();
		if ( ! $transformer->supports_blocks_to_html() ) {
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
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page', $transformer ), $theme_slug, null !== $stylesheet ),
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
				$page_html = $transformer->blocks_to_html( isset( $page->post_content ) ? (string) $page->post_content : '' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template, $transformer ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
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
	private static function export_theme_chrome_html( string $theme_dir, string $template, Static_Site_Importer_Transformer_Adapter $transformer ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html', $transformer );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html', $transformer );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = $transformer->blocks_to_html( $template_html );
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
	private static function convert_theme_block_file_to_html( string $path, Static_Site_Importer_Transformer_Adapter $transformer ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : $transformer->blocks_to_html( $content );
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
			'schema'        => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
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
	private static function source_of_truth_manifest( string $import_run_id, array $artifact, string $theme_dir, string $theme_slug, array $page_targets, array $page_ids, array $permalinks, array $writes, array $materialized ): array {
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
		foreach ( array( 'static-site-importer-manifest.json', 'import-report.json', 'import-validation-result.json', 'finding-packets.json' ) as $path ) {
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
	 * Normalize website artifact document rows into source pages.
	 *
	 * SSI consumes the compiled-site page contract when available, then attaches
	 * the referenced `artifacts.documents[]` block markup for WordPress
	 * materialization.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string, Static_Site_Importer_Source_Page>|WP_Error
	 */
	private static function website_artifact_source_pages( array $compiled ) {
		$artifacts = isset( $compiled['artifacts'] ) && is_array( $compiled['artifacts'] ) ? $compiled['artifacts'] : array();
		$documents = isset( $artifacts['documents'] ) && is_array( $artifacts['documents'] ) ? $artifacts['documents'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( ! empty( $site['pages'] ) && is_array( $site['pages'] ) ) {
			if ( 'blocks-engine/php-transformer/materialization-plan/v1' === (string) ( $site['schema'] ?? '' ) ) {
				return self::source_pages_from_materialization_plan( $site, $documents );
			}

			if ( 'blocks-engine/php-transformer/compiled-site/v1' !== (string) ( $site['schema'] ?? '' ) ) {
				return new WP_Error( 'static_site_importer_compiled_site_missing', 'Website artifact document pages require a supported compiled-site contract.' );
			}

			$documents = self::documents_from_compiled_site_pages( $site['pages'], $documents );
			if ( is_wp_error( $documents ) ) {
				return $documents;
			}
		}

		$pages     = array();

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact( $document );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$pages[ $page->source_key() ] = $page;
		}

		return $pages;
	}

	/**
	 * Build source pages directly from Blocks Engine materialization-plan page rows.
	 *
	 * @param array<string,mixed> $site      Materialization-plan site report.
	 * @param array<int,mixed>    $documents Document artifacts with serialized block markup.
	 * @return array<string, Static_Site_Importer_Source_Page>|WP_Error
	 */
	private static function source_pages_from_materialization_plan( array $site, array $documents = array() ) {
		$site_pages = isset( $site['pages'] ) && is_array( $site['pages'] ) ? $site['pages'] : array();
		$routes     = self::materialization_plan_routes_by_source_path( $site );
		if ( is_wp_error( $routes ) ) {
			return $routes;
		}
		$documents_by_source = self::document_artifacts_by_source_path( $documents );

		$pages = array();
		foreach ( $site_pages as $page_row ) {
			if ( ! is_array( $page_row ) ) {
				continue;
			}

			$source_path = isset( $page_row['source_path'] ) && is_scalar( $page_row['source_path'] ) ? self::normalize_route_path( (string) $page_row['source_path'] ) : '';
			if ( '' !== $source_path && isset( $routes[ $source_path ] ) ) {
				$page_row = array_merge( $page_row, $routes[ $source_path ] );
			}
			if ( '' !== $source_path && ( ! isset( $page_row['block_markup'] ) || ! is_scalar( $page_row['block_markup'] ) || '' === trim( (string) $page_row['block_markup'] ) ) && isset( $documents_by_source[ $source_path ] ) ) {
				$document = $documents_by_source[ $source_path ];
				foreach ( array( 'block_markup', 'content', 'post_content' ) as $content_key ) {
					if ( isset( $document[ $content_key ] ) && is_scalar( $document[ $content_key ] ) && '' !== trim( (string) $document[ $content_key ] ) ) {
						$page_row['block_markup'] = (string) $document[ $content_key ];
						break;
					}
				}
			}

			$page = Static_Site_Importer_Source_Page::from_materialization_plan_page( $page_row );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$pages[ $page->source_key() ] = $page;
		}

		return $pages;
	}

	/**
	 * Normalize document artifacts by source path for materialization-plan joins.
	 *
	 * @param array<int,mixed> $documents Document artifact rows.
	 * @return array<string,array<string,mixed>>
	 */
	private static function document_artifacts_by_source_path( array $documents ): array {
		$documents_by_source = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = '';
			foreach ( array( 'source_path', 'path', 'slug' ) as $key ) {
				if ( isset( $document[ $key ] ) && is_scalar( $document[ $key ] ) && '' !== trim( (string) $document[ $key ] ) ) {
					$source_path = self::normalize_route_path( (string) $document[ $key ] );
					break;
				}
			}

			if ( '' !== $source_path ) {
				$documents_by_source[ $source_path ] = $document;
			}
		}

		return $documents_by_source;
	}

	/**
	 * Normalize native materialization-plan route rows by source path.
	 *
	 * @param array<string,mixed> $site Materialization-plan site report.
	 * @return array<string,array<string,mixed>>|WP_Error Route rows keyed by normalized source path.
	 */
	private static function materialization_plan_routes_by_source_path( array $site ) {
		if ( ! array_key_exists( 'routes', $site ) ) {
			return array();
		}

		if ( ! is_array( $site['routes'] ) ) {
			return new WP_Error( 'static_site_importer_materialization_plan_routes_invalid', 'Blocks Engine materialization_plan.routes must be an array.' );
		}

		$routes = array();
		foreach ( $site['routes'] as $route ) {
			if ( ! is_array( $route ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_route_invalid', 'Blocks Engine materialization-plan route entries must be arrays.' );
			}

			$source_path = isset( $route['source_path'] ) && is_scalar( $route['source_path'] ) ? self::normalize_route_path( (string) $route['source_path'] ) : '';
			if ( '' === $source_path ) {
				return new WP_Error( 'static_site_importer_materialization_plan_route_missing_source', 'Blocks Engine materialization-plan route is missing source_path.' );
			}

			$normalized = array();
			foreach ( array( 'slug', 'title', 'post_type', 'status', 'entrypoint', 'body_format' ) as $key ) {
				if ( isset( $route[ $key ] ) && is_scalar( $route[ $key ] ) && '' !== trim( (string) $route[ $key ] ) ) {
					$normalized[ $key ] = (string) $route[ $key ];
				}
			}
			if ( ! isset( $normalized['slug'] ) && isset( $route['target_slug'] ) && is_scalar( $route['target_slug'] ) && '' !== trim( (string) $route['target_slug'] ) ) {
				$normalized['slug'] = (string) $route['target_slug'];
			}
			if ( ! isset( $normalized['title'] ) && isset( $route['target_title'] ) && is_scalar( $route['target_title'] ) && '' !== trim( (string) $route['target_title'] ) ) {
				$normalized['title'] = (string) $route['target_title'];
			}

			if ( isset( $route['route_key'] ) && is_scalar( $route['route_key'] ) && '' !== trim( (string) $route['route_key'] ) ) {
				$normalized['route_key'] = (string) $route['route_key'];
			}
			$route_path = '';
			if ( isset( $route['path'] ) && is_scalar( $route['path'] ) && '' !== trim( (string) $route['path'] ) ) {
				$route_path = (string) $route['path'];
			} elseif ( isset( $route['target_path'] ) && is_scalar( $route['target_path'] ) && '' !== trim( (string) $route['target_path'] ) ) {
				$route_path = (string) $route['target_path'];
			}
			if ( '' !== $route_path ) {
				$normalized['route_path'] = self::normalize_route_path( $route_path );
				if ( '' === $normalized['route_path'] && ! empty( $route['source_relation'] ) && 'entrypoint' === (string) $route['source_relation'] ) {
					$normalized['entrypoint'] = '1';
				}
				if ( '' === $normalized['route_path'] && isset( $normalized['slug'] ) && in_array( sanitize_title( $normalized['slug'] ), array( 'index', 'home' ), true ) ) {
					$normalized['slug'] = 'home';
				}
			}

			if ( isset( $route['metadata'] ) && is_array( $route['metadata'] ) ) {
				$normalized['metadata'] = $route['metadata'];
			}

			$routes[ $source_path ] = $normalized;
		}

		return $routes;
	}

	/**
	 * Attach document markup to compiled-site page contracts.
	 *
	 * @param array<int,array<string,mixed>> $site_pages Compiled-site page rows.
	 * @param array<int,mixed>               $documents  Document artifacts.
	 * @return array<int,array<string,mixed>>|WP_Error Document artifacts ordered by compiled-site pages.
	 */
	private static function documents_from_compiled_site_pages( array $site_pages, array $documents ) {
		$documents_by_source = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = isset( $document['source_path'] ) && is_scalar( $document['source_path'] ) ? (string) $document['source_path'] : '';
			if ( '' !== $source_path ) {
				$documents_by_source[ $source_path ] = $document;
			}
		}

		$compiled_documents = array();
		foreach ( $site_pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_source', 'Compiled-site page is missing source_path.' );
			}
			$metadata  = isset( $page['metadata'] ) && is_array( $page['metadata'] ) ? $page['metadata'] : array();
			$slug      = isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
			if ( '' === $slug ) {
				$slug = Static_Site_Importer_Page_Materializer::page_slug( $source_path );
			}
			if ( ! empty( $page['entrypoint'] ) && in_array( $slug, array( 'index', 'home' ), true ) ) {
				$slug = 'home';
			}
			$route_key = isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? trim( (string) $page['route_key'] ) : $slug;
			$post_type = isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? trim( (string) $page['post_type'] ) : '';
			if ( '' === $post_type && isset( $metadata['post_type'] ) && is_scalar( $metadata['post_type'] ) ) {
				$post_type = trim( (string) $metadata['post_type'] );
			}
			if ( '' === $post_type && in_array( (string) ( $page['kind'] ?? 'html' ), array( 'html', 'document', 'markdown', 'mdx' ), true ) ) {
				$post_type = 'page';
			}
			if ( '' === $route_key || '' === $slug || '' === $post_type ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_identity_incomplete', sprintf( 'Compiled-site page is missing route_key, slug, or post_type: %s', $source_path ) );
			}
			if ( ! isset( $documents_by_source[ $source_path ] ) && ( ! isset( $page['block_markup'] ) || ! is_scalar( $page['block_markup'] ) || '' === trim( (string) $page['block_markup'] ) ) ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_document', sprintf( 'Compiled-site page does not reference a document artifact: %s', $source_path ) );
			}

			$document = array_merge( $documents_by_source[ $source_path ] ?? array(), array_filter(
				array(
					'source_path'  => $source_path,
					'slug'         => $slug,
					'route_key'    => $route_key,
					'post_type'    => $post_type,
					'title'        => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
					'block_markup' => isset( $page['block_markup'] ) && is_scalar( $page['block_markup'] ) ? (string) $page['block_markup'] : '',
				),
				static fn ( string $value ): bool => '' !== $value
			) );

			if ( ! empty( $page['entrypoint'] ) ) {
				$document['entrypoint'] = '1';
			}

			$compiled_documents[] = $document;
		}

		return $compiled_documents;
	}

	/**
	 * Normalize template part artifacts into generated theme writes.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @return array<string,string>|WP_Error Absolute write paths keyed to serialized block markup.
	 */
	private static function template_part_artifact_writes( string $theme_dir, array $artifacts ) {
		$result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['reports'] as $report ) {
			self::$conversion_report['generated_theme']['template_parts'][] = $report;
		}

		return $result['writes'];
	}

	/**
	 * Check whether a source filename is the site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_index_source_filename( string $filename ): bool {
		return in_array( strtolower( basename( $filename ) ), array( 'index.html' ), true );
	}

	/**
	 * Get imported front page ID for HTML index sources.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by source filename.
	 * @return int
	 */
	private static function front_page_id( array $page_ids ): int {
		foreach ( $page_ids as $filename => $page_id ) {
			if ( self::is_index_source_filename( $filename ) ) {
				return (int) $page_id;
			}
		}

		return 0;
	}

	/**
	 * Analyze canonical imported page post content for conversion quality.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,string>                           $contents Converted block markup keyed by filename.
	 * @return void
	 */
	private static function analyze_imported_page_content_documents( array $pages, array $contents ): void {
		foreach ( $pages as $filename => $page ) {
			$slug    = Static_Site_Importer_Page_Materializer::page_slug( $filename, $page );
			$source  = '' !== $slug ? 'posts/page-' . $slug . '.post_content' : 'posts/' . sanitize_title( $filename ) . '.post_content';
			$content = $contents[ $filename ] ?? '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$analysis = array_merge(
				Static_Site_Importer_Block_Document_Reporter::analyze_generated_block_document( $source, $content, self::$conversion_report ),
				array(
					'target'      => 'materialized_post_content',
					'source_path' => $page->source_key(),
					'slug'        => $slug,
					'post_type'   => Static_Site_Importer_Page_Materializer::page_post_type( $page ),
				)
			);
			self::$conversion_report['materialized_content']['block_documents'][] = $analysis;
			self::$conversion_report['generated_theme']['block_documents'][]      = $analysis;
		}
	}

	/**
	 * Normalize a route-like path without resolving outside the source root.
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	private static function normalize_route_path( string $path ): string {
		$path_without_query = strtok( $path, '?' );
		$path               = str_replace( '\\', '/', false === $path_without_query ? $path : $path_without_query );
		$path               = ltrim( $path, '/' );
		$segments           = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Check whether a URL is local to the imported source tree.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	private static function is_local_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return false;
		}

		$lower = strtolower( $url );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $lower ) || str_starts_with( $lower, '//' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Prepare or write compiler-emitted files that can be consumed without re-importing HTML.
	 *
	 * @param string              $theme_dir          Theme directory.
	 * @param array<string,mixed> $artifacts          WordPress artifacts from Blocks Engine.
	 * @param bool                $write_files        Whether to write materialized asset files.
	 * @param bool                $record_diagnostics Whether to append materialization diagnostics to the report.
	 * @return array{css:string,js:string,assets:array<string,array<string,mixed>>,scripts:array<int,array<string,mixed>>,stylesheets:array<int,array<string,mixed>>}|WP_Error
	 */
	private static function materialize_website_artifact_files_to_theme( string $theme_dir, array $artifacts, bool $write_files = true, bool $record_diagnostics = true ) {
		$result = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, self::$active_theme_uri, $artifacts, $write_files );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $record_diagnostics ) {
			foreach ( $result['diagnostics'] as $diagnostic ) {
				self::$conversion_report['diagnostics'][] = $diagnostic;
			}
		}
		return array(
			'css'         => $result['css'],
			'js'          => $result['js'],
			'assets'      => $result['assets'],
			'scripts'     => $result['scripts'],
			'stylesheets' => $result['stylesheets'],
		);
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
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function materialize_required_plugins( array $args ): void {
		self::$conversion_report['plugin_materialization'] = array(
			'status'  => 'skipped',
			'plugins' => array(),
		);

		$intent  = self::commerce_dependency_intent();
		$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
		if ( ! $intent['present'] ) {
			self::$conversion_report['plugin_materialization']['reason'] = 'no_plugin_backed_intent';
			return;
		}
		if ( ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? 'allow_missing_woocommerce' ) ] ) ) {
			self::$conversion_report['plugin_materialization']['reason'] = 'woocommerce_requirement_waived';
			return;
		}
		if ( array_key_exists( 'materialize_dependencies', $args ) && false === (bool) $args['materialize_dependencies'] ) {
			self::$conversion_report['plugin_materialization']['reason'] = 'dependency_materialization_disabled';
			return;
		}

		$reports = Static_Site_Importer_Entity_Materializer_Registry::materialize_plugin_dependencies( $adapter );
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
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_form_materialization( array $args ): void {
		Static_Site_Importer_Report_Diagnostics::materialize_form_findings( self::$conversion_report, $args );
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
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_product_materialization( array $args ): void {
		Static_Site_Importer_Report_Diagnostics::materialize_product_findings( self::$conversion_report, $args );
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
