<?php
/**
 * Static Site Importer diagnostic contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}

/**
 * Normalizes importer-owned diagnostics for validation and repair loops.
 */
class Static_Site_Importer_Diagnostic_Contract {

	public const IMPORT_DIAGNOSTICS_SCHEMA = 'static-site-importer/import-diagnostics/v1';

	/**
	 * Build an importer-owned diagnostics envelope from a validation/import result.
	 *
	 * @param array<string,mixed> $result Validation provider or synthesized result.
	 * @return array<string,mixed>
	 */
	public static function build( array $result ): array {
		$request       = isset( $result['request'] ) && is_array( $result['request'] ) ? $result['request'] : array();
		$import_args   = isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array();
		$import_report = self::provider_import_report( $result );
		$summary       = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
		$artifacts     = isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array();

		$diagnostics = array_merge(
			self::diagnostic_rows_from_result( $result ),
			self::diagnostic_rows_from_import_report( $import_report ),
			self::blocks_engine_conversion_diagnostics( $import_report ),
			self::runtime_dependency_target_gaps( $import_report ),
			self::semantic_parity_diagnostics( $import_report )
		);
		$diagnostics = self::dedupe_diagnostics( $diagnostics );

		$quality_counts = self::quality_counts( $import_report, $summary );

		return array(
			'schema'                         => self::IMPORT_DIAGNOSTICS_SCHEMA,
			'fixture'                        => array(
				'slug' => isset( $result['slug'] ) && is_scalar( $result['slug'] ) ? (string) $result['slug'] : ( isset( $import_args['slug'] ) ? (string) $import_args['slug'] : '' ),
				'name' => isset( $result['name'] ) && is_scalar( $result['name'] ) ? (string) $result['name'] : ( isset( $import_args['name'] ) ? (string) $import_args['name'] : '' ),
			),
			'status'                         => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : '',
			'success'                        => ! empty( $result['success'] ),
			'quality_counts'                 => $quality_counts,
			'import_report_quality_counts'   => $quality_counts,
			'diagnostic_summary'             => self::diagnostic_summary( $diagnostics ),
			'diagnostics'                    => $diagnostics,
			'loss_class_summary'             => Static_Site_Importer_Diagnostic_Loss_Classes::counts( $diagnostics ),
			'by_repair_bucket'               => self::diagnostics_by_field( $diagnostics, 'repair_bucket' ),
			'by_loss_class'                  => self::diagnostics_by_field( $diagnostics, 'loss_class' ),
			'by_parser_owner'                => self::diagnostics_by_field( $diagnostics, 'parser_owner' ),
			'by_category'                    => self::diagnostics_by_field( $diagnostics, 'category' ),
			'top_parser_buckets'             => self::top_parser_buckets( $diagnostics ),
			'blocks_engine'                  => self::blocks_engine_summary( $import_report ),
			'runtime_dependency_target_gaps' => self::runtime_dependency_target_gaps( $import_report ),
			'asset_diagnostics'              => self::diagnostics_matching_types( $diagnostics, array( 'asset', 'image', 'local_asset_not_materialized', 'missing_asset', 'dropped_image' ) ),
			'svg_diagnostics'                => self::diagnostics_matching_types( $diagnostics, array( 'svg', 'unsafe_inline_svg', 'svg_materialization_failure', 'svg_sprite_reference_failure' ) ),
			'button_style_loss_hints'        => self::diagnostics_matching_types( $diagnostics, array( 'button', 'style_loss', 'presentation_gap' ) ),
			'artifact_refs'                  => self::artifact_refs( $artifacts, $import_report ),
		);
	}

	/**
	 * Extract an import report from common provider result slots.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @return array<string,mixed>
	 */
	private static function provider_import_report( array $result ): array {
		foreach ( array( $result['import_report'] ?? null, $result['summary']['import_report'] ?? null, $result['artifacts']['import_report'] ?? null ) as $candidate ) {
			if ( is_array( $candidate ) && ( isset( $candidate['quality'] ) || isset( $candidate['diagnostics'] ) || isset( $candidate['blocks_engine'] ) ) ) {
				return $candidate;
			}
		}

		return array();
	}

	/**
	 * Read provider-level diagnostic rows.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostic_rows_from_result( array $result ): array {
		$rows = array();
		foreach ( array( $result['diagnostics'] ?? array(), $result['artifact_diagnostics']['diagnostics'] ?? array(), $result['import_validation_result']['diagnostics'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $candidate ) );
			}
		}

		return $rows;
	}

	/**
	 * Read import-report diagnostic rows.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostic_rows_from_import_report( array $import_report ): array {
		$rows = self::normalize_diagnostic_rows( isset( $import_report['diagnostics'] ) && is_array( $import_report['diagnostics'] ) ? $import_report['diagnostics'] : array() );
		if ( isset( $import_report['artifact_diagnostics']['diagnostics'] ) && is_array( $import_report['artifact_diagnostics']['diagnostics'] ) ) {
			$rows = array_merge( $rows, self::normalize_diagnostic_rows( $import_report['artifact_diagnostics']['diagnostics'] ) );
		}

		return $rows;
	}

	/**
	 * Extract Blocks Engine conversion-report diagnostics and fallback rows.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function blocks_engine_conversion_diagnostics( array $import_report ): array {
		$conversion_report = isset( $import_report['blocks_engine']['conversion_report'] ) && is_array( $import_report['blocks_engine']['conversion_report'] ) ? $import_report['blocks_engine']['conversion_report'] : array();
		$rows              = array();
		foreach ( array( 'diagnostics', 'fallback_diagnostics', 'fallbacks', 'presentation_gaps', 'interaction_candidates' ) as $field ) {
			if ( isset( $conversion_report[ $field ] ) && is_array( $conversion_report[ $field ] ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $conversion_report[ $field ], 'blocks_engine_conversion_report' ) );
			}
		}

		return $rows;
	}

	/**
	 * Extract runtime dependency parity target gaps.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function runtime_dependency_target_gaps( array $import_report ): array {
		$runtime_dependency_parity = isset( $import_report['blocks_engine']['runtime_dependency_parity'] ) && is_array( $import_report['blocks_engine']['runtime_dependency_parity'] ) ? $import_report['blocks_engine']['runtime_dependency_parity'] : array();
		$rows                      = array();
		foreach ( array( 'findings', 'missing_dom_targets', 'unsupported_elements' ) as $field ) {
			if ( isset( $runtime_dependency_parity[ $field ] ) && is_array( $runtime_dependency_parity[ $field ] ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $runtime_dependency_parity[ $field ], 'runtime_dependency_parity' ) );
			}
		}

		return $rows;
	}

	/**
	 * Extract semantic parity findings.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function semantic_parity_diagnostics( array $import_report ): array {
		$semantic_parity = isset( $import_report['blocks_engine']['semantic_parity'] ) && is_array( $import_report['blocks_engine']['semantic_parity'] ) ? $import_report['blocks_engine']['semantic_parity'] : array();
		$findings        = isset( $semantic_parity['findings'] ) && is_array( $semantic_parity['findings'] ) ? $semantic_parity['findings'] : array();

		return self::normalize_diagnostic_rows( $findings, 'semantic_parity' );
	}

	/**
	 * Normalize diagnostic rows to a stable consumer-facing subset.
	 *
	 * @param array<int|string,mixed> $rows          Raw diagnostic rows.
	 * @param string                  $default_stage Default stage.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_diagnostic_rows( array $rows, string $default_stage = '' ): array {
		$normalized = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( self::is_report_only_diagnostic( $row ) || self::is_count_only_diagnostic( $row ) ) {
				continue;
			}

			$type                           = self::first_identifier( $row, array( 'type', 'kind', 'code', 'reason_code' ), 'diagnostic' );
			$reason_code                    = self::first_identifier( $row, array( 'reason_code', 'code', 'reason', 'kind', 'type' ), $type );
			$source_path                    = self::first_scalar( $row, array( 'source_path', 'path', 'source', 'file', 'script_path' ), '' );
			$repair_bucket                  = self::repair_bucket( $type, $reason_code, $row );
			$loss_class                     = Static_Site_Importer_Diagnostic_Loss_Classes::classify( array_merge( $row, array( 'repair_bucket' => $repair_bucket ) ) );
			$repair_bucket                  = self::repair_bucket_for_loss_class( $repair_bucket, $loss_class );
			$parser_owner                   = self::parser_owner( $type, $repair_bucket, $row );
			$diagnostic                     = array(
				'id'                      => self::first_scalar( $row, array( 'id' ), sprintf( 'diag-%03d-%s', $index + 1, sanitize_key( $type . '-' . $reason_code . '-' . $source_path ) ) ),
				'type'                    => sanitize_key( $type ),
				'kind'                    => sanitize_key( self::first_identifier( $row, array( 'kind', 'code', 'type' ), $type ) ),
				'severity'                => self::first_scalar( $row, array( 'severity', 'level' ), self::default_diagnostic_severity( $type ) ),
				'category'                => self::diagnostic_category( $type ),
				'group_key'               => $repair_bucket,
				'repair_bucket'           => $repair_bucket,
				'parser_owner'            => $parser_owner,
				'candidate_repo'          => $parser_owner,
				'repair_mode'             => self::repair_mode( $repair_bucket ),
				'reason_code'             => sanitize_key( $reason_code ),
				'source_path'             => $source_path,
				'path'                    => $source_path,
				'selector'                => self::first_scalar( $row, array( 'selector', 'target_selector', 'css_selector' ), '' ),
				'code'                    => self::first_scalar( $row, array( 'code', 'error_code' ), sanitize_key( $reason_code ) ),
				'stage'                   => self::first_scalar( $row, array( 'stage' ), $default_stage ),
				'owner'                   => self::first_scalar( $row, array( 'owner', 'engine', 'converter' ), $parser_owner ),
				'runtime_target_selector' => self::first_scalar( $row, array( 'runtime_target_selector', 'target_selector', 'target', 'selector' ), '' ),
				'missing_asset_path'      => self::missing_asset_path( $row ),
			);
			$diagnostic['loss_class']       = $loss_class;
			$diagnostic['diagnostic_class'] = $diagnostic['loss_class'];
			$diagnostic['repair_class']     = $diagnostic['repair_mode'];
			$diagnostic['acceptability']    = self::diagnostic_acceptability( $diagnostic['loss_class'] );
			$source_diagnostic              = self::source_diagnostic_identity( $row, $diagnostic );
			if ( ! empty( $source_diagnostic ) ) {
				$diagnostic['source_diagnostic'] = $source_diagnostic;
			}

			foreach ( array( 'message', 'reason', 'excerpt', 'source_snippet', 'source_html_preview', 'emitted_block_preview', 'observed_output', 'html_excerpt', 'block_name', 'block_path', 'script_path', 'element', 'tag_name', 'tag', 'src', 'href', 'expected', 'observed', 'suggested_primitive', 'diagnostic_code', 'mapped_provider' ) as $field ) {
				$value = self::first_scalar( $row, array( $field ), '' );
				if ( '' !== $value ) {
					$diagnostic[ $field ] = $value;
				}
			}

			// Preserve the runtime-mapping signal a real provider sets when it
			// materializes a preserved island (e.g. a <form> mapped to working
			// form-provider blocks). The honest fixture gate treats a preserved
			// runtime island as acceptable only when this signal is truthy.
			foreach ( array( 'runtime_mapped', 'runtime_carried' ) as $signal ) {
				if ( ! empty( $row[ $signal ] ) ) {
					$diagnostic[ $signal ] = true;
				}
			}

			$normalized[] = array_filter(
				$diagnostic,
				static fn ( mixed $value ): bool => '' !== $value
			);
		}

		return $normalized;
	}

	/**
	 * Return quality counts from import report first, then compact summaries.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @param array<string,mixed> $summary Provider summary.
	 * @return array<string,int>
	 */
	private static function quality_counts( array $import_report, array $summary ): array {
		$quality = isset( $import_report['quality'] ) && is_array( $import_report['quality'] ) ? $import_report['quality'] : $summary;
		$keys    = array( 'fallback_count', 'content_loss_count', 'empty_conversion_count', 'core_html_block_count', 'freeform_block_count', 'invalid_block_count', 'invalid_block_document_count', 'unsafe_svg_count', 'svg_materialization_failure_count', 'svg_sprite_reference_failure_count', 'commerce_dependency_failures', 'interaction_candidate_count', 'runtime_dependency_parity_issue_count', 'semantic_parity_failure_count' );

		$counts = array();
		foreach ( $keys as $key ) {
			$counts[ $key ] = isset( $quality[ $key ] ) && is_numeric( $quality[ $key ] ) ? (int) $quality[ $key ] : 0;
		}

		return $counts;
	}

	/**
	 * Summarize Blocks Engine import-report details.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<string,mixed>
	 */
	private static function blocks_engine_summary( array $import_report ): array {
		$blocks_engine = isset( $import_report['blocks_engine'] ) && is_array( $import_report['blocks_engine'] ) ? $import_report['blocks_engine'] : array();

		$summary = array();
		foreach ( array( 'website_artifact', 'conversion_report', 'runtime_dependency_parity', 'semantic_parity' ) as $field ) {
			if ( isset( $blocks_engine[ $field ] ) && is_array( $blocks_engine[ $field ] ) && ! empty( $blocks_engine[ $field ] ) ) {
				$summary[ $field ] = $blocks_engine[ $field ];
			}
		}

		return $summary;
	}

	/**
	 * Build diagnostic counts by severity, category, type, owner, and bucket.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_summary( array $diagnostics ): array {
		$summary = array(
			'total'         => count( $diagnostics ),
			'severity'      => array(),
			'category'      => array(),
			'loss_class'    => Static_Site_Importer_Diagnostic_Loss_Classes::counts( $diagnostics ),
			'type'          => array(),
			'parser_owner'  => array(),
			'repair_bucket' => array(),
		);
		foreach ( $diagnostics as $diagnostic ) {
			foreach ( array( 'severity', 'category', 'type', 'parser_owner', 'repair_bucket' ) as $field ) {
				$value                       = isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ? (string) $diagnostic[ $field ] : 'unknown';
				$summary[ $field ][ $value ] = ( $summary[ $field ][ $value ] ?? 0 ) + 1;
			}
		}

		return $summary;
	}

	/**
	 * Group diagnostics by a field.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @param string                         $field       Field name.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function diagnostics_by_field( array $diagnostics, string $field ): array {
		$grouped = array();
		foreach ( $diagnostics as $diagnostic ) {
			$key               = isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ? (string) $diagnostic[ $field ] : 'uncategorized';
			$grouped[ $key ][] = $diagnostic;
		}

		return $grouped;
	}

	/**
	 * Select diagnostics matching types or type fragments.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @param array<int,string>              $needles     Type needles.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostics_matching_types( array $diagnostics, array $needles ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static function ( array $diagnostic ) use ( $needles ): bool {
					$type     = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : '';
					$category = isset( $diagnostic['category'] ) && is_scalar( $diagnostic['category'] ) ? (string) $diagnostic['category'] : '';
					$bucket   = isset( $diagnostic['repair_bucket'] ) && is_scalar( $diagnostic['repair_bucket'] ) ? (string) $diagnostic['repair_bucket'] : '';
					foreach ( $needles as $needle ) {
						if ( $needle === $type || str_contains( $type, $needle ) || str_contains( $category, $needle ) || str_contains( $bucket, $needle ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/**
	 * Build the top parser-owner/repair-bucket counts.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function top_parser_buckets( array $diagnostics ): array {
		$buckets = array();
		foreach ( $diagnostics as $diagnostic ) {
			$parser_owner  = isset( $diagnostic['parser_owner'] ) && is_scalar( $diagnostic['parser_owner'] ) ? (string) $diagnostic['parser_owner'] : 'static-site-importer';
			$repair_bucket = isset( $diagnostic['repair_bucket'] ) && is_scalar( $diagnostic['repair_bucket'] ) ? (string) $diagnostic['repair_bucket'] : 'static_site_import_quality';
			$key           = $parser_owner . ':' . $repair_bucket;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'parser_owner'  => $parser_owner,
					'repair_bucket' => $repair_bucket,
					'count'         => 0,
				);
			}

			++$buckets[ $key ]['count'];
		}

		$values = array_values( $buckets );
		usort(
			$values,
			static function ( array $left, array $right ): int {
				$count_compare = $right['count'] <=> $left['count'];
				if ( 0 !== $count_compare ) {
					return $count_compare;
				}

				$owner_compare = strcmp( (string) $left['parser_owner'], (string) $right['parser_owner'] );
				if ( 0 !== $owner_compare ) {
					return $owner_compare;
				}

				return strcmp( (string) $left['repair_bucket'], (string) $right['repair_bucket'] );
			}
		);

		return $values;
	}

	/**
	 * Stable artifact references from validation/import output.
	 *
	 * @param array<string,mixed> $artifacts     Validation artifacts.
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<string,mixed>
	 */
	private static function artifact_refs( array $artifacts, array $import_report ): array {
		$refs = self::sanitize_artifact_refs( $artifacts );
		if ( isset( $import_report['import_validation_result']['artifacts'] ) && is_array( $import_report['import_validation_result']['artifacts'] ) ) {
			$refs['import_validation_artifacts'] = self::sanitize_artifact_refs( $import_report['import_validation_result']['artifacts'] );
		}
		if ( isset( $import_report['visual_parity_artifacts'] ) && is_array( $import_report['visual_parity_artifacts'] ) ) {
			$refs['visual_parity_artifacts'] = self::sanitize_artifact_refs( $import_report['visual_parity_artifacts'] );
		}

		return $refs;
	}

	/**
	 * Remove local-only filesystem paths from reviewer-facing artifact refs.
	 *
	 * @param array<string,mixed> $refs Artifact refs.
	 * @return array<string,mixed>
	 */
	private static function sanitize_artifact_refs( array $refs ): array {
		$sanitized = array();
		foreach ( $refs as $key => $value ) {
			if ( in_array( $key, array( 'path', 'full_path', 'local_path' ), true ) ) {
				continue;
			}

			$sanitized[ $key ] = is_array( $value ) ? self::sanitize_artifact_refs( $value ) : $value;
		}

		return $sanitized;
	}

	/**
	 * Remove duplicate diagnostics by stable source context and reason.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function dedupe_diagnostics( array $diagnostics ): array {
		$seen   = array();
		$unique = array();
		foreach ( $diagnostics as $diagnostic ) {
			$key = self::diagnostic_dedupe_key( $diagnostic );
			if ( isset( $seen[ $key ] ) ) {
				$unique[ $seen[ $key ] ] = self::merge_diagnostic_context( $unique[ $seen[ $key ] ], $diagnostic );
				continue;
			}

			$seen[ $key ] = count( $unique );
			$unique[]     = $diagnostic;
		}

		return $unique;
	}

	/**
	 * Build a dedupe key that can collapse SSI and Blocks Engine echoes of the same finding.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	private static function diagnostic_dedupe_key( array $diagnostic ): string {
		$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '';
		$selector    = isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '';
		$reason      = self::first_scalar( $diagnostic, array( 'reason_code', 'code', 'reason' ), '' );
		$loss_class  = isset( $diagnostic['loss_class'] ) && is_scalar( $diagnostic['loss_class'] ) ? (string) $diagnostic['loss_class'] : '';

		if ( '' !== $source_path && '' !== $selector && '' !== $reason ) {
			return implode( '|', array( 'context', $source_path, $selector, sanitize_key( $reason ), $loss_class ) );
		}

		return implode( '|', array_map( 'strval', array( 'identity', $diagnostic['id'] ?? '', $diagnostic['type'] ?? '', $source_path, $selector, $diagnostic['code'] ?? '' ) ) );
	}

	/**
	 * Merge duplicate diagnostics without discarding source evidence from either producer.
	 *
	 * @param array<string,mixed> $primary   First diagnostic row.
	 * @param array<string,mixed> $duplicate Duplicate diagnostic row.
	 * @return array<string,mixed>
	 */
	private static function merge_diagnostic_context( array $primary, array $duplicate ): array {
		foreach ( $duplicate as $field => $value ) {
			if ( ! array_key_exists( $field, $primary ) || '' === $primary[ $field ] || null === $primary[ $field ] || array() === $primary[ $field ] ) {
				$primary[ $field ] = $value;
			}
		}

		return $primary;
	}

	/**
	 * Resolve a scalar value from candidate fields.
	 *
	 * @param array<string,mixed> $row      Source row.
	 * @param array<int,string>   $fields   Candidate fields.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private static function first_scalar( array $row, array $fields, string $fallback = '' ): string {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}

		return $fallback;
	}

	/**
	 * Resolve a diagnostic identity value without treating counts/indexes as codes.
	 *
	 * @param array<string,mixed> $row      Source row.
	 * @param array<int,string>   $fields   Candidate fields.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private static function first_identifier( array $row, array $fields, string $fallback = '' ): string {
		foreach ( $fields as $field ) {
			if ( ! isset( $row[ $field ] ) || ! is_scalar( $row[ $field ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $field ] );
			if ( '' === $value || self::is_numeric_only_string( $value ) ) {
				continue;
			}

			return $value;
		}

		return $fallback;
	}

	/**
	 * Check whether a string is only a numeric count/index.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_numeric_only_string( string $value ): bool {
		return 1 === preg_match( '/^\d+$/', trim( $value ) );
	}

	/**
	 * Check whether a diagnostic row is report evidence rather than repair work.
	 *
	 * @param array<string,mixed> $row Source row.
	 * @return bool
	 */
	private static function is_report_only_diagnostic( array $row ): bool {
		$constraints = strtolower( self::first_scalar( $row, array( 'constraints', 'constraint' ), '' ) );

		return in_array( $constraints, array( 'report_only', 'report-only' ), true );
	}

	/**
	 * Check whether a diagnostic is only a count/index placeholder.
	 *
	 * @param array<string,mixed> $row Source row.
	 * @return bool
	 */
	private static function is_count_only_diagnostic( array $row ): bool {
		$type   = sanitize_key( self::first_scalar( $row, array( 'type', 'kind', 'code' ), '' ) );
		$reason = self::first_scalar( $row, array( 'reason_code', 'reason', 'error_code', 'message' ), '' );

		if ( ! self::is_placeholder_scalar( $type ) && ! in_array( $type, array( 'diagnostic', 'import_diagnostic', 'static_site_fixture_diagnostic', 'static_site_importer_diagnostic' ), true ) ) {
			return false;
		}

		if ( '' !== $reason && ! self::is_placeholder_scalar( $reason ) ) {
			return false;
		}

		foreach ( array( 'selector', 'source_snippet', 'source_html_preview', 'emitted_block_preview', 'observed_output', 'html_excerpt', 'excerpt', 'script_path', 'src', 'href', 'expected', 'observed' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && ! self::is_placeholder_scalar( (string) $row[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a scalar is a placeholder rather than source evidence.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_placeholder_scalar( string $value ): bool {
		$value = trim( strtolower( $value ) );

		return '' === $value || '(none)' === $value || 'none' === $value || self::is_numeric_only_string( $value );
	}

	/**
	 * Classify diagnostics by generic category.
	 *
	 * @param string $type Diagnostic type.
	 * @return string
	 */
	private static function diagnostic_category( string $type ): string {
		if ( str_contains( $type, 'svg' ) ) {
			return 'svg';
		}
		if ( str_contains( $type, 'asset' ) || str_contains( $type, 'image' ) ) {
			return 'asset';
		}
		if ( str_contains( $type, 'runtime_dependency' ) || str_contains( $type, 'dom_target' ) || str_contains( $type, 'runtime_target' ) ) {
			return 'runtime_dependency_parity';
		}
		if ( str_contains( $type, 'semantic_parity' ) || str_contains( $type, 'navigation_' ) || str_contains( $type, 'landmark_' ) ) {
			return 'semantic_parity';
		}
		if ( str_contains( $type, 'core_html' ) || str_contains( $type, 'freeform' ) || str_contains( $type, 'fallback' ) ) {
			return 'fallback_block';
		}
		if ( str_contains( $type, 'invalid_block' ) || str_contains( $type, 'block_validation' ) ) {
			return 'block_validity';
		}
		if ( str_contains( $type, 'button' ) || str_contains( $type, 'style' ) || str_contains( $type, 'presentation' ) ) {
			return 'style_loss_hint';
		}

		return 'import_quality';
	}

	/**
	 * Determine repair bucket.
	 *
	 * @param string              $type        Diagnostic type.
	 * @param string              $reason_code Reason code.
	 * @param array<string,mixed> $row         Source row.
	 * @return string
	 */
	private static function repair_bucket( string $type, string $reason_code, array $row ): string {
		$explicit = self::first_scalar( $row, array( 'repair_bucket', 'group_key' ), '' );
		if ( '' !== $explicit ) {
			return sanitize_key( $explicit );
		}
		if ( 'document_metadata_routed' === $type ) {
			return 'static_site_import_quality';
		}

		$haystack = strtolower( implode( ' ', array( $type, $reason_code, self::first_scalar( $row, array( 'message', 'reason', 'detail' ), '' ) ) ) );
		if ( str_contains( $haystack, 'runtime_dependency' ) || str_contains( $haystack, 'dom_target' ) || str_contains( $haystack, 'runtime_target' ) || str_contains( $haystack, 'canvas' ) || str_contains( $haystack, 'animation' ) ) {
			return 'runtime_target_gap';
		}
		if ( str_contains( $haystack, 'invalid_block' ) || str_contains( $haystack, 'block_validation' ) || str_contains( $haystack, 'invalid content' ) ) {
			return 'invalid_block_content';
		}
		if ( str_contains( $haystack, 'svg' ) ) {
			return 'broken_svg';
		}
		if ( str_contains( $haystack, 'asset' ) || str_contains( $haystack, 'image' ) || self::missing_asset_path( $row ) ) {
			return 'dropped_images';
		}
		if ( str_contains( $haystack, 'button' ) || str_contains( $haystack, 'style' ) || str_contains( $haystack, 'presentation' ) ) {
			return 'button_style_loss';
		}
		if ( str_contains( $haystack, 'semantic_parity' ) || str_contains( $haystack, 'navigation_' ) || str_contains( $haystack, 'landmark_' ) ) {
			return 'semantic_parity';
		}
		if ( str_contains( $haystack, 'core_html' ) || str_contains( $haystack, 'freeform' ) || str_contains( $haystack, 'fallback' ) ) {
			return 'fallback_block';
		}

		return 'static_site_import_quality';
	}

	/**
	 * Keep acceptable runtime islands out of actionable importer-quality buckets.
	 *
	 * @param string $repair_bucket Current repair bucket.
	 * @param string $loss_class    Product-facing loss class.
	 * @return string
	 */
	private static function repair_bucket_for_loss_class( string $repair_bucket, string $loss_class ): string {
		if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND !== $loss_class ) {
			return $repair_bucket;
		}

		return in_array( $repair_bucket, array( 'static_site_import_quality', 'import_quality' ), true ) ? Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND : $repair_bucket;
	}

	/**
	 * Determine likely product owner for the parser/repair bucket.
	 *
	 * @param string              $type          Diagnostic type.
	 * @param string              $repair_bucket Repair bucket.
	 * @param array<string,mixed> $row           Source row.
	 * @return string
	 */
	private static function parser_owner( string $type, string $repair_bucket, array $row ): string {
		$explicit = self::first_scalar( $row, array( 'parser_owner', 'owner' ), '' );
		if ( in_array( $explicit, array( 'blocks-engine', 'static-site-importer' ), true ) ) {
			return $explicit;
		}

		$engine = self::first_scalar( $row, array( 'engine', 'converter', 'candidate_repo' ), '' );
		if ( str_contains( $engine, 'blocks-engine' ) ) {
			return 'blocks-engine';
		}
		if ( str_contains( $engine, 'static-site-importer' ) ) {
			return 'static-site-importer';
		}

		if ( in_array( $repair_bucket, array( 'dropped_images', 'static_site_import_quality' ), true ) || str_contains( $type, 'asset' ) || str_contains( $type, 'image' ) ) {
			return 'static-site-importer';
		}

		return 'blocks-engine';
	}

	/**
	 * Repair mode for a bucket.
	 *
	 * @param string $repair_bucket Repair bucket.
	 * @return string
	 */
	private static function repair_mode( string $repair_bucket ): string {
		$modes = array(
			'button_style_loss'          => 'transformer-style-parity',
			'broken_svg'                 => 'svg-transformer-parity',
			'dropped_images'             => 'asset-materialization',
			'invalid_block_content'      => 'block-validation-parity',
			'preserved_runtime_island'   => 'accepted-runtime-preservation',
			'runtime_target_gap'         => 'runtime-dom-target-parity',
			'semantic_parity'            => 'semantic-parity',
			'fallback_block'             => 'fallback-block-replacement',
			'static_site_import_quality' => 'import-validation',
		);

		return $modes[ $repair_bucket ] ?? 'import-validation';
	}

	/**
	 * Classify whether a diagnostic represents acceptable preservation or an imported-output defect.
	 *
	 * @param string $loss_class Normalized loss class.
	 * @return string
	 */
	private static function diagnostic_acceptability( string $loss_class ): string {
		if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === $loss_class ) {
			return 'acceptable_preservation';
		}
		if ( in_array( $loss_class, array( Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION, Static_Site_Importer_Diagnostic_Loss_Classes::EDITABLE_APPROXIMATION ), true ) ) {
			return 'acceptable_conversion';
		}

		return 'unacceptable_imported_output_defect';
	}

	/**
	 * Preserve exact source diagnostic identity for matrix and repair-loop consumers.
	 *
	 * @param array<string,mixed> $row        Raw diagnostic row.
	 * @param array<string,mixed> $diagnostic Normalized diagnostic row.
	 * @return array<string,string>
	 */
	private static function source_diagnostic_identity( array $row, array $diagnostic ): array {
		$identity = array();
		foreach ( array( 'id', 'type', 'kind', 'code', 'reason_code', 'reason', 'message', 'source_path', 'selector', 'stage', 'engine' ) as $field ) {
			$value = self::first_scalar( $row, array( $field ), '' );
			if ( '' === $value ) {
				$value = self::first_scalar( $diagnostic, array( $field ), '' );
			}
			if ( '' !== $value ) {
				$identity[ $field ] = $value;
			}
		}

		return $identity;
	}

	/**
	 * Default diagnostic severity.
	 *
	 * @param string $type Diagnostic type.
	 * @return string
	 */
	private static function default_diagnostic_severity( string $type ): string {
		return str_contains( $type, 'missing' ) || str_contains( $type, 'invalid' ) || str_contains( $type, 'error' ) ? 'error' : 'warning';
	}

	/**
	 * Extract a missing asset path.
	 *
	 * @param array<string,mixed> $row Diagnostic row.
	 * @return string
	 */
	private static function missing_asset_path( array $row ): string {
		return self::first_scalar( $row, array( 'missing_asset_path', 'asset_path', 'src', 'href' ), '' );
	}
}
