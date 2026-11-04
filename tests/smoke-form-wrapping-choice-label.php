<?php
/**
 * A standalone checkbox whose source label wraps it (a custom-drawn consent
 * box) is one choice box, wherever its field sits: inside a fieldset too. The
 * label's box facts present the provider's choice wrap, never the checkbox
 * itself, so the caption keeps its row beside the box; and the label's own
 * required marker stays the field's indicator, in the label's type, instead
 * of the provider's "(required)".
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-wrapping-choice-label.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row         = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$markup      = (string) $row['block_markup'];
$overlay     = $row['provider_layout_overlay_css'];
$css         = (string) ( $overlay['css'] ?? '' );
$editor_css  = (string) ( $overlay['editor_css'] ?? '' );

$find = static function ( array $blocks, string $name ) use ( &$find ): ?array {
	foreach ( $blocks as $block ) {
		if ( $name === ( $block['blockName'] ?? null ) ) {
			return $block;
		}
		$found = $find( $block['innerBlocks'] ?? array(), $name );
		if ( null !== $found ) {
			return $found;
		}
	}
	return null;
};
$parsed   = parse_blocks( $markup );
$checkbox = $find( $parsed, 'jetpack/field-checkbox' ) ?? array();
$option   = $find( $checkbox['innerBlocks'] ?? array(), 'jetpack/option' ) ?? array();
preg_match( '/(?:^|\s)ssi-source-choice-label--(ssi-node-[a-f0-9]{12})(?:\s|$)/', (string) ( $option['attrs']['className'] ?? '' ), $choice );
$label_class = $choice[1] ?? '';

// Jetpack's render of the field, through the provider runtime filters.
$GLOBALS['ssi_role_box_field_index'] = 0;
$front = ssi_role_box_render_block( ssi_role_box_parse_blocks( serialize_block( $checkbox ) )[0] );
preg_match( '/<input\b[^>]*type=["\']checkbox["\'][^>]*>/', $front, $input );
preg_match( '/<div class=["\'][^"\']*contact-form__checkbox-wrap[^"\']*["\']/', $front, $wrap );
preg_match( '/<label\b[^>]*class=["\']grunion-field-label checkbox[^>]*>(.*?)<\/label>/s', $front, $label );

$rule = static fn( string $sheet, string $selector ): string => implode( ';', preg_match_all( '/(?:(?<=\})|^)[^{}]*' . preg_quote( $selector, '/' ) . '(?=[,{])[^{}]*\{([^}]*)\}/', $sheet, $found ) ? $found[1] : array() );

$checks = array(
	'form maps'                                => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'wrapping label is the choice box'         => '' !== $label_class,
	'option states the source marker'          => '*' === ( $option['attrs']['requiredText'] ?? null ),
	'caption field keeps its source marker'    => 2 === substr_count( $markup, '"requiredText":"*"' ),
	'choice wrap carries the label box'        => '' !== $label_class && isset( $wrap[0] ) && str_contains( $wrap[0], $label_class ),
	'checkbox does not take the label box'     => '' !== $label_class && isset( $input[0] ) && ! str_contains( $input[0], $label_class ),
	'label box presents the choice wrap'       => str_contains( $rule( $css, '.' . $label_class . '{' ) . $rule( $css, '.' . $label_class ), 'display:flex' ),
	'provider renders the source marker'       => isset( $label[1] ) && str_contains( $label[1], '<span class="grunion-label-required" aria-hidden="true">*</span>' ) && ! str_contains( $front, '(required)' ),
	'marker inherits the label type'           => str_contains( $rule( $css, '.' . $label_class . ' > label > .grunion-label-required' ), 'font-weight:inherit!important' ),
	'editor marker inherits the label type'    => str_contains( $rule( $editor_css, '.ssi-source-choice-label--' . $label_class . ' > .jetpack-field-option__label-wrapper > .required' ), 'font-weight:inherit!important' ),
	'overlay is admitted'                      => null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay ),
);
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
	}
}
echo in_array( false, $checks, true ) ? '' : "smoke-form-wrapping-choice-label: passed\n";
exit( in_array( false, $checks, true ) ? 1 : 0 );
