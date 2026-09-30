<?php
/**
 * Consumer contract for Blocks Engine generic/computed-layout-graph/v3 form
 * source boxes (Automattic/static-site-importer#1904, blocks-engine#2356).
 *
 * The fixture is the producer's real ArtifactCompiler runtime form entity for
 * neutral source HTML (regenerate with form-source-boxes-v3-generate.php):
 * - responsive context typography whose class follows sixteen other tokens,
 * - typography inherited from an ancestor through a custom property,
 * - a submit wrapper with its own min-height and padding-bottom,
 * - a copy-only disclaimer wrapper with padding and a responsive patch,
 * - a second entity whose class list exhausts the producer's source bound.
 *
 * Run from the repository root: php tests/smoke-form-source-boxes-v3.php
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$fixture  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/form-source-boxes-v3.json' ), true );
	$complete = $fixture['complete'];
	$validate = static fn( array $entity ): array => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $entity ) ) );
	$seed     = static fn( array $validated ): array => Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();

	// 1. The complete v3 graph validates without dropping its new facts.
	$validated = $validate( $complete );
	$assert( array() === ( $validated['errors'] ?? array() ) && 1 === count( $validated['forms'] ?? array() ), 'v3-entity-validates', wp_json_encode( $validated['errors'] ?? array() ) );
	$graph = $validated['forms'][0]['layout_graph'] ?? array();
	$by_id = array_column( $graph['nodes'] ?? array(), null, 'id' );
	$assert( 'generic/computed-layout-graph/v3' === ( $graph['schema'] ?? null ), 'v3-schema-retained' );
	$assert( '73px' === ( $by_id['wrapper-0']['layout']['min_height'] ?? null ) && '19px' === ( $by_id['wrapper-0']['layout']['padding_bottom'] ?? null ), 'v3-wrapper-box-retained', wp_json_encode( $by_id['wrapper-0'] ?? null ) );
	$assert( isset( $by_id['context-1']['presentation']['variants'][0]['styles']['font_size'] ) && is_string( $by_id['context-1']['source']['selector'] ?? null ), 'v3-context-identity-and-presentation-retained' );

	// 2. Malformed v3 facts are rejected with named errors, per form row.
	$mutate = static function ( callable $change ) use ( $complete ): array {
		$entity = $complete;
		$change( $entity );
		return $entity;
	};
	$negatives = array(
		'unknown-node-id'           => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][1]['id'] = 'copy-0'; } ),
		'context-kind-control'      => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][1]['kind'] = 'control'; } ),
		'oversized-classes'         => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][2]['source']['classes'] = array_map( static fn( int $i ): string => 'c' . $i, range( 1, 65 ) ); } ),
		'missing-selector'          => $mutate( static function ( array &$entity ): void { unset( $entity['layout_graph']['nodes'][2]['source']['selector'] ); } ),
		'presentation-on-control'   => $mutate( static function ( array &$entity ): void { foreach ( $entity['layout_graph']['nodes'] as &$node ) { if ( 'control-1' === $node['id'] ) { $node['presentation'] = $entity['layout_graph']['nodes'][2]['presentation']; } } } ),
		'presentation-unknown-key'  => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][2]['presentation']['extra'] = true; } ),
		'presentation-truncated'    => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][2]['presentation']['truncated'] = true; } ),
		'unsupported-style'         => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['nodes'][2]['presentation']['styles']['behavior'] = 'x'; } ),
		'box-key-in-v2'             => $mutate( static function ( array &$entity ): void { $entity['layout_graph']['schema'] = 'generic/computed-layout-graph/v2'; } ),
		'malformed-contract-losses' => $mutate( static function ( array &$entity ): void { $entity['source_contract_losses'] = array( '' ); } ),
	);
	foreach ( $negatives as $label => $entity ) {
		$result = $validate( $entity );
		$assert( array() === ( $result['forms'] ?? array() ) && ! empty( $result['errors'][0]['message'] ?? '' ), 'v3-negative-' . $label, wp_json_encode( $result['errors'] ?? array() ) );
	}

	// 3. Wrapper boxes land on the provider wrapper; the control keeps its own box.
	$row = $seed( $validated );
	$css = (string) ( $row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( 'mapped' === ( $row['status'] ?? null ), 'v3-form-maps', wp_json_encode( $row['mapping_decision'] ?? $row ) );
	$wrapper_hook = Static_Site_Importer_Form_Layout_Projection::layout_node_class( Static_Site_Importer_Form_Layout_Projection::layout_scope( $validated['forms'][0] ?? $complete ), 'wrapper-0' );
	$assert( 1 === preg_match( '/\.' . preg_quote( $wrapper_hook, '/' ) . '\{[^}]*min-height:73px[^}]*\}/', $css ) && 1 === preg_match( '/\.' . preg_quote( $wrapper_hook, '/' ) . '\{[^}]*padding-bottom:19px[^}]*\}/', $css ), 'submit-wrapper-box-on-provider-wrapper', $css );
	$assert( 1 === preg_match( '/> \.wp-block-button__link\{[^}]*min-height:41px/', $css ) && 0 === preg_match( '/\.' . preg_quote( $wrapper_hook, '/' ) . '\{[^}]*min-height:41px/', $css ), 'button-min-height-stays-on-native-control', $css );
	$markup = (string) ( $row['block_markup'] ?? '' );
	$assert( str_contains( $markup, '"name":"email"' ) || str_contains( $markup, 'email' ), 'field-semantics-preserved' );
	$assert( 1 === substr_count( $markup, 'type="submit"' ), 'single-native-submit-preserved', $markup );

	// 4. Context copy joins its source element by identity and recreates its box.
	$context_css = (string) ( $row['provider_layout_overlay_css']['context_css'] ?? '' );
	$intro_class = preg_match( '/<p class="[^"]*\bwide-copy (ssi-context-[a-f0-9]{12})"/', $markup, $intro_match ) ? $intro_match[1] : '';
	$assert( 1 === preg_match( '/^ssi-context-[a-f0-9]{12}$/D', $intro_class ), 'intro-identity-by-source-selector', $intro_class );
	$assert( str_contains( $markup, 'hook-20 wide-copy' ), 'late-class-hook-retained-on-block', $markup );
	$assert( 1 === preg_match( '/\.' . $intro_class . '\{[^}]*font-size:21px/', $context_css ) && 1 === preg_match( '/\.' . $intro_class . '\{[^}]*font-family:Georgia/', $context_css ) && 1 === preg_match( '/\.' . $intro_class . '\{[^}]*color:#123456/', $context_css ), 'inherited-and-custom-property-typography', $context_css );
	$assert( 1 === preg_match( '/@media \(min-width:1200px\)\{[^{]*\.' . $intro_class . '\{font-size:27px;letter-spacing:2px\}\}/', $context_css ), 'property-only-responsive-context-patch', $context_css );
	$assert( 1 === preg_match( '/<!-- wp:core\/group \{"className":"note-box (ssi-context-[a-f0-9]{12})"\} -->\s*<div class="wp-block-group note-box \1">\s*<!-- wp:core\/paragraph/', $markup, $note_group ), 'disclaimer-wrapper-recreated-around-copy', $markup );
	$note_class = $note_group[1] ?? 'missing';
	$assert( 1 === preg_match( '/\.' . $note_class . '\{padding:7px 0 13px\}/', $context_css ) && 1 === preg_match( '/@media \(min-width:1200px\)\{[^{]*\.' . $note_class . '\{padding-bottom:17px\}\}/', $context_css ), 'disclaimer-wrapper-box-and-patch', $context_css );
	$assert( ! preg_match( '/\.' . $note_class . '\{[^}]*font-family/', $context_css ), 'wrapper-carries-box-not-inherited-typography', $context_css );
	$assert( Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $row['provider_layout_overlay_css'] ?? null ) === $row['provider_layout_overlay_css'], 'overlay-artifact-validates' );

	// 4b. The import runtime prepares entities through the fallback contract first;
	// that normalization must keep the identity join and late class hooks.
	$prepared     = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $complete );
	$prepared_row = $seed( $validate( $prepared ) );
	$prepared_css = (string) ( $prepared_row['provider_layout_overlay_css']['context_css'] ?? '' );
	$assert( ( $prepared['form']['context_before'][0]['source_selector'] ?? null ) === ( $complete['form']['context_before'][0]['source_selector'] ?? '' ) && str_contains( (string) ( $prepared['form']['context_before'][0]['class'] ?? '' ), 'hook-20 wide-copy' ), 'runtime-preparation-keeps-identity-and-late-class', wp_json_encode( $prepared['form']['context_before'] ?? null ) );
	$assert( 1 === preg_match( '/@media \(min-width:1200px\)\{[^{]*\.ssi-context-[a-f0-9]{12}\{font-size:27px;letter-spacing:2px\}\}/', $prepared_css ) && ! in_array( 'provider_context_identity_unmatched', array_column( $prepared_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'runtime-preparation-materializes-source-presentation', $prepared_css );

	// 5. An exhausted producer contract is a named loss, never a faithful graph.
	$exhausted = $validate( $fixture['exhausted'] );
	$assert( array() === ( $exhausted['errors'] ?? array() ) && ! isset( $exhausted['forms'][0]['layout_graph'] ), 'exhausted-entity-validates-without-graph', wp_json_encode( $exhausted['errors'] ?? array() ) );
	$exhausted_row = $seed( $exhausted );
	$losses        = array_column( $exhausted_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( in_array( 'producer_source_contract_exhausted', $losses, true ), 'exhausted-contract-reported-as-loss', wp_json_encode( $losses ) );
	$assert( 'mapped' === ( $exhausted_row['status'] ?? null ), 'exhausted-contract-still-maps-functional-form', wp_json_encode( $exhausted_row['mapping_decision'] ?? $exhausted_row ) );

	// 6. Determinism.
	$assert( $seed( $validate( $complete ) ) === $row, 'seeding-is-deterministic' );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		fwrite( STDERR, count( $failures ) . " of {$assertions} assertions failed\n" );
		exit( 1 );
	}
	echo "Form source boxes v3 contract passed: {$assertions} assertions\n";
}
