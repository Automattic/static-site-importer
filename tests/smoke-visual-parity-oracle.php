<?php
/**
 * Layout-baseline visual parity oracle coverage.
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

$viewport = array(
	'width'  => 1440,
	'height' => 900,
);

$baseline_page = array(
	'id'        => 'index',
	'viewport'  => $viewport,
	'sections'  => array(
		array(
			'id'       => 'hero',
			'order'    => 0,
			'offset'   => array( 'top' => 80 ),
			'height'   => 902,
			'headings' => array(
				array(
					'text'      => 'The Awards',
					'font_size' => 72,
				),
			),
			'body'     => array(
				array( 'font_size' => 16 ),
			),
			'media'    => array(
				array(
					'id'             => 'header-logo',
					'role'           => 'logo',
					'display_width'  => 36,
					'display_height' => 36,
				),
				array(
					'id'             => 'injected-badge',
					'display_width'  => 24,
					'display_height' => 24,
				),
			),
			'forms'    => array(
				array(
					'id'      => 'nominate',
					'padding' => array(
						'top'    => 28,
						'right'  => 28,
						'bottom' => 28,
						'left'   => 28,
					),
					'fields'  => array(
						array(
							'id'             => 'nominee',
							'display_width'  => 320,
							'display_height' => 48,
						),
						array(
							'id'             => 'website',
							'display_width'  => 0,
							'display_height' => 0,
						),
					),
				),
			),
		),
		array(
			'id'       => 'about',
			'order'    => 1,
			'offset'   => array( 'top' => 1209 ),
			'height'   => 635,
			'headings' => array(
				array( 'font_size' => 48 ),
			),
			'media'    => array(),
		),
	),
	'landmarks' => array(
		array(
			'id'     => 'banner',
			'role'   => 'banner',
			'offset' => array( 'top' => 0 ),
			'height' => 80,
			'media'  => array(
				array(
					'id'             => 'header-logo',
					'role'           => 'logo',
					'display_width'  => 36,
					'display_height' => 36,
				),
			),
		),
	),
);

$baseline = array(
	'schema'                 => Static_Site_Importer_Visual_Parity_Oracle::SCHEMA,
	'viewports'              => array( $viewport ),
	'pages'                  => array( $baseline_page ),
	'intentional_omissions'  => array(
		array(
			'kind' => 'media',
			'id'   => 'injected-badge',
		),
		array(
			'kind' => 'form_field',
			'id'   => 'website',
		),
	),
);

$matching_page = $baseline_page;
$matching_page['sections'][0]['media']  = array(
	array(
		'id'             => 'header-logo',
		'role'           => 'logo',
		'display_width'  => 36,
		'display_height' => 36,
	),
);
$matching_page['sections'][0]['forms'][0]['fields'] = array(
	array(
		'id'             => 'nominee',
		'display_width'  => 320,
		'display_height' => 48,
	),
);
$matching_page['sections'][1]['height'] = 636;

$matching_render = array(
	'schema'                => Static_Site_Importer_Visual_Parity_Oracle::SCHEMA,
	'viewports'             => array( $viewport ),
	'pages'                 => array( $matching_page ),
	'intentional_omissions' => array(),
);

$envelope = static function ( array $baseline_doc, ?array $imported_doc ) use ( $viewport ): array {
	$payload = array(
		'source_reports' => array(
			'layout_baseline' => $baseline_doc,
		),
	);
	if ( null !== $imported_doc ) {
		$payload['imported_render'] = $imported_doc;
	}
	return $payload;
};

$absent = Static_Site_Importer_Visual_Parity_Oracle::contract_evidence( array() );
$assert( 'blocked_missing_compiler_contract' === $absent['status'], 'absent-baseline-is-blocked' );
$assert( in_array( 'source_reports.layout_baseline schema static-site-importer/layout-baseline/v1', $absent['missing_data_contract'], true ), 'absent-baseline-names-schema' );
$assert( in_array( 'source_reports.layout_baseline.pages', $absent['missing_data_contract'], true ), 'absent-baseline-names-pages' );
$assert( in_array( 'source_reports.layout_baseline.intentional_omissions', $absent['missing_data_contract'], true ), 'absent-baseline-names-omissions' );

$malformed = Static_Site_Importer_Visual_Parity_Oracle::contract_evidence(
	array(
		'source_reports' => array(
			'layout_baseline' => array(
				'schema' => 'not-a-layout-baseline',
			),
		),
	)
);
$assert( 'blocked_missing_compiler_contract' === $malformed['status'], 'wrong-schema-is-blocked' );
$assert( in_array( 'source_reports.layout_baseline.viewports', $malformed['missing_data_contract'], true ), 'wrong-schema-lists-viewports' );

$present = Static_Site_Importer_Visual_Parity_Oracle::contract_evidence( $envelope( $baseline, null ) );
$assert( 'contract_present' === $present['status'], 'valid-baseline-is-present', wp_json_encode( $present['missing_data_contract'] ) ?: '' );
$assert( array() === $present['missing_data_contract'], 'valid-baseline-has-no-missing-fields' );

$unverified = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, null ) );
$assert( 'not_verified' === $unverified['status'], 'baseline-without-imported-render-is-not-verified' );
$assert( array() === $unverified['diagnostics'], 'not-verified-does-not-emit-diagnostics' );
$assert( array() === $unverified['missing_data_contract'], 'missing-render-does-not-claim-missing-contract' );

$absent_eval = Static_Site_Importer_Visual_Parity_Oracle::evaluate( array() );
$assert( 'not_verified' === $absent_eval['status'], 'absent-baseline-evaluate-is-not-verified' );
$assert( array() !== $absent_eval['missing_data_contract'], 'absent-baseline-evaluate-lists-missing-contract' );

$pass = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, $matching_render ) );
$assert( 'passed' === $pass['status'], 'matching-render-passes', (string) ( $pass['reason'] ?? '' ) );
$assert( array() === $pass['disagreements'], 'matching-render-has-no-disagreements', wp_json_encode( $pass['disagreements'] ) ?: '' );
$assert( ( $pass['omitted_count'] ?? 0 ) >= 2, 'declared-omissions-are-recorded' );
$assert( 'visual_diff' === ( $pass['artifact_refs']['visual_diff']['kind'] ?? '' ), 'pass-emits-visual-diff-ref' );
$assert( 'imported-layout-baseline.json' === ( $pass['artifact_refs']['browser_render']['artifact_name'] ?? '' ), 'pass-emits-browser-render-ref' );

$one_px = $matching_render;
$one_px['pages'][0]['page_height'] = 6555;
$one_px_baseline = $baseline;
$one_px_baseline['pages'][0]['page_height'] = 6554;
$one_px_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $one_px_baseline, $one_px ) );
$assert( 'passed' === $one_px_result['status'], 'page-level-1px-delta-is-ignored' );

$oversized = $matching_render;
$oversized['pages'][0]['sections'][0]['media'][0]['display_width']  = 728;
$oversized['pages'][0]['sections'][0]['media'][0]['display_height'] = 728;
$oversized['pages'][0]['landmarks'][0]['height'] = 728;
$oversized['pages'][0]['landmarks'][0]['media'][0]['display_width']  = 728;
$oversized['pages'][0]['landmarks'][0]['media'][0]['display_height'] = 728;
$fail = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, $oversized ) );
$codes = array_column( $fail['disagreements'], 'code' );
$assert( 'failed' === $fail['status'], 'oversized-logo-fails' );
$assert( in_array( 'image_display_width', $codes, true ), 'oversized-logo-reports-display-width', implode( ',', $codes ) );
$assert( in_array( 'image_display_height', $codes, true ), 'oversized-logo-reports-display-height', implode( ',', $codes ) );
$assert( in_array( 'landmark_height', $codes, true ), 'oversized-header-reports-landmark-height', implode( ',', $codes ) );

$short = $matching_render;
$short['pages'][0]['sections'][0]['height'] = 806;
$short_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, $short ) );
$assert( 'failed' === $short_result['status'], 'materially-shorter-section-fails' );
$assert( in_array( 'section_height', array_column( $short_result['disagreements'], 'code' ), true ), 'section-height-disagreement' );

$heading = $matching_render;
$heading['pages'][0]['sections'][0]['headings'] = array( array( 'font_size' => 36 ) );
$heading_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, $heading ) );
$assert( 'failed' === $heading_result['status'], 'heading-size-disagreement-fails' );

$padding = $matching_render;
$padding['pages'][0]['sections'][0]['forms'][0]['padding'] = array(
	'top'    => 0,
	'right'  => 0,
	'bottom' => 0,
	'left'   => 0,
);
$padding_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $baseline, $padding ) );
$assert( 'failed' === $padding_result['status'], 'dropped-form-padding-fails' );
$assert( in_array( 'form_padding_top', array_column( $padding_result['disagreements'], 'code' ), true ), 'form-padding-disagreement' );

$undeclared = $baseline;
$undeclared['intentional_omissions'] = array();
$undeclared_result = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $envelope( $undeclared, $matching_render ) );
$assert( 'failed' === $undeclared_result['status'], 'undeclared-missing-media-fails' );
$assert( in_array( 'image_count', array_column( $undeclared_result['disagreements'], 'code' ), true ), 'undeclared-missing-media-is-image-count' );

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$unverified_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$assert( true === $unverified_quality['pass'], 'unverified-does-not-fail-quality' );
$assert( 0 === ( $unverified_quality['visual_parity_failure_count'] ?? -1 ), 'unverified-count-is-zero' );
$assert( 'pending' === ( $report['visual_parity_artifacts']['artifacts']['visual_diff']['status'] ?? '' ), 'unverified-visual-diff-stays-pending' );
$assert( 'not_verified' === ( $report['visual_fidelity']['status'] ?? '' ), 'unverified-marks-visual-fidelity-not-verified' );
$assert( 'source_reports.layout_baseline' === ( $report['visual_fidelity']['compiler_report_path'] ?? '' ), 'unverified-names-contract-slot' );
$assert( in_array( 'source_reports.layout_baseline.pages', $report['visual_fidelity']['missing_data_contract'] ?? array(), true ), 'unverified-lists-missing-pages' );

$pass_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$pass_quality = Static_Site_Importer_Report_Diagnostics::finalize_report(
	$pass_report,
	array(
		'source_reports'       => array( 'layout_baseline' => $baseline ),
		'validation_artifacts' => array(
			'imported_render' => $matching_render,
		),
	)
);
$assert( true === $pass_quality['pass'], 'matching-render-quality-pass' );
$assert( 0 === ( $pass_quality['visual_parity_failure_count'] ?? -1 ), 'matching-render-quality-count' );
$assert( 'passed' === ( $pass_report['visual_fidelity']['status'] ?? '' ), 'matching-render-marks-visual-fidelity-passed' );
$assert( 'captured' === ( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['status'] ?? '' ), 'matching-render-captures-visual-diff' );
$assert( 'visual-diff.json' === ( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['ref']['artifact_name'] ?? '' ), 'visual-diff-uses-durable-name' );
$assert( ! isset( $pass_report['visual_parity_artifacts']['artifacts']['visual_diff']['ref']['path'] ), 'visual-diff-omits-local-path' );
$assert( 'captured' === ( $pass_report['visual_parity_artifacts']['artifacts']['browser_render']['status'] ?? '' ), 'matching-render-captures-browser-render' );

$fail_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$fail_quality = Static_Site_Importer_Report_Diagnostics::finalize_report(
	$fail_report,
	array(
		'fail_on_quality'      => true,
		'source_reports'       => array( 'layout_baseline' => $baseline ),
		'validation_artifacts' => array(
			'imported_render' => $oversized,
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
