<?php
/**
 * Compaction preserves cascade boundaries and conditional applicability.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
}
$method = new ReflectionMethod( Static_Site_Importer_Provider_Layout_Overlay::class, 'compact_rules' );
$node_a = '.ssi-form-123456789abc .ssi-node-000000000001-wrap';
$node_b = '.ssi-form-123456789abc .ssi-node-000000000002-wrap';
$node_c = '.ssi-form-123456789abc .ssi-node-000000000003-wrap';
$cases  = array(
	array( array( '@media (max-width:500px){' . $node_a . '{color:red}}', "$node_b{color:blue}", '@media (max-width:500px){' . $node_c . '{color:red}}' ), array( '@media (max-width:500px){' . $node_a . ', ' . $node_c . '{color:red}}', "$node_b{color:blue}" ) ),
	array( array( "$node_a{margin:0}", "$node_a{margin-left:8px}", "$node_a{margin:0}" ), array( "$node_a{margin:0}", "$node_a{margin-left:8px}", "$node_a{margin:0}" ) ),
	array( array( "$node_a{color:red}", '.unknown{color:blue}', "$node_c{color:red}" ), array( "$node_a{color:red}", '.unknown{color:blue}', "$node_c{color:red}" ) ),
	array( array( "$node_a{color:red}", "$node_b{color:blue}", "$node_c{color:red}" ), array( "$node_a, $node_c{color:red}", "$node_b{color:blue}" ) ),
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
$limit = new ReflectionMethod( Static_Site_Importer_Provider_Layout_Overlay::class, 'overlay_byte_limit' );
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
