<?php
/** Run with WP-CLI in an explicitly disposable, HTTP-serving WordPress site. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_ROUTE_OWNERSHIP_TEST' ) ) {
	throw new RuntimeException( 'Set SSI_ROUTE_OWNERSHIP_TEST=1 in a disposable WordPress site.' );
}
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-internal-link-runtime.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$filesystem = new WP_Filesystem_Direct( null );

$assert = static function ( bool $ok, string $label ): void {
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $label ) );
	}
};
$theme  = 'ssi-source-route-ownership-proof';
$dir    = get_theme_root() . '/' . $theme;
wp_mkdir_p( $dir );
$overlay = Static_Site_Importer_Internal_Link_Runtime::prepare_overlay( array(), array(), $theme );
foreach ( $overlay['writes'] as $write ) {
	$filesystem->put_contents( $dir . '/' . $write['target_path'], $write['content'], 0644 );
}
$filesystem->put_contents( $dir . '/style.css', "/*\nTheme Name: Source Route Ownership Proof\nVersion: 1\n*/\n", 0644 );
$filesystem->put_contents( $dir . '/index.php', '<?php echo "<!doctype html><html><head>"; wp_head(); echo "</head><body>"; while(have_posts()){the_post();echo "<h1>".esc_html(get_the_title())."</h1>";the_content();} echo "</body></html>";', 0644 );
switch_theme( $theme );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'tag_base', '' );

$upsert_page = static function ( string $slug, string $body, int $parent_id = 0, ?string $source = null ): int {
	$existing = get_page_by_path( ( $parent_id ? get_page_uri( $parent_id ) . '/' : '' ) . $slug );
	$id       = wp_insert_post(
		array(
			'ID'           => $existing ? $existing->ID : 0,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $slug,
			'post_content' => '<!-- wp:paragraph --><p>' . $body . '</p><!-- /wp:paragraph -->',
			'post_parent'  => $parent_id,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( $id->get_error_message() ) );
	}
	if ( null !== $source ) {
		update_post_meta( $id, '_static_site_importer_source_route', $source );
	}
	return $id;
};
$home        = $upsert_page( 'home-proof', 'HOME_DOCUMENT', 0, 'index.html' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
$tag_parent       = $upsert_page( 'tag', 'SYNTHETIC_TAG_PARENT' );
$topic            = $upsert_page( 'topic', 'SYNTHETIC_TOPIC_PARENT', $tag_parent );
$pager            = $upsert_page( 'page', 'SYNTHETIC_PAGER_PARENT', $topic );
$tag_page         = $upsert_page( '1-2', 'IMPORTED_TAG_DOCUMENT', $pager, 'tag/topic/page/1/index.html' );
$page_root        = $upsert_page( 'page', 'SYNTHETIC_PAGE_PARENT' );
$second           = $upsert_page( '2-2', 'IMPORTED_SECOND_DOCUMENT', $page_root, 'page/2/index.html' );
$control_existing = get_page_by_path( 'native-control', OBJECT, 'post' );
$control_id       = wp_insert_post(
	array(
		'ID'           => $control_existing ? $control_existing->ID : 0,
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_name'    => 'native-control',
		'post_title'   => 'Native control',
		'post_content' => '<!-- wp:paragraph --><p>NATIVE_ARCHIVE_DOCUMENT</p><!-- /wp:paragraph -->',
		'tags_input'   => array( 'topic', 'native-control' ),
	)
);
$article_existing = get_page_by_path( 'article-native-slug', OBJECT, 'post' );
$article          = wp_insert_post(
	array(
		'ID'           => $article_existing ? $article_existing->ID : 0,
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_name'    => 'article-native-slug',
		'post_title'   => 'Imported article',
		'post_content' => '<!-- wp:paragraph --><p>IMPORTED_POST_DOCUMENT</p><!-- /wp:paragraph -->',
	)
);
update_post_meta( $article, '_static_site_importer_source_route', 'notes/person/index.html' );
flush_rewrite_rules( false );

// HTTP requests load only the generated theme's copies, not this CLI's classes.
$http = static function ( string $path, string $marker, string $absent = '' ) use ( $assert ): void {
	$response = wp_remote_get(
		home_url( $path ),
		array(
			'timeout'     => 15,
			'redirection' => 3,
		)
	);
	$assert( ! is_wp_error( $response ), 'HTTP request failed: ' . $path );
	$assert( 200 === wp_remote_retrieve_response_code( $response ), 'Unexpected HTTP status: ' . $path );
	$body = wp_remote_retrieve_body( $response );
	$assert( str_contains( $body, $marker ), 'Wrong document: ' . $path );
	$assert( '' === $absent || ! str_contains( $body, $absent ), 'Conflicting native content served: ' . $path );
};
$http( '/tag/topic/page/1/?utm_source=proof', 'IMPORTED_TAG_DOCUMENT', 'NATIVE_ARCHIVE_DOCUMENT' );
$http( '/page/2/', 'IMPORTED_SECOND_DOCUMENT', 'HOME_DOCUMENT' );
$http( '/notes/person/', 'IMPORTED_POST_DOCUMENT' );
$http( '/tag/native-control/feed/', 'NATIVE_ARCHIVE_DOCUMENT', 'IMPORTED_TAG_DOCUMENT' );

// Load the same generated bootstrap for the CLI's canonical-link assertions.
require $dir . '/functions.php';
$assert( home_url( '/tag/topic/page/1/' ) === get_permalink( $tag_page ), 'Renamed numeric slug leaked into canonical URL.' );
$assert( home_url( '/page/2/' ) === get_permalink( $second ), 'Pagination source route was not canonical.' );
$assert( home_url( '/notes/person/' ) === get_permalink( $article ), 'Imported post source route was not canonical.' );
update_post_meta( $tag_page, '_static_site_importer_source_route', 'tag/topic/page/1/index.html' );
$http( '/tag/topic/page/1/', 'IMPORTED_TAG_DOCUMENT' );

// The public exporter must keep owned source paths, not renamed native slugs.
require_once dirname( __DIR__, 2 ) . '/static-site-importer.php';
$export = Static_Site_Importer_Theme_Exporter::export_theme( array( 'include_pages' => array( $home, $tag_page, $second, $article ) ) );
$assert( ! is_wp_error( $export ), 'Website export failed.' );
$paths = array_column( $export['website_artifact']['files'], 'path' );
foreach ( array( 'website/tag/topic/page/1/index.html', 'website/page/2/index.html', 'website/notes/person/index.html' ) as $artifact_path ) {
	$assert( in_array( $artifact_path, $paths, true ), 'Export lost source route: ' . $artifact_path );
}
foreach ( array( 'IMPORTED_TAG_DOCUMENT', 'IMPORTED_SECOND_DOCUMENT', 'IMPORTED_POST_DOCUMENT' ) as $marker ) {
	$assert( str_contains( wp_json_encode( $export['website_artifact'] ), $marker ), 'Export lost native content: ' . $marker );
}
$export_path = getenv( 'SSI_ROUTE_EXPORT_OUTPUT' );
if ( is_string( $export_path ) && '' !== $export_path ) {
	$filesystem->put_contents( $export_path, wp_json_encode( $export['website_artifact'] ), 0644 );
}

echo wp_json_encode(
	array(
		'status'         => 'passed',
		'pages'          => array(
			'tag'     => $tag_page,
			'second'  => $second,
			'article' => $article,
		),
		'home'           => home_url( '/' ),
		'native_control' => $control_id,
	)
) . "\n";
