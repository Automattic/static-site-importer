<?php
/**
 * Neutral two-input form with visually hidden sub-labels.
 *
 * @package StaticSiteImporter
 */

// Load the standalone provider and WordPress test runtime.
require_once __DIR__ . '/form-seeder-cli-stubs.php';
$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' );
$transformer_root = false === $transformer_root || '' === $transformer_root ? dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer' : $transformer_root;
require_once $transformer_root . '/php-transformer.php';

$css      = '.name-form .field-help.clip-copy{position:absolute;clip:rect(0 0 0 0);width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;border:0}.name-form input{display:block;height:32px;box-sizing:border-box}.name-form label{display:block}';
$html     = '<form class="name-form"><div><label for="first">Given name</label><input id="first" name="first" placeholder="First name"><label for="first" class="field-help clip-copy">First</label></div><div><label for="last">Family name</label><input id="last" name="last" placeholder="Last name"><label for="last" class="field-help clip-copy">Last</label></div><button type="submit">Send</button></form>';
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'contact.html',
		'files'      => array( 'contact.html' => '<style>' . $css . '</style>' . $html ),
	)
);
$view     = $compiled->toWordPressSitePlanView();
$plan     = $view['wordpress_site_plan'];
$entities = array();
foreach ( $plan['runtime_declarations'] as $declaration ) {
	if ( 'forms' === ( $declaration['type'] ?? '' ) ) {
		$entities = $declaration['payload']['entities'];
	}
}
// Production declarations omit the fallback HTML; recover it by source identity.
$entities  = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $entities, $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$markup    = $row['block_markup'] ?? '';
preg_match_all( '/<!-- wp:jetpack\/field-text (.*?) -->/', $markup, $matches );
preg_match_all( '/<!-- wp:jetpack\/input (.*?) -->/', $markup, $inputs );
$fields = array();
foreach ( $matches[1] as $index => $json ) {
	$attrs       = json_decode( $json, true );
	$input_attrs = json_decode( $inputs[1][ $index ] ?? '{}', true );
	$classes     = implode( ' ', array_map( static fn ( string $class_name ): string => $class_name . '-wrap', explode( ' ', $attrs['className'] ?? '' ) ) );
	$fields[]    = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap ' . htmlspecialchars( $classes, ENT_QUOTES, 'UTF-8' ) . '"><label>' . ( 0 === $index ? 'Given name' : 'Family name' ) . '</label><input aria-describedby="help-' . $index . '" placeholder="' . htmlspecialchars( $input_attrs['placeholder'] ?? '', ENT_QUOTES, 'UTF-8' ) . '"><span id="help-' . $index . '" class="contact-form__field-help">' . htmlspecialchars( $attrs['helpText'] ?? '', ENT_QUOTES, 'UTF-8' ) . '</span></div>' );
}
echo wp_json_encode(
	array(
		'status' => $row['status'],
		'source' => $html,
		'css'    => $css,
		'fields' => $fields,
	)
);
