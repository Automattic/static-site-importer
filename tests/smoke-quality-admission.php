<?php
/**
 * Quality-admission contract coverage.
 *
 * Run: php tests/smoke-quality-admission.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-admission.php';

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$plan = array(
	'schema'      => 'blocks-engine/wordpress-site-plan/v2',
	'quality'     => array( 'metrics' => array( 'block_count' => 3, 'fallback_count' => 1 ) ),
	'pages'       => array(
		array( 'resolved_block_markup' => '<!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph --><!-- wp:html --><iframe></iframe><!-- /wp:html -->' ),
	),
	'template_parts' => array(),
	'writes'       => array(
		array( 'kind' => 'theme_bootstrap', 'payload' => array( 'encoding' => 'plain', 'data' => 'bootstrap' ) ),
	),
	'assets'       => array( array( 'target_path' => 'assets/site.css' ), array( 'target_path' => 'assets/logo.svg' ) ),
);
$report = array(
	'asset_map'         => array( 'unresolved_count' => 0 ),
	'visual_fidelity'   => array( 'status' => 'passed' ),
	'editor_fidelity'   => array( 'status' => 'passed' ),
	'diagnostics'       => array(),
);

$pass = Static_Site_Importer_Quality_Admission::evaluate(
	$plan,
	array( 'quality_admission' => array( 'mode' => 'production_ready', 'budgets' => array( 'max_raw_html_fallback_count' => 1, 'max_theme_bootstrap_bytes' => 9, 'max_stylesheet_asset_count' => 1 ) ) ),
	$report
);
$assert( 'passed' === $pass['status'] && 'completed' === $pass['mechanical_status'], 'production-ready-pass' );
$assert( 1 === $pass['metrics']['native_block_count'] && 1 === $pass['metrics']['raw_html_fallback_count'] && array( 'core_html' => 1 ) === $pass['metrics']['raw_html_fallback_families'], 'native-and-raw-html-metrics' );
$assert( 9 === $pass['metrics']['theme_bootstrap_bytes'] && 1 === $pass['metrics']['stylesheet_asset_count'], 'asset-budget-metrics' );

$fail = Static_Site_Importer_Quality_Admission::evaluate( $plan, array( 'quality_admission' => array( 'mode' => 'production_ready', 'budgets' => array( 'max_raw_html_fallback_count' => 0 ) ) ), $report );
$assert( 'hard_budget_failed' === $fail['status'] && 'max_raw_html_fallback_count' === ( $fail['failures'][0]['budget'] ?? '' ), 'hard-budget-fail' );

$preview = Static_Site_Importer_Quality_Admission::evaluate( $plan, array( 'quality_admission' => array( 'mode' => 'preview', 'budgets' => array( 'max_raw_html_fallback_count' => 0 ) ) ), $report );
$assert( 'preview' === $preview['status'] && 'hard_budget_failed' === $preview['production_ready'], 'preview-preserves-failure-evidence' );

$unknown = Static_Site_Importer_Quality_Admission::evaluate( array( 'pages' => $plan['pages'], 'writes' => array(), 'assets' => array() ) );
$assert( 'unknown' === $unknown['status'] && 'unknown' === $unknown['evidence']['canonical_plan'] && 'unknown' === $unknown['evidence']['visual'], 'missing-evidence-is-unknown' );

$receipt = array( 'schema' => 'static-site-importer/materialization-receipt/v2', 'status' => 'completed', 'quality_admission' => $pass );
$encoded = json_encode( $receipt );
$persisted = json_decode( false === $encoded ? '' : $encoded, true );
$assert( Static_Site_Importer_Quality_Admission::SCHEMA === ( $persisted['quality_admission']['schema'] ?? '' ) && 'passed' === ( $persisted['quality_admission']['status'] ?? '' ), 'receipt-persistence' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: quality admission smoke passed (' . $assertions . " assertions)\n";
