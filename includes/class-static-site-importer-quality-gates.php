<?php
/**
 * Evaluates import quality gates from a finalized diagnostic report.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Artifact_Diagnostics_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-artifact-diagnostics-adapter.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Fallback_Contract' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-fallback-contract.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-projection.php';
}

/** Computes quality-gate status from normalized import diagnostics. */
final class Static_Site_Importer_Quality_Gates {
	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @param array<string,mixed> $args   Import args.
	 * @return array<string, mixed>
	 */
	public static function finalize_quality_report( Static_Site_Importer_Import_Report $report, array $args ): array {
		self::normalize_quality_report( $report );
		self::reconcile_provider_materialized_fallbacks( $report );
		self::mark_active_companion_script_fallbacks_materialized( $report );
		Static_Site_Importer_Diagnostic_Projection::normalize_import_diagnostics( $report );

		$quality            = $report['quality'];
		$fallback_admission = self::fallback_admission_counts( $report['diagnostics'] ?? array(), (int) $quality['fallback_count'] );
		$quality['accepted_preserved_runtime_island_count'] = $fallback_admission['accepted'];
		$quality['unsupported_fallback_count']              = $fallback_admission['unsupported'];
		$quality['unsafe_layout_constraint_count']          = count( array_filter( $report['diagnostics'] ?? array(), static fn( $diagnostic ): bool => is_array( $diagnostic ) && Static_Site_Importer_Report_Diagnostics::UNSAFE_LAYOUT_CONSTRAINT_TYPE === ( $diagnostic['type'] ?? '' ) ) );
		$quality['omitted_file_count']                     = self::omitted_file_count( $report['diagnostics'] ?? array() );
		$reasons = array();
		if ( $quality['unsupported_fallback_count'] > 0 ) {
			$reasons[] = 'unsupported_html_fallback';
		}
		if ( $quality['content_loss_count'] > 0 ) {
			$reasons[] = 'content_loss_abort';
		}
		if ( $quality['empty_conversion_count'] > 0 ) {
			$reasons[] = 'empty_conversion';
		}
		if ( $quality['core_html_block_count'] > 0 ) {
			$reasons[] = 'core_html_block';
		}
		if ( $quality['freeform_block_count'] > 0 ) {
			$reasons[] = 'freeform_block';
		}
		if ( $quality['invalid_block_count'] > 0 ) {
			$reasons[] = 'invalid_block';
		}
		if ( $quality['unsafe_svg_count'] > 0 ) {
			$reasons[] = 'unsafe_inline_svg';
		}
		if ( ( $quality['image_missing_source_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'image_missing_source';
		}
		if ( $quality['svg_materialization_failure_count'] > 0 ) {
			$reasons[] = 'svg_materialization_failure';
		}
		if ( $quality['svg_sprite_reference_failure_count'] > 0 ) {
			$reasons[] = 'svg_sprite_reference_failure';
		}
		if ( ( $quality['commerce_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'woocommerce_missing';
		}
		if ( ( $quality['companion_plugin_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'companion_plugin_missing';
		}
		if ( ( $quality['runtime_dependency_parity_issue_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'runtime_dependency_parity';
		}
		if ( ( $quality['semantic_parity_failure_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'semantic_parity_failure';
		}
		if ( $quality['unsafe_layout_constraint_count'] > 0 ) {
			$reasons[] = Static_Site_Importer_Report_Diagnostics::UNSAFE_LAYOUT_CONSTRAINT_TYPE;
		}
		if ( ( $quality['omitted_file_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'dropped_artifact_files';
		}

		$quality['pass']            = empty( $reasons );
		$quality['failure_reasons'] = $reasons;
		$quality['fail_import']     = false;
		if ( ! empty( $args['fail_on_quality'] ) && ! $quality['pass'] ) {
			$quality['fail_import'] = true;
		}
		if ( array_key_exists( 'max_fallbacks', $args ) && null !== $args['max_fallbacks'] && $quality['unsupported_fallback_count'] > (int) $args['max_fallbacks'] ) {
			$quality['fail_import'] = true;
		}
		if ( in_array( 'woocommerce_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}
		if ( in_array( 'companion_plugin_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}

		$quality['diagnostic_refs'] = self::quality_diagnostic_refs( $report['diagnostics'] ?? array() );
		$report['quality']          = $quality;
		self::normalize_source_document_diagnostic_refs( $report );
		$report['artifact_diagnostics'] = Static_Site_Importer_Artifact_Diagnostics_Adapter::build_for_import_report( $report );

		return $quality;
	}

	/**
	 * Return the canonical quality shape consumed by report finalization.
	 *
	 * @return array<string,mixed>
	 */
	public static function quality_defaults(): array {
		return array(
			'pass'                                    => true,
			'fallback_count'                          => 0,
			'unsupported_fallback_count'              => 0,
			'accepted_preserved_runtime_island_count' => 0,
			'content_loss_count'                      => 0,
			'empty_conversion_count'                  => 0,
			'core_html_block_count'                   => 0,
			'freeform_block_count'                    => 0,
			'invalid_block_count'                     => 0,
			'invalid_block_document_count'            => 0,
			'unsafe_svg_count'                        => 0,
			'image_missing_source_count'              => 0,
			'svg_materialization_failure_count'       => 0,
			'svg_sprite_reference_failure_count'      => 0,
			'commerce_dependency_failures'            => 0,
			'companion_plugin_dependency_failures'    => 0,
			'interaction_candidate_count'             => 0,
			'runtime_dependency_parity_issue_count'   => 0,
			'semantic_parity_failure_count'           => 0,
			'unsafe_layout_constraint_count'          => 0,
			'omitted_file_count'                      => 0,
			'failure_reasons'                         => array(),
		);
	}

	/**
	 * Normalize partial report quality and retain unresolved compiler fallbacks.
	 *
	 * Website-artifact composition supplies the compiler's nested metrics rather
	 * than the complete conversion-report quality shape. Preserve every supplied
	 * report value while making the quality gate consume its authoritative fallback
	 * count until a materialization receipt explicitly resolves it.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	public static function normalize_quality_report( Static_Site_Importer_Import_Report $report ): void {
		$supplied = isset( $report['quality'] ) && is_array( $report['quality'] ) ? $report['quality'] : array();
		$metrics  = isset( $supplied['metrics'] ) && is_array( $supplied['metrics'] ) ? $supplied['metrics'] : array();
		$quality  = array_merge( self::quality_defaults(), $metrics, $supplied );

		$plan_quality = isset( $report['blocks_engine']['wordpress_site_plan']['quality'] ) && is_array( $report['blocks_engine']['wordpress_site_plan']['quality'] )
			? $report['blocks_engine']['wordpress_site_plan']['quality']
			: array();
		$plan_metrics = isset( $plan_quality['metrics'] ) && is_array( $plan_quality['metrics'] ) ? $plan_quality['metrics'] : $plan_quality;
		if ( isset( $plan_metrics['fallback_count'] ) && is_numeric( $plan_metrics['fallback_count'] ) ) {
			$quality['fallback_count'] = max( (int) $quality['fallback_count'], (int) $plan_metrics['fallback_count'] );
		}

		$report['quality'] = $quality;
	}

	/**
	 * Count normalized diagnostics that report artifact files the compiler omitted.
	 *
	 * The compiler emits no aggregate counter for dropped files, so the count is
	 * derived from the rewritten rows the same way the fallback admission gate
	 * derives its split from diagnostics.
	 *
	 * @param array<int,mixed> $diagnostics Normalized diagnostics.
	 * @return int
	 */
	private static function omitted_file_count( array $diagnostics ): int {
		$count = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && in_array( $diagnostic['type'] ?? '', array( Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_ARTIFACT_FILES_TYPE, Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_ARTIFACT_FILE_TYPE ), true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Split compiler fallbacks into fail-closed unsupported loss and explicitly
	 * accepted, bounded runtime preservation. A count without matching evidence
	 * remains unsupported so producers cannot weaken the admission gate by label.
	 *
	 * @param array<int,mixed> $diagnostics Normalized diagnostics.
	 * @param int              $fallback_count Compiler fallback count.
	 * @return array{accepted:int,unsupported:int}
	 */
	public static function fallback_admission_counts( array $diagnostics, int $fallback_count ): array {
		$accepted = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && self::is_accepted_preserved_runtime_island( $diagnostic ) ) {
				++$accepted;
			}
		}

		$accepted = min( max( 0, $fallback_count ), $accepted );
		return array(
			'accepted'    => $accepted,
			'unsupported' => max( 0, $fallback_count - $accepted ),
		);
	}

	/**
	 * Require a complete generic runtime-preservation contract before admitting a
	 * fallback. Raw HTML and unsafe or unbounded payloads always remain gated.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 */
	public static function is_accepted_preserved_runtime_island( array $diagnostic ): bool {
		if ( 'unsupported_html_fallback' !== ( $diagnostic['type'] ?? '' ) ) {
			return false;
		}
		$loss_class = Static_Site_Importer_Diagnostic_Loss_Classes::canonicalize( (string) ( $diagnostic['loss_class'] ?? '' ) );
		if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND !== $loss_class || ! in_array( $diagnostic['acceptability'] ?? '', array( 'acceptable_conversion', 'acceptable_preservation' ), true ) ) {
			return false;
		}
		if ( ! in_array( $diagnostic['preservation_strategy'] ?? '', array( 'sanitized_embed_markup', 'fallback_metadata_with_readable_blocks' ), true ) || '' === trim( (string) ( $diagnostic['runtime_requirement'] ?? '' ) ) || '' === trim( (string) ( $diagnostic['materialization_path'] ?? '' ) ) ) {
			return false;
		}
		if ( '' === trim( (string) ( $diagnostic['id'] ?? '' ) ) || '' === trim( (string) ( $diagnostic['source_path'] ?? '' ) ) || '' === trim( (string) ( $diagnostic['selector'] ?? '' ) ) || '' === trim( (string) ( $diagnostic['reason_code'] ?? '' ) ) ) {
			return false;
		}

		$payload = (string) ( $diagnostic['source_html_preview'] ?? $diagnostic['html_excerpt'] ?? '' );
		if ( '' === trim( $payload ) || strlen( $payload ) > 8192 ) {
			return false;
		}

		return 1 !== preg_match( '/<\s*(?:script|style)\b|\bon[a-z]+\s*=|(?:javascript|data)\s*:|\bsrcdoc\s*=|<!--\s*wp:(?:html|freeform)\b/i', $payload )
			&& ! in_array( $diagnostic['block_name'] ?? '', array( 'core/html', 'core/freeform' ), true );
	}

	/** Identify the canonical form fallback across raw and normalized diagnostics. */
	public static function is_form_fallback_diagnostic( array $diagnostic ): bool {
		foreach ( array( 'diagnostic_code', 'code', 'reason_code' ) as $key ) {
			if ( 'html_form_fallback' === (string) ( $diagnostic[ $key ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve script fallback diagnostics after the generated companion plugin is active.
	 *
	 * @param Static_Site_Importer_Import_Report            $report          Import report.
	 * @param array<int,array<string,mixed>> $runtime_scripts Materialized companion scripts.
	 * @param string                         $slug             Companion plugin slug.
	 * @return void
	 */
	public static function mark_companion_script_fallbacks_materialized( Static_Site_Importer_Import_Report $report, array $runtime_scripts, string $slug ): void {
		$selectors = array();
		foreach ( $runtime_scripts as $script ) {
			$selector = Static_Site_Importer_Diagnostic_Projection::first_scalar( $script, array( 'selector' ) );
			if ( '' !== $selector ) {
				$selectors[ $selector ] = true;
			}
		}
		if ( empty( $selectors ) || empty( $report['diagnostics'] ) || ! is_array( $report['diagnostics'] ) ) {
			return;
		}

		$resolved    = array();
		$diagnostics = $report->diagnostics();
		foreach ( $diagnostics as &$diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::is_script_runtime_fallback_diagnostic( $diagnostic ) ) {
				continue;
			}
			$selector = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'selector' ) );
			if ( '' === $selector || ! isset( $selectors[ $selector ] ) ) {
				continue;
			}

			$diagnostic['original_code']                 = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'code', 'diagnostic_code', 'kind', 'type' ) );
			$diagnostic['code']                          = 'runtime_script_materialized';
			$diagnostic['diagnostic_code']               = 'runtime_script_materialized';
			$diagnostic['kind']                          = 'runtime_script_materialized';
			$diagnostic['type']                          = 'runtime_script_materialized';
			$diagnostic['severity']                      = 'info';
			$diagnostic['loss_class']                    = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['diagnostic_class']              = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['repair_bucket']                 = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['runtime_carried']               = true;
			$diagnostic['materialized_runtime_provider'] = 'companion_plugin';
			$diagnostic['companion_plugin']              = $slug;
			$diagnostic['message']                       = sprintf( 'Runtime script is materialized by active companion plugin %s.', $slug );
			$resolved[ $selector ]                       = true;
		}
		unset( $diagnostic );
		$report->set_diagnostics( $diagnostics );

		if ( ! empty( $resolved ) ) {
			$quality                   = $report->quality();
			$quality['fallback_count'] = max( 0, (int) ( $quality['fallback_count'] ?? 0 ) - count( $resolved ) );
			$report->set_quality( $quality );
		}
	}

	/** Identify diagnostics that represent a source script awaiting runtime carriage. */
	public static function is_script_runtime_fallback_diagnostic( array $diagnostic ): bool {
		$code   = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'code', 'diagnostic_code', 'kind', 'type', 'reason' ) );
		$tag    = strtolower( Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'tag' ) ) );
		$reason = strtolower( $code );
		return 'script' === $tag || str_contains( $reason, 'script' ) || str_contains( $reason, 'runtime' );
	}

	/**
	 * Reconcile late-added fallback rows against active companion dependencies.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	public static function mark_active_companion_script_fallbacks_materialized( Static_Site_Importer_Import_Report $report ): void {
		$dependencies = $report['companion_plugins']['dependencies'] ?? array();
		if ( ! is_array( $dependencies ) ) {
			return;
		}

		foreach ( $dependencies as $slug => $dependency ) {
			if ( ! is_array( $dependency ) || empty( $dependency['active'] ) ) {
				continue;
			}
			$runtime_scripts = isset( $dependency['runtime_scripts'] ) && is_array( $dependency['runtime_scripts'] ) ? $dependency['runtime_scripts'] : array();
			self::mark_companion_script_fallbacks_materialized( $report, $runtime_scripts, (string) $slug );
		}
	}

	/**
	 * Reconcile source form fallbacks against hash-bound provider receipts.
	 *
	 * The source finding remains in diagnostics for auditability. Only the final
	 * quality count excludes a form after a completed receipt proves that exact
	 * source fallback was replaced by the provider's persisted block markup.
	 * A matching provider decline does not resolve the fallback; it endorses the
	 * preserved island so admission can count it instead of failing closed.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	public static function reconcile_provider_materialized_fallbacks( Static_Site_Importer_Import_Report $report, array $receipts = array() ): void {
		$quality                          = $report->quality();
		$source_total                     = max(
			(int) ( $quality['source_fallback_count'] ?? 0 ),
			(int) ( $quality['fallback_count'] ?? 0 )
		);
		$quality['source_fallback_count'] = $source_total;
		$report->set_quality( $quality );

		if ( empty( $receipts ) ) {
			$bindings = $report['materialization_receipt']['completed']['runtime_declarations']['entity_bindings'] ?? array();
			foreach ( $bindings as $binding ) {
				if ( ! is_array( $binding ) || 'completed' !== ( $binding['status'] ?? null ) || 'form' !== ( $binding['role'] ?? null ) ) {
					continue;
				}
				$receipts[] = array(
					'schema'                           => 'static-site-importer/quality-resolution-receipt/v1',
					'status'                           => 'completed',
					'fallback_reconciliation_identity' => $binding['fallback_reconciliation_identity'] ?? '',
					'source_path'                      => $binding['source_path'] ?? '',
					'fallback_hash'                    => $binding['fallback_hash'] ?? '',
					'binding_reconciliation_identity'  => $binding['reconciliation_identity'] ?? '',
					'materialized_block_hash'          => $binding['materialized_block_hash'] ?? '',
					'persisted_fragment_hash'          => $binding['persisted_fragment_hash'] ?? '',
					'materialized_content_hash'        => $binding['materialized_content_hash'] ?? '',
					'provider'                         => $binding['provider'] ?? '',
				);
			}
		}
		$receipts_by_fallback    = array();
		$receipts_by_source_hash = array();
		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) || ! is_string( $receipt['fallback_reconciliation_identity'] ?? null ) ) {
				continue;
			}
			$identity = $receipt['fallback_reconciliation_identity'];
			if ( isset( $receipts_by_fallback[ $identity ] ) ) {
				// A source identity is consumed once. Ambiguous proof must leave it unresolved.
				$receipts_by_fallback[ $identity ] = false;
				continue;
			}
			$receipts_by_fallback[ $identity ] = $receipt;

			$source_path   = Static_Site_Importer_Diagnostic_Projection::first_scalar( $receipt, array( 'source_path', 'source' ) );
			$fallback_hash = $receipt['fallback_hash'] ?? '';
			if ( '' === $source_path || ! is_string( $fallback_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fallback_hash ) ) {
				continue;
			}
			$source_hash_key = $source_path . "\n" . $fallback_hash;
			if ( isset( $receipts_by_source_hash[ $source_hash_key ] ) ) {
				$receipts_by_source_hash[ $source_hash_key ] = false;
				continue;
			}
			$receipts_by_source_hash[ $source_hash_key ] = $receipt;
		}
		$resolved    = 0;
		$resolutions = array();
		foreach ( $report['diagnostics'] ?? array() as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::is_form_fallback_diagnostic( $diagnostic ) ) {
				continue;
			}
			$fallback_hash     = Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $diagnostic );
			$source_path       = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'source_path', 'source' ) );
			$identity          = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $diagnostic );
			$candidate_receipt = $receipts_by_fallback[ $identity ] ?? array();
			$receipt           = is_array( $candidate_receipt ) ? $candidate_receipt : array();
			if ( empty( $receipt ) && ! self::has_form_fallback_identity( $diagnostic ) ) {
				// Recover a missing producer identity only from one exact persisted form.
				$candidate_receipt = $receipts_by_source_hash[ $source_path . "\n" . $fallback_hash ] ?? array();
				if ( is_array( $candidate_receipt ) ) {
					$producer_identity = $candidate_receipt['fallback_reconciliation_identity'] ?? '';
					if ( is_string( $producer_identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $producer_identity ) ) {
						$diagnostic['source_fallback_identity'] = $producer_identity;
						$identity                               = $producer_identity;
						$receipt                                = $candidate_receipt;
					}
				}
			}
			$page_receipt         = $report['materialization_receipt']['completed']['materialized_pages'][ $source_path ] ?? array();
			$page_hash            = is_array( $page_receipt ) && is_string( $page_receipt['content_hash'] ?? null ) ? $page_receipt['content_hash'] : '';
			$resolved_by_provider = 'static-site-importer/quality-resolution-receipt/v1' === ( $receipt['schema'] ?? null )
				&& 'completed' === ( $receipt['status'] ?? null )
				&& ( $receipt['fallback_reconciliation_identity'] ?? null ) === $identity
				&& ( $receipt['fallback_hash'] ?? null ) === $fallback_hash
				&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['binding_reconciliation_identity'] ?? '' ) )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['materialized_block_hash'] ?? '' ) )
				&& ( $receipt['persisted_fragment_hash'] ?? null ) === ( $receipt['materialized_block_hash'] ?? null )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['materialized_content_hash'] ?? '' ) )
				&& '' !== trim( (string) ( $receipt['provider'] ?? '' ) )
				&& ( $receipt['materialized_content_hash'] ?? null ) === $page_hash;

			$diagnostic['fallback_reconciliation_identity'] = $identity;
			$diagnostic['fallback_hash']                    = $fallback_hash;
			$diagnostic['fallback_resolution']              = array(
				'source_state' => 'detected',
				'state'        => $resolved_by_provider ? 'resolved_by_provider' : 'unresolved',
				'receipt'      => $receipt,
			);
			$report->replace_diagnostic( $index, $diagnostic );
			if ( $resolved_by_provider ) {
				++$resolved;
			}
			$resolutions[] = array(
				'fallback_reconciliation_identity' => $identity,
				'fallback_hash'                    => $fallback_hash,
				'state'                            => $resolved_by_provider ? 'resolved_by_provider' : 'unresolved',
				'receipt'                          => $receipt,
			);
		}

		$report->merge_quality( array( 'fallback_count' => max( 0, $source_total - $resolved ) ) );
		$report['quality_resolutions']     = array(
			'schema'                    => 'static-site-importer/quality-resolutions/v1',
			'source_fallback_count'     => $source_total,
			'resolved_by_provider'      => $resolved,
			'unresolved_fallback_count' => max( 0, $source_total - $resolved ),
			'resolutions'               => $resolutions,
		);
		$report['fallback_reconciliation'] = $report['quality_resolutions'];
		self::endorse_declined_form_islands( $report );
	}

	/**
	 * Endorse a declined form fallback as an accepted preserved runtime island.
	 *
	 * A provider decline is already classified `preserved_runtime_island` /
	 * `acceptable_preservation`. The compiler's matching `html_form_fallback` is
	 * not: its repair bucket still says `materialize_form_provider`, so
	 * classification treats it as an importer bug and admission leaves it in
	 * `unsupported_fallback_count`. Stamping the SSI-owned island contract onto
	 * that fallback is the evidence `is_accepted_preserved_runtime_island()`
	 * requires. Producers cannot admit a form by label because they do not set
	 * `type`, `acceptability`, or `materialization_path`.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	private static function endorse_declined_form_islands( Static_Site_Importer_Import_Report $report ): void {
		$declined_keys = array();
		foreach ( $report['diagnostics'] ?? array() as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || 'provider_entity_declined' !== ( $diagnostic['code'] ?? '' ) ) {
				continue;
			}
			if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND !== ( $diagnostic['loss_class'] ?? '' ) ) {
				continue;
			}
			$key = self::form_island_key( $diagnostic );
			if ( '' !== $key ) {
				$declined_keys[ $key ] = true;
			}
		}
		if ( array() === $declined_keys ) {
			return;
		}

		$quality   = $report->quality();
		$fallbacks = isset( $quality['fallbacks'] ) && is_array( $quality['fallbacks'] ) ? $quality['fallbacks'] : array();
		foreach ( $report['diagnostics'] ?? array() as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::is_form_fallback_diagnostic( $diagnostic ) ) {
				continue;
			}
			if ( 'resolved_by_provider' === ( $diagnostic['fallback_resolution']['state'] ?? '' ) ) {
				continue;
			}
			$key = self::form_island_key( $diagnostic );
			if ( '' === $key || ! isset( $declined_keys[ $key ] ) ) {
				continue;
			}

			$payload = self::form_fallback_payload( $diagnostic, $fallbacks );

			$diagnostic['loss_class']       = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			$diagnostic['diagnostic_class'] = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			$diagnostic['acceptability']    = 'acceptable_preservation';
			$diagnostic['repair_bucket']    = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			$diagnostic['type']             = 'unsupported_html_fallback';
			if ( '' === trim( (string) ( $diagnostic['materialization_path'] ?? '' ) ) ) {
				$diagnostic['materialization_path'] = 'runtime_island_registry';
			}
			if ( '' === trim( (string) ( $diagnostic['source_html_preview'] ?? $diagnostic['html_excerpt'] ?? '' ) ) && '' !== $payload ) {
				$diagnostic['source_html_preview'] = $payload;
			}
			$report->replace_diagnostic( $index, $diagnostic );
		}
	}

	/**
	 * Join key for a form island and a matching provider decline.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 */
	private static function form_island_key( array $diagnostic ): string {
		$source_path = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'source_path', 'source' ) );
		$selector    = Static_Site_Importer_Diagnostic_Projection::first_scalar( $diagnostic, array( 'selector' ) );
		if ( '' === $source_path || '' === $selector ) {
			return '';
		}

		return $source_path . "\n" . $selector;
	}

	/**
	 * Bounded HTML evidence for a form fallback, including compiler fallback rows.
	 *
	 * @param array<string,mixed> $diagnostic Form fallback diagnostic.
	 * @param array<int,mixed>    $fallbacks  Compiler quality fallback rows.
	 */
	private static function form_fallback_payload( array $diagnostic, array $fallbacks ): string {
		$payload = trim( (string) ( $diagnostic['source_html_preview'] ?? $diagnostic['html_excerpt'] ?? $diagnostic['html'] ?? '' ) );
		if ( '' !== $payload ) {
			return $payload;
		}

		$key = self::form_island_key( $diagnostic );
		if ( '' === $key ) {
			return '';
		}
		foreach ( $fallbacks as $fallback ) {
			if ( ! is_array( $fallback ) || self::form_island_key( $fallback ) !== $key ) {
				continue;
			}
			$payload = trim( (string) ( $fallback['source_html_preview'] ?? $fallback['html_excerpt'] ?? $fallback['html'] ?? '' ) );
			if ( '' !== $payload ) {
				return $payload;
			}
		}

		return '';
	}

	/** @param array<string,mixed> $fallback */
	public static function has_form_fallback_identity( array $fallback ): bool {
		foreach ( array( 'source_fallback_identity', 'fallback_reconciliation_identity', 'fallback_identity' ) as $field ) {
			$identity = $fallback[ $field ] ?? null;
			if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build quality counter references into normalized diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,array<int,string>> Diagnostic IDs keyed by quality count.
	 */
	public static function quality_diagnostic_refs( array $diagnostics ): array {
		$types_by_count = array(
			'fallback_count'                          => array( 'unsupported_html_fallback' ),
			'unsupported_fallback_count'              => array( 'unsupported_html_fallback' ),
			'accepted_preserved_runtime_island_count' => array( 'unsupported_html_fallback' ),
			'content_loss_count'                      => array( 'content_loss_abort' ),
			'empty_conversion_count'                  => array( 'empty_conversion' ),
			'core_html_block_count'                   => array( 'core_html_block' ),
			'freeform_block_count'                    => array( 'freeform_block' ),
			'invalid_block_count'                     => array( 'invalid_block_document' ),
			'unsafe_svg_count'                        => array( 'unsafe_inline_svg' ),
			'image_missing_source_count'              => array( 'image_missing_source' ),
			'svg_materialization_failure_count'       => array( 'svg_materialization_failure' ),
			'svg_sprite_reference_failure_count'      => array( 'svg_sprite_reference_failure' ),
			'commerce_dependency_failures'            => array( 'commerce_dependency_failure' ),
			'interaction_candidate_count'             => array( 'interaction_candidate' ),
			'runtime_dependency_parity_issue_count'   => array( 'runtime_dependency_missing_dom_target', 'runtime_dependency_unsupported_element_reference', 'runtime_dependency_parity_issue' ),
			'semantic_parity_failure_count'           => array( 'semantic_parity_navigation_missing', 'semantic_parity_navigation_mismatch', 'semantic_parity_landmark_missing', 'semantic_parity_failure' ),
			'unsafe_layout_constraint_count'          => array( Static_Site_Importer_Report_Diagnostics::UNSAFE_LAYOUT_CONSTRAINT_TYPE ),
			'omitted_file_count'                      => array( Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_ARTIFACT_FILES_TYPE, Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_ARTIFACT_FILE_TYPE ),
		);

		$refs = array();
		foreach ( $types_by_count as $count_key => $types ) {
			$refs[ $count_key ] = array_values(
				array_filter(
					array_map(
					static function ( array $diagnostic ) use ( $count_key, $types ): string {
						if ( ! in_array( $diagnostic['type'] ?? '', $types, true ) || ! isset( $diagnostic['id'] ) ) {
							return '';
						}
						if ( 'accepted_preserved_runtime_island_count' === $count_key && ! self::is_accepted_preserved_runtime_island( $diagnostic ) ) {
							return '';
						}
						if ( 'unsupported_fallback_count' === $count_key && self::is_accepted_preserved_runtime_island( $diagnostic ) ) {
							return '';
						}
							return (string) $diagnostic['id'];
					},
						$diagnostics
					)
				)
			);
		}

		return $refs;
	}

	/**
	 * Link source-document counts back to concrete diagnostics.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	public static function normalize_source_document_diagnostic_refs( Static_Site_Importer_Import_Report $report ): void {
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$refs        = array(
			'unresolved_link_count'      => array(),
			'skipped_mdx_count'          => array(),
			'markdown_parse_error_count' => array(),
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || empty( $diagnostic['id'] ) ) {
				continue;
			}

			$type = (string) ( $diagnostic['type'] ?? '' );
			if ( 'unresolved_internal_link' === $type ) {
				$refs['unresolved_link_count'][] = (string) $diagnostic['id'];
			} elseif ( 'unsupported_source_document' === $type ) {
				$refs['skipped_mdx_count'][] = (string) $diagnostic['id'];
			} elseif ( 'markdown_parse_error' === $type ) {
				$refs['markdown_parse_error_count'][] = (string) $diagnostic['id'];
			}
		}

		$report->set_in_section( 'source_documents', 'diagnostic_refs', $refs );
	}
}
