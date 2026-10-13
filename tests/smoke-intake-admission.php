<?php
/**
 * One static-content decision across every intake (Automattic/static-site-importer#2054, item 4).
 *
 * The same source arrives as inline REST files, a REST ZIP upload, a staged
 * ZIP and a CLI request bundle. Each runs through the real canonical import
 * service, Direct artifact freeze and Receipt_Projection::compose(), and each
 * reaches the same decision through Static_Site_Importer_Content_Policy::admit_artifact():
 *
 * - a non-static asset is skipped with `static_site_importer_non_static_file_skipped`;
 * - a non-entry page that is not static content is dropped with
 *   `static_site_importer_non_static_page_dropped`, and the remaining pages import;
 * - the entrypoint fails closed.
 *
 * Run from the repository root:
 * php tests/smoke-intake-admission.php
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
// Site-relative source tree: two clean pages, a page carrying server code,
// a server page, a build-source asset, and a stylesheet.
$tree = static fn ( string $home_body = '<p>Welcome.</p><a href="about.html">About</a> <a href="blog.html">Blog</a>' ): array => array(
	'index.html'     => $document( 'Home', $home_body ),
	'about.html'     => $document( 'About', '<p>About us.</p><a href="index.html">Home</a>' ),
	'blog.html'      => $document( 'Blog', '<p><?php echo get_posts(); ?></p>' ),
	'feed.php'       => '<?php echo "feed";',
	'assets/app.ts'  => 'export const app = 1;',
	'styles.css'     => 'body{font-family:serif}',
);
$zip_bytes = static function ( array $files ): string {
	$path = tempnam( sys_get_temp_dir(), 'ssi-intake-zip-' );
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::OVERWRITE );
	foreach ( $files as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	$bytes = (string) file_get_contents( $path );
	unlink( $path );
	return $bytes;
};
$request = static fn ( string $slug, array $source ): array => array(
	'operation' => 'apply',
	'slug'      => $slug,
	'source'    => $source,
);

// Each transport builds its request from the same site-relative tree.
$transports = array(
	'rest-files'  => static fn ( array $files, string $slug ): array => $request(
		$slug,
		array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'files'      => array_map( static fn ( string $path, string $content ): array => array( 'path' => 'website/' . $path, 'content' => $content ), array_keys( $files ), $files ),
		)
	),
	'rest-zip'    => static fn ( array $files, string $slug ): array => $request(
		$slug,
		array(
			'type' => 'zip',
			'zip'  => array(
				'name'           => 'site.zip',
				'content_base64' => base64_encode( $zip_bytes( $files ) ),
			),
		)
	),
	'staged-zip'  => static function ( array $files, string $slug ) use ( $zip_bytes, $request ): array {
		$staged = $GLOBALS['test_root'] . '/' . $slug . '.zip';
		file_put_contents( $staged, $zip_bytes( $files ) );
		add_filter(
			'static_site_importer_resolve_source_reference',
			static fn ( $resolved, string $reference, string $type ) => null === $resolved && 'staged:' . $slug === $reference && 'zip' === $type ? array(
				'source' => array(
					'zip' => array(
						'name'        => $slug . '.zip',
						'staged_path' => $staged,
					),
				),
			) : $resolved
		);
		return $request(
			$slug,
			array(
				'type' => 'zip',
				'ref'  => 'staged:' . $slug,
			)
		);
	},
	'cli-bundle'  => static function ( array $files, string $slug ) use ( $request ): array {
		$root = $GLOBALS['test_root'] . '/' . $slug;
		foreach ( $files as $path => $content ) {
			wp_mkdir_p( dirname( $root . '/' . $slug . '/' . $path ) );
			file_put_contents( $root . '/' . $slug . '/' . $path, $content );
		}
		file_put_contents( $root . '/request.json', '{}' );
		$prepared = static_site_importer_cli_prepare_request_bundle(
			$request(
				$slug,
				array(
					'type' => 'files',
					'ref'  => 'request-bundle:' . $slug,
				)
			),
			realpath( $root . '/request.json' )
		);
		if ( is_wp_error( $prepared ) ) {
			throw new RuntimeException( 'Cannot prepare the CLI request bundle: ' . $prepared->get_error_code() );
		}
		return $prepared;
	},
);

$expected_findings = array(
	array( Static_Site_Importer_Content_Policy::FILE_SKIPPED, 'website/assets/app.ts', 'non_static_extension', 'file' ),
	array( Static_Site_Importer_Content_Policy::PAGE_DROPPED, 'website/blog.html', 'server_side_code', 'page' ),
	array( Static_Site_Importer_Content_Policy::PAGE_DROPPED, 'website/feed.php', 'non_static_extension', 'page' ),
);
$finding_tuples    = static function ( array $rows ): array {
	$tuples = array_map( static fn ( array $row ): array => array( (string) $row['code'], (string) $row['source_path'], (string) $row['reason_code'], (string) ( $row['unit'] ?? '' ) ), $rows );
	sort( $tuples );
	return $tuples;
};

foreach ( $transports as $transport => $build ) {
	$result = ssi_harness_drive( $build( $tree(), 'intake-' . $transport ) );
	$assert( ! empty( $result['success'] ), "$transport: the import completes instead of rejecting the whole source" );
	$import    = ssi_harness_last_import();
	$persisted = array_column( $import['plan']['pages'] ?? array(), 'source_path' );
	sort( $persisted );
	$assert( array( 'website/about.html', 'website/index.html' ) === $persisted, "$transport: the clean pages import and the dropped pages do not" );

	$rows = array_merge(
		ssi_harness_findings( $import['report'], Static_Site_Importer_Content_Policy::FILE_SKIPPED ),
		ssi_harness_findings( $import['report'], Static_Site_Importer_Content_Policy::PAGE_DROPPED )
	);
	$assert( $expected_findings === $finding_tuples( $rows ), "$transport: Receipt_Projection::compose() reports each left-out file once with its unit: " . wp_json_encode( $finding_tuples( $rows ) ) );
	foreach ( $rows as $row ) {
		$assert(
			'omitted_unit' === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && 'static-site-importer' === ( $row['owning_layer'] ?? '' ) && $row['source_path'] === ( $row['location'] ?? '' ),
			"$transport: {$row['source_path']} is an accepted, reported loss owned by static-site-importer"
		);
	}
	$summary = $result['result']['import_report_summary'] ?? array();
	$assert( false === ( $summary['fail_import'] ?? true ) && 3 === ( $summary['loss_counts']['omitted_unit'] ?? 0 ), "$transport: the compact summary counts the three losses without failing the import" );

	// The entrypoint fails closed on the same transport.
	$imports_before = count( $GLOBALS['ssi_harness_imports'] );
	$rejected       = ssi_harness_drive( $build( $tree( '<p><?php echo 1; ?></p>' ), 'intake-entry-' . $transport ) );
	$assert( empty( $rejected['success'] ) && 'static_site_importer_executable_source_rejected' === ( $rejected['error']['code'] ?? '' ), "$transport: an entrypoint carrying server code fails closed" );
	$assert( $imports_before === count( $GLOBALS['ssi_harness_imports'] ), "$transport: nothing is persisted when the entrypoint fails closed" );
}

// A provided artifact reaches the same decision through the same owner.
$provided = Static_Site_Importer_Content_Policy::admit_artifact(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => array_map( static fn ( string $path, string $content ): array => array( 'path' => 'website/' . $path, 'content' => $content ), array_keys( $tree() ), $tree() ),
	)
);
$assert(
	is_array( $provided ) && array_map( static fn ( array $tuple ): array => array_slice( $tuple, 0, 3 ), $expected_findings ) === array_map( static fn ( array $tuple ): array => array_slice( $tuple, 0, 3 ), $finding_tuples( $provided['findings'] ) ),
	'admit_artifact: a provided artifact gets the decision every transport gets'
);

ssi_harness_cleanup();

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-intake-admission: ok\n";
