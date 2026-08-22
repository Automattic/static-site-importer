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

print "quality budget admission smoke passed\n";
