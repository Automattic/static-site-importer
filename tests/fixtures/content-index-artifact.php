<?php
/**
 * A small data-liberation-agent Blogger export: three posts, two labels, one
 * captured label listing and the exporter's generated listing for the other.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Fixture data.

$document  = static fn( string $ssi_title, string $body ): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $ssi_title . '</title></head><body><main><h1>' . $ssi_title . '</h1>' . $body . '</main></body></html>';
$origin    = 'https://example.blogspot.com';
$ssi_posts = array(
	array( '2025/01/post-0.html', 'post-0', 'First trip', '2025-01-10T09:00:00.000-08:00', '2025-02-01T12:00:00Z', array( 'Travel', 'Food' ) ),
	array( '2025/02/post-1.html', 'post-1', 'Second trip', '2025-02-11T10:30:00Z', '2025-03-01T08:00:00Z', array( 'Travel' ) ),
	array( '2025/03/post-2.html', 'post-2', 'A recipe', '2025-03-12', '2025-03-13T00:00:00Z', array( 'Food' ) ),
);
$food_path = 'terms/' . hash( 'sha256', 'Food' ) . '.html';
$files     = array(
	array(
		'path'    => 'website/index.html',
		'content' => $document( 'Blog home', '<p>Latest posts.</p>' ),
	),
);
$index     = array(
	'schema'    => 'data-liberation-agent/content-index/v1',
	'posts'     => array(),
	'terms'     => array(
		array(
			'name'      => 'Travel',
			'kind'      => 'term-archive',
			'sourceUrl' => $origin . '/search/label/Travel',
			'path'      => '/search/label/Travel.html',
		),
		array(
			'name'      => 'Food',
			'kind'      => 'term-archive',
			'sourceUrl' => $origin . '/search/label/Food',
			'path'      => '/' . $food_path,
		),
	),
	'redirects' => array(),
);
foreach ( $ssi_posts as [ $ssi_path, $slug, $ssi_title, $published, $updated, $terms ] ) {
	$files[]              = array(
		'path'    => 'website/' . $ssi_path,
		'content' => $document( $ssi_title, '<p>' . $ssi_title . ' body text.</p>' ),
	);
	$index['posts'][]     = array(
		'permalink'   => $origin . '/' . $ssi_path,
		'slug'        => $slug,
		'kind'        => 'post',
		'title'       => $ssi_title,
		'contentHtml' => '<p>' . $ssi_title . ' body text.</p>',
		'published'   => $published,
		'updated'     => $updated,
		'author'      => 'Ada',
		'terms'       => $terms,
		'path'        => '/' . $ssi_path,
	);
	$index['redirects'][] = array(
		'from' => $origin . '/' . $ssi_path,
		'to'   => '/' . $ssi_path,
	);
}
foreach ( $index['terms'] as $ssi_term ) {
	$index['redirects'][] = array(
		'from' => $ssi_term['sourceUrl'],
		'to'   => $ssi_term['path'],
	);
}
// The one captured label listing, and the exporter's generated listing for the other label.
$files[] = array(
	'path'    => 'website/search/label/Travel.html',
	'content' => $document( 'Travel', '<article><h2><a href="/2025/01/post-0.html">First trip</a></h2></article><article><h2><a href="/2025/02/post-1.html">Second trip</a></h2></article>' ),
);
$files[] = array(
	'path'    => 'website/' . $food_path,
	'content' => $document( 'Food', '<ul><li><a href="/2025/01/post-0.html">First trip</a></li><li><a href="/2025/03/post-2.html">A recipe</a></li></ul>' ),
);
$files[] = array(
	'path'    => 'website/content-index.json',
	'content' => json_encode( $index ),
);
$files[] = array(
	'path'    => 'website/_redirects',
	'content' => "/search/label/Travel /search/label/Travel.html 301\n/search/label/Food /" . $food_path . " 301\n",
);


return array(
	'files'     => $files,
	'posts'     => $ssi_posts,
	'food_path' => $food_path,
);
