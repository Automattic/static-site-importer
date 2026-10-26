<?php
/**
 * The textarea row sizing policy resolves every textarea fact once.
 *
 * `Static_Site_Importer_Form_Textarea_Rows` resolves, per textarea control,
 * the row count, the outer display, the provider height release, the editor
 * box height and the editor min-height release. This smoke covers each
 * decision and checks every surface realises the resolved facts:
 *
 * - grid, flex and block-flow source parents, and a captured display;
 * - a captured height, a captured min-height, and `calc(…)` or unresolvable
 *   block edges;
 * - the front-end marker round-trips through the provider runtime's `rows`
 *   rewrite;
 * - the editor surface realises the resolved resets only on the parts that
 *   can address a textarea.
 *
 * Run from the repository root:
 *   php tests/smoke-form-textarea-rows.php
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

/**
 * Write text to a standard stream.
 *
 * @param string $stream `php://stdout` or `php://stderr`.
 * @param string $text   Text to write.
 */
function ssi_textarea_rows_write( string $stream, string $text ): void {
	$handle = new SplFileObject( $stream, 'w' );
	$handle->fwrite( $text );
}

/**
 * A one-textarea form whose control sits in a parent with the given display.
 *
 * @param string|null              $parent_display Source parent display, or null for no parent node.
 * @param array<string,mixed>|null $styles         Captured control styles, or null for no presentation row.
 * @param array<string,mixed>      $control        Extra control facts.
 * @return array<int,Static_Site_Importer_Form_Textarea_Rows>
 */
function ssi_textarea_rows_resolve( ?string $parent_display, ?array $styles, array $control = array() ): array {
	$nodes = array( array( 'id' => 'form' ) );
	if ( null !== $parent_display ) {
		$nodes[] = array(
			'id'     => 'row',
			'layout' => array( 'display' => $parent_display ),
		);
	}
	$nodes[]  = array(
		'id'     => 'control-1',
		'parent' => null === $parent_display ? 'form' : 'row',
	);
	$form     = array(
		'layout_graph'       => array( 'nodes' => $nodes ),
		'presentation_graph' => array(
			'controls' => null === $styles ? array() : array(
				array(
					'index'   => 1,
					'control' => array( 'styles' => $styles ),
				),
			),
		),
	);
	$controls = array(
		array(
			'tag'  => 'input',
			'type' => 'text',
		),
		array( 'tag' => 'textarea' ) + $control,
	);
	return Static_Site_Importer_Form_Textarea_Rows::resolve_form( $form, $controls );
}

$ssi_textarea_failures = array();
$ssi_textarea_checks   = 0;
$ssi_textarea_expect   = static function ( string $name, mixed $actual, mixed $expected ) use ( &$ssi_textarea_failures, &$ssi_textarea_checks ): void {
	++$ssi_textarea_checks;
	if ( $actual !== $expected ) {
		$ssi_textarea_failures[] = $name . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual );
	}
};

$ssi_textarea_edges = array(
	'padding'      => '8px 12px',
	'border_width' => '1px',
);

// Only textarea controls are resolved, keyed by control index.
$ssi_textarea_resolved = ssi_textarea_rows_resolve( 'grid', $ssi_textarea_edges, array( 'rows' => 4 ) );
$ssi_textarea_expect( 'only-textareas-resolved', array_keys( $ssi_textarea_resolved ), array( 1 ) );

// Outer display.
$ssi_textarea_expect( 'grid-parent-block', ssi_textarea_rows_resolve( 'grid', null )[1]->display, 'block' );
$ssi_textarea_expect( 'inline-grid-parent-block', ssi_textarea_rows_resolve( 'inline-grid', null )[1]->display, 'block' );
$ssi_textarea_expect( 'flex-parent-block', ssi_textarea_rows_resolve( 'flex', null )[1]->display, 'block' );
$ssi_textarea_expect( 'inline-flex-parent-block', ssi_textarea_rows_resolve( ' Inline-Flex ', null )[1]->display, 'block' );
$ssi_textarea_expect( 'block-parent-inline-block', ssi_textarea_rows_resolve( 'block', null )[1]->display, 'inline-block' );
$ssi_textarea_expect( 'unknown-parent-inline-block', ssi_textarea_rows_resolve( null, null )[1]->display, 'inline-block' );
$ssi_textarea_expect( 'captured-display-wins', ssi_textarea_rows_resolve( 'grid', array( 'display' => 'flex' ) )[1]->display, null );

// Row count and the provider height release.
$ssi_textarea_default = ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges )[1];
$ssi_textarea_expect( 'default-rows', $ssi_textarea_default->rows, 2 );
$ssi_textarea_expect( 'default-height-released', $ssi_textarea_default->release_height, true );
$ssi_textarea_expect(
	'default-presentation-styles',
	$ssi_textarea_default->presentation_styles( $ssi_textarea_edges ),
	$ssi_textarea_edges + array(
		'display' => 'inline-block',
		'height'  => 'auto',
	)
);
$ssi_textarea_expect(
	'default-editor-resets',
	$ssi_textarea_default->editor_resets(),
	array(
		'min-height' => '0',
		'height'     => 'calc(2lh + 8px + 8px + 1px + 1px)',
	)
);
$ssi_textarea_expect( 'marker-class', Static_Site_Importer_Form_Textarea_Rows::marker_class( array( 'rows' => 7 ) ), 'ssi-textarea-rows-7' );
$ssi_textarea_expect( 'out-of-range-rows', Static_Site_Importer_Form_Textarea_Rows::row_count( array( 'rows' => 51 ) ), null );
$ssi_textarea_expect( 'out-of-range-marker', Static_Site_Importer_Form_Textarea_Rows::marker_class( array( 'rows' => 0 ) ), '' );

// A captured height is authoritative: no release and no editor height.
$ssi_textarea_fixed = ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges + array( 'height' => '120px' ), array( 'rows' => 4 ) )[1];
$ssi_textarea_expect( 'captured-height-not-released', $ssi_textarea_fixed->release_height, false );
$ssi_textarea_expect( 'captured-height-no-editor-height', $ssi_textarea_fixed->editor_height, null );
$ssi_textarea_expect( 'captured-height-editor-resets', $ssi_textarea_fixed->editor_resets(), array( 'min-height' => '0' ) );
$ssi_textarea_auto = ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges + array( 'height' => 'auto' ), array( 'rows' => 4 ) )[1];
$ssi_textarea_expect( 'captured-auto-height-editor-height', $ssi_textarea_auto->editor_height, 'calc(4lh + 8px + 8px + 1px + 1px)' );

// A captured min-height is kept: the provider height is still released, the
// editor floor is not.
$ssi_textarea_min = ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges + array( 'min_height' => '6rem' ), array( 'rows' => 3 ) )[1];
$ssi_textarea_expect( 'min-height-height-released', $ssi_textarea_min->release_height, true );
$ssi_textarea_expect( 'min-height-no-release', $ssi_textarea_min->release_min_height, false );
$ssi_textarea_expect( 'min-height-editor-resets', $ssi_textarea_min->editor_resets(), array( 'height' => 'calc(3lh + 8px + 8px + 1px + 1px)' ) );

// Editor height: resolvable `calc()` edges are carried, unresolvable ones are not.
$ssi_textarea_expect( 'content-box-editor-height', ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges + array( 'box_sizing' => 'content-box' ), array( 'rows' => 5 ) )[1]->editor_height, 'calc(5lh)' );
$ssi_textarea_expect(
	'calc-edge-editor-height',
	ssi_textarea_rows_resolve(
		'block',
		array(
			'padding_block' => 'calc(0.75rem)',
			'border'        => '1px solid #ccc',
		)
	)[1]->editor_height,
	'calc(2lh + calc(0.75rem) + calc(0.75rem) + 1px + 1px)'
);
$ssi_textarea_expect(
	'percentage-calc-no-editor-height',
	ssi_textarea_rows_resolve(
		'block',
		array(
			'padding' => 'calc(1% + 2px)',
			'border'  => '0',
		)
	)[1]->editor_height,
	null
);
$ssi_textarea_expect(
	'percentage-no-editor-height',
	ssi_textarea_rows_resolve(
		'block',
		array(
			'padding' => '5%',
			'border'  => 'none',
		)
	)[1]->editor_height,
	null
);
$ssi_textarea_expect(
	'var-no-editor-height',
	ssi_textarea_rows_resolve(
		'block',
		array(
			'padding' => 'var(--gap)',
			'border'  => 'thin solid',
		)
	)[1]->editor_height,
	null
);
$ssi_textarea_expect( 'missing-edges-no-editor-height', ssi_textarea_rows_resolve( 'block', array() )[1]->editor_height, null );
$ssi_textarea_expect( 'missing-edges-editor-resets', ssi_textarea_rows_resolve( 'block', array() )[1]->editor_resets(), array( 'min-height' => '0' ) );

// A row count the policy does not carry: no projection; the editor floor is
// released only when the control carries a presentation box.
$ssi_textarea_rowless = ssi_textarea_rows_resolve( 'grid', $ssi_textarea_edges, array( 'rows' => 99 ) )[1];
$ssi_textarea_expect( 'rowless-no-display', $ssi_textarea_rowless->display, null );
$ssi_textarea_expect( 'rowless-no-height-release', $ssi_textarea_rowless->release_height, false );
$ssi_textarea_expect( 'rowless-editor-resets', $ssi_textarea_rowless->editor_resets(), array( 'min-height' => '0' ) );
$ssi_textarea_expect( 'rowless-unpresented-editor-resets', ssi_textarea_rows_resolve( 'grid', null, array( 'rows' => 99 ) )[1]->editor_resets(), array() );

// Front end: the marker the policy writes is the `rows` the runtime realises.
$ssi_textarea_marker = Static_Site_Importer_Form_Textarea_Rows::marker_class( array( 'rows' => 6 ) );
$ssi_textarea_html   = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div><textarea class="contact-form__textarea ' . $ssi_textarea_marker . '" rows="20"></textarea></div>' );
$ssi_textarea_expect( 'front-end-rows', $ssi_textarea_html, '<div><textarea class="contact-form__textarea" rows="6"></textarea></div>' );

// Editor: the resolved resets reach only parts that can address a textarea.
$ssi_textarea_scope   = 'ssi-form-123456789abc';
$ssi_textarea_policy  = ssi_textarea_rows_resolve( 'block', $ssi_textarea_edges, array( 'rows' => 4 ) );
$ssi_textarea_surface = array();
foreach ( array( 'textarea', 'select', 'submit' ) as $ssi_textarea_type ) {
	$ssi_textarea_map    = Static_Site_Importer_Form_Presentation::provider_layout_target_map(
		array( 'layout_graph' => array( 'nodes' => array( array( 'id' => 'form' ), array( 'id' => 'control-1' ) ) ) ),
		$ssi_textarea_scope,
		array(
			1 => Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor(
				$ssi_textarea_scope,
				1,
				$ssi_textarea_type,
				array(
					'control'                => true,
					'sibling_stacked_margin' => true,
				)
			),
		)
	);
	$ssi_textarea_editor = Static_Site_Importer_Form_Presentation_Descriptor::editor_layout_target_map( $ssi_textarea_map, $ssi_textarea_scope, $ssi_textarea_policy );
	$ssi_textarea_count  = 0;
	foreach ( $ssi_textarea_editor['presentation_targets'] as $ssi_textarea_target ) {
		foreach ( $ssi_textarea_target['destinations'] as $ssi_textarea_destination ) {
			if ( ( $ssi_textarea_destination['resets'] ?? null ) === $ssi_textarea_policy[1]->editor_resets() ) {
				++$ssi_textarea_count;
			}
		}
	}
	$ssi_textarea_surface[ $ssi_textarea_type ] = $ssi_textarea_count;
}
$ssi_textarea_expect(
	'editor-realisation-per-part',
	$ssi_textarea_surface,
	array(
		'textarea' => 1,
		'select'   => 0,
		'submit'   => 0,
	)
);
$ssi_textarea_table = Static_Site_Importer_Form_Presentation_Descriptor::editor_surface_table();
$ssi_textarea_expect( 'editor-textarea-parts', array_keys( array_filter( $ssi_textarea_table, static fn ( array $surface ): bool => ! empty( $surface['textarea_rows'] ) ) ), array( 'control' ) );

if ( array() !== $ssi_textarea_failures ) {
	ssi_textarea_rows_write( 'php://stderr', 'FAIL form textarea rows: ' . implode( "\n", $ssi_textarea_failures ) . "\n" );
	exit( 1 );
}
ssi_textarea_rows_write( 'php://stdout', 'PASS form textarea rows: ' . $ssi_textarea_checks . " checks\n" );
