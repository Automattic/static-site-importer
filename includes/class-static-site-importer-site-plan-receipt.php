<?php
/**
 * Composes materialization receipts from prepared and persisted plan state.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Quality_Budget_Admission' ) ) {
	require_once __DIR__ . '/class-static-site-importer-quality-budget-admission.php';
}
if ( ! class_exists( 'Static_Site_Importer_Public_Error_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-public-error-projection.php';
}

/** Projects stable materialization receipts without performing writes. */
final class Static_Site_Importer_Site_Plan_Receipt {
	private const BLOCK_PROVENANCE_LIMIT = 50;

	/** Preserve typed preflight diagnostics without claiming filesystem mutation. */
	public static function rejected_receipt_from_error( array $state, WP_Error $error ): array {
		unset( $state['preflight_error'] );
		$state['diagnostics'][]  = array( 'reason_code' => $error->get_error_code() );
		$state['failure_reason'] = $error->get_error_code();
		$data                    = $error->get_error_data();
		if ( is_array( $data ) ) {
			$diagnostics = is_array( $data['diagnostics'] ?? null ) ? $data['diagnostics'] : $data;
			$diagnostics = 'static_site_importer_entity_materialization_failed' === $error->get_error_code() ? Static_Site_Importer_Public_Error_Projection::project_public_diagnostics( $diagnostics ) : $diagnostics;
			foreach ( $diagnostics as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				$reason = (string) ( $diagnostic['reason_code'] ?? $diagnostic['reason'] ?? $diagnostic['code'] ?? '' );
				if ( '' !== $reason ) {
					$state['diagnostics'][] = array_merge( $diagnostic, array( 'reason_code' => $reason ) );
				}
			}
		}
		return self::receipt( 'rejected', $state );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	public static function receipt( string $status, array $state ): array {
		unset( $state['font_overlay'], $state['viewport_overlay'], $state['route_title_overlay'], $state['provider_layout_overlay_writes'], $state['composed_theme_writes'], $state['preflight_error'] );
		$plan                   = $state['plan'];
		$resolved_plan          = $state['resolved'] ?? $plan;
		$materialized_pages     = array();
		$block_provenance       = array();
		$block_provenance_count = 0;
		$written_sources        = array_fill_keys( array_filter( array_column( $state['applied']['posts'] ?? array(), 'source_path' ), 'is_string' ), true );
		$receipt_pages          = $resolved_plan['pages'] ?? array();
		foreach ( $receipt_pages as &$page ) {
			if ( isset( $written_sources[ $page['source_path'] ] ) ) {
				$materialized_markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
				++$block_provenance_count;
				if ( count( $block_provenance ) < self::BLOCK_PROVENANCE_LIMIT ) {
					$block_provenance[] = self::block_provenance( $page, $materialized_markup );
				}
				if ( isset( $page['materialized_block_markup'] ) ) {
					$materialized_pages[ $page['source_path'] ] = array(
						'block_markup' => $page['materialized_block_markup'],
						'content_hash' => hash( 'sha256', $page['materialized_block_markup'] ),
					);
				}
			}
			if ( isset( $page['materialized_block_markup'] ) ) {
				unset( $page['materialized_block_markup'] );
			}
		}
		unset( $page );
		$resolved_plan['pages'] = $receipt_pages;
		$errors                 = array();
		$pages                  = isset( $state['source_ids'] ) && is_array( $state['source_ids'] ) ? $state['source_ids'] : array();
		if ( isset( $state['failure_reason'] ) && is_string( $state['failure_reason'] ) && '' !== $state['failure_reason'] ) {
			$errors[] = array(
				'code'    => $state['failure_reason'],
				'message' => class_exists( 'Static_Site_Importer_Public_Error_Projection' ) ? Static_Site_Importer_Public_Error_Projection::project_public_error_message( $state['failure_reason'] ) : 'Materialization failed.',
			);
		}
		$receipt = array(
			'schema'                    => Static_Site_Importer_WordPress_Site_Plan_Materializer::RECEIPT_SCHEMA,
			'status'                    => $status,
			'plan_identity'             => $state['plan_identity'],
			'receipt_instance_id'       => self::valid_receipt_instance_id( $state['receipt_instance_id'] ?? null ) ? $state['receipt_instance_id'] : self::receipt_instance_id(),
			'plan'                      => $resolved_plan,
			'theme'                     => $state['theme'] ?? array(),
			'completed'                 => array(
				'pages'                      => $pages,
				'files'                      => $state['applied']['files'],
				'operations'                 => $state['applied']['operations'],
				'runtime_declarations'       => $state['applied']['runtime_declarations'] ?? array( 'asset_publications' => array() ),
				'font_materialization'       => $state['applied']['font_materialization'] ?? array(
					'status'      => 'not_requested',
					'files'       => array(),
					'diagnostics' => array(),
				),
				'viewport_metadata'          => $state['applied']['viewport_metadata'] ?? array(
					'status'      => 'not_requested',
					'declaration' => '',
					'files'       => array(),
					'diagnostics' => array(),
				),
				'route_document_titles'      => $state['applied']['route_document_titles'] ?? array(
					'status' => 'not_requested',
					'files'  => array(),
				),
				'provider_layout_overlays'   => $state['applied']['provider_layout_overlays'] ?? array(
					'status' => 'not_requested',
					'files'  => array(),
				),
				'companion_asset_loading'    => $state['applied']['companion_asset_loading'] ?? array( 'status' => 'not_requested' ),
				'runtime_policy'             => array(
					'disable_smilies'        => array(
						'requested' => isset( $state['args']['disable_smilies'] ) ? (bool) $state['args']['disable_smilies'] : true,
						'applied'   => isset( $state['applied']['runtime_policy']['disable_smilies'] ) && true === $state['applied']['runtime_policy']['disable_smilies'],
					),
					'remove_default_content' => array(
						'requested' => isset( $state['args']['remove_default_content'] ) ? (bool) $state['args']['remove_default_content'] : true,
						'report'    => $state['applied']['runtime_policy']['remove_default_content'] ?? array( 'status' => 'not_applied' ),
					),
				),
				'materialized_pages'         => $materialized_pages,
				'block_provenance'           => $block_provenance,
				'block_provenance_count'     => $block_provenance_count,
				'block_provenance_truncated' => $block_provenance_count > count( $block_provenance ),
				'declaration_ids'            => array_keys( $state['applied']['runtime_declarations']['asset_publications'] ?? array() ),
			),
			'reconciliation_identities' => array_merge( array_column( $plan['pages'] ?? array(), 'reconciliation_identity' ), array_column( $plan['writes'] ?? array(), 'reconciliation_identity' ), array_column( $plan['runtime_declarations'] ?? array(), 'reconciliation_identity' ) ),
			'wordpress'                 => $state['applied']['posts'],
			'generated_files'           => $state['applied']['files'],
			'operations'                => $state['applied']['operations'],
			'skipped_targets'           => $state['skipped'],
			'existing_matches'          => $state['existing_matches'],
			'rollback'                  => array(
				'status'   => ! empty( $state['rollback']['partial'] ) ? 'partial' : ( ! empty( $state['rollback']['done'] ) ? 'rolled_back' : 'not_requested' ),
				'failures' => array_slice( is_array( $state['rollback']['failures'] ?? null ) ? $state['rollback']['failures'] : array(), 0, 32 ),
			),
			'preparation'               => $state['preparation'] ?? array(),
			'editability_report'        => $state['editability_report'] ?? array(
				'schema' => 'static-site-importer/editability-report-admission/v1',
				'status' => 'not_checked',
			),
			'quality_budget_admission'  => $state['quality_budget_admission'] ?? Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved_plan, $state['args'] ?? array(), array(), Static_Site_Importer_Quality_Budget_Admission::applied_entity_bindings( $state ) ),
			'diagnostics'               => $state['diagnostics'],
			'errors'                    => $errors,
			'theme_materialization'     => $state['theme_materialization'] ?? self::strategy_evidence( $state['args'] ?? array() ),
		);
		$receipt['quality_budget_admission']['mechanical_status'] = 'completed' === $status ? 'completed' : $status;
		if ( ! empty( $state['args']['defer_materialization_commit'] ) && 'completed' === $status ) {
			$receipt['transaction'] = (object) array( 'state' => $state );
		}
		return $receipt;
	}

	/** Generate a server-side receipt identity that cannot be inferred from the plan. */
	public static function receipt_instance_id(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Validate the persistent receipt identity across deferred materialization phases. */
	public static function valid_receipt_instance_id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $id );
	}

	/** @return array<string,mixed> */
	public static function strategy_evidence( array $args ): array {
		$strategy = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return array(
				'schema'      => 'static-site-importer/theme-materialization-evidence/v1',
				'status'      => 'invalid',
				'reason_code' => $strategy->get_error_code(),
			);
		}
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] && is_array( $args['classic_theme_projection'] ?? null ) ) {
			$strategy['evidence']['status']            = 'source_artifact_projection';
			$strategy['evidence']['projection_schema'] = $args['classic_theme_projection']['schema'] ?? '';
		}
		return $strategy['evidence'];
	}

	/**
	 * Record bounded stage evidence for one WordPress page without retaining markup.
	 *
	 * @param array<string,mixed> $page              Resolved compiler page.
	 * @param string              $materialized_markup WordPress post-content markup.
	 * @return array<string,mixed>
	 */
	public static function block_provenance( array $page, string $materialized_markup ): array {
		$resolved_markup   = (string) ( $page['resolved_block_markup'] ?? '' );
		$resolved_evidence = self::bounded_block_markup_evidence( $resolved_markup );
		$stages            = array(
			array(
				'stage'  => 'blocks-engine/wordpress-site-plan-resolver',
				'output' => $resolved_evidence,
			),
		);
		if ( $resolved_markup !== $materialized_markup ) {
			$stages[] = array(
				'stage'        => 'static-site-importer/runtime-entity-bindings',
				'input_sha256' => $resolved_evidence['sha256'],
				'output'       => self::bounded_block_markup_evidence( $materialized_markup ),
			);
		}

		return array(
			// This mirrors the page meta provenance written during materialization.
			'source' => array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'source_path'             => (string) ( $page['source_path'] ?? '' ),
				'reconciliation_identity' => (string) ( $page['reconciliation_identity'] ?? '' ),
			),
			'stages' => $stages,
		);
	}

	/** @return array{sha256:string,bytes:int} */
	public static function bounded_block_markup_evidence( string $markup ): array {
		return array(
			'sha256' => hash( 'sha256', $markup ),
			'bytes'  => strlen( $markup ),
		);
	}
}
