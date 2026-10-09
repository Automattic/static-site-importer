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

echo "submit caption color smoke passed ({$assertions} assertions)\n";
