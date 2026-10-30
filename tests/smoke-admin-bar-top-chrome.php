<?php
/**
 * Frontend top chrome offsets across canonical carried stylesheets.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php';

$resolved = array(
	'pages'  => array( array( 'resolved_block_markup' => '<header class="fixed inset-x-0 top-0"></header><span class="absolute top-0"></span><nav class="sticky top-gap"></nav>' ) ),
	'writes' => array(
		array( 'target_path' => 'style.css', 'payload' => array( 'encoding' => 'utf8', 'data' => '/* Theme Name: Fixture */' ) ),
		array( 'target_path' => 'assets/website/assets/styles-tailwind.css', 'payload' => array( 'encoding' => 'utf8', 'data' => '.fixed{position:fixed}.top-0{top:0}.absolute{position:absolute}.sticky{position:sticky}.top-gap{top:12px!important}.masthead{position:fixed;top:2rem!important}.modal{position:fixed;top:0}' ) ),
		array( 'target_path' => 'assets/css/editor-style.css', 'payload' => array( 'encoding' => 'utf8', 'data' => '.editor-header{position:fixed;top:0}' ) ),
	),
);
$result = Static_Site_Importer_Site_Plan_Preparation::with_admin_bar_top_chrome( $resolved );
$css    = $result['writes'][0]['payload']['data'];
$checks = array(
	str_contains( $css, ':root .fixed.top-0 { top: calc(0px + var(--wp-admin--admin-bar--height, 0px)); }' ),
	str_contains( $css, ':root .sticky.top-gap { top: calc(12px + var(--wp-admin--admin-bar--height, 0px)) !important; }' ),
	str_contains( $css, ':root .masthead { top: calc(2rem + var(--wp-admin--admin-bar--height, 0px)) !important; }' ),
	str_contains( $css, '@media screen and (max-width: 600px) { :root .fixed.top-0 { top: 0; } }' ),
	! str_contains( $css, '.absolute.top-0' ),
	! str_contains( $css, '.editor-header' ),
	! str_contains( $css, '.modal' ),
	! str_contains( $css, 'body.admin-bar' ),
	! str_contains( $css, '32px' ),
	! str_contains( $css, '46px' ),
	$result['writes'][0]['payload_hash'] === hash( 'sha256', $css ),
	$result['writes'][1] === $resolved['writes'][1],
);
if ( in_array( false, $checks, true ) ) {
	fwrite( STDERR, "Admin-bar top chrome regression\n" . $css );
	exit( 1 );
}
echo "Admin-bar top chrome passed.\n";
