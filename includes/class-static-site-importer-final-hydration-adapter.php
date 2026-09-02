<?php
/**
 * Durable final hydration adapter contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter contract for effects that may outlive one PHP request.
 */
interface Static_Site_Importer_Final_Hydration_Adapter {
	/** Stable adapter identity. */
	public function id(): string;

	/** Durable adapter contract version. */
	public function contract_version(): int;

	/** Adapter implementation identity. */
	public function implementation_version(): string;

	/** Capabilities required for receipt recovery. */
	public function capabilities(): array;

	/** Apply final hydration effect. */
	public function apply( array $artifact, array $import_args );

	/** Reconcile an effect that may have completed before interruption. */
	public function reconcile( array $receipt, array $artifact, array $import_args );

	/** Verify result before receipt reuse. */
	public function verify( array $result, array $artifact, array $import_args ): bool;
}
