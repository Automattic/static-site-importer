<?php
/**
 * Computes public receipt projections without touching materialized state.
 *
 * Theme_Generator owns the transaction boundaries around these projections.
 */
class Static_Site_Importer_Receipt_Projection {
	/** Compose the report and source-of-truth manifest before reconciliation. */
	public static function compose( array $receipt, array $args, array $lifecycle, array $dependencies, array $entities, string $import_run_id, array $transformer_provenance, array $build ): array {
		$plan = $receipt['plan'];
		$theme = $receipt['theme'];
		$diagnostics = Static_Site_Importer_Report_Diagnostics::after_completed_entity_bindings( is_array( $plan['diagnostics'] ?? null ) ? $plan['diagnostics'] : array(), $receipt );
		if ( ! empty( $args['compiler_options'] ) && is_array( $args['compiler_options'] ) ) {
			$diagnostics[] = array( 'code' => 'static_site_importer_compiler_options_ignored', 'type' => 'static-site-importer', 'message' => 'The compiler_options import argument is accepted for backward compatibility but is no longer honored because the Blocks Engine compiler ignores it at the artifact compile boundary.' );
		}
		$diagnostics = array_merge( $diagnostics, $lifecycle['diagnostics'] ?? array(), Static_Site_Importer_Report_Diagnostics::provider_entity_decline_diagnostics( $entities ) );
		$gutenberg_gaps = is_array( $receipt['extensions']['gutenberg_gaps'] ?? null ) ? $receipt['extensions']['gutenberg_gaps'] : array();
		$diagnostics = array_merge( $diagnostics, $gutenberg_gaps );
		if ( is_array( $args['missing_author_stylesheet_diagnostics'] ?? null ) ) {
			$diagnostics = array_merge( $diagnostics, array_values( array_filter( $args['missing_author_stylesheet_diagnostics'], 'is_array' ) ) );
		}
		$report = Static_Site_Importer_Import_Report::from_array(
			array(
				'schema' => Static_Site_Importer_Import_Report::SCHEMA,
				'import_run_id' => $import_run_id,
				'plan_identity' => $receipt['plan_identity'] ?? array(),
				'blocks_engine' => array( 'transformer' => $transformer_provenance, 'wordpress_site_plan' => $plan, 'gutenberg_gaps' => $gutenberg_gaps ),
				'quality' => is_array( $plan['quality'] ?? null ) ? $plan['quality'] : array(),
				'client_script_policy' => $args['client_script_policy_report'] ?? array(),
				'theme_materialization' => $receipt['theme_materialization'] ?? array(),
				'diagnostics' => $diagnostics,
				'entity_lifecycle' => array( 'status' => $lifecycle['status'] ?? 'not_requested', 'entities' => $entities, 'dependencies' => $dependencies ),
				'companion_plugin_materialization' => $receipt['completed']['companion_plugin'] ?? array( 'status' => 'skipped', 'reason' => 'companion_plugin_payload_absent' ),
				'generated_theme' => array(
					'document_metadata' => self::document_metadata( $plan ),
					'template_parts' => array_map( static fn( array $part ): array => array( 'path' => 'parts/' . $part['slug'] . '.html', 'content' => $part['resolved_block_markup'] ), $plan['template_parts'] ),
					'block_documents' => array_map( static function ( array $page ) use ( $receipt ): array {
						$document = array( 'path' => 'posts/page-' . ( ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'] ) . '.post_content', 'content' => $receipt['completed']['materialized_pages'][ $page['source_path'] ]['block_markup'] ?? $page['resolved_block_markup'] ?? '' );
						if ( isset( $page['core_html_block_count'] ) ) { $document['core_html_block_count'] = $page['core_html_block_count']; }
						return $document;
					}, $plan['pages'] ),
				),
				'source_documents' => array( 'source' => 'blocks_engine', 'total_count' => count( $plan['pages'] ), 'blocks_engine_document_count' => count( $plan['pages'] ), 'blocks_engine_documents' => array_map( static fn( array $page ): array => array( 'source_path' => $page['source_path'], 'slug' => ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'], 'permalink' => ! empty( $page['entrypoint'] ) ? '/' : '/' . $page['slug'] . '/' ), $plan['pages'] ), 'counts_by_format' => array( 'html' => count( $plan['pages'] ), 'markdown' => 0, 'mdx' => 0 ) ),
			)
		);
		$report['source_artifact'] = array( 'hash' => (string) ( $args['artifact_hash'] ?? $plan['source']['source_hash'] ) );
		$report['materialization_receipt'] = $receipt;
		Static_Site_Importer_Block_Document_Reporter::analyze_materialized_block_documents( $report['generated_theme']['block_documents'], $report );
		$artifact = array_merge( is_array( $args['source_artifact_reference'] ?? null ) ? $args['source_artifact_reference'] : array(), array_filter( array( 'schema' => $plan['source']['schema'] ?? null, 'source_hash' => $plan['source']['source_hash'] ?? null, 'entry_path' => $plan['source']['entry_path'] ?? null ) ) );
		$artifact['hash'] = (string) ( $args['artifact_hash'] ?? $artifact['hash'] ?? $plan['source']['source_hash'] );
		$desired_files = array_map( static fn( array $write ): array => array( 'path' => $write['target_path'], 'kind' => $write['kind'] ), array_values( array_filter( $plan['writes'] ?? array(), static fn( $write ): bool => is_array( $write ) && is_scalar( $write['target_path'] ?? null ) && is_scalar( $write['kind'] ?? null ) ) ) );
		$desired_paths = array_fill_keys( array_column( $desired_files, 'path' ), true );
		foreach ( $receipt['completed']['files'] ?? array() as $file ) {
			$path = is_array( $file ) && is_scalar( $file['target_path'] ?? null ) ? (string) $file['target_path'] : '';
			if ( '' !== $path && ! isset( $desired_paths[ $path ] ) ) { $desired_paths[ $path ] = true; $desired_files[] = array( 'path' => $path, 'kind' => is_scalar( $file['kind'] ?? null ) ? (string) $file['kind'] : 'materialized_theme_file' ); }
		}
		$desired_assets = array_map( static fn( array $asset ): array => array( 'source_path' => $asset['source_path'], 'theme_path' => $asset['target_path'] ), $plan['assets'] );
		foreach ( $receipt['completed']['font_materialization']['files'] ?? array() as $file ) {
			$path = is_array( $file ) && is_scalar( $file['target_path'] ?? null ) ? (string) $file['target_path'] : '';
			if ( '' !== $path && ! isset( $desired_paths[ $path ] ) ) { $desired_paths[ $path ] = true; $desired_files[] = array( 'path' => $path, 'kind' => 'font_materialization' ); $desired_assets[] = array( 'source_path' => (string) ( $file['source_path'] ?? 'theme.font_materialization' ), 'theme_path' => $path ); }
		}
		$manifest = array( 'schema' => 'static-site-importer/source-of-truth-manifest/v1', 'version' => 1, 'import_run_id' => $report['import_run_id'], 'build' => $build, 'artifact' => array_merge( $artifact, array( 'provenance' => $plan['source']['provenance'] ) ), 'manifest_path' => 'static-site-importer-manifest.json', 'generated_theme' => array( 'slug' => $theme['slug'], 'dir' => $theme['dir'] ), 'desired' => array( 'pages' => array(), 'files' => array_merge( $desired_files, array( array( 'path' => 'static-site-importer-manifest.json', 'kind' => 'ssi_manifest' ) ) ), 'assets' => $desired_assets ) );
		foreach ( $plan['pages'] as $page ) {
			$source_path = $page['source_path']; $match = null;
			foreach ( $receipt['existing_matches']['pages'] ?? array() as $candidate ) { if ( ( $candidate['source_path'] ?? '' ) === $source_path ) { $match = $candidate; break; } }
			$manifest['desired']['pages'][] = array( 'source_path' => $source_path, 'materialized_post_id' => (int) ( $receipt['completed']['pages'][ $source_path ] ?? 0 ), 'reconciliation_identity' => $page['reconciliation_identity'], 'content_hash' => $receipt['completed']['materialized_pages'][ $source_path ]['content_hash'] ?? $page['content_hash'], 'route' => $page['route']['path'], 'permalink' => $match['permalink'] ?? $page['route']['path'], 'slug' => $page['slug'], 'post_type' => $page['post_type'], 'protected' => ! empty( $match['protected'] ), 'provenance_meta_key' => ! empty( $match['protected'] ) ? '' : '_static_site_importer_provenance' );
		}
		return array( 'report' => $report, 'manifest' => $manifest );
	}

	/** Preserve prior batch declarations that this partial run does not replace. */
	public static function merge_previous_manifest( array $manifest, array $previous ): array {
		if ( ! is_array( $previous['desired'] ?? null ) ) { return $manifest; }
		foreach ( array( 'pages', 'files', 'assets' ) as $kind ) {
			$keys = array();
			foreach ( $manifest['desired'][ $kind ] as $item ) { $keys[ (string) ( $item['source_path'] ?? $item['path'] ?? $item['theme_path'] ?? '' ) ] = true; }
			foreach ( $previous['desired'][ $kind ] ?? array() as $item ) { $key = (string) ( $item['source_path'] ?? $item['path'] ?? $item['theme_path'] ?? '' ); if ( '' !== $key && ! isset( $keys[ $key ] ) ) { $manifest['desired'][ $kind ][] = $item; } }
		}
		return $manifest;
	}

	/** Finalize report quality before cleanup mutates the receipt. */
	public static function finalize_report( Static_Site_Importer_Import_Report $report, array $args ): array {
		return Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
	}

	/** Refresh report-derived projections after cleanup has updated the receipt. */
	public static function finalize( Static_Site_Importer_Import_Report $report, array $manifest, array &$receipt, array $args, array $quality ): array {
		$report['source_of_truth'] = $manifest;
		$receipt['quality_budget_admission'] = Static_Site_Importer_Quality_Budget_Admission::evaluate( $receipt['plan'], $receipt['plan'] ?? array(), $args, $report );
		$receipt['quality_budget_admission']['mechanical_status'] = $receipt['status'] ?? 'completed';
		$report['quality_budget_admission'] = $receipt['quality_budget_admission'];
		$report['materialization_receipt'] = $receipt;
		return array( 'fixture_diagnostics' => Static_Site_Importer_Report_Diagnostics::refresh_projections( $report, $quality ), 'validation' => $report['import_validation_result'], 'findings' => $report['finding_packets'] );
	}

	private static function document_metadata( array $plan ): array {
		foreach ( $plan['pages'] as $page ) {
			if ( empty( $page['entrypoint'] ) || ! is_array( $page['document_metadata'] ?? null ) ) { continue; }
			$metadata = array_merge( array( 'schema' => 'static-site-importer/document-metadata/v1' ), $page['document_metadata'] );
			foreach ( array( 'links' => 'href', 'scripts' => 'src' ) as $kind => $field ) { foreach ( $metadata[ $kind ] ?? array() as &$declaration ) { if ( is_array( $declaration ) && isset( $declaration['resolved_url'] ) ) { $declaration[ $field ] = $declaration['resolved_url']; } } unset( $declaration ); }
			return $metadata;
		}
		return array( 'schema' => 'static-site-importer/document-metadata/v1' );
	}
}
