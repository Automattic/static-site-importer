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
