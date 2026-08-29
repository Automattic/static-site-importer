<?php
/**
 * Owner-handoff evidence and report-card composer.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes existing, owning-layer evidence without recreating its policy.
 */
final class Static_Site_Importer_Owner_Handoff_Evidence {

	public const SCHEMA                    = 'static-site-importer/owner-handoff-evidence/v1';
	public const REPORT_CARD_SCHEMA        = 'static-site-importer/owner-handoff-report-card/v1';
	public const DIMENSION_EVIDENCE_SCHEMA = 'static-site-importer/owner-handoff-dimension-evidence/v1';
	public const SOURCE_EVIDENCE_SCHEMA    = 'static-site-importer/owner-handoff-source-evidence/v1';
	public const OWNER_TASK_SCHEMA         = 'static-site-importer/owner-task-check/v1';
	public const VERSION                   = 1;

	public const DIMENSION_IDS = array(
		'route_content_completeness',
		'visual_acceptance',
		'editability_and_shared_regions',
		'editor_presentation_and_persistence',
		'media_library_ownership',
		'link_portability',
		'interaction_and_provider_functionality',
		'document_metadata_and_identity',
		'accessibility',
		'frontend_performance',
		'dependency_deployment_rollback',
		'owner_task_check',
	);

	public const OWNER_TASKS = array(
		'text_edit',
		'image_replace',
		'navigation_edit',
		'shared_footer_edit',
		'form_recipient_edit',
	);

	public const STATUSES = array(
		'pass',
		'hard_failure',
		'owner_decision',
		'acceptable_conversion',
		'informational',
		'evidence_gap',
	);

	private const OWNERS = array(
		'route_content_completeness'             => 'Automattic/blocks-engine',
		'visual_acceptance'                      => 'Automattic/static-site-importer',
		'editability_and_shared_regions'         => 'Automattic/blocks-engine',
		'editor_presentation_and_persistence'    => 'Automattic/static-site-importer',
		'media_library_ownership'                => 'Automattic/static-site-importer',
		'link_portability'                       => 'Automattic/static-site-importer',
		'interaction_and_provider_functionality' => 'Automattic/static-site-importer',
		'document_metadata_and_identity'         => 'Automattic/static-site-importer',
		'accessibility'                          => 'Automattic/static-site-importer',
		'frontend_performance'                   => 'Automattic/static-site-importer',
		'dependency_deployment_rollback'         => 'Automattic/static-site-importer',
		'owner_task_check'                       => 'Automattic/static-site-importer',
	);

	private const GAP_ACTIONS = array(
		'route_content_completeness'             => 'Supply route-scoped completeness evidence from the owning conversion policy.',
		'visual_acceptance'                      => 'Supply accepted desktop and mobile visual evidence for every required route.',
		'editability_and_shared_regions'         => 'Supply a plan-bound Blocks Engine editability and shared-region admission.',
		'editor_presentation_and_persistence'    => 'Supply editor presentation and persisted edit evidence from the owning runtime.',
		'media_library_ownership'                => 'Supply per-asset Media Library attachment and block-binding evidence.',
		'link_portability'                       => 'Supply internal-link portability and external-link inventory evidence.',
		'interaction_and_provider_functionality' => 'Supply interaction coverage and successful provider-functionality receipts.',
		'document_metadata_and_identity'         => 'Supply rendered metadata, site identity, and unresolved-placeholder evidence.',
		'accessibility'                          => 'Supply bounded keyboard and accessibility evidence from the owning runtime.',
		'frontend_performance'                   => 'Supply bounded generated-frontend performance evidence.',
		'dependency_deployment_rollback'         => 'Supply dependency, deployment, and rollback readiness evidence.',
		'owner_task_check'                       => 'Supply all five owner tasks with hash-bound save, reload, and validation receipts.',
	);

	/**
	 * @param array<string,mixed> $input Plan identity, receipt, and optional evidence artifacts.
	 * @return array<string,mixed>
	 */
	public static function compose( array $input ): array {
		$receipt   = self::object( $input['materialization_receipt'] ?? null );
		$bindings  = self::bindings( self::object( $input['plan_identity'] ?? null ), $receipt );
		$supplied  = self::object( $input['evidence'] ?? null );
		$dimensions = array();
		$findings   = array();

		foreach ( self::DIMENSION_IDS as $id ) {
			if ( isset( $supplied[ $id ] ) && is_array( $supplied[ $id ] ) ) {
				$evaluated = 'owner_task_check' === $id
					? self::consume_owner_tasks( $supplied[ $id ], $bindings )
					: self::consume_dimension( $id, $supplied[ $id ], $bindings );
			} else {
				$evaluated = self::consume_receipt_evidence( $id, $receipt, $bindings );
			}
			$dimensions[ $id ] = $evaluated['dimension'];
			$findings           = array_merge( $findings, $evaluated['findings'] );
		}

		$counts      = self::counts( $dimensions );
		$disposition = self::disposition( $counts, $bindings );
		$admitted    = self::bindings_verified( $bindings ) && 0 === $counts['hard_failure'] && 0 === $counts['evidence_gap'];
		$core        = array(
			'schema'           => self::SCHEMA,
			'version'          => self::VERSION,
			'bindings'         => $bindings,
			'dimensions'       => $dimensions,
			'findings'         => $findings,
			'counts'           => $counts,
			'disposition'      => $disposition,
			'handoff_admitted' => $admitted,
		);
		$document_hash              = self::hash_value( $core );
		$core['evidence_sha256']     = $document_hash;
		$core['report_card']         = self::report_card( $core );
		return $core;
	}

	/**
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report Finalized import report.
	 * @param array<string,mixed> $quality Unused compatibility argument.
	 * @param array<string,mixed> $args Import arguments carrying optional owner_handoff_evidence.
	 * @return array<string,mixed>
	 */
	public static function compose_from_report( array|Static_Site_Importer_Import_Report $report, array $quality = array(), array $args = array() ): array {
		$payload = $report instanceof Static_Site_Importer_Import_Report ? $report->to_array() : $report;
		unset( $payload['owner_handoff_evidence'] );
		$receipt = self::object( $payload['materialization_receipt'] ?? null );
		// Commit removes only this rollback journal. Bind evidence to the exact
		// terminal receipt projection while retaining the journal until writes end.
		unset( $receipt['transaction'] );
		return self::compose(
			array(
				'plan_identity'           => self::object( $payload['plan_identity'] ?? ( $receipt['plan_identity'] ?? null ) ),
				'materialization_receipt' => $receipt,
				'evidence'                => self::object( $args['owner_handoff_evidence'] ?? null ),
			)
		);
	}

	/**
	 * Recompose admission from authoritative receipt and source artifacts.
	 *
	 * @param array<string,mixed> $document Serialized owner-handoff document.
	 * @param array<string,mixed> $receipt Terminal materialization receipt.
	 * @param array<string,mixed> $evidence Owning source evidence used to compose the document.
	 */
	public static function admits_accepted_or_built( array $document, array $receipt = array(), array $evidence = array() ): bool {
		$plan_identity = self::object( $document['bindings']['plan_identity'] ?? null );
		if ( array() === $receipt || array() === $evidence || ! self::valid_plan_identity( $plan_identity ) ) {
			return false;
		}
		$expected = self::compose(
			array(
				'plan_identity'           => $plan_identity,
				'materialization_receipt' => $receipt,
				'evidence'                => $evidence,
			)
		);
		return true === ( $expected['handoff_admitted'] ?? false ) && hash_equals( self::hash_value( $expected ), self::hash_value( $document ) );
	}

	/**
	 * Canonical hash used for complete receipts and embedded evidence artifacts.
	 *
	 * @param mixed $value
	 */
	public static function hash_value( mixed $value ): string {
		$context = hash_init( 'sha256' );
		self::hash_json_value( $context, $value );
		return hash_final( $context );
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array<string,mixed>
	 */
	public static function report_card( array $document ): array {
		$rows = array();
		foreach ( self::DIMENSION_IDS as $id ) {
			$dimension = self::object( $document['dimensions'][ $id ] ?? null );
			$rows[]    = array(
				'dimension' => $id,
				'status'    => (string) ( $dimension['status'] ?? 'evidence_gap' ),
			);
		}
		return array(
			'schema'           => self::REPORT_CARD_SCHEMA,
			'evidence_sha256'  => (string) ( $document['evidence_sha256'] ?? '' ),
			'disposition'      => (string) ( $document['disposition'] ?? 'not_proven' ),
			'handoff_admitted' => true === ( $document['handoff_admitted'] ?? false ),
			'headline'         => self::headline( (string) ( $document['disposition'] ?? 'not_proven' ) ),
			'counts'           => self::object( $document['counts'] ?? null ),
			'dimensions'       => $rows,
			'findings'         => is_array( $document['findings'] ?? null ) ? $document['findings'] : array(),
		);
	}

	/** @return array<string,mixed> */
	private static function bindings( array $plan, array $receipt ): array {
		$gaps       = array();
		$valid_plan = self::valid_plan_identity( $plan ) ? self::plan_identity( $plan ) : null;
		if ( null === $valid_plan ) {
			$gaps[] = array( 'code' => 'plan_identity_missing_or_invalid' );
		}

		$receipt_binding = null;
		if ( 'static-site-importer/materialization-receipt/v2' !== ( $receipt['schema'] ?? null ) || ! in_array( $receipt['status'] ?? null, array( 'completed', 'failed', 'partial', 'rejected' ), true ) ) {
			$gaps[] = array( 'code' => 'materialization_receipt_missing_or_invalid' );
		} else {
			$receipt_plan    = self::object( $receipt['plan_identity'] ?? null );
			$receipt_hash = self::try_hash_value( $receipt );
			$receipt_binding = array(
				'schema'              => (string) $receipt['schema'],
				'status'              => (string) $receipt['status'],
				'sha256'              => $receipt_hash,
				'plan_identity'       => self::valid_plan_identity( $receipt_plan ) ? self::plan_identity( $receipt_plan ) : null,
				'receipt_instance_id' => self::is_sha256( $receipt['receipt_instance_id'] ?? null ) ? (string) $receipt['receipt_instance_id'] : null,
			);
			if ( null === $receipt_hash ) {
				$gaps[] = array( 'code' => 'materialization_receipt_not_hashable' );
			} elseif ( null === $receipt_binding['receipt_instance_id'] ) {
				$gaps[] = array( 'code' => 'receipt_instance_id_missing_or_invalid' );
			} elseif ( null === $receipt_binding['plan_identity'] ) {
				$gaps[] = array( 'code' => 'receipt_plan_identity_missing_or_invalid' );
			} elseif ( null !== $valid_plan && $valid_plan !== $receipt_binding['plan_identity'] ) {
				$gaps[] = array( 'code' => 'receipt_plan_identity_mismatch' );
			}
		}

		return array(
			'plan_identity'           => $valid_plan,
			'materialization_receipt' => $receipt_binding,
			'verified'                => array() === $gaps,
			'gaps'                    => $gaps,
		);
	}

	/** @return array{dimension:array<string,mixed>,findings:array<int,array<string,mixed>>} */
	private static function consume_dimension( string $id, array $reference, array $bindings ): array {
		$artifact = self::object( $reference['artifact'] ?? null );
		$reason   = self::artifact_problem( $id, $reference, $artifact, $bindings, self::DIMENSION_EVIDENCE_SCHEMA );
		if ( null !== $reason ) {
			return self::gap( $id, $reason );
		}

		$sources = self::source_references( $artifact['source_evidence'] ?? null, $bindings, $id );
		$status  = is_string( $artifact['status'] ?? null ) && in_array( $artifact['status'], self::STATUSES, true ) ? $artifact['status'] : null;
		$source_status = self::source_status( $artifact['source_evidence'] ?? null );
		if ( null === $status || array() === $sources || $status !== $source_status ) {
			return self::gap( $id, 'dimension_evidence_incomplete' );
		}

		$raw_findings = is_array( $artifact['findings'] ?? null ) ? array_values( $artifact['findings'] ) : array();
		$findings     = self::normalize_findings( $id, $raw_findings, $sources );
		$derived  = self::aggregate_status( $findings );
		if ( count( $raw_findings ) !== count( $findings ) || ( 'pass' === $status && array() !== $raw_findings ) || ( 'pass' !== $status && $status !== $derived ) ) {
			return self::gap( $id, 'dimension_evidence_status_mismatch' );
		}

		$evidence = array(
			'schema' => self::DIMENSION_EVIDENCE_SCHEMA,
			'sha256' => (string) $reference['sha256'],
			'sources' => $sources,
		);
		return array(
			'dimension' => self::dimension( $id, $status, $evidence, array_column( $findings, 'id' ) ),
			'findings'  => $findings,
		);
	}

	/** @return array{dimension:array<string,mixed>,findings:array<int,array<string,mixed>>} */
	private static function consume_owner_tasks( array $reference, array $bindings ): array {
		$id       = 'owner_task_check';
		$artifact = self::object( $reference['artifact'] ?? null );
		$reason   = self::artifact_problem( $id, $reference, $artifact, $bindings, self::OWNER_TASK_SCHEMA, false );
		if ( null !== $reason ) {
			return self::gap( $id, $reason );
		}

		$operations = self::object( $artifact['operations'] ?? null );
		$normalized = array();
		$findings   = array();
		foreach ( self::OWNER_TASKS as $task ) {
			$operation = self::object( $operations[ $task ] ?? null );
			$sources   = self::source_references( $operation['evidence'] ?? null, $bindings, $id );
			$complete  = self::is_sha256( $operation['before_sha256'] ?? null )
				&& self::is_sha256( $operation['after_sha256'] ?? null )
				&& ! hash_equals( (string) $operation['before_sha256'], (string) $operation['after_sha256'] )
				&& array() !== $sources
				&& isset( $operation['status'], $operation['save_reload_status'], $operation['validation_status'] );
			$failed    = $complete && in_array( 'failed', array( $operation['status'], $operation['save_reload_status'], $operation['validation_status'] ), true );
			$status    = ! $complete ? 'evidence_gap' : ( $failed ? 'hard_failure' : ( array( 'passed', 'passed', 'passed' ) === array( $operation['status'], $operation['save_reload_status'], $operation['validation_status'] ) ? 'pass' : 'evidence_gap' ) );
			$normalized[ $task ] = array(
				'status' => $status,
			);
			if ( 'pass' !== $status ) {
				$findings[] = self::finding(
					$id,
					$status,
					'*',
					$task,
					'hard_failure' === $status ? 'owner_task_operation_failed' : 'owner_task_operation_evidence_missing',
					'hard_failure' === $status ? 'An owner-task operation failed save, reload, or validation.' : 'Owner-task evidence is incomplete.',
					self::GAP_ACTIONS[ $id ],
					array() !== $sources ? $sources[0] : self::missing_reference( $id, 'owner_task_operation_evidence_missing' ),
					count( $findings )
				);
			}
		}

		$status = self::aggregate_status( $findings );
		return array(
			'dimension' => self::dimension(
				$id,
				$status,
				array(
					'schema' => self::OWNER_TASK_SCHEMA,
					'sha256' => (string) $reference['sha256'],
				),
				array_column( $findings, 'id' ),
				$normalized
			),
			'findings'  => $findings,
		);
	}

	/** @return array{dimension:array<string,mixed>,findings:array<int,array<string,mixed>>} */
	private static function consume_receipt_evidence( string $id, array $receipt, array $bindings ): array {
		if ( 'route_content_completeness' === $id && in_array( $receipt['status'] ?? null, array( 'failed', 'partial', 'rejected' ), true ) && null !== ( $bindings['materialization_receipt']['sha256'] ?? null ) ) {
			$finding = self::finding( $id, 'hard_failure', '*', 'materialization_receipt', 'materialization_not_completed', 'Materialization did not complete.', 'Repair the materialization failure before handoff.', self::receipt_reference( $bindings ), 0 );
			return array( 'dimension' => self::dimension( $id, 'hard_failure', self::receipt_reference( $bindings ), array( $finding['id'] ) ), 'findings' => array( $finding ) );
		}

		if ( 'editability_and_shared_regions' === $id && self::bindings_verified( $bindings ) ) {
			$admission = self::object( $receipt['editability_report'] ?? null );
			$reference = self::receipt_reference( $bindings, 'editability_report' );
			if ( 'static-site-importer/editability-report-admission/v1' === ( $admission['schema'] ?? null )
				&& 'passed' === ( $admission['status'] ?? null )
				&& 'blocks-engine/php-transformer/editability-report/v2' === ( $admission['report_schema'] ?? null )
				&& ( $bindings['plan_identity']['hash'] ?? null ) === ( $admission['plan_hash'] ?? null ) ) {
				return array( 'dimension' => self::dimension( $id, 'pass', $reference ), 'findings' => array() );
			}
			if ( 'static-site-importer/editability-report-admission/v1' === ( $admission['schema'] ?? null ) && 'rejected' === ( $admission['status'] ?? null ) ) {
				$finding = self::finding( $id, 'hard_failure', '*', 'editability_report', 'editability_report_rejected', 'The owning editability admission rejected the plan.', 'Repair the Blocks Engine editability findings before handoff.', $reference, 0 );
				return array( 'dimension' => self::dimension( $id, 'hard_failure', $reference, array( $finding['id'] ) ), 'findings' => array( $finding ) );
			}
		}

		return self::gap( $id, self::gap_reason( $id ) );
	}

	/** @return string|null */
	private static function artifact_problem( string $id, array $reference, array $artifact, array $bindings, string $schema, bool $require_dimension = true ): ?string {
		if ( ! self::bindings_verified( $bindings ) ) {
			return 'subject_binding_not_verified';
		}
		if ( $schema !== ( $artifact['schema'] ?? null ) || ( $require_dimension && $id !== ( $artifact['dimension'] ?? null ) ) ) {
			return 'evidence_schema_or_dimension_invalid';
		}
		$artifact_hash = self::try_hash_value( $artifact );
		if ( null === $artifact_hash || ! self::is_sha256( $reference['sha256'] ?? null ) || ! hash_equals( (string) $reference['sha256'], $artifact_hash ) ) {
			return 'evidence_hash_mismatch';
		}
		$subject = self::object( $artifact['subject'] ?? null );
		if ( self::plan_identity( self::object( $subject['plan_identity'] ?? null ) ) !== $bindings['plan_identity']
			|| ( $subject['materialization_receipt_sha256'] ?? null ) !== ( $bindings['materialization_receipt']['sha256'] ?? null ) ) {
			return 'evidence_subject_mismatch';
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function normalize_findings( string $id, mixed $value, array $sources ): array {
		$normalized = array();
		foreach ( array_slice( is_array( $value ) ? array_values( $value ) : array(), 0, 100 ) as $index => $finding ) {
			if ( ! is_array( $finding ) || ! in_array( $finding['status'] ?? null, array_diff( self::STATUSES, array( 'pass' ) ), true ) ) {
				continue;
			}
			$affected = self::object( $finding['affected'] ?? null );
			$evidence = self::object( $finding['evidence'] ?? null );
			if ( ! self::bounded_string( $affected['route'] ?? null, 2048 )
				|| ! self::bounded_string( $affected['component'] ?? null, 256 )
				|| ! self::bounded_string( $finding['reason_code'] ?? null, 128 )
				|| ! self::bounded_string( $finding['summary'] ?? null, 2048 )
				|| ! self::bounded_string( $finding['next_action'] ?? null, 2048 )
				|| ! self::reference_in( $evidence, $sources ) ) {
				continue;
			}
			$normalized[] = self::finding( $id, (string) $finding['status'], (string) $affected['route'], (string) $affected['component'], (string) $finding['reason_code'], (string) $finding['summary'], (string) $finding['next_action'], $evidence, $index );
		}
		return $normalized;
	}

	/** @return array{dimension:array<string,mixed>,findings:array<int,array<string,mixed>>} */
	private static function gap( string $id, string $reason ): array {
		$evidence = self::missing_reference( $id, $reason );
		$finding  = self::finding( $id, 'evidence_gap', '*', $id, $reason, 'Mandatory owner-handoff evidence is not proven.', self::GAP_ACTIONS[ $id ], $evidence, 0 );
		return array(
			'dimension' => self::dimension( $id, 'evidence_gap', $evidence, array( $finding['id'] ) ),
			'findings'  => array( $finding ),
		);
	}

	/** @return array<string,mixed> */
	private static function dimension( string $id, string $status, array $evidence, array $finding_ids = array(), array $operations = array() ): array {
		$row = array(
			'dimension'         => $id,
			'status'            => $status,
			'mandatory'         => true,
			'evidence'          => $evidence,
			'owning_repository' => self::OWNERS[ $id ],
			'finding_ids'       => array_values( $finding_ids ),
		);
		if ( array() !== $operations ) {
			$row['operations'] = $operations;
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private static function finding( string $id, string $status, string $route, string $component, string $reason, string $summary, string $action, array $evidence, int $index ): array {
		return array(
			'id'                => $id . ':' . $index . ':' . $reason,
			'dimension'         => $id,
			'status'            => $status,
			'reason_code'       => $reason,
			'affected'          => array( 'route' => $route, 'component' => $component ),
			'evidence'          => $evidence,
			'owning_repository' => self::OWNERS[ $id ],
			'next_action'       => $action,
			'summary'           => $summary,
		);
	}

	/** @return array<int,array<string,string>> */
	private static function source_references( mixed $value, array $bindings, string $id ): array {
		$references = array();
		foreach ( array_slice( is_array( $value ) ? array_values( $value ) : array(), 0, 32 ) as $reference ) {
			$artifact      = self::object( is_array( $reference ) ? ( $reference['artifact'] ?? null ) : null );
			$artifact_hash = self::try_hash_value( $artifact );
			$subject       = self::object( $artifact['subject'] ?? null );
			if ( is_array( $reference )
				&& self::SOURCE_EVIDENCE_SCHEMA === ( $reference['schema'] ?? null )
				&& ( $artifact['schema'] ?? null ) === $reference['schema']
				&& $id === ( $artifact['dimension'] ?? null )
				&& in_array( $artifact['status'] ?? null, self::STATUSES, true )
				&& null !== $artifact_hash
				&& self::is_sha256( $reference['sha256'] ?? null )
				&& hash_equals( (string) $reference['sha256'], $artifact_hash )
				&& self::plan_identity( self::object( $subject['plan_identity'] ?? null ) ) === ( $bindings['plan_identity'] ?? null )
				&& ( $subject['materialization_receipt_sha256'] ?? null ) === ( $bindings['materialization_receipt']['sha256'] ?? null ) ) {
				$references[] = array( 'schema' => $reference['schema'], 'sha256' => $reference['sha256'] );
			}
		}
		return $references;
	}

	private static function source_status( mixed $value ): string {
		$statuses = array();
		foreach ( array_slice( is_array( $value ) ? array_values( $value ) : array(), 0, 32 ) as $reference ) {
			$artifact = self::object( is_array( $reference ) ? ( $reference['artifact'] ?? null ) : null );
			$statuses[] = (string) ( $artifact['status'] ?? 'evidence_gap' );
		}
		foreach ( array( 'hard_failure', 'evidence_gap', 'owner_decision', 'acceptable_conversion', 'informational' ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return array() === $statuses ? 'evidence_gap' : 'pass';
	}

	private static function reference_in( array $reference, array $sources ): bool {
		return in_array( $reference, $sources, true );
	}

	/** @return array<string,string> */
	private static function missing_reference( string $id, string $reason ): array {
		return array(
			'schema'      => self::DIMENSION_EVIDENCE_SCHEMA,
			'state'       => 'missing',
			'dimension'   => $id,
			'reason_code' => $reason,
		);
	}

	/** @return array<string,mixed> */
	private static function receipt_reference( array $bindings, string $selector = '' ): array {
		$reference = array(
			'schema' => (string) ( $bindings['materialization_receipt']['schema'] ?? '' ),
			'sha256' => (string) ( $bindings['materialization_receipt']['sha256'] ?? '' ),
		);
		if ( '' !== $selector ) {
			$reference['selector'] = $selector;
		}
		return $reference;
	}

	private static function aggregate_status( array $findings ): string {
		$statuses = array_column( $findings, 'status' );
		foreach ( array( 'hard_failure', 'evidence_gap', 'owner_decision', 'acceptable_conversion', 'informational' ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return 'pass';
	}

	/** @return array<string,int> */
	private static function counts( array $dimensions ): array {
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $dimensions as $dimension ) {
			$status = is_array( $dimension ) ? (string) ( $dimension['status'] ?? '' ) : '';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		return $counts;
	}

	private static function disposition( array $counts, array $bindings ): string {
		if ( $counts['hard_failure'] > 0 ) {
			return 'blocked';
		}
		if ( ! self::bindings_verified( $bindings ) || $counts['evidence_gap'] > 0 ) {
			return 'not_proven';
		}
		return $counts['owner_decision'] > 0 ? 'needs_owner' : 'ready';
	}

	private static function bindings_verified( array $bindings ): bool {
		return true === ( $bindings['verified'] ?? false );
	}

	private static function gap_reason( string $id ): string {
		return match ( $id ) {
			'visual_acceptance' => 'desktop_mobile_visual_evidence_missing',
			'editability_and_shared_regions' => 'editability_shared_region_evidence_missing',
			'editor_presentation_and_persistence' => 'editor_presentation_persistence_evidence_missing',
			'media_library_ownership' => 'media_ownership_evidence_missing',
			'link_portability' => 'link_inventory_evidence_missing',
			'interaction_and_provider_functionality' => 'provider_functionality_evidence_missing',
			'document_metadata_and_identity' => 'rendered_metadata_identity_evidence_missing',
			'accessibility' => 'accessibility_evidence_missing',
			'frontend_performance' => 'performance_evidence_missing',
			'dependency_deployment_rollback' => 'deployment_rollback_evidence_missing',
			'owner_task_check' => 'owner_task_evidence_missing',
			default => 'route_content_evidence_missing',
		};
	}

	private static function headline( string $disposition ): string {
		return match ( $disposition ) {
			'ready' => 'Ready for ordinary ownership.',
			'needs_owner' => 'Owner decisions required before ordinary ownership.',
			'blocked' => 'Hard failures block accepted/built handoff.',
			default => 'Mandatory evidence is missing; handoff is not proven.',
		};
	}

	private static function valid_plan_identity( array $identity ): bool {
		return 'blocks-engine/wordpress-site-plan-identity/v1' === ( $identity['schema'] ?? null ) && self::is_sha256( $identity['hash'] ?? null );
	}

	/** @return array<string,string>|null */
	private static function plan_identity( array $identity ): ?array {
		return self::valid_plan_identity( $identity ) ? array( 'schema' => $identity['schema'], 'hash' => $identity['hash'] ) : null;
	}

	private static function is_sha256( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	private static function bounded_string( mixed $value, int $maximum ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $maximum;
	}

	/**
	 * Feed canonical JSON into a digest without allocating an encoded receipt.
	 *
	 * @param HashContext $context Hash context.
	 * @param mixed       $value   JSON-compatible value.
	 */
	private static function hash_json_value( HashContext $context, mixed $value ): void {
		if ( ! is_array( $value ) ) {
			if ( is_object( $value ) || is_resource( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
				throw new InvalidArgumentException( 'Owner-handoff hashes require JSON-compatible arrays and scalar values.' );
			}
			$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				throw new InvalidArgumentException( 'Owner-handoff hashes require valid UTF-8 JSON values.' );
			}
			hash_update( $context, $encoded );
			return;
		}

		$list = array_is_list( $value );
		hash_update( $context, $list ? '[' : '{' );
		$keys = array_keys( $value );
		if ( ! $list ) {
			sort( $keys, SORT_STRING );
		}
		foreach ( $keys as $index => $key ) {
			if ( $index > 0 ) {
				hash_update( $context, ',' );
			}
			if ( ! $list ) {
				$encoded_key = json_encode( (string) $key, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $encoded_key ) ) {
					throw new InvalidArgumentException( 'Owner-handoff hashes require valid UTF-8 JSON object keys.' );
				}
				hash_update( $context, $encoded_key . ':' );
			}
			self::hash_json_value( $context, $value[ $key ] );
		}
		hash_update( $context, $list ? ']' : '}' );
	}

	private static function try_hash_value( mixed $value ): ?string {
		try {
			return self::hash_value( $value );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/** @return array<string,mixed> */
	private static function object( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}
}
