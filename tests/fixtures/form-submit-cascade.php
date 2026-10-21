<?php
/**
 * Neutral full-width submit with a losing preflight background reset (#2223).
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once __DIR__ . '/form-seeder-cli-stubs.php';
require_once dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

$css       = '.submit-box{display:block}button{background-color:transparent}.ctx .submit{background:#171717;color:#fff;width:100%;border-radius:100px}';
$html      = '<section class="ctx"><form><input type="email" name="email"><div class="submit-box"><button class="submit" type="submit">Send</button></div></form></section>';
$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'contact.html',
		'files'      => array( 'contact.html' => '<style>' . $css . '</style>' . $html ),
	)
)->toArray();
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ) ) );
$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $row['block_markup'], $form );
preg_match( '/<div class="wp-block-button[^>]*>.*?<\/div>/s', $row['block_markup'], $submit );
preg_match( '/<!-- wp:(?:core\/)?button (\{.*?\}) -->/', $row['block_markup'], $button );
echo wp_json_encode(
	array(
		'css'        => $row['provider_layout_overlay_css']['css'],
		'className'  => json_decode( $form[1], true )['className'],
		'submit'     => Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $submit[0], array( 'attrs' => json_decode( $button[1], true ) ) ),
		'sourceCss'  => $css,
		'sourceHtml' => $html,
	)
);
