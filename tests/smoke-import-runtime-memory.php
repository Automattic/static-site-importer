<?php
/**
 * Memory bound for the steps of a fresh import runtime (Automattic/static-site-importer#1634).
 *
 * Each CLI import step runs in a fresh runtime, so a step's own peak is what the
 * kernel's out-of-memory killer sees. This drives a generated multi-page site
 * through the real canonical import service with the CLI run policy (which
 * yields after composition) and measures, per step, how far the heap grows
 * above where the step started, resetting the process-wide read cache between
 * steps the way a new process would.
 *
 * The bound is expressed in multiples of the retained source bytes, so it holds
 * across PHP versions, and it separates the steps that must be cheap: once
 * composition is durable, applying must not also hold the shared plan, page
 * plans and receipts it replaced.
 *
 * Run from the repository root:
 * php tests/smoke-import-runtime-memory.php
 * SSI_RUNTIME_MEMORY_REPORT=1 php tests/smoke-import-runtime-memory.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions -- Standalone smoke measures a real import process.

require_once __DIR__ . '/fixtures/direct-import-harness.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// A multi-page site whose retained bytes are dominated by assets, as hosted failures are.
$page_count = 16;
$asset_kib   = 64;
$asset_count = 40;
$files      = array();
$css        = '';
for ( $i = 0; $i < 200; ++$i ) {
	$css .= '.c' . $i . '{padding:' . ( $i % 9 ) . 'rem;color:#' . substr( md5( (string) $i ), 0, 6 ) . ';margin:0 auto;max-width:' . ( 600 + $i ) . "px}\n";
}
$files[] = array( 'path' => 'website/styles.css', 'content' => $css );
for ( $a = 0; $a < $asset_count; ++$a ) {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200">';
	for ( $r = 0; strlen( $svg ) < $asset_kib * 1024; ++$r ) {
		$svg .= '<rect x="' . ( $r % 290 ) . '" y="' . ( $r % 190 ) . '" width="8" height="8" fill="#' . substr( md5( $a . ':' . $r ), 0, 6 ) . '"/>';
	}
	$files[] = array( 'path' => 'website/images/art-' . $a . '.svg', 'content' => $svg . '</svg>' );
}
$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.';
for ( $p = 0; $p < $page_count; ++$p ) {
	$body = '<main><h1>Page ' . $p . '</h1>';
	for ( $s = 0; $s < 4; ++$s ) {
		$body .= '<section class="c' . ( ( $p * 4 + $s ) % 200 ) . '"><h2>Section ' . $s . '</h2>';
		for ( $c = 0; $c < 4; ++$c ) {
			$body .= '<div class="card"><img src="images/art-' . ( ( $p + $s + $c ) % $asset_count ) . '.svg" alt="Art" width="300" height="200"><p>' . $lorem . '</p><a href="' . ( 0 === ( ( $p + $c ) % $page_count ) ? 'index' : 'page-' . ( ( $p + $c ) % $page_count ) ) . '.html">More</a></div>';
		}
		$body .= '</section>';
	}
	$files[] = array(
		'path'    => 'website/' . ( 0 === $p ? 'index' : 'page-' . $p ) . '.html',
		'content' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Page ' . $p . '</title><link rel="stylesheet" href="styles.css"></head><body>' . $body . '</main></body></html>',
	);
}
$source_bytes = array_sum( array_map( static fn ( array $file ): int => strlen( (string) ( $file['content'] ?? '' ) ), $files ) );

$GLOBALS['ssi_harness_filters']['static_site_importer_direct_artifact_run_policy'][] = static function ( array $policy ): array {
	$policy['yield_after_compose']            = true;
	$policy['compile_in_process_pages']       = 4;
	$policy['compile_batches_per_invocation'] = 1000;
	$policy['max_invocation_seconds']         = 3600.0;
	return $policy;
};

$reset_runtime = new ReflectionProperty( Static_Site_Importer_Direct_Artifact_Import::class, 'checkpoint_read_cache' );
$steps         = array();
$request       = array(
	'operation' => 'apply',
	'slug'      => 'memory-bound',
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => $files,
	),
);
unset( $files );
$result = array();
for ( $attempt = 0; $attempt < 40; ++$attempt ) {
	$reset_runtime->setValue( null, array() );
	gc_collect_cycles();
	gc_mem_caches();
	$start = memory_get_usage();
	memory_reset_peak_usage();
	$result = Static_Site_Importer_Canonical_Import_Service::import( $request );
	$steps[] = array(
		'reason' => (string) ( $result['continuation_reason'] ?? 'terminal' ),
		'growth' => memory_get_peak_usage() - $start,
	);
	if ( empty( $result['success'] ) ) {
		throw new RuntimeException( 'The memory fixture failed to import: ' . wp_json_encode( $result['error'] ?? $result ) );
	}
	if ( empty( $result['continuation'] ) ) {
		break;
	}
	$request = static_site_importer_cli_apply_import_id( $request, (string) ( $result['import_id'] ?? '' ) );
	if ( 'dependencies_prepared' === ( $result['continuation_reason'] ?? '' ) ) {
		$request['runtime_lifecycle_phase']      = 'resume';
		$request['runtime_lifecycle_request_id'] = (string) ( $result['result']['fresh_runtime']['request_id'] ?? '' );
		$request['runtime_lifecycle_checkpoint'] = (string) ( $result['result']['runtime_lifecycle_checkpoint'] ?? '' );
	}
}
$assert( empty( $result['continuation'] ) && ! empty( $result['success'] ), 'the memory fixture imports to a terminal result' );
$assert( $page_count === count( $GLOBALS['ssi_harness_imports'][0]['plan']['pages'] ?? array() ), 'every generated page is materialized' );

if ( getenv( 'SSI_RUNTIME_MEMORY_REPORT' ) ) {
	fwrite( STDERR, sprintf( "source_bytes=%d\n", $source_bytes ) );
	foreach ( $steps as $index => $step ) {
		fwrite( STDERR, sprintf( "step %d %-22s growth=%.2f x source (%d bytes)\n", $index + 1, $step['reason'], $step['growth'] / $source_bytes, $step['growth'] ) );
	}
}

// The last two steps start from a durable composition: they read it, the source and
// nothing else. A leaked shared plan or receipt set is what breaks these bounds.
// Measured on the generated site: 2.98 x and 5.24 x now, 4.06 x and 6.32 x before the
// shared plan, page plans and receipts were skipped once composition is durable.
$apply_bounds = array( 3.5, 5.8 );
foreach ( array_slice( $steps, -2 ) as $index => $step ) {
	$assert( $step['growth'] <= $apply_bounds[ $index ] * $source_bytes, sprintf( 'apply step %d grew the heap by %.2f x the source bytes, above the %.1f x bound', $index + 1, $step['growth'] / $source_bytes, $apply_bounds[ $index ] ) );
}
// Compilation and composition legitimately dominate (16.6 x measured); this only catches a runaway.
$worst = max( array_column( $steps, 'growth' ) );
$assert( $worst <= 22.0 * $source_bytes, sprintf( 'the worst step grew the heap by %.2f x the source bytes, above the 22.0 x bound', $worst / $source_bytes ) );

ssi_harness_cleanup();

if ( $failures ) {
	fwrite( STDERR, "Import runtime memory smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo 'Import runtime memory smoke passed (' . count( $steps ) . " steps).\n";
