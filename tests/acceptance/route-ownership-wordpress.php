<?php
/** Public CLI import and native-owner snapshot in a disposable WordPress site. */
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Disposable CLI fixture, not plugin global state.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable request transport.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable evidence readback.
if ( '1' !== getenv( 'SSI_ROUTE_METADATA_DISPOSABLE' ) || ! defined( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Disposable WordPress CLI required.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$phase  = $args[0] ?? 'import';
if ( in_array( $phase, array( 'import', 'reimport' ), true ) ) {
	if ( 'import' === $phase ) {
		update_option( 'blogdescription', '' );
		delete_option( 'site_icon' );
	}
	$chunk = static function ( string $type, string $bytes ): string {
		return pack( 'N', strlen( $bytes ) ) . $type . $bytes . pack( 'N', crc32( $type . $bytes ) );
	};
	$png   = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 512, 512, 8, 2, 0, 0, 0 ) )
		. $chunk( 'IDAT', gzcompress( str_repeat( "\0" . str_repeat( "\x14\x28\x3c", 512 ), 512 ) ) ) . $chunk( 'IEND', '' );
	$head  = '<link rel="icon" href="assets/content.png"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Ownership Garden","slogan":"Growing together"}</script>';
	$files = array();
	foreach ( array(
		'index.html' => 'Garden home',
		'about.html' => 'About the garden',
	) as $path => $title ) {
		$files[] = array(
			'path'    => 'website/' . $path,
			'content' => '<!doctype html><html lang="en"><head><title>' . $title . ' | Garden</title><meta name="description" content="Authored description for ' . $title . '"><meta property="og:image" content="assets/social.png">' . $head . '</head><body><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><h1>' . $title . '</h1><p>Persistent garden content.</p><img src="assets/content.png" alt="Garden artwork"></main></body></html>',
		);
	}
	$files[]     = array(
		'path'           => 'website/assets/social.png',
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact transport.
		'content_base64' => base64_encode( $png ),
	);
	$content_png = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 512, 512, 8, 2, 0, 0, 0 ) )
		. $chunk( 'IDAT', gzcompress( str_repeat( "\0" . str_repeat( "\x50\x60\x70", 512 ), 512 ) ) ) . $chunk( 'IEND', '' );
	$files[]     = array(
		'path'           => 'website/assets/content.png',
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact transport.
		'content_base64' => base64_encode( $content_png ),
	);
	$files[]     = array(
		'path'    => 'website/_redirects',
		'content' => "/old-garden /about.html 301\n",
	);
	$request     = array(
		'operation' => 'apply',
		'overwrite' => true,
		'slug'      => 'ownership-garden',
		'activate'  => true,
		'source'    => array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'files'      => $files,
		),
	);
	$file        = wp_tempnam( 'ownership-request.json' );
	file_put_contents( $file, wp_json_encode( $request ) );
	try {
		$output  = WP_CLI::runcommand(
			'static-site-importer import --request=' . escapeshellarg( $file ) . ' --keep-source',
			array(
				'return'     => true,
				'launch'     => true,
				'exit_error' => false,
			)
		);
		$success = false;
		foreach ( explode( "\n", $output ) as $line ) {
			$receipt = json_decode( $line, true );
			if ( is_array( $receipt ) && 'static-site-importer/import-cli-receipt/v1' === ( $receipt['schema'] ?? '' ) ) {
				$success = true === ( $receipt['response']['success'] ?? false );
			}
		}
		$assert( $success, 'Public CLI import completes: ' . $output );
	} finally {
		wp_delete_file( $file );
	}
	WP_CLI::success( 'Imported through the public CLI.' );
	return;
}
if ( 'rollback' === $phase ) {
	$artifact = array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<html><head><title>Changed title</title><meta name="description" content="Changed description"></head><body><main><p>Changed content</p></main></body></html>',
			),
			array(
				'path'    => 'website/about.html',
				'content' => '<html><head><title>Changed about</title></head><body><main><p>Changed about</p></main></body></html>',
			),
		),
	);
	$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
		$artifact,
		array(
			'slug'                           => 'ownership-garden',
			'activate'                       => true,
			'overwrite'                      => true,
			'inject_materialization_failure' => 'after_blogname',
		)
	);
	$assert( ! is_wp_error( $compiled ), 'Rollback probe compiles.' );
	$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
	$assert( 'rolled_back' === ( $failed['rollback']['status'] ?? '' ), 'Late failure restores native route and registered SEO fields.' );
}
if ( 'switch' === $phase ) {
	switch_theme( 'twentytwentyfive' );
	flush_rewrite_rules();
}
$pages = get_posts(
	array(
		'post_type'   => 'page',
		'numberposts' => -1,
		'meta_key'    => '_static_site_importer_provenance',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	)
);
$assert( 2 === count( $pages ), 'Two imported routes persist.' );
$snapshot = array(
	'pages'    => array(),
	'menus'    => array(),
	'media'    => array(),
	'settings' => array(),
);
foreach ( $pages as $page ) {
	$id = $page->ID;
	$assert( str_contains( $page->post_content, 'Persistent garden content.' ), 'Content belongs to the post.' );
	$assert( ! str_contains( $page->post_content, '?page_id=' ), 'Stored links are native permalinks.' );
	$assert( 'Authored description for ' . $page->post_title === $page->post_excerpt, 'Description belongs to excerpt.' );
	$image = get_post_thumbnail_id( $id );
	$assert( $image > 0 && 'attachment' === get_post_type( $image ), 'Social image belongs to a Media Library attachment.' );
	$assert( $page->post_title . ' | Garden' === get_post_meta( $id, 'jetpack_seo_html_title', true ), 'The branded document title belongs to the registered SEO title; post_title stays the route title.' );
	$assert( get_post_meta( $id, 'advanced_seo_description', true ) === $page->post_excerpt, 'Registered platform description is written.' );
	$snapshot['pages'][] = array(
		'id'              => $id,
		'title'           => $page->post_title,
		'excerpt'         => $page->post_excerpt,
		'content'         => $page->post_content,
		'url'             => get_permalink( $id ),
		'image'           => $image,
		'image_url'       => wp_get_attachment_url( $image ),
		'seo_title'       => get_post_meta( $id, 'jetpack_seo_html_title', true ),
		'seo_description' => get_post_meta( $id, 'advanced_seo_description', true ),
	);
}
foreach ( get_posts(
	array(
		'post_type'   => 'wp_navigation',
		'post_status' => 'any',
		'numberposts' => -1,
	)
) as $menu ) {
	$assert( ! str_contains( $menu->post_content, '?page_id=' ), 'Menu URLs are persisted permalinks.' );
	$snapshot['menus'][ $menu->ID ] = array(
		'content'  => $menu->post_content,
		'rendered' => do_blocks( $menu->post_content ),
	);
}
$assert( count( $snapshot['menus'] ) > 0, 'Menus have native wp_navigation owners.' );
foreach ( get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'numberposts' => -1,
	)
) as $media ) {
	$assert( is_file( get_attached_file( $media->ID ) ), 'Media bytes persist.' );
	$snapshot['media'][ $media->ID ] = array(
		'url'  => wp_get_attachment_url( $media->ID ),
		'hash' => hash_file( 'sha256', get_attached_file( $media->ID ) ),
	);
}
foreach ( array( 'blogname', 'blogdescription', 'site_icon', 'WPLANG', 'page_on_front', 'show_on_front', 'permalink_structure' ) as $option ) {
	$snapshot['settings'][ $option ] = get_option( $option );
}
$assert( 'Garden home' === get_option( 'blogname' ), 'Site title has an option owner.' );
$assert( 'Growing together' === html_entity_decode( get_option( 'blogdescription' ), ENT_QUOTES | ENT_HTML5 ), 'Tagline has an option owner.' );
$assert( (int) get_option( 'site_icon' ) > 0, 'Site icon has an attachment owner.' );
file_put_contents( '/evidence/' . ( 'switch' === $phase ? 'after' : $phase ) . '.json', wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
WP_CLI::success( 'Native owners verified: ' . $phase );
