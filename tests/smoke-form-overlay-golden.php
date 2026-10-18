<?php
/**
 * Golden-output harness for provider form overlay CSS (front end and editor).
 *
 * Every `tests/fixtures/form-overlay-golden/*.html` fixture is a small, neutral,
 * self-contained source page with one form and an inline `<style>`. Each one is
 * run through the producer and the seeder exactly as an import does:
 *
 *   ArtifactCompiler::compile()
 *   -> Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest()
 *   -> Static_Site_Importer_Form_Seeder::seed()
 *
 * The seeded row is written as a readable text snapshot to
 * `tests/fixtures/form-overlay-golden/__snapshots__/<fixture>.snap`, one section
 * per part: `status`, then each of the `provider_layout_overlay_css` parts
 * `css`, `editor_css`, `context_css`, `editor_context_css` that the row carries,
 * then the projected `block_markup`. CSS is split one rule per line (nested
 * at-rule bodies indented) so a changed declaration diffs as one line; the first
 * line of every CSS section is the sha256 of the raw compiled string, so the
 * comparison is still byte-exact even though inter-rule whitespace is normalised.
 *
 * The test fails, naming the fixture and the part with a unified-style line
 * diff, whenever compiled output differs from the committed snapshot. A refactor
 * of the overlay or editor projection must keep every snapshot identical.
 *
 * Every fixture must seed with status `mapped`, except fixtures whose file name
 * starts with `fallback-`: those are deliberate fallback cases and must NOT map.
 *
 * Run from the repository root:
 *   php tests/smoke-form-overlay-golden.php
 *
 * Regenerate the committed snapshots after an intended output change (review
 * the resulting `git diff` before committing it):
 *   SSI_UPDATE_GOLDEN=1 php tests/smoke-form-overlay-golden.php
 *
 * The Blocks Engine transformer is loaded from STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
 * when set, otherwise from vendor/automattic/blocks-engine-php-transformer.
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

/**
 * Write text to a standard stream without WordPress output escaping concerns.
 *
 * @param string $stream `php://stdout` or `php://stderr`.
 * @param string $text   Text to write.
 */
function ssi_golden_write( string $stream, string $text ): void {
	$handle = new SplFileObject( $stream, 'w' );
	$handle->fwrite( $text );
}

/**
 * Read a whole local file.
 *
 * @param string $path Absolute file path.
 * @return string|null File contents, or null when the file is unreadable.
 */
function ssi_golden_read( string $path ): ?string {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return null;
	}
	$file = new SplFileObject( $path, 'r' );
	$size = $file->getSize();
	if ( false === $size || 0 === $size ) {
		return '';
	}
	$contents = $file->fread( $size );
	return false === $contents ? null : $contents;
}

/**
 * Overwrite a local file.
 *
 * @param string $path     Absolute file path.
 * @param string $contents File contents.
 */
function ssi_golden_store( string $path, string $contents ): void {
	$file = new SplFileObject( $path, 'w' );
	$file->fwrite( $contents );
}

/**
 * Split compiled CSS into one rule per line. Comments and top-level rules get a
 * line each; an at-rule block opens on its own line, its nested rules follow
 * indented by two spaces, and its closing brace closes it on its own line.
 * Braces inside strings, comments and parentheses never split a rule.
 *
 * @param string $css Compiled CSS.
 * @return array<int,string>
 */
function ssi_golden_css_lines( string $css ): array {
	$lines  = array();
	$buffer = '';
	$depth  = 0;
	$parens = 0;
	$quote  = '';
	$length = strlen( $css );
	$flush  = static function ( string $text, int $indent ) use ( &$lines ): void {
		$text = trim( preg_replace( '/\s*\n\s*/', ' ', $text ) ?? $text );
		if ( '' !== $text ) {
			$lines[] = str_repeat( '  ', $indent ) . $text;
		}
	};
	for ( $i = 0; $i < $length; ++$i ) {
		$char = $css[ $i ];
		if ( '' !== $quote ) {
			$buffer .= $char;
			if ( '\\' === $char && $i + 1 < $length ) {
				$buffer .= $css[ ++$i ];
			} elseif ( $char === $quote ) {
				$quote = '';
			}
			continue;
		}
		if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
			$end = strpos( $css, '*/', $i + 2 );
			$end = false === $end ? $length : $end + 2;
			$flush( $buffer, $depth );
			$flush( substr( $css, $i, $end - $i ), $depth );
			$buffer = '';
			$i      = $end - 1;
			continue;
		}
		if ( '"' === $char || "'" === $char ) {
			$quote   = $char;
			$buffer .= $char;
			continue;
		}
		if ( '(' === $char ) {
			++$parens;
		} elseif ( ')' === $char && $parens > 0 ) {
			--$parens;
		}
		if ( 0 < $parens ) {
			$buffer .= $char;
			continue;
		}
		if ( '{' === $char ) {
			if ( str_starts_with( ltrim( $buffer ), '@' ) ) {
				$flush( $buffer . '{', $depth );
				$buffer = '';
				++$depth;
				continue;
			}
			// A declaration block: read through its matching brace onto one line.
			$inner = 1;
			$close = $i + 1;
			while ( $close < $length && 0 < $inner ) {
				if ( '{' === $css[ $close ] ) {
					++$inner;
				} elseif ( '}' === $css[ $close ] ) {
					--$inner;
				}
				++$close;
			}
			$flush( $buffer . substr( $css, $i, $close - $i ), $depth );
			$i      = $close - 1;
			$buffer = '';
			continue;
		}
		if ( '}' === $char ) {
			$flush( $buffer, $depth );
			$buffer = '';
			$depth  = max( 0, $depth - 1 );
			$flush( '}', $depth );
			continue;
		}
		$buffer .= $char;
	}
	$flush( $buffer, $depth );
	return $lines;
}

/**
 * Render one seeded row as snapshot parts, in a fixed order.
 *
 * @param array<string,mixed> $row Seeded form row.
 * @return array<string,string> Part name => part body.
 */
function ssi_golden_parts( array $row ): array {
	$status = $row['status'] ?? null;
	$parts  = array( 'status' => is_string( $status ) ? $status : '(none)' );
	$css    = is_array( $row['provider_layout_overlay_css'] ?? null ) ? $row['provider_layout_overlay_css'] : array();
	foreach ( array( 'css', 'editor_css', 'context_css', 'editor_context_css' ) as $key ) {
		$value = $css[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			continue;
		}
		$parts[ $key ] = implode( "\n", array_merge( array( 'sha256: ' . hash( 'sha256', $value ) ), ssi_golden_css_lines( $value ) ) );
	}
	$markup                = $row['block_markup'] ?? null;
	$parts['block_markup'] = is_string( $markup ) ? rtrim( $markup, "\n" ) : '(none)';
	return $parts;
}

/**
 * Serialize snapshot parts into the committed text format.
 *
 * @param string               $fixture Fixture file name.
 * @param array<string,string> $parts   Part name => part body.
 */
function ssi_golden_serialize( string $fixture, array $parts ): string {
	$text = '# Form overlay golden snapshot for tests/fixtures/form-overlay-golden/' . $fixture . "\n"
		. "# Regenerate: SSI_UPDATE_GOLDEN=1 php tests/smoke-form-overlay-golden.php\n";
	foreach ( $parts as $name => $body ) {
		$text .= "\n=== " . $name . " ===\n" . $body . "\n";
	}
	return $text;
}

/**
 * Parse a committed snapshot back into its parts.
 *
 * @param string $text Snapshot text.
 * @return array<string,string> Part name => part body.
 */
function ssi_golden_unserialize( string $text ): array {
	$parts   = array();
	$current = null;
	$body    = array();
	foreach ( explode( "\n", $text ) as $line ) {
		if ( 1 === preg_match( '/^=== ([a-z_]+) ===$/D', $line, $match ) ) {
			if ( null !== $current ) {
				$parts[ $current ] = rtrim( implode( "\n", $body ), "\n" );
			}
			$current = $match[1];
			$body    = array();
			continue;
		}
		if ( null !== $current ) {
			$body[] = $line;
		}
	}
	if ( null !== $current ) {
		$parts[ $current ] = rtrim( implode( "\n", $body ), "\n" );
	}
	return $parts;
}

/**
 * Unified-style line diff of two texts, limited to the first differing lines.
 *
 * @param string $expected  Committed text.
 * @param string $actual    Compiled text.
 * @param int    $max_lines Maximum number of differing lines to print.
 */
function ssi_golden_diff( string $expected, string $actual, int $max_lines = 40 ): string {
	$old       = explode( "\n", $expected );
	$new       = explode( "\n", $actual );
	$old_count = count( $old );
	$new_count = count( $new );
	$prefix    = 0;
	while ( $prefix < $old_count && $prefix < $new_count && $old[ $prefix ] === $new[ $prefix ] ) {
		++$prefix;
	}
	$suffix = 0;
	while ( $suffix < $old_count - $prefix && $suffix < $new_count - $prefix && $old[ $old_count - 1 - $suffix ] === $new[ $new_count - 1 - $suffix ] ) {
		++$suffix;
	}
	$old_mid = array_slice( $old, $prefix, $old_count - $prefix - $suffix );
	$new_mid = array_slice( $new, $prefix, $new_count - $prefix - $suffix );
	$rows    = count( $old_mid );
	$cols    = count( $new_mid );
	// Longest common subsequence over the differing middle.
	$lcs = array_fill( 0, $rows + 1, array_fill( 0, $cols + 1, 0 ) );
	for ( $i = $rows - 1; $i >= 0; --$i ) {
		for ( $j = $cols - 1; $j >= 0; --$j ) {
			$lcs[ $i ][ $j ] = $old_mid[ $i ] === $new_mid[ $j ] ? $lcs[ $i + 1 ][ $j + 1 ] + 1 : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
		}
	}
	$ops = array();
	for ( $c = max( 0, $prefix - 2 ); $c < $prefix; ++$c ) {
		$ops[] = '  ' . $old[ $c ];
	}
	$i = 0;
	$j = 0;
	while ( $i < $rows || $j < $cols ) {
		if ( $i < $rows && $j < $cols && $old_mid[ $i ] === $new_mid[ $j ] ) {
			$ops[] = '  ' . $old_mid[ $i ];
			++$i;
			++$j;
		} elseif ( $i < $rows && ( $j >= $cols || $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) ) {
			$ops[] = '- ' . $old_mid[ $i ];
			++$i;
		} else {
			$ops[] = '+ ' . $new_mid[ $j ];
			++$j;
		}
	}
	$context_end = min( $old_count, $old_count - $suffix + 2 );
	for ( $c = $old_count - $suffix; $c < $context_end; ++$c ) {
		$ops[] = '  ' . $old[ $c ];
	}
	$out     = '    @@ -' . ( $prefix + 1 ) . ',' . $rows . ' +' . ( $prefix + 1 ) . ',' . $cols . " @@\n";
	$changed = 0;
	$total   = 0;
	foreach ( $ops as $op ) {
		if ( '  ' !== substr( $op, 0, 2 ) ) {
			++$total;
		}
	}
	foreach ( $ops as $op ) {
		$differs = '  ' !== substr( $op, 0, 2 );
		if ( $differs && $changed >= $max_lines ) {
			break;
		}
		if ( $differs ) {
			++$changed;
		}
		$out .= '    ' . $op . "\n";
	}
	if ( $total > $changed ) {
		$out .= '    ... ' . ( $total - $changed ) . " more differing line(s)\n";
	}
	return $out;
}

/**
 * Compile, validate and seed one fixture page.
 *
 * @param string $html Fixture HTML.
 * @return array{row:array<string,mixed>,errors:array<int,string>}
 */
function ssi_golden_seed_fixture( string $html ): array {
	$errors   = array();
	$compiler = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
	$compiled = $compiler->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array( 'index.html' => $html ),
		)
	)->toArray();
	$forms    = is_array( $compiled['fallbacks'] ?? null ) ? array_values( $compiled['fallbacks'] ) : array();
	if ( 1 !== count( $forms ) ) {
		$errors[] = 'expected exactly one compiled form fallback, got ' . count( $forms );
	}
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array_slice( $forms, 0, 1 ) ) );
	foreach ( $validated['errors'] as $error ) {
		$errors[] = 'validate_forms_manifest: ' . implode( ' ', $error );
	}
	$seeded = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) );
	$rows   = is_array( $seeded['forms'] ?? null ) ? array_values( $seeded['forms'] ) : array();
	$row    = is_array( $rows[0] ?? null ) ? $rows[0] : array();
	if ( array() === $row ) {
		$errors[] = 'the seeder returned no form row';
	}
	return array(
		'row'    => $row,
		'errors' => $errors,
	);
}

$ssi_golden_transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' );
if ( ! is_string( $ssi_golden_transformer_root ) || '' === $ssi_golden_transformer_root ) {
	$ssi_golden_transformer_root = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer';
}
$ssi_golden_transformer = rtrim( $ssi_golden_transformer_root, '/\\' ) . '/php-transformer.php';
if ( ! is_readable( $ssi_golden_transformer ) ) {
	ssi_golden_write( 'php://stderr', "FAIL: the Blocks Engine transformer is unavailable at {$ssi_golden_transformer}\n" );
	exit( 1 );
}
require_once $ssi_golden_transformer;

$ssi_golden_dir       = __DIR__ . '/fixtures/form-overlay-golden';
$ssi_golden_snap_dir  = $ssi_golden_dir . '/__snapshots__';
$ssi_golden_update    = '1' === getenv( 'SSI_UPDATE_GOLDEN' );
$ssi_golden_fixtures  = glob( $ssi_golden_dir . '/*.html' );
$ssi_golden_fixtures  = false === $ssi_golden_fixtures ? array() : $ssi_golden_fixtures;
$ssi_golden_failures  = array();
$ssi_golden_parts     = 0;
$ssi_golden_written   = 0;
$ssi_golden_snapshots = array();
sort( $ssi_golden_fixtures, SORT_STRING );

if ( array() === $ssi_golden_fixtures ) {
	ssi_golden_write( 'php://stderr', "FAIL: no fixtures found in {$ssi_golden_dir}\n" );
	exit( 1 );
}
if ( ! is_dir( $ssi_golden_snap_dir ) ) {
	ssi_golden_write( 'php://stderr', "FAIL: the snapshot directory {$ssi_golden_snap_dir} is missing\n" );
	exit( 1 );
}

foreach ( $ssi_golden_fixtures as $ssi_golden_path ) {
	$ssi_golden_fixture  = basename( $ssi_golden_path );
	$ssi_golden_snap     = $ssi_golden_snap_dir . '/' . basename( $ssi_golden_fixture, '.html' ) . '.snap';
	$ssi_golden_html     = ssi_golden_read( $ssi_golden_path );
	$ssi_golden_fallback = str_starts_with( $ssi_golden_fixture, 'fallback-' );

	$ssi_golden_snapshots[] = basename( $ssi_golden_snap );
	if ( null === $ssi_golden_html ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] fixture is unreadable";
		continue;
	}
	$ssi_golden_result = ssi_golden_seed_fixture( $ssi_golden_html );
	foreach ( $ssi_golden_result['errors'] as $ssi_golden_error ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] {$ssi_golden_error}";
	}
	$ssi_golden_actual = ssi_golden_parts( $ssi_golden_result['row'] );
	if ( ! $ssi_golden_fallback && 'mapped' !== $ssi_golden_actual['status'] ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] status: expected mapped, got {$ssi_golden_actual['status']}";
	}
	if ( $ssi_golden_fallback && 'mapped' === $ssi_golden_actual['status'] ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] status: a fallback- fixture is a deliberate fallback case and must not map";
	}

	$ssi_golden_text = ssi_golden_serialize( $ssi_golden_fixture, $ssi_golden_actual );
	if ( $ssi_golden_update ) {
		if ( ssi_golden_read( $ssi_golden_snap ) !== $ssi_golden_text ) {
			ssi_golden_store( $ssi_golden_snap, $ssi_golden_text );
			++$ssi_golden_written;
		}
		$ssi_golden_parts += count( $ssi_golden_actual );
		continue;
	}

	$ssi_golden_committed = ssi_golden_read( $ssi_golden_snap );
	if ( null === $ssi_golden_committed ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] missing snapshot __snapshots__/" . basename( $ssi_golden_snap ) . '; run SSI_UPDATE_GOLDEN=1 php tests/smoke-form-overlay-golden.php';
		continue;
	}
	$ssi_golden_expected = ssi_golden_unserialize( $ssi_golden_committed );
	foreach ( array_unique( array_merge( array_keys( $ssi_golden_expected ), array_keys( $ssi_golden_actual ) ) ) as $ssi_golden_part ) {
		++$ssi_golden_parts;
		$ssi_golden_old = $ssi_golden_expected[ $ssi_golden_part ] ?? null;
		$ssi_golden_new = $ssi_golden_actual[ $ssi_golden_part ] ?? null;
		if ( $ssi_golden_old === $ssi_golden_new ) {
			continue;
		}
		if ( null === $ssi_golden_old ) {
			$ssi_golden_failures[] = "[{$ssi_golden_fixture}] part {$ssi_golden_part}: emitted but absent from the snapshot\n" . ssi_golden_diff( '', $ssi_golden_new ?? '' );
		} elseif ( null === $ssi_golden_new ) {
			$ssi_golden_failures[] = "[{$ssi_golden_fixture}] part {$ssi_golden_part}: in the snapshot but no longer emitted\n" . ssi_golden_diff( $ssi_golden_old, '' );
		} else {
			$ssi_golden_failures[] = "[{$ssi_golden_fixture}] part {$ssi_golden_part}: differs from the snapshot\n" . ssi_golden_diff( $ssi_golden_old, $ssi_golden_new );
		}
	}
	if ( $ssi_golden_committed !== $ssi_golden_text && array() === array_diff_assoc( $ssi_golden_expected, $ssi_golden_actual ) && array() === array_diff_assoc( $ssi_golden_actual, $ssi_golden_expected ) ) {
		$ssi_golden_failures[] = "[{$ssi_golden_fixture}] snapshot header or framing differs; regenerate it";
	}
}

$ssi_golden_existing = glob( $ssi_golden_snap_dir . '/*.snap' );
foreach ( false === $ssi_golden_existing ? array() : $ssi_golden_existing as $ssi_golden_stale ) {
	if ( in_array( basename( $ssi_golden_stale ), $ssi_golden_snapshots, true ) ) {
		continue;
	}
	$ssi_golden_failures[] = '[' . basename( $ssi_golden_stale ) . '] stale snapshot without a fixture; delete tests/fixtures/form-overlay-golden/__snapshots__/' . basename( $ssi_golden_stale );
}

$ssi_golden_count = count( $ssi_golden_fixtures );
if ( array() !== $ssi_golden_failures ) {
	ssi_golden_write( 'php://stderr', 'FAIL form overlay golden: ' . count( $ssi_golden_failures ) . " failure(s) across {$ssi_golden_count} fixtures\n\n" . implode( "\n", $ssi_golden_failures ) . "\n\nIf the change is intended, regenerate with: SSI_UPDATE_GOLDEN=1 php tests/smoke-form-overlay-golden.php\n" );
	exit( 1 );
}
if ( $ssi_golden_update ) {
	ssi_golden_write( 'php://stdout', "UPDATED form overlay golden: {$ssi_golden_count} fixtures, {$ssi_golden_parts} parts, {$ssi_golden_written} snapshot file(s) written\n" );
	exit( 0 );
}
ssi_golden_write( 'php://stdout', "PASS form overlay golden: {$ssi_golden_count} fixtures, {$ssi_golden_parts} parts match their snapshots\n" );
