<?php
/**
 * Owner-handoff evidence contract coverage.
 *
 * Run from the repository root:
 * php tests/smoke-owner-handoff-evidence.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0, int $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-owner-handoff-evidence.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$plan_identity = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => str_repeat( 'a', 64 ),
);
$receipt       = array(
	'schema'              => 'static-site-importer/materialization-receipt/v2',
	'status'              => 'completed',
	'plan_identity'       => $plan_identity,
	'receipt_instance_id' => str_repeat( 'b', 64 ),
	'completed'           => array(
		'materialized_pages' => array(
			'/' => array( 'post_id' => 7, 'content_hash' => str_repeat( 'c', 64 ) ),
		),
	),
	'editability_report'  => array(
		'schema'        => 'static-site-importer/editability-report-admission/v1',
		'status'        => 'passed',
		'report_schema' => 'blocks-engine/php-transformer/editability-report/v2',
		'plan_hash'     => $plan_identity['hash'],
	),
);
$receipt_hash  = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $receipt );
$source_for = static function ( string $id, string $status = 'pass' ) use ( $plan_identity, $receipt_hash ): array {
	$artifact = array(
		'schema'    => Static_Site_Importer_Owner_Handoff_Evidence::SOURCE_EVIDENCE_SCHEMA,
		'dimension' => $id,
		'status'    => $status,
		'subject'   => array(
			'plan_identity'                  => $plan_identity,
			'materialization_receipt_sha256' => $receipt_hash,
		),
	);
	return array(
		'schema'   => $artifact['schema'],
		'sha256'   => Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $artifact ),
		'artifact' => $artifact,
	);
};

$dimension_reference = static function ( string $id, string $status = 'pass', array $findings = array() ) use ( $plan_identity, $receipt_hash, $source_for ): array {
	$source           = $source_for( $id, $status );
	$source_reference = array( 'schema' => $source['schema'], 'sha256' => $source['sha256'] );
	foreach ( $findings as &$finding ) {
		$finding['evidence'] = $source_reference;
	}
	unset( $finding );
	$artifact = array(
		'schema'          => Static_Site_Importer_Owner_Handoff_Evidence::DIMENSION_EVIDENCE_SCHEMA,
		'dimension'       => $id,
		'subject'         => array(
			'plan_identity'                        => $plan_identity,
			'materialization_receipt_sha256'       => $receipt_hash,
		),
		'status'          => $status,
		'source_evidence' => array( $source ),
		'findings'        => $findings,
	);
	return array(
		'artifact' => $artifact,
		'sha256'   => Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $artifact ),
	);
};

$finding = static function ( string $status, string $reason, string $route = '/', string $component = 'hero' ): array {
	return array(
		'status'      => $status,
		'reason_code' => $reason,
		'affected'    => array( 'route' => $route, 'component' => $component ),
		'summary'     => 'Owning evidence reported a scoped result.',
		'next_action' => 'Resolve or acknowledge the owning evidence result.',
	);
};

$owner_task_reference = static function ( array $overrides = array() ) use ( $plan_identity, $receipt_hash, $source_for ): array {
	$source     = $source_for( 'owner_task_check' );
	$operations = array();
	foreach ( Static_Site_Importer_Owner_Handoff_Evidence::OWNER_TASKS as $task ) {
		$operations[ $task ] = array(
			'status'             => 'passed',
			'save_reload_status' => 'passed',
			'validation_status'  => 'passed',
			'before_sha256'      => hash( 'sha256', $task . ':before' ),
			'after_sha256'       => hash( 'sha256', $task . ':after' ),
			'evidence'           => array( $source ),
		);
	}
	$operations = array_replace( $operations, $overrides );
	$artifact   = array(
		'schema'     => Static_Site_Importer_Owner_Handoff_Evidence::OWNER_TASK_SCHEMA,
		'subject'    => array(
			'plan_identity'                  => $plan_identity,
			'materialization_receipt_sha256' => $receipt_hash,
		),
		'operations' => $operations,
	);
	return array(
		'artifact' => $artifact,
		'sha256'   => Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $artifact ),
	);
};

$complete_evidence = static function () use ( $dimension_reference, $owner_task_reference ): array {
	$evidence = array();
	foreach ( Static_Site_Importer_Owner_Handoff_Evidence::DIMENSION_IDS as $id ) {
		$evidence[ $id ] = 'owner_task_check' === $id ? $owner_task_reference() : $dimension_reference( $id );
	}
	return $evidence;
};

$compose = static function ( array $evidence, array $overrides = array() ) use ( $plan_identity, $receipt ): array {
	return Static_Site_Importer_Owner_Handoff_Evidence::compose(
		array_replace(
			array(
				'plan_identity'           => $plan_identity,
				'materialization_receipt' => $receipt,
				'evidence'                => $evidence,
			),
			$overrides
		)
	);
};

$empty = Static_Site_Importer_Owner_Handoff_Evidence::compose( array() );
$assert( Static_Site_Importer_Owner_Handoff_Evidence::SCHEMA === ( $empty['schema'] ?? '' ), 'empty-schema' );
$assert( 'not_proven' === ( $empty['disposition'] ?? '' ), 'empty-not-proven' );
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $empty ), 'empty-not-admitted' );
$assert( 12 === ( $empty['counts']['evidence_gap'] ?? 0 ), 'empty-all-gaps' );
$assert( false === ( $empty['bindings']['verified'] ?? true ), 'empty-bindings-unverified' );
$assert( 'plan_identity_missing_or_invalid' === ( $empty['bindings']['gaps'][0]['code'] ?? '' ), 'empty-binding-gap-typed' );

$ready_evidence = $complete_evidence();
$ready = $compose( $ready_evidence );
$assert( 'ready' === ( $ready['disposition'] ?? '' ), 'complete-ready' );
$assert( true === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $ready, $receipt, $ready_evidence ), 'complete-admitted' );
$assert( 12 === ( $ready['counts']['pass'] ?? 0 ), 'complete-all-pass' );
$assert( array() === ( $ready['findings'] ?? null ), 'complete-no-findings' );
$assert( $receipt_hash === ( $ready['bindings']['materialization_receipt']['sha256'] ?? '' ), 'complete-full-receipt-hash' );
$assert( $ready['evidence_sha256'] === ( $ready['report_card']['evidence_sha256'] ?? null ), 'report-card-hash-bound' );
$assert( 12 === count( $ready['report_card']['dimensions'] ?? array() ), 'report-card-dimension-rows' );
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( array( 'handoff_admitted' => true ) ), 'forged-admission-boolean-rejected' );
$tampered_ready = $ready;
$tampered_ready['counts']['pass'] = 11;
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $tampered_ready, $receipt, $ready_evidence ), 'tampered-admitted-document-rejected' );

$reordered_receipt = array_reverse( $receipt, true );
$assert( $receipt_hash === Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $reordered_receipt ), 'canonical-hash-key-order-independent' );
$mutated_receipt = $receipt;
$mutated_receipt['completed']['materialized_pages']['/']['post_id'] = 8;
$mutated = $compose( $complete_evidence(), array( 'materialization_receipt' => $mutated_receipt ) );
$assert( $receipt_hash !== ( $mutated['bindings']['materialization_receipt']['sha256'] ?? '' ), 'complete-receipt-mutation-changes-hash' );
$assert( 'evidence_gap' === ( $mutated['dimensions']['visual_acceptance']['status'] ?? '' ), 'old-evidence-rejected-after-receipt-mutation' );
$assert( 'evidence_subject_mismatch' === ( $mutated['findings'][1]['reason_code'] ?? '' ), 'receipt-mutation-has-subject-gap' );

$forged = $compose( array( 'visual_acceptance' => array( 'status' => 'passed' ) ) );
$assert( 'evidence_gap' === ( $forged['dimensions']['visual_acceptance']['status'] ?? '' ), 'bare-status-cannot-pass' );
$assert( 'evidence_schema_or_dimension_invalid' === ( $forged['findings'][1]['reason_code'] ?? '' ), 'bare-status-typed-gap' );

$tampered_evidence = $complete_evidence();
$tampered_evidence['visual_acceptance']['artifact']['status'] = 'hard_failure';
$tampered = $compose( $tampered_evidence );
$assert( 'evidence_gap' === ( $tampered['dimensions']['visual_acceptance']['status'] ?? '' ), 'tampered-artifact-cannot-pass' );
$assert( 'evidence_hash_mismatch' === ( $tampered['findings'][0]['reason_code'] ?? '' ), 'tampered-artifact-typed-gap' );

$wrong_plan = $plan_identity;
$wrong_plan['hash'] = str_repeat( 'e', 64 );
$mismatched = $compose( $complete_evidence(), array( 'plan_identity' => $wrong_plan ) );
$assert( false === ( $mismatched['bindings']['verified'] ?? true ), 'receipt-plan-mismatch-unverified' );
$assert( 'receipt_plan_identity_mismatch' === ( $mismatched['bindings']['gaps'][0]['code'] ?? '' ), 'receipt-plan-mismatch-typed' );
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $mismatched ), 'receipt-plan-mismatch-not-admitted' );

$visual_reference = $dimension_reference(
	'visual_acceptance',
	'hard_failure',
	array(
		$finding( 'informational', 'desktop_minor_difference', '/', 'header' ),
		$finding( 'hard_failure', 'mobile_visual_mismatch', '/pricing', 'footer' ),
	)
);
$visual_evidence = $complete_evidence();
$visual_evidence['visual_acceptance'] = $visual_reference;
$visual_fail = $compose( $visual_evidence );
$assert( 'blocked' === ( $visual_fail['disposition'] ?? '' ), 'visual-fail-blocked' );
$assert( 2 === count( $visual_fail['dimensions']['visual_acceptance']['finding_ids'] ?? array() ), 'multiple-scoped-findings-retained' );
$assert( '/pricing' === ( $visual_fail['findings'][1]['affected']['route'] ?? '' ), 'finding-route-retained' );
$assert( 'Automattic/static-site-importer' === ( $visual_fail['findings'][1]['owning_repository'] ?? '' ), 'owner-fixed-by-dimension' );

$owner_reference = $dimension_reference(
	'document_metadata_and_identity',
	'owner_decision',
	array( $finding( 'owner_decision', 'site_identity_confirmation_required', '/', 'site_identity' ) )
);
$owner_evidence = $complete_evidence();
$owner_evidence['document_metadata_and_identity'] = $owner_reference;
$needs_owner = $compose( $owner_evidence );
$assert( 'needs_owner' === ( $needs_owner['disposition'] ?? '' ), 'owner-decision-needs-owner' );
$assert( true === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $needs_owner, $receipt, $owner_evidence ), 'owner-decision-does-not-block-accepted-built' );

$missing_task = $owner_task_reference();
unset( $missing_task['artifact']['operations']['form_recipient_edit'] );
$missing_task['sha256'] = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $missing_task['artifact'] );
$task_evidence = $complete_evidence();
$task_evidence['owner_task_check'] = $missing_task;
$task_gap = $compose( $task_evidence );
$assert( 'evidence_gap' === ( $task_gap['dimensions']['owner_task_check']['status'] ?? '' ), 'missing-owner-task-is-gap' );
$assert( 'form_recipient_edit' === ( $task_gap['findings'][0]['affected']['component'] ?? '' ), 'missing-owner-task-component' );

$owner_source = $source_for( 'owner_task_check' );
$failed_operation = array(
	'status'             => 'passed',
	'save_reload_status' => 'failed',
	'validation_status'  => 'passed',
	'before_sha256'      => str_repeat( '1', 64 ),
	'after_sha256'       => str_repeat( '2', 64 ),
	'evidence'           => array( $owner_source ),
);
$failed_task = $owner_task_reference( array( 'text_edit' => $failed_operation ) );
$task_evidence['owner_task_check'] = $failed_task;
$task_fail = $compose( $task_evidence );
$assert( 'hard_failure' === ( $task_fail['dimensions']['owner_task_check']['status'] ?? '' ), 'failed-save-reload-is-hard-failure' );
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::admits_accepted_or_built( $task_fail ), 'failed-save-reload-not-admitted' );

$no_op_task = $owner_task_reference();
$no_op_task['artifact']['operations']['text_edit']['after_sha256'] = $no_op_task['artifact']['operations']['text_edit']['before_sha256'];
$no_op_task['sha256'] = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $no_op_task['artifact'] );
$task_evidence['owner_task_check'] = $no_op_task;
$no_op = $compose( $task_evidence );
$assert( 'evidence_gap' === ( $no_op['dimensions']['owner_task_check']['status'] ?? '' ), 'no-op-owner-task-is-gap' );

$unsupported_evidence = $complete_evidence();
$unsupported_evidence['visual_acceptance']['artifact']['source_evidence'][0]['schema'] = 'example/unsupported/v1';
$unsupported_evidence['visual_acceptance']['artifact']['source_evidence'][0]['artifact']['schema'] = 'example/unsupported/v1';
$unsupported_evidence['visual_acceptance']['artifact']['source_evidence'][0]['sha256'] = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $unsupported_evidence['visual_acceptance']['artifact']['source_evidence'][0]['artifact'] );
$unsupported_evidence['visual_acceptance']['sha256'] = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $unsupported_evidence['visual_acceptance']['artifact'] );
$unsupported = $compose( $unsupported_evidence );
$assert( 'evidence_gap' === ( $unsupported['dimensions']['visual_acceptance']['status'] ?? '' ), 'unsupported-source-schema-is-gap' );

$malformed_reference = $dimension_reference( 'visual_acceptance', 'hard_failure', array( $finding( 'hard_failure', 'mobile_visual_mismatch' ) ) );
unset( $malformed_reference['artifact']['findings'][0]['next_action'] );
$malformed_reference['sha256'] = Static_Site_Importer_Owner_Handoff_Evidence::hash_value( $malformed_reference['artifact'] );
$malformed_evidence = $complete_evidence();
$malformed_evidence['visual_acceptance'] = $malformed_reference;
$malformed = $compose( $malformed_evidence );
$assert( 'evidence_gap' === ( $malformed['dimensions']['visual_acceptance']['status'] ?? '' ), 'malformed-finding-is-gap' );

$unhashable_receipt = $receipt;
$unhashable_receipt['transaction'] = new stdClass();
$unhashable = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'plan_identity'           => $plan_identity,
		'materialization_receipt' => $unhashable_receipt,
	)
);
$assert( false === ( $unhashable['bindings']['verified'] ?? true ), 'unhashable-receipt-fails-closed' );
$assert( 'materialization_receipt_not_hashable' === ( $unhashable['bindings']['gaps'][0]['code'] ?? '' ), 'unhashable-receipt-typed-gap' );

$automatic = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'plan_identity'           => $plan_identity,
		'materialization_receipt' => $receipt,
	)
);
$assert( 'pass' === ( $automatic['dimensions']['editability_and_shared_regions']['status'] ?? '' ), 'verified-receipt-editability-consumed' );
$assert( 11 === ( $automatic['counts']['evidence_gap'] ?? 0 ), 'unsupported-aggregate-evidence-stays-gap' );

$compatibility_receipt = $receipt;
$compatibility_receipt['editability_report'] = array(
	'schema' => 'static-site-importer/editability-report-admission/v1',
	'status' => 'compatibility_policy_only',
);
$report = Static_Site_Importer_Import_Report::from_array(
	array(
		'schema'                  => Static_Site_Importer_Import_Report::SCHEMA,
		'status'                  => 'completed',
		'plan_identity'           => $plan_identity,
		'materialization_receipt' => $compatibility_receipt,
		'quality'                 => array( 'fallback_count' => 0, 'content_loss_count' => 0, 'empty_conversion_count' => 0 ),
		'source_documents'        => array( 'unresolved_link_count' => 0 ),
		'visual_fidelity'         => array( 'status' => 'requires_runtime_visual_parity_check' ),
	)
);
$from_report = Static_Site_Importer_Owner_Handoff_Evidence::compose_from_report( $report, $report->section( 'quality' ) );
$assert( 12 === ( $from_report['counts']['evidence_gap'] ?? 0 ), 'aggregate-zeroes-do-not-fabricate-passes' );
$assert( 'evidence_gap' === ( $from_report['dimensions']['link_portability']['status'] ?? '' ), 'unresolved-zero-is-not-external-inventory' );
$assert( 'evidence_gap' === ( $from_report['dimensions']['media_library_ownership']['status'] ?? '' ), 'theme-assets-are-not-media-ownership' );

$failed_receipt = $receipt;
$failed_receipt['status'] = 'failed';
$failed = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'plan_identity'           => $plan_identity,
		'materialization_receipt' => $failed_receipt,
	)
);
$assert( 'hard_failure' === ( $failed['dimensions']['route_content_completeness']['status'] ?? '' ), 'failed-materialization-is-hard-failure' );
$assert( 'blocked' === ( $failed['disposition'] ?? '' ), 'failed-materialization-blocks' );

$schema = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/contracts/owner-handoff-evidence-v1.schema.json' ), true );
$assert( is_array( $schema ) && Static_Site_Importer_Owner_Handoff_Evidence::SCHEMA === ( $schema['$id'] ?? '' ), 'json-schema-parses' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: owner handoff evidence smoke passed (' . $assertions . " assertions)\n";
