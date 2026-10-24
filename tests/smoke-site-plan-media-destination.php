<?php
/**
 * Real WordPress media-destination transaction smoke. Run with wp eval-file.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	throw new RuntimeException( 'This smoke requires a loaded disposable WordPress runtime.' );
}
require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$slug   = 'ssi-media-' . strtolower( wp_generate_password( 8, false, false ) );
// Three distinct 20x20 PNGs exercise core attachment metadata without GD fixtures.
$images                     = array_map(
	'base64_decode',
	array(
		'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC',
		'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHUlEQVQ4jWNkYPjPQC5gIlvnqOZRzaOaRzVTRTMAIJ4BJ8n5s94AAAAASUVORK5CYII=',
		'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHElEQVQ4jWNk+M9ANmAiX+uo5lHNo5pHNVNDMwAhnQEnl6/TDAAAAABJRU5ErkJggg==',
	)
);
$artifact                   = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html' => '<html><head><link rel="stylesheet" href="site.css"></head><body><main><h1>Media destinations</h1><img src="block.png" alt="Block artwork"><img src="dual.png" alt="Dual artwork"><p class="css-only">CSS only</p><p class="dual">Shared artwork</p></main></body></html>',
		'site.css'   => '.css-only { background-image: url("css.png"); } .dual { background-image: url("dual.png"); }',
		'block.png'  => $images[0],
		'dual.png'   => $images[1],
		'css.png'    => $images[2],
	),
);
$compiler_artifact          = $artifact;
$compiler_artifact['files'] = array();
foreach ( $artifact['files'] as $path => $bytes ) {
	$compiler_artifact['files'][] = str_ends_with( $path, '.png' )
		? array(
			'path'           => $path,
			'content_base64' => base64_encode( $bytes ),
		)
		: array(
			'path'    => $path,
			'content' => $bytes,
		);
}
$plan    = ( new ArtifactCompiler() )->compile( $compiler_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$dir     = get_theme_root() . '/' . $slug;
$args    = array(
	'slug'                         => $slug,
	'name'                         => 'Media destinations',
	'activate'                     => false,
	'defer_materialization_commit' => true,
);
$receipt = null;
$before  = (int) wp_count_posts( 'attachment' )->inherit;
try {
	$prepared = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
	$assert( 'prepared' === $prepared['status'], 'Preparation must succeed without uploading.' );
	$assert( $before === (int) wp_count_posts( 'attachment' )->inherit, 'Read-only preparation must not create attachments.' );
	$receipt = Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
	$assert( 'completed' === ( $receipt['status'] ?? '' ), 'Materialization failed: ' . wp_json_encode( $receipt ) );
	// Exercise the same public receipt projection that writes the import manifest.
	$project = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'public_result_from_wordpress_site_plan_receipt' );
	$project->invoke( null, $receipt, $args );
	$functions = file_get_contents( $dir . '/functions.php' );
	$manifest  = json_decode( file_get_contents( $dir . '/static-site-importer-manifest.json' ), true );
	$ids       = array();
	foreach ( $plan['assets'] as $asset ) {
		$source = basename( $asset['source_path'] );
		if ( ! in_array( $source, array( 'block.png', 'dual.png', 'css.png' ), true ) ) {
			continue;
		}
		$hash    = hash( 'sha256', $artifact['files'][ $source ] );
		$matches = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'numberposts' => -1,
				'meta_key'    => Static_Site_Importer_Media_Library_Materializer::SOURCE_ASSET_META_KEY,
				'meta_value'  => $slug . '#' . $hash,
			)
		);
		if ( 'css.png' === $source ) {
			$assert( empty( $matches ) && is_file( $dir . '/' . $asset['target_path'] ), 'CSS-only artwork belongs only to the theme.' );
			continue;
		}
		$assert( 1 === count( $matches ), 'Each content image must have exactly one attachment.' );
		$ids[ $source ] = (int) $matches[0];
		$metadata       = wp_get_attachment_metadata( $ids[ $source ] );
		$assert( 20 === $metadata['width'] && 20 === $metadata['height'], 'Core attachment metadata must be generated.' );
		$assert( ! empty( get_post_meta( $ids[ $source ], '_wp_attachment_image_alt', true ) ), 'Authored alt text must be retained.' );
		$assert( ! in_array( $asset['target_path'], array_column( $manifest['desired']['assets'], 'theme_path' ), true ), 'Manifest must not claim attachment artwork as a theme asset.' );
		$assert( ! file_exists( $dir . '/' . $asset['target_path'] ), 'Content artwork must not be a theme file.' );
		$assert( ! str_contains( $functions, $asset['token'] ), 'Content token must not be in functions.php.' );
		$assert( ! in_array( $asset['target_path'], array_column( $manifest['desired']['files'], 'path' ), true ), 'Manifest must not list the image as a theme write.' );
	}
	$assert( 2 === count( $ids ), 'Both block-consumed images must be tested.' );
	$assert( 2 === count( $receipt['transaction']->state['applied']['attachments'] ?? array() ), 'Exactly two uploads must be journaled, with none from the post-write pass.' );
	$posts   = get_posts(
		array(
			'post_type'   => 'page',
			'numberposts' => -1,
			'meta_key'    => '_static_site_importer_reconciliation_identity',
			'meta_value'  => $plan['pages'][0]['reconciliation_identity'],
		)
	);
	$content = ! empty( $posts ) ? $posts[0]->post_content : '';
	foreach ( $ids as $id ) {
		$assert( str_contains( $content, 'wp-image-' . $id ) && str_contains( $content, '"id":' . $id ), 'Core image binding must survive post-write materialization.' );
	}
	$blocks = parse_blocks( $content );
	$assert( ! empty( $blocks ) && serialize_blocks( $blocks ) === $content, 'Bound blocks must parse and round-trip.' );
	$css = '';
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( 'css' === $file->getExtension() ) {
			$css .= file_get_contents( $file->getPathname() );
		}
	}
	$assert( str_contains( $css, wp_get_attachment_url( $ids['dual.png'] ) ), 'Dual-use CSS must reference the attachment URL.' );
	// A repeated import reuses the content-addressed attachments. Its rollback
	// must restore content without deleting media owned by the first import.
	$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array_merge( $args, array( 'overwrite' => true ) ) );
	$assert( 'completed' === $repeat['status'] && empty( $repeat['transaction']->state['applied']['attachments'] ), 'Reimport must reuse attachments: ' . wp_json_encode( $repeat['errors'] ?? array() ) );
	Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $repeat, 'smoke_reimport_cleanup' );
	foreach ( $ids as $id ) {
		$assert( 'attachment' === get_post_type( $id ), 'Rollback must not delete reused attachments.' );
	}
} finally {
	if ( is_array( $receipt ) ) {
		Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, 'smoke_cleanup' );
	}
}
foreach ( $ids ?? array() as $id ) {
	$assert( null === get_post( $id ), 'Rollback must delete newly created attachments.' );
}
$failure_args = array_merge(
	$args,
	array(
		'slug'                           => $slug . '-failure',
		'inject_materialization_failure' => 'font_verification',
	)
);
$failed       = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $failure_args );
$assert( 'completed' !== $failed['status'], 'Injected failure must reject the import.' );
foreach ( array( 'block.png', 'dual.png' ) as $source ) {
	$remaining = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'fields'      => 'ids',
			'meta_key'    => Static_Site_Importer_Media_Library_Materializer::SOURCE_ASSET_META_KEY,
			'meta_value'  => $slug . '-failure#' . hash( 'sha256', $artifact['files'][ $source ] ),
		)
	);
	$assert( empty( $remaining ), 'Failure must delete uploads created before resolution.' );
}
echo "media destination smoke passed\n";
