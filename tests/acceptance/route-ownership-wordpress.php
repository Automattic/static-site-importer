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
	} else {
		// The site owner chooses a different language between imports; the next
		// import must record the decision as preserved, never overwrite it.
		update_option( 'WPLANG', 'fr_FR' );
	}
	$chunk = static function ( string $type, string $bytes ): string {
		return pack( 'N', strlen( $bytes ) ) . $type . $bytes . pack( 'N', crc32( $type . $bytes ) );
	};
	$png   = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 512, 512, 8, 2, 0, 0, 0 ) )
		. $chunk( 'IDAT', gzcompress( str_repeat( "\0" . str_repeat( "\x14\x28\x3c", 512 ), 512 ) ) ) . $chunk( 'IEND', '' );
	$files = array();
	foreach ( array(
		// path => array( title, lang, asset base, home, about, contact links ).
		'index.html'      => array( 'Garden home', 'nl', '', 'index.html', 'about.html', 'fr/contact.html' ),
		'about.html'      => array( 'About the garden', 'en', '', 'index.html', 'about.html', 'fr/contact.html' ),
		'fr/contact.html' => array( 'Contact the garden', 'fr', '../', '../index.html', '../about.html', 'contact.html' ),
	) as $path => $spec ) {
		list( $title, $lang, $base, $home, $about, $contact ) = $spec;
		$head    = '<link rel="icon" href="' . $base . 'assets/content.png"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Ownership Garden","slogan":"Growing together"}</script>';
		$files[] = array(
			'path'    => 'website/' . $path,
			'content' => '<!doctype html><html lang="' . $lang . '"><head><title>' . $title . ' | Garden</title><meta name="description" content="Authored description for ' . $title . '"><meta property="og:image" content="' . $base . 'assets/social.png">' . $head . '</head><body><header><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a><a href="' . $contact . '">Contact</a></nav></header><main><h1>' . $title . '</h1><p>Persistent garden content.</p><img src="' . $base . 'assets/content.png" alt="Garden artwork"></main></body></html>',
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
$assert( 3 === count( $pages ), 'Three imported routes persist.' );
$route_language_key = Static_Site_Importer_Site_Language::ROUTE_META_KEY;
// before: the source document language (nl → nl_NL) owns the site language
// through the core language-pack path. Later phases: the owner's fr_FR
// survives reimport, rollback and the theme switch untouched.
$site_language_owner = 'before' === $phase ? 'nl_NL' : 'fr_FR';
$assert( get_option( 'WPLANG' ) === $site_language_owner, 'The site language owner matches the phase expectation: source language applied via the core pack path, then the owner value preserved.' );
$route_language_owners = array(
	'Garden home'        => 'before' === $phase ? null : 'nl',
	'About the garden'   => 'en',
	'Contact the garden' => 'fr',
);
$snapshot              = array(
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
	$assert( get_post_meta( $id, 'jetpack_seo_html_title', true ) === sprintf( '%s | Garden', $page->post_title ), 'The branded document title belongs to the registered SEO title; post_title stays the route title.' );
	$assert( get_post_meta( $id, 'advanced_seo_description', true ) === $page->post_excerpt, 'Registered platform description is written.' );
	$expected_route_language = array_key_exists( $page->post_title, $route_language_owners ) ? $route_language_owners[ $page->post_title ] : false;
	$assert( false !== $expected_route_language, 'Every imported route is known to the language ownership proof: ' . $page->post_title );
	$route_language = get_post_meta( $id, $route_language_key, true );
	if ( null === $expected_route_language ) {
		$assert( ! metadata_exists( 'post', $id, $route_language_key ), 'A route sharing the site language stays unrecorded.' );
	} else {
		$assert( $expected_route_language === $route_language, 'A route whose source language differs from the site language carries its language on its own owner.' );
	}
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
		'route_language'  => $route_language,
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
