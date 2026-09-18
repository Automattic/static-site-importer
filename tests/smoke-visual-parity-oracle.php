<?php
/**
 * Section-geometry visual parity oracle coverage.
 *
 * Run from the repository root:
 * php tests/smoke-visual-parity-oracle.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '' ) {}
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-visual-parity-oracle.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$source_page = array(
	'page'      => 'index',
	'sourceUrl' => 'https://golden-bull-awards.lovable.app/',
	'viewport'  => array(
		'width'  => 1440,
		'height' => 900,
	),
	'sections'  => array(
		array(
			'sectionIndex' => 0,
			'selector'     => 'section.hero',
			'top'          => 80,
			'height'       => 902,
			'headings'     => array( 'The Indian Golden Bull Awards' ),
			'headingSizes' => array( 72 ),
			'images'       => array(
				array(
					'alt'           => 'The Indian Golden Bull Awards emblem',
					'displayWidth'  => 36,
					'displayHeight' => 36,
				),
				array(
					'alt'      => 'Made with Lovable',
					'selector' => '#lovable-badge-cta',
					'url'      => 'https://lovable.dev/badge.svg',
				),
			),
			'forms'        => array(
				array(
					'fields' => array(
						array(
							'kind'  => 'text',
							'label' => 'Nominee name',
						),
						array(
							'kind'     => 'text',
							'label'    => 'Website',
							'name'     => 'website',
							'tabindex' => -1,
							'width'    => 0,
							'height'   => 0,
						),
					),
				),
			),
		),
		array(
			'sectionIndex' => 1,
			'selector'     => 'section.about',
			'top'          => 1209,
			'height'       => 635,
			'headingSizes' => array( 48 ),
		),
	),
	'landmarks' => array(
		array(
			'role'       => 'header',
			'tag'        => 'header',
			'selector'   => 'header.fixed',
			'mediaCount' => 1,
			'textLength' => 70,
		),
	),
);

$matching_imported = $source_page;
$matching_imported['sections'][0]['images'] = array(
	array(
		'alt'           => 'The Indian Golden Bull Awards emblem',
		'displayWidth'  => 36,
		'displayHeight' => 36,
	),
);
$matching_imported['sections'][0]['forms'][0]['fields'] = array(
	array(
		'kind'  => 'text',
		'label' => 'Nominee name',
	),
);
$matching_imported['sections'][1]['height'] = 636;
$matching_imported['landmarks'][0]['height'] = 80;

$pass = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'    => $source_page,
		'imported_pages'  => $matching_imported,
		'omissions'       => array( '#lovable-badge' ),
	)
);
$assert( 'passed' === $pass['status'], 'matching-render-passes', (string) ( $pass['reason'] ?? '' ) );
$assert( array() === $pass['disagreements'], 'matching-render-has-no-disagreements', wp_json_encode( $pass['disagreements'] ) ?: '' );
$assert( $pass['omitted_count'] ?? count( $pass['omitted'] ) >= 1, 'stripped-badge-is-omitted' );
$assert( 'visual_diff' === ( $pass['artifact_refs']['visual_diff']['kind'] ?? '' ), 'pass-emits-visual-diff-ref' );

$one_px = $matching_imported;
$one_px['page_height'] = 6555;
$one_px_source = $source_page;
$one_px_source['page_height'] = 6554;
$one_px_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'   => $one_px_source,
		'imported_pages' => $one_px,
		'omissions'      => array( '#lovable-badge' ),
	)
);
$assert( 'passed' === $one_px_result['status'], 'page-level-1px-delta-is-ignored' );

$oversized = $matching_imported;
$oversized['sections'][0]['images'][0]['displayWidth']  = 728;
$oversized['sections'][0]['images'][0]['displayHeight'] = 728;
$oversized['landmarks'][0]['height'] = 728;
$fail = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'   => $source_page,
		'imported_pages' => $oversized,
		'omissions'      => array( '#lovable-badge' ),
	)
);
$codes = array_column( $fail['disagreements'], 'code' );
$assert( 'failed' === $fail['status'], 'oversized-logo-fails' );
$assert( in_array( 'image_display_width', $codes, true ), 'oversized-logo-reports-display-width', implode( ',', $codes ) );
$assert( in_array( 'landmark_height', $codes, true ), 'oversized-header-reports-landmark-height', implode( ',', $codes ) );

$short = $matching_imported;
$short['sections'][0]['height'] = 806;
$short_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'   => $source_page,
		'imported_pages' => $short,
		'omissions'      => array( '#lovable-badge' ),
	)
);
$assert( 'failed' === $short_result['status'], 'materially-shorter-section-fails' );
$assert( in_array( 'section_height', array_column( $short_result['disagreements'], 'code' ), true ), 'section-height-disagreement' );

$heading = $matching_imported;
$heading['sections'][0]['headingSizes'] = array( 36 );
$heading_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'   => $source_page,
		'imported_pages' => $heading,
		'omissions'      => array( '#lovable-badge' ),
	)
);
$assert( 'failed' === $heading_result['status'], 'heading-size-disagreement-fails' );

$skipped = Static_Site_Importer_Visual_Parity_Oracle::evaluate( array() );
$assert( 'skipped' === $skipped['status'], 'missing-records-skip' );
$assert( 'not_verified' === $skipped['verification'], 'missing-records-are-not-verified' );
$assert( array() === $skipped['diagnostics'], 'skip-does-not-emit-diagnostics' );

$cleanup = array(
	'pages' => array(
		array(
			'reports' => array(
				array(
					'records' => array(
						array(
							'rule'     => 'lovable-badge',
							'selector' => '#lovable-badge',
							'action'   => 'remove',
						),
					),
				),
			),
		),
	),
);
$cleanup_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate(
	array(
		'source_pages'   => $source_page,
		'imported_pages' => $matching_imported,
		'cleanup'        => $cleanup,
	)
);
$assert( 'passed' === $cleanup_result['status'], 'cleanup-evidence-omits-stripped-badge' );

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$unverified = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$assert( true === $unverified['pass'], 'unverified-does-not-fail-quality' );
$assert( 0 === ( $unverified['visual_parity_failure_count'] ?? -1 ), 'unverified-count-is-zero' );
$assert( 'pending' === ( $report['visual_parity_artifacts']['artifacts']['visual_diff']['status'] ?? '' ), 'unverified-visual-diff-stays-pending' );
$assert( 'requires_runtime_visual_parity_check' === ( $report['visual_fidelity']['status'] ?? '' ), 'unverified-keeps-explicit-runtime-check-status' );

$pass_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$pass_quality = Static_Site_Importer_Report_Diagnostics::finalize_report(
	$pass_report,
	array(
		'validation_artifacts' => array(
			'source_pages'   => $source_page,
			'imported_pages' => $matching_imported,
			'omissions'      => array( '#lovable-badge' ),
		),
	)
);
$assert( true === $pass_quality['pass'], 'matching-render-quality-pass' );
$assert( 0 === ( $pass_quality['visual_parity_failure_count'] ?? -1 ), 'matching-render-quality-count' );
$assert( 'passed' === ( $pass_report['visual_fidelity']['status'] ?? '' ), 'matching-render-marks-visual-fidelity-passed' );
$assert( 'captured' === ( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['status'] ?? '' ), 'matching-render-captures-visual-diff' );
$assert( 'visual-diff.json' === ( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['ref']['artifact_name'] ?? '' ), 'visual-diff-uses-durable-name' );
$assert( ! isset( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['ref']['path'] ), 'visual-diff-omits-local-path' );

$fail_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$fail_quality = Static_Site_Importer_Report_Diagnostics::finalize_report(
	$fail_report,
	array(
		'fail_on_quality'      => true,
		'validation_artifacts' => array(
			'source_pages'   => $source_page,
			'imported_pages' => $oversized,
			'omissions'      => array( '#lovable-badge' ),
		),
	)
);
$assert( false === $fail_quality['pass'], 'oversized-logo-quality-fail' );
$assert( true === $fail_quality['fail_import'], 'oversized-logo-fail-import-when-strict' );
$assert( in_array( 'visual_parity_mismatch', $fail_quality['failure_reasons'] ?? array(), true ), 'oversized-logo-failure-reason' );
$assert( ( $fail_quality['visual_parity_failure_count'] ?? 0 ) > 0, 'oversized-logo-failure-count' );
$assert( 'failed' === ( $fail_report['visual_fidelity']['status'] ?? '' ), 'oversized-logo-marks-visual-fidelity-failed' );
$assert( 'passed' !== ( $fail_report['import_validation_result']['quality_gates']['visual_parity']['status'] ?? 'passed' ), 'validation-result-does-not-pass-visual-parity' );

if ( array() !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

print "visual parity oracle smoke passed ({$assertions} assertions)\n";
