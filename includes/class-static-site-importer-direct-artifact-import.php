<?php
/**
 * Durable, bounded compilation for direct multi-page artifacts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Artifact_Run_Workspace' ) ) {
	require_once __DIR__ . '/class-static-site-importer-artifact-run.php';
}
if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Client_Script_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-client-script-policy.php';
}

final class Static_Site_Importer_Direct_Artifact_Import {
	private const RUN_SCHEMA        = 'static-site-importer/direct-artifact-run/v1';
	private const CHECKPOINT_SCHEMA = 'static-site-importer/direct-artifact-checkpoint/v1';
	private const EVIDENCE_SCHEMA   = 'static-site-importer/direct-artifact-run-evidence/v1';
	private const RECEIPT_SCHEMA    = 'blocks-engine/php-transformer/compiled-page-receipt/v2';
	private const TTL               = 604800;
	private const CLEANUP_HOOK      = 'static_site_importer_purge_direct_artifact_imports';

	/** Start a server-owned run after normal canonical source normalization. */
	public static function start( array $artifact, array $args, string $source_type, string $operation, array $provenance ) {
		$freeze_started  = microtime( true );
		$source_identity = self::hash_json( $artifact, false );
		$source_policy   = Static_Site_Importer_Content_Policy::validate_artifact( $artifact );
		if ( is_wp_error( $source_policy ) ) {
			return $source_policy;
		}

		$policy                                = Static_Site_Importer_Client_Script_Policy::apply( $artifact, $args );
		$artifact                              = $policy['artifact'];
		$args['client_script_policy_report']   = $policy['report'];
		$args['source_metadata']['collection'] = is_array( $args['source_metadata']['collection'] ?? null ) ? $args['source_metadata']['collection'] : array();
		$args['source_metadata']['collection']['script_policy'] = $policy['report'];
		$identity  = self::hash( $artifact );
		$import_id = bin2hex( random_bytes( 32 ) );
		$workspace = self::workspace( $import_id, true );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$run          = array(
			'import_id'  => $import_id,
			'binding'    => array(
				'source_type'       => $source_type,
				'operation'         => $operation,
				'args'              => self::binding_args( $args ),
				'owner'             => self::owner(),
				'source_identity'   => $source_identity,
				'artifact_identity' => $identity,
				'implementation'    => self::implementation_binding(),
			),
			'provenance' => $provenance,
			'state'      => 'running',
			'phase'      => 'freezing',
			'refs'       => array(),
			'page_ids'   => array(),
			'failures'   => array(),
			'timings'    => array( 'freeze_seconds' => microtime( true ) - $freeze_started ),
			'work'       => array(
				'content_policy_applications'       => 1,
				'client_script_policy_applications' => 1,
				'shared_prepares'                   => 0,
				'page_prepare_passes'               => 0,
				'page_plans_prepared'               => 0,
				'compile_batches'                   => 0,
				'pages_compiled'                    => 0,
				'page_compile_counts'               => array(),
				'compositions'                      => 0,
				'materialization_claims'            => 0,
				'materialization_attempts'          => 0,
				'materializations'                  => 0,
			),
			'progress'   => array(
				'phase'         => 'freezing',
				'page_count'    => 0,
				'receipt_count' => 0,
				'updated_at'    => gmdate( 'c' ),
			),
		);
		$artifact_ref = self::publish_checkpoint(
			$workspace,
			$run,
			'artifact',
			'artifact.json',
			array(
				'artifact'      => $artifact,
				'args'          => $args,
				'policy_report' => $policy['report'],
			)
		);
		if ( is_wp_error( $artifact_ref ) ) {
			$workspace->purge();
			return $artifact_ref;
		}
		$run['refs']['artifact']  = $artifact_ref;
		$run['phase']             = 'preparing_shared';
		$run['progress']['phase'] = 'preparing_shared';
		$write                    = self::write_run( $workspace, $run );
		if ( is_wp_error( $write ) ) {
			return $write;
		}
		$artifact_bytes = 0;
		if ( self::exceeds_string_bytes( $artifact, self::run_policy()['freeze_continuation_bytes'], $artifact_bytes ) ) {
			return self::continuation( $run, 'artifact_frozen' );
		}

		return self::execute( $workspace, $run );
	}

	/** Resume an opaque run without reacquiring source bytes. */
	public static function resume( string $import_id, array $args, string $source_type, string $operation, array $source ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $import_id ) ) {
			return new WP_Error( 'static_site_importer_invalid_direct_artifact_import_id', 'The direct artifact import_id is invalid.' );
		}
		foreach ( array( 'html', 'files', 'entrypoint', 'ref', 'zip', 'metadata' ) as $field ) {
			if ( array_key_exists( $field, $source ) ) {
				return new WP_Error( 'static_site_importer_direct_artifact_source_mismatch', 'A retained direct artifact run must be resumed without replacement source data.' );
			}
		}
		$workspace = self::workspace( $import_id, false );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		$run = self::read_run( $workspace, $import_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$requested = array(
			'source_type' => $source_type,
			'operation'   => $operation,
			'args'        => self::binding_args( $args ),
			'owner'       => self::owner(),
		);
		foreach ( $requested as $key => $value ) {
			if ( self::canonical( $value ) !== self::canonical( $run['binding'][ $key ] ?? null ) ) {
				return new WP_Error( 'static_site_importer_direct_artifact_run_mismatch', 'The direct artifact import_id does not match this source type, operation, import options, or owner.' );
			}
		}
		if ( self::implementation_binding() !== ( $run['binding']['implementation'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_implementation_changed', 'The retained direct artifact run was created by a different compiler or policy implementation.' );
		}
		$validated = self::validate_retained_refs( $workspace, $run );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( 'completed' === ( $run['state'] ?? '' ) ) {
			$final = self::read_checkpoint( $workspace, $run, $run['refs']['final'] ?? array(), 'final' );
			return is_wp_error( $final ) ? $final : $final['response'];
		}

		return self::execute( $workspace, $run );
	}

	/** Continue the phase machine until its server-owned work boundary. */
	private static function execute( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run ) {
		$lock = $workspace->acquire_lock( 'execution.lock' );
		if ( is_wp_error( $lock ) ) {
			return self::continuation( $run, 'run_in_progress' );
		}
		try {
			return self::execute_locked( $workspace, $run );
		} finally {
			$workspace->release_lock( $lock );
		}
	}

	private static function execute_locked( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run ) {
		if ( $workspace->is_expired() ) {
			$workspace->purge();
			return new WP_Error( 'static_site_importer_direct_artifact_run_expired', 'The retained direct artifact run expired and must be restarted.' );
		}
		$policy       = self::run_policy();
		$clock        = $policy['clock'];
		$run['state'] = 'running';
		$write        = self::write_run( $workspace, $run );
		if ( is_wp_error( $write ) ) {
			return self::fail( $workspace, $run, 'run_checkpoint', $write );
		}
		$final_ref = self::existing_checkpoint_ref( $workspace, $run, 'final-response.json', 'final' );
		if ( is_wp_error( $final_ref ) ) {
			return $final_ref;
		}
		if ( ! empty( $final_ref ) ) {
			$final = self::read_checkpoint( $workspace, $run, $final_ref, 'final' );
			if ( is_wp_error( $final ) ) {
				return $final;
			}
			$run['refs']['final']          = $final_ref;
			$run['state']                  = 'completed';
			$run['phase']                  = 'completed';
			$run['progress']['phase']      = 'completed';
			$run['progress']['updated_at'] = gmdate( 'c' );
			$write                         = self::write_run( $workspace, $run );
			return is_wp_error( $write ) ? self::fail( $workspace, $run, 'terminal_checkpoint', $write ) : $final['response'];
		}

		try {
			$compiler = self::compiler();
			if ( is_wp_error( $compiler ) ) {
				return self::fail( $workspace, $run, 'compiler', $compiler );
			}
			$prepare_shared = array( $compiler, 'prepareShared' );
			$prepare_page   = array( $compiler, 'preparePage' );
			$compile_pages  = array( $compiler, 'compilePreparedPages' );
			$compose        = array( $compiler, 'compose' );
			if ( ! is_callable( $prepare_shared ) || ! is_callable( $prepare_page ) || ! is_callable( $compile_pages ) || ! is_callable( $compose ) ) {
				return self::fail( $workspace, $run, 'compiler', new WP_Error( 'static_site_importer_invalid_transformer', 'The direct artifact compiler does not implement the staged compilation contract.' ) );
			}
			$artifact_state = self::read_checkpoint( $workspace, $run, $run['refs']['artifact'], 'artifact' );
			if ( is_wp_error( $artifact_state ) ) {
				return $artifact_state;
			}
			$artifact = $artifact_state['artifact'];
			$args     = $artifact_state['args'];

			if ( empty( $run['refs']['shared'] ) ) {
				$shared_ref = self::existing_checkpoint_ref( $workspace, $run, 'shared-plan.json', 'shared' );
				if ( is_wp_error( $shared_ref ) ) {
					return $shared_ref;
				}
				if ( ! empty( $shared_ref ) ) {
					$shared_state = self::read_checkpoint( $workspace, $run, $shared_ref, 'shared' );
					if ( is_wp_error( $shared_state ) ) {
						return $shared_state;
					}
					$run['refs']['shared'] = $shared_ref;
					$run['page_ids']       = array_values( $shared_state['plan']['analysis']['page_ids'] );
					sort( $run['page_ids'], SORT_STRING );
					$run['work']['shared_prepares'] = 1;
				}
			}
			if ( empty( $run['refs']['shared'] ) ) {
				$started = microtime( true );
				$entered = self::enter_phase( $workspace, $run, 'prepare_shared' );
				if ( is_wp_error( $entered ) ) {
					return $entered;
				}
				$run = $entered;
				self::before_phase( 'prepare_shared', $run );
				$shared = call_user_func( $prepare_shared, $artifact );
				if ( 'blocks-engine/php-transformer/staged-shared-plan/v1' !== ( $shared['schema'] ?? '' ) || empty( $shared['digest'] ) || ! is_array( $shared['analysis']['page_ids'] ?? null ) ) {
					throw new RuntimeException( 'Blocks Engine returned an invalid shared plan.' );
				}
				$ref = self::publish_checkpoint( $workspace, $run, 'shared', 'shared-plan.json', array( 'plan' => $shared ) );
				if ( is_wp_error( $ref ) ) {
					return self::fail( $workspace, $run, 'prepare_shared', $ref );
				}
				$run['refs']['shared'] = $ref;
				$run['page_ids']       = array_values( $shared['analysis']['page_ids'] );
				sort( $run['page_ids'], SORT_STRING );
				$run['work']['shared_prepares']           = 1;
				$run['timings']['prepare_shared_seconds'] = microtime( true ) - $started;
				$run['phase']                             = 'preparing_pages';
				$run['progress']['phase']                 = 'preparing_pages';
				$run['progress']['page_count']            = count( $run['page_ids'] );
				$run['progress']['updated_at']            = gmdate( 'c' );
				$write                                    = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'prepare_shared_checkpoint', $write );
				}
			}

			$shared_state = self::read_checkpoint( $workspace, $run, $run['refs']['shared'], 'shared' );
			if ( is_wp_error( $shared_state ) ) {
				return $shared_state;
			}
			$shared  = $shared_state['plan'];
			$adopted = self::adopt_page_plans( $workspace, $run, $shared );
			if ( is_wp_error( $adopted ) ) {
				return $adopted;
			}
			if ( ( $run['refs']['pages'] ?? array() ) !== $adopted ) {
				$run['refs']['pages']                   = $adopted;
				$run['work']['page_plans_prepared']     = count( $adopted );
				$run['progress']['prepared_page_count'] = count( $adopted );
				$run['progress']['updated_at']          = gmdate( 'c' );
				$write                                  = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'prepare_pages_checkpoint', $write );
				}
			}
			// Retained-state validation is prerequisite work; reserve the full
			// invocation budget for a new bounded prepare/compile unit.
			$deadline = call_user_func( $clock ) + $policy['max_invocation_seconds'];

			$pending_plans = array_values( array_diff( $run['page_ids'], array_keys( $run['refs']['pages'] ?? array() ) ) );
			$prepare_ids   = self::deadline_reached( $deadline, $clock ) ? array() : array_slice( $pending_plans, 0, $policy['prepare_batch_pages'] );
			if ( ! empty( $prepare_ids ) ) {
				if ( self::deadline_reached( $deadline, $clock ) ) {
					return self::continuation( $run, 'deadline_exhausted' );
				}
				$started = microtime( true );
				$entered = self::enter_phase( $workspace, $run, 'prepare_pages', $prepare_ids );
				if ( is_wp_error( $entered ) ) {
					return $entered;
				}
				$run = $entered;
				self::before_phase( 'prepare_pages', $run, $prepare_ids );
				$run['work']['page_prepare_passes'] = (int) $run['work']['page_prepare_passes'] + 1;
				foreach ( $prepare_ids as $page_id ) {
					$page_plan = call_user_func( $prepare_page, $artifact, $shared, $page_id );
					$validated = self::validate_page_plan( $page_plan, $shared, $page_id );
					if ( is_wp_error( $validated ) ) {
						return self::fail( $workspace, $run, 'prepare_pages', $validated, array( $page_id ) );
					}
					$relative = self::page_file( 'page-plans', $page_id );
					$ref      = self::publish_checkpoint( $workspace, $run, 'page_plan', $relative, array(
						'page_id' => $page_id,
						'plan'    => $page_plan,
					) );
					if ( is_wp_error( $ref ) ) {
						return self::fail( $workspace, $run, 'prepare_pages', $ref, array( $page_id ) );
					}
					$run['refs']['pages'][ $page_id ]       = $ref;
					$run['work']['page_plans_prepared']     = count( $run['refs']['pages'] );
					$run['progress']['prepared_page_count'] = count( $run['refs']['pages'] );
					$run['progress']['updated_at']          = gmdate( 'c' );
					$write                                  = self::write_run( $workspace, $run );
					if ( is_wp_error( $write ) ) {
						return self::fail( $workspace, $run, 'prepare_pages_checkpoint', $write, array( $page_id ) );
					}
				}
				$run['timings']['prepare_pages_seconds'] = (float) ( $run['timings']['prepare_pages_seconds'] ?? 0 ) + microtime( true ) - $started;
				$run['phase']                            = 'compiling_pages';
				$run['progress']['phase']                = 'compiling_pages';
				$run['progress']['updated_at']           = gmdate( 'c' );
				$write                                   = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'prepare_pages_checkpoint', $write, $prepare_ids );
				}
			}
			$remaining_plans = count( $run['page_ids'] ) - count( $run['refs']['pages'] ?? array() );
			if ( 0 < $remaining_plans ) {
				$reason = self::deadline_reached( $deadline, $clock ) ? 'deadline_exhausted' : 'pages_remaining';
				return self::continuation( $run, $reason );
			}

			$adopted = self::adopt_receipts( $workspace, $run, $shared );
			if ( is_wp_error( $adopted ) ) {
				return $adopted;
			}
			if ( ( $run['refs']['receipts'] ?? array() ) !== $adopted ) {
				$run['refs']['receipts'] = $adopted;
				foreach ( array_keys( $adopted ) as $page_id ) {
					$run['work']['page_compile_counts'][ $page_id ] = 1;
				}
				$run['work']['pages_compiled']    = count( $adopted );
				$run['work']['compile_batches']   = max( (int) ( $run['work']['compile_batches'] ?? 0 ), empty( $adopted ) ? 0 : 1 );
				$run['progress']['receipt_count'] = count( $adopted );
				$run['progress']['updated_at']    = gmdate( 'c' );
				$write                            = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'compile_pages_checkpoint', $write, array_keys( $adopted ) );
				}
			}

			$pending   = array_values( array_diff( $run['page_ids'], array_keys( $run['refs']['receipts'] ?? array() ) ) );
			$batch_ids = self::deadline_reached( $deadline, $clock ) ? array() : array_slice( $pending, 0, $policy['compile_batch_pages'] );
			if ( ! empty( $batch_ids ) ) {
				$plans = array();
				foreach ( $batch_ids as $page_id ) {
					$page_state = self::read_checkpoint( $workspace, $run, $run['refs']['pages'][ $page_id ] ?? array(), 'page_plan' );
					if ( is_wp_error( $page_state ) ) {
						return $page_state;
					}
					$plans[ $page_id ] = $page_state['plan'];
				}
				$started = microtime( true );
				$entered = self::enter_phase( $workspace, $run, 'compile_pages', $batch_ids );
				if ( is_wp_error( $entered ) ) {
					return $entered;
				}
				$run = $entered;
				self::before_phase( 'compile_pages', $run, $batch_ids );
				$batch = call_user_func( $compile_pages, $shared, array_values( $plans ) );
				if ( ! is_array( $batch ) || array_keys( $batch ) !== $batch_ids ) {
					return self::fail( $workspace, $run, 'compile_pages', new WP_Error( 'static_site_importer_direct_artifact_receipt_set_mismatch', 'Blocks Engine did not return the exact requested compiled receipt batch.' ), $batch_ids );
				}
				foreach ( $batch_ids as $page_id ) {
					$valid = self::validate_receipt( $batch[ $page_id ], $plans[ $page_id ], $shared );
					if ( is_wp_error( $valid ) ) {
						return self::fail( $workspace, $run, 'compile_pages', $valid, $batch_ids );
					}
				}
				$run['work']['compile_batches'] = (int) $run['work']['compile_batches'] + 1;
				foreach ( $batch_ids as $page_id ) {
					$receipt = $batch[ $page_id ];
					$ref     = self::publish_checkpoint( $workspace, $run, 'receipt', self::page_file( 'receipts', $page_id ), array(
						'page_id' => $page_id,
						'receipt' => $receipt,
					) );
					if ( is_wp_error( $ref ) ) {
						return self::fail( $workspace, $run, 'compile_pages', $ref, $batch_ids );
					}
					$run['refs']['receipts'][ $page_id ]            = $ref;
					$run['work']['pages_compiled']                  = (int) $run['work']['pages_compiled'] + 1;
					$run['work']['page_compile_counts'][ $page_id ] = (int) ( $run['work']['page_compile_counts'][ $page_id ] ?? 0 ) + 1;
					$run['progress']['receipt_count']               = count( $run['refs']['receipts'] );
					$run['progress']['updated_at']                  = gmdate( 'c' );
					$write = self::write_run( $workspace, $run );
					if ( is_wp_error( $write ) ) {
						return self::fail( $workspace, $run, 'compile_pages_checkpoint', $write, array( $page_id ) );
					}
				}
				$run['timings']['compile_pages_seconds'] = (float) ( $run['timings']['compile_pages_seconds'] ?? 0 ) + microtime( true ) - $started;
				$write                                   = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'compile_pages_checkpoint', $write, $batch_ids );
				}
			}

			$remaining = count( $run['page_ids'] ) - count( $run['refs']['receipts'] ?? array() );
			if ( 0 < $remaining ) {
				$reason = self::deadline_reached( $deadline, $clock ) ? 'deadline_exhausted' : 'pages_remaining';
				return self::continuation( $run, $reason );
			}
			if ( self::deadline_reached( $deadline, $clock ) && empty( $run['refs']['composed'] ) ) {
				return self::continuation( $run, 'deadline_exhausted' );
			}

			if ( empty( $run['refs']['composed'] ) ) {
				$composed_ref = self::existing_checkpoint_ref( $workspace, $run, 'composed-result.json', 'composed' );
				if ( is_wp_error( $composed_ref ) ) {
					return $composed_ref;
				}
				if ( ! empty( $composed_ref ) ) {
					$run['refs']['composed']     = $composed_ref;
					$run['work']['compositions'] = 1;
				}
			}
			if ( empty( $run['refs']['composed'] ) ) {
				$receipts = self::complete_receipts( $workspace, $run, $shared );
				if ( is_wp_error( $receipts ) ) {
					return self::fail( $workspace, $run, 'compose', $receipts );
				}
				$started = microtime( true );
				$entered = self::enter_phase( $workspace, $run, 'compose', $run['page_ids'] );
				if ( is_wp_error( $entered ) ) {
					return $entered;
				}
				$run = $entered;
				self::before_phase( 'compose', $run, $run['page_ids'] );
				$result = call_user_func( $compose, $shared, array_values( $receipts ) );
				if ( ! is_object( $result ) || ! is_callable( array( $result, 'toArray' ) ) ) {
					throw new RuntimeException( 'Blocks Engine returned an invalid composed result.' );
				}
				$composed = call_user_func( array( $result, 'toArray' ) );
				$metrics  = is_array( $composed['metrics'] ?? null ) ? $composed['metrics'] : array();
				if ( 0 !== (int) ( $metrics['html_document_transform_count'] ?? 0 ) || 0 !== (int) ( $metrics['normalization_count'] ?? 0 ) ) {
					throw new RuntimeException( 'Terminal receipt composition performed HTML transforms or normalization.' );
				}
				$ref = self::publish_checkpoint( $workspace, $run, 'composed', 'composed-result.json', array(
					'result'        => $composed,
					'terminal_work' => $metrics,
				) );
				if ( is_wp_error( $ref ) ) {
					return self::fail( $workspace, $run, 'compose', $ref, $run['page_ids'] );
				}
				$run['refs']['composed']           = $ref;
				$run['work']['compositions']       = 1;
				$run['timings']['compose_seconds'] = microtime( true ) - $started;
				$run['phase']                      = 'composed';
				$run['progress']['phase']          = 'composed';
				$run['progress']['updated_at']     = gmdate( 'c' );
				$write                             = self::write_run( $workspace, $run );
				if ( is_wp_error( $write ) ) {
					return self::fail( $workspace, $run, 'compose_checkpoint', $write, $run['page_ids'] );
				}
			}

			$composed_state = self::read_checkpoint( $workspace, $run, $run['refs']['composed'], 'composed' );
			if ( is_wp_error( $composed_state ) ) {
				return $composed_state;
			}
			if ( self::deadline_reached( $deadline, $clock ) ) {
				return self::continuation( $run, 'deadline_exhausted' );
			}
			$args['compiled_artifact_result']                 = $composed_state['result'];
			$args['_static_site_importer_precompiled_source'] = true;

			if ( 'plan' === $run['binding']['operation'] ) {
				$entered = self::enter_phase( $workspace, $run, 'canonical_plan' );
				if ( is_wp_error( $entered ) ) {
					return $entered;
				}
				$run      = $entered;
				$response = Static_Site_Importer_Canonical_Import_Service::plan_artifact( $artifact, $args, $run['binding']['source_type'], $run['provenance'] );
				if ( empty( $response['success'] ) ) {
					return self::fail( $workspace, $run, 'canonical_plan', self::response_error( $response ) );
				}
				$response['source']['identity'] = (string) ( $run['binding']['source_identity'] ?? '' );
			} else {
				if ( empty( $run['refs']['materialization'] ) ) {
					$materialization_ref = self::existing_checkpoint_ref( $workspace, $run, 'materialization-result.json', 'materialization' );
					if ( is_wp_error( $materialization_ref ) ) {
						return $materialization_ref;
					}
					if ( ! empty( $materialization_ref ) ) {
						$run['refs']['materialization']  = $materialization_ref;
						$run['work']['materializations'] = 1;
					}
				}
				if ( empty( $run['refs']['materialization'] ) ) {
					$claim = self::claim_materialization( $workspace, $run );
					if ( is_wp_error( $claim ) ) {
						return self::fail( $workspace, $run, 'materialization_claim', $claim );
					}
					$run                                     = $claim;
					$args['import_run_id']                   = $run['import_id'];
					$run['work']['materialization_attempts'] = (int) $run['work']['materialization_attempts'] + 1;
					$entered                                 = self::enter_phase( $workspace, $run, 'materialize' );
					if ( is_wp_error( $entered ) ) {
						return $entered;
					}
					$run     = $entered;
					$started = microtime( true );
					self::before_phase( 'materialize', $run );
					$materialized = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
					if ( is_wp_error( $materialized ) ) {
						return self::fail( $workspace, $run, 'materialize', $materialized );
					}
					$materialized = Static_Site_Importer_Canonical_Import_Service::bound_success_result( $materialized );
					$ref          = self::publish_checkpoint( $workspace, $run, 'materialization', 'materialization-result.json', array( 'result' => $materialized ) );
					if ( is_wp_error( $ref ) ) {
						return self::fail( $workspace, $run, 'materialize', $ref );
					}
					$run['refs']['materialization']        = $ref;
					$run['work']['materializations']       = 1;
					$run['timings']['materialize_seconds'] = microtime( true ) - $started;
					$write                                 = self::write_run( $workspace, $run );
					if ( is_wp_error( $write ) ) {
						return self::fail( $workspace, $run, 'materialization_checkpoint', $write );
					}
				}
				$materialization = self::read_checkpoint( $workspace, $run, $run['refs']['materialization'], 'materialization' );
				if ( is_wp_error( $materialization ) ) {
					return $materialization;
				}
				$response = Static_Site_Importer_Canonical_Import_Service::success(
					$materialization['result'],
					array_merge(
						$args,
						array(
							'operation' => 'apply',
							'source'    => array(
								'type'      => $run['binding']['source_type'],
								'import_id' => $run['import_id'],
							),
						)
					)
				);
			}

			$run['state']                  = 'completed';
			$run['phase']                  = 'completed';
			$run['progress']['phase']      = 'completed';
			$run['progress']['updated_at'] = gmdate( 'c' );
			$response                      = array_merge(
				$response,
				array(
					'import_id'           => $run['import_id'],
					'continuation'        => false,
					'continuation_reason' => '',
					'artifact_run'        => self::evidence( $run, $composed_state['terminal_work'] ?? array() ),
				)
			);
			$final_ref                     = self::publish_checkpoint( $workspace, $run, 'final', 'final-response.json', array( 'response' => $response ) );
			if ( is_wp_error( $final_ref ) ) {
				return self::fail( $workspace, $run, 'terminal_checkpoint', $final_ref );
			}
			$run['refs']['final'] = $final_ref;
			$write                = self::write_run( $workspace, $run );
			if ( is_wp_error( $write ) ) {
				return self::fail( $workspace, $run, 'terminal_checkpoint', $write );
			}
			return $response;
		} catch ( Throwable $error ) {
			return self::fail( $workspace, $run, (string) ( $run['progress']['phase'] ?? $run['phase'] ?? 'runtime' ), $error );
		}
	}

	/** Atomically reserve the only materialization attempt before mutation. */
	private static function claim_materialization( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run ) {
		if ( ! $workspace->claim_directory( 'materialization-claim' ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_materialization_ambiguous', 'Materialization was already claimed without a durable result; the import will not repeat WordPress mutation.' );
		}
		$run['work']['materialization_claims'] = 1;
		$run['phase']                          = 'materialization_claimed';
		$run['progress']['phase']              = 'materialization_claimed';
		$run['progress']['updated_at']         = gmdate( 'c' );
		$write                                 = self::write_run( $workspace, $run );
		return is_wp_error( $write ) ? $write : $run;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function complete_receipts( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, array $shared ) {
		$refs = $run['refs']['receipts'] ?? array();
		if ( array_keys( $refs ) !== $run['page_ids'] ) {
			$ordered = array();
			foreach ( $run['page_ids'] as $page_id ) {
				if ( ! isset( $refs[ $page_id ] ) ) {
					return new WP_Error( 'static_site_importer_direct_artifact_receipts_incomplete', 'Composition requires one receipt for every frozen page.' );
				}
				$ordered[ $page_id ] = $refs[ $page_id ];
			}
			$refs = $ordered;
		}
		$receipts = array();
		foreach ( $run['page_ids'] as $page_id ) {
			$page = self::read_checkpoint( $workspace, $run, $run['refs']['pages'][ $page_id ] ?? array(), 'page_plan' );
			$row  = self::read_checkpoint( $workspace, $run, $refs[ $page_id ], 'receipt' );
			if ( is_wp_error( $page ) || is_wp_error( $row ) ) {
				return is_wp_error( $page ) ? $page : $row;
			}
			$valid = self::validate_receipt( $row['receipt'], $page['plan'], $shared );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$receipts[ $page_id ] = $row['receipt'];
		}
		return $receipts;
	}

	private static function adopt_page_plans( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, array $shared ) {
		$refs = is_array( $run['refs']['pages'] ?? null ) ? $run['refs']['pages'] : array();
		foreach ( $run['page_ids'] as $page_id ) {
			if ( isset( $refs[ $page_id ] ) ) {
				continue;
			}
			$ref = self::existing_checkpoint_ref( $workspace, $run, self::page_file( 'page-plans', $page_id ), 'page_plan' );
			if ( is_wp_error( $ref ) ) {
				return $ref;
			}
			if ( empty( $ref ) ) {
				continue;
			}
			$row = self::read_checkpoint( $workspace, $run, $ref, 'page_plan' );
			if ( is_wp_error( $row ) || ( $row['plan']['shared_digest'] ?? '' ) !== ( $shared['digest'] ?? '' ) || ( $row['plan']['page_id'] ?? '' ) !== $page_id ) {
				return is_wp_error( $row ) ? $row : new WP_Error( 'static_site_importer_direct_artifact_page_plan_mismatch', 'A retained page plan does not match the frozen shared plan.' );
			}
			$refs[ $page_id ] = $ref;
		}
		$ordered = array();
		foreach ( $run['page_ids'] as $page_id ) {
			if ( isset( $refs[ $page_id ] ) ) {
				$ordered[ $page_id ] = $refs[ $page_id ];
			}
		}
		return $ordered;
	}

	private static function adopt_receipts( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, array $shared ) {
		$refs = is_array( $run['refs']['receipts'] ?? null ) ? $run['refs']['receipts'] : array();
		foreach ( $run['page_ids'] as $page_id ) {
			if ( isset( $refs[ $page_id ] ) ) {
				continue;
			}
			$ref = self::existing_checkpoint_ref( $workspace, $run, self::page_file( 'receipts', $page_id ), 'receipt' );
			if ( is_wp_error( $ref ) ) {
				return $ref;
			}
			if ( empty( $ref ) ) {
				continue;
			}
			$page = self::read_checkpoint( $workspace, $run, $run['refs']['pages'][ $page_id ] ?? array(), 'page_plan' );
			$row  = self::read_checkpoint( $workspace, $run, $ref, 'receipt' );
			if ( is_wp_error( $page ) || is_wp_error( $row ) ) {
				return is_wp_error( $page ) ? $page : $row;
			}
			$valid = self::validate_receipt( $row['receipt'], $page['plan'], $shared );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$refs[ $page_id ] = $ref;
		}
		$ordered = array();
		foreach ( $run['page_ids'] as $page_id ) {
			if ( isset( $refs[ $page_id ] ) ) {
				$ordered[ $page_id ] = $refs[ $page_id ];
			}
		}
		return $ordered;
	}

	private static function validate_page_plan( $plan, array $shared, string $page_id ) {
		if ( ! is_array( $plan ) || 'blocks-engine/php-transformer/staged-page-plan/v1' !== ( $plan['schema'] ?? '' ) || ( $plan['page_id'] ?? '' ) !== $page_id || ( $shared['digest'] ?? '' ) !== ( $plan['shared_digest'] ?? '' ) || empty( $plan['digest'] ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_page_plan_invalid', 'A prepared page plan is not bound to the frozen shared plan.' );
		}
		return true;
	}

	private static function validate_receipt( $receipt, array $page_plan, array $shared ) {
		$reduction          = is_array( $receipt['terminal_reduction'] ?? null ) ? $receipt['terminal_reduction'] : array();
		$required_reduction = array( 'files', 'normalization', 'source_documents', 'owned_transformable_paths', 'stylesheet_occurrence_files', 'component_facts', 'block_types' );
		$reduction_complete = empty( array_diff( $required_reduction, array_keys( $reduction ) ) );
		if ( ! is_array( $receipt ) || self::RECEIPT_SCHEMA !== ( $receipt['receipt_schema'] ?? '' ) || ( $page_plan['page_id'] ?? '' ) !== ( $receipt['page_id'] ?? '' ) || ( $shared['digest'] ?? '' ) !== ( $receipt['shared_digest'] ?? '' ) || ( $shared['shared_reduction_digest'] ?? '' ) !== ( $receipt['shared_reduction_digest'] ?? '' ) || ( $page_plan['compiler_options'] ?? null ) !== ( $receipt['compiler_options'] ?? null ) || ( $page_plan['output_schema'] ?? null ) !== ( $receipt['output_schema'] ?? null ) || ( $page_plan['digest'] ?? '' ) === ( $receipt['digest'] ?? '' ) || empty( $receipt['digest'] ) || ! is_array( $receipt['compiled_documents'] ?? null ) || ! is_array( $receipt['owned_document_paths'] ?? null ) || ! $reduction_complete ) {
			return new WP_Error( 'static_site_importer_direct_artifact_receipt_invalid', 'A compiled page receipt does not satisfy the frozen v2 receipt contract.' );
		}
		return true;
	}

	/** Validate every referenced retained value before a resumed phase reads it. */
	private static function validate_retained_refs( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run ) {
		$kinds = array(
			'artifact'        => 'artifact',
			'shared'          => 'shared',
			'composed'        => 'composed',
			'materialization' => 'materialization',
			'final'           => 'final',
		);
		foreach ( $kinds as $key => $kind ) {
			if ( isset( $run['refs'][ $key ] ) && is_wp_error( self::read_checkpoint( $workspace, $run, $run['refs'][ $key ], $kind ) ) ) {
				return self::read_checkpoint( $workspace, $run, $run['refs'][ $key ], $kind );
			}
		}
		foreach ( array(
			'pages'    => 'page_plan',
			'receipts' => 'receipt',
		) as $key => $kind ) {
			foreach ( is_array( $run['refs'][ $key ] ?? null ) ? $run['refs'][ $key ] : array() as $ref ) {
				$value = self::read_checkpoint( $workspace, $run, $ref, $kind );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
		}
		return true;
	}

	/** Persist the boundary before entering an uninterruptible owning call. */
	private static function enter_phase( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, string $phase, array $page_ids = array() ) {
		$run['phase']                           = $phase;
		$run['progress']['phase']               = $phase;
		$run['progress']['attempted_page_ids']  = array_values( $page_ids );
		$run['progress']['phase_started_at']    = gmdate( 'c' );
		$run['progress']['phase_started_epoch'] = microtime( true );
		$run['progress']['updated_at']          = $run['progress']['phase_started_at'];
		$write                                  = self::write_run( $workspace, $run );
		return is_wp_error( $write ) ? $write : $run;
	}

	private static function continuation( array $run, string $reason ): array {
		return array(
			'success'               => true,
			'operation'             => $run['binding']['operation'],
			'import_id'             => $run['import_id'],
			'continuation'          => true,
			'continuation_reason'   => $reason,
			'import_report_summary' => array( 'status' => 'continuing' ),
			'artifact_run'          => self::evidence( $run ),
		);
	}

	private static function evidence( array $run, array $terminal_work = array() ): array {
		$counts = array_values( is_array( $run['work']['page_compile_counts'] ?? null ) ? $run['work']['page_compile_counts'] : array() );
		sort( $counts, SORT_NUMERIC );
		$counts             = array_slice( $counts, 0, 50 );
		$receipt_identities = array();
		foreach ( is_array( $run['refs']['receipts'] ?? null ) ? $run['refs']['receipts'] : array() as $page_id => $ref ) {
			$receipt_identities[] = hash( 'sha256', $page_id . "\n" . (string) ( $ref['sha256'] ?? '' ) );
		}
		sort( $receipt_identities, SORT_STRING );
		$failures = array_map(
			static fn ( array $failure ): array => array(
				'phase'              => (string) ( $failure['phase'] ?? '' ),
				'exception_class'    => (string) ( $failure['exception_class'] ?? '' ),
				'artifact_identity'  => (string) ( $failure['artifact_identity'] ?? '' ),
				'attempted_page_ids' => array_map( static fn ( string $id ): string => hash( 'sha256', $id ), array_slice( is_array( $failure['attempted_page_ids'] ?? null ) ? $failure['attempted_page_ids'] : array(), 0, 20 ) ),
				'phase_started_at'   => (string) ( $failure['phase_started_at'] ?? '' ),
				'elapsed_seconds'    => (float) ( $failure['elapsed_seconds'] ?? 0 ),
				'error'              => self::scrub_error( $failure['error'] ?? array() ),
			),
			array_slice( is_array( $run['failures'] ?? null ) ? $run['failures'] : array(), -5 )
		);
		return array(
			'schema'               => self::EVIDENCE_SCHEMA,
			'state'                => (string) ( $run['state'] ?? '' ),
			'phase'                => (string) ( $run['phase'] ?? '' ),
			'artifact_identity'    => (string) ( $run['binding']['artifact_identity'] ?? '' ),
			'progress'             => array(
				'page_count'     => count( $run['page_ids'] ?? array() ),
				'prepared_count' => count( $run['refs']['pages'] ?? array() ),
				'receipt_count'  => count( $run['refs']['receipts'] ?? array() ),
				'remaining'      => max( 0, count( $run['page_ids'] ?? array() ) - count( $run['refs']['receipts'] ?? array() ) ),
			),
			'work'                 => array(
				'content_policy_applications'       => (int) ( $run['work']['content_policy_applications'] ?? 0 ),
				'client_script_policy_applications' => (int) ( $run['work']['client_script_policy_applications'] ?? 0 ),
				'shared_prepares'                   => (int) ( $run['work']['shared_prepares'] ?? 0 ),
				'page_prepare_passes'               => (int) ( $run['work']['page_prepare_passes'] ?? 0 ),
				'page_plans_prepared'               => (int) ( $run['work']['page_plans_prepared'] ?? 0 ),
				'compile_batches'                   => (int) ( $run['work']['compile_batches'] ?? 0 ),
				'pages_compiled'                    => (int) ( $run['work']['pages_compiled'] ?? 0 ),
				'page_compile_counts'               => $counts,
				'compositions'                      => (int) ( $run['work']['compositions'] ?? 0 ),
				'materialization_claims'            => (int) ( $run['work']['materialization_claims'] ?? 0 ),
				'materialization_attempts'          => (int) ( $run['work']['materialization_attempts'] ?? 0 ),
				'materializations'                  => (int) ( $run['work']['materializations'] ?? 0 ),
			),
			'phase_timings'        => array_map(
				'floatval',
				array_intersect_key(
					is_array( $run['timings'] ?? null ) ? $run['timings'] : array(),
					array_flip( array( 'freeze_seconds', 'prepare_shared_seconds', 'prepare_pages_seconds', 'compile_pages_seconds', 'compose_seconds', 'materialize_seconds' ) )
				)
			),
			'receipt_identities'   => array_slice( $receipt_identities, 0, 50 ),
			'terminal_result_work' => array_intersect_key( $terminal_work, array_flip( array( 'html_document_transform_count', 'normalization_count', 'analysis_count', 'terminal_reduction_count' ) ) ),
			'failures'             => $failures,
		);
	}

	private static function fail( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, string $phase, Throwable|WP_Error $error, array $page_ids = array() ) {
		if ( is_wp_error( $error ) ) {
			$code    = (string) $error->get_error_code();
			$message = $error->get_error_message();
			$data    = $error->get_error_data();
		} else {
			$code    = 'static_site_importer_direct_artifact_phase_failed';
			$message = $error->getMessage();
			$data    = null;
		}
		$failure                       = array(
			'phase'              => $phase,
			'exception_class'    => get_class( $error ),
			'artifact_identity'  => (string) ( $run['binding']['artifact_identity'] ?? '' ),
			'attempted_page_ids' => ! empty( $page_ids ) ? array_values( $page_ids ) : array_values( is_array( $run['progress']['attempted_page_ids'] ?? null ) ? $run['progress']['attempted_page_ids'] : array() ),
			'phase_started_at'   => (string) ( $run['progress']['phase_started_at'] ?? '' ),
			'elapsed_seconds'    => max( 0.0, microtime( true ) - (float) ( $run['progress']['phase_started_epoch'] ?? microtime( true ) ) ),
			'error'              => array(
				'code'    => $code,
				'message' => $message,
				'data'    => self::scrub_error( $data ),
			),
			'at'                 => gmdate( 'c' ),
		);
		$run['failures'][]             = $failure;
		$run['failures']               = array_slice( $run['failures'], -20 );
		$run['state']                  = 'failed';
		$run['progress']['phase']      = $phase;
		$run['progress']['updated_at'] = gmdate( 'c' );
		$write                         = self::write_run( $workspace, $run );
		$error_data                    = array(
			'import_id'    => (string) ( $run['import_id'] ?? '' ),
			'artifact_run' => self::evidence( $run ),
			'failure'      => self::scrub_error( $failure ),
			'resumable'    => ! in_array( $phase, array( 'materialize', 'materialization_claim' ), true ),
		);
		if ( is_wp_error( $write ) ) {
			$error_data['checkpoint_error'] = array(
				'code'    => $write->get_error_code(),
				'message' => $write->get_error_message(),
			);
		}
		return new WP_Error( $code, $message, $error_data );
	}

	private static function publish_checkpoint( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, string $kind, string $relative, array $payload ) {
		$allowed = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_direct_artifact_checkpoint_publish', true, $kind, $relative, self::evidence( $run ) ) : true;
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( true !== $allowed ) {
			return new WP_Error( 'static_site_importer_direct_artifact_checkpoint_rejected', 'The direct artifact checkpoint publication was rejected.' );
		}
		$payload_hash = self::hash( $payload );
		$shards       = array();
		if ( in_array( $kind, array( 'artifact', 'shared' ), true ) ) {
			$sharded = self::publish_file_shards( $workspace, $payload, $kind );
			if ( is_wp_error( $sharded ) ) {
				return $sharded;
			}
			$payload = $sharded['payload'];
			$shards  = $sharded['shards'];
		}
		$record = array(
			'schema'            => self::CHECKPOINT_SCHEMA,
			'kind'              => $kind,
			'import_id'         => $run['import_id'],
			'artifact_identity' => $run['binding']['artifact_identity'],
			'payload_sha256'    => $payload_hash,
			'payload'           => $payload,
			'shards'            => $shards,
		);
		$write  = $workspace->publish_json_once( $relative, $record );
		if ( is_wp_error( $write ) ) {
			return $write;
		}
		$raw_hash = is_string( $write ) ? hash_file( 'sha256', $write ) : false;
		if ( ! is_string( $raw_hash ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_checkpoint_missing', 'The published direct artifact checkpoint could not be verified.' );
		}
		return array(
			'file'    => $relative,
			'sha256'  => $raw_hash,
			'payload' => $payload_hash,
		);
	}

	private static function read_checkpoint( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, array $ref, string $kind ) {
		$relative = is_string( $ref['file'] ?? null ) ? $ref['file'] : '';
		$raw      = '' !== $relative ? $workspace->read_raw( $relative ) : null;
		$record   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$raw_hash = is_string( $raw ) ? hash( 'sha256', $raw ) : '';
		unset( $raw );
		if ( is_array( $record ) && in_array( $kind, array( 'artifact', 'shared' ), true ) ) {
			$record = self::hydrate_file_shards( $workspace, $record, $kind );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
		}
		if ( ! is_array( $record ) || ! hash_equals( (string) ( $ref['sha256'] ?? '' ), $raw_hash ) || self::CHECKPOINT_SCHEMA !== ( $record['schema'] ?? '' ) || ( $record['kind'] ?? '' ) !== $kind || ( $record['import_id'] ?? '' ) !== $run['import_id'] || ( $run['binding']['artifact_identity'] ?? '' ) !== ( $record['artifact_identity'] ?? '' ) || ! is_array( $record['payload'] ?? null ) || ! hash_equals( (string) ( $record['payload_sha256'] ?? '' ), self::hash( $record['payload'] ) ) || ! hash_equals( (string) ( $ref['payload'] ?? '' ), (string) $record['payload_sha256'] ) || ! self::checkpoint_contract_valid( $record['payload'], $kind, $run ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_checkpoint_invalid', 'A retained direct artifact checkpoint failed its identity, hash, or contract validation.' );
		}
		return $record['payload'];
	}

	private static function existing_checkpoint_ref( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run, string $relative, string $kind ) {
		$raw    = $workspace->read_raw( $relative );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_string( $raw ) ) {
			return array();
		}
		if ( is_array( $record ) && in_array( $kind, array( 'artifact', 'shared' ), true ) ) {
			$record = self::hydrate_file_shards( $workspace, $record, $kind );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
		}
		if ( ! is_array( $record ) || self::CHECKPOINT_SCHEMA !== ( $record['schema'] ?? '' ) || ( $record['kind'] ?? '' ) !== $kind || ( $record['import_id'] ?? '' ) !== $run['import_id'] || ( $run['binding']['artifact_identity'] ?? '' ) !== ( $record['artifact_identity'] ?? '' ) || ! is_array( $record['payload'] ?? null ) || ! hash_equals( (string) ( $record['payload_sha256'] ?? '' ), self::hash( $record['payload'] ) ) || ! self::checkpoint_contract_valid( $record['payload'], $kind, $run ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_checkpoint_invalid', 'An unpublished retained checkpoint failed validation.' );
		}
		return array(
			'file'    => $relative,
			'sha256'  => hash( 'sha256', $raw ),
			'payload' => $record['payload_sha256'],
		);
	}

	private static function publish_file_shards( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $payload, string $kind ) {
		$files  = 'artifact' === $kind ? ( $payload['artifact']['files'] ?? null ) : ( $payload['plan']['artifact']['files'] ?? null );
		$files  = is_array( $files ) ? $files : array();
		$shards = array();
		foreach ( array_values( $files ) as $index => $file ) {
			$relative = $kind . '-files/' . sprintf( '%05d.json', $index );
			$write    = $workspace->publish_json_once( $relative, array( 'file' => $file ) );
			$sha256   = is_string( $write ) ? hash_file( 'sha256', $write ) : false;
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			if ( ! is_string( $sha256 ) ) {
				return new WP_Error( 'static_site_importer_direct_artifact_shard_missing', 'A published direct artifact file shard could not be verified.' );
			}
			$shards[] = array(
				'file'   => $relative,
				'sha256' => $sha256,
			);
		}
		if ( 'artifact' === $kind ) {
			$payload['artifact']['files'] = array();
		} else {
			$payload['plan']['artifact']['files'] = array();
		}
		return array(
			'payload' => $payload,
			'shards'  => $shards,
		);
	}

	private static function hydrate_file_shards( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $record, string $kind ) {
		$stored_files = 'artifact' === $kind ? ( $record['payload']['artifact']['files'] ?? null ) : ( $record['payload']['plan']['artifact']['files'] ?? null );
		if ( ! is_array( $stored_files ) || ! empty( $stored_files ) || ! is_array( $record['shards'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_shards_invalid', 'The retained direct artifact file shard manifest is invalid.' );
		}
		$files = array();
		foreach ( $record['shards'] as $shard ) {
			$relative = is_array( $shard ) && is_string( $shard['file'] ?? null ) ? $shard['file'] : '';
			$raw      = '' !== $relative ? $workspace->read_raw( $relative ) : null;
			$sha256   = is_string( $raw ) ? hash( 'sha256', $raw ) : '';
			$decoded  = is_string( $raw ) ? json_decode( $raw, true ) : null;
			unset( $raw );
			if ( ! is_array( $decoded ) || ! array_key_exists( 'file', $decoded ) || ! hash_equals( (string) ( $shard['sha256'] ?? '' ), $sha256 ) ) {
				return new WP_Error( 'static_site_importer_direct_artifact_shard_invalid', 'A retained direct artifact file shard failed validation.' );
			}
			$files[] = $decoded['file'];
		}
		if ( 'artifact' === $kind ) {
			$record['payload']['artifact']['files'] = $files;
		} else {
			$record['payload']['plan']['artifact']['files'] = $files;
		}
		return $record;
	}

	private static function write_run( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $run ) {
		$allowed = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_direct_artifact_checkpoint_publish', true, 'run', 'run.json', self::evidence( $run ) ) : true;
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( true !== $allowed ) {
			return new WP_Error( 'static_site_importer_direct_artifact_checkpoint_rejected', 'The direct artifact run checkpoint publication was rejected.' );
		}
		$record = array(
			'schema'         => self::RUN_SCHEMA,
			'payload_sha256' => self::hash( $run ),
			'payload'        => $run,
		);
		return $workspace->publish_json( 'run.json', $record );
	}

	private static function read_run( Static_Site_Importer_Artifact_Run_Workspace $workspace, string $import_id ) {
		$raw    = $workspace->read_raw( 'run.json' );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $record ) || self::RUN_SCHEMA !== ( $record['schema'] ?? '' ) || ! is_array( $record['payload'] ?? null ) || ! hash_equals( (string) ( $record['payload_sha256'] ?? '' ), self::hash( $record['payload'] ) ) || ( $record['payload']['import_id'] ?? '' ) !== $import_id || ! is_array( $record['payload']['binding'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_run_not_found', 'The direct artifact run was not found or failed validation.' );
		}
		return $record['payload'];
	}

	private static function workspace( string $import_id, bool $create ) {
		$root = self::root();
		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_workspace_unavailable', 'The direct artifact run workspace is unavailable.' );
		}
		$directory = trailingslashit( $root ) . '.ssi-artifact-run-direct-' . $import_id;
		if ( ! $create && ! is_dir( $directory ) ) {
			return new WP_Error( 'static_site_importer_direct_artifact_run_not_found', 'The direct artifact run was not found.' );
		}
		try {
			return new Static_Site_Importer_Artifact_Run_Workspace(
				$root,
				'direct-' . $import_id,
				array(
					'on_success' => 'retain',
					'on_failure' => 'retain',
					'expires_at' => gmdate( 'c', time() + self::TTL ),
				)
			);
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'static_site_importer_direct_artifact_workspace_unavailable', $error->getMessage() );
		}
	}

	/** Register expiry cleanup without exposing retained workspace paths. */
	public static function register_cleanup(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::CLEANUP_HOOK, array( self::class, 'purge_expired' ) );
		}
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function unschedule_cleanup(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}
	}

	public static function purge_expired(): void {
		$root = self::root();
		if ( wp_mkdir_p( $root ) ) {
			Static_Site_Importer_Artifact_Run_Workspace::purge_expired_in( $root );
		}
	}

	private static function root(): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : sys_get_temp_dir();
		$root    = trailingslashit( $base ) . 'static-site-importer/direct-artifact-imports';
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'static_site_importer_direct_artifact_root', $root ) : $root;
	}

	private static function compiler() {
		$class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required for direct multi-page imports.' );
		}
		$compiler = new $class();
		return function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_direct_artifact_compiler', $compiler ) : $compiler;
	}

	private static function run_policy(): array {
		$policy = array(
			'prepare_batch_pages'       => 2,
			'compile_batch_pages'       => 2,
			'max_invocation_seconds'    => 20.0,
			'freeze_continuation_bytes' => 8 * 1024 * 1024,
			'clock'                     => static fn (): float => microtime( true ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'static_site_importer_direct_artifact_run_policy', $policy );
			if ( is_array( $filtered ) ) {
				$policy = array_merge( $policy, $filtered );
			}
		}
		$policy['prepare_batch_pages']       = min( 20, max( 1, (int) $policy['prepare_batch_pages'] ) );
		$policy['compile_batch_pages']       = min( 20, max( 1, (int) $policy['compile_batch_pages'] ) );
		$policy['max_invocation_seconds']    = max( 0.001, (float) $policy['max_invocation_seconds'] );
		$policy['freeze_continuation_bytes'] = max( 1, (int) $policy['freeze_continuation_bytes'] );
		$policy['clock']                     = is_callable( $policy['clock'] ) ? $policy['clock'] : static fn (): float => microtime( true );
		return $policy;
	}

	private static function exceeds_string_bytes( $value, int $limit, int &$bytes ): bool {
		if ( is_string( $value ) ) {
			$bytes += strlen( $value );
			return $bytes > $limit;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( self::exceeds_string_bytes( $item, $limit, $bytes ) ) {
				return true;
			}
		}
		return false;
	}

	private static function implementation_binding(): array {
		$classes = array(
			'Static_Site_Importer_Content_Policy',
			'Static_Site_Importer_Client_Script_Policy',
			'Static_Site_Importer_Canonical_Import_Service',
			'Static_Site_Importer_Theme_Generator',
			'Static_Site_Importer_Theme_Materialization_Strategy',
			'Static_Site_Importer_WordPress_Site_Plan_Materializer',
			self::class,
			'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler',
		);
		$binding = array();
		foreach ( $classes as $class ) {
			$file              = class_exists( $class ) ? ( new ReflectionClass( $class ) )->getFileName() : false;
			$binding[ $class ] = is_string( $file ) && is_readable( $file ) ? hash_file( 'sha256', $file ) : '';
		}
		return $binding;
	}

	private static function binding_args( array $args ): array {
		unset( $args['runtime_lifecycle_invocation_id'], $args['client_script_policy_report'], $args['compiled_artifact_result'], $args['_static_site_importer_precompiled_source'], $args['import_run_id'] );
		if ( is_array( $args['source_metadata']['collection'] ?? null ) ) {
			unset( $args['source_metadata']['collection']['script_policy'] );
			if ( empty( $args['source_metadata']['collection'] ) ) {
				unset( $args['source_metadata']['collection'] );
			}
		}
		return self::canonical( $args );
	}

	private static function owner(): string {
		if ( class_exists( 'Static_Site_Importer_Lifecycle_Compile_Checkpoint' ) ) {
			return Static_Site_Importer_Lifecycle_Compile_Checkpoint::current_owner();
		}
		$site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return 'site:' . $site . ';user:' . $user;
	}

	private static function before_phase( string $phase, array $run, array $page_ids = array() ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'static_site_importer_direct_artifact_before_phase', $phase, self::evidence( $run ), array_map( 'strval', $page_ids ) );
		}
	}

	private static function response_error( array $response ): WP_Error {
		$error = is_array( $response['error'] ?? null ) ? $response['error'] : array();
		return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_direct_artifact_canonical_plan_failed' ), (string) ( $error['message'] ?? 'The composed artifact did not produce a canonical plan.' ), $error['data'] ?? null );
	}

	private static function checkpoint_contract_valid( array $payload, string $kind, array $run ): bool {
		if ( 'artifact' === $kind ) {
			return is_array( $payload['artifact'] ?? null )
				&& is_array( $payload['args'] ?? null )
				&& is_array( $payload['policy_report'] ?? null )
				&& hash_equals( (string) $run['binding']['artifact_identity'], self::hash( $payload['artifact'] ) )
				&& self::binding_args( $payload['args'] ) === ( $run['binding']['args'] ?? null );
		}
		if ( 'shared' === $kind ) {
			return 'blocks-engine/php-transformer/staged-shared-plan/v1' === ( $payload['plan']['schema'] ?? '' )
				&& is_string( $payload['plan']['digest'] ?? null )
				&& is_array( $payload['plan']['analysis']['page_ids'] ?? null );
		}
		if ( 'page_plan' === $kind ) {
			return is_string( $payload['page_id'] ?? null )
				&& 'blocks-engine/php-transformer/staged-page-plan/v1' === ( $payload['plan']['schema'] ?? '' )
				&& ( $payload['plan']['page_id'] ?? null ) === $payload['page_id']
				&& is_string( $payload['plan']['digest'] ?? null );
		}
		if ( 'receipt' === $kind ) {
			return is_string( $payload['page_id'] ?? null )
				&& self::RECEIPT_SCHEMA === ( $payload['receipt']['receipt_schema'] ?? '' )
				&& ( $payload['receipt']['page_id'] ?? null ) === $payload['page_id']
				&& is_array( $payload['receipt']['terminal_reduction'] ?? null );
		}
		if ( 'composed' === $kind ) {
			$work = is_array( $payload['terminal_work'] ?? null ) ? $payload['terminal_work'] : array();
			return 'blocks-engine/php-transformer/result/v1' === ( $payload['result']['schema'] ?? '' )
				&& 0 === (int) ( $work['html_document_transform_count'] ?? -1 )
				&& 0 === (int) ( $work['normalization_count'] ?? -1 );
		}
		if ( 'materialization' === $kind ) {
			return is_array( $payload['result'] ?? null );
		}
		if ( 'final' === $kind ) {
			return is_array( $payload['response'] ?? null ) && ! empty( $payload['response']['success'] ) && empty( $payload['response']['continuation'] );
		}
		return false;
	}

	private static function page_file( string $directory, string $page_id ): string {
		return $directory . '/' . hash( 'sha256', $page_id ) . '.json';
	}

	private static function deadline_reached( float $deadline, callable $clock ): bool {
		return (float) call_user_func( $clock ) >= $deadline;
	}

	private static function hash( array $value ): string {
		return self::hash_json( $value, true );
	}

	private static function hash_json( array $value, bool $canonical ): string {
		$context = hash_init( 'sha256' );
		self::hash_json_value( $context, $value, $canonical );
		return hash_final( $context );
	}

	/** Stream JSON tokens so artifact identity never requires an artifact-sized string. */
	private static function hash_json_value( $context, $value, bool $canonical ): void {
		if ( ! is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			hash_update( $context, is_string( $encoded ) ? $encoded : '' );
			return;
		}
		if ( array_is_list( $value ) ) {
			hash_update( $context, '[' );
			foreach ( $value as $index => $item ) {
				if ( 0 !== $index ) {
					hash_update( $context, ',' );
				}
				self::hash_json_value( $context, $item, $canonical );
			}
			hash_update( $context, ']' );
			return;
		}
		$keys = array_keys( $value );
		if ( $canonical ) {
			sort( $keys, SORT_STRING );
		}
		hash_update( $context, '{' );
		foreach ( $keys as $index => $key ) {
			if ( 0 !== $index ) {
				hash_update( $context, ',' );
			}
			$encoded_key = wp_json_encode( (string) $key );
			hash_update( $context, is_string( $encoded_key ) ? $encoded_key : '' );
			hash_update( $context, ':' );
			self::hash_json_value( $context, $value[ $key ], $canonical );
		}
		hash_update( $context, '}' );
	}

	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as &$item ) {
			$item = self::canonical( $item );
		}
		unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	private static function scrub_error( $value, int $depth = 0 ) {
		if ( $depth >= 6 ) {
			return '[truncated]';
		}
		if ( ! is_array( $value ) ) {
			if ( is_string( $value ) ) {
				return substr( $value, 0, 1000 );
			}
			return is_scalar( $value ) || null === $value ? $value : get_debug_type( $value );
		}
		$clean = array();
		foreach ( $value as $key => $item ) {
			if ( count( $clean ) >= 20 ) {
				$clean['_truncated'] = true;
				break;
			}
			if ( is_string( $key ) && ( str_contains( strtolower( $key ), 'path' ) || str_contains( strtolower( $key ), 'workspace' ) || str_contains( strtolower( $key ), 'manifest' ) ) ) {
				continue;
			}
			$clean[ $key ] = self::scrub_error( $item, $depth + 1 );
		}
		return $clean;
	}
}
