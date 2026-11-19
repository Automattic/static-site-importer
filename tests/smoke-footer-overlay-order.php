<?php
/**
 * A fixed overlay trailing a footer template part renders ahead of the part,
 * so the editor canvas keeps the front end's order while the part loads.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-footer-overlay-order.php';

$footer  = '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"div"} /-->';
$overlay = static fn( string $class, string $src ): string => '<!-- wp:paragraph {"className":"blocks-engine-synthetic-paragraph ' . $class . '"} --><p class="blocks-engine-synthetic-paragraph ' . $class . '"><a href="https://wa.me/1" aria-label="Chat"><img src="' . $src . '" alt=""></a></p><!-- /wp:paragraph -->';
$flow    = '<!-- wp:paragraph {"className":"notice"} --><p class="notice">After the footer</p><!-- /wp:paragraph -->';
$frame   = static fn( string $inner ): string => '<!-- wp:group {"className":"frame"} --><div class="wp-block-group frame"><!-- wp:template-part {"slug":"header","area":"header","tagName":"div"} /--><!-- wp:post-content /-->' . $inner . '</div><!-- /wp:group -->';

$canonical = $frame( $footer . $overlay( 'be-chat', '{{wordpress-site-plan:asset:asset-1}}' ) . $flow . $overlay( 'be-media', '{{wordpress-site-plan:asset:asset-2}}' ) . $overlay( 'be-loose', '{{wordpress-site-plan:asset:asset-3}}' ) );
$resolved  = $frame( $footer . $overlay( 'be-chat', 'https://example.test/chat.svg' ) . $flow . $overlay( 'be-media', 'https://example.test/media.svg' ) . $overlay( 'be-loose', 'https://example.test/loose.svg' ) );
$css       = '.be-chat.be-chat{display:flex;position:fixed;z-index:50;inset:auto 20px 20px auto}'
	// Fixed only below 768px: in flow on wide screens, so its place matters.
	. '.be-media{position:fixed;bottom:0;right:0}@media (min-width:768px){.be-media{position:static}}'
	// Fixed at its static position: moving it would move it on screen.
	. '.be-loose{position:fixed}';
$plan      = array(
	'template_parts' => array(
		array(
			'slug' => 'footer',
			'area' => 'footer',
		),
	),
	'templates'      => array(
		array(
			'slug'                   => 'page',
			'canonical_block_markup' => $canonical,
			'resolved_block_markup'  => $resolved,
		),
	),
	'writes'         => array(
		array(
			'target_path'            => 'templates/page.html',
			'payload'                => array(
				'encoding' => 'utf8',
				'data'     => $canonical,
			),
			'payload_hash'           => hash( 'sha256', $canonical ),
			'canonical_payload'      => $canonical,
			'canonical_payload_hash' => hash( 'sha256', $canonical ),
		),
		array(
			'target_path' => 'assets/css/engine-support.css',
			'payload'     => array(
				'encoding' => 'utf8',
				'data'     => $css,
			),
		),
	),
);

$result   = Static_Site_Importer_Footer_Overlay_Order::apply( $plan );
$expected = $frame( $overlay( 'be-chat', '{{wordpress-site-plan:asset:asset-1}}' ) . $footer . $flow . $overlay( 'be-media', '{{wordpress-site-plan:asset:asset-2}}' ) . $overlay( 'be-loose', '{{wordpress-site-plan:asset:asset-3}}' ) );
$write    = $result['writes'][0];
$checks   = array(
	'canonical template'      => $expected === $result['templates'][0]['canonical_block_markup'],
	'resolved template'       => $frame( $overlay( 'be-chat', 'https://example.test/chat.svg' ) . $footer . $flow . $overlay( 'be-media', 'https://example.test/media.svg' ) . $overlay( 'be-loose', 'https://example.test/loose.svg' ) ) === $result['templates'][0]['resolved_block_markup'],
	'theme write'             => $expected === $write['payload']['data'] && $expected === $write['canonical_payload'],
	'write hashes'            => hash( 'sha256', $expected ) === $write['payload_hash'] && hash( 'sha256', $expected ) === $write['canonical_payload_hash'],
	'stylesheet untouched'    => $plan['writes'][1] === $result['writes'][1],
	'only anchored fixed'     => array( 'be-chat' => true ) === Static_Site_Importer_Footer_Overlay_Order::anchored_fixed_classes( $plan['writes'] ),
	// Without a footer reference among its siblings, an overlay keeps its place.
	'nested footer unchanged' => $frame( '<!-- wp:group --><div class="wp-block-group">' . $footer . '</div><!-- /wp:group -->' . $overlay( 'be-chat', 'x' ) ) === Static_Site_Importer_Footer_Overlay_Order::reorder( $frame( '<!-- wp:group --><div class="wp-block-group">' . $footer . '</div><!-- /wp:group -->' . $overlay( 'be-chat', 'x' ) ), array( 'be-chat' => true ) ),
	'no fixed css no-op'      => $plan['templates'] === Static_Site_Importer_Footer_Overlay_Order::apply( array_merge( $plan, array( 'writes' => array( $plan['writes'][0] ) ) ) )['templates'],
);
$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
if ( array() !== $failed ) {
	fwrite( STDERR, 'Footer overlay order regression: ' . implode( ', ', $failed ) . "\n" . $result['templates'][0]['canonical_block_markup'] . "\n" );
	exit( 1 );
}
echo "Footer overlay order passed.\n";
