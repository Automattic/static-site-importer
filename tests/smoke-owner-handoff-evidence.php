<?php
/**
 * Owner-handoff evidence contract smoke.
 *
 * php tests/smoke-owner-handoff-evidence.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-owner-handoff-evidence.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$hash = str_repeat( 'a', 64 );
$plan = array(
	'schema' => Static_Site_Importer_Owner_Handoff_Evidence::PLAN_IDENTITY_SCHEMA,
	'hash'   => $hash,
);
$receipt = array(
	'schema'        => 'static-site-importer/materialization-receipt/v2',
	'status'        => 'completed',
	'plan_identity' => $plan,
	'rollback'      => array( 'status' => 'not_requested' ),
);

$complete_tasks = array();
foreach ( Static_Site_Importer_Owner_Handoff_Evidence::OWNER_TASK_IDS as $id ) {
	$complete_tasks[ $id ] = array(
		'persisted' => true,
		'reloaded'  => true,
	);
}

$complete = array(
	'plan_identity'           => $plan,
	'materialization_receipt' => $receipt,
	'dimensions'              => array(
		'visual_acceptance'               => array(
			'desktop' => array( 'status' => 'passed' ),
			'mobile'  => array( 'status' => 'passed' ),
		),
		'editability_shared_regions'      => array(
			'schema' => 'static-site-importer/editability-report-admission/v1',
			'status' => 'passed',
		),
		'editor_presentation_persistence' => array(
			'schema'            => 'static-site-importer/editor-presentation-evidence/v2',
			'coverage_complete' => true,
			'persistence'       => array(
				'persisted' => true,
				'reloaded'  => true,
			),
		),
		'media_library_ownership'         => array(
			'attachment_count'        => 3,
			'replaceable_media_count' => 3,
		),
		'link_portability'                => array(
			'unresolved_internal_count' => 0,
			'external_inventory'        => array( 'https://example.com' ),
		),
		'provider_functionality'          => array(
			'receipts' => array(
				array( 'status' => 'passed' ),
			),
		),
		'site_identity_metadata'          => array(
			'title'        => 'Nimbus Commute',
			'placeholders' => array(),
		),
		'accessibility'                   => array(
			'schema' => 'static-site-importer/accessibility-evidence/v1',
			'status' => 'passed',
		),
		'frontend_performance'            => array(
			'schema' => 'static-site-importer/frontend-performance-evidence/v1',
			'status' => 'passed',
		),
		'deployment_rollback'             => array( 'status' => 'not_requested' ),
		'owner_tasks'                     => $complete_tasks,
	),
);

$passed = Static_Site_Importer_Owner_Handoff_Evidence::compose( $complete );
$assert( 'passed' === $passed['disposition'], 'complete evidence passes' );
$assert( true === $passed['accepted_built_allowed'], 'complete evidence allows accepted/built' );
$assert( true === Static_Site_Importer_Owner_Handoff_Evidence::accepted_built_allowed( $passed ), 'consume helper allows accepted/built' );
$assert( $hash === $passed['plan_identity']['hash'], 'plan identity hash is bound' );
$assert( 64 === strlen( (string) $passed['materialization_receipt_sha256'] ), 'receipt hash is bound' );
$assert( str_contains( $passed['report_card'], 'Accepted/built allowed: yes' ), 'report card renders pass' );
$assert( array() === $passed['findings'], 'passing report has no findings' );

$missing = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'plan_identity'           => $plan,
		'materialization_receipt' => $receipt,
	)
);
$assert( 'not_proven' === $missing['disposition'], 'missing dimensions are not proven' );
$assert( false === $missing['accepted_built_allowed'], 'missing dimensions cannot be accepted/built' );
$ids = array_column( $missing['dimensions'], 'id' );
$assert( Static_Site_Importer_Owner_Handoff_Evidence::DIMENSION_IDS === $ids, 'all mandatory dimensions are projected' );
foreach ( $missing['dimensions'] as $dimension ) {
	if ( 'route_content_completeness' === $dimension['id'] || 'deployment_rollback' === $dimension['id'] ) {
		$assert( 'pass' === $dimension['status'], $dimension['id'] . ' consumes receipt evidence' );
		continue;
	}
	$assert( 'evidence_gap' === $dimension['status'], $dimension['id'] . ' stays an evidence gap' );
}
foreach ( $missing['findings'] as $finding ) {
	$assert( isset( $finding['route'], $finding['component'], $finding['owning_repository'], $finding['recommended_next_action'] ), 'non-pass findings are complete' );
	$assert( 'evidence_gap' === $finding['status'], 'absence is not fabricated into a pass' );
}

$text_only = $complete;
$text_only['dimensions']['owner_tasks'] = array(
	'text_edit' => array(
		'persisted' => true,
		'reloaded'  => true,
	),
);
$text_only_document = Static_Site_Importer_Owner_Handoff_Evidence::compose( $text_only );
$assert( 'not_proven' === $text_only_document['disposition'], 'text persistence does not fabricate remaining owner tasks' );
$task_ids = array_column( $text_only_document['owner_tasks']['tasks'], 'id' );
$assert( Static_Site_Importer_Owner_Handoff_Evidence::OWNER_TASK_IDS === $task_ids, 'owner-task check covers the five required edits' );
foreach ( $text_only_document['owner_tasks']['tasks'] as $task ) {
	if ( 'text_edit' === $task['id'] ) {
		$assert( 'pass' === $task['status'], 'supplied text edit remains a pass' );
		continue;
	}
	$assert( 'evidence_gap' === $task['status'], $task['id'] . ' remains an evidence gap' );
}

$compat = $complete;
$compat['dimensions']['editability_shared_regions'] = array(
	'schema' => 'static-site-importer/editability-report-admission/v1',
	'status' => 'compatibility_policy_only',
);
$compat_document = Static_Site_Importer_Owner_Handoff_Evidence::compose( $compat );
$assert( 'not_proven' === $compat_document['disposition'], 'compatibility editability is not a pass' );

$desktop_only = $complete;
$desktop_only['dimensions']['visual_acceptance'] = array(
	'desktop' => array( 'status' => 'passed' ),
);
$desktop_document = Static_Site_Importer_Owner_Handoff_Evidence::compose( $desktop_only );
$assert( 'not_proven' === $desktop_document['disposition'], 'desktop-only visual evidence is a gap' );

$failed = $complete;
$failed['materialization_receipt']['status'] = 'failed';
$failed_document = Static_Site_Importer_Owner_Handoff_Evidence::compose( $failed );
$assert( 'failed' === $failed_document['disposition'], 'failed receipt is a hard failure' );
$assert( false === $failed_document['accepted_built_allowed'], 'hard failures block accepted/built' );

$decision = $complete;
$decision['dimensions']['site_identity_metadata']['placeholders'] = array( '{{PHONE}}' );
$decision_document = Static_Site_Importer_Owner_Handoff_Evidence::compose( $decision );
$assert( 'owner_decisions_required' === $decision_document['disposition'], 'placeholders are owner decisions' );
$assert( true === $decision_document['accepted_built_allowed'], 'owner decisions do not by themselves hard-fail' );

$unbound = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'dimensions' => $complete['dimensions'],
	)
);
$assert( 'not_proven' === $unbound['disposition'], 'missing hashes fail closed' );
$assert( false === $unbound['accepted_built_allowed'], 'unbound reports cannot be accepted/built' );

$import = Static_Site_Importer_Owner_Handoff_Evidence::compose_from_import(
	array(
		'plan_identity'           => $plan,
		'materialization_receipt' => $receipt,
		'visual_fidelity'         => array(
			'status' => 'requires_runtime_visual_parity_check',
		),
		'assets'                  => array(
			'policy' => 'theme',
		),
	)
);
$assert( 'not_proven' === $import['disposition'], 'import-time visual placeholder is a gap' );
$assert( str_contains( $import['report_card'], 'Accepted/built allowed: no' ), 'import report card stays fail-closed' );
$assert( false === Static_Site_Importer_Owner_Handoff_Evidence::consume( $import )['accepted_built_allowed'], 'consume blocks accepted/built on gaps' );

echo "owner-handoff evidence smoke passed\n";
