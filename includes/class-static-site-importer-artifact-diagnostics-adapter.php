<?php
/**
 * Artifact diagnostics integration boundary.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds artifact diagnostics without owning the WP Codebox diagnostics schema.
 */
class Static_Site_Importer_Artifact_Diagnostics_Adapter {

	/**
	 * Build diagnostics for a Static Site Importer import report.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return array<string,mixed>
	 */
	public static function build_for_import_report( array $report ): array {
		$input   = array( 'diagnostics' => $report['diagnostics'] ?? array() );
		$options = array(
			'source'          => 'static-site-importer',
			'stage'           => 'import',
			'observationType' => 'static-site-importer/import-report',
			'refs'            => array(
				array(
					'path' => 'import-report.json',
					'kind' => 'static-site-importer/import-report',
				),
			),
		);

		$codebox = self::build_with_codebox_normalizer( $input, $options );
		if ( null !== $codebox ) {
			return $codebox;
		}

		return self::build_static_site_importer_diagnostics( $report );
	}

	/**
	 * Call the public WP Codebox normalizer when the runtime exposes one.
	 *
	 * @param mixed                $input   Normalizer input.
	 * @param array<string,mixed> $options Normalizer options.
	 * @return array<string,mixed>|null
	 */
	private static function build_with_codebox_normalizer( mixed $input, array $options ): ?array {
		if ( function_exists( 'wp_codebox_build_artifact_diagnostics' ) ) {
			$diagnostics = wp_codebox_build_artifact_diagnostics( $input, $options );

			return is_array( $diagnostics ) ? $diagnostics : null;
		}

		$normalizer = array( 'WP_Codebox_Artifact_Diagnostics_Normalizer', 'build' );
		if ( class_exists( 'WP_Codebox_Artifact_Diagnostics_Normalizer' ) && is_callable( $normalizer ) ) {
			/** @var callable $normalizer */
			$diagnostics = call_user_func( $normalizer, $input, $options );

			return is_array( $diagnostics ) ? $diagnostics : null;
		}

		return null;
	}

	/**
	 * Build an SSI-owned fallback envelope when Codebox is not present.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return array<string,mixed>
	 */
	private static function build_static_site_importer_diagnostics( array $report ): array {
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? array_values( $report['diagnostics'] ) : array();
		$summary     = array(
			'total'   => count( $diagnostics ),
			'error'   => self::count_by_severity( $diagnostics, 'error' ),
			'warning' => self::count_by_severity( $diagnostics, 'warning' ),
			'notice'  => self::count_by_severity( $diagnostics, 'notice' ),
			'info'    => self::count_by_severity( $diagnostics, 'info' ),
		);

		return array(
			'schema'      => 'static-site-importer/artifact-diagnostics/v1',
			'status'      => empty( $diagnostics ) ? 'clean' : 'reported',
			'source'      => 'static-site-importer',
			'summary'     => $summary,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Count diagnostics by severity.
	 *
	 * @param array<int,mixed> $diagnostics Diagnostics.
	 * @param string           $severity    Severity.
	 * @return int
	 */
	private static function count_by_severity( array $diagnostics, string $severity ): int {
		return count(
			array_filter(
				$diagnostics,
				static fn ( mixed $diagnostic ): bool => is_array( $diagnostic ) && ( $diagnostic['severity'] ?? '' ) === $severity
			)
		);
	}
}
