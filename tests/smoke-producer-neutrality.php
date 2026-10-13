<?php
/**
 * Producer-neutrality guard.
 *
 * SSI reads website artifacts through contracts it owns (source findings) or
 * that Blocks Engine publishes (capture evidence), so any capture producer can
 * feed it. This guard fails when runtime source names one specific producer
 * again: `dla` as a word (`-` and `_` count as separators, so `data-dla-`,
 * ` dla-<class>`, `--dla-<variant>` and `DLA_` all match), `data-liberation`
 * (including `data-liberation/<schema>` ids), `liberation-agent`, or
 * `capture-findings` / `capture_findings`, matched case-insensitively in
 * includes/, lib/, the plugin bootstrap and tools/ (tests excluded). It also
 * fails on a capture name outside Blocks Engine's one prefix: every capture
 * attribute and class is `data-blocks-engine-capture-<name>`, so a bare
 * `blocks-engine-capture-<name>` (an engine-generated class namespace) is
 * wrong; schema ids `blocks-engine-capture/<name>/v<n>` are fine. See
 * Automattic/data-liberation-agent#787 and Automattic/blocks-engine#2725.
 *
 * Run from the repository root:
 * php tests/smoke-producer-neutrality.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- Standalone CLI smoke; WordPress is not loaded.

const SSI_PRODUCER_NAME_PATTERN = '/(?<![a-z0-9])dla(?![a-z0-9])|data-liberation|liberation-agent|capture[-_]findings|(?<![a-z0-9-])blocks-engine-capture-/i';

/**
 * Operator tools that deliberately drive one producer release. Each entry must
 * still match (shrink-only): delete it when the tool stops naming the producer.
 */
const SSI_PRODUCER_NAME_EXEMPT = array(
	'tools/url-loop-intake.mjs'     => 'URL-loop operator harness that pins and runs one published capture release.',
	'tools/url-loop-controller.mjs' => 'URL-loop operator harness that records the pinned capture release identity.',
);

$root  = dirname( __DIR__ );
$files = array( 'static-site-importer.php' );
foreach ( array( 'includes', 'lib', 'tools' ) as $directory ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( $file->isFile() && 1 === preg_match( '/\.(php|mjs|cjs|js|sh)$/', $relative ) && 1 !== preg_match( '/\.test\.[cm]?js$/', $relative ) ) {
			$files[] = $relative;
		}
	}
}
sort( $files );

$failures = array();
$exempted = array();
foreach ( $files as $relative ) {
	$lines = file( $root . '/' . $relative, FILE_IGNORE_NEW_LINES );
	foreach ( false === $lines ? array() : $lines as $index => $line ) {
		if ( 1 !== preg_match( SSI_PRODUCER_NAME_PATTERN, $line, $match ) ) {
			continue;
		}
		if ( isset( SSI_PRODUCER_NAME_EXEMPT[ $relative ] ) ) {
			$exempted[ $relative ] = true;
			continue;
		}
		$failures[] = sprintf( '%s:%d names a capture producer or a capture name outside data-blocks-engine-capture- (%s); read it through a neutral contract instead.', $relative, $index + 1, $match[0] );
	}
}
foreach ( array_keys( SSI_PRODUCER_NAME_EXEMPT ) as $relative ) {
	if ( ! isset( $exempted[ $relative ] ) ) {
		$failures[] = "$relative no longer names a producer; remove its exemption.";
	}
}
if ( count( $files ) < 50 ) {
	$failures[] = 'Scanned only ' . count( $files ) . ' files; the scan roots moved.';
}

// The pattern itself must keep catching every spelling it guards.
foreach ( array( 'data-dla-device-style', 'DLA_DEVICE_SELECTION_SOURCE', 'dla_execution', 'Data-Liberation', 'data-liberation-agent', 'data-liberation/capture-receipt/v1', 'class="x dla-member-login-wix"', 'hero--dla-mobile', 'capture-findings.json', 'Capture_Findings', 'a DLA run', 'class="blocks-engine-capture-member-login-wix"' ) as $sample ) {
	if ( 1 !== preg_match( SSI_PRODUCER_NAME_PATTERN, $sample ) ) {
		$failures[] = "The pattern misses '$sample'.";
	}
}
foreach ( array( 'data-blocks-engine-capture-device-style', 'class="data-blocks-engine-capture-member-login-wix"', 'hero--data-blocks-engine-capture-mobile', 'blocks-engine-capture/capture-receipt/v1', 'deadlast', 'idla', 'source-findings' ) as $sample ) {
	if ( 1 === preg_match( SSI_PRODUCER_NAME_PATTERN, $sample ) ) {
		$failures[] = "The pattern flags '$sample'.";
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo 'smoke-producer-neutrality: ok (' . count( $files ) . " files)\n";
