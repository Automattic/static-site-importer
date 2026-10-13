<?php
/**
 * Omitted files and per-entry archive limits are findings (Automattic/static-site-importer#2054, item 7).
 *
 * Through the real canonical import service, Direct artifact import and
 * Receipt_Projection::compose():
 *
 * - a source file Blocks Engine drops at a declared compiler limit no longer
 *   refuses the site; it is reported as an `omitted_artifact_file(s)` accepted,
 *   reported loss, and `fail_on_quality` callers can still refuse it;
 * - a non-entry archive entry over a per-entry limit is skipped unread with a
 *   `static_site_importer_archive_entry_skipped` finding;
 * - the entrypoint over a per-entry limit, and the archive's total-size and
 *   entry-count limits, stay fail-closed.
 *
 * Run from the repository root:
 * php tests/smoke-omitted-files-findings.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone smoke writes real intake inputs.

require_once __DIR__ . '/fixtures/direct-import-harness.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$document = static fn ( string $title, string $body ): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="styles.css"></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
$pages    = array(
	'index.html' => $document( 'Home', '<p>Welcome.</p><a href="about.html">About</a>' ),
	'about.html' => $document( 'About', '<p>About us.</p><a href="index.html">Home</a>' ),
	'styles.css' => 'body{font-family:serif}',
);

// 1. Blocks Engine drops a source file at a declared byte limit. The import
// completes and the drop is an accepted, reported loss.
$compiler_limits = Static_Site_Importer_Compiler_Limits::resolve( array( 'max_file_bytes' => 4096 ) );
$dropped_files   = array_map(
	static fn ( string $path, string $content ): array => array(
		'path'    => 'website/' . $path,
		'content' => $content,
	),
	array_keys( $pages ),
	$pages
);
$dropped_files[] = array(
	'path'    => 'website/assets/extra.css',
	'content' => '.extra{color:red}' . str_repeat( ' ', 5000 ),
);
$result          = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'omitted-files',
		'source'    => array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'metadata'   => array( 'compiler_limits' => $compiler_limits ),
			'files'      => $dropped_files,
		),
	)
);
$assert( ! empty( $result['success'] ), 'compiler-drop: a site with an omitted source file imports instead of being refused: ' . wp_json_encode( $result['error'] ?? array() ) );
$import = ssi_harness_last_import();
$rows   = array_merge( ssi_harness_findings( $import['report'], 'omitted_artifact_file' ), ssi_harness_findings( $import['report'], 'omitted_artifact_files' ) );
$assert( 1 <= count( $rows ), 'compiler-drop: Receipt_Projection::compose() reports the omitted file' );
foreach ( $rows as $row ) {
	$assert(
		'omitted_unit' === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && 'file' === ( $row['unit'] ?? '' ) && 'blocks-engine' === ( $row['owning_layer'] ?? '' ),
		'compiler-drop: the omission is an accepted, reported blocks-engine file loss: ' . wp_json_encode( array_intersect_key( $row, array_flip( array( 'code', 'loss_class', 'acceptability', 'unit', 'owning_layer', 'reason_code' ) ) ) )
	);
}
$summary = $result['result']['import_report_summary'] ?? array();
$assert( false === ( $summary['fail_import'] ?? true ) && 1 <= ( $summary['loss_counts']['omitted_unit'] ?? 0 ), 'compiler-drop: the compact summary counts the loss without failing the import' );
$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( Static_Site_Importer_Import_Report::from_array( $import['report'] ), array( 'fail_on_quality' => true ) );
$assert( true === $quality['fail_import'] && in_array( 'dropped_artifact_files', $quality['failure_reasons'], true ), 'compiler-drop: a fail_on_quality caller can still refuse omitted files' );

// 2. REST ZIP per-entry limits: an oversize non-entry entry is skipped unread
// and reported; the import completes.
$zip_source = static function ( array $files ): array {
	$path = tempnam( sys_get_temp_dir(), 'ssi-omitted-zip-' );
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::OVERWRITE );
	foreach ( $files as $name => $content ) {
		$zip->addFromString( $name, $content );
		$zip->setCompressionName( $name, ZipArchive::CM_STORE );
	}
	$zip->close();
	$bytes = (string) file_get_contents( $path );
	unlink( $path );
	return array(
		'type' => 'zip',
		'zip'  => array(
			'name'           => 'site.zip',
			'content_base64' => base64_encode( $bytes ),
		),
	);
};
$archive_limits = static function ( array $overrides ): void {
	$GLOBALS['ssi_harness_filters']['static_site_importer_archive_limits'] = array(
		static fn ( array $limits ): array => array_merge( $limits, $overrides ),
	);
};
$archive_limits( array( 'max_entry_uncompressed_bytes' => 2048 ) );
$oversize = $pages + array( 'assets/clip.mp4' => str_repeat( 'v', 4096 ) );
$result   = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'oversize-entry',
		'source'    => $zip_source( $oversize ),
	)
);
$assert( ! empty( $result['success'] ), 'archive-entry: an oversize non-entry entry no longer rejects the archive: ' . wp_json_encode( $result['error'] ?? array() ) );
$import  = ssi_harness_last_import();
$skipped = ssi_harness_findings( $import['report'], 'static_site_importer_archive_entry_skipped' );
$assert( 1 === count( $skipped ), 'archive-entry: Receipt_Projection::compose() reports the skipped entry once' );
$skipped = $skipped[0] ?? array();
$assert( 'website/assets/clip.mp4' === ( $skipped['location'] ?? '' ) && 'entry_uncompressed_bytes_exceeded' === ( $skipped['reason_code'] ?? '' ) && 4096 === ( $skipped['metrics']['uncompressed_bytes'] ?? 0 ), 'archive-entry: the finding names the entry, the limit and its size' );
$assert( 'omitted_unit' === ( $skipped['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $skipped['acceptability'] ?? '' ) && 'file' === ( $skipped['unit'] ?? '' ) && 'static-site-importer' === ( $skipped['owning_layer'] ?? '' ), 'archive-entry: the skip is an accepted, reported static-site-importer file loss' );

// 3. The entrypoint over a per-entry limit, and the archive-wide limits, fail closed.
$imports_before = count( $GLOBALS['ssi_harness_imports'] );
$entry          = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'oversize-entrypoint',
		'source'    => $zip_source( array( 'index.html' => $document( 'Home', str_repeat( '<p>Long.</p>', 400 ) ) ) + $pages ),
	)
);
$assert( 'static_site_importer_archive_entry_uncompressed_bytes_exceeded' === ( $entry['error']['code'] ?? '' ), 'archive-entry: an oversize entrypoint fails closed' );
$archive_limits( array( 'max_total_uncompressed_bytes' => 128 ) );
$total = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'oversize-total',
		'source'    => $zip_source( $pages ),
	)
);
$assert( 'static_site_importer_archive_total_uncompressed_bytes_exceeded' === ( $total['error']['code'] ?? '' ), 'archive-total: the total-size limit stays fail-closed' );
$archive_limits( array( 'max_entries' => 2 ) );
$count = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'too-many-entries',
		'source'    => $zip_source( $pages ),
	)
);
$assert( 'static_site_importer_archive_entry_count_exceeded' === ( $count['error']['code'] ?? '' ), 'archive-count: the entry-count limit stays fail-closed' );
$assert( $imports_before === count( $GLOBALS['ssi_harness_imports'] ), 'archive: nothing is persisted when an archive-wide limit fails closed' );
unset( $GLOBALS['ssi_harness_filters']['static_site_importer_archive_limits'] );

ssi_harness_cleanup();

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-omitted-files-findings: ok\n";
