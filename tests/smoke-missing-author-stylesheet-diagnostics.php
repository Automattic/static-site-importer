<?php
/**
 * Smoke coverage for the page-without-author-styles materialization gate.
 *
 * A page route with zero author-scoped stylesheet assets in the canonical plan
 * while its source document ships author styles must surface a per-route
 * warning in the import report instead of silent success
 * (https://github.com/Automattic/static-site-importer/issues/1353, upstream
 * Automattic/blocks-engine#1241).
 *
 * Run from the repository root:
 * php tests/smoke-missing-author-stylesheet-diagnostics.php
 *
 * @package StaticSiteImporter
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$page = static function ( string $source_path, string $route ): array {
	return array(
		'source_path' => $source_path,
		'route'       => array( 'path' => $route ),
		'slug'        => '' === trim( $route, '/' ) ? 'home' : trim( $route, '/' ),
	);
};

$bundle_asset = static function ( string $scoped_source_path, string $route ): array {
	return array(
		'source_path' => 'assets/css/stylesheet-bundle-0011223344556677.css',
		'target_path' => 'assets/assets/css/stylesheet-bundle-0011223344556677.css',
		'kind'        => 'css',
		'role'        => 'stylesheet',
		'source'      => 'stylesheet-bundle',
		'scopes'      => array(
			array(
				'kind'        => 'page',
				'source_path' => $scoped_source_path,
				'route_path'  => trim( $route, '/' ),
				'front_page'  => '/' === $route,
			),
		),
	);
};

$styled_html   = '<html><head><style>main { color: rebeccapurple; }</style></head><body><main><h1>Styled</h1></main></body></html>';
$unstyled_html = '<html><head></head><body><main><h1>Plain</h1></main></body></html>';

// (a) A page with author styles in source and no author-scoped stylesheet
// assets in the plan yields the warning with the route in context.
$plan_missing = array(
	'pages'  => array( $page( 'website/index.html', '/' ), $page( 'website/new-patients/index.html', '/new-patients' ), $page( 'website/about/index.html', '/about' ) ),
	'assets' => array( $bundle_asset( 'website/about/index.html', '/about' ) ),
);
$artifact     = array(
	'files' => array(
		array(
			'path'    => 'website/index.html',
			'content' => $styled_html,
		),
		array(
			'path'    => 'website/new-patients/index.html',
			'content' => $styled_html,
		),
		array(
			'path'    => 'website/about/index.html',
			'content' => $styled_html,
		),
	),
);

$rows = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $plan_missing, $artifact );
$assert( 2 === count( $rows ), 'uncovered-styled-pages-warn', 'expected 2 warnings, got ' . count( $rows ) );
$by_source = array_column( $rows, null, 'source_path' );
$front     = $by_source['website/index.html'] ?? array();
$inner     = $by_source['website/new-patients/index.html'] ?? array();
$assert( 'page_materialized_without_author_styles' === ( $front['type'] ?? '' ), 'warning-type' );
$assert( Static_Site_Importer_Report_Diagnostics::PAGE_WITHOUT_AUTHOR_STYLES_TYPE === ( $front['code'] ?? '' ), 'warning-code-constant' );
$assert( 'warning' === ( $front['severity'] ?? '' ), 'warning-severity' );
$assert( true === ( $front['front_page'] ?? null ) && '' === ( $front['route_path'] ?? null ), 'front-page-route-flags' );
$assert( 'new-patients' === ( $inner['route_path'] ?? '' ) && 'new-patients' === ( $inner['context']['route_path'] ?? '' ), 'route-path-in-context' );
$assert( 'website/new-patients/index.html' === ( $inner['context']['source_path'] ?? '' ), 'source-path-in-context' );
$assert( 0 === ( $inner['context']['author_scoped_stylesheet_asset_count'] ?? null ) && 0 === ( $inner['context']['global_author_stylesheet_asset_count'] ?? null ), 'zero-coverage-counts-in-context' );
$assert( 1 === ( $inner['context']['source_inline_style_count'] ?? null ) && 0 === ( $inner['context']['source_linked_stylesheet_count'] ?? null ), 'source-evidence-counts-in-context' );
$assert( 'importer_materialization_bug' === ( $front['loss_class'] ?? '' ), 'loss-class-pins-pipeline-defect' );

// (b) A page WITH an author-scoped stylesheet asset yields no warning.
$assert( ! isset( $by_source['website/about/index.html'] ), 'covered-page-does-not-warn' );

// (c) A page whose source genuinely has no styles yields no warning.
$plan_no_styles = array(
	'pages'  => array( $page( 'website/index.html', '/' ) ),
	'assets' => array(),
);
$rows           = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics(
	$plan_no_styles,
	array(
		'files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => $unstyled_html,
			),
		),
	)
);
$assert( array() === $rows, 'unstyled-source-does-not-warn' );

// An empty inline style payload is not author-style evidence.
$rows = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics(
	$plan_no_styles,
	array( 'files' => array( 'website/index.html' => '<html><head><style>   </style><style type="text/template">.x{}</style></head><body><main>Plain</main></body></html>' ) )
);
$assert( array() === $rows, 'empty-or-non-css-style-payloads-do-not-warn' );

// A global author stylesheet covers every route.
$global_plan = array(
	'pages'  => array( $page( 'website/index.html', '/' ) ),
	'assets' => array(
		array(
			'source_path' => 'assets/site.css',
			'target_path' => 'assets/assets/site.css',
			'kind'        => 'css',
			'role'        => 'stylesheet',
			'scopes'      => array( array( 'kind' => 'global' ) ),
		),
	),
);
$rows        = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $global_plan, array( 'files' => array( 'website/index.html' => $styled_html ) ) );
$assert( array() === $rows, 'global-author-stylesheet-covers-route' );

// Engine-generated and editor-only stylesheet assets never satisfy author coverage.
$engine_only_plan = array(
	'pages'  => array( $page( 'website/index.html', '/' ) ),
	'assets' => array(
		array(
			'source_path' => 'assets/css/engine-support-after-author-0011223344556677.css',
			'target_path' => 'assets/assets/css/engine-support-after-author-0011223344556677.css',
			'kind'        => 'css',
			'source'      => 'engine-support',
			'scopes'      => array( array( 'kind' => 'global' ) ),
		),
		array(
			'source_path'       => 'assets/css/editor-static-state-0011223344556677.css',
			'target_path'       => 'assets/assets/css/editor-static-state-0011223344556677.css',
			'kind'              => 'css',
			'source'            => 'editor-static-state',
			'stylesheet_target' => 'editor',
			'scopes'            => array( array( 'kind' => 'global' ) ),
		),
	),
);
$rows             = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $engine_only_plan, array( 'files' => array( 'website/index.html' => $styled_html ) ) );
$assert( 1 === count( $rows ), 'engine-generated-assets-do-not-count-as-author-coverage' );

// A linked stylesheet resolving to a non-empty artifact-local CSS file is author-style evidence.
$linked_artifact = array(
	'files' => array(
		'website/team/index.html' => '<html><head><link rel="stylesheet" href="../styles/site.css?v=3"><link rel="stylesheet" href="https://cdn.example.test/remote.css"></head><body><main>Linked</main></body></html>',
		'website/styles/site.css' => 'main { margin: 0 auto; }',
	),
);
$linked_plan     = array(
	'pages'  => array( $page( 'website/team/index.html', '/team' ) ),
	'assets' => array(),
);
$rows            = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $linked_plan, $linked_artifact );
$assert( 1 === count( $rows ) && 1 === ( $rows[0]['context']['source_linked_stylesheet_count'] ?? null ) && 0 === ( $rows[0]['context']['source_inline_style_count'] ?? null ), 'linked-local-stylesheet-counts-remote-does-not' );

// A linked stylesheet pointing at a file the artifact never captured is not proof the pipeline dropped styles.
$rows = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics(
	$linked_plan,
	array( 'files' => array( 'website/team/index.html' => '<html><head><link rel="stylesheet" href="missing.css"></head><body><main>Linked</main></body></html>' ) )
);
$assert( array() === $rows, 'uncaptured-linked-stylesheet-does-not-warn' );

// Detection stays linear over multi-megabyte documents: the exact lazy-regex
// PCRE backtracking failure that caused the upstream loss must not recur here.
$huge_html = '<html><head><style>' . str_repeat( 'main{color:#000;}', 200000 ) . '</style></head><body><main>' . str_repeat( '<p>filler</p>', 100000 ) . '</main></body></html>';
$assert( strlen( $huge_html ) > 4000000, 'huge-fixture-is-multi-megabyte' );
$started = microtime( true );
$rows    = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics(
	array(
		'pages'  => array( $page( 'website/index.html', '/' ) ),
		'assets' => array(),
	),
	array( 'files' => array( 'website/index.html' => $huge_html ) )
);
$assert( 1 === count( $rows ) && 1 === ( $rows[0]['context']['source_inline_style_count'] ?? null ), 'multi-megabyte-inline-style-detected' );
$assert( microtime( true ) - $started < 2.0, 'multi-megabyte-scan-is-linear-time' );

// The warning routes into finding packets for repair-loop consumers.
$packets = Static_Site_Importer_Report_Diagnostics::finding_packets(
	array(
		'diagnostics' => array( $front ),
	)
);
$assert( 1 === ( $packets['count'] ?? 0 ) && 'reported' === ( $packets['status'] ?? '' ), 'warning-routes-as-finding-packet' );
$assert( 'page_materialized_without_author_styles' === ( $packets['packets'][0]['type'] ?? '' ) && 'warning' === ( $packets['packets'][0]['severity'] ?? '' ), 'finding-packet-carries-type-and-severity' );

// End-to-end shape check against the real compiler: a healthy compile scopes
// author styles to the route (no warning); stripping the author stylesheet
// assets — the upstream extraction-loss signature — must produce the warning.
$compiled_plan = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><style>main { color: rebeccapurple; }</style></head><body><main><h1>Home</h1><p>Body</p></main></body></html>',
			'about.html' => '<html><head></head><body><main><h1>About</h1><p>No styles here</p></main></body></html>',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];

$compiled_artifact = array(
	'files' => array(
		array(
			'path'    => 'index.html',
			'content' => '<html><head><style>main { color: rebeccapurple; }</style></head><body><main><h1>Home</h1><p>Body</p></main></body></html>',
		),
		array(
			'path'    => 'about.html',
			'content' => '<html><head></head><body><main><h1>About</h1><p>No styles here</p></main></body></html>',
		),
	),
);

$rows = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $compiled_plan, $compiled_artifact );
$assert( array() === $rows, 'healthy-compiled-plan-reports-clean', wp_json_encode( $rows ) );

$lossy_plan           = $compiled_plan;
$lossy_plan['assets'] = array_values(
	array_filter(
		$lossy_plan['assets'],
		static fn ( array $asset ): bool => 'css' !== ( $asset['kind'] ?? '' ) || in_array( (string) ( $asset['source'] ?? '' ), array( 'engine-support', 'editor-static-state' ), true )
	)
);
$rows                 = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $lossy_plan, $compiled_artifact );
$assert( 1 === count( $rows ), 'stripped-author-assets-surface-warning', 'expected 1 warning, got ' . count( $rows ) );
$assert( 'index.html' === ( $rows[0]['source_path'] ?? '' ) && true === ( $rows[0]['front_page'] ?? null ), 'compiled-warning-names-styled-route-only' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: missing author stylesheet diagnostics smoke passed (' . $assertions . " assertions)\n";
