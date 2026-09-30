<?php
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once ABSPATH . 'includes/class-static-site-importer-provider-layout-overlay.php';
$rules  = array(
	'.form .first{color:red;padding:4px}',
	'.form .last{color:red;padding:4px}',
	'.form .first{color:blue}',
	'@media (min-width:1080px){.form .first{font-size:18px}}',
	'@media (max-width:1079px){.form .first{font-size:18px}}',
	'@media (min-width:1080px){.form .last{font-size:20px}}',
	'@media (min-width:1080px){.form .message{font-size:20px}}',
	'@media (max-width:500px){.form .last{font-size:14px}}',
	'@media (min-width:1080px){@container (min-width:500px){.form .first{padding:8px}}}',
	'@media (min-width:1080px){@container (min-width:500px){.form .last{padding:8px}}}',
	'.editor-styles-wrapper .form .first{border:1px solid red}',
	'.editor-styles-wrapper .form .last{border:1px solid red}',
);
$method = new ReflectionMethod( Static_Site_Importer_Provider_Layout_Overlay::class, 'compact_rules' );
$result = array(
	'before' => implode( "\n", $rules ),
	'after'  => implode( "\n", $method->invoke( null, $rules ) ),
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone browser fixture has no WordPress runtime.
echo json_encode( $result );
