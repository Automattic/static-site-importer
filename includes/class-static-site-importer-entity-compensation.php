<?php
/**
 * Owns provider rollback compensation receipts and their replay bindings.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_Entity_Compensation {
	/** Compensate completed entities in reverse order and retain bounded residual evidence. */
	private static function rollback_materialized_entities( array $lifecycle, array $reports ): array {
		$compensation = array(
			'schema'    => 'static-site-importer/entity-compensation-receipt/v1',
			'status'    => 'rolled_back',
			'entities'  => array(),
			'errors'    => array(),
			'truncated' => false,
		);
		foreach ( array_reverse( array_keys( $lifecycle['entities'] ?? array() ) ) as $id ) {
			$prepared = $lifecycle['entities'][ $id ];
			if ( ! is_array( $prepared ) || ! is_array( $prepared['adapter'] ?? null ) || ! is_array( $reports[ $id ] ?? null ) ) {
				continue; }
			if ( ! self::entity_report_requires_rollback( $reports[ $id ] ) ) {
				continue; }
			$adapter = $prepared['adapter'];
			try {
				$result = Static_Site_Importer_Entity_Materializer_Registry::rollback( $adapter, $reports[ $id ] );
			} catch ( Throwable $error ) {
				$result = new WP_Error( 'static_site_importer_entity_rollback_exception', $error->getMessage() );
			}
			$entry = array(
				'entity_id' => (string) $id,
				'adapter'   => (string) ( $adapter['provider'] ?? '' ),
			);
			if ( is_wp_error( $result ) ) {
				$entry['status'] = 'failed';
				$entry['errors'] = array(
					array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				);
			} elseif ( is_array( $result ) ) {
				$entry['status']         = (string) ( $result['status'] ?? 'failed' );
				$entry['rollback']       = self::bounded_entity_rollback_result( $result );
				$entry['residual_state'] = self::entity_rollback_residual_state( $entry['rollback'] );
			} else {
				$entry['status'] = 'failed';
				$entry['errors'] = array(
					array(
						'code'    => 'static_site_importer_entity_rollback_invalid',
						'message' => 'The entity rollback callback did not return a receipt.',
					),
				);
			}
			if ( ! in_array( $entry['status'], array( 'rolled_back', 'skipped', 'not_requested' ), true ) ) {
				$compensation['status']   = 'partial';
				$compensation['errors'][] = array(
					'entity_id' => $entry['entity_id'],
					'adapter'   => $entry['adapter'],
					'status'    => $entry['status'],
				);
			}
			if ( count( $compensation['entities'] ) < 32 ) {
				$compensation['entities'][] = $entry;
			} else {
				$compensation['truncated'] = true; }
		}
		$compensation['errors'] = array_slice( $compensation['errors'], 0, 32 );
		return $compensation;
	}

	/** Only callbacks with an explicit mutation receipt may perform destructive compensation. */
	private static function entity_report_requires_rollback( array $report ): bool {
		if ( ! in_array( $report['status'] ?? null, array( 'completed', 'materialized', 'mapped', 'mutated' ), true ) ) {
			return false;
		}
		foreach ( array( 'products', 'forms', 'entities', 'mutations' ) as $key ) {
			foreach ( $report[ $key ] ?? array() as $row ) {
				if ( is_array( $row ) && in_array( $row['status'] ?? null, array( 'created', 'updated', 'mapped', 'materialized', 'mutated' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Attach failure context and compensation diagnostics to public and internal receipts. */
	public static function append( array &$result, array $lifecycle, array $reports, string $stage, string $code ): void {
		$existing_compensation = isset( $result['entity_compensation'] ) && is_array( $result['entity_compensation'] ) ? $result['entity_compensation'] : array();
		if ( self::compensation_binding_matches( $existing_compensation, $result, $lifecycle, $reports ) ) {
			if ( isset( $result['completed']['runtime_declarations'] ) && is_array( $result['completed']['runtime_declarations'] ) ) {
				$result['completed']['runtime_declarations']['entity_compensation'] = $result['entity_compensation'];
			}
			return;
		}
		$compensation            = self::rollback_materialized_entities( $lifecycle, $reports );
		$compensation['binding'] = self::compensation_binding( $result, $lifecycle, $reports );
		if ( ! empty( $existing_compensation ) || ! empty( $result['previous_entity_compensation_binding_mismatch'] ) ) {
			$compensation['superseded_binding_mismatch'] = true;
		}
		unset( $result['previous_entity_compensation_binding_mismatch'] );
		$result['failure_context']     = array(
			'stage' => $stage,
			'code'  => $code,
		);
		$result['entity_compensation'] = $compensation;
		$result['diagnostics']         = is_array( $result['diagnostics'] ?? null ) ? $result['diagnostics'] : array();
		$result['diagnostics'][]       = array(
			'reason_code' => $code,
			'stage'       => $stage,
		);
		foreach ( $compensation['entities'] as $entry ) {
			if ( 'rolled_back' !== ( $entry['status'] ?? '' ) ) {
				$result['diagnostics'][] = array(
					'reason_code' => 'entity_compensation_' . (string) $entry['status'],
					'stage'       => $stage,
					'entity_id'   => $entry['entity_id'],
					'adapter'     => $entry['adapter'],
				);
			}
		}
		if ( isset( $result['completed']['runtime_declarations'] ) && is_array( $result['completed']['runtime_declarations'] ) ) {
			$result['completed']['runtime_declarations']['entity_compensation'] = $compensation;
		}
		if ( 'partial' === $compensation['status'] && 'completed' === ( $result['status'] ?? null ) ) {
			$result['status'] = 'partial'; }
	}

	/** Bind idempotent compensation to the exact receipt, lifecycle declarations, and provider reports. */
	private static function compensation_binding( array $result, array $lifecycle, array $reports ): array {
		$transaction_state        = isset( $result['transaction'] ) && is_object( $result['transaction'] ) && is_array( $result['transaction']->state ?? null ) ? $result['transaction']->state : array();
		$transaction              = array(
			'plan_identity'                     => $result['plan_identity'] ?? array(),
			'theme'                             => $result['theme'] ?? array(),
			'import_run_id'                     => $transaction_state['args']['import_run_id'] ?? '',
			'prepared_resolved_projection_hash' => $transaction_state['prepared_resolved_projection_hash'] ?? '',
			'applied'                           => $transaction_state['applied'] ?? array(),
			'page_ids'                          => $transaction_state['page_ids'] ?? array(),
			'source_ids'                        => $transaction_state['source_ids'] ?? array(),
		);
		$lifecycle_entities       = array();
		$rollback_contracts_valid = true;
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			if ( ! is_array( $prepared ) ) {
				continue;
			}
			$adapter              = is_array( $prepared['adapter'] ?? null ) ? $prepared['adapter'] : array();
			$rollback_contract_id = Static_Site_Importer_Entity_Materializer_Registry::rollback_contract_id( $adapter );
			if ( '' === $rollback_contract_id ) {
				$rollback_contracts_valid = false;
			}
			$lifecycle_entities[ (string) $id ] = array(
				'adapter'              => array_intersect_key( $adapter, array_flip( array( 'id', 'capability', 'provider', 'waiver_arg' ) ) ),
				'declaration'          => is_array( $prepared['declaration'] ?? null ) ? $prepared['declaration'] : array(),
				'manifest'             => is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array(),
				'required'             => true === ( $prepared['required'] ?? false ),
				'rollback_contract_id' => $rollback_contract_id,
			);
		}
		ksort( $lifecycle_entities, SORT_STRING );
		$receipt = array(
			'schema'                    => $result['schema'] ?? '',
			'receipt_instance_id'       => $result['receipt_instance_id'] ?? '',
			'plan_identity'             => $result['plan_identity'] ?? array(),
			'theme'                     => $result['theme'] ?? array(),
			'reconciliation_identities' => $result['reconciliation_identities'] ?? array(),
			'materialized_pages'        => $result['completed']['materialized_pages'] ?? array(),
			'transaction_identity'      => self::compensation_hash( $transaction ),
		);
		return array(
			'schema'                  => 'static-site-importer/entity-compensation-binding/v1',
			'receipt_identity'        => self::compensation_hash( $receipt ),
			'plan_identity'           => $result['plan_identity'] ?? array(),
			'receipt_instance_id'     => $receipt['receipt_instance_id'],
			'transaction_identity'    => $receipt['transaction_identity'],
			'lifecycle_entities_hash' => self::compensation_hash( $lifecycle_entities ),
			'rollback_contracts_hash' => $rollback_contracts_valid ? self::compensation_hash( array_column( $lifecycle_entities, 'rollback_contract_id' ) ) : '',
			'provider_reports_hash'   => self::compensation_hash( $reports ),
		);
	}

	/** Only an exact, complete binding may suppress another provider rollback. */
	private static function compensation_binding_matches( array $compensation, array $result, array $lifecycle, array $reports ): bool {
		$binding = isset( $compensation['binding'] ) && is_array( $compensation['binding'] ) ? $compensation['binding'] : array();
		return 'static-site-importer/entity-compensation-receipt/v1' === ( $compensation['schema'] ?? null )
			&& 'static-site-importer/entity-compensation-binding/v1' === ( $binding['schema'] ?? null )
			&& '' !== ( $binding['receipt_identity'] ?? '' )
			&& self::valid_compensation_receipt_instance_id( $binding['receipt_instance_id'] ?? null )
			&& '' !== ( $binding['transaction_identity'] ?? '' )
			&& '' !== ( $binding['lifecycle_entities_hash'] ?? '' )
			&& '' !== ( $binding['rollback_contracts_hash'] ?? '' )
			&& '' !== ( $binding['provider_reports_hash'] ?? '' )
			&& self::compensation_binding( $result, $lifecycle, $reports ) === $binding;
	}

	/** Reject compensation reuse unless it carries a canonical server receipt identity. */
	private static function valid_compensation_receipt_instance_id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $id );
	}

	/** Hash recursively sorted scalar data so equivalent receipts produce the same binding. */
	private static function compensation_hash( array $value ): string {
		$valid     = true;
		$normalize = static function ( array $input ) use ( &$normalize, &$valid ): array {
			if ( array_is_list( $input ) ) {
				return array_map(
					static function ( $item ) use ( &$normalize, &$valid ) {
						if ( is_array( $item ) ) {
							return $normalize( $item );
						}
						if ( ! is_scalar( $item ) && null !== $item ) {
							$valid = false;
						}
						return $item;
					},
					$input
				);
			}
			ksort( $input, SORT_STRING );
			foreach ( $input as $key => $item ) {
				if ( is_array( $item ) ) {
					$input[ $key ] = $normalize( $item );
					continue;
				}
				if ( ! is_scalar( $item ) && null !== $item ) {
					$valid = false;
				}
			}
			return $input;
		};
		$json      = wp_json_encode( $normalize( $value ) );
		return $valid && is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	/** Keep provider rollback diagnostics useful without exposing unbounded provider receipts. */
	private static function bounded_entity_rollback_result( array $result ): array {
		$bounded = array();
		foreach ( array( 'status', 'reason', 'product_cleanup_failures', 'term_cleanup_failures', 'form_cleanup_failures' ) as $key ) {
			if ( ! array_key_exists( $key, $result ) ) {
				continue; }
			$bounded[ $key ] = is_array( $result[ $key ] ) ? array_slice( $result[ $key ], 0, 32 ) : $result[ $key ];
		}
		return $bounded;
	}

	/** Name provider objects that could remain after a partial compensation. */
	private static function entity_rollback_residual_state( array $rollback ): array {
		$residual = array();
		if ( ! empty( $rollback['product_cleanup_failures'] ) ) {
			$residual['products'] = $rollback['product_cleanup_failures']; }
		if ( ! empty( $rollback['term_cleanup_failures'] ) ) {
			$residual['terms'] = $rollback['term_cleanup_failures']; }
		if ( ! empty( $rollback['form_cleanup_failures'] ) ) {
			$residual['forms'] = $rollback['form_cleanup_failures']; }
		return $residual;
	}
}
