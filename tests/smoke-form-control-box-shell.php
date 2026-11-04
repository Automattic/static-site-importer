<?php
/** A control-box shell renders as a real box around the provider control. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The producer splits the field box at the caption level and marks the
// control's own box (Automattic/blocks-engine#4145); the smoke reads that
// real finding. Unmarked, the same finding is what a consumer sees from a
// producer that states no control box.
$unmark = static function ( array $blocks ) use ( &$unmark ): array {
	foreach ( $blocks as &$block ) {
		unset( $block['form_control_box'] );
		$block['innerBlocks'] = $unmark( $block['innerBlocks'] ?? array() );
	}
	return $blocks;
};
$seed = static function ( bool $marked ) use ( $unmark ): array {
	$result = ( new ArtifactCompiler() )->compile( array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-control-box-shell.html' ) ),
	) )->toArray();
	$plan        = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	if ( ! $marked ) {
		$validated['forms'][0]['readable_blocks'] = $unmark( $validated['forms'][0]['readable_blocks'] );
	}
	return Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
};

// The declarations of the rules whose selector names one class (with an optional suffix).
$rule = static fn( string $css, string $selector ): string => implode( ';', preg_match_all( '/(?:(?<=\})|^)[^{}]*\.' . preg_quote( $selector, '/' ) . '(?=[,{])[^{}]*\{([^}]*)\}/', $css, $found ) ? $found[1] : array() );
$token_of = static function ( array $block, string $pattern ): string {
	foreach ( preg_split( '/\s+/', trim( (string) ( $block['attrs']['className'] ?? '' ) ) ) ?: array() as $token ) {
		if ( 1 === preg_match( $pattern, (string) $token ) ) {
			return (string) $token;
		}
	}
	return '';
};

$row         = $seed( true );
$tree        = parse_blocks( $row['block_markup'] )[0]['innerBlocks'][0];
$field_box   = $tree['innerBlocks'][0]['innerBlocks'][0] ?? array();
$field       = $field_box['innerBlocks'][0] ?? array();
$overlay     = $row['provider_layout_overlay_css'];
$checks      = array(
	'marked tree maps'                 => 'mapped' === $row['status'],
	'field box keeps the label box'    => ( $field_box['attrs']['wrappers'][0]['attributes']['class'] ?? '' ) === 'framer-s3i8tk'
		&& str_contains( (string) $row['block_markup'], '<div class="framer-s3i8tk">' ),
	'provider field takes the shell place' => str_starts_with( (string) ( $field['blockName'] ?? '' ), 'jetpack/field-' ),
	'no control box shell wraps the field' => ! str_contains( (string) $row['block_markup'], '<div class="framer-form-input-wrapper framer-io8vii">' )
		&& 1 === count( $field_box['innerBlocks'] ),
);
$input        = $field['innerBlocks'][1] ?? array();
$classes      = preg_split( '/\s+/', trim( (string) ( $input['attrs']['className'] ?? '' ) ) ) ?: array();
$control_hook = $token_of( $input, '/^ssi-node-[a-f0-9]{12}$/D' );
$box_hook     = $token_of( $input, '/^ssi-node-[a-f0-9]{12}-destination-box$/D' );
$markers      = preg_grep( '/^ssi-source-box--/', $classes );
$checks['control carries the frame classes as markers'] = array( 'ssi-source-box--framer-form-input-wrapper', 'ssi-source-box--framer-io8vii' ) === array_values( $markers )
	&& '' !== $box_hook && '' !== $control_hook && ! in_array( 'framer-io8vii', $classes, true );
$declarations = $rule( (string) ( $overlay['css'] ?? '' ), $control_hook );
$checks['control keeps its own paint and sizing'] = str_contains( $declarations, 'background:#fff' ) && str_contains( $declarations, 'border-radius:8px' ) && str_contains( $declarations, 'height:100%' );
$box_declarations = $rule( (string) ( $overlay['css'] ?? '' ), $box_hook );
$checks['box takes the frame size']            = str_contains( $box_declarations, 'height:40px' ) && str_contains( $box_declarations, 'flex:0 0 auto' );
$checks['frame width beats the stretch reset'] = str_contains( $box_declarations, 'width:100%' ) && ! str_contains( $box_declarations, 'width:auto' );
$checks['editor control takes the frame size'] = str_contains( $rule( (string) ( $overlay['editor_css'] ?? '' ), $control_hook ), 'height:40px' );
$field_hook  = $token_of( $field, '/^ssi-node-[a-f0-9]{12}$/D' );
$wrap        = $rule( (string) ( $overlay['css'] ?? '' ), $field_hook . '-wrap' );
$checks['caption keeps the field box arrangement'] = str_contains( $wrap, 'flex-direction:column' ) && str_contains( $wrap, 'gap:10px' );
$checks['overlay is admitted']                     = null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay );
$checks['no gate losses']                          = 'mapped' === $row['status'] && empty( $row['mapping_decision']['losses'] );

// The provider's own render moves the markers onto a real box around the
// control: the frame's classes name it, so its ::after underline paints and
// its padding applies, while the caption stays outside the box.
$rendered  = ssi_role_box_render_form( ssi_role_box_parse_blocks( $row['block_markup'] )[0] ?? array() );
$box_open  = '<div class="' . $box_hook . ' framer-form-input-wrapper framer-io8vii">';
$at        = strpos( $rendered, $box_open );
$checks['rendered frame wraps the control'] = '' !== $box_hook && false !== $at;
if ( false !== $at ) {
	$box_html   = (string) substr( $rendered, $at, (int) strpos( $rendered, '</div>', $at ) - $at );
	$at_input   = strpos( $box_html, '<input' );
	$input_html = false === $at_input ? '' : (string) substr( $box_html, $at_input );
	$checks['rendered control sits inside the box'] = false !== $at_input && str_contains( $input_html, $control_hook )
		&& ! str_contains( $input_html, 'framer-io8vii' ) && ! str_contains( $input_html, 'ssi-source-box--' );
	$checks['rendered caption sits outside the box'] = ! str_contains( $box_html, '<label' )
		&& false !== strpos( (string) substr( $rendered, 0, $at ), 'grunion-field-label' );
}
$checks['rendered shell keeps no marker copies'] = '' !== $box_hook && ! str_contains( $rendered, 'ssi-source-box--' ) && ! str_contains( $rendered, $box_hook . '-wrap' );

// A finding without the marker keeps the generic walk: the provider field
// sits inside the control's own shell, which carries the frame.
$today        = $seed( false );
$today_tree   = parse_blocks( $today['block_markup'] )[0]['innerBlocks'][0];
$today_shell  = $today_tree['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0] ?? array();
$today_field  = $today_shell['innerBlocks'][0] ?? array();
$today_input  = $today_field['innerBlocks'][1] ?? array();
$today_hook   = $token_of( $today_input, '/^ssi-node-[a-f0-9]{12}$/D' );
$today_rule   = $rule( (string) ( $today['provider_layout_overlay_css']['css'] ?? '' ), $today_hook );
$checks['unmarked tree maps']                       = 'mapped' === $today['status'];
$checks['unmarked field sits inside the shell']     = 'framer-form-input-wrapper framer-io8vii' === ( $today_shell['attrs']['wrappers'][0]['attributes']['class'] ?? '' )
	&& str_contains( (string) $today['block_markup'], '<div class="framer-form-input-wrapper framer-io8vii">' );
$checks['unmarked control carries no frame classes'] = ! in_array( 'framer-io8vii', preg_split( '/\s+/', trim( (string) ( $today_input['attrs']['className'] ?? '' ) ) ) ?: array(), true );
$checks['unmarked control keeps its own facts']      = str_contains( $today_rule, 'height:100%' );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
