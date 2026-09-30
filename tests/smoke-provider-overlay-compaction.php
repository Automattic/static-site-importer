<?php
/** Adjacent compaction preserves cascade boundaries and conditional applicability. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
}
$method = new ReflectionMethod( Static_Site_Importer_Provider_Layout_Overlay::class, 'compact_rules' );
$cases  = array(
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
echo 'provider overlay compaction smoke passed (' . count( $cases ) . " cases)\n";
