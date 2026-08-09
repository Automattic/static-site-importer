<?php
/**
 * Smoke test for durable final hydration effect receipts.
 *
 * @package StaticSiteImporter
 */

const ABSPATH = __DIR__ . '/';

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code, string $message = '', mixed $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function wp_mkdir_p( $path ): bool { return is_dir( $path ) || mkdir( $path, 0700, true ); }

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-final-hydration-effects.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$root = sys_get_temp_dir() . '/ssi-final-effects-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $root );
$workspace = new Static_Site_Importer_Artifact_Run_Workspace( $root, 'receipt-test' );
$store     = new Static_Site_Importer_Final_Hydration_Effects( $workspace );
$id        = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-a', 'batch-a', 'snapshot-a', 'plan-a' );
$assert( $id === Static_Site_Importer_Final_Hydration_Effects::identity( 'run-a', 'batch-a', 'snapshot-a', 'plan-a' ), 'identity must be deterministic' );
$started = $store->begin( $id, 'run-a', 'batch-a', 'snapshot-a', 'plan-a' );
$assert( is_array( $started ) && 'effect_started' === $started['state'], 'begin must persist effect_started state' );
$loaded = $store->load( $id );
$assert( is_array( $loaded ) && $id === $loaded['identity']['value'], 'receipt must reload with stable identity' );
$completed = $store->complete( $id, array( 'theme_slug' => 'receipt-test' ) );
$assert( is_array( $completed ) && 'verified' === $completed['state'], 'complete must persist verified state' );
$replayed = $store->begin( $id, 'run-a', 'batch-a', 'snapshot-a', 'plan-a' );
$assert( is_array( $replayed ) && 'verified' === $replayed['state'], 'verified receipt must be reusable' );

$unknown_id = hash( 'sha256', 'unknown' );
$workspace->publish_json( 'effects/' . $unknown_id . '.json', array( 'schema' => 'unknown/v9', 'version' => 9, 'receipt_id' => $unknown_id ) );
$assert( is_wp_error( $store->load( $unknown_id ) ) && 'static_site_importer_final_effect_receipt_unsupported' === $store->load( $unknown_id )->get_error_code(), 'unknown receipt schema must fail closed' );

$ambiguous_id = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-b', 'batch-b', 'snapshot-b', 'plan-b' );
$store->begin( $ambiguous_id, 'run-b', 'batch-b', 'snapshot-b', 'plan-b' );
$ambiguous = $store->begin( $ambiguous_id, 'run-b', 'batch-b', 'snapshot-b', 'plan-b' );
$assert( is_wp_error( $ambiguous ) && 'static_site_importer_final_effect_needs_recovery' === $ambiguous->get_error_code(), 'ambiguous effect must not replay blindly' );

echo "PASS smoke-final-hydration-effects.php\n";
