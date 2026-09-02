<?php
/**
 * Default durable final hydration adapter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Built-in adapter for the standard theme generator importer. */
final class Static_Site_Importer_Default_Final_Hydration_Adapter implements Static_Site_Importer_Final_Hydration_Adapter {
	public function id(): string {
		return 'static-site-importer/theme-generator';
	}

	public function contract_version(): int {
		return 1;
	}

	public function implementation_version(): string {
		return defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? STATIC_SITE_IMPORTER_VERSION : '1';
	}

	public function capabilities(): array {
		return array( 'verify_result', 'reconcile_verified_result' );
	}

	public function apply( array $artifact, array $import_args ) {
		return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
	}

	public function reconcile( array $receipt, array $artifact, array $import_args ) {
		$result = $receipt['effect']['result'] ?? null;
		return is_array( $result ) && $this->verify( $result, $artifact, $import_args ) ? $result : new WP_Error( 'static_site_importer_final_effect_reconciliation_unavailable', 'Final hydration effect cannot be reconciled without a verified result.' );
	}

	public function verify( array $result, array $artifact, array $import_args ): bool {
		return isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] );
	}
}
