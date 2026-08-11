<?php
/**
 * Durable final hydration effect receipts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores final hydration state beside a resumable URL batch.
 */
final class Static_Site_Importer_Final_Hydration_Effects {
	public const SCHEMA  = 'static-site-importer/final-hydration-effect-receipt/v1';
	public const VERSION = 1;

	private Static_Site_Importer_Artifact_Run_Workspace $workspace;

	public function __construct( Static_Site_Importer_Artifact_Run_Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * Build stable receipt identity from pre-effect inputs.
	 *
	 * @param string $run_id Run identity.
	 * @param string $batch_id Batch identity.
	 * @param string $snapshot_hash Source snapshot hash.
	 * @param string $plan_hash Compiled plan hash.
	 * @return string
	 */
	public static function identity( string $run_id, string $batch_id, string $snapshot_hash, string $plan_hash ): string {
		return hash( 'sha256', wp_json_encode( array( 'run_id' => $run_id, 'batch_id' => $batch_id, 'snapshot_sha256' => $snapshot_hash, 'plan_hash' => $plan_hash ) ) );
	}

	/**
	 * Start receipt before final hydration mutation.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function begin( string $receipt_id, string $run_id, string $batch_id, string $snapshot_hash, string $plan_hash ) {
		$existing = $this->load( $receipt_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$state = $existing['state'] ?? '';
			if ( 'effect_started' === $state ) {
				$existing['state']                 = 'needs_manual_recovery';
				$existing['recovery']['retryable'] = false;
				$recovery                         = $this->save( $receipt_id, $existing );
				if ( is_wp_error( $recovery ) ) {
					return $recovery;
				}
				return new WP_Error(
					'static_site_importer_final_effect_needs_recovery',
					'Final hydration effect may have completed before the process stopped and needs adapter verification before retry.',
					$existing
				);
			}
			if ( in_array( $state, array( 'verified', 'failed' ), true ) ) {
				return $existing;
			}
			return new WP_Error( 'static_site_importer_final_effect_unsupported_state', 'Final hydration effect receipt state is unsupported.', $existing );
		}

		$receipt = array(
			'schema'          => self::SCHEMA,
			'version'         => self::VERSION,
			'receipt_id'      => $receipt_id,
			'run_id'          => $run_id,
			'batch_id'        => $batch_id,
			'snapshot_sha256' => $snapshot_hash,
			'plan_hash'       => $plan_hash,
			'adapter'         => array( 'id' => 'url-importer', 'contract_version' => 1 ),
			'identity'        => array( 'algorithm' => 'sha256', 'value' => $receipt_id ),
			'state'           => 'effect_started',
			'effect'          => array( 'started_at' => gmdate( 'c' ) ),
			'recovery'        => array( 'attempt' => 0, 'retryable' => false ),
			'diagnostics'     => array(),
		);
		return $this->save( $receipt_id, $receipt );
	}

	/**
	 * Record completed effect and importer result before batch manifest checkpoint.
	 *
	 * @param array<string,mixed> $result Importer result.
	 * @return array<string,mixed>|WP_Error
	 */
	public function complete( string $receipt_id, array $result ) {
		$receipt = $this->load( $receipt_id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( ! is_array( $receipt ) ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_missing', 'Final hydration effect receipt was not started.' );
		}
		$receipt['state']                  = 'verified';
		$receipt['effect']['completed_at'] = gmdate( 'c' );
		$receipt['effect']['result']       = $result;
		$receipt['effect']['result_hash']  = hash( 'sha256', (string) wp_json_encode( $result ) );
		return $this->save( $receipt_id, $receipt );
	}

	/** Mark a failed importer call for normal retry. */
	public function fail( string $receipt_id, WP_Error $error ) {
		$receipt = $this->load( $receipt_id );
		if ( ! is_array( $receipt ) ) {
			return $receipt;
		}
		$receipt['state']                    = 'failed';
		$receipt['recovery']['retryable']   = true;
		$receipt['recovery']['reason_code'] = $error->get_error_code();
		return $this->save( $receipt_id, $receipt );
	}

	/**
	 * Load receipt and reject unknown or mismatched contracts.
	 *
	 * @return array<string,mixed>|null|WP_Error
	 */
	public function load( string $receipt_id ) {
		$relative = 'effects/' . $receipt_id . '.json';
		$raw      = $this->workspace->read_raw( $relative );
		if ( null === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		$hash = static function ( string $value ): bool {
			return 64 === strlen( $value ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $value );
		};
		$identity = is_array( $data['identity'] ?? null ) ? $data['identity'] : array();
		$valid    = is_array( $data )
			&& self::SCHEMA === ( $data['schema'] ?? '' )
			&& self::VERSION === (int) ( $data['version'] ?? 0 )
			&& ( $data['receipt_id'] ?? '' ) === $receipt_id
			&& ! empty( $data['run_id'] )
			&& ! empty( $data['batch_id'] )
			&& $hash( (string) ( $data['snapshot_sha256'] ?? '' ) )
			&& $hash( (string) ( $data['plan_hash'] ?? '' ) )
			&& ( $identity['algorithm'] ?? '' ) === 'sha256'
			&& ( $identity['value'] ?? '' ) === $receipt_id
			&& self::identity( (string) $data['run_id'], (string) $data['batch_id'], (string) $data['snapshot_sha256'], (string) $data['plan_hash'] ) === $receipt_id;
		if ( ! $valid ) {
			return new WP_Error( 'static_site_importer_final_effect_receipt_unsupported', 'Final hydration effect receipt contract is unsupported.' );
		}
		return $data;
	}

	/** Persist receipt atomically. */
	private function save( string $receipt_id, array $receipt ) {
		$write = $this->workspace->publish_json( 'effects/' . $receipt_id . '.json', $receipt );
		return is_wp_error( $write ) ? $write : $receipt;
	}
}
