<?php
/**
 * WordPress-runtime smoke: mixed post/page site-plan materialization.
 *
 * Runs only inside a WordPress site with the Blocks Engine php-transformer
 * available, proving real wp_insert_post/get_page_by_path/get_permalink
 * behavior for the consumer-side post-vs-page classification added in #789
 * (see #513). The standalone materializer smoke cannot exercise the real
 * WP post store; this lane covers that gap.
 *
 * Run: wp eval-file tests/smoke-plan-post-classification.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_WordPress_Site_Plan_Materializer', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Document_Type_Classifier', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-document-type-classifier.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// The compiler emits a default 'page' post_type for every HTML document on
// the v2 plan. The consumer-side classifier upgrades a dated or /YYYY/MM/
// routed document to 'post'. Materialize through the canonical plan via the
// Blocks Engine compiler so the plan is producer-real.
if ( ! class_exists( 'Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler' ) ) {
	if ( is_readable( $plugin_root . '/vendor/autoload.php' ) ) {
		require_once $plugin_root . '/vendor/autoload.php';
	}
}
if ( ! class_exists( 'Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler' ) ) {
	fwrite( STDERR, "Blocks Engine php-transformer is required for this smoke\n" );
	exit( 1 );
}

$compiler = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$plan     = $compiler->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html'             => '<main><h1>Home</h1></main>',
			'blog/hello.html'        => '<html><head><meta property="article:published_time" content="2024-03-12T10:00:00Z"></head><body><main><h1>Hello</h1></main></body></html>',
			'2024/03/dated-post.html' => '<main><h1>Dated by URL</h1></main>',
			'notes/index.html'       => '<main><h1>Notes</h1></main>',
			'notes/essay.html'       => '<html><head><meta property="article:published_time" content="2024-05-20T09:30:00Z"></head><body><main><h1>Essay</h1></main></body></html>',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];

/**
 * Run one materialization and collect every post ID it reported, across all
 * result shapes, so cleanup never depends on a single shape or a completed
 * status. Created rows are returned in $collect even when an assertion later
 * fails, keeping the DB clean on any exit path.
 */
$materialize = static function ( array $plan, string $slug, array &$collect ): array {
	$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => $slug ) );
	$pages  = $result['completed']['pages'] ?? $result['pages'] ?? array();
	foreach ( $pages as $path => $id ) {
		$id = (int) $id;
		if ( $id > 0 ) {
			$collect[] = $id;
		}
	}
	return $result;
};

$slug   = 'ssi-plan-post-classification-' . substr( md5( random_int( 0, PHP_INT_MAX ) ), 0, 8 );
$prior  = array();
try {
	$result = $materialize( $plan, $slug, $prior );

	$assert( isset( $result['status'] ) && 'completed' === $result['status'], 'materialization-completes', (string) ( $result['status'] ?? 'missing-status' ) );

	if ( isset( $result['status'] ) && 'completed' === $result['status'] ) {
		$pages    = $result['pages'] ?? $result['completed']['pages'] ?? array();
		$page_id  = (int) ( $pages['index.html'] ?? 0 );
		$post_id  = (int) ( $pages['blog/hello.html'] ?? 0 );
		$dated_id = (int) ( $pages['2024/03/dated-post.html'] ?? 0 );
		$notes_id = (int) ( $pages['notes/essay.html'] ?? 0 );

		$post = $post_id ? get_post( $post_id ) : null;
		$assert( $post instanceof WP_Post && 'post' === $post->post_type, 'dated-document-classifies-as-post' );
		$assert( $post instanceof WP_Post && 0 === (int) $post->post_parent, 'post-has-no-page-parent' );
		$assert( $post instanceof WP_Post && '2024-03-12 10:00:00' === $post->post_date_gmt, 'post-gmt-date-is-utc' );
		$assert( $post instanceof WP_Post && 0 === strpos( (string) get_permalink( $post ), home_url() ), 'post-permalink-resolves' );

		$dated = $dated_id ? get_post( $dated_id ) : null;
		$assert( $dated instanceof WP_Post && 'post' === $dated->post_type, 'url-dated-document-classifies-as-post' );
		$assert( $dated instanceof WP_Post && 0 === (int) $dated->post_parent, 'url-dated-post-has-no-page-parent' );

		$page = $page_id ? get_post( $page_id ) : null;
		$assert( $page instanceof WP_Post && 'page' === $page->post_type, 'undated-entrypoint-stays-page' );

		// A dated post nested under a page wrapper still classifies as a post
		// and stays parentless, while the wrapper imports as a page. The real
		// get_page_by_path conflict check must find the nested post route, not
		// fall back to a page-shaped lookup (see #789 review).
		$notes   = $notes_id ? get_post( $notes_id ) : null;
		$wrapper = (int) ( $pages['notes/index.html'] ?? 0 );
		$wrap    = $wrapper ? get_post( $wrapper ) : null;
		$assert( $notes instanceof WP_Post && 'post' === $notes->post_type, 'nested-document-classifies-as-post' );
		$assert( $notes instanceof WP_Post && 0 === (int) $notes->post_parent, 'nested-post-has-no-page-parent' );
		$assert( $wrap instanceof WP_Post && 'page' === $wrap->post_type, 'wrapper-imports-as-page' );

		// Reconciliation identity is unique per document, so a re-import reuses
		// the same real post across the classifier's page -> post boundary.
		$reet_result = $materialize( $plan, $slug, $prior );
		$reet_status = isset( $reet_result['status'] ) ? $reet_result['status'] : '';
		$assert( 'completed' === $reet_status, 're-import-completes', (string) ( $reet_result['status'] ?? 'missing-status' ) );
		$reet_pages = $reet_result['pages'] ?? $reet_result['completed']['pages'] ?? array();
		$reet_post_id = (int) ( $reet_pages['blog/hello.html'] ?? 0 );
		$reet_notes_id = (int) ( $reet_pages['notes/essay.html'] ?? 0 );
		$assert( $reet_post_id === $post_id, 're-import-reuses-existing-post-id' );
		$assert( $reet_notes_id === $notes_id, 're-import-reuses-existing-nested-post-id' );
	}
} finally {
	// Clean up only the rows this smoke created, on every exit path.
	foreach ( array_filter( array_unique( array_map( 'intval', $prior ) ) ) as $id ) {
		wp_delete_post( $id, true );
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: plan post classification smoke passed (' . $assertions . " assertions)\n";
