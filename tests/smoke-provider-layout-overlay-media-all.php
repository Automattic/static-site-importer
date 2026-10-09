<?php
/**
 * A presentation variant under a stylesheet's implicit `media="all"` wrapper
 * holds at every width. A device-split capture tags each device stylesheet that
 * way, so the submit button's whole resting style (fill, text colour, corners)
 * arrives as such a variant. It must compile as an unconditional rule instead
 * of being recorded as a responsive loss and dropped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
}

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$scope = '.ssi-form-123456789abc';
$graph = array(
	'nodes' => array(
		array(
			'id'     => 'form',
			'layout' => array(
				'display'   => 'flex',
				'direction' => 'column',
			),
		),
	),
);
$map   = array(
	'schema'               => Static_Site_Importer_Provider_Layout_Overlay::MAP_SCHEMA,
	'provider'             => 'synthetic',
	'scope'                => $scope,
	'targets'              => array(
		array(
			'node'         => 'form',
			'selector'     => $scope . ' > form.provider-form',
			'capabilities' => array( 'container_layout' ),
		),
	),
	'presentation_targets' => array(
		array(
			'index'        => 0,
			'destinations' => array(
				array(
					'role'       => 'control',
					'selector'   => $scope . ' .ssi-node-000000000000 > .wp-block-button__link',
					'properties' => array( 'background_color', 'color', 'border_radius', 'font_size' ),
				),
			),
		),
	),
);
$presentation_graph = array(
	'controls' => array(),
	'variants' => array(
		array(
			'index'       => 0,
			'role'        => 'control',
			'condition'   => array(
				'kind'  => 'media',
				'query' => 'all',
			),
			'style_patch' => array(
				'background_color' => 'rgba(0,87,225,1)',
				'color'            => 'rgb(255,255,255)',
				'border_radius'    => '0px',
			),
		),
		array(
			'index'       => 0,
			'role'        => 'control',
			'condition'   => array(
				'kind'  => 'media',
				'query' => '(max-width:767px)',
			),
			'style_patch' => array( 'font_size' => '14px' ),
		),
	),
);

$compiled = Static_Site_Importer_Provider_Layout_Overlay::compile( $graph, $map, $presentation_graph );
$css      = (string) ( $compiled['css'] ?? '' );
$assert( ! str_contains( (string) json_encode( $compiled['losses'] ?? array() ), 'responsive_layout_ownership' ), 'a media="all" variant is not recorded as a responsive loss: ' . json_encode( $compiled['losses'] ?? array() ) );
$assert( 1 === preg_match( '/(?:^|\})[^@{}]*\.ssi-node-000000000000 > \.wp-block-button__link\{[^}]*background-color:rgba\(0,87,225,1\)/', $css ), 'the media="all" patch compiles outside any at-rule: ' . $css );
$assert( ! str_contains( $css, '@media all' ), 'no @media all wrapper is emitted: ' . $css );
$assert( 1 === preg_match( '/@media \(max-width:767px\)\{[^{}]*\.ssi-node-000000000000 > \.wp-block-button__link\{[^}]*font-size:14px/', $css ), 'a real breakpoint stays conditional: ' . $css );
$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $compiled['overlay'] ), 'the overlay is admitted' );


echo "provider layout overlay media all smoke passed ({$assertions} assertions)\n";
