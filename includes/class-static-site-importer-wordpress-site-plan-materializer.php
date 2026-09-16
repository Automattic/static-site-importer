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

	/**
	 * Apply prepared runtime declarations and the canonical plan as one transaction.
	 *
	 * Theme_Generator supplies compiler-owned declarations and projects the public
	 * import result; this boundary owns every WordPress/provider mutation.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize_prepared_lifecycle( array $prepared, array $lifecycle, $companion_payload, array $gutenberg_gaps, array $theme_materialization ) {
		return Static_Site_Importer_Prepared_Plan_Application::materialize( $prepared, $lifecycle, $companion_payload, $gutenberg_gaps, $theme_materialization );
	}

	/** Public because checkpoint preparation provisions the same typed dependencies. */
	public static function materialize_runtime_dependencies( array $lifecycle, array $args ) {
		return Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle, $args );
	}

	/**
	 * Validate and resolve every destination without mutating WordPress or the filesystem.
	 *
	 * The resulting state may be passed to materialize_prepared(). That method prepares
	 * again before writing so changes after this check cannot bypass conflict protection.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function prepare( array $plan, array $args = array() ): array {
		return Static_Site_Importer_Site_Plan_Preparation::prepare( $plan, $args );
	}

	/** Prepare the canonical plan state that may safely precede provider provisioning. */
	public static function prepare_for_materialization( array $plan, array $args = array() ): array {
		return Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
	}

	/**
	 * Verify all reference-backed payloads before related runtime work begins.
	 *
	 * The success marker is intentionally ephemeral: it is neither part of the
	 * canonical plan hash nor projected into materialization receipts. Individual
	 * writes still reread and verify their payload immediately before mutation.
	 *
	 * @param array<string,mixed> $prepared Prepared materialization state.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function admit_prepared( array $prepared ): array {
		return Static_Site_Importer_Site_Plan_Preparation::admit_prepared( $prepared );
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		return Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
	}

	/**
	 * External report output is a CLI-only operator seam. Every artifact must be
	 * a new file directly beneath one existing, physical directory.
	 */
	public static function safe_external_report_destination( $path ): bool {
		return Static_Site_Importer_Site_Plan_Preparation::safe_external_report_destination( $path );
	}

	/** @param array<string,string> $routes */
	private static function rewrite_route_references( string $content, array $routes ): string {
		return Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $content, $routes );
	}

	/** @param array<string,mixed> $write */
	private static function write_file( string $theme_dir, array $write, ?object $payload_reader = null, ?Closure $chunk_writer = null ) {
		return Static_Site_Importer_Site_Plan_Persistence::write_file( $theme_dir, $write, $payload_reader, $chunk_writer );
	}

	/** Derive expected overlay-composed stylesheets and frontend delivery bootstrap. */
	private static function provider_layout_stylesheet_writes( array $state, array $overlays ) {
		return Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $state, $overlays );
	}

	/** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>>|null */
	private static function parent_ordered_pages( array $pages, string $import_run_id = '' ): ?array {
		return Static_Site_Importer_Site_Plan_Preparation::parent_ordered_pages( $pages, $import_run_id );
	}

	/** Resolve a reference exactly once at the write boundary and verify declared raw bytes. */
	private static function write_payload_bytes( array $write, ?object $payload_reader ) {
		return Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $write, $payload_reader );
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

	/** Revert importer-owned runtime state, writes, and posts on a failed receipt. */
	private static function rollback( array &$state ): void {
		Static_Site_Importer_Site_Plan_Persistence::rollback( $state );
	}

	/**
	 * Admit producer-owned editability evidence without recreating its metrics or policy.
	 *
	 * Current Blocks Engine plans carry the required policy but not the report binding.
	 * That compatibility path is explicit and can be retired by setting
	 * quality.editability_report_required on plans from upgraded producers.
	 *
	 * @param array<string,mixed> $plan
	 * @return array<string,mixed>
	 */
	private static function editability_report_admission( array $plan ): array {
		return Static_Site_Importer_Site_Plan_Preparation::editability_report_admission( $plan );
	}

	/** @param array<string,mixed> $plan */
	private static function hash( array $plan ): string {
		return Static_Site_Importer_Site_Plan_Preparation::hash( $plan );
	}

	/** Hash the resolved projection only for prepare-to-write change detection. */
	public static function prepared_resolved_projection_hash( array $projection ): string {
		return Static_Site_Importer_Site_Plan_Preparation::prepared_resolved_projection_hash( $projection );
	}

}
