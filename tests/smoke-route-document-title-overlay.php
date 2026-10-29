<?php
/** Route title extraction feeds the post owner, not a theme overlay. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-route-document-metadata.php';
foreach ( array(
	'A &amp; B'             => 'A & B',
	'<b>About</b>'          => 'About',
	str_repeat( 'x', 1001 ) => '',
) as $input => $expected ) {
	$actual = Static_Site_Importer_Route_Document_Metadata::title_from_page( array( 'document_metadata' => array( 'title' => $input ) ) );
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Native title normalization failed.' );
	}
}
if ( method_exists( Static_Site_Importer_Route_Document_Metadata::class, 'prepare_overlay' ) ) {
	throw new RuntimeException( 'Retired theme title overlay must not return.' );
}
echo "native route title passed\n";
