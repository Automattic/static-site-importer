<?php
/**
 * Equivalence gate for the producer-neutral website-artifact inputs.
 *
 * Proves the neutral contracts lose no power against the producer-specific
 * readers they replaced (Automattic/data-liberation-agent#787):
 *
 * 1. Source findings. The committed baseline is the import of
 *    `capture-findings.json` (the producer-specific file) by the plugin before
 *    the change. This checkout imports the equivalent `source-findings.json`,
 *    whose rows carry the loss class, unit and owning layer the old registry
 *    assigned, and must yield identical loss rows, counts, acceptability and
 *    compact summaries. A second run with another owning layer must differ
 *    only in that label, which now comes from the row.
 * 2. Capture attributes. The baseline is the client-script policy and the
 *    member sign-in rewrite applied to pages that use the producer-specific
 *    attribute names. This checkout applies them to the same pages with the
 *    names mapped to the Blocks Engine capture vocabulary and must produce the
 *    same output under that mapping.
 *
 * Run from the repository root:
 * php tests/smoke-source-findings-equivalence.php
 *
 * Re-record the baseline from a checkout of the earlier plugin revision
 * (Composer dependencies installed):
 * php tests/smoke-source-findings-equivalence.php --record=<checkout>
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, Squiz.Commenting.FunctionComment.Missing, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone CLI smoke.

const SSI_EQUIVALENCE_DIR = __DIR__ . '/fixtures/source-findings-equivalence';

/**
 * Map producer-specific capture names onto the Blocks Engine capture vocabulary (Automattic/blocks-engine#2725).
 *
 * One prefix for everything: attributes `data-dla-<name>` and `data-liberation-<name>`, classes `dla-<name>`
 * and the id suffix `--dla-` all become `data-blocks-engine-capture-<name>`; sidecar schemas
 * `data-liberation/<name>/v<n>` become `blocks-engine-capture/<name>/v<n>`.
 */
function ssi_equivalence_rename( string $text ): string {
	$text = str_replace( array( 'data-liberation/', '--dla-', 'data-dla-', 'data-liberation-' ), array( 'blocks-engine-capture/', '--data-blocks-engine-capture-', 'data-blocks-engine-capture-', 'data-blocks-engine-capture-' ), $text );
	return (string) preg_replace( '/(?<![A-Za-z0-9_-])dla-/', 'data-blocks-engine-capture-', $text );
}

/** Drop run-specific values so two imports of the same artifact compare equal. */
function ssi_equivalence_stable( $value, string $temp_root ) {
	if ( is_array( $value ) ) {
		$stable = array();
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, array( 'import_run_id', 'import_id', 'generated_at', 'started_at', 'completed_at', 'created_at', 'updated_at', 'duration_ms', 'elapsed_ms' ), true ) ) {
				continue;
			}
			$stable[ $key ] = ssi_equivalence_stable( $item, $temp_root );
		}
		return $stable;
	}
	return is_string( $value ) ? str_replace( $temp_root, '<temp>', $value ) : $value;
}

/** Child process: import one findings file with the plugin at `$root` and print the comparable result. */
function ssi_equivalence_findings_part( string $root, string $artifact_path, string $bytes ): array {
	$GLOBALS['ssi_harness_root'] = $root;
	require __DIR__ . '/fixtures/source-findings-import-harness.php';
	$import  = ssi_source_findings_import( array( $artifact_path => $bytes ) );
	$stored  = $import['stored'];
	$summary = $import['summary'];
	$losses  = array_values(
		array_filter(
			$stored['diagnostics'] ?? array(),
			static fn ( $row ): bool => is_array( $row ) && in_array( $row['acceptability'] ?? '', array( 'accepted_reported_loss', 'unacceptable_imported_output_defect' ), true )
		)
	);
	$pages   = array_column( $import['plan']['pages'] ?? array(), 'source_path' );
	sort( $pages );
	return ssi_equivalence_stable(
		array(
			'success'                => ! empty( $import['result']['success'] ),
			'pages'                  => $pages,
			'loss_rows'              => $losses,
			'counts'                 => array(
				'loss_class_summary' => $summary['loss_class_summary'] ?? null,
				'loss_counts'        => $summary['loss_counts'] ?? null,
				'loss_count'         => $summary['loss_count'] ?? null,
			),
			'acceptability'          => array(
				'status'      => $summary['status'] ?? null,
				'fail_import' => $summary['fail_import'] ?? null,
				'rows'        => array_count_values( array_column( $stored['diagnostics'] ?? array(), 'acceptability' ) ),
			),
			'compact_summary'        => $stored['compact_summary'] ?? null,
			'inline_compact_summary' => $summary,
		),
		(string) $GLOBALS['test_root']
	);
}

/** Child process: apply the plugin at `$root` to pages carrying capture attributes. */
function ssi_equivalence_attributes_part( string $root, bool $rename ): array {
	define( 'ABSPATH', $root . '/' );
	$GLOBALS['ssi_link_home'] = 'https://playground.test/scope:abc/';
	$_SERVER['HTTP_HOST']     = 'playground.test';
	$_SERVER['REQUEST_URI']   = '/scope:abc/bylaws/';
	require __DIR__ . '/fixtures/link-runtime-stubs.php';
	foreach ( array( 'capture-evidence', 'client-script-policy', 'internal-link-runtime' ) as $name ) {
		$file = $root . '/includes/class-static-site-importer-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
	$legacy = json_decode( (string) file_get_contents( SSI_EQUIVALENCE_DIR . '/legacy-attributes.json' ), true );
	$map    = static fn ( string $text ): string => $rename ? ssi_equivalence_rename( $text ) : $text;
	$page   = $map( $legacy['device_page'] );
	$policy = Static_Site_Importer_Client_Script_Policy::apply(
		array(
			'entrypoint' => 'website/index.html',
			'files'      => array(
				array(
					'path'      => 'website/index.html',
					'mime_type' => 'text/html',
					'content'   => $page,
				),
			),
		),
		array()
	);
	$report = $policy['report'];
	foreach ( array( 'preserved', 'dropped', 'quarantined' ) as $bucket ) {
		// A row's sha256 hashes the script bytes, which include the attribute names.
		$report[ $bucket ] = array_map( static fn ( array $row ): array => array_diff_key( $row, array( 'sha256' => true ) ), $report[ $bucket ] ?? array() );
	}
	$links = array();
	foreach ( $legacy['member_login'] as $label => $markup ) {
		$links[ $label ] = array(
			'content'        => Static_Site_Importer_Internal_Link_Runtime::filter_content( $map( $markup ) ),
			'rendered_block' => Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $map( $markup ) ),
		);
	}
	return array(
		'device_page'   => (string) $policy['artifact']['files'][0]['content'],
		'device_report' => array(
			'preserved'   => $report['preserved'],
			'dropped'     => $report['dropped'],
			'quarantined' => $report['quarantined'],
		),
		'member_login'  => $links,
	);
}

/** Run one part in a fresh PHP process, so each plugin revision loads alone. */
function ssi_equivalence_run( array $args ): array {
	$command = array_merge( array( PHP_BINARY, __FILE__ ), $args );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$stdout  = stream_get_contents( $pipes[1] );
	$stderr  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	$result = json_decode( (string) $stdout, true );
	if ( 0 !== $status || ! is_array( $result ) ) {
		fwrite( STDERR, "FAIL: part failed: " . implode( ' ', $args ) . "\n" . $stderr . substr( (string) $stdout, 0, 2000 ) . "\n" );
		exit( 1 );
	}
	return $result;
}

/** Report the first path where two values differ. */
function ssi_equivalence_diff( $expected, $actual, string $path = '$' ): ?string {
	if ( is_array( $expected ) && is_array( $actual ) ) {
		foreach ( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) ) as $key ) {
			if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) {
				return "$path.$key is " . ( array_key_exists( $key, $expected ) ? 'missing' : 'unexpected' );
			}
			$diff = ssi_equivalence_diff( $expected[ $key ], $actual[ $key ], "$path.$key" );
			if ( null !== $diff ) {
				return $diff;
			}
		}
		return array_keys( $expected ) === array_keys( $actual ) ? null : "$path key order differs";
	}
	return $expected === $actual ? null : "$path: expected " . json_encode( $expected ) . ', got ' . json_encode( $actual );
}

$options = getopt( '', array( 'part:', 'root:', 'path:', 'file:', 'rename', 'record:' ) );

if ( isset( $options['part'] ) ) {
	$root   = rtrim( (string) $options['root'], '/' );
	$result = 'findings' === $options['part']
		? ssi_equivalence_findings_part( $root, (string) $options['path'], (string) file_get_contents( (string) $options['file'] ) )
		: ssi_equivalence_attributes_part( $root, isset( $options['rename'] ) );
	echo json_encode( $result );
	exit( 0 );
}

$baseline_path = SSI_EQUIVALENCE_DIR . '/expected-from-main.json';

if ( isset( $options['record'] ) ) {
	$root     = rtrim( (string) realpath( (string) $options['record'] ), '/' );
	$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );
	$baseline = array(
		'recorded_from' => 'static-site-importer@' . $revision,
		'command'       => 'php tests/smoke-source-findings-equivalence.php --record=<checkout>',
		'findings'      => ssi_equivalence_run( array( '--part=findings', '--root=' . $root, '--path=website/capture-findings.json', '--file=' . SSI_EQUIVALENCE_DIR . '/capture-findings.json' ) ),
		'attributes'    => ssi_equivalence_run( array( '--part=attributes', '--root=' . $root ) ),
	);
	file_put_contents( $baseline_path, json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo "recorded $baseline_path from {$baseline['recorded_from']}\n";
	exit( 0 );
}

$failures = array();
$assert   = static function ( ?string $diff, string $label ) use ( &$failures ): void {
	if ( null !== $diff ) {
		$failures[] = "$label: $diff";
	}
};
$baseline = json_decode( (string) file_get_contents( $baseline_path ), true );
$root     = dirname( __DIR__ );
$neutral  = SSI_EQUIVALENCE_DIR . '/source-findings.json';

// 1. The neutral findings file imports exactly like the producer file did.
$actual   = ssi_equivalence_run( array( '--part=findings', '--root=' . $root, '--path=website/source-findings.json', '--file=' . $neutral ) );
$expected = $baseline['findings'];
$assert( ssi_equivalence_diff( $expected, $actual ), 'findings' );
$assert( 12 === count( $actual['loss_rows'] ) ? null : 'expected 12 loss rows, got ' . count( $actual['loss_rows'] ), 'findings-row-count' );

// The owning layer is the row's own label: another producer name changes only that label.
$document = json_decode( (string) file_get_contents( $neutral ), true );
foreach ( $document['findings'] as $index => $finding ) {
	$document['findings'][ $index ]['owning_layer'] = 'example-capture';
}
$relabelled = tempnam( sys_get_temp_dir(), 'ssi-source-findings-' );
file_put_contents( $relabelled, json_encode( $document ) );
$other = ssi_equivalence_run( array( '--part=findings', '--root=' . $root, '--path=website/source-findings.json', '--file=' . $relabelled ) );
unlink( $relabelled );
$relabel = static fn ( $value ) => json_decode( str_replace( 'data-liberation-agent', 'example-capture', (string) json_encode( $value ) ), true );
$assert( ssi_equivalence_diff( $relabel( $expected ), $other ), 'findings-owning-layer-from-row' );
$assert( 12 === count( array_filter( $other['loss_rows'], static fn ( array $row ): bool => 'example-capture' === $row['owning_layer'] && 'example-capture' === $row['type'] ) ) ? null : 'rows do not carry the row owning layer', 'findings-owning-layer-label' );

// 2. Renamed capture attributes produce the same output as the producer names did.
$expected_attributes = json_decode( ssi_equivalence_rename( (string) json_encode( $baseline['attributes'] ) ), true );
$actual_attributes   = ssi_equivalence_run( array( '--part=attributes', '--root=' . $root, '--rename' ) );
$assert( ssi_equivalence_diff( $expected_attributes, $actual_attributes ), 'attributes' );
$assert( 2 === count( $actual_attributes['device_report']['preserved'] ) ? null : 'device-selection scripts were not preserved', 'attributes-device-scripts-preserved' );
$legacy = json_decode( (string) file_get_contents( SSI_EQUIVALENCE_DIR . '/legacy-attributes.json' ), true );
$assert( ssi_equivalence_rename( $legacy['device_page'] ) === file_get_contents( __DIR__ . '/fixtures/device-selection/index.html' ) ? null : 'tests/fixtures/device-selection/index.html is not the mapped legacy page', 'attributes-device-fixture-is-the-mapped-page' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-source-findings-equivalence: ok (baseline {$baseline['recorded_from']})\n";
