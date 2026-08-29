<?php
/**
 * Hash-bound owner-handoff evidence and import report card.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes existing owning-layer receipts into owner-handoff evidence/v1.
 */
final class Static_Site_Importer_Owner_Handoff_Evidence {

	public const SCHEMA                = 'static-site-importer/owner-handoff-evidence/v1';
	public const PLAN_IDENTITY_SCHEMA  = 'blocks-engine/wordpress-site-plan-identity/v1';
	public const OWNER_TASK_SCHEMA     = 'static-site-importer/owner-task-check/v1';

	public const DIMENSION_IDS = array(
		'route_content_completeness',
		'visual_acceptance',
		'editability_shared_regions',
		'editor_presentation_persistence',
		'media_library_ownership',
		'link_portability',
		'provider_functionality',
		'site_identity_metadata',
		'accessibility',
		'frontend_performance',
		'deployment_rollback',
		'owner_tasks',
	);

	public const OWNER_TASK_IDS = array(
		'text_edit',
		'image_replace',
		'navigation_edit',
		'shared_footer_edit',
		'form_recipient_edit',
	);

	public const STATUSES = array(
		'pass',
		'hard_failure',
		'required_owner_decision',
		'acceptable_conversion',
		'informational',
		'evidence_gap',
	);

	private const RANK = array(
		'hard_failure'             => 5,
		'evidence_gap'             => 4,
		'required_owner_decision'  => 3,
		'acceptable_conversion'    => 2,
		'informational'            => 1,
		'pass'                     => 0,
	);

	private const OWNERS = array(
		'route_content_completeness'        => 'static-site-importer',
		'visual_acceptance'                 => 'static-site-importer',
		'editability_shared_regions'        => 'blocks-engine',
		'editor_presentation_persistence'   => 'static-site-importer',
		'media_library_ownership'           => 'static-site-importer',
		'link_portability'                  => 'static-site-importer',
		'provider_functionality'            => 'static-site-importer',
		'site_identity_metadata'            => 'static-site-importer',
		'accessibility'                     => 'static-site-importer',
		'frontend_performance'              => 'static-site-importer',
		'deployment_rollback'               => 'static-site-importer',
		'owner_tasks'                       => 'static-site-importer',
	);

	private const ACTIONS = array(
		'route_content_completeness'        => 'Supply a completed materialization receipt bound to the canonical plan hash.',
		'visual_acceptance'                 => 'Provide desktop and mobile visual acceptance evidence for every materialized route.',
		'editability_shared_regions'        => 'Admit a hash-bound Blocks Engine editability report with shared-region ownership.',
		'editor_presentation_persistence'   => 'Provide editor presentation coverage and persisted edit/save/reload evidence.',
		'media_library_ownership'           => 'Import replaceable media as WordPress attachments with stable Media Library IDs.',
		'link_portability'                  => 'Prove internal-link rewrites and inventory remaining external URLs.',
		'provider_functionality'            => 'Record provider-functionality receipts, including a successful form submission.',
		'site_identity_metadata'            => 'Materialize document titles, canonical metadata, site identity, and unresolved placeholders.',
		'accessibility'                     => 'Provide keyboard and accessibility evidence for the generated frontend.',
		'frontend_performance'              => 'Provide bounded generated-frontend performance evidence.',
		'deployment_rollback'               => 'Record dependency, deployment, and rollback readiness on the materialization receipt.',
		'owner_tasks'                       => 'Prove text, image, navigation, shared-footer, and form-recipient edits with save/reload validation.',
	);

	/**
	 * @param array<string,mixed> $input Evidence bag.
	 * @return array<string,mixed>
	 */
	public static function compose( array $input ): array {
		$plan         = self::plan_identity( $input['plan_identity'] ?? null );
		$receipt      = self::object( $input['materialization_receipt'] ?? null );
		$receipt_hash = self::receipt_hash( $input, $receipt );
		$supplied     = self::object( $input['dimensions'] ?? $input['evidence'] ?? null );
		$dimensions   = array();
		$findings     = array();
		$owner_tasks  = null;
		foreach ( self::DIMENSION_IDS as $id ) {
			$row          = self::project_dimension( $id, $supplied[ $id ] ?? null, $receipt );
			$dimensions[] = array(
				'id'                 => $id,
				'status'             => $row['status'],
				'owning_repository'  => $row['owning_repository'],
				'evidence_reference' => $row['evidence_reference'],
			);
			foreach ( $row['findings'] as $finding ) {
				$findings[] = $finding;
			}
			if ( 'owner_tasks' === $id ) {
				$owner_tasks = $row['owner_tasks'];
			}
		}
		$worst = 'pass';
		foreach ( $dimensions as $dimension ) {
			$worst = self::worse( $worst, $dimension['status'] );
		}
		if ( ! self::valid_plan_identity( $plan ) || ! self::sha256( $receipt_hash ) ) {
			$worst = self::worse( $worst, 'evidence_gap' );
			$findings[] = self::finding( 'route_content_completeness', 'evidence_gap', '', 'plan_identity', self::OWNERS['route_content_completeness'], 'Bind the report to a canonical plan identity hash and materialization receipt hash.', $plan );
		}
		$disposition = 'hard_failure' === $worst ? 'failed' : ( 'evidence_gap' === $worst ? 'not_proven' : ( 'required_owner_decision' === $worst ? 'owner_decisions_required' : 'passed' ) );
		$allowed     = ! in_array( $worst, array( 'hard_failure', 'evidence_gap' ), true );
		$document    = array(
			'schema'                         => self::SCHEMA,
			'plan_identity'                  => self::valid_plan_identity( $plan ) ? $plan : null,
			'materialization_receipt_sha256' => self::sha256( $receipt_hash ) ? $receipt_hash : null,
			'dimensions'                     => $dimensions,
			'findings'                       => $findings,
			'owner_tasks'                    => $owner_tasks,
			'disposition'                    => $disposition,
			'accepted_built_allowed'         => $allowed,
		);
		$document['report_card'] = self::render_report_card( $document );
		return $document;
	}

	/**
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report Import report.
	 * @param array<string,mixed> $quality Finalized quality gate.
	 * @return array<string,mixed>
	 */
	public static function compose_from_import( array|object $report, array $quality = array() ): array {
		$data      = $report instanceof Static_Site_Importer_Import_Report ? $report->to_array() : self::object( $report );
		$receipt   = self::object( $data['materialization_receipt'] ?? null );
		$generated = self::object( $data['generated_theme'] ?? null );
		return self::compose(
			array(
				'plan_identity'           => $data['plan_identity'] ?? $receipt['plan_identity'] ?? null,
				'materialization_receipt' => $receipt,
				'dimensions'              => array(
					'visual_acceptance'               => $data['visual_fidelity'] ?? null,
					'editability_shared_regions'      => $receipt['editability_report'] ?? null,
					'editor_presentation_persistence' => $data['editor_presentation'] ?? $quality['editor_presentation'] ?? null,
					'media_library_ownership'         => $data['assets'] ?? null,
					'link_portability'                => $data['source_documents'] ?? null,
					'provider_functionality'          => $data['plugin_materialization'] ?? $data['commerce'] ?? null,
					'site_identity_metadata'          => $generated['document_metadata'] ?? null,
					'accessibility'                   => $data['accessibility'] ?? $quality['accessibility'] ?? null,
					'frontend_performance'            => $data['frontend_performance'] ?? $quality['frontend_performance'] ?? null,
					'deployment_rollback'             => $receipt['rollback'] ?? null,
					'owner_tasks'                     => $data['owner_tasks'] ?? $quality['owner_tasks'] ?? null,
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $document Owner-handoff document.
	 */
	public static function accepted_built_allowed( array $document ): bool {
		return self::SCHEMA === ( $document['schema'] ?? '' ) && true === ( $document['accepted_built_allowed'] ?? false );
	}

	/**
	 * @param array<string,mixed> $document Owner-handoff document.
	 * @return array<string,mixed>
	 */
	public static function consume( array $document ): array {
		$allowed = self::accepted_built_allowed( $document );
		return array(
			'schema'                 => self::SCHEMA,
			'disposition'            => (string) ( $document['disposition'] ?? 'not_proven' ),
			'accepted_built_allowed' => $allowed,
			'reasons'                => $allowed ? array() : array_values(
				array_map(
					static fn( array $finding ): array => array(
						'code'     => (string) ( $finding['status'] ?? 'evidence_gap' ),
						'dimension' => (string) ( $finding['dimension'] ?? '' ),
					),
					isset( $document['findings'] ) && is_array( $document['findings'] ) ? $document['findings'] : array()
				)
			),
		);
	}

	/**
	 * @param array<string,mixed> $document Owner-handoff document.
	 */
	public static function render_report_card( array $document ): string {
		$lines   = array(
			'# Owner handoff report card',
			'',
			'Disposition: `' . (string) ( $document['disposition'] ?? 'not_proven' ) . '`',
			'Accepted/built allowed: ' . ( ! empty( $document['accepted_built_allowed'] ) ? 'yes' : 'no' ),
			'',
			'## Dimensions',
		);
		foreach ( isset( $document['dimensions'] ) && is_array( $document['dimensions'] ) ? $document['dimensions'] : array() as $dimension ) {
			if ( ! is_array( $dimension ) ) {
				continue;
			}
			$lines[] = '- ' . (string) ( $dimension['id'] ?? '' ) . ': `' . (string) ( $dimension['status'] ?? 'evidence_gap' ) . '`';
		}
		$lines[] = '';
		$lines[] = '## Remaining actions';
		$findings = isset( $document['findings'] ) && is_array( $document['findings'] ) ? $document['findings'] : array();
		if ( array() === $findings ) {
			$lines[] = '- None.';
		}
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$scope = trim( (string) ( $finding['route'] ?? '' ) . ' ' . (string) ( $finding['component'] ?? '' ) );
			$lines[] = '- `' . (string) ( $finding['status'] ?? '' ) . '` ' . (string) ( $finding['dimension'] ?? '' ) . ( '' !== $scope ? ' (' . $scope . ')' : '' ) . ' — ' . (string) ( $finding['recommended_next_action'] ?? '' ) . ' (owning: ' . (string) ( $finding['owning_repository'] ?? '' ) . ')';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param mixed               $evidence Dimension evidence.
	 * @param array<string,mixed> $receipt  Materialization receipt.
	 * @return array<string,mixed>
	 */
	private static function project_dimension( string $id, mixed $evidence, array $receipt ): array {
		$owner     = self::OWNERS[ $id ];
		$action    = self::ACTIONS[ $id ];
		$reference = self::reference( $evidence );
		if ( 'route_content_completeness' === $id ) {
			$evidence = array() !== $receipt ? $receipt : $evidence;
			$reference = self::reference( $evidence );
			$status    = self::receipt_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'materialization_receipt' );
		}
		if ( 'visual_acceptance' === $id ) {
			$status = self::visual_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'visual' );
		}
		if ( 'editability_shared_regions' === $id ) {
			$status = self::editability_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'editability_report' );
		}
		if ( 'editor_presentation_persistence' === $id ) {
			$status = self::editor_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'editor' );
		}
		if ( 'media_library_ownership' === $id ) {
			$status = self::media_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'media_library' );
		}
		if ( 'link_portability' === $id ) {
			$status = self::link_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'links' );
		}
		if ( 'provider_functionality' === $id ) {
			$status = self::provider_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'provider' );
		}
		if ( 'site_identity_metadata' === $id ) {
			$status = self::identity_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'site_identity' );
		}
		if ( 'accessibility' === $id || 'frontend_performance' === $id ) {
			$status = self::gated_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', $id );
		}
		if ( 'deployment_rollback' === $id ) {
			$evidence  = is_array( $evidence ) ? $evidence : ( $receipt['rollback'] ?? null );
			$reference = self::reference( $evidence );
			$status    = self::rollback_status( $evidence );
			return self::dimension( $id, $status, $owner, $action, $reference, '', 'rollback' );
		}
		$owner_tasks = self::owner_tasks( $evidence );
		return array(
			'status'             => $owner_tasks['status'],
			'owning_repository'  => $owner,
			'evidence_reference' => $reference,
			'findings'           => self::owner_task_findings( $owner_tasks, $owner, $action, $reference ),
			'owner_tasks'        => $owner_tasks,
		);
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function receipt_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( ! in_array( $row['schema'] ?? '', array( 'static-site-importer/materialization-receipt/v1', 'static-site-importer/materialization-receipt/v2' ), true ) ) {
			return 'evidence_gap';
		}
		if ( 'failed' === ( $row['status'] ?? '' ) ) {
			return 'hard_failure';
		}
		return 'completed' === ( $row['status'] ?? '' ) ? 'pass' : 'evidence_gap';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function visual_status( mixed $evidence ): string {
		$row     = self::object( $evidence );
		$desktop = self::viewport_status( $row['desktop'] ?? $row['viewports']['desktop'] ?? null );
		$mobile  = self::viewport_status( $row['mobile'] ?? $row['viewports']['mobile'] ?? null );
		if ( null === $desktop || null === $mobile ) {
			return 'evidence_gap';
		}
		return self::worse( $desktop, $mobile );
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function viewport_status( mixed $evidence ): ?string {
		$row = self::object( $evidence );
		if ( array() === $row ) {
			return null;
		}
		$status = self::normalize_pass_fail( $row['status'] ?? null );
		return null === $status ? 'evidence_gap' : $status;
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function editability_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( 'static-site-importer/editability-report-admission/v1' !== ( $row['schema'] ?? '' ) && 'blocks-engine/php-transformer/editability-report/v2' !== ( $row['schema'] ?? '' ) ) {
			return 'evidence_gap';
		}
		if ( in_array( $row['status'] ?? '', array( 'rejected', 'failed' ), true ) ) {
			return 'hard_failure';
		}
		return 'passed' === ( $row['status'] ?? '' ) ? 'pass' : 'evidence_gap';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function editor_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		$presentation = self::object( $row['editor_presentation'] ?? $row );
		$persistence  = self::object( $row['persistence'] ?? $row['editor_persistence'] ?? null );
		$coverage     = $presentation['coverage_complete'] ?? null;
		if ( ! in_array( $presentation['schema'] ?? '', array( 'static-site-importer/editor-presentation-evidence/v1', 'static-site-importer/editor-presentation-evidence/v2' ), true ) || null === $coverage ) {
			return 'evidence_gap';
		}
		if ( true !== $coverage ) {
			return 'hard_failure';
		}
		if ( array() === $persistence ) {
			return 'evidence_gap';
		}
		if ( ! empty( $persistence['persisted'] ) && ! empty( $persistence['reloaded'] ) ) {
			return 'pass';
		}
		return false === ( $persistence['persisted'] ?? null ) || false === ( $persistence['reloaded'] ?? null ) ? 'hard_failure' : 'evidence_gap';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function media_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( ! array_key_exists( 'attachment_count', $row ) || ! array_key_exists( 'replaceable_media_count', $row ) ) {
			return 'evidence_gap';
		}
		$attachments = (int) $row['attachment_count'];
		$replaceable = (int) $row['replaceable_media_count'];
		if ( $replaceable > 0 && $attachments < 1 ) {
			return 'hard_failure';
		}
		return $attachments >= 0 && $replaceable >= 0 ? 'pass' : 'evidence_gap';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function link_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( ! array_key_exists( 'unresolved_internal_count', $row ) || ! array_key_exists( 'external_inventory', $row ) || ! is_array( $row['external_inventory'] ) ) {
			if ( array_key_exists( 'unresolved_internal_count', $row ) && (int) $row['unresolved_internal_count'] > 0 ) {
				return 'hard_failure';
			}
			return 'evidence_gap';
		}
		return (int) $row['unresolved_internal_count'] > 0 ? 'hard_failure' : 'pass';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function provider_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		$receipts = isset( $row['receipts'] ) && is_array( $row['receipts'] ) ? $row['receipts'] : null;
		if ( ! is_array( $receipts ) ) {
			return 'evidence_gap';
		}
		foreach ( $receipts as $item ) {
			$status = self::normalize_pass_fail( is_array( $item ) ? ( $item['status'] ?? null ) : null );
			if ( 'hard_failure' === $status ) {
				return 'hard_failure';
			}
			if ( 'pass' !== $status ) {
				return 'evidence_gap';
			}
		}
		return 'pass';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function identity_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		$title = isset( $row['title'] ) && is_scalar( $row['title'] ) ? trim( (string) $row['title'] ) : '';
		if ( '' === $title || ! array_key_exists( 'placeholders', $row ) || ! is_array( $row['placeholders'] ) ) {
			return 'evidence_gap';
		}
		if ( ! empty( $row['environment_dependent_urls'] ) || array() !== $row['placeholders'] ) {
			return 'required_owner_decision';
		}
		return 'pass';
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function gated_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( empty( $row['schema'] ) || ! is_string( $row['schema'] ) ) {
			return 'evidence_gap';
		}
		$status = self::normalize_pass_fail( $row['status'] ?? null );
		return null === $status ? 'evidence_gap' : $status;
	}

	/**
	 * @param mixed $evidence Evidence.
	 */
	private static function rollback_status( mixed $evidence ): string {
		$row = self::object( $evidence );
		if ( empty( $row['status'] ) || ! is_string( $row['status'] ) ) {
			return 'evidence_gap';
		}
		if ( 'partial' === $row['status'] ) {
			return 'hard_failure';
		}
		return in_array( $row['status'], array( 'not_requested', 'rolled_back', 'ready' ), true ) ? 'pass' : 'evidence_gap';
	}

	/**
	 * @param mixed $evidence Evidence.
	 * @return array<string,mixed>
	 */
	private static function owner_tasks( mixed $evidence ): array {
		$row   = self::object( $evidence );
		$tasks = array();
		$worst = 'pass';
		$index = isset( $row['tasks'] ) && is_array( $row['tasks'] ) ? $row['tasks'] : $row;
		foreach ( self::OWNER_TASK_IDS as $id ) {
			$item   = is_array( $index[ $id ] ?? null ) ? $index[ $id ] : self::task_row( $index, $id );
			$status = self::task_status( $item );
			$worst  = self::worse( $worst, $status );
			$tasks[] = array(
				'id'        => $id,
				'status'    => $status,
				'persisted' => is_array( $item ) && array_key_exists( 'persisted', $item ) ? (bool) $item['persisted'] : null,
				'reloaded'  => is_array( $item ) && array_key_exists( 'reloaded', $item ) ? (bool) $item['reloaded'] : null,
			);
		}
		return array(
			'schema' => self::OWNER_TASK_SCHEMA,
			'status' => $worst,
			'tasks'  => $tasks,
		);
	}

	/**
	 * @param array<string,mixed> $index Task list or map.
	 * @return array<string,mixed>|null
	 */
	private static function task_row( array $index, string $id ): ?array {
		foreach ( $index as $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? '' ) === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param mixed $item Task evidence.
	 */
	private static function task_status( mixed $item ): string {
		$row = self::object( $item );
		if ( array() === $row ) {
			return 'evidence_gap';
		}
		if ( array_key_exists( 'persisted', $row ) && array_key_exists( 'reloaded', $row ) ) {
			if ( true === $row['persisted'] && true === $row['reloaded'] ) {
				return 'pass';
			}
			if ( false === $row['persisted'] || false === $row['reloaded'] ) {
				return 'hard_failure';
			}
		}
		$status = self::normalize_pass_fail( $row['status'] ?? null );
		return null === $status ? 'evidence_gap' : $status;
	}

	/**
	 * @param array<string,mixed>      $owner_tasks Owner-task projection.
	 * @param array<string,mixed>|null $reference   Evidence reference.
	 * @return array<int,array<string,mixed>>
	 */
	private static function owner_task_findings( array $owner_tasks, string $owner, string $action, ?array $reference ): array {
		$findings = array();
		foreach ( $owner_tasks['tasks'] as $task ) {
			if ( 'pass' === ( $task['status'] ?? '' ) ) {
				continue;
			}
			$findings[] = self::finding( 'owner_tasks', (string) $task['status'], '', (string) $task['id'], $owner, $action, $reference );
		}
		return $findings;
	}

	/**
	 * @param array<string,mixed>|null $reference Evidence reference.
	 * @return array<string,mixed>
	 */
	private static function dimension( string $id, string $status, string $owner, string $action, ?array $reference, string $route, string $component ): array {
		$findings = array();
		if ( 'pass' !== $status ) {
			$findings[] = self::finding( $id, $status, $route, $component, $owner, $action, $reference );
		}
		return array(
			'status'             => $status,
			'owning_repository'  => $owner,
			'evidence_reference' => $reference,
			'findings'           => $findings,
			'owner_tasks'        => null,
		);
	}

	/**
	 * @param array<string,mixed>|null $reference Evidence reference.
	 * @return array<string,mixed>
	 */
	private static function finding( string $dimension, string $status, string $route, string $component, string $owner, string $action, ?array $reference ): array {
		return array(
			'dimension'               => $dimension,
			'status'                  => $status,
			'route'                   => $route,
			'component'               => $component,
			'evidence_reference'      => $reference,
			'owning_repository'       => $owner,
			'recommended_next_action' => $action,
		);
	}

	/**
	 * @param mixed $status Raw status.
	 */
	private static function normalize_pass_fail( mixed $status ): ?string {
		if ( ! is_string( $status ) || '' === $status ) {
			return null;
		}
		if ( in_array( $status, self::STATUSES, true ) ) {
			return $status;
		}
		if ( in_array( $status, array( 'passed', 'completed', 'success', 'ready' ), true ) ) {
			return 'pass';
		}
		if ( in_array( $status, array( 'failed', 'rejected', 'mismatch', 'error' ), true ) ) {
			return 'hard_failure';
		}
		return null;
	}

	private static function worse( string $left, string $right ): string {
		return ( self::RANK[ $right ] ?? 0 ) > ( self::RANK[ $left ] ?? 0 ) ? $right : $left;
	}

	/**
	 * @param mixed $value Plan identity.
	 * @return array<string,mixed>
	 */
	private static function plan_identity( mixed $value ): array {
		$row = self::object( $value );
		return array(
			'schema' => (string) ( $row['schema'] ?? '' ),
			'hash'   => (string) ( $row['hash'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $identity Plan identity.
	 */
	private static function valid_plan_identity( array $identity ): bool {
		return self::PLAN_IDENTITY_SCHEMA === ( $identity['schema'] ?? '' ) && self::sha256( $identity['hash'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $input   Input bag.
	 * @param array<string,mixed> $receipt Receipt.
	 */
	private static function receipt_hash( array $input, array $receipt ): string {
		foreach ( array( $input['materialization_receipt_sha256'] ?? '', $receipt['sha256'] ?? '', $receipt['receipt_instance_id'] ?? '' ) as $candidate ) {
			if ( self::sha256( $candidate ) ) {
				return (string) $candidate;
			}
		}
		$identity  = is_array( $receipt['plan_identity'] ?? null ) ? $receipt['plan_identity'] : array();
		$plan_hash = (string) ( $identity['hash'] ?? '' );
		$schema    = (string) ( $receipt['schema'] ?? '' );
		$status    = (string) ( $receipt['status'] ?? '' );
		if ( '' === $schema && '' === $status && '' === $plan_hash ) {
			return '';
		}
		return hash( 'sha256', $schema . "\n" . $status . "\n" . $plan_hash );
	}

	/**
	 * @param mixed $evidence Evidence.
	 * @return array<string,mixed>|null
	 */
	private static function reference( mixed $evidence ): ?array {
		$row = self::object( $evidence );
		if ( array() === $row ) {
			return null;
		}
		$reference = array();
		if ( isset( $row['schema'] ) && is_string( $row['schema'] ) && '' !== $row['schema'] ) {
			$reference['schema'] = $row['schema'];
		}
		if ( isset( $row['path'] ) && is_string( $row['path'] ) && '' !== $row['path'] ) {
			$reference['path'] = $row['path'];
		}
		if ( self::sha256( $row['sha256'] ?? '' ) ) {
			$reference['sha256'] = (string) $row['sha256'];
		}
		return array() === $reference ? ( isset( $row['schema'] ) ? $reference : null ) : $reference;
	}

	private static function sha256( mixed $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private static function object( mixed $value ): array {
		return is_array( $value ) && ! array_is_list( $value ) ? $value : ( is_array( $value ) ? $value : array() );
	}
}
