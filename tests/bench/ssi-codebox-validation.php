<?php
/**
 * Codebox validation workload for Static Site Importer.
 *
 * @package StaticSiteImporter
 */

require_once '/homeboy-extension/scripts/bench/lib/wordpress-bench-artifacts.php';

return static function (): array {
	$artifact_path = __DIR__ . '/../fixtures/website-artifact-bundle/artifact.json';
	$artifact_json = file_get_contents( $artifact_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned fixture artifact.
	$artifact      = json_decode( false === $artifact_json ? '' : $artifact_json, true );
	if ( ! is_array( $artifact ) ) {
		throw new RuntimeException( 'SSI Codebox validation fixture artifact is not readable JSON.' );
	}

	$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
		$artifact,
		array(
			'slug'        => 'ssi-codebox-validation-fixture',
			'name'        => 'SSI Codebox Validation Fixture',
			'activate'    => true,
			'overwrite'   => true,
			'source_metadata' => array(
				'source_type' => 'homeboy-codebox-validation-workload',
			),
		)
	);

	if ( is_wp_error( $result ) ) {
		$validation_result = array(
			'success'      => false,
			'schema'       => 'static-site-importer/codebox-validation-result/v1',
			'status'       => 'failed',
			'product_path' => 'static-site-importer/validate-in-codebox',
			'summary'      => array(
				'quality_pass' => false,
				'error_code'   => $result->get_error_code(),
				'error_message' => $result->get_error_message(),
			),
		);
	} else {
		$report_path            = (string) ( $result['report_path'] ?? '' );
		$validation_result_path = (string) ( $result['validation_result_path'] ?? '' );
		$finding_packets_path   = (string) ( $result['finding_packets_path'] ?? '' );
		$browser_evidence_mode  = getenv( 'STATIC_SITE_IMPORTER_CODEBOX_BROWSER_EVIDENCE' );
		$browser_evidence_refs  = 'runtime-post-step' === $browser_evidence_mode ? array(
			'browser_render_evidence' => array(
				'artifact_ref' => 'files/browser/summary.json',
				'kind'         => 'wp-codebox/browser-summary',
			),
			'screenshots'             => array(
				array(
					'artifact_ref' => 'files/browser/screenshot.png',
					'kind'         => 'wp-codebox/browser-screenshot',
				),
			),
			'browser_html'            => array(
				'artifact_ref' => 'files/browser/snapshot.html',
				'kind'         => 'wp-codebox/browser-html-snapshot',
			),
		) : array();
		$raw_artifacts          = array(
			array(
				'artifact_ref' => basename( $finding_packets_path ),
				'kind'         => 'blocks-engine/finding-packets',
			),
		);
		if ( isset( $browser_evidence_refs['browser_html'] ) ) {
			$raw_artifacts[] = $browser_evidence_refs['browser_html'];
		}
		$validation_result = array(
			'success'      => ! empty( $result['quality']['pass'] ),
			'schema'       => 'static-site-importer/codebox-validation-result/v1',
			'status'       => ! empty( $result['quality']['pass'] ) ? 'succeeded' : 'failed',
			'product_path' => 'static-site-importer/validate-in-codebox',
			'artifacts'    => array(
				'schema'                  => 'static-site-importer/codebox-validation-artifacts/v1',
				'generated_theme'         => array(
					'artifact_ref' => (string) ( $result['theme_slug'] ?? '' ),
					'kind'         => 'wordpress-theme-directory',
					'status'       => 'materialized',
				),
				'import_report'           => array(
					'artifact_ref' => basename( $report_path ),
					'kind'         => 'blocks-engine/import-report',
				),
				'block_validation_result' => array(
					'artifact_ref' => basename( $validation_result_path ),
					'kind'         => 'blocks-engine/import-validation-result',
				),
				'browser_render_evidence' => $browser_evidence_refs['browser_render_evidence'] ?? array(),
				'screenshots'             => $browser_evidence_refs['screenshots'] ?? array(),
				'raw'                     => $raw_artifacts,
			),
			'summary'      => array(
				'quality_pass'          => ! empty( $result['quality']['pass'] ),
				'import_report'         => is_readable( $report_path ) ? 'captured' : 'missing',
				'block_validation'      => is_readable( $validation_result_path ) ? 'captured' : 'missing',
				'browser_render'        => 'runtime-post-step' === $browser_evidence_mode ? 'captured' : 'pending',
				'screenshot_artifacts'  => 'runtime-post-step' === $browser_evidence_mode ? 1 : 0,
				'visual_diff_artifacts' => 0,
				'theme_slug'            => (string) ( $result['theme_slug'] ?? '' ),
			),
		);
	}

	$artifact_ref = homeboy_bench_write_json_artifact(
		'ssi-codebox-validation',
		'codebox-validation-result',
		$validation_result
	);

	return array(
		'metrics'   => array(
			'ssi_codebox_validation_success'      => ! empty( $validation_result['success'] ) ? 1 : 0,
			'ssi_codebox_validation_quality_pass' => ! empty( $validation_result['summary']['quality_pass'] ) ? 1 : 0,
		),
		'artifacts' => array(
			'codebox_validation_result' => $artifact_ref,
		),
		'metadata'  => array(
			'ssi_codebox_validation' => $validation_result,
		),
	);
};
