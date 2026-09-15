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
	 * `max_fallback_count` is a limit on fallbacks that remain unresolved. A
	 * compiler fallback a provider has already superseded is not a fallback the
	 * caller receives, so counting it would reject sites that land perfectly;
	 * counting anything a provider has not superseded keeps the limit honest.
	 *
	 * `entity_bindings` are the runtime entity bindings already applied to the
	 * resolved plan. Each one that supersedes a source fallback carries that
	 * fallback's identity and hash, which is what makes a provider-materializable
	 * island distinguishable from an unresolvable one before anything is written.
	 *
	 * @param array<string,mixed> $plan
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $args
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report
	 * @param array<string|int,mixed> $entity_bindings
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $plan, array $resolved, array $args = array(), array|Static_Site_Importer_Import_Report $report = array(), array $entity_bindings = array() ): array {
		$budget                  = isset( $args['quality_budget'] ) && is_array( $args['quality_budget'] ) ? $args['quality_budget'] : ( isset( $args['quality_budgets'] ) && is_array( $args['quality_budgets'] ) ? $args['quality_budgets'] : array() );
		$mode                    = in_array( $budget['mode'] ?? 'preview', array( 'production', 'production_ready' ), true ) ? 'production' : 'preview';
		$quality                 = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$metrics                 = isset( $quality['metrics'] ) && is_array( $quality['metrics'] ) ? $quality['metrics'] : $quality;
		$diagnostics             = isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array();
		$writes                  = isset( $resolved['writes'] ) && is_array( $resolved['writes'] ) ? $resolved['writes'] : ( isset( $plan['writes'] ) && is_array( $plan['writes'] ) ? $plan['writes'] : array() );
		$native_blocks           = self::metric( $metrics, array( 'native_block_count', 'block_count' ) );
		$core_html               = self::metric( $metrics, array( 'core_html_block_count' ) );
		$families                = self::core_html_families( $diagnostics );
		$unresolved_media        = self::metric( $metrics, array( 'unresolved_media_count', 'unresolved_asset_count' ) );
		$unresolved_dependencies = self::metric( $metrics, array( 'unresolved_dependency_count', 'dependency_failure_count', 'runtime_dependency_parity_issue_count' ) );
		$materialized_quality    = isset( $report['quality'] ) && is_array( $report['quality'] ) ? $report['quality'] : array();
		$materialized_metrics    = array_merge( isset( $materialized_quality['metrics'] ) && is_array( $materialized_quality['metrics'] ) ? $materialized_quality['metrics'] : array(), $materialized_quality );
		$native_blocks           = self::metric( $materialized_metrics, array( 'native_block_count', 'block_count' ) ) ?? $native_blocks;
		$core_html               = self::metric( $materialized_metrics, array( 'core_html_block_count' ) ) ?? $core_html;
		$fallbacks               = self::metric( $materialized_metrics, array( 'fallback_count', 'unresolved_fallback_count' ) );
		if ( null === $fallbacks ) {
			$fallbacks = self::metric( $metrics, array( 'fallback_count', 'unresolved_fallback_count' ) );
		}
		$bootstrap_bytes = self::bootstrap_bytes( $writes );
		$stylesheets     = self::stylesheet_count( $writes );
		$resolution      = self::provider_resolved_fallbacks( $entity_bindings );
		$unresolved      = null === $fallbacks ? null : max( 0, $fallbacks - min( $fallbacks, count( $resolution['identities'] ) ) );
		$evidence        = array(
			'native_block_count'               => $native_blocks,
			'core_html_block_count'            => $core_html,
			'core_html_families'               => $families,
			'unresolved_media_count'           => $unresolved_media,
			'unresolved_dependency_count'      => $unresolved_dependencies,
			'fallback_count'                   => $fallbacks,
			'provider_resolved_fallback_count' => count( $resolution['identities'] ),
			'unresolved_fallback_count'        => $unresolved,
			'fallback_providers'               => $resolution['providers'],
			'bootstrap_bytes'                  => $bootstrap_bytes,
			'stylesheet_asset_count'           => $stylesheets,
			'visual_gate'                      => self::gate_status( $args, $report, 'visual' ),
			'editor_gate'                      => self::gate_status( $args, $report, 'editor' ),
		);
		$limits          = array(
			'max_native_block_count'          => $native_blocks,
			'max_core_html_block_count'       => $core_html,
			'max_core_html_family_count'      => count( $families ),
			'max_unresolved_media_count'      => $unresolved_media,
			'max_unresolved_dependency_count' => $unresolved_dependencies,
			'max_fallback_count'              => $unresolved,
			'max_bootstrap_bytes'             => $bootstrap_bytes,
			'max_stylesheet_asset_count'      => $stylesheets,
		);
		$failures        = array();
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

	/**
	 * Read the runtime entity bindings already applied to a materialization state.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string|int,mixed>
	 */
	public static function applied_entity_bindings( array $state ): array {
		$bindings = $state['applied']['runtime_declarations']['entity_bindings'] ?? null;
		return is_array( $bindings ) ? $bindings : array();
	}

	/** @param array<string,mixed> $admission */
	public static function rejects_materialization( array $admission ): bool {
		return 'production' === ( $admission['mode'] ?? '' ) && 'failed' === ( $admission['production_status'] ?? '' );
	}

	/**
	 * Count source fallbacks a provider has already superseded.
	 *
	 * A binding only counts when it names the fallback it replaced by identity
	 * and hash and names the provider that replaced it. Absent a provider there
	 * are no bindings, so a missing provider keeps the original strictness
	 * instead of quietly discounting an island nothing can materialize.
	 *
	 * @param array<string|int,mixed> $bindings
	 * @return array{identities:array<string,true>,providers:array<int,string>}
	 */
	private static function provider_resolved_fallbacks( array $bindings ): array {
		$identities = array();
		$providers  = array();
		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) ) {
				continue;
			}
			$identity = isset( $binding['fallback_reconciliation_identity'] ) && is_string( $binding['fallback_reconciliation_identity'] ) ? $binding['fallback_reconciliation_identity'] : '';
			$hash     = isset( $binding['fallback_hash'] ) && is_string( $binding['fallback_hash'] ) ? $binding['fallback_hash'] : '';
			$provider = isset( $binding['provider'] ) && is_string( $binding['provider'] ) ? trim( $binding['provider'] ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $identity ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || '' === $provider ) {
				continue;
			}
			$identities[ $identity ] = true;
			$providers[ $provider ]  = true;
		}
		ksort( $providers, SORT_STRING );
		return array(
			'identities' => $identities,
			'providers'  => array_keys( $providers ),
		);
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
			$family              = (string) ( $diagnostic['tag_name'] ?? $diagnostic['block_name'] ?? 'unknown' );
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

	/** @param array<string,mixed> $args @param array<string,mixed>|Static_Site_Importer_Import_Report $report */
	private static function gate_status( array $args, array|Static_Site_Importer_Import_Report $report, string $gate ): string {
		$artifacts = isset( $args['validation_artifacts'] ) && is_array( $args['validation_artifacts'] ) ? $args['validation_artifacts'] : array();
		if ( isset( $artifacts[ $gate . '_gate' ]['status'] ) ) {
			return (string) $artifacts[ $gate . '_gate' ]['status'];
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
		return array(
			'metric'       => $metric,
			'status'       => $status,
			'actual'       => $actual,
			'limit'        => $limit,
			'repair_class' => in_array( $metric, array( 'bootstrap_bytes', 'stylesheet_asset_count' ), true ) ? 'static-site-importer' : ( str_ends_with( $metric, '_gate' ) ? 'runtime-validation' : 'blocks-engine' ),
		);
	}
}
