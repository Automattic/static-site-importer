<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Static_Site_Importer_Callable_Final_Hydration_Adapter implements Static_Site_Importer_Final_Hydration_Adapter {
	public function __construct( private $callback ) {}
	public function id(): string { return 'static-site-importer/callable'; }
	public function contract_version(): int { return 1; }
	public function implementation_version(): string { return '1'; }
	public function capabilities(): array { return array( 'effect_started' ); }
	public function apply( array $artifact, array $args ) { return call_user_func( $this->callback, $artifact, $args ); }
	public function reconcile( array $receipt, array $artifact, array $args ) { return new WP_Error( 'static_site_importer_final_effect_reconciliation_unavailable', 'A plain importer callable cannot prove the outcome of an interrupted effect; manual recovery required.' ); }
	public function verify( array $result, array $artifact, array $args ): bool { return true; }
}
