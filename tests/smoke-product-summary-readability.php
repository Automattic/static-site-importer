<?php
/**
 * Intrinsic single-product summary readability contract.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once ABSPATH . 'includes/class-static-site-importer-product-presentation.php';

$overlay = Static_Site_Importer_Product_Presentation::capture( array(
	'gallery' => array( 'main' => array( 'aspectRatio' => '3/4' ) ),
) );
$scope = '.' . $overlay['className'] . '.' . $overlay['className'];
foreach ( array(
	':has(> ' . $scope . ' + *){flex-wrap:wrap !important;}',
	$scope . ' + *{min-width:min(100%,20rem) !important;flex-grow:1 !important;}',
	'aspect-ratio:3/4 !important;',
) as $expected ) {
	if ( ! str_contains( $overlay['css'], $expected ) ) {
		throw new RuntimeException( 'Missing intrinsic product presentation: ' . $expected );
	}
}
if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) || str_contains( $overlay['css'], '@media' ) ) {
	throw new RuntimeException( 'Readability must survive publication without viewport breakpoints.' );
}
// The measured source row: gallery + gap + summary floor determines wrapping.
// Once alone on its line, flex-grow fills the summary's available content width.
foreach ( array( 768 => array( 691, 450, true ), 1024 => array( 922, 471, false ), 1440 => array( 1296, 662, false ) ) as $viewport => list( $content, $gallery, $wrap ) ) {
	if ( ( $gallery + 27 + 20 * 16 > $content ) !== $wrap ) {
		throw new RuntimeException( 'Unexpected wrap threshold at ' . $viewport );
	}
}
echo "Product summary readability smoke passed.\n";
