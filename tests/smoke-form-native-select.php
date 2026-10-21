<?php
/** Regression for #2294: source-native selects do not gain provider chevrons. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once dirname( __DIR__ ) . '/vendor/wordpress-core/wp-includes/kses.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/native-select-form.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $declaration['payload']['entities'] ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$overlay     = $row['provider_layout_overlay_css']['css'];
$css         = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
// Locate the select input's seeded presentation hook, not another field's hook.
preg_match( '/<!-- wp:jetpack\/field-select .*?<!-- wp:jetpack\/input (.*?) -->/s', $row['block_markup'], $match );
$attrs       = json_decode( $match[1] ?? '{}', true );
$class       = $attrs['className'] ?? '';
// Jetpack front-end DOM: the seeded input class lives on the select wrapper.
$markup      = '<form class="contact-form"><div class="contact-form__select-wrapper ' . htmlspecialchars( $class, ENT_QUOTES ) . '"><select><option>General enquiry</option><option>Support</option></select><span class="jetpack-field-dropdown__icon"></span></div></form>';
$unprojected = $markup;
$markup      = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $markup );
$plain       = str_replace( $class, 'ordinary-select', $unprojected );
$adjacent    = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $unprojected . $plain );
$checks      = array(
	'fixture maps' => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'select hook materialized' => str_contains( $class, 'ssi-node-' ),
	'native arrow restored' => str_contains( $overlay, 'appearance:auto!important' ),
	'provider decoration suppressed' => str_contains( $markup, 'style="display:none!important"' ),
	'ordinary provider field unchanged' => $plain === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $plain ),
	'projection is idempotent' => $markup === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $markup ),
	'adjacent ordinary control stays untouched' => str_ends_with( $adjacent, $plain ) && 1 === substr_count( $adjacent, 'display:none!important' ),
	'icon without a select wrapper stays untouched' => '<span class="jetpack-field-dropdown__icon"></span>' === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<span class="jetpack-field-dropdown__icon"></span>' ),
);
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'overlay', 'markup', 'plain' ) );
	exit;
}
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
	}
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
