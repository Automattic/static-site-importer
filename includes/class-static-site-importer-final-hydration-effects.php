<?php
/**
 * Durable final hydration effect receipts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores final hydration state beside a resumable URL batch. */
final class Static_Site_Importer_Final_Hydration_Effects {
	public const SCHEMA  = 'static-site-importer/final-hydration-effect-receipt/v1';
	public const VERSION = 1;

	private Static_Site_Importer_Artifact_Run_Workspace $workspace;

	public function __construct( Static_Site_Importer_Artifact_Run_Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/** Build stable receipt identity from pre-effect inputs and adapter descriptor. */
	public static function identity( string $run_id, string $batch_id, string $snapshot_hash, string $plan_hash, array $adapter = array() ): string {
		return hash( 'sha256', (string) wp_json_encode( array(
			'run_id'          => $run_id,
			'batch_id'        => $batch_id,
			'snapshot_sha256' => $snapshot_hash,
			'plan_hash'       => $plan_hash,
			'adapter'         => $adapter,
		) ) );
	}

	/** Start receipt before final hydration mutation. */
	public function begin( string $receipt_id, string $run_id, string $batch_id, string $snapshot_hash, string $plan_hash, array $adapter = array() ) {
		$existing = $this->load( $receipt_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			if ( 'effect_started' === ( $existing['state'] ?? '' ) ) {
				$existing['state']                 = 'needs_manual_recovery';
				$existing['recovery']['retryable'] = false;
				$existing['diagnostics'][]         = 'adapter_reconciliation_required';
				$write                             = $this->save( $receipt_id, $existing );
				return is_wp_error( $write ) ? $write : new WP_Error( 'static_site_importer_final_effect_needs_recovery', 'Final hydration effect may have completed before the process stopped and needs adapter verification before retry.', $existing );
			}
			if ( 'needs_manual_recovery' === ( $existing['state'] ?? '' ) ) {
				return new WP_Error( 'static_site_importer_final_effect_needs_recovery', 'Final hydration effect needs adapter verification before retry.', $existing );
			}
			if ( 'failed' === ( $existing['state'] ?? '' ) ) {
				$existing['state']                 = 'effect_started';
				$existing['effect']                = array( 'started_at' => gmdate( 'c' ) );
				$existing['recovery']['retryable'] = false;
				$write                             = $this->save( $receipt_id, $existing );
				return is_wp_error( $write ) ? $write : $existing;
			}
			return 'verified' === ( $existing['state'] ?? '' ) ? $existing : new WP_Error( 'static_site_importer_final_effect_unsupported_state', 'Final hydration effect receipt state is unsupported.', $existing );
		}
		$receipt = array(
			'schema'          => self::SCHEMA,
			'version'         => self::VERSION,
			'receipt_id'      => $receipt_id,
			'run_id'          => $run_id,
			'batch_id'        => $batch_id,
			'snapshot_sha256' => $snapshot_hash,
			'plan_hash'       => $plan_hash,
			'adapter'         => $adapter,
			'identity'        => array(
				'algorithm' => 'sha256',
				'value'     => $receipt_id,
			),
			'state'           => 'effect_started',
			'effect'          => array( 'started_at' => gmdate( 'c' ) ),
			'recovery'        => array(
				'attempt'   => 0,
				'retryable' => false,
			),
			'diagnostics'     => array(),
		);
		return $this->save( $receipt_id, $receipt );
	}

	/** Record completed effect and importer result before batch checkpoint. */
	public function complete( string $receipt_id, array $result ) {
		$receipt = $this->load( $receipt_id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( ! is_array( $receipt ) || 'effect_started' !== ( $receipt['state'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_missing', 'Final hydration effect receipt was not started.' );
		}
		return $this->persist_result( $receipt_id, $receipt, $result );
	}

	/** Promote an adapter-reconciled ambiguous effect to verified before batch checkpoint. */
	public function recover( string $receipt_id, array $result ) {
		$receipt = $this->load( $receipt_id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( ! is_array( $receipt ) || 'needs_manual_recovery' !== ( $receipt['state'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_missing', 'Final hydration effect receipt was not awaiting manual recovery.' );
		}
		$receipt['recovery'] = array(
			'attempt'       => (int) ( $receipt['recovery']['attempt'] ?? 0 ) + 1,
			'retryable'     => false,
			'reconciled_at' => gmdate( 'c' ),
		);
		return $this->persist_result( $receipt_id, $receipt, $result );
	}

	/** Persist a verified effect result, wiping prior ambiguity. */
	private function persist_result( string $receipt_id, array $receipt, array $result ) {
		$receipt['state']                  = 'verified';
		$receipt['effect']['completed_at'] = gmdate( 'c' );
		$receipt['effect']['result']       = $result;
		$receipt['effect']['result_hash']  = self::result_hash( $result );
		return $this->save( $receipt_id, $receipt );
	}

	/** Mark failed importer call for normal retry. */
	public function fail( string $receipt_id, WP_Error $error ) {
		$receipt = $this->load( $receipt_id );
		if ( ! is_array( $receipt ) ) {
			return $receipt;
		}
		$receipt['state']                   = 'failed';
		$receipt['recovery']['retryable']   = true;
		$receipt['recovery']['reason_code'] = $error->get_error_code();
		return $this->save( $receipt_id, $receipt );
	}

	/** Compute canonical result integrity hash. */
	public static function result_hash( array $result ): string {
		return hash( 'sha256', (string) wp_json_encode( $result ) );
	}

	/** Load receipt and reject unknown, mismatched, or tampered contracts. */
	public function load( string $receipt_id ) {
		$raw = $this->workspace->read_raw( 'effects/' . $receipt_id . '.json' );
		if ( null === $raw ) {
			return null;
		}
		$data     = json_decode( $raw, true );
		$hash     = static fn ( string $value ): bool => 64 === strlen( $value ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $value );
		$identity = is_array( $data['identity'] ?? null ) ? $data['identity'] : array();
		$adapter  = is_array( $data['adapter'] ?? null ) ? $data['adapter'] : array();
		$valid    = is_array( $data ) && self::SCHEMA === ( $data['schema'] ?? '' ) && self::VERSION === (int) ( $data['version'] ?? 0 ) && ( $data['receipt_id'] ?? '' ) === $receipt_id && ! empty( $data['run_id'] ) && ! empty( $data['batch_id'] ) && $hash( (string) ( $data['snapshot_sha256'] ?? '' ) ) && $hash( (string) ( $data['plan_hash'] ?? '' ) ) && ( $identity['algorithm'] ?? '' ) === 'sha256' && ( $identity['value'] ?? '' ) === $receipt_id && self::identity( (string) $data['run_id'], (string) $data['batch_id'], (string) $data['snapshot_sha256'], (string) $data['plan_hash'], $adapter ) === $receipt_id;
		if ( ! $valid ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_unsupported', 'Final hydration effect receipt contract is unsupported.' );
		}
		$state = $data['state'] ?? '';
		if ( ! in_array( $state, array( 'effect_started', 'verified', 'failed', 'needs_manual_recovery', 'unsupported' ), true ) ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_unsupported', 'Final hydration effect receipt state is unsupported.' );
		}
		if ( 'verified' === $state && ( ! is_array( $data['effect']['result'] ?? null ) || ( $data['effect']['result_hash'] ?? '' ) !== self::result_hash( $data['effect']['result'] ) ) ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_unsupported', 'Verified final hydration effect result integrity check failed.' );
		}
		return $data;
	}

	/** Persist receipt atomically. */
	private function save( string $receipt_id, array $receipt ) {
		$write = $this->workspace->publish_json( 'effects/' . $receipt_id . '.json', $receipt );
		return is_wp_error( $write ) ? $write : $receipt;
	}
}
