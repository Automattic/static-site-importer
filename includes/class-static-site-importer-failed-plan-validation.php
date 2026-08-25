<?php
/**
 * Builds durable, bounded evidence for plans rejected before materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Failed_Plan_Validation {

	private const MAX_DIAGNOSTICS = 50;

	/** @return array<string,mixed> */
	public static function build( array $plan, array $args = array(), array $compiled = array() ): array {
		$diagnostics = isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array();
		$report = array(
			'schema'      => 'static-site-importer/import-report/v1',
			'version'     => 1,
			'status'      => 'failed',
			'theme_slug'  => (string) ( $args['slug'] ?? '' ),
			'quality'     => isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array(),
			'diagnostics' => array_slice( $diagnostics, 0, self::MAX_DIAGNOSTICS ),
			'blocks_engine' => self::compiler_evidence( $plan, $compiled ),
			'source_documents' => self::source_documents( $plan ),
			'failure_context' => array(
				'stage' => 'pre_materialization_quality_admission',
				'code'  => 'static_site_importer_quality_gate_failed',
			),
		);
		if ( count( $diagnostics ) > self::MAX_DIAGNOSTICS ) {
			$report['diagnostics_truncated'] = true;
			$report['diagnostic_count']      = count( $diagnostics );
		}

		$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array_merge( $args, array( 'fail_on_quality' => true ) ) );
		$quality['pass']            = false;
		$quality['fail_import']     = true;
		$quality['failure_reasons'] = self::failure_reasons( $plan, $quality );
		$fixture_diagnostics = Static_Site_Importer_Report_Diagnostics::refresh_projections( $report, $quality );

		return array(
			'import_report'            => $report,
			'import_report_summary'    => $report['compact_summary'],
			'import_validation_result' => $report['import_validation_result'],
			'finding_packets'          => $report['finding_packets'],
			'fixture_diagnostics'      => $fixture_diagnostics,
		);
	}

	/** @return array<string,mixed> */
	private static function compiler_evidence( array $plan, array $compiled ): array {
		$source     = isset( $plan['source'] ) && is_array( $plan['source'] ) ? $plan['source'] : array();
		$reporting  = isset( $plan['reporting'] ) && is_array( $plan['reporting'] ) ? $plan['reporting'] : array();
		$metrics    = isset( $reporting['metrics'] ) && is_array( $reporting['metrics'] ) ? $reporting['metrics'] : array();
		$provenance = isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : ( isset( $source['provenance'] ) && is_array( $source['provenance'] ) ? $source['provenance'] : array() );
		$summary    = array_filter(
			array(
				'schema'           => (string) ( $compiled['result_schema'] ?? $compiled['schema'] ?? '' ),
				'status'           => (string) ( $compiled['status'] ?? '' ),
				'source'           => (string) ( $source['schema'] ?? '' ),
				'page_count'       => (int) ( $metrics['source_document_count'] ?? count( $plan['pages'] ?? array() ) ),
				'block_count'      => (int) ( $plan['quality']['metrics']['block_count'] ?? 0 ),
				'diagnostic_count' => count( $plan['diagnostics'] ?? array() ),
			),
			static fn( $value ): bool => '' !== $value
		);

		return array(
			'available'           => ! empty( $compiled ),
			'website_artifact'    => array(
				'summary'    => $summary,
				'provenance' => array_slice( $provenance, 0, self::MAX_DIAGNOSTICS ),
			),
			'wordpress_site_plan' => array(
				'schema'  => (string) ( $plan['schema'] ?? 'blocks-engine/wordpress-site-plan/v2' ),
				'source'  => $source,
				'quality' => isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array(),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function source_documents( array $plan ): array {
		$pages = isset( $plan['pages'] ) && is_array( $plan['pages'] ) ? $plan['pages'] : array();
		return array(
			'source'                       => 'blocks_engine',
			'total_count'                  => count( $pages ),
			'blocks_engine_document_count' => count( $pages ),
			'counts_by_format'             => array(
				'html'     => count( $pages ),
				'markdown' => 0,
				'mdx'      => 0,
			),
		);
	}

	/** @return array<string,string> */
	public static function persist( array $artifacts, string $report_path ): array {
		if ( '' === trim( $report_path ) ) {
			return array();
		}
		$directory = dirname( $report_path );
		$paths = array(
			'import_report'            => $report_path,
			'import_validation_result' => trailingslashit( $directory ) . 'import-validation-result.json',
			'finding_packets'          => trailingslashit( $directory ) . 'finding-packets.json',
		);
		foreach ( $paths as $path ) {
			if ( ! self::safe_destination( $path ) ) {
				throw new RuntimeException( 'Failed-plan report destination is not ready.' );
			}
		}
		self::write( $paths['import_report'], isset( $artifacts['import_report'] ) && is_array( $artifacts['import_report'] ) ? $artifacts['import_report'] : array() );
		self::write( $paths['import_validation_result'], isset( $artifacts['import_validation_result'] ) && is_array( $artifacts['import_validation_result'] ) ? $artifacts['import_validation_result'] : array() );
		self::write( $paths['finding_packets'], isset( $artifacts['finding_packets'] ) && is_array( $artifacts['finding_packets'] ) ? $artifacts['finding_packets'] : array() );
		return $paths;
	}

	/** @return array<int,string> */
	private static function failure_reasons( array $plan, array $quality ): array {
		$reasons = isset( $plan['quality']['failure_reasons'] ) && is_array( $plan['quality']['failure_reasons'] ) ? $plan['quality']['failure_reasons'] : ( $quality['failure_reasons'] ?? array() );
		$reasons = array_values( array_filter( $reasons, 'is_string' ) );
		return empty( $reasons ) ? array( 'canonical_plan_quality_gate_failed' ) : array_slice( $reasons, 0, self::MAX_DIAGNOSTICS );
	}

	private static function safe_destination( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );
		if ( '' === $path || str_contains( $normalized, '/../' ) || str_starts_with( $normalized, '../' ) || str_ends_with( $normalized, '/..' ) ) {
			return false;
		}
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || is_link( $parent ) || is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Explicit CLI report artifacts are atomically replaced to preserve retryability.
			return false;
		}
		return true;
	}

	private static function write( string $path, array $payload ): void {
		$temp = tempnam( dirname( $path ), '.ssi-failed-plan-' );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $temp || false === $json || false === file_put_contents( $temp, $json . "\n" ) || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.rename_rename -- Atomically writes bounded explicit report artifacts.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed atomic report temporary file.
			}
			throw new RuntimeException( 'Failed to write failed-plan report artifact.' );
		}
	}
}
