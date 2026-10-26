<?php
/**
 * An oversized editor control chrome degrades to a recorded layout loss
 * instead of emitting an overlay that output admission later rejects, and the
 * assembled overlay then keeps its front-end CSS with no editor CSS at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'Static_Site_Importer_Form_Presentation_Overlay' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-presentation-overlay.php';
}

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// Neutral form: a flex root and many captured controls, each with its own
// bounded presentation destination.
$scope   = '.ssi-form-123456789abc';
$graph   = array(
	'nodes' => array(
		array(
			'id'     => 'form',
			'layout' => array(
				'display'   => 'flex',
				'direction' => 'column',
				'gap'       => '1rem',
			),
		),
	),
);
$map     = array(
	'schema'               => Static_Site_Importer_Form_Presentation_Overlay::MAP_SCHEMA,
	'provider'             => 'synthetic',
	'scope'                => $scope,
	'targets'              => array(
		array(
			'node'         => 'form',
			'selector'     => $scope . ' > form.provider-form',
			'capabilities' => array( 'container_layout' ),
		),
	),
	'presentation_targets' => array(),
);
$styles  = array(
	'background_color' => '#ffffff',
	'border_color'     => '#cccccc',
	'border_style'     => 'solid',
	'border_width'     => '1px',
	'border_radius'    => '4px',
	'color'            => '#111111',
	'font_size'        => '16px',
	'line_height'      => '24px',
	'padding'          => '8px 12px',
);
$controls = array();
for ( $index = 0; $index < 128; ++$index ) {
	$map['presentation_targets'][] = array(
		'index'        => $index,
		'destinations' => array(
			array(
				'role'       => 'control',
				'selector'   => $scope . ' .ssi-node-' . sprintf( '%012x', $index ),
				'properties' => array_keys( $styles ),
			),
		),
	);
	$controls[]                    = array(
		'index'   => $index,
		'control' => array( 'styles' => $styles ),
	);
}
$presentation_graph = array( 'controls' => $controls );

$shared_editor = Static_Site_Importer_Form_Presentation_Overlay::compile( $graph, $map, $presentation_graph, Static_Site_Importer_Form_Presentation_Overlay::SURFACE_EDITOR );
$assert( empty( $shared_editor['losses'] ) && ! empty( $shared_editor['overlay']['editor_css'] ), 'equivalent adjacent control presentations fit the editor admission bound' );
$assert( null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $shared_editor['overlay'] ), 'compacted shared editor presentation is admitted' );

// Distinct declarations cannot share a selector list and must retain the bound.
foreach ( $presentation_graph['controls'] as $index => &$control ) {
	$control['control']['styles']['color'] = sprintf( '#%06x', $index + 1 );
	$control['control']['styles']['font_family'] = '"' . str_repeat( 'NeutralFont', 22 ) . '"';
}
unset( $control );
foreach ( $map['presentation_targets'] as &$target ) {
	$target['destinations'][0]['properties'][] = 'font_family';
}
unset( $target );

$frontend = Static_Site_Importer_Form_Presentation_Overlay::compile( $graph, $map, $presentation_graph );
$assert( array() === $frontend['losses'] && strlen( $frontend['css'] ) <= 65536, 'fixture frontend overlay fits its bound (' . strlen( $frontend['css'] ) . ' bytes)' );
$assert( null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $frontend['overlay'] ), 'fixture frontend overlay is admitted' );

$editor      = Static_Site_Importer_Form_Presentation_Overlay::compile( $graph, $map, $presentation_graph, Static_Site_Importer_Form_Presentation_Overlay::SURFACE_EDITOR );
$editor_size = strlen( $editor['overlay']['editor_css'] ?? '' );
$assert(
	array() === $editor['overlay'] && '' === $editor['css'] && in_array( 'provider layout overlay exceeds its bounded size.', array_column( $editor['losses'], 'map_error' ), true ),
	'editor chrome over the admission bound compiles to a recorded layout loss, not an overlay (editor_css ' . $editor_size . ' bytes)'
);

// Assembled surfaces: the editor overlay is over its bound while the front end
// fits. The front end keeps its overlay, the editor carries no CSS at all (no
// chrome-only fallback compiled from the front-end map), and the bound loss is
// recorded. A control container gives a chrome-only pass something to emit.
$bound_map = $map;

$bound_map['presentation_targets'][0]['destinations'][] = array(
	'role'       => 'control_container',
	'selector'   => $scope . ' .ssi-node-ffffffffffff',
	'properties' => array( 'background_color', 'border_color' ),
);

$bound_graph = $presentation_graph;

$bound_graph['control_containers'] = array(
	array(
		'index'  => 0,
		'styles' => array(
			'background_color' => '#fafafa',
			'border_color'     => '#dddddd',
		),
	),
);

$bound_front    = Static_Site_Importer_Form_Presentation_Overlay::compile( $graph, $bound_map, $bound_graph );
$bound_surfaces = Static_Site_Importer_Form_Presentation_Overlay::compile_surfaces( $graph, $bound_map, $bound_map, $bound_graph );
$bound_css      = $bound_front['css'];
$assert( '' !== $bound_css && $bound_surfaces['css'] === $bound_css && ( $bound_surfaces['overlay']['css'] ?? null ) === $bound_css, 'an over-bound editor surface keeps the front-end css' );
$assert( '' === ( $bound_surfaces['overlay']['editor_css'] ?? '' ) && '' === ( $bound_surfaces['overlay']['editor_context_css'] ?? '' ), 'an over-bound editor surface carries no editor css (editor_css ' . strlen( $bound_surfaces['overlay']['editor_css'] ?? '' ) . ' bytes)' );
$assert( in_array( 'provider layout overlay exceeds its bounded size.', array_column( $bound_surfaces['losses'], 'map_error' ), true ), 'an over-bound editor surface records its bound loss' );
$assert( null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $bound_surfaces['overlay'] ), 'the front-end-only overlay is admitted' );

echo "provider layout overlay editor bound smoke passed ({$assertions} assertions)\n";
