<?php
/**
 * Compaction preserves cascade boundaries and conditional applicability.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! class_exists( 'Static_Site_Importer_Form_Presentation_Overlay' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-presentation-overlay.php';
}
$method   = new ReflectionMethod( Static_Site_Importer_Form_Presentation_Overlay::class, 'compact_rules' );
$node_a   = '.ssi-form-123456789abc .ssi-node-000000000001-wrap';
$node_b   = '.ssi-form-123456789abc .ssi-node-000000000002-wrap';
$node_c   = '.ssi-form-123456789abc .ssi-node-000000000003-wrap';
$link_a   = '.ssi-form-123456789abc .ssi-node-000000000001 > .wp-block-button__link';
$field_b  = '.ssi-form-123456789abc .ssi-node-000000000002';
$link_c   = '.ssi-form-123456789abc .ssi-node-000000000003 > .wp-block-button__link';
$field_a  = '.ssi-form-123456789abc .ssi-node-000000000001';
$field_c  = '.ssi-form-123456789abc .ssi-node-000000000003';
$option_a = '.editor-styles-wrapper .ssi-form-123456789abc .ssi-source-choice-label--ssi-node-000000000004';
$option_c = '.editor-styles-wrapper .ssi-form-123456789abc .ssi-source-choice-label--ssi-node-000000000006';
$cases    = array(
	array( array( '@media (max-width:500px){' . $node_a . '{color:red}}', "$node_b{color:blue}", '@media (max-width:500px){' . $node_c . '{color:red}}' ), array( '@media (max-width:500px){' . $node_a . ', ' . $node_c . '{color:red}}', "$node_b{color:blue}" ) ),
	array( array( "$node_a{margin:0}", "$node_a{margin-left:8px}", "$node_a{margin:0}" ), array( "$node_a{margin:0}", "$node_a{margin-left:8px}", "$node_a{margin:0}" ) ),
	array( array( "$node_a{color:red}", '.unknown{color:blue}', "$node_c{color:red}" ), array( "$node_a{color:red}", '.unknown{color:blue}', "$node_c{color:red}" ) ),
	array( array( "$node_a{color:red}", "$node_b{color:blue}", "$node_c{color:red}" ), array( "$node_a, $node_c{color:red}", "$node_b{color:blue}" ) ),
	// A node's core/button link and placeholder are that node's own boxes, so they never block distinct nodes' rules from merging.
	array( array( "$link_a{margin:0}", "$field_b::placeholder{color:red}", "$link_c{margin:0}" ), array( "$link_a, $link_c{margin:0}", "$field_b::placeholder{color:red}" ) ),
	// A node's unclassed `> label` and required marker are its own boxes too: repeated choice rows merge.
	array( array( "$field_a{display:flex}", "$field_a > label{color:red}", "$field_b > .grunion-label-required{margin:0}", "$field_c{display:flex}" ), array( "$field_a, $field_c{display:flex}", "$field_a > label{color:red}", "$field_b > .grunion-label-required{margin:0}" ) ),
	// Distinct subject elements never compete; neither do disjoint property families.
	array( array( '.x input{color:red}', '.y label{color:blue}', '.z input{color:red}' ), array( '.x input, .z input{color:red}', '.y label{color:blue}' ) ),
	array( array( '.x{display:flex}', '.y{color:blue}', '.z{display:flex}' ), array( '.x, .z{display:flex}', '.y{color:blue}' ) ),
	// Jetpack's editor option block, its checkbox and its label RichText are distinct elements, so per-option rules merge across options.
	array( array( "$field_a > input.jetpack-field-option__checkbox{display:flex}", "$option_a > .jetpack-field-option__label-wrapper > .rich-text{margin:0}", "$option_a{display:inline-flex}", "$field_c > input.jetpack-field-option__checkbox{display:flex}", "$option_c > .jetpack-field-option__label-wrapper > .rich-text{margin:0}", "$option_c{display:inline-flex}" ), array( "$field_a > input.jetpack-field-option__checkbox, $field_c > input.jetpack-field-option__checkbox{display:flex}", "$option_a > .jetpack-field-option__label-wrapper > .rich-text, $option_c > .jetpack-field-option__label-wrapper > .rich-text{margin:0}", "$option_a, $option_c{display:inline-flex}" ) ),
	// An unknown div may still be the option block or its label text.
	array( array( "$option_a{display:flex}", '.y div{display:block}', "$option_c{display:flex}" ), array( "$option_a{display:flex}", '.y div{display:block}', "$option_c{display:flex}" ) ),
	// Shorthands, logical forms and `all` share a family, so they stay barriers.
	array( array( '.x{margin:0}', '.y{margin-left:8px}', '.z{margin:0}' ), array( '.x{margin:0}', '.y{margin-left:8px}', '.z{margin:0}' ) ),
	array( array( '.x{width:10px}', '.y{inline-size:20px}', '.z{width:10px}' ), array( '.x{width:10px}', '.y{inline-size:20px}', '.z{width:10px}' ) ),
	array( array( '.x{color:red}', '.y{all:unset}', '.z{color:red}' ), array( '.x{color:red}', '.y{all:unset}', '.z{color:red}' ) ),
	array( array( "$node_a{color:red}", "$node_a{color:blue}", "$node_a{color:red}" ), array( "$node_a{color:red}", "$node_a{color:blue}", "$node_a{color:red}" ) ),
	array( array( "$node_a{color:red}", "$node_c{color:blue}", "$node_c{color:red}" ), array( "$node_a{color:red}", "$node_c{color:blue}", "$node_c{color:red}" ) ),
	array( array( '.a{color:red}', '.b{color:red}' ), array( '.a, .b{color:red}' ) ),
	array( array( '.a{color:red}', '.a{color:blue}', '.b{color:red}' ), array( '.a{color:red}', '.a{color:blue}', '.b{color:red}' ) ),
	array( array( '@media (min-width:1080px){.a{color:red}}', '@media (max-width:1079px){.a{color:red}}' ), array( '@media (min-width:1080px),(max-width:1079px){.a{color:red}}' ) ),
	array( array( '@media (min-width:1080px){.a{color:red}}', '@media (min-width:1080px){.b{color:red}}' ), array( '@media (min-width:1080px){.a, .b{color:red}}' ) ),
	array( array( '@container (min-width:1080px){.a{color:red}}', '@container (max-width:1079px){.a{color:red}}' ), array( '@container (min-width:1080px){.a{color:red}}', '@container (max-width:1079px){.a{color:red}}' ) ),
	array( array( '@media (min-width:1080px){@container (min-width:500px){.a{color:red}}}', '@media (min-width:1080px){@container (min-width:500px){.b{color:red}}}' ), array( '@media (min-width:1080px){@container (min-width:500px){.a, .b{color:red}}}' ) ),
);
foreach ( $cases as $index => list( $input, $expected ) ) {
	if ( $method->invoke( null, $input ) !== $expected ) {
		throw new RuntimeException( 'Rule compaction changed cascade case ' . $index );
	}
}
$limit = new ReflectionMethod( Static_Site_Importer_Form_Presentation_Overlay::class, 'overlay_byte_limit' );
if ( 16384 !== $limit->invoke( null, '' ) || 17408 !== $limit->invoke( null, "$node_a{color:red}\n$node_a{color:blue}" ) || 18432 !== $limit->invoke( null, "$node_a{color:red}\n$node_b{color:blue}" ) ) {
	throw new RuntimeException( 'Overlay budget must grow with unique mapped nodes, not repeated rules.' );
}
$large = '';
for ( $index = 0; $index < 128; ++$index ) {
	$large .= '.ssi-node-' . sprintf( '%012x', $index ) . '{color:red}';
}
if ( 65536 !== $limit->invoke( null, $large ) ) {
	throw new RuntimeException( 'Overlay budget must retain its hard ceiling.' );
}
echo 'provider overlay compaction smoke passed (' . count( $cases ) . " cases)\n";
