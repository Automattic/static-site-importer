<?php
/**
 * A materialized submit keeps its source caption span, and the source rule
 * that painted that caption (Wix: `.mu5PoX .OR4Nv8{color:rgb(var(--txt))}`)
 * still matches it after the wrapper defining `--txt` is gone. The captured
 * text colour must therefore be restated on the caption span, not only on the
 * link, or the "Send" label falls back to the theme's black.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-layout-projection.php';

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$scope      = 'ssi-form-123456789abc';
$descriptor = Static_Site_Importer_Form_Layout_Projection::presentation_descriptor( $scope, 0, 'submit', array( 'control' => true ), true );
$caption    = array_values( array_filter( $descriptor['destinations'], static fn( array $destination ): bool => str_ends_with( $destination['selector'], ' > .wp-block-button__link > span' ) ) );
$assert( 1 === count( $caption ), 'a captioned submit has one caption destination' );
$assert( in_array( 'color', $caption[0]['properties'], true ), 'the caption destination carries the captured text colour' );

$graph = array(
	'nodes' => array(
		array(
			'id'     => 'form',
			'layout' => array( 'display' => 'flex' ),
		),
	),
);
$map   = array(
	'schema'               => Static_Site_Importer_Provider_Layout_Overlay::MAP_SCHEMA,
	'provider'             => 'synthetic',
	'scope'                => '.' . $scope,
	'targets'              => array(
		array(
			'node'         => 'form',
			'selector'     => '.' . $scope . ' > form.provider-form',
			'capabilities' => array( 'container_layout' ),
		),
	),
	'presentation_targets' => array(
		array(
			'index'        => 0,
			'destinations' => $descriptor['destinations'],
		),
	),
);
$presentation_graph = array(
	'controls' => array(
		array(
			'index'   => 0,
			'control' => array(
				'styles' => array(
					'background_color' => 'rgba(0,87,225,1)',
					'color'            => 'rgb(255,255,255)',
					'font_size'        => '14px',
				),
			),
		),
	),
);
$compiled = Static_Site_Importer_Provider_Layout_Overlay::compile( $graph, $map, $presentation_graph );
$css      = (string) ( $compiled['css'] ?? '' );
$assert( 1 === preg_match( '/' . preg_quote( $caption[0]['selector'], '/' ) . '\{[^}]*color:rgb\(255,255,255\)/', $css ), 'the caption span is painted with the button text colour: ' . $css );
$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $compiled['overlay'] ), 'the overlay is admitted' );


// The captured `color` is the caption's own resolved colour, not an ancestor's:
// Blocks Engine reads a button caption's typography from its sole text carrier.
// So restating it on the span cannot paint inherited body text over a caption
// that sets its own colour (Tailwind `<span class="text-white">`), and a Wix
// caption whose colour comes from a wrapper variable resolves to that value.
$caption_colour = static function ( string $source ): array {
	$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array( 'index.html' => $source ),
		)
	)->toArray();
	$colours = array();
	$walk    = static function ( mixed $node ) use ( &$walk, &$colours ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node['presentation_graph']['controls'] ?? array() as $row ) {
			if ( isset( $row['control']['styles']['color'] ) && ( isset( $row['control']['styles']['background'] ) || isset( $row['control']['styles']['background_color'] ) ) ) {
				$colours[] = $row['control']['styles']['color'];
			}
		}
		foreach ( $node as $child ) {
			$walk( $child );
		}
	};
	$walk( $compiled );
	return array_values( array_unique( $colours ) );
};
$form = static fn( string $css, string $button ): string => '<style>body{color:#111827}' . $css . '</style><main><form action="/contact" method="post"><label for="e">Email</label><input id="e" name="email" type="email">' . $button . '</form></main>';

$tailwind = $caption_colour( $form( '.bg-indigo-600{background:#4f46e5}.text-white{color:#fff}', '<button type="submit" class="bg-indigo-600"><span class="text-white">Send</span></button>' ) );
$assert( array( '#fff' ) === $tailwind, 'a caption with its own colour rule keeps it: ' . json_encode( $tailwind ) );
$wix = $caption_colour( $form( '#b1{--txt:255,255,255}.mu5PoX .twJknM{background:rgb(0,87,225)}.mu5PoX .OR4Nv8{color:rgb(var(--txt,0,0,0))}', '<div id="b1" class="mu5PoX"><button type="submit" class="twJknM"><span class="OR4Nv8">Send</span></button></div>' ) );
$assert( array( 'rgb(255,255,255)' ) === $wix, 'a Wix caption resolves its wrapper colour variable: ' . json_encode( $wix ) );

echo "submit caption color smoke passed ({$assertions} assertions)\n";
