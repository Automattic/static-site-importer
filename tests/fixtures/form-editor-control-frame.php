<?php
/**
 * Compile a framed email/phone form and model Jetpack's editor block DOM.
 * Field className belongs to the wrapper; input className belongs to the control.
 */
// phpcs:ignoreFile -- Standalone CLI browser fixture.
require_once __DIR__ . '/core-html-api.php';
$wp_root = require __DIR__ . '/wordpress-core-root.php';
require_once __DIR__ . '/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/form-seeder-cli-stubs.php';
require_once __DIR__ . '/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>
.frame{border:1px solid rgb(234,234,158);border-radius:40px;background-color:#515b2a}
input{border:0;background:transparent;padding:12px}
</style></head><body><form action="/contact" method="post">
<label for="email">Email</label><div class="frame"><input id="email" name="email" type="email"></div>
<label for="phone">Phone</label><div class="frame"><input id="phone" name="phone" type="tel"></div>
<button type="submit">Send</button></form></body></html>'; 
$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $html ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
if ( 'mapped' !== $row['status'] ) {
	throw new RuntimeException( 'Fixture form must map to Jetpack.' );
}
$render = static function ( array $block ) use ( &$render ): string {
	$name = $block['name'];
	$class = ssi_role_box_esc( $block['attrs']['className'] ?? '' );
	$children = implode( '', array_map( $render, $block['innerBlocks'] ?? array() ) );
	if ( 'jetpack/input' === $name ) {
		return '<input class="' . $class . '" />';
	}
	if ( 'jetpack/phone-input' === $name ) {
		// Jetpack's composite uses separate shell and primary input destinations.
		preg_match( '/ssi-node-[a-f0-9]+-destination-shell/', $class, $shell );
		preg_match( '/ssi-node-[a-f0-9]+-destination-primary/', $class, $primary );
		return '<div data-control="phone" class="' . ( $shell[0] ?? '' ) . '"><button>+1</button><input type="tel" class="' . ( $primary[0] ?? '' ) . '" /></div>';
	}
	if ( 'jetpack/label' === $name ) {
		return '<label class="' . $class . '">Field label</label>';
	}
	return '<div data-block="' . ssi_role_box_esc( $name ) . '" class="' . $class . '">' . $children . '</div>';
};
echo json_encode( array(
	'css' => $row['provider_layout_overlay_css']['editor_css'],
	'html' => implode( '', array_map( $render, ssi_role_box_parse_blocks( $row['block_markup'] ) ) ),
), JSON_THROW_ON_ERROR );
