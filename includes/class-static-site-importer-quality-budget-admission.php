<?php
/**
 * Evaluates caller-owned quality budgets without changing compiler output.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_Quality_Budget_Admission {

	public const SCHEMA = 'static-site-importer/quality-budget-admission/v1';

	/**
	 * Evaluate plan evidence and resolved write facts.
	 *
	 * `quality_budget` is an optional caller contract. Its mode is `preview`
	 * (the compatibility default) or `production`. Limits are optional, so a
	 * caller can tighten only the dimensions it has a policy for.
	 *
	 * @param array<string,mixed> $plan
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $plan, array $resolved, array $args = array(), array $report = array() ): array {
		$budget = isset( $args['quality_budget'] ) && is_array( $args['quality_budget'] ) ? $args['quality_budget'] : ( isset( $args['quality_budgets'] ) && is_array( $args['quality_budgets'] ) ? $args['quality_budgets'] : array() );
		$mode   = in_array( $budget['mode'] ?? 'preview', array( 'production', 'production_ready' ), true ) ? 'production' : 'preview';
		$quality = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$metrics = isset( $quality['metrics'] ) && is_array( $quality['metrics'] ) ? $quality['metrics'] : $quality;
		$diagnostics = isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array();
		$writes = isset( $resolved['writes'] ) && is_array( $resolved['writes'] ) ? $resolved['writes'] : ( isset( $plan['writes'] ) && is_array( $plan['writes'] ) ? $plan['writes'] : array() );
		$native_blocks = self::metric( $metrics, array( 'native_block_count', 'block_count' ) );
		$core_html = self::metric( $metrics, array( 'core_html_block_count' ) );
		$families = self::core_html_families( $diagnostics );
		$unresolved_media = self::metric( $metrics, array( 'unresolved_media_count', 'unresolved_asset_count' ) );
		$unresolved_dependencies = self::metric( $metrics, array( 'unresolved_dependency_count', 'dependency_failure_count', 'runtime_dependency_parity_issue_count' ) );
		$bootstrap_bytes = self::bootstrap_bytes( $writes );
		$stylesheets = self::stylesheet_count( $writes );
		$evidence = array(
			'native_block_count'            => $native_blocks,
			'core_html_block_count'         => $core_html,
			'core_html_families'            => $families,
			'unresolved_media_count'        => $unresolved_media,
			'unresolved_dependency_count'   => $unresolved_dependencies,
			'bootstrap_bytes'               => $bootstrap_bytes,
			'stylesheet_asset_count'        => $stylesheets,
			'visual_gate'                   => self::gate_status( $args, $report, 'visual' ),
			'editor_gate'                   => self::gate_status( $args, $report, 'editor' ),
		);
		$limits = array(
			'max_native_block_count'          => $native_blocks,
			'max_core_html_block_count'       => $core_html,
			'max_core_html_family_count'      => count( $families ),
			'max_unresolved_media_count'      => $unresolved_media,
			'max_unresolved_dependency_count' => $unresolved_dependencies,
			'max_bootstrap_bytes'             => $bootstrap_bytes,
			'max_stylesheet_asset_count'      => $stylesheets,
		);
		$failures = array();
		foreach ( $limits as $limit => $actual ) {
			if ( ! array_key_exists( $limit, $budget ) ) {
				continue;
			}
			if ( null === $actual ) {
				$failures[] = self::failure( $limit, 'not_proven', $actual, $budget[ $limit ] );
			} elseif ( is_numeric( $budget[ $limit ] ) && $actual > (int) $budget[ $limit ] ) {
				$failures[] = self::failure( $limit, 'exceeded', $actual, (int) $budget[ $limit ] );
			}
		}
		foreach ( array( 'visual', 'editor' ) as $gate ) {
			if ( empty( $budget[ 'require_' . $gate . '_gate' ] ) ) {
				continue;
			}
			if ( 'passed' !== $evidence[ $gate . '_gate' ] ) {
				$failures[] = self::failure( $gate . '_gate', $evidence[ $gate . '_gate' ], $evidence[ $gate . '_gate' ], 'passed' );
			}
		}
		$production_status = empty( $budget ) ? 'not_proven' : ( empty( $failures ) ? 'passed' : 'failed' );
		return array(
			'schema'            => self::SCHEMA,
			'mode'              => $mode,
			'mechanical_status' => 'not_materialized',
			'production_status' => $production_status,
			'status'            => 'production' === $mode ? $production_status : 'preview',
			'evidence'          => $evidence,
			'budget'            => $budget,
			'failures'          => $failures,
		);
	}

	/** @param array<string,mixed> $admission */
	public static function rejects_materialization( array $admission ): bool {
		return 'production' === ( $admission['mode'] ?? '' ) && 'failed' === ( $admission['production_status'] ?? '' );
	}

	/** @param array<string,mixed> $metrics @param array<int,string> $keys */
	private static function metric( array $metrics, array $keys ): ?int {
		foreach ( $keys as $key ) {
			if ( isset( $metrics[ $key ] ) && is_numeric( $metrics[ $key ] ) ) {
				return max( 0, (int) $metrics[ $key ] );
			}
		}
		return null;
	}

	/** @param array<int,mixed> $diagnostics @return array<string,int> */
	private static function core_html_families( array $diagnostics ): array {
		$families = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! str_contains( (string) ( $diagnostic['type'] ?? $diagnostic['reason_code'] ?? '' ), 'core_html' ) ) {
				continue;
			}
			$family = (string) ( $diagnostic['tag_name'] ?? $diagnostic['block_name'] ?? 'unknown' );
			$families[ $family ] = ( $families[ $family ] ?? 0 ) + 1;
		}
		ksort( $families, SORT_STRING );
		return $families;
	}

	/** @param array<int,mixed> $writes */
	private static function bootstrap_bytes( array $writes ): int {
		$bytes = 0;
		foreach ( $writes as $write ) {
			if ( is_array( $write ) && 'theme_bootstrap' === ( $write['kind'] ?? '' ) ) {
				$bytes += strlen( (string) ( $write['payload']['data'] ?? '' ) );
			}
		}
		return $bytes;
	}

	/** @param array<int,mixed> $writes */
	private static function stylesheet_count( array $writes ): int {
		$count = 0;
		foreach ( $writes as $write ) {
			$path = is_array( $write ) ? (string) ( $write['target_path'] ?? '' ) : '';
			if ( str_ends_with( strtolower( $path ), '.css' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** @param array<string,mixed> $args @param array<string,mixed> $report */
	private static function gate_status( array $args, array $report, string $gate ): string {
		$artifacts = isset( $args['validation_artifacts'] ) && is_array( $args['validation_artifacts'] ) ? $args['validation_artifacts'] : array();
		if ( isset( $artifacts[ $gate . '_gate']['status'] ) ) {
			return (string) $artifacts[ $gate . '_gate']['status'];
		}
		if ( 'visual' === $gate && 'passed' === ( $report['visual_fidelity']['status'] ?? null ) ) {
			return 'passed';
		}
		if ( 'editor' === $gate && 'passed' === ( $report['import_validation_result']['quality_gates']['editor']['status'] ?? null ) ) {
			return 'passed';
		}
		return 'not_proven';
	}

	private static function failure( string $metric, string $status, $actual, $limit ): array {
		return array( 'metric' => $metric, 'status' => $status, 'actual' => $actual, 'limit' => $limit, 'repair_class' => in_array( $metric, array( 'bootstrap_bytes', 'stylesheet_asset_count' ), true ) ? 'static-site-importer' : ( str_ends_with( $metric, '_gate' ) ? 'runtime-validation' : 'blocks-engine' ) );
	}
}
