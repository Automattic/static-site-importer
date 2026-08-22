<?php
/**
 * Evaluates materialized-site quality without changing compiler output.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Quality_Admission {
	public const SCHEMA = 'static-site-importer/quality-admission/v1';

	/**
	 * Return a generic admission decision from canonical plan, receipt, and report evidence.
	 *
	 * `quality_admission.mode` is deliberately additive: existing callers retain their
	 * mechanical materialization result while production-ready callers opt into its
	 * explicit decision. No missing evidence is interpreted as visual or editor parity.
	 *
	 * @param array<string,mixed> $plan    Resolved canonical plan.
	 * @param array<string,mixed> $args    Materialization arguments.
	 * @param array<string,mixed> $report  Optional finalized import report.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $plan, array $args = array(), array $report = array() ): array {
		$config  = isset( $args['quality_admission'] ) && is_array( $args['quality_admission'] ) ? $args['quality_admission'] : array();
		$mode    = isset( $config['mode'] ) && is_string( $config['mode'] ) ? $config['mode'] : 'evidence';
		$mode    = in_array( $mode, array( 'evidence', 'preview', 'production_ready' ), true ) ? $mode : 'evidence';
		$budgets = self::budgets( $config['budgets'] ?? array() );
		$metrics = self::metrics( $plan, $report );
		$visual  = self::evidence_status( $report, 'visual' );
		$editor  = self::evidence_status( $report, 'editor' );
		$failures = array();
		foreach ( $budgets as $budget => $maximum ) {
			$metric = substr( $budget, 4 );
			if ( $metrics[ $metric ] > $maximum ) {
				$failures[] = array( 'budget' => $budget, 'metric' => $metric, 'actual' => $metrics[ $metric ], 'maximum' => $maximum );
			}
		}
		$canonical_evidence = isset( $plan['quality'] ) && is_array( $plan['quality'] ) && ( isset( $plan['quality']['metrics'] ) || isset( $plan['quality']['fallback_count'] ) || isset( $plan['quality']['block_count'] ) );
		$production_status  = ! empty( $failures ) ? 'hard_budget_failed' : ( 'failed' === $visual || 'failed' === $editor ? 'evidence_failed' : ( $canonical_evidence ? 'passed' : 'unknown' ) );

		return array(
			'schema'           => self::SCHEMA,
			'mode'             => $mode,
			'status'           => 'preview' === $mode ? 'preview' : $production_status,
			'production_ready' => $production_status,
			'mechanical_status' => 'completed',
			'budgets'          => $budgets,
			'metrics'          => $metrics,
			'evidence'         => array(
				'canonical_plan' => $canonical_evidence ? 'provided' : 'unknown',
				'visual'         => $visual,
				'editor'         => $editor,
			),
			'failures'         => $failures,
			'repair_owner'     => ! empty( $failures ) || 'failed' === $visual || 'failed' === $editor ? 'blocks-engine' : 'none',
		);
	}

	/** @return array<string,int> */
	private static function budgets( $raw ): array {
		$allowed = array( 'max_raw_html_fallback_count', 'max_unresolved_media_count', 'max_unresolved_dependency_count', 'max_theme_bootstrap_bytes', 'max_stylesheet_asset_count' );
		$budgets = array();
		if ( ! is_array( $raw ) ) {
			return $budgets;
		}
		foreach ( $allowed as $name ) {
			if ( isset( $raw[ $name ] ) && is_numeric( $raw[ $name ] ) && 0 <= $raw[ $name ] ) {
				$budgets[ $name ] = (int) $raw[ $name ];
			}
		}
		return $budgets;
	}

	/** @return array<string,mixed> */
	private static function metrics( array $plan, array $report ): array {
		$blocks = array( 'native_block_count' => 0, 'raw_html_fallback_count' => 0, 'raw_html_fallback_families' => array() );
		foreach ( array_merge( self::documents( $plan['pages'] ?? array() ), self::documents( $plan['template_parts'] ?? array() ) ) as $markup ) {
			if ( preg_match_all( '/<!--\s+wp:([^\s>{]+).*?(?:\/-->|-->)/i', $markup, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$name = strtolower( (string) $name );
					if ( in_array( $name, array( 'html', 'core/html', 'freeform', 'core/freeform' ), true ) ) {
						++$blocks['raw_html_fallback_count'];
						$family = str_contains( $name, 'freeform' ) ? 'freeform' : 'core_html';
						$blocks['raw_html_fallback_families'][ $family ] = (int) ( $blocks['raw_html_fallback_families'][ $family ] ?? 0 ) + 1;
					} else {
						++$blocks['native_block_count'];
					}
				}
			}
		}
		$diagnostics = array_merge( is_array( $plan['diagnostics'] ?? null ) ? $plan['diagnostics'] : array(), is_array( $report['diagnostics'] ?? null ) ? $report['diagnostics'] : array() );
		$media = 0;
		$dependencies = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$fields = array_filter( array_intersect_key( $diagnostic, array_flip( array( 'type', 'code', 'reason_code', 'reason' ) ) ), 'is_scalar' );
			$signal = strtolower( implode( ' ', array_map( 'strval', $fields ) ) );
			if ( ! str_contains( $signal, 'unresolved' ) && ! str_contains( $signal, 'missing' ) && ! str_contains( $signal, 'not_materialized' ) ) {
				continue;
			}
			if ( preg_match( '/asset|image|media|svg|font/', $signal ) ) {
				++$media;
			} elseif ( preg_match( '/depend|plugin|runtime|script|provider/', $signal ) ) {
				++$dependencies;
			}
		}
		$asset_map = is_array( $report['asset_map'] ?? null ) ? $report['asset_map'] : array();
		$media = max( $media, (int) ( $asset_map['unresolved_count'] ?? 0 ) );
		return array_merge( $blocks, array(
			'unresolved_media_count'      => $media,
			'unresolved_dependency_count' => $dependencies,
			'theme_bootstrap_bytes'       => self::bootstrap_bytes( $plan['writes'] ?? array() ),
			'stylesheet_asset_count'      => self::stylesheet_assets( $plan['assets'] ?? array() ),
		) );
	}

	/** @return array<int,string> */
	private static function documents( $rows ): array {
		$documents = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && is_string( $row['resolved_block_markup'] ?? null ) ) {
				$documents[] = $row['resolved_block_markup'];
			}
		}
		return $documents;
	}

	private static function bootstrap_bytes( $writes ): int {
		$bytes = 0;
		foreach ( is_array( $writes ) ? $writes : array() as $write ) {
			if ( ! is_array( $write ) || 'theme_bootstrap' !== ( $write['kind'] ?? null ) ) {
				continue;
			}
			$payload = is_array( $write['payload'] ?? null ) ? $write['payload'] : array();
			if ( isset( $payload['data'] ) && is_string( $payload['data'] ) ) {
				$decoded = 'base64' === ( $payload['encoding'] ?? null ) ? base64_decode( $payload['data'], true ) : $payload['data'];
				$bytes += is_string( $decoded ) ? strlen( $decoded ) : 0;
			} elseif ( isset( $write['bytes'] ) && is_numeric( $write['bytes'] ) ) {
				$bytes += max( 0, (int) $write['bytes'] );
			}
		}
		return $bytes;
	}

	private static function stylesheet_assets( $assets ): int {
		$paths = array();
		foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$path = (string) ( $asset['target_path'] ?? $asset['source_path'] ?? '' );
			if ( str_ends_with( strtolower( strtok( $path, '?' ) ), '.css' ) ) {
				$paths[ $path ] = true;
			}
		}
		return count( $paths );
	}

	private static function evidence_status( array $report, string $kind ): string {
		$key = 'visual' === $kind ? 'visual_fidelity' : 'editor_fidelity';
		$status = strtolower( (string) ( $report[ $key ]['status'] ?? $report['quality_evidence'][ $kind ]['status'] ?? '' ) );
		return in_array( $status, array( 'passed', 'failed' ), true ) ? $status : 'unknown';
	}
}
