<?php
/**
 * Smoke coverage for source findings in an import.
 *
 * A website artifact may carry `source-findings.json`
 * (`static-site-importer/source-findings/v1`) at its root. The import reads it
 * at intake, leaves it out of the compiled site, and carries its rows into the
 * stored report and compact summary with the loss class, unit, severity and
 * owning layer each row declares. An artifact without the file imports as
 * before; a malformed file records a single `source_findings_unreadable`
 * warning and never fails the import; a row outside SSI's vocabulary stays
 * visibly unrecognized.
 *
 * Run from the repository root:
 * php tests/smoke-source-findings.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone smoke.

require __DIR__ . '/fixtures/source-findings-import-harness.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$findings = array(
	'schema'     => 'static-site-importer/source-findings/v1',
	'entrypoint' => 'index.html',
	'complete'   => false,
	'usable'     => true,
	'summary'    => array( 'routes_discovered' => 3 ),
	'findings'   => array(
		array(
			'code'         => 'route_capture_degraded',
			'severity'     => 'warning',
			'unit'         => 'route',
			'loss_class'   => 'degraded_unit',
			'location'     => array(
				'url'  => 'https://example.test/about/',
				'path' => 'about.html',
			),
			'message'      => 'mobile/screenshot-scrolled: screenshot timeout',
			'metrics'      => array(
				'failedStages' => 1,
				'stage'        => 'mobile',
			),
			'owning_layer' => 'example-capture',
		),
		array(
			'code'         => 'route_export_failed',
			'severity'     => 'error',
			'unit'         => 'route',
			'loss_class'   => 'omitted_unit',
			'location'     => array( 'url' => 'https://example.test/pricing/' ),
			'message'      => 'cannot export Pricing',
			'owning_layer' => 'example-capture',
		),
		array(
			'code'         => 'template_variant_sampled',
			'severity'     => 'info',
			'unit'         => 'page',
			'loss_class'   => 'omitted_unit',
			'location'     => array( 'path' => 'blog/page-2.html' ),
			'message'      => 'derived from a sampled template',
			'owning_layer' => 'studio.export',
		),
	),
);
$import   = static function ( ?string $bytes, string $label ) use ( $assert ): array {
	$import = ssi_source_findings_import( null === $bytes ? array() : array( 'website/source-findings.json' => $bytes ) );
	$assert( ! empty( $import['result']['success'] ) && empty( $import['result']['continuation'] ), "$label: the import succeeds" );
	$persisted = array_column( $import['plan']['pages'] ?? array(), 'source_path' );
	sort( $persisted );
	$assert( array( 'website/about.html', 'website/index.html' ) === $persisted, "$label: both pages import" );
	$assert( ! str_contains( (string) wp_json_encode( $import['plan'] ), 'source-findings.json' ), "$label: the findings file is not site content" );
	return $import;
};
$rows     = static fn ( array $diagnostics, string $field, string $value ): array => array_values( array_filter( $diagnostics, static fn ( $row ): bool => is_array( $row ) && $value === ( $row[ $field ] ?? null ) ) );
$stage    = static fn ( array $diagnostics ): array => $rows( $diagnostics, 'stage', 'capture' );
$accepted = Static_Site_Importer_Diagnostic_Loss_Classes::ACCEPTED_REPORTED_LOSS;
$omitted  = Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_UNIT;
$reduced  = Static_Site_Importer_Diagnostic_Loss_Classes::DEGRADED_UNIT;

// Findings travel into the stored report and the compact summary, classified by their own fields.
$with    = $import( (string) wp_json_encode( $findings ), 'with-findings' );
$stored  = $with['stored'];
$summary = $with['summary'];
$source  = $stage( $stored['diagnostics'] ?? array() );
$assert( 3 === count( $source ), 'with-findings: the stored report carries one row per finding' );
foreach ( $source as $row ) {
	$missing = array_diff( Static_Site_Importer_Diagnostic_Projection::CANONICAL_FINDING_FIELDS, array_keys( $row ) );
	$assert( array() === $missing, 'with-findings: ' . ( $row['code'] ?? '?' ) . ' carries every canonical finding field' );
}
$degraded = $rows( $source, 'code', 'route_capture_degraded' )[0] ?? array();
$assert( 'route' === ( $degraded['unit'] ?? '' ) && 'warning' === ( $degraded['severity'] ?? '' ) && 'website/about.html' === ( $degraded['location'] ?? '' ), 'with-findings: the degraded row is a route-unit warning located at its page' );
$assert( $reduced === ( $degraded['loss_class'] ?? '' ) && $accepted === ( $degraded['acceptability'] ?? '' ) && 'example-capture' === ( $degraded['owning_layer'] ?? '' ), 'with-findings: the degraded row keeps its own loss class and owning layer' );
$assert( array( 'failedStages' => 1 ) === ( $degraded['metrics'] ?? null ) && 'https://example.test/about/' === ( $degraded['url'] ?? '' ), 'with-findings: the degraded row keeps its url and numeric metrics' );
$assert( 'mobile/screenshot-scrolled: screenshot timeout' === ( $degraded['message'] ?? '' ), 'with-findings: the degraded row keeps its message' );
$dropped = $rows( $source, 'code', 'route_export_failed' )[0] ?? array();
$assert( 'error' === ( $dropped['severity'] ?? '' ) && 'https://example.test/pricing/' === ( $dropped['location'] ?? '' ) && $omitted === ( $dropped['loss_class'] ?? '' ) && $accepted === ( $dropped['acceptability'] ?? '' ), 'with-findings: the dropped route is an accepted omitted unit located at its URL' );
$sampled = $rows( $source, 'code', 'template_variant_sampled' )[0] ?? array();
$assert( 'info' === ( $sampled['severity'] ?? '' ) && 'page' === ( $sampled['unit'] ?? '' ) && 'website/blog/page-2.html' === ( $sampled['location'] ?? '' ) && 'studio.export' === ( $sampled['owning_layer'] ?? '' ) && ! isset( $sampled['url'] ), 'with-findings: a path-only info row from another producer keeps its own fields' );
$assert( 2 === ( $summary['loss_class_summary'][ $omitted ] ?? 0 ) && 1 === ( $summary['loss_class_summary'][ $reduced ] ?? 0 ) && 3 <= ( $summary['loss_count'] ?? 0 ), 'with-findings: the compact summary counts every source row as a loss' );
$assert( 'completed' === ( $summary['status'] ?? '' ) && false === ( $summary['fail_import'] ?? true ), 'with-findings: accepted losses do not fail the import' );
$compact       = $stage( $stored['compact_summary']['diagnostics'] ?? array() );
$compact_codes = array_column( $compact, 'code' );
sort( $compact_codes );
$assert( array( 'route_capture_degraded', 'route_export_failed', 'template_variant_sampled' ) === $compact_codes, 'with-findings: compact rows keep the source codes' );
$compact_degraded = $rows( $compact, 'code', 'route_capture_degraded' )[0] ?? array();
$assert( 'website/about.html' === ( $compact_degraded['location'] ?? '' ) && 1 === ( $compact_degraded['metrics']['failedStages'] ?? null ) && $accepted === ( $compact_degraded['acceptability'] ?? '' ), 'with-findings: the compact row keeps location, metrics and acceptability' );
$assert( array() === $rows( $stored['diagnostics'] ?? array(), 'code', Static_Site_Importer_Source_Findings::UNREADABLE ), 'with-findings: a valid file records no unreadable warning' );

// Artifacts without the file import exactly as before.
$without = $import( null, 'without-findings' );
$assert( array() === $stage( $without['stored']['diagnostics'] ?? array() ), 'without-findings: no source rows' );
$assert( array() === $rows( $without['stored']['diagnostics'] ?? array(), 'code', Static_Site_Importer_Source_Findings::UNREADABLE ), 'without-findings: no unreadable warning' );

// Vocabulary SSI does not know stays on the row, visibly unrecognized; the row is never dropped.
$unknown              = $findings;
$unknown['findings']  = array(
	array_merge( $findings['findings'][0], array( 'loss_class' => 'partially_captured' ) ),
	array_merge( $findings['findings'][1], array( 'unit' => 'viewport' ) ),
);
$unrecognized         = Static_Site_Importer_Diagnostic_Loss_Classes::UNRECOGNIZED_LOSS_CLASS;
$drift                = $import( (string) wp_json_encode( $unknown ), 'unknown-vocabulary' );
$drift_rows           = $stage( $drift['stored']['diagnostics'] ?? array() );
$unknown_class        = $rows( $drift_rows, 'code', 'route_capture_degraded' )[0] ?? array();
$unknown_unit         = $rows( $drift_rows, 'code', 'route_export_failed' )[0] ?? array();
$assert( 2 === count( $drift_rows ), 'unknown-vocabulary: both rows are kept' );
$assert( $unrecognized === ( $unknown_class['loss_class'] ?? '' ) && isset( Static_Site_Importer_Diagnostic_Loss_Classes::unmapped_loss_classes()['partially_captured'] ), 'unknown-vocabulary: an unknown loss class is recorded as drift' );
$assert( $unrecognized === ( $unknown_unit['loss_class'] ?? '' ) && 'viewport' === ( $unknown_unit['unit'] ?? '' ), 'unknown-vocabulary: an unknown unit keeps its name and is unrecognized' );
$assert( 2 === ( $drift['summary']['loss_class_summary'][ $unrecognized ] ?? 0 ), 'unknown-vocabulary: the compact summary counts both unrecognized rows' );

// A malformed file never fails the import; it records one warning and no partial rows.
$row_with = static fn ( array $changes, array $remove = array() ): string => (string) wp_json_encode( array_merge( $findings, array( 'findings' => array( $findings['findings'][1], array_diff_key( array_merge( $findings['findings'][0], $changes ), array_flip( $remove ) ) ) ) ) );
foreach (
	array(
		'not-json'               => '{"schema":',
		'producer-schema'        => (string) wp_json_encode( array_merge( $findings, array( 'schema' => 'example-capture/capture-findings/v1' ) ) ),
		'no-findings'            => (string) wp_json_encode( array_diff_key( $findings, array( 'findings' => true ) ) ),
		'findings-not-a-list'    => (string) wp_json_encode( array_merge( $findings, array( 'findings' => array( 'a' => $findings['findings'][0] ) ) ) ),
		'complete-not-boolean'   => (string) wp_json_encode( array_merge( $findings, array( 'complete' => 'no' ) ) ),
		'row-without-code'       => $row_with( array(), array( 'code' ) ),
		'row-unknown-severity'   => $row_with( array( 'severity' => 'fatal' ) ),
		'row-without-unit'       => $row_with( array(), array( 'unit' ) ),
		'row-without-loss-class' => $row_with( array( 'loss_class' => 7 ) ),
		'row-owning-layer-text'  => $row_with( array( 'owning_layer' => 'Example Capture' ) ),
		'row-without-message'    => $row_with( array(), array( 'message' ) ),
		'row-without-location'   => $row_with( array( 'location' => array( 'profile' => 'tablet' ) ) ),
		'row-metrics-scalar'     => $row_with( array( 'metrics' => 3 ) ),
	) as $case => $bytes
) {
	$bad        = $import( $bytes, "malformed-$case" );
	$unreadable = $rows( $bad['stored']['diagnostics'] ?? array(), 'code', Static_Site_Importer_Source_Findings::UNREADABLE );
	$assert( 1 === count( $unreadable ) && 'warning' === ( $unreadable[0]['severity'] ?? '' ) && 'website/source-findings.json' === ( $unreadable[0]['source_path'] ?? '' ), "malformed-$case: one source_findings_unreadable warning" );
	$assert( array() === $stage( $bad['stored']['diagnostics'] ?? array() ), "malformed-$case: no partial source rows" );
	$assert( 'completed' === ( $bad['summary']['status'] ?? '' ), "malformed-$case: the import completes" );
}

// Request-bundle sources reference the file through the payload reader.
$reader    = new class( (string) wp_json_encode( $findings ) ) {
	public function __construct( private string $bytes ) {}
	public function read( array $reference ): string {
		return $this->bytes;
	}
};
$extracted = Static_Site_Importer_Source_Findings::extract(
	array(
		array(
			'path'    => 'website/index.html',
			'content' => '<h1>Home</h1>',
		),
		array(
			'path'              => 'website/source-findings.json',
			'payload_reference' => array( 'id' => 'request-bundle-file:source-findings.json' ),
		),
	),
	$reader
);
$assert( array( 'website/index.html' ) === array_column( $extracted['files'], 'path' ) && 3 === count( $extracted['findings'] ), 'payload-reference: the file is read through the payload reader and removed' );

// The reported-loss registry holds only codes SSI and Blocks Engine own.
$owners = array_unique( array_column( Static_Site_Importer_Diagnostic_Loss_Classes::reported_loss_codes(), 'owning_layer' ) );
sort( $owners );
$assert( array( 'blocks-engine', 'static-site-importer' ) === $owners, 'registry: only SSI and Blocks Engine codes are registered' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-source-findings: ok\n";
