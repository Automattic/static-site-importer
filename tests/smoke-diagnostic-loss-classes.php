<?php
/**
 * Smoke coverage for product-facing diagnostic loss classes.
 *
 * Run from the repository root:
 * php tests/smoke-diagnostic-loss-classes.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

$static_site_importer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $static_site_importer_autoload ) ) {
	require_once $static_site_importer_autoload;
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$fixtures = array(
	'native'      => array(
		'diagnostic' => array(
			'type'        => 'document_metadata_routed',
			'source_path' => 'website/index.html',
		),
		'expected'   => 'native_conversion',
	),
	'editable'    => array(
		'diagnostic' => array(
			'type'       => 'core_html_block',
			'block_name' => 'core/html',
		),
		'expected'   => 'editable_approximation',
	),
	'runtime'     => array(
		'diagnostic' => array(
			'type'   => 'interaction_candidate',
			'reason' => 'native_conversion_report_interaction_candidate',
		),
		'expected'   => 'preserved_runtime_island',
	),
	'preserved-dom-markup' => array(
		'diagnostic' => array(
			'type'   => 'dom',
			'reason' => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
		),
		'expected'   => 'editable_approximation',
	),
	'runtime-script-reason-phrase' => array(
		'diagnostic' => array(
			'type'     => 'dom',
			'selector' => 'script:nth-of-type(1)',
			'reason'   => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
		),
		'expected'   => 'preserved_runtime_island',
	),
	'unsupported' => array(
		'diagnostic' => array(
			'type' => 'content_loss_abort',
		),
		'expected'   => 'unsupported_loss',
	),
	'importer'    => array(
		'diagnostic' => array(
			'type' => 'svg_materialization_failure',
		),
		'expected'   => 'importer_materialization_bug',
	),
);

foreach ( $fixtures as $label => $fixture ) {
	$assert(
		$fixture['expected'] === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $fixture['diagnostic'] ),
		'loss-class-' . $label
	);
}

$counts = Static_Site_Importer_Diagnostic_Loss_Classes::counts( array_column( $fixtures, 'diagnostic' ) );
$assert( 1 === ( $counts['native_conversion'] ?? 0 ), 'counts-native' );
$assert( 2 === ( $counts['editable_approximation'] ?? 0 ), 'counts-editable' );
$assert( 2 === ( $counts['preserved_runtime_island'] ?? 0 ), 'counts-runtime' );
$assert( 1 === ( $counts['unsupported_loss'] ?? 0 ), 'counts-unsupported' );
$assert( 1 === ( $counts['importer_materialization_bug'] ?? 0 ), 'counts-importer' );

/*
 * Every fixture above is an importer-side row: raised by this plugin, carrying no
 * `code` / `diagnostic_code`, so it is not a transformer finding and is expected
 * to take the heuristic path. Assert that explicitly — it is the boundary that
 * keeps the heuristic scoped to importer diagnostics.
 */
foreach ( $fixtures as $label => $fixture ) {
	$provenance = Static_Site_Importer_Diagnostic_Loss_Classes::classify_with_provenance( $fixture['diagnostic'] );
	$assert(
		Static_Site_Importer_Diagnostic_Loss_Classes::SOURCE_HEURISTIC === $provenance['source'],
		'importer-row-uses-heuristic-' . $label,
		'got source: ' . $provenance['source']
	);
}

/*
 * Compiler file-drop rows are the documented exception to both paths above.
 * The transformer's artifact normalizer reports each drop as a warning carrying
 * a producer code like `file_limit_exceeded`, but no remediation lane, so the
 * contract files it under generic review -- still an acceptable bucket, even
 * though the omitted files are gone. That misclassification is issue #1547. The
 * drift guard below pins the raw behavior so a change on either side surfaces
 * here.
 */
$raw_drop_rows = array(
	'file_limit_exceeded'      => array(
		'code'      => 'file_limit_exceeded',
		'severity'  => 'warning',
		'source'    => 'artifact_normalization',
		'context'   => array( 'declared_limit' => 500 ),
	),
	'artifact_file_too_large'  => array(
		'code'      => 'artifact_file_too_large',
		'severity'  => 'warning',
		'source'    => 'artifact_normalization',
		'context'   => array( 'file_size' => 10485761 ),
	),
	'artifact_total_too_large' => array(
		'code'      => 'artifact_total_too_large',
		'severity'  => 'warning',
		'source'    => 'artifact_normalization',
		'context'   => array( 'total_size' => 104857601 ),
	),
);

$expected_drop_types = array(
	'file_limit_exceeded'      => 'omitted_artifact_files',
	'artifact_file_too_large'  => 'omitted_artifact_file',
	'artifact_total_too_large' => 'omitted_artifact_file',
);

// Mapping the producer code onto the importer-owned type is deterministic.
foreach ( $raw_drop_rows as $code => $row ) {
	$assert(
		$expected_drop_types[ $code ] === Static_Site_Importer_Diagnostic_Loss_Classes::compiler_file_drop_type( $row ),
		'compiler-drop-maps-' . $code
	);
}
$assert(
	'' === Static_Site_Importer_Diagnostic_Loss_Classes::compiler_file_drop_type( array( 'code' => 'conversion_warning' ) ),
	'compiler-drop-ignores-unrelated-codes'
);

// Left un-rewritten, a drop row is a contract finding (it carries a `code`) and
// lands in an acceptable product bucket even though the omitted files are gone.
// Pinning that drift here is the point: if upstream ever reclassifies these
// codes itself, this test flags it and the rewrite below can be revisited.
foreach ( $raw_drop_rows as $code => $row ) {
	$class = Static_Site_Importer_Diagnostic_Loss_Classes::classify( $row );
	$assert(
		in_array( $class, array( 'native_conversion', 'editable_approximation', 'preserved_runtime_island' ), true ),
		'raw-drop-row-classifies-acceptable-' . $code,
		'got: ' . $class
	);
}

// After the rewrite the row carries the importer-owned identity everywhere:
// machine type, producer code preserved for diagnosis, explicit loss class, and
// a materialization status that matches reality (the files are not in the import).
foreach ( $raw_drop_rows as $code => $row ) {
	$reowned = Static_Site_Importer_Diagnostic_Loss_Classes::reown_compiler_file_drop( $row );
	$assert(
		$expected_drop_types[ $code ] === ( $reowned['type'] ?? '' ) && $expected_drop_types[ $code ] === ( $reowned['code'] ?? '' ) && $expected_drop_types[ $code ] === ( $reowned['diagnostic_code'] ?? '' ) && $expected_drop_types[ $code ] === ( $reowned['kind'] ?? '' ),
		'reowned-drop-uses-importer-type-' . $code
	);
	$assert(
		$code === ( $reowned['original_code'] ?? '' ) && $code === ( $reowned['reason_code'] ?? '' ),
		'reowned-drop-preserves-producer-code-' . $code
	);
	$assert(
		'unsupported_loss' === ( $reowned['loss_class'] ?? '' ) && 'not_materialized' === ( $reowned['materialization_status'] ?? '' ),
		'reowned-drop-stamps-unsupported-loss-' . $code
	);
	$provenance = Static_Site_Importer_Diagnostic_Loss_Classes::classify_with_provenance( $reowned );
	$assert(
		'unsupported_loss' === $provenance['class'] && Static_Site_Importer_Diagnostic_Loss_Classes::SOURCE_EXPLICIT === $provenance['source'],
		'reowned-drop-classifies-explicitly-' . $code,
		'got: ' . $provenance['class'] . ' via ' . $provenance['source']
	);
}

// Non-drop rows pass through the reown helper untouched.
$untouched = array( 'type' => 'content_loss_abort', 'code' => 'content_loss_abort' );
$assert(
	$untouched === Static_Site_Importer_Diagnostic_Loss_Classes::reown_compiler_file_drop( $untouched ),
	'reown-leaves-non-drop-rows-untouched'
);

/*
 * Contract path, driven by real transformer output.
 *
 * Rather than asserting against hand-built finding stubs, run the vendored
 * php-transformer over this repo's own HTML fixtures and classify every finding
 * it actually emits. This is what proves the cross-repo coupling holds: if
 * upstream renames a remediation lane, real findings start landing in
 * `unmapped_repair_buckets()` and this test fails.
 */
$contract_class = 'Automattic\\BlocksEngine\\PhpTransformer\\Contract\\ConversionFindingContract';
$transformer_class = 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer';

if ( class_exists( $contract_class ) && class_exists( $transformer_class ) ) {
	Static_Site_Importer_Diagnostic_Loss_Classes::reset_unmapped_repair_buckets();

	$html_fixtures = glob( __DIR__ . '/fixtures/*/*.html' ) ?: array();
	sort( $html_fixtures );

	$findings_seen   = 0;
	$heuristic_leaks = array();
	$observed_classes = array();

	foreach ( $html_fixtures as $html_fixture ) {
		$html = file_get_contents( $html_fixture );
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			continue;
		}

		try {
			$result = ( new $transformer_class() )->transform( $html );
		} catch ( Throwable $e ) {
			continue;
		}

		foreach ( array_merge( $result->fallbacks, $result->diagnostics ) as $finding ) {
			if ( ! is_array( $finding ) || ! $contract_class::isFinding( $finding ) ) {
				continue;
			}

			++$findings_seen;
			$provenance = Static_Site_Importer_Diagnostic_Loss_Classes::classify_with_provenance( $finding );
			$observed_classes[ $provenance['class'] ] = true;

			if ( Static_Site_Importer_Diagnostic_Loss_Classes::SOURCE_HEURISTIC === $provenance['source'] ) {
				$heuristic_leaks[ $contract_class::findingCode( $finding ) ] = $provenance['repair_bucket'];
			}
		}
	}

	$assert( $findings_seen > 0, 'contract-fixtures-produced-findings', 'no transformer findings emitted from tests/fixtures' );

	// A transformer finding must never be classified by string matching.
	$assert(
		array() === $heuristic_leaks,
		'no-producer-finding-falls-to-heuristic',
		'codes leaking to heuristic: ' . wp_json_encode_fallback( $heuristic_leaks )
	);

	// Upstream drift guard: an unrecognized remediation lane fails here.
	$unmapped = Static_Site_Importer_Diagnostic_Loss_Classes::unmapped_repair_buckets();
	$assert(
		array() === $unmapped,
		'no-unmapped-contract-repair-buckets',
		'unmapped lanes: ' . wp_json_encode_fallback( $unmapped )
	);

	// The corpus must exercise more than a single product bucket, otherwise the
	// mapping is not actually being discriminated by this test.
	$assert(
		count( $observed_classes ) > 1,
		'contract-corpus-spans-multiple-classes',
		'observed: ' . wp_json_encode_fallback( array_keys( $observed_classes ) )
	);
}

/**
 * Minimal JSON encoder so failure detail works without WordPress loaded.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_fallback( $value ): string {
	$encoded = json_encode( $value );

	return is_string( $encoded ) ? $encoded : '';
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: diagnostic loss classes smoke passed (' . $assertions . " assertions)\n";
