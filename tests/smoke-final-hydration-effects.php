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
$snapshot_hash = hash( 'sha256', 'snapshot-a' );
$plan_hash     = hash( 'sha256', 'plan-a' );
$id            = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-a', 'batch-a', $snapshot_hash, $plan_hash );
$assert( $id === Static_Site_Importer_Final_Hydration_Effects::identity( 'run-a', 'batch-a', $snapshot_hash, $plan_hash ), 'identity must be deterministic' );
$started = $store->begin( $id, 'run-a', 'batch-a', $snapshot_hash, $plan_hash );
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

$ambiguous_id       = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-b', 'batch-b', hash( 'sha256', 'snapshot-b' ), hash( 'sha256', 'plan-b' ) );
$ambiguous_snapshot = hash( 'sha256', 'snapshot-b' );
$ambiguous_plan     = hash( 'sha256', 'plan-b' );
$store->begin( $ambiguous_id, 'run-b', 'batch-b', $ambiguous_snapshot, $ambiguous_plan );
$ambiguous = $store->begin( $ambiguous_id, 'run-b', 'batch-b', $ambiguous_snapshot, $ambiguous_plan );
$assert( is_wp_error( $ambiguous ) && 'static_site_importer_final_effect_needs_recovery' === $ambiguous->get_error_code(), 'ambiguous effect must not replay blindly' );
$recovery = $store->load( $ambiguous_id );
$assert( is_array( $recovery ) && 'needs_manual_recovery' === $recovery['state'], 'ambiguous effect must persist manual recovery state' );

$malformed_id = hash( 'sha256', 'malformed' );
$workspace->publish_json( 'effects/' . $malformed_id . '.json', array(
	'schema'          => Static_Site_Importer_Final_Hydration_Effects::SCHEMA,
	'version'         => Static_Site_Importer_Final_Hydration_Effects::VERSION,
	'receipt_id'      => $malformed_id,
	'run_id'          => 'run-c',
	'batch_id'        => 'batch-c',
	'snapshot_sha256' => 'not-a-hash',
	'plan_hash'       => 'not-a-hash',
	'identity'        => array( 'algorithm' => 'sha256', 'value' => $malformed_id ),
	'state'           => 'verified',
) );
$assert( is_wp_error( $store->load( $malformed_id ) ), 'malformed receipt hashes must fail closed' );

// Adapter descriptor is part of receipt identity: two adapters over the same run/batch/snapshot/plan produce distinct identities.
$adapter_a = array( 'id' => 'a', 'contract_version' => 1, 'implementation_version' => '1', 'capabilities' => array( 'verify_result' ) );
$adapter_b = array( 'id' => 'b', 'contract_version' => 1, 'implementation_version' => '1', 'capabilities' => array( 'verify_result' ) );
$id_a = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-d', 'batch-d', $snapshot_hash, $plan_hash, $adapter_a );
$id_b = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-d', 'batch-d', $snapshot_hash, $plan_hash, $adapter_b );
$assert( $id_a !== $id_b && 64 === strlen( $id_a ) && 64 === strlen( $id_b ), 'adapter descriptor must participate in receipt identity' );

// Verified reuse closes the durable checkpoint window: once verified, re-begin returns the verified result without requiring a fresh apply.
$reuse_snapshot = hash( 'sha256', 'reuse-snapshot' );
$reuse_plan     = hash( 'sha256', 'reuse-plan' );
$reuse_id       = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-reuse', 'batch-reuse', $reuse_snapshot, $reuse_plan, $adapter_a );
$store->begin( $reuse_id, 'run-reuse', 'batch-reuse', $reuse_snapshot, $reuse_plan, $adapter_a );
$store->complete( $reuse_id, array( 'theme_slug' => 'reused' ) );
$reuse_after = $store->begin( $reuse_id, 'run-reuse', 'batch-reuse', $reuse_snapshot, $reuse_plan, $adapter_a );
$assert( is_array( $reuse_after ) && 'verified' === $reuse_after['state'] && array( 'theme_slug' => 'reused' ) === $reuse_after['effect']['result'], 'verified receipt must be reusable without re-applying the effect' );

// Tampered verified result_hash fails closed: corrupt the persisted result and load must reject.
$tamper_snapshot = hash( 'sha256', 'tamper-snapshot' );
$tamper_plan     = hash( 'sha256', 'tamper-plan' );
$tamper_id       = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-tamper', 'batch-tamper', $tamper_snapshot, $tamper_plan, $adapter_a );
$store->begin( $tamper_id, 'run-tamper', 'batch-tamper', $tamper_snapshot, $tamper_plan, $adapter_a );
$store->complete( $tamper_id, array( 'theme_slug' => 'tampered' ) );
$tampered_raw = json_decode( (string) $workspace->read_raw( 'effects/' . $tamper_id . '.json' ), true );
$tampered_raw['effect']['result'] = array( 'theme_slug' => 'forged' ); // result no longer matches result_hash.
$workspace->publish_raw( 'effects/' . $tamper_id . '.json', (string) wp_json_encode( $tampered_raw ) );
$tampered_load = $store->load( $tamper_id );
$assert( is_wp_error( $tampered_load ) && 'static_site_importer_final_effect_receipt_unsupported' === $tampered_load->get_error_code(), 'tampered verified result must fail closed before reuse' );

// recover() promotes an adapter-reconciled ambiguous effect to verified.
$recover_snapshot = hash( 'sha256', 'recover-snapshot' );
$recover_plan     = hash( 'sha256', 'recover-plan' );
$recover_id       = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-recover', 'batch-recover', $recover_snapshot, $recover_plan, $adapter_a );
$store->begin( $recover_id, 'run-recover', 'batch-recover', $recover_snapshot, $recover_plan, $adapter_a );
$store->begin( $recover_id, 'run-recover', 'batch-recover', $recover_snapshot, $recover_plan, $adapter_a ); // Marks needs_manual_recovery.
$recover_wrong = $store->recover( $recover_id, array( 'theme_slug' => 'recover' ) );
$assert( is_array( $recover_wrong ) && 'verified' === $recover_wrong['state'] && array( 'theme_slug' => 'recover' ) === $recover_wrong['effect']['result'], 'recover must persist a reconciled result as verified' );
$recover_verified = $store->load( $recover_id );
$assert( is_array( $recover_verified ) && 'verified' === $recover_verified['state'] && 1 === ( $recover_verified['recovery']['attempt'] ?? 0 ), 'recovered receipt must persist the recovery attempt counter' );

// recover() fails closed when the receipt is not awaiting manual recovery.
$recover_locked_snapshot = hash( 'sha256', 'locked-snapshot' );
$recover_locked_plan     = hash( 'sha256', 'locked-plan' );
$recover_locked_id       = Static_Site_Importer_Final_Hydration_Effects::identity( 'run-locked', 'batch-locked', $recover_locked_snapshot, $recover_locked_plan, $adapter_a );
$store->begin( $recover_locked_id, 'run-locked', 'batch-locked', $recover_locked_snapshot, $recover_locked_plan, $adapter_a );
$recover_locked = $store->recover( $recover_locked_id, array( 'theme_slug' => 'locked' ) );
$assert( is_wp_error( $recover_locked ) && 'static_site_importer_final_effect_receipt_missing' === $recover_locked->get_error_code(), 'recover must reject receipts not awaiting manual recovery' );

// Exclusive claim primitive: first claim wins, second is rejected, and only the owning worker can release.
$claim_workspace_root = sys_get_temp_dir() . '/ssi-final-claim-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $claim_workspace_root );
$claim_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $claim_workspace_root, 'claim-test' );
$first_claim  = $claim_workspace->claim( 'effects/contended.claim', 'owner-one', array( 'receipt_id' => 'contended' ) );
$second_claim = $claim_workspace->claim( 'effects/contended.claim', 'owner-two', array( 'receipt_id' => 'contended' ) );
$assert( is_array( $first_claim ) && 'owner-one' === $first_claim['owner'] && is_wp_error( $second_claim ) && 'static_site_importer_artifact_claim_owned' === $second_claim->get_error_code(), 'first claimant must win and second must be rejected' );
$wrong_release = $claim_workspace->release_claim( 'effects/contended.claim', 'owner-two' );
$assert( false === $wrong_release, 'a non-owning worker must not release another worker claim' );
$right_release = $claim_workspace->release_claim( 'effects/contended.claim', 'owner-one' );
$assert( true === $right_release, 'the owning worker must release its own claim' );
$reclaimed = $claim_workspace->claim( 'effects/contended.claim', 'owner-three', array() );
$assert( is_array( $reclaimed ) && 'owner-three' === $reclaimed['owner'], 'a released claim must be available to a new owner' );
$claim_workspace->purge();

echo "PASS smoke-final-hydration-effects.php\n";
