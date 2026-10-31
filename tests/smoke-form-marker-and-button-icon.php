<?php
/**
 * Regression for #2422: a visible required marker on a `novalidate` form, with
 * no `required` validation, survives into the Jetpack field label, and a plain
 * (non-submit) button keeps its captured inline icon.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression, using filesystem and output without WordPress.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$form   = '<form novalidate action="/contact" method="post">'
	. '<div><label for="naam">Naam <span class="text-accent" aria-hidden="true">*</span></label><input id="naam" name="naam" type="text"></div>'
	. '<div><label for="note">Note</label><input id="note" name="note" type="text"></div>'
	. '<button type="button" class="attach"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>Add file</button>'
	. '<button type="submit">Send</button></form>';
$html   = '<!doctype html><html><head><style>.text-accent{color:#c00}</style></head><body><main>' . $form . '</main></body></html>';
$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$markup      = $row['block_markup'];
$overlay     = $row['provider_layout_overlay_css']['css'] ?? '';

$checks = array(
	'fixture maps'                     => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'marked label keeps its marker'    => str_contains( $markup, '"label":"Naam \u003cspan class=\u0022grunion-label-required\u0022 aria-hidden=\u0022true\u0022\u003e*\u003c/span\u003e"' ) || str_contains( $markup, '"label":"Naam <span class=\"grunion-label-required\" aria-hidden=\"true\">*<\/span>"' ),
	'unmarked label stays plain'       => str_contains( $markup, '"label":"Note"' ),
	'field is not made required'       => ! str_contains( $markup, '"required":true' ),
	'marker keeps its source color'    => (bool) preg_match( '/> \.grunion-label-required\{[^}]*color:#c00/', $overlay ),
	'plain button keeps its icon'      => (bool) preg_match( '#<button type="button" class="wp-block-button__link wp-element-button"><svg[^>]*><path d="M12 5v14M5 12h14"></path></svg>Add file</button>#', $markup ),
);
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
	}
}
echo in_array( false, $checks, true ) ? '' : "smoke-form-marker-and-button-icon: passed\n";
exit( in_array( false, $checks, true ) ? 1 : 0 );
