<?php
/**
 * A choice label that spaces its box from its caption with a flex
 * `column-gap` (Squarespace: `label{display:inline-flex;column-gap:6px}`)
 * keeps that gap: Blocks Engine reports `column_gap` on the label role, and
 * the overlay must project it rather than drop it as an unknown property.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-presentation-overlay.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-presentation.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-readable-substitution.php';

$scope      = 'ssi-form-123456789abc';
$descriptor = Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor( $scope, 0, 'checkbox', array( 'control' => true, 'label' => true ), false, true, true );
$map        = Static_Site_Importer_Form_Presentation_Descriptor::without_parts( array(
	'schema'               => Static_Site_Importer_Form_Presentation_Overlay::MAP_SCHEMA,
	'provider'             => 'synthetic',
	'scope'                => '.' . $scope,
	'targets'              => array( array( 'node' => 'form', 'selector' => '.' . $scope . ' > form.provider-form', 'capabilities' => array( 'container_layout' ) ) ),
	'presentation_targets' => array( array( 'index' => 0, 'destinations' => $descriptor['destinations'] ) ),
) );
$compiled = Static_Site_Importer_Form_Presentation_Overlay::compile(
	array( 'nodes' => array( array( 'id' => 'form', 'layout' => array( 'display' => 'flex' ) ) ) ),
	$map,
	array( 'controls' => array( array( 'index' => 0, 'label' => array( 'styles' => array( 'display' => 'inline-flex', 'column_gap' => '6px' ) ) ) ) )
);
if ( ! str_contains( $compiled['css'], 'column-gap:6px' ) ) {
	throw new RuntimeException( 'Choice label lost its column gap: ' . $compiled['css'] );
}
if ( null === Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $compiled['overlay'] ) ) {
	throw new RuntimeException( 'Overlay with a label column gap must be admitted.' );
}
echo "Form label column gap passed.\n";
