<?php
/**
 * Applies the canonical Blocks Engine WordPress site plan to a WordPress runtime.
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/class-static-site-importer-stylesheet-materializer.php';
require_once __DIR__ . '/class-static-site-importer-protected-page-policy.php';
require_once __DIR__ . '/class-static-site-importer-default-content.php';
require_once __DIR__ . '/class-static-site-importer-route-document-metadata.php';
require_once __DIR__ . '/class-static-site-importer-internal-link-runtime.php';
require_once __DIR__ . '/class-static-site-importer-source-route-redirect.php';
require_once __DIR__ . '/class-static-site-importer-route-head-metadata.php';
if ( ! class_exists( 'Static_Site_Importer_Theme_Materialization_Strategy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-theme-materialization-strategy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Import_Destination' ) ) {
	require_once __DIR__ . '/class-static-site-importer-import-destination.php';
}
if ( ! class_exists( 'Static_Site_Importer_Companion_Asset_Publication' ) ) {
	require_once __DIR__ . '/class-static-site-importer-companion-asset-publication.php';
}
if ( ! class_exists( 'Static_Site_Importer_Classic_Theme_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-classic-theme-projection.php';
}
if ( ! class_exists( 'Static_Site_Importer_Current_Site_Capabilities' ) ) {
	require_once __DIR__ . '/class-static-site-importer-current-site-capabilities.php';
}
if ( ! class_exists( 'Static_Site_Importer_Quality_Budget_Admission' ) ) {
	require_once __DIR__ . '/class-static-site-importer-quality-budget-admission.php';
}
require_once __DIR__ . '/class-static-site-importer-site-plan-receipt.php';
require_once __DIR__ . '/class-static-site-importer-site-plan-preparation.php';
require_once __DIR__ . '/class-static-site-importer-site-plan-persistence.php';
require_once __DIR__ . '/class-static-site-importer-media-library-materializer.php';
require_once __DIR__ . '/class-static-site-importer-prepared-plan-application.php';

final class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public const RECEIPT_SCHEMA = 'static-site-importer/materialization-receipt/v2';

	/**
	 * Materialize a fully canonical v2 plan. Compilation and plan validation belong to Blocks Engine.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Receipt.
	 */
	public static function materialize( array $plan, array $args = array() ): array {
		$prepared = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			return $prepared['receipt'];
		}
		return Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
	}

	/** Seam for Static_Site_Importer_Prepared_Plan_Application::materialize(); see it for the contract. */
	public static function materialize_prepared_lifecycle( array $prepared, array $lifecycle, $companion_payload, array $gutenberg_gaps, array $theme_materialization ) {
		return Static_Site_Importer_Prepared_Plan_Application::materialize( $prepared, $lifecycle, $companion_payload, $gutenberg_gaps, $theme_materialization );
	}

	/** Public because checkpoint preparation provisions the same typed dependencies. */
	public static function materialize_runtime_dependencies( array $lifecycle, array $args ) {
		return Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle, $args );
	}

	/** Seam for Static_Site_Importer_Site_Plan_Preparation::prepare(); see it for the contract. */
	public static function prepare( array $plan, array $args = array() ): array {
		return Static_Site_Importer_Site_Plan_Preparation::prepare( $plan, $args );
	}

	/** Prepare the canonical plan state that may safely precede provider provisioning. */
	public static function prepare_for_materialization( array $plan, array $args = array() ): array {
		return Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
	}

	/** Seam for Static_Site_Importer_Site_Plan_Preparation::admit_prepared(); see it for the contract. */
	public static function admit_prepared( array $prepared ): array {
		return Static_Site_Importer_Site_Plan_Preparation::admit_prepared( $prepared );
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		return Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
	}

	/** Seam for Static_Site_Importer_Site_Plan_Preparation::safe_external_report_destination(); see it for the contract. */
	public static function safe_external_report_destination( $path ): bool {
		return Static_Site_Importer_Site_Plan_Preparation::safe_external_report_destination( $path );
	}

	/** Add a late file mutation to a deferred materialization receipt. */
	public static function journal_receipt_file( array &$receipt, string $path ): void {
		Static_Site_Importer_Site_Plan_Persistence::journal_receipt_file( $receipt, $path );
	}

	/** Add a late post mutation to a deferred materialization receipt. */
	public static function journal_receipt_post( array &$receipt, int $id ): void {
		Static_Site_Importer_Site_Plan_Persistence::journal_receipt_post( $receipt, $id );
	}

	/** Commit a deferred receipt after every durable projection has completed. */
	public static function commit_receipt( array &$receipt ): void {
		Static_Site_Importer_Site_Plan_Persistence::commit_receipt( $receipt );
	}

	/** Roll back a deferred receipt, including late files and options, in reverse journal order. */
	public static function rollback_receipt( array &$receipt, string $reason ): array {
		return Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, $reason );
	}

	/** Hash the resolved projection only for prepare-to-write change detection. */
	public static function prepared_resolved_projection_hash( array $projection ): string {
		return Static_Site_Importer_Site_Plan_Preparation::prepared_resolved_projection_hash( $projection );
	}
}
