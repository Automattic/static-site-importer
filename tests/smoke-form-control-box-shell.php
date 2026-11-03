<?php
/** A control-box shell presents its source frame on the provider control. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$seed = static function ( bool $marked ): array {
	$result = ( new ArtifactCompiler() )->compile( array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-control-box-shell.html' ) ),
	) )->toArray();
	$plan        = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	if ( $marked ) {
		// The producer's control-box marker (Automattic/blocks-engine#4145),
		// stated directly: the captured field box splits, and inside it the
		// exclusive wrapper chain encloses the control marked with its index.
		$shell =& $validated['forms'][0]['readable_blocks'][0]['innerBlocks'][0]['innerBlocks'][0];
		$split = static function ( array $wrappers, array $inner, string $marker ): array {
			$opening = implode( '', array_column( $wrappers, 'opening' ) );
			$closing = implode( '', array_reverse( array_column( $wrappers, 'closing' ) ) );
			$block   = array(
				'blockName'    => $wrappers[0]['shell_block'],
				'attrs'        => array( 'wrappers' => array_map( static fn( array $wrapper ): array => array( 'tagName' => $wrapper['tagName'], 'attributes' => $wrapper['attributes'] ), $wrappers ) ),
				'innerBlocks'  => $inner,
				'innerHTML'    => $opening . $closing,
				'innerContent' => array_merge( array( $opening ), array_fill( 0, count( $inner ), null ), array( $closing ) ),
			);
			if ( '' !== $marker ) {
				$block[ $marker ] = 0;
			}
			return $block;
		};
		$chain = array_map(
			static fn( array $wrapper ): array => $wrapper + array( 'shell_block' => (string) $shell['blockName'] ),
			is_array( $shell['_layout_shell_wrappers'] ?? null ) ? $shell['_layout_shell_wrappers'] : array()
		);
		$shell = $split(
			array( $chain[0] ),
			array( $split( array( $chain[1] ), $shell['innerBlocks'], 'form_control_box' ) ),
			'_form_field_box'
		);
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
$checks['control carries the frame classes'] = in_array( 'framer-io8vii', $classes, true ) && in_array( 'framer-form-input-wrapper', $classes, true ) && '' !== $control_hook;
$declarations = $rule( (string) ( $overlay['css'] ?? '' ), $control_hook );
$checks['control takes the frame size']       = str_contains( $declarations, 'height:40px' ) && ! str_contains( $declarations, 'height:100%' );
$checks['control keeps its own paint']        = str_contains( $declarations, 'background:#fff' ) && str_contains( $declarations, 'border-radius:8px' );
$checks['frame width beats the stretch reset'] = str_contains( $declarations, 'width:100%' ) && ! str_contains( $declarations, 'width:auto' );
$checks['editor control takes the frame size'] = str_contains( $rule( (string) ( $overlay['editor_css'] ?? '' ), $control_hook ), 'height:40px' );
$field_hook  = $token_of( $field, '/^ssi-node-[a-f0-9]{12}$/D' );
$wrap        = $rule( (string) ( $overlay['css'] ?? '' ), $field_hook . '-wrap' );
$checks['caption keeps the field box arrangement'] = str_contains( $wrap, 'flex-direction:column' ) && str_contains( $wrap, 'gap:10px' );
$checks['overlay is admitted']                     = null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay );
$checks['no gate losses']                          = 'mapped' === $row['status'] && empty( $row['mapping_decision']['losses'] );

// A finding without the marker renders as it does today: the captured field
// box is one shell, and the provider field sits inside it.
$today        = $seed( false );
$today_tree   = parse_blocks( $today['block_markup'] )[0]['innerBlocks'][0];
$today_shell  = $today_tree['innerBlocks'][0]['innerBlocks'][0] ?? array();
$today_field  = $today_shell['innerBlocks'][0] ?? array();
$today_input  = $today_field['innerBlocks'][1] ?? array();
$today_hook   = $token_of( $today_input, '/^ssi-node-[a-f0-9]{12}$/D' );
$today_rule   = $rule( (string) ( $today['provider_layout_overlay_css']['css'] ?? '' ), $today_hook );
$checks['unmarked tree maps']                       = 'mapped' === $today['status'];
$checks['unmarked field sits inside the shell']     = 2 === count( $today_shell['attrs']['wrappers'] ?? array() )
	&& str_contains( (string) $today['block_markup'], '<div class="framer-form-input-wrapper framer-io8vii">' );
$checks['unmarked control carries no frame classes'] = ! in_array( 'framer-io8vii', preg_split( '/\s+/', trim( (string) ( $today_input['attrs']['className'] ?? '' ) ) ) ?: array(), true );
$checks['unmarked control keeps its own facts']      = str_contains( $today_rule, 'height:100%' );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
