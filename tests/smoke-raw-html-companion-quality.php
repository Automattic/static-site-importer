<?php
/**
 * Raw-markup companions are reported without failing import quality.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone smoke-test bootstrap and assertions.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}
require_once ABSPATH . 'vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
if ( ! ssi_test_require_core_block_parser( $wp_root ) ) {
	throw new RuntimeException( 'WordPress parser unavailable; set STATIC_SITE_IMPORTER_WP_ROOT.' );
}
require_once ABSPATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once ABSPATH . 'includes/class-static-site-importer-block-document-reporter.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-budget-admission.php';
require_once ABSPATH . 'includes/class-static-site-importer-receipt-projection.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$definitions = array(
	array( 'block_json' => array( 'name' => 'example/declared', 'blocksEngine' => array( 'editingSurface' => 'raw_html' ) ) ),
	array( 'block_json' => array( 'name' => 'example/responsive-layout' ) ),
);
$documents = array(
	array(
		'path'    => 'posts/page-home.post_content',
		'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:example/declared {"content":"Hello"} /--><!-- wp:example/responsive-layout /--></div><!-- /wp:group -->',
	),
);
$report = Static_Site_Importer_Import_Report::from_array( array( 'quality' => array( 'raw_html_companion_block_count' => 99 ) ) );
// Analyze twice to prove materialized reanalysis replaces counts and diagnostics.
Static_Site_Importer_Block_Document_Reporter::analyze_materialized_block_documents( $documents, $report, $definitions );
Static_Site_Importer_Block_Document_Reporter::analyze_materialized_block_documents( $documents, $report, $definitions );
$quality = Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, array( 'fail_on_quality' => true ) );
$assert( 1 === $report['quality']['raw_html_companion_block_count'], 'Only declared raw-markup companion instances count.' );
$assert( 0 === $report['quality']['core_html_block_count'], 'Companions are not core/html.' );
$diagnostics = array_values( array_filter( $report['diagnostics'], static fn( $row ) => 'raw_html_companion_block' === ( $row['type'] ?? '' ) ) );
$assert( 1 === count( $diagnostics ), 'Exactly one companion diagnostic survives reanalysis.' );
$diagnostic = $diagnostics[0];
$assert( 'example/declared' === $diagnostic['block_name'] && '0.0' === $diagnostic['block_path'], 'Diagnostic identifies the nested companion.' );
$assert( $documents[0]['path'] === $diagnostic['source'] && 'generated_theme_block_analysis' === $diagnostic['stage'], 'Diagnostic identifies the document and stage.' );
$assert( true === $quality['pass'] && false === $quality['fail_import'] && array() === $quality['failure_reasons'], 'Reported companions do not fail quality.' );
$admission = Static_Site_Importer_Quality_Budget_Admission::evaluate( array(), array(), array( 'quality_budget' => array( 'mode' => 'production', 'max_core_html_block_count' => 0, 'max_fallback_count' => 0 ) ), $report->to_array() );
$assert( ! Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $admission ), 'Companions do not fail quality budget admission.' );
// Theme-owned runtimes carry block.json in plan writes, not a companion payload.
$receipt = array(
	'status' => 'completed',
	'theme' => array( 'slug' => 'raw-html-theme', 'dir' => sys_get_temp_dir() . '/raw-html-theme' ),
	'completed' => array(
		'theme_runtime' => array( 'status' => 'theme_owned', 'owner' => 'theme', 'owner_slug' => 'raw-html-theme', 'block_names' => array( 'example/declared', 'example/responsive-layout' ), 'runtime_scripts' => array() ),
	),
	'plan' => array(
		'source' => array( 'source_hash' => 'raw-html-theme', 'provenance' => array() ),
		'template_parts' => array(),
		'assets' => array(),
		'pages' => array( array( 'source_path' => 'index.html', 'slug' => 'home', 'entrypoint' => true, 'reconciliation_identity' => 'home', 'content_hash' => 'home', 'route' => array( 'path' => '/', 'permalink' => '/' ), 'post_type' => 'page', 'resolved_block_markup' => $documents[0]['content'] ) ),
		'writes' => array_map(
			static fn( $definition ) => array(
				'kind' => 'theme_bootstrap',
				'target_path' => 'ssi-runtime/blocks/' . basename( $definition['block_json']['name'] ) . '/block.json',
				'payload' => array( 'encoding' => 'utf8', 'data' => wp_json_encode( $definition['block_json'] ) ),
			),
			$definitions
		),
	),
);
$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), array(), array(), array(), 'raw-html-theme-run', array(), array() );
$theme_report = $projection['report'];
$assert( 'runtime_owned_by_generated_theme' === $theme_report['companion_plugin_materialization']['reason'], 'Regression exercises the theme-owned runtime without a companion payload.' );
$assert( $theme_report['materialized_content']['block_documents'][0]['raw_html_companion_block_count'] >= 1, 'Theme-owned raw HTML blocks count in the materialized document report.' );
$assert( 1 === $theme_report['quality']['raw_html_companion_block_count'], 'Theme-owned metadata uses the same raw HTML classification as companion metadata.' );
echo "PASS: raw HTML companion quality\n";
