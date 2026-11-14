<?php
/**
 * A data-liberation-agent content index declares slugs, dates, labels and
 * redirects through the public import interface.
 *
 * Real admission, Direct freezing, the content index extractor and Blocks
 * Engine compilation run; WordPress persistence is the harness stub. Set
 * BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT to a php-transformer checkout to run
 * against a paired producer candidate.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone smoke with WordPress stubs.

$ssi_engine_root = getenv( 'BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT' );
if ( is_string( $ssi_engine_root ) && '' !== $ssi_engine_root ) {
	// Composer prepends the last registered loader, so the candidate checkout
	// loads after the installed package and before the harness loads any class.
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	require_once rtrim( $ssi_engine_root, '/' ) . '/vendor/autoload.php';
}
require_once __DIR__ . '/fixtures/direct-import-harness.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-index.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-redirection-materializer.php';
class WP {
	public function parse_request() {}
	public function query_posts() {}
	public function handle_404() {}
}
class WP_Query {
	public function is_singular() { return false; }
	public function is_404() { return true; }
}
function get_permalink( $id ) { return 'https://native.test/?p=' . $id; }
function get_term_link( $id, $taxonomy ) { return 'https://native.test/tag/' . $GLOBALS['term_slugs'][ $id ] . '/'; }
function home_url( $path = '' ) { return 'https://native.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_posts( $args ) { return array(); }
function get_option( $key, $default = false ) { return $default; }
function sanitize_title_for_query( $value ) { return $value; }
function url_to_postid( $url ) { return 0; }
function redirect_canonical( $url, $redirect = true ) { return false; }
function redirect_guess_404_permalink() { return false; }

$assert    = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$fixture   = require __DIR__ . '/fixtures/content-index-artifact.php';
$files     = $fixture['files'];
$food_path = $fixture['food_path'];

try {
	// The extractor alone: the index leaves the artifact and its facts land on the files.
	$extracted = Static_Site_Importer_Content_Index::extract( array( 'files' => $files ) );
	$assert( is_array( $extracted ), 'The content index must extract.' );
	$stamped = array_column( $extracted['artifact']['files'], null, 'path' );
	$assert( ! isset( $stamped['website/content-index.json'] ), 'The content index is import metadata, not a file.' );
	$first = $stamped['website/2025/01/post-0.html']['metadata'] ?? array();
	$assert( 'post' === ( $first['post_type'] ?? null ) && '/2025/01/post-0' === ( $first['route_path'] ?? null ), 'Posts carry their type and permalink route: ' . json_encode( $first ) );
	$assert(
		array(
			'slug'      => 'post-0',
			'published' => '2025-01-10T09:00:00.000-08:00',
			'updated'   => '2025-02-01T12:00:00Z',
			'author'    => 'Ada',
			'terms'     => array( 'Travel', 'Food' ),
		) === ( $first['declared_content'] ?? null ),
		'Posts declare their content record without its HTML.'
	);
	$travel = $stamped['website/search/label/Travel.html']['metadata'] ?? array();
	$assert( array( 'term_archive' => array( 'name' => 'Travel' ) ) === ( $travel['declared_content'] ?? null ) && '/search/label/travel' === ( $travel['route_path'] ?? null ) && ! isset( $travel['post_type'] ), 'Label listings declare their term on a canonical route: ' . json_encode( $travel ) );
	$aliases = array_column( $extracted['aliases'], 'to', 'from' );
	$assert( 'search/label/Travel.html' === ( $aliases['search/label/Travel'] ?? null ) && ! isset( $aliases['2025/01/post-0.html'] ), 'Index redirects become aliases; self-redirects drop: ' . json_encode( $aliases ) );

	// Artifacts without a content index pass through unchanged, and a
	// differently shaped content-index.json is an ordinary asset.
	$plain = array( 'files' => array_slice( $files, 0, 4 ) );
	$assert( array(
		'artifact' => $plain,
		'aliases'  => array(),
	) === Static_Site_Importer_Content_Index::extract( $plain ), 'No content index changes nothing.' );
	$foreign = array(
		'files' => array(
			array(
				'path'    => 'website/content-index.json',
				'content' => '{"schema":"example/content-index/v2","posts":[]}',
			),
		),
	);
	$assert( $foreign === Static_Site_Importer_Content_Index::extract( $foreign )['artifact'], 'A foreign content-index.json stays an asset.' );

	if ( ! class_exists( 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\DeclaredContent' ) ) {
		// The installed producer predates declared content (Automattic/blocks-engine#5699).
		echo "Content index extraction passed; plan assertions need a Blocks Engine with declared content (set BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT).\n";
		return;
	}

	// The public import: declared slugs, dates and labels reach the plan.
	$result = ssi_harness_drive(
		array(
			'operation' => 'apply',
			'slug'      => 'content-index',
			'source'    => array(
				'type'  => 'files',
				'files' => $files,
			),
		)
	);
	$assert( ! empty( $result['success'] ), 'Import must complete: ' . json_encode( $result ) );
	$import   = end( $GLOBALS['ssi_harness_imports'] );
	$plan     = $import['plan'];
	$pages    = array_column( $plan['pages'], null, 'source_path' );
	$expected = array(
		'website/2025/01/post-0.html' => array( 'post-0', '2025-01-10T17:00:00Z', '2025-02-01T12:00:00Z' ),
		'website/2025/02/post-1.html' => array( 'post-1', '2025-02-11T10:30:00Z', '2025-03-01T08:00:00Z' ),
		'website/2025/03/post-2.html' => array( 'post-2', '2025-03-12T00:00:00Z', '2025-03-13T00:00:00Z' ),
	);
	foreach ( $expected as $source => [ $slug, $published, $modified ] ) {
		$page = $pages[ $source ] ?? array();
		$assert( 'post' === ( $page['post_type'] ?? null ) && $slug === ( $page['slug'] ?? null ), 'Declared post and slug: ' . $source . ' ' . json_encode( array_intersect_key( $page, array_flip( array( 'post_type', 'slug' ) ) ) ) );
		$assert( $published === ( $page['publication_timestamp'] ?? null ) && $modified === ( $page['modified_timestamp'] ?? null ), 'Declared dates: ' . $source . ' ' . json_encode( array_keys( $page ) ) . json_encode( $page['content_decision'] ?? null ) . json_encode( array_intersect_key( $page, array_flip( array( 'publication_timestamp', 'modified_timestamp', 'publication_local_time', 'author_name' ) ) ) ) );
		$assert( 'metadata:content_index' === ( $page['content_decision']['provenance'] ?? null ), 'Declared provenance: ' . $source );
	}
	$assert( '2025-01-10T09:00:00-08:00' === ( $pages['website/2025/01/post-0.html']['publication_local_time'] ?? null ), 'The source offset keeps its wall-clock time.' );
	$assert( ! isset( $pages['website/search/label/Travel.html'] ) && ! isset( $pages[ 'website/' . $food_path ] ), 'Label listings are not posts or pages: ' . json_encode( array_keys( $pages ) ) );
	$terms = array_column( $plan['taxonomy_entities'] ?? array(), null, 'slug' );
	ksort( $terms );
	$assert( array( 'food', 'travel' ) === array_keys( $terms ), 'Two tags: ' . json_encode( array_keys( $terms ) ) );
	$assert( 'post_tag' === $terms['travel']['taxonomy'] && array( 'website/2025/01/post-0.html', 'website/2025/02/post-1.html' ) === $terms['travel']['membership_source_paths'], 'Travel memberships.' );
	$assert( 'post_tag' === $terms['food']['taxonomy'] && array( 'website/2025/01/post-0.html', 'website/2025/03/post-2.html' ) === $terms['food']['membership_source_paths'], 'Food memberships.' );
	$assert( '/search/label/travel' === ( $terms['travel']['archive']['source_route'] ?? null ) && '/search/label/food' === ( $terms['food']['archive']['source_route'] ?? null ), 'Label listings are the term archive routes.' );

	// Redirects: each old permalink to its post, each label URL to its term.
	$GLOBALS['term_slugs'] = array(
		7 => 'travel',
		8 => 'food',
	);
	$receipt               = array(
		'plan'      => $plan,
		'completed' => array(
			'pages'             => array_combine( array_keys( $pages ), range( 100, 99 + count( $pages ) ) ),
			'taxonomy_entities' => array(
				array(
					'term_id'       => 7,
					'taxonomy'      => 'post_tag',
					'slug'          => 'travel',
					'archive_route' => '/search/label/travel',
				),
				array(
					'term_id'       => 8,
					'taxonomy'      => 'post_tag',
					'slug'          => 'food',
					'archive_route' => '/search/label/food',
				),
			),
		),
	);
	$routes                = Static_Site_Importer_Redirection_Materializer::routes( $receipt, $import['args'] );
	$assert( is_array( $routes ), 'Redirect routes must bind: ' . json_encode( $routes ) );
	foreach ( array_keys( $expected ) as $source ) {
		$from = '/' . substr( $source, strlen( 'website/' ) );
		$assert( get_permalink( $receipt['completed']['pages'][ $source ] ) === ( $routes[ $from ] ?? null ), 'Old permalink redirects to its post: ' . $from );
	}
	foreach ( array(
		'Travel' => 'travel',
		'Food'   => 'food',
	) as $label => $slug ) {
		$link = 'https://native.test/tag/' . $slug . '/';
		$assert( $link === ( $routes[ '/search/label/' . $label ] ?? null ) && $link === ( $routes[ '/search/label/' . $slug ] ?? null ), 'Label URL redirects to its term: ' . $label . ' ' . json_encode( $routes ) );
	}
	// Non-Latin archive segments must fall back to distinct derived page routes.
	$archive_files = array( $files[0] );
	$archive_terms = array();
	foreach ( array( '%D9%85%DA%A9%DA%BE%D9%86' => 'مکھن', '%D8%B4%DA%A9%D8%B1' => 'شکر' ) as $segment => $name ) {
		$path            = '/collections/all/' . $segment;
		$archive_files[] = array(
			'path'    => 'website' . $path . '/index.html',
			'content' => '<!doctype html><html><head><meta charset="utf-8"><title>' . $name . '</title></head><body><main><h1>' . $name . '</h1><p>Collection archive.</p></main></body></html>',
		);
		$archive_terms[] = array(
			'name'      => $name,
			'kind'      => 'term-archive',
			'sourceUrl' => 'https://example.com' . $path,
			'path'      => $path . '/index.html',
		);
	}
	$archive_files[] = array(
		'path'    => 'website/content-index.json',
		'content' => json_encode( array( 'schema' => 'example/content-index/v1', 'terms' => $archive_terms ) ),
	);
	$result         = ssi_harness_drive(
		array(
			'operation' => 'apply',
			'slug'      => 'non-latin-archives',
			'source'    => array(
				'type'  => 'files',
				'files' => $archive_files,
			),
		)
	);
	$assert( ! empty( $result['success'] ), 'Non-Latin archives must import: ' . json_encode( $result ) );
	$import = end( $GLOBALS['ssi_harness_imports'] );
	// Declared term listings materialize as taxonomy archives, not ordinary pages.
	$archives = array_column( array_column( $import['plan']['taxonomy_entities'], 'archive' ), null, 'source_path' );
	$routes   = array();
	foreach ( $archive_terms as $term ) {
		$archive = $archives[ 'website' . $term['path'] ] ?? array();
		$assert( ! empty( $archive['source_route'] ) && ! empty( $archive['presentation_markup'] ), 'Each non-Latin archive materializes with content and a route: ' . json_encode( $archive ) );
		$routes[] = $archive['source_route'];
	}
	$assert( 2 === count( array_unique( $routes ) ), 'Non-Latin archives have distinct derived routes: ' . json_encode( $routes ) );
	$extracted = Static_Site_Importer_Content_Index::extract( array( 'files' => $archive_files ) );
	foreach ( $extracted['artifact']['files'] as $file ) {
		$assert( ! isset( $file['metadata']['route_path'] ), 'A lost segment must not declare an explicit parent route.' );
	}
	$assert( array() === $extracted['aliases'], 'A lost segment must not create a route alias.' );
} finally {
	ssi_harness_cleanup();
}
echo "Content index import passed.\n";
