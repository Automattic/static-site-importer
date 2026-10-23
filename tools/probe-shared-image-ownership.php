<?php
/**
 * Reduced producer-side ownership probe for #2315 (not a fixed-behavior test).
 * Run with STATIC_SITE_IMPORTER_WP_ROOT pointing at WordPress core.
 */
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/tests/fixtures/core-html-api.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$fixture = dirname( __DIR__ ) . '/tests/fixtures/shared-image-background/';
$plan = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array(
		array( 'path' => 'index.html', 'content' => file_get_contents( $fixture . 'index.html' ), 'mime_type' => 'text/html' ),
		array( 'path' => 'style.css', 'content' => file_get_contents( $fixture . 'style.css' ), 'mime_type' => 'text/css' ),
		array( 'path' => 'shared.png', 'content_base64' => base64_encode( file_get_contents( $fixture . 'shared.png' ) ), 'mime_type' => 'image/png', 'binary' => true ),
	),
) )->toArray()['source_reports']['wordpress_site_plan'];

$images = array_values( array_filter( $plan['assets'], static fn( array $asset ): bool => 'shared.png' === $asset['source_path'] ) );
if ( 1 !== count( $images ) ) {
	throw new RuntimeException( 'Expected exactly one planned source image.' );
}
$image = $images[0];
$token = '{{wordpress-site-plan:asset:' . $image['token'] . '}}';
$page_uses = 0;
foreach ( $plan['pages'] as $page ) {
	$page_uses += substr_count( $page['canonical_block_markup'], $token );
}
$css_uses = 0;
$css_urls = array();
foreach ( $plan['writes'] as $write ) {
	if ( ! str_ends_with( $write['target_path'], '.css' ) || 'utf8' !== $write['payload']['encoding'] ) {
		continue;
	}
	$uses = substr_count( $write['payload']['data'], $token );
	$css_uses += $uses;
	if ( $uses ) {
		$css_urls[ $write['target_path'] ] = WordPressSitePlanResolver::referencesForWrite( $plan['reference_tokens'], 'https://destination.test/themes/reduction', $write['target_path'] )[ $token ];
	}
}
if ( $page_uses < 1 || $css_uses < 1 ) {
	throw new RuntimeException( 'The reduction must use the same declared token in page markup and CSS.' );
}
echo json_encode( array(
	'image_bytes' => filesize( $fixture . 'shared.png' ),
	'planned_source_images' => count( $images ),
	'image_target' => $image['target_path'],
	'page_token_occurrences' => $page_uses,
	'css_token_occurrences' => $css_uses,
	'resolved_css_urls' => $css_urls,
	'page_image_url' => WordPressSitePlanResolver::references( $plan['reference_tokens'], 'https://destination.test/themes/reduction' )[ $token ],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
