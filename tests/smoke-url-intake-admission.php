<?php
/**
 * URL imports reach the one static-content decision (Automattic/static-site-importer#2054, item 4).
 *
 * The same site-relative source is served to the URL collector through the
 * batch fetcher seam and imported as REST files. Both run the real canonical
 * import service, Blocks Engine compilation and Receipt_Projection::compose();
 * the URL import plans, then applies its approved plan. Both reach the same
 * decision through Static_Site_Importer_Content_Policy::admit_artifact():
 *
 * - a non-static asset is skipped with `static_site_importer_non_static_file_skipped`;
 * - a non-entry page carrying server code is dropped with
 *   `static_site_importer_non_static_page_dropped`, and the remaining pages import;
 * - the entry page fails closed.
 *
 * URL-only fetch outcomes:
 *
 * - a non-entry route that answers 404 or exceeds the response limit is left
 *   out with a `static_site_importer_url_route_omitted` finding;
 * - a failed site entry, and a public-address refusal on any route, fail the import.
 *
 * Run from the repository root:
 * php tests/smoke-url-intake-admission.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone smoke drives real intakes.

require_once __DIR__ . '/fixtures/direct-import-harness.php';
// The URL collector's WordPress helpers, as in tests/smoke-url-batch-import.php.
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string { return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '-' ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone stub.
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-import-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-final-hydration-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-default-final-hydration-adapter.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$document = static fn ( string $title, string $body ): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="styles.css"></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
// Site-relative source tree: two clean pages, a page carrying server code,
// a build-source asset the home page references, and a stylesheet.
$tree = static fn ( string $home_body = '<p>Welcome.</p><a href="about.html">About</a> <a href="blog.html">Blog</a><img src="assets/app.ts" alt="">' ): array => array(
	'index.html'    => $document( 'Home', $home_body ),
	'about.html'    => $document( 'About', '<p>About us.</p><a href="index.html">Home</a>' ),
	'blog.html'     => $document( 'Blog', '<p><?php echo get_posts(); ?></p>' ),
	'assets/app.ts' => 'export const app = 1;',
	'styles.css'    => 'body{font-family:serif}',
);
$content_types = array(
	'html' => 'text/html',
	'css'  => 'text/css',
	'ts'   => 'text/plain',
);

// The URL batch fetcher seam serves a site; anything it does not hold is a 404.
$GLOBALS['ssi_url_sites'] = array();
add_filter(
	'static_site_importer_url_batch_import_fetcher',
	static fn () => static function ( string $url ) {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone smoke.
		$site  = $GLOBALS['ssi_url_sites'][ (string) ( $parts['host'] ?? '' ) ] ?? array();
		$path  = ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		$path  = '' === $path ? 'index.html' : $path;
		$entry = $site[ $path ] ?? null;
		if ( $entry instanceof WP_Error ) {
			return $entry;
		}
		if ( ! is_array( $entry ) ) {
			return new WP_Error( 'static_site_importer_url_http_status', 'The URL returned HTTP status 404.', array( 'status' => 404 ) );
		}
		return array(
			'body'     => $entry['body'],
			'metadata' => array(
				'content_type' => $entry['type'],
				'final_url'    => $url,
			),
		);
	}
);
add_filter(
	'static_site_importer_url_batch_import_args',
	static fn ( array $args ): array => array_merge(
		$args,
		array(
			'request_delay_ms'                     => 0,
			'max_effective_batches_per_invocation' => 100,
		)
	)
);

/**
 * Serve a site-relative tree from a host; a route may answer with a fetch error instead.
 *
 * @param array<string,string|WP_Error> $files Site-relative files.
 */
$serve = static function ( string $host, array $files ) use ( $content_types ): string {
	$site    = array();
	$sitemap = '';
	foreach ( $files as $path => $body ) {
		$extension     = pathinfo( $path, PATHINFO_EXTENSION );
		$site[ $path ] = $body instanceof WP_Error ? $body : array(
			'body' => $body,
			'type' => $content_types[ $extension ] ?? 'application/octet-stream',
		);
		if ( 'html' === $extension ) {
			$sitemap .= '<url><loc>https://' . $host . '/' . ( 'index.html' === $path ? '' : $path ) . '</loc></url>';
		}
	}
	$site['sitemap.xml']                = array(
		'body' => '<urlset>' . $sitemap . '</urlset>',
		'type' => 'application/xml',
	);
	$GLOBALS['ssi_url_sites'][ $host ] = $site;
	return 'https://' . $host . '/';
};

/**
 * Plan a URL import, then apply its approved plan, through every continuation.
 *
 * @return array{plan:array<string,mixed>,apply:array<string,mixed>}
 */
$import_url = static function ( string $url, string $slug ): array {
	$drive = static function ( array $request ): array {
		for ( $attempt = 0; $attempt < 40; ++$attempt ) {
			$result = Static_Site_Importer_Canonical_Import_Service::import( $request );
			if ( empty( $result['continuation'] ) ) {
				return $result;
			}
			if ( '' !== (string) ( $result['import_id'] ?? '' ) ) {
				$request['source']['import_id'] = (string) $result['import_id'];
			}
		}
		throw new RuntimeException( 'The URL import exceeded its continuation bound.' );
	};
	$plan = $drive(
		array(
			'operation' => 'plan',
			'slug'      => $slug,
			'source'    => array(
				'type' => 'url',
				'url'  => $url,
			),
		)
	);
	if ( empty( $plan['success'] ) ) {
		return array(
			'plan'  => $plan,
			'apply' => array(),
		);
	}
	return array(
		'plan'  => $plan,
		'apply' => $drive(
			array(
				'operation' => 'apply',
				'slug'      => $slug,
				'plan'      => $plan['plan'],
				'source'    => array(
					'type'      => 'url',
					'url'       => $url,
					'import_id' => (string) ( $plan['source']['provenance']['import_id'] ?? '' ),
				),
			)
		),
	);
};

$finding_tuples = static function ( array $rows ): array {
	$tuples = array_map( static fn ( array $row ): array => array( (string) $row['code'], (string) ( $row['location'] ?? '' ), (string) $row['reason_code'], (string) ( $row['unit'] ?? '' ) ), $rows );
	sort( $tuples );
	return $tuples;
};
$admission_rows = static fn ( array $report ): array => array_merge(
	ssi_harness_findings( $report, Static_Site_Importer_Content_Policy::FILE_SKIPPED ),
	ssi_harness_findings( $report, Static_Site_Importer_Content_Policy::PAGE_DROPPED )
);
$persisted_pages = static function ( array $import ): array {
	$pages = array_column( $import['plan']['pages'] ?? array(), 'source_path' );
	sort( $pages );
	return $pages;
};

// 1. The same source through REST files and through URL collection.
$rest = ssi_harness_drive(
	array(
		'operation' => 'apply',
		'slug'      => 'url-parity-rest',
		'source'    => array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'files'      => array_map( static fn ( string $path, string $content ): array => array( 'path' => 'website/' . $path, 'content' => $content ), array_keys( $tree() ), $tree() ),
		),
	)
);
$assert( ! empty( $rest['success'] ), 'rest: the import completes' );
$rest_import = ssi_harness_last_import();
$rest_rows   = $finding_tuples( $admission_rows( $rest_import['report'] ) );

$imports_before = count( $GLOBALS['ssi_harness_imports'] );
$url            = $import_url( $serve( 'parity.test', $tree() ), 'url-parity' );
$assert( count( $GLOBALS['ssi_harness_imports'] ) > $imports_before && ! empty( $url['plan']['success'] ) && ! empty( $url['apply']['success'] ), 'url: the import plans and applies instead of rejecting the whole site: ' . wp_json_encode( $url['plan']['error'] ?? $url['apply']['error'] ?? null ) );
$url_import = ssi_harness_last_import();
$assert( array( 'website/about.html', 'website/index.html' ) === $persisted_pages( $url_import ), 'url: the clean pages import and the dropped page does not: ' . wp_json_encode( $persisted_pages( $url_import ) ) );
$assert( $persisted_pages( $rest_import ) === $persisted_pages( $url_import ), 'url and rest persist the same pages' );

$expected = array(
	array( Static_Site_Importer_Content_Policy::FILE_SKIPPED, 'website/assets/app.ts', 'non_static_extension', 'file' ),
	array( Static_Site_Importer_Content_Policy::PAGE_DROPPED, 'website/blog.html', 'server_side_code', 'page' ),
);
$assert( $expected === $rest_rows, 'rest: Receipt_Projection::compose() reports the admission findings: ' . wp_json_encode( $rest_rows ) );
$url_rows = $finding_tuples( $admission_rows( $url_import['report'] ) );
$assert( $rest_rows === $url_rows, 'url: Receipt_Projection::compose() reports the same admission findings as rest: ' . wp_json_encode( $url_rows ) );
foreach ( $admission_rows( $url_import['report'] ) as $row ) {
	$assert(
		'omitted_unit' === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && 'static-site-importer' === ( $row['owning_layer'] ?? '' ),
		"url: {$row['source_path']} is an accepted, reported loss owned by static-site-importer"
	);
}
$summary = $url['apply']['result']['import_report_summary'] ?? array();
$assert( false === ( $summary['fail_import'] ?? true ) && 2 === ( $summary['loss_counts']['omitted_unit'] ?? 0 ), 'url: the compact summary counts both losses without failing the import: ' . wp_json_encode( $summary['loss_counts'] ?? null ) );
$plan_codes = array_column( $url['plan']['diagnostics'] ?? array(), 'code' );
$assert( in_array( Static_Site_Importer_Content_Policy::FILE_SKIPPED, $plan_codes, true ) && in_array( Static_Site_Importer_Content_Policy::PAGE_DROPPED, $plan_codes, true ), 'url: the plan response carries the admission findings before apply' );

// 2. The entry page carrying server code fails closed, on both intakes.
$imports_before = count( $GLOBALS['ssi_harness_imports'] );
$entry_rejected = $import_url( $serve( 'entry-code.test', $tree( '<p><?php echo 1; ?></p>' ) ), 'url-entry-code' );
$assert( empty( $entry_rejected['plan']['success'] ) && 'static_site_importer_executable_source_rejected' === ( $entry_rejected['plan']['error']['code'] ?? '' ), 'url: an entry page carrying server code fails closed: ' . wp_json_encode( $entry_rejected['plan']['error'] ?? null ) );
$assert( $imports_before === count( $GLOBALS['ssi_harness_imports'] ), 'url: nothing is persisted when the entry page fails closed' );

// 3. Non-entry routes that cannot be fetched are omitted with findings.
$omission_tree                = $tree();
$omission_tree['gone.html']   = new WP_Error( 'static_site_importer_url_http_status', 'The URL returned HTTP status 404.', array( 'status' => 404 ) );
$omission_tree['huge.html']   = new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
$omission_tree['index.html']  = $document( 'Home', '<p>Welcome.</p><a href="about.html">About</a> <a href="gone.html">Gone</a> <a href="huge.html">Huge</a>' );
unset( $omission_tree['blog.html'], $omission_tree['assets/app.ts'] );
$omitted = $import_url( $serve( 'omission.test', $omission_tree ), 'url-omission' );
$assert( ! empty( $omitted['plan']['success'] ) && ! empty( $omitted['apply']['success'] ), 'url: a 404 and an oversize non-entry route do not fail the import: ' . wp_json_encode( $omitted['plan']['error'] ?? $omitted['apply']['error'] ?? null ) );
$omitted_import = ssi_harness_last_import();
$assert( array( 'website/about.html', 'website/index.html' ) === $persisted_pages( $omitted_import ), 'url: the routes that could be fetched import' );
$route_rows = ssi_harness_findings( $omitted_import['report'], Static_Site_Importer_URL_Batch_Import::ROUTE_OMITTED );
$assert(
	array(
		array( Static_Site_Importer_URL_Batch_Import::ROUTE_OMITTED, 'https://omission.test/gone.html', 'static_site_importer_url_http_status', 'route' ),
		array( Static_Site_Importer_URL_Batch_Import::ROUTE_OMITTED, 'https://omission.test/huge.html', 'static_site_importer_url_too_large', 'route' ),
	) === $finding_tuples( $route_rows ),
	'url: Receipt_Projection::compose() reports each omitted route once: ' . wp_json_encode( $finding_tuples( $route_rows ) )
);
foreach ( $route_rows as $row ) {
	$assert( 'omitted_unit' === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && 'static-site-importer' === ( $row['owning_layer'] ?? '' ) && $row['url'] === ( $row['location'] ?? '' ), "url: {$row['url']} is an accepted, reported route loss" );
}
$assert( 2 === ( $omitted['plan']['url_batch_run']['omitted_routes'] ?? 0 ), 'url: the run evidence counts the omitted routes' );

// 4. A failed site entry still fails the import.
$entry_missing                = $tree();
$entry_missing['index.html']  = new WP_Error( 'static_site_importer_url_http_status', 'The URL returned HTTP status 404.', array( 'status' => 404 ) );
$entry_failed                 = $import_url( $serve( 'entry-missing.test', $entry_missing ), 'url-entry-missing' );
$assert( empty( $entry_failed['plan']['success'] ) && 'static_site_importer_url_http_status' === ( $entry_failed['plan']['error']['code'] ?? '' ), 'url: a site entry that cannot be fetched fails the import: ' . wp_json_encode( $entry_failed['plan']['error'] ?? null ) );

// 5. A public-address refusal stays fail-closed on a non-entry route.
$guarded               = $tree();
$guarded['inner.html'] = new WP_Error( 'static_site_importer_url_private_ip', 'The URL resolves to a private, loopback, link-local, or otherwise reserved IP address.' );
unset( $guarded['blog.html'], $guarded['assets/app.ts'] );
$guard_failed = $import_url( $serve( 'guarded.test', $guarded ), 'url-guarded' );
$assert( empty( $guard_failed['plan']['success'] ) && 'static_site_importer_url_private_ip' === ( $guard_failed['plan']['error']['code'] ?? '' ), 'url: a private-address refusal on a non-entry route fails the import: ' . wp_json_encode( $guard_failed['plan']['error'] ?? null ) );

ssi_harness_cleanup();

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-url-intake-admission: ok\n";
