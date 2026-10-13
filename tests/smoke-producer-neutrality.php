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
 * includes/, lib/, the plugin bootstrap and tools/ (tests excluded), with no
 * exemptions: operator tools take their capture producer as configuration. The
 * capture-shaped form fixtures (kmr-form-*.json, *-form*.json.gz) are scanned
 * too, with JSON `\u002d` escapes spelled out. It also
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
foreach ( $files as $relative ) {
	$lines = file( $root . '/' . $relative, FILE_IGNORE_NEW_LINES );
	foreach ( false === $lines ? array() : $lines as $index => $line ) {
		if ( 1 !== preg_match( SSI_PRODUCER_NAME_PATTERN, $line, $match ) ) {
			continue;
		}
		$failures[] = sprintf( '%s:%d names a capture producer or a capture name outside data-blocks-engine-capture- (%s); read it through a neutral contract instead.', $relative, $index + 1, $match[0] );
	}
}
if ( count( $files ) < 50 ) {
	$failures[] = 'Scanned only ' . count( $files ) . ' files; the scan roots moved.';
}

/**
 * Decode a fixture's text and spell out JSON `\u002d` escapes (block-comment
 * attributes escape `--`), so an escaped `--dla-mobile` id is matched too.
 *
 * @param string $path Fixture path, optionally gzip-compressed.
 */
function ssi_neutrality_fixture_text( string $path ): string {
	$raw = (string) file_get_contents( $path );
	if ( str_starts_with( $raw, "\x1f\x8b" ) ) {
		$raw = (string) gzdecode( $raw );
	}
	return (string) preg_replace( '/\\\\+u002d/i', '-', $raw );
}

// Capture-shaped form fixtures feed the locked compiler, which reads only the
// one capture prefix, so they must carry the current names as well.
$fixtures = array_merge( glob( $root . '/tests/fixtures/kmr-form-*.json' ), glob( $root . '/tests/fixtures/*-form*.json.gz' ) );
foreach ( $fixtures as $fixture ) {
	if ( 0 < preg_match_all( SSI_PRODUCER_NAME_PATTERN, ssi_neutrality_fixture_text( $fixture ), $matches ) ) {
		$failures[] = sprintf( '%s carries %d capture name(s) outside data-blocks-engine-capture- (first: %s); rename them by the one-prefix rule.', substr( $fixture, strlen( $root ) + 1 ), count( $matches[0] ), $matches[0][0] );
	}
}
if ( count( $fixtures ) < 6 ) {
	$failures[] = 'Scanned only ' . count( $fixtures ) . ' capture-shaped form fixtures; the fixtures moved.';
}

// The pattern itself must keep catching every spelling it guards.
foreach ( array( 'data-dla-device-style', 'DLA_DEVICE_SELECTION_SOURCE', 'dla_execution', 'Data-Liberation', 'data-liberation-agent', 'data-liberation/capture-receipt/v1', 'class="x dla-member-login-wix"', 'hero--dla-mobile', 'capture-findings.json', 'Capture_Findings', 'a DLA run', 'class="blocks-engine-capture-member-login-wix"' ) as $sample ) {
	if ( 1 !== preg_match( SSI_PRODUCER_NAME_PATTERN, $sample ) ) {
		$failures[] = "The pattern misses '$sample'.";
	}
}
$escaped_sample = tempnam( sys_get_temp_dir(), 'ssi-neutrality-' );
file_put_contents( $escaped_sample, (string) gzencode( '{"m":"<!-- wp:group {\\"anchor\\":\\"comp-x\\\\u002d\\\\u002ddla-mobile\\"} -->"}' ) );
if ( 1 !== preg_match( SSI_PRODUCER_NAME_PATTERN, ssi_neutrality_fixture_text( $escaped_sample ) ) ) {
	$failures[] = 'The fixture scan misses an escaped --dla- id in gzip-compressed block markup.';
}
unlink( $escaped_sample );
foreach ( array( 'data-blocks-engine-capture-device-style', 'class="data-blocks-engine-capture-member-login-wix"', 'hero--data-blocks-engine-capture-mobile', 'blocks-engine-capture/capture-receipt/v1', 'deadlast', 'idla', 'source-findings' ) as $sample ) {
	if ( 1 === preg_match( SSI_PRODUCER_NAME_PATTERN, $sample ) ) {
		$failures[] = "The pattern flags '$sample'.";
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo 'smoke-producer-neutrality: ok (' . count( $files ) . ' files, ' . count( $fixtures ) . " form fixtures)\n";
