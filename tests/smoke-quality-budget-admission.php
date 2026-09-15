<?php
/** Run: php tests/smoke-quality-budget-admission.php */

require dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-budget-admission.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$plan = array(
	'quality' => array( 'metrics' => array( 'block_count' => 12, 'core_html_block_count' => 2, 'unresolved_media_count' => 0, 'unresolved_dependency_count' => 0 ) ),
	'diagnostics' => array( array( 'type' => 'core_html_block', 'tag_name' => 'table' ), array( 'type' => 'core_html_block', 'tag_name' => 'form' ) ),
);
$resolved = array( 'writes' => array( array( 'kind' => 'theme_bootstrap', 'payload' => array( 'data' => 'bootstrap' ) ), array( 'target_path' => 'assets/site.css' ) ) );
$pass = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved, array( 'quality_budget' => array( 'mode' => 'production', 'max_native_block_count' => 12, 'max_core_html_block_count' => 2, 'max_core_html_family_count' => 2, 'max_bootstrap_bytes' => 9, 'max_stylesheet_asset_count' => 1 ) ) );
$assert( 'passed' === $pass['production_status'] && ! Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $pass ), 'within explicit production budgets passes admission' );

$failed = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved, array( 'quality_budget' => array( 'mode' => 'production', 'max_core_html_block_count' => 1, 'max_unresolved_media_count' => 0 ) ) );
$assert( 'failed' === $failed['production_status'] && Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $failed ) && 'blocks-engine' === ( $failed['failures'][0]['repair_class'] ?? '' ), 'exceeded production evidence rejects with its owning repair class' );

$preview = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved, array( 'quality_budget' => array( 'max_core_html_block_count' => 1 ) ) );
$assert( 'preview' === $preview['status'] && 'failed' === $preview['production_status'] && ! Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $preview ), 'preview retains failing evidence without blocking materialization' );

$unknown = Static_Site_Importer_Quality_Budget_Admission::evaluate( array(), array(), array() );
$assert( 'not_proven' === $unknown['production_status'] && 'preview' === $unknown['status'], 'imports without budget evidence remain explicitly not proven' );

$source_fallback_plan = array( 'quality' => array( 'metrics' => array( 'fallback_count' => 2 ) ) );
$resolved_fallbacks = Static_Site_Importer_Quality_Budget_Admission::evaluate( $source_fallback_plan, array(), array( 'quality_budget' => array( 'mode' => 'production', 'max_fallback_count' => 0 ) ), array( 'quality' => array( 'fallback_count' => 0, 'source_fallback_count' => 2 ) ) );
$assert( 'passed' === $resolved_fallbacks['production_status'] && 0 === $resolved_fallbacks['evidence']['fallback_count'], 'zero-fallback admission uses the provider-reconciled materialized result' );
$unresolved_fallbacks = Static_Site_Importer_Quality_Budget_Admission::evaluate( $source_fallback_plan, array(), array( 'quality_budget' => array( 'mode' => 'production', 'max_fallback_count' => 0 ) ) );
$assert( 'failed' === $unresolved_fallbacks['production_status'] && 2 === $unresolved_fallbacks['evidence']['fallback_count'], 'unresolved provider-materializable fallbacks fail zero-fallback admission' );

// A provider-materializable island is only discounted once a provider has
// actually superseded it, named by fallback identity, hash and provider.
$form_identity   = str_repeat( 'a', 64 );
$form_hash       = str_repeat( 'b', 64 );
$binding         = array( 'fallback_reconciliation_identity' => $form_identity, 'fallback_hash' => $form_hash, 'provider' => 'jetpack-forms', 'role' => 'form' );
$single_fallback = array( 'quality' => array( 'metrics' => array( 'fallback_count' => 1 ) ) );
$zero_budget     = array( 'quality_budget' => array( 'mode' => 'production', 'max_fallback_count' => 0 ) );

$unproven = Static_Site_Importer_Quality_Budget_Admission::evaluate( $single_fallback, array(), $zero_budget );
$assert( 'failed' === $unproven['production_status'] && 1 === $unproven['evidence']['unresolved_fallback_count'] && 0 === $unproven['evidence']['provider_resolved_fallback_count'], 'a compiler fallback with no provider binding stays unresolved under a zero-fallback budget' );

$covered = Static_Site_Importer_Quality_Budget_Admission::evaluate( $single_fallback, array(), $zero_budget, array(), array( $binding ) );
$assert( 'passed' === $covered['production_status'] && 1 === $covered['evidence']['fallback_count'] && 0 === $covered['evidence']['unresolved_fallback_count'] && array( 'jetpack-forms' ) === $covered['evidence']['fallback_providers'], 'a fallback a provider superseded admits under a zero-fallback budget and names the provider' );

$duplicated = Static_Site_Importer_Quality_Budget_Admission::evaluate( array( 'quality' => array( 'metrics' => array( 'fallback_count' => 2 ) ) ), array(), $zero_budget, array(), array( $binding, $binding ) );
$assert( 'failed' === $duplicated['production_status'] && 1 === $duplicated['evidence']['unresolved_fallback_count'], 'one binding repeated cannot discount two fallbacks' );

foreach ( array(
	array( 'fallback_reconciliation_identity' => $form_identity, 'fallback_hash' => $form_hash, 'provider' => '' ),
	array( 'fallback_reconciliation_identity' => $form_identity, 'fallback_hash' => 'not-a-hash', 'provider' => 'jetpack-forms' ),
	array( 'fallback_reconciliation_identity' => '', 'fallback_hash' => $form_hash, 'provider' => 'jetpack-forms' ),
	array( 'provider' => 'jetpack-forms' ),
) as $index => $incomplete ) {
	$result = Static_Site_Importer_Quality_Budget_Admission::evaluate( $single_fallback, array(), $zero_budget, array(), array( $incomplete ) );
	$assert( 'failed' === $result['production_status'] && 1 === $result['evidence']['unresolved_fallback_count'], "binding {$index} without complete fallback provenance cannot discount a fallback" );
}

$over_resolved = Static_Site_Importer_Quality_Budget_Admission::evaluate( $single_fallback, array(), $zero_budget, array(), array( $binding, array( 'fallback_reconciliation_identity' => str_repeat( 'c', 64 ), 'fallback_hash' => $form_hash, 'provider' => 'jetpack-forms' ) ) );
$assert( 'passed' === $over_resolved['production_status'] && 0 === $over_resolved['evidence']['unresolved_fallback_count'], 'more bindings than fallbacks never drives the unresolved count below zero' );

$state_bindings = Static_Site_Importer_Quality_Budget_Admission::applied_entity_bindings( array( 'applied' => array( 'runtime_declarations' => array( 'entity_bindings' => array( 'one' => $binding ) ) ) ) );
$assert( array( 'one' => $binding ) === $state_bindings && array() === Static_Site_Importer_Quality_Budget_Admission::applied_entity_bindings( array() ), 'applied bindings read from materialization state, and an absent state contributes nothing' );

$materialized_counts = Static_Site_Importer_Quality_Budget_Admission::evaluate(
	array( 'quality' => array( 'metrics' => array( 'block_count' => 12, 'fallback_count' => 4 ) ) ),
	array(),
	array(),
	array( 'quality' => array( 'metrics' => array( 'block_count' => 12, 'fallback_count' => 4 ), 'block_count' => 18, 'core_html_block_count' => 3, 'fallback_count' => 2 ) )
);
$assert( 18 === $materialized_counts['evidence']['native_block_count'] && 3 === $materialized_counts['evidence']['core_html_block_count'] && 2 === $materialized_counts['evidence']['fallback_count'], 'materialized report counts override nested compiler estimates' );

print "quality budget admission smoke passed\n";
