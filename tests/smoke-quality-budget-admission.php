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

$materialized_counts = Static_Site_Importer_Quality_Budget_Admission::evaluate(
	array( 'quality' => array( 'metrics' => array( 'block_count' => 12, 'fallback_count' => 4 ) ) ),
	array(),
	array(),
	array( 'quality' => array( 'metrics' => array( 'block_count' => 12, 'fallback_count' => 4 ), 'block_count' => 18, 'core_html_block_count' => 3, 'fallback_count' => 2 ) )
);
$assert( 18 === $materialized_counts['evidence']['native_block_count'] && 3 === $materialized_counts['evidence']['core_html_block_count'] && 2 === $materialized_counts['evidence']['fallback_count'], 'materialized report counts override nested compiler estimates' );

print "quality budget admission smoke passed\n";
