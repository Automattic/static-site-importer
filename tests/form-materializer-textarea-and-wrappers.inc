<?php
/**
 * Form materializer smoke suite: textarea sizing, semantic wrappers and field lists.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$few_rows_form = $topology_form;
	$few_rows_form['forms'][0]['controls'][2]['rows'] = '3';
	$few_rows_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $few_rows_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$many_rows_form = $topology_form;
	$many_rows_form['forms'][0]['controls'][2]['rows'] = '6';
	$many_rows_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $many_rows_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		str_contains( (string) ( $few_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-3' )
			&& str_contains( (string) ( $many_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-6' )
			&& ! str_contains( (string) ( $few_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-6' )
			&& str_contains( (string) ( $few_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' )
			&& str_contains( (string) ( $many_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' )
			&& ! str_contains( (string) ( $few_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' )
			&& ! str_contains( (string) ( $many_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' ),
		'distinctly-authored-textarea-row-counts-materialize-onto-the-rendered-control-instead-of-one-provider-default',
		wp_json_encode( array( 'few' => $few_rows_row['block_markup'] ?? null, 'many' => $many_rows_row['block_markup'] ?? null ) )
	);
	$jetpack_textarea = "<div class=\"grunion-field-textarea-wrap\"><textarea\n\t\t                style=''\n\t\t                name='message'\n\t\t                id='contact-form-comment-message'\n\t\t                rows='20'\n\t\t                class='textarea ssi-textarea-rows-6'></textarea></div>";
	$projected_rows   = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $jetpack_textarea );
	$assert(
		str_contains( $projected_rows, "rows='6'" )
			&& ! str_contains( $projected_rows, "rows='20'" )
			&& ! str_contains( $projected_rows, 'ssi-textarea-rows-' ),
		'provider-runtime-rewrites-jetpack-textarea-rows-20-to-the-authored-count',
		$projected_rows
	);
	$projected_rows_via_wrapper = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $jetpack_textarea );
	$assert(
		str_contains( $projected_rows_via_wrapper, "rows='6'" )
			&& ! str_contains( $projected_rows_via_wrapper, "rows='20'" )
			&& ! str_contains( $projected_rows_via_wrapper, 'ssi-textarea-rows-' ),
		'provider-wrapper-projection-also-rewrites-authored-textarea-rows',
		$projected_rows_via_wrapper
	);
	// A source textarea sized by rows="6" plus captured padding/font/line-height
	// (12px / 14px / 20px) must keep that row count on the materialized control
	// so the browser can size 6×20 + 12+12 + 1+1 = 146px instead of Jetpack's
	// rows="20" at 200px or a provider-default overlay of 178px.
	$authored_rows_html = '<style>textarea{box-sizing:border-box;padding:12px;font-size:14px;line-height:20px;border:1px solid}</style><form>'
		. '<div><label>Message</label><textarea rows="6" required></textarea></div>'
		. '<button type="submit">Send</button></form>';
	$authored_rows_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $authored_rows_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$authored_rows_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $authored_rows_source ) ) );
	$authored_rows_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_rows_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_rows_markup    = (string) ( $authored_rows_row['block_markup'] ?? '' );
	$authored_rows_css       = (string) ( $authored_rows_row['provider_layout_overlay_css']['css'] ?? '' );
	$authored_rows_rendered  = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes(
		"<textarea rows='20' class='textarea ssi-textarea-rows-6'></textarea>"
	);
	$assert(
		empty( $authored_rows_validated['errors'] )
			&& 'mapped' === ( $authored_rows_row['status'] ?? '' )
			&& str_contains( $authored_rows_markup, 'ssi-textarea-rows-6' )
			&& str_contains( $authored_rows_css, 'height:auto' )
			&& ! str_contains( $authored_rows_css, 'height:178px' )
			&& ! str_contains( $authored_rows_css, 'height:200px' )
			&& str_contains( $authored_rows_rendered, "rows='6'" )
			&& ! str_contains( $authored_rows_rendered, "rows='20'" ),
		'compiled-textarea-rows-6-reaches-the-rendered-control-with-provider-height-neutralized',
		wp_json_encode( array( 'errors' => $authored_rows_validated['errors'] ?? array(), 'markup' => $authored_rows_markup, 'css' => $authored_rows_css, 'rendered' => $authored_rows_rendered, 'source' => $authored_rows_source['controls'][0] ?? null ) )
	);
	// A cascade-resolved height or minimum height already captured for this textarea
	// is authoritative; the row-count height override above only fills what it omits.
	$authored_textarea_height_form = $topology_form;
	$authored_textarea_height_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array( array( 'index' => 2, 'control' => array( 'styles' => array( 'height' => '9rem' ), 'provenance' => array() ) ) ),
	);
	$authored_textarea_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_textarea_height_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		str_contains( (string) ( $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:9rem' )
			&& ! str_contains( (string) ( $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:82px' ),
		'source-cascade-resolved-textarea-height-is-authoritative-over-the-row-count-fallback',
		wp_json_encode( $authored_textarea_height_row['provider_layout_overlay_css'] ?? null )
	);
	// The HTML platform renders an author-less textarea inline-level, so its field
	// row keeps the control's baseline descent inside the row box. The provider
	// paints its textarea block-level, which shrinks every row by that descent and
	// changes the form box. The platform default is restored whenever the source
	// cascade captured no display of its own for the control - independently of a
	// captured height, which governs a different dimension of the same box.
	$assert(
		str_contains( $authored_rows_css, 'display:inline-block' )
			&& 1 === preg_match( '/height:9rem[^}]*display:inline-block|display:inline-block[^}]*height:9rem/', (string) ( $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ) ),
		'authored-less-textarea-keeps-the-platform-inline-level-box-participation-even-with-a-captured-height',
		wp_json_encode( array( 'rows_css' => $authored_rows_css, 'height_css' => $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ) )
	);
	// A textarea whose source field row is a grid container is a grid item, so its
	// outer display is blockified: the projection restores `block`, not the
	// block-flow platform default, so no editor row gains a baseline descent.
	$grid_row_html   = '<style>.row{display:grid;gap:8px}textarea{box-sizing:border-box;padding:12px;font-size:14px;line-height:20px;border:1px solid}</style><form>'
		. '<div class="row"><label>Message</label><textarea rows="6" required></textarea></div>'
		. '<button type="submit">Send</button></form>';
	$grid_row_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $grid_row_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$grid_row_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_row_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_row_css    = (string) ( $grid_row_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		'mapped' === ( $grid_row_row['status'] ?? '' )
			&& 1 === preg_match( '/display:block;height:auto/', $grid_row_css )
			&& ! str_contains( $grid_row_css, 'display:inline-block' ),
		'grid-row-textarea-takes-the-blockified-grid-item-display',
		$grid_row_css
	);
	$captured_display_form = $authored_textarea_height_form;
	$captured_display_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array( array( 'index' => 2, 'control' => array( 'styles' => array( 'display' => 'flex' ), 'provenance' => array() ) ) ),
	);
	$captured_display_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $captured_display_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$captured_display_css = (string) ( $captured_display_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		str_contains( $captured_display_css, 'display:flex' )
			&& ! str_contains( $captured_display_css, 'display:inline-block' ),
		'captured-source-display-is-authoritative-over-the-platform-textarea-default',
		$captured_display_css
	);
	$direct_label_form = array(
		'forms' => array( array(
			'selector' => 'form.direct-labels',
			'form' => array(),
			'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ), array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
			'sibling_relations' => array( 'schema' => 'generic/form-sibling-relations/v1', 'max_pairs' => 128, 'truncated' => false, 'pairs' => array( array( 'control' => 0 ), array( 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'direct-labels' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => '1.2rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.direct-labels', 'condition' => null, 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) ) ) ),
		) ),
	);
	$direct_label_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $direct_label_form );
	$direct_label_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $direct_label_validation['forms'] ?? array() ) );
	$direct_label_css = $expand_selector_lists( (string) ( $direct_label_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ) );
	$assert( empty( $direct_label_validation['errors'] ) && 4 === substr_count( $direct_label_css, 'gap:1.2rem' ) && 2 === preg_match_all( '/\.ssi-node-[a-f0-9]{12}-wrap\{display:flex;flex-direction:column;gap:1\.2rem\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__input-error:not\(\.has-errors\)\{display:none\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__field-hints\{display:contents\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__field-format\{display:none\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-field-row > label\{margin-block-end:0\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.grunion-field::placeholder\{color:revert\}/', $direct_label_css ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $direct_label_seed['forms'][0]['provider_layout_overlay_css'] ?? null ), 'proven direct label/control sibling pairs preserve native placeholder appearance and suppress only inactive provider errors' );
	$native_row_form = array(
		'forms' => array( array(
			'selector' => 'form.subscribe',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Subscribe' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'email-submit-row' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'email-submit-row' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.email-submit-row', 'condition' => null, 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) ) ) ),
		) ),
	);
	$native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_row_form );
	$native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$native_row_markup     = (string) ( $native_row_result['block_markup'] ?? '' );
	$native_row_losses     = array_column( $native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$native_row_targets    = array_filter( $native_row_result['provider_layout_target_map']['targets'] ?? array(), static fn( array $target ): bool => 'wrapper-0' === ( $target['node'] ?? '' ) );
	$native_row_blocks     = parse_blocks( $native_row_markup );
	$native_row_block      = $native_row_blocks[0]['innerBlocks'][0] ?? array();
	$assert( empty( $native_row_validation['errors'] ) && 'core/group' === ( $native_row_block['blockName'] ?? '' ) && array( 'jetpack/field-email', 'core/button' ) === array_column( $native_row_block['innerBlocks'] ?? array(), 'blockName' ) && str_contains( (string) ( $native_row_block['attrs']['className'] ?? '' ), 'email-submit-row' ) && preg_match( '/ssi-node-[a-f0-9]{12}/', (string) ( $native_row_block['attrs']['className'] ?? '' ) ) && 1 === count( $native_row_targets ) && in_array( 'direct_child_layout', reset( $native_row_targets )['capabilities'] ?? array(), true ) && 1 === substr_count( $native_row_markup, '<div class="wp-block-group' ) && ! array_intersect( array( 'provider_wrapper_layout_unrepresentable', 'direct_child_relationship_unrepresentable' ), $native_row_losses ) && $native_row_markup === serialize_blocks( parse_blocks( $native_row_markup ) ), 'proven-horizontal-direct-control-row-preserves-native-group-and-direct-child-layout-target', wp_json_encode( $native_row_result ) );
	$nested_native_row_form = $native_row_form;
	$nested_native_row_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'newsletter-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div', 'class' => 'email-submit-row' ),
		array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'email-box' ),
		array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'tag' => 'div', 'class' => 'email-control-shell' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 4, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'control' => 1 ),
	);
	$nested_native_row_form['forms'][0]['layout_graph']['nodes'] = array(
		$layout_node( 'form', array(), 'form' ),
		$proven_layout_node( 'wrapper-0', 'form', 'newsletter-shell', array( 'display' => 'flex', 'direction' => 'column', 'gap' => '20px', 'width' => '100%', 'flex_shrink' => '0', 'align_items' => 'center' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items' ) ),
		$proven_layout_node( 'wrapper-1', 'wrapper-0', 'email-submit-row', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '16px', 'width' => '100%', 'flex_shrink' => '0', 'align_items' => 'center', 'justify_content' => 'center' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items', 'justify-content' ) ),
		$proven_layout_node( 'wrapper-2', 'wrapper-1', 'email-box', array( 'display' => 'flex', 'direction' => 'column', 'gap' => '8px', 'width' => '244px', 'flex_shrink' => '0' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink' ) ),
		$proven_layout_node( 'wrapper-3', 'wrapper-2', 'email-control-shell', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '8px', 'width' => '244px', 'flex_shrink' => '0', 'align_items' => 'center', 'align_self' => 'stretch' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items', 'align-self' ) ),
	);
	$nested_condition = array( 'kind' => 'media', 'query' => '(max-width:915px)' );
	$nested_native_row_form['forms'][0]['layout_graph']['variants'] = array(
		$proven_layout_variant( 'wrapper-0', 'newsletter-shell', $nested_condition, array( 'gap' => '10px', 'width' => '100%' ), array( 'gap', 'width' ) ),
		$proven_layout_variant( 'wrapper-1', 'email-submit-row', $nested_condition, array( 'direction' => 'column', 'gap' => '13px', 'align_self' => 'stretch' ), array( 'flex-direction', 'gap', 'align-self' ) ),
		$proven_layout_variant( 'wrapper-1', 'email-submit-row', array( 'kind' => 'media', 'query' => '(max-width:390px)' ), array( 'direction' => 'column', 'wrap' => 'nowrap', 'align_items' => 'stretch' ), array( 'flex-direction', 'flex-wrap', 'align-items' ) ),
		$proven_layout_variant( 'wrapper-2', 'email-box', $nested_condition, array( 'width' => '100%' ), array( 'width' ) ),
		$proven_layout_variant( 'wrapper-3', 'email-control-shell', $nested_condition, array( 'width' => '100%' ), array( 'width' ) ),
	);
	$nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_native_row_form );
	$nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_native_row_markup     = (string) ( $nested_native_row_result['block_markup'] ?? '' );
	$nested_native_row_block      = parse_blocks( $nested_native_row_markup )[0]['innerBlocks'][0] ?? array();
	$nested_native_row_child      = $nested_native_row_block['innerBlocks'][0] ?? array();
	$nested_native_row_grandchild = $nested_native_row_child['innerBlocks'][0] ?? array();
	$nested_native_row_field_box  = $nested_native_row_grandchild['innerBlocks'][0] ?? array();
	$nested_native_row_losses     = array_column( $nested_native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$nested_native_row_targets    = array_column( $nested_native_row_result['provider_layout_target_map']['targets'] ?? array(), null, 'node' );
	$nested_native_row_css        = (string) ( $nested_native_row_result['provider_layout_overlay_css']['css'] ?? '' );
	$nested_native_row_strategies = array_column( $nested_native_row_result['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $nested_native_row_validation['errors'] ) && true === ( $nested_native_row_result['runtime_mapped'] ?? false ) && 'core/group' === ( $nested_native_row_block['blockName'] ?? '' ) && 'vertical' === ( $nested_native_row_block['attrs']['layout']['orientation'] ?? '' ) && 'core/group' === ( $nested_native_row_child['blockName'] ?? '' ) && 'core/group' === ( $nested_native_row_grandchild['blockName'] ?? '' ) && 'core/group' === ( $nested_native_row_field_box['blockName'] ?? '' ) && array( 'jetpack/field-email' ) === array_column( $nested_native_row_field_box['innerBlocks'] ?? array(), 'blockName' ) && array( 'core/group', 'core/button' ) === array_column( $nested_native_row_child['innerBlocks'] ?? array(), 'blockName' ) && isset( $nested_native_row_targets['wrapper-0'], $nested_native_row_targets['wrapper-1'], $nested_native_row_targets['wrapper-2'], $nested_native_row_targets['wrapper-3'] ) && in_array( 'direct_child_layout', $nested_native_row_targets['wrapper-1']['capabilities'] ?? array(), true ) && ! str_contains( $nested_native_row_markup, 'ssi-source-wrapper-' ) && 4 === substr_count( $nested_native_row_markup, '<!-- wp:group ' ) && 4 === substr_count( $nested_native_row_markup, '<div class="wp-block-group' ) && str_contains( $nested_native_row_css, 'width:100%;flex-shrink:0' ) && str_contains( $nested_native_row_css, '@media (max-width:915px)' ) && str_contains( $nested_native_row_css, '@media (max-width:390px)' ) && ! array_intersect( array( 'provider_wrapper_layout_unrepresentable', 'direct_child_relationship_unrepresentable', 'responsive_layout_ownership' ), $nested_native_row_losses ) && $nested_native_row_markup === serialize_blocks( parse_blocks( $nested_native_row_markup ) ), 'proven-responsive-nested-div-containers-preserve-exact-native-groups-and-overlay', wp_json_encode( $nested_native_row_result ) );
	$unsafe_nested_native_row_form = $nested_native_row_form;
	$unsafe_nested_native_row_form['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 1, 'depth' => 3, 'tag' => 'div', 'class' => 'ambiguous-extra-child' );
	$unsafe_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_nested_native_row_form );
	$unsafe_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$unsafe_nested_native_row_markup     = (string) ( $unsafe_nested_native_row_result['block_markup'] ?? '' );
	$assert( empty( $unsafe_nested_native_row_validation['errors'] ) && ! str_contains( $unsafe_nested_native_row_markup, 'newsletter-shell ssi-node-' ) && ! str_contains( $unsafe_nested_native_row_markup, 'email-submit-row ssi-node-' ), 'nested-div-control-row-with-ambiguous-extra-child-remains-declined', wp_json_encode( $unsafe_nested_native_row_result ) );
	$semantic_nested_native_row_form = $nested_native_row_form;
	$semantic_nested_native_row_form['forms'][0]['control_topology']['nodes'][0]['tag'] = 'section';
	$semantic_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $semantic_nested_native_row_form );
	$semantic_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $semantic_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$semantic_nested_native_row_losses     = array_column( $semantic_nested_native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( empty( $semantic_nested_native_row_validation['errors'] ) && ! str_contains( (string) ( $semantic_nested_native_row_result['block_markup'] ?? '' ), 'newsletter-shell ssi-node-' ) && in_array( 'unsupported_semantic_wrapper', $semantic_nested_native_row_losses, true ), 'nested-semantic-container-remains-declined', wp_json_encode( $semantic_nested_native_row_result ) );
	$unsafe_property_nested_native_row_form = $nested_native_row_form;
	$unsafe_property_nested_native_row_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = 'url(https://example.test/unsafe)';
	$unsafe_property_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_property_nested_native_row_form );
	$unsafe_property_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_property_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $unsafe_property_nested_native_row_validation['errors'] ) && ! str_contains( (string) ( $unsafe_property_nested_native_row_result['block_markup'] ?? '' ), 'newsletter-shell ssi-node-' ), 'nested-container-with-unsafe-property-remains-declined', wp_json_encode( array( 'validation' => $unsafe_property_nested_native_row_validation, 'row' => $unsafe_property_nested_native_row_result ) ) );
	// A stylesheet may be attached as media="all". It is unconditional, so a
	// two-field grid can be mapped while source paragraph field wrappers remain
	// represented by the provider runtime instead of being silently flattened.
	$aetna_topology_form = $topology_form;
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][1]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][3]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][5]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['layout_graph']['nodes'][0]['layout'] = array();
	$aetna_all_condition = array( 'kind' => 'media', 'query' => 'all' );
	$aetna_topology_form['forms'][0]['layout_graph']['variants'][] = array(
		'node' => 'wrapper-0', 'condition' => $aetna_all_condition, 'layout_patch' => array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)', 'gap' => '1rem' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'gap' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.row-2', 'condition' => $aetna_all_condition, 'properties' => array( 'display', 'grid-template-columns', 'gap' ) ) ),
	);
	$aetna_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $aetna_topology_form );
	$aetna_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $aetna_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$aetna_markup     = (string) ( $aetna_row['block_markup'] ?? '' );
	$aetna_losses     = array_column( $aetna_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( empty( $aetna_validation['errors'] ) && 'mapped' === ( $aetna_row['status'] ?? '' ) && true === ( $aetna_row['runtime_mapped'] ?? false ) && 2 === substr_count( $aetna_markup, '"width":50' ) && ! in_array( 'unsupported_semantic_wrapper', $aetna_losses, true ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $aetna_losses, true ) && $aetna_markup === serialize_blocks( parse_blocks( $aetna_markup ) ), 'unconditional-media-grid-and-paragraph-field-wrappers-materialize-as-editable-valid-blocks', wp_json_encode( $aetna_row ) );
	$aetna_field_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-semantic-wrapper-1--p--field"><label>Name</label><input></div>' );
	$aetna_submit_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( '<div class="wp-block-button ssi-source-semantic-wrapper-1--p"><button>Send</button></div>', array( 'attrs' => array( 'className' => 'ssi-source-semantic-wrapper-1--p' ) ) );
	// A paragraph cannot hold the provider's div markup: the browser would close
	// it before the div and add an empty paragraph after it, and both empty
	// paragraphs would become items of the provider's flex row. The marker is
	// consumed without emitting that paragraph. Phrasing-only markup still gets
	// the paragraph back.
	$aetna_phrasing_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<span class="note ssi-source-semantic-wrapper-1--p--field"><input></span>' );
	$assert( '<div class="grunion-field-text-wrap"><label>Name</label><input></div>' === $aetna_field_runtime && '<div class="wp-block-button"><button>Send</button></div>' === $aetna_submit_runtime && '<p class="field"><span class="note"><input></span></p>' === $aetna_phrasing_runtime, 'provider-runtime-consumes-paragraph-wrapper-markers-without-nesting-block-markup-in-a-paragraph', $aetna_field_runtime . "\n" . $aetna_submit_runtime . "\n" . $aetna_phrasing_runtime );
	$aetna_subscription_form = array(
		'forms' => array( array(
			'selector' => 'form.subscribe',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'token' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Subscribe' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'subscription-row' ), array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'p' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ), array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'p' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 1, 'depth' => 2, 'control' => 2 ) ) ),
			'layout_graph' => $v2_layout_graph( array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'subscribe' ) ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'subscription-row' ) ), 'layout' => array(), 'provenance' => array() ) ) ),
		) ),
	);
	$aetna_subscription_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $aetna_all_condition, 'layout_patch' => array( 'display' => 'flex', 'direction' => 'row', 'align_items' => 'flex-start' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'flex-direction' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'align-items' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/subscription.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.subscription-row', 'condition' => $aetna_all_condition, 'properties' => array( 'display', 'flex-direction', 'align-items' ) ) ) );
	$aetna_subscription_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $aetna_subscription_form );
	$aetna_subscription_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $aetna_subscription_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $aetna_subscription_validation['errors'] ) && 'mapped' === ( $aetna_subscription_row['status'] ?? '' ) && true === ( $aetna_subscription_row['runtime_mapped'] ?? false ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), array_column( $aetna_subscription_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ) ), 'unconditional-subscription-row-with-hidden-bookkeeping-materializes-without-source-form-runtime', wp_json_encode( $aetna_subscription_row ) );
	// The same subscription row when the stylesheet lays out the classless
	// paragraphs by id (`form p#subscribe-email { flex-grow: 1 }`, the submit
	// paragraph centred with flex) and the producer omits the hidden inputs, as
	// Blocks Engine does. A classless paragraph was not a projectable box like
	// a classless div, so its proven layout became an unrepresentable topology
	// loss that declined the whole form. Now the email paragraph's facts reach
	// the provider field shell and the submit paragraph's facts reach the Button
	// wrapper, as they do for a classed paragraph. The source row declares only
	// `display: flex`, so the form element it is laid out on also states the
	// initial `row`/`nowrap` instead of keeping Jetpack's own wrapping row.
	$styled_paragraph_css  = array( 'source_path' => 'assets/subscription.css', 'source_sha256' => str_repeat( 'b', 64 ), 'condition' => null );
	$styled_paragraph_form = array(
		'forms' => array( array(
			'selector'         => 'form.subscribe',
			'controls'         => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Subscribe' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'subscription-row' ), array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'p', 'source_id' => 'subscribe-email' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ), array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'p', 'source_id' => 'subscribe-submit' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ) ) ),
			'layout_graph'     => $v2_layout_graph( array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'subscribe' ) ), 'layout' => array(), 'provenance' => array() ),
				array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'subscription-row' ) ), 'layout' => array( 'display' => 'flex', 'align_items' => 'flex-start' ), 'provenance' => array( $styled_paragraph_css + array( 'selector' => '.subscription-row', 'properties' => array( 'display', 'align-items' ) ) ) ),
				array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'p', 'id' => 'subscribe-email', 'classes' => array() ), 'layout' => array( 'flex_grow' => '1' ), 'provenance' => array( $styled_paragraph_css + array( 'selector' => 'form p#subscribe-email', 'properties' => array( 'flex-grow' ) ) ) ),
				array( 'id' => 'wrapper-2', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 1, 'source' => array( 'tag' => 'p', 'id' => 'subscribe-submit', 'classes' => array() ), 'layout' => array( 'display' => 'flex', 'justify_content' => 'center' ), 'provenance' => array( $styled_paragraph_css + array( 'selector' => '.subscription-row p#subscribe-submit', 'properties' => array( 'display', 'justify-content' ) ) ) ),
			) ),
		) ),
	);
	$styled_paragraph_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $styled_paragraph_form );
	$styled_paragraph_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $styled_paragraph_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$styled_paragraph_markup     = (string) ( $styled_paragraph_row['block_markup'] ?? '' );
	$styled_paragraph_css_out    = (string) ( $styled_paragraph_row['provider_layout_overlay_css']['css'] ?? '' );
	$styled_paragraph_ops        = array_column( $styled_paragraph_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	// The email paragraph's hook rides the field-shell marker; the submit paragraph's hook is the generated class right before the Button block's semantic marker.
	$styled_paragraph_field_hook  = 1 === preg_match( '/ssi-source-wrapper-1\\\\u002d\\\\u002d(ssi-node-[a-f0-9]{12})/', $styled_paragraph_markup, $styled_paragraph_field_match ) ? $styled_paragraph_field_match[1] : '-';
	$styled_paragraph_submit_hook = 1 === preg_match( '/<!-- wp:button \{[^\n]*"className":"[^"\n]* (ssi-node-[a-f0-9]{12}) ssi-source-semantic-wrapper-1/', $styled_paragraph_markup, $styled_paragraph_submit_match ) ? $styled_paragraph_submit_match[1] : '-';
	$assert(
		empty( $styled_paragraph_validation['errors'] )
			&& 'mapped' === ( $styled_paragraph_row['status'] ?? '' )
			&& true === ( $styled_paragraph_row['runtime_mapped'] ?? false )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $styled_paragraph_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true )
			&& 2 === count( array_keys( $styled_paragraph_ops, 'provider_field_wrapper_class_projection', true ) )
			&& 2 === count( array_keys( $styled_paragraph_ops, 'provider_source_box_transposition', true ) )
			&& '-' !== $styled_paragraph_field_hook
			&& '-' !== $styled_paragraph_submit_hook
			&& $styled_paragraph_field_hook !== $styled_paragraph_submit_hook
			&& str_contains( $styled_paragraph_css_out, ' .' . $styled_paragraph_field_hook . '-wrap{flex-grow:1;flex-shrink:1;flex-basis:auto}' )
			&& str_contains( $styled_paragraph_css_out, ' .' . $styled_paragraph_submit_hook . '{display:flex;justify-content:center;flex-grow:0;flex-shrink:1;flex-basis:auto}' )
			&& 1 === preg_match( '/form\.jetpack-contact-form__form[^{]*\{[^}]*display:flex[^}]*\}/', $styled_paragraph_css_out, $styled_paragraph_form_rule )
			&& str_contains( $styled_paragraph_form_rule[0], 'flex-direction:row' )
			&& str_contains( $styled_paragraph_form_rule[0], 'flex-wrap:nowrap' )
			&& array() === array_diff( array( 'wrapper-1', 'wrapper-2' ), array_column( $styled_paragraph_row['provider_layout_target_map']['targets'] ?? array(), 'node' ) ),
		'id-styled-classless-paragraph-wrappers-carry-their-layout-onto-the-provider-shells-in-a-non-wrapping-row',
		wp_json_encode( array( 'errors' => $styled_paragraph_validation['errors'] ?? array(), 'status' => $styled_paragraph_row['status'] ?? '', 'reason' => $styled_paragraph_row['reason'] ?? '', 'losses' => $styled_paragraph_row['computed_layout_receipt']['losses'] ?? array(), 'ops' => $styled_paragraph_ops, 'markup' => $styled_paragraph_markup, 'css' => $styled_paragraph_css_out ) )
	);
	// Jetpack renders in-form copy as an item of the same form element. The
	// source row arranged only the field and the submit, so with copy in the
	// form the element keeps Jetpack's own wrapping row.
	$styled_paragraph_copy_form                                    = $styled_paragraph_form;
	$styled_paragraph_copy_form['forms'][0]['form']['context_before'] = array( array( 'type' => 'paragraph', 'text' => 'Join our list.' ) );
	$styled_paragraph_copy_validation                              = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $styled_paragraph_copy_form );
	$styled_paragraph_copy_row                                     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $styled_paragraph_copy_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$styled_paragraph_copy_css                                     = (string) ( $styled_paragraph_copy_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $styled_paragraph_copy_validation['errors'] )
			&& 'mapped' === ( $styled_paragraph_copy_row['status'] ?? '' )
			&& str_contains( (string) ( $styled_paragraph_copy_row['block_markup'] ?? '' ), 'Join our list.' )
			&& 1 === preg_match( '/form\.jetpack-contact-form__form[^{]*\{[^}]*display:flex[^}]*\}/', $styled_paragraph_copy_css, $styled_paragraph_copy_rule )
			&& ! str_contains( $styled_paragraph_copy_rule[0], 'flex-wrap' ),
		'transposed-row-keeps-the-provider-wrap-when-in-form-copy-shares-the-form-element',
		wp_json_encode( array( 'errors' => $styled_paragraph_copy_validation['errors'] ?? array(), 'status' => $styled_paragraph_copy_row['status'] ?? '', 'css' => $styled_paragraph_copy_css ) )
	);
	// A paragraph without layout facts keeps the bare marker: no hook, no empty class suffix.
	$assert( str_contains( (string) ( $aetna_subscription_row['block_markup'] ?? '' ), 'ssi-source-semantic-wrapper-1--p' ) && ! str_contains( (string) ( $aetna_subscription_row['block_markup'] ?? '' ), 'ssi-source-semantic-wrapper-1--p--' ), 'plain-classless-paragraph-wrapper-keeps-the-bare-semantic-marker', (string) ( $aetna_subscription_row['block_markup'] ?? '' ) );
	// The initial values are stated only for a flex container that leaves them implicit.
	$assert(
		method_exists( Static_Site_Importer_Form_Layout_Projection::class, 'with_flex_container_defaults' )
			&& array( 'display' => 'flex', 'direction' => 'row', 'wrap' => 'nowrap', 'align_items' => 'stretch' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex' ) )
			&& array( 'display' => 'flex', 'wrap' => 'wrap', 'direction' => 'row', 'align_items' => 'stretch' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex', 'wrap' => 'wrap' ) )
			&& array( 'display' => 'flex', 'direction' => 'column', 'wrap' => 'nowrap', 'align_items' => 'stretch' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex', 'direction' => 'column' ) )
			&& array( 'display' => 'flex', 'align_items' => 'center', 'direction' => 'row', 'wrap' => 'nowrap' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex', 'align_items' => 'center' ) )
			&& array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)' ) )
			&& array( 'gap' => '1rem' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'gap' => '1rem' ) )
			// A conditional patch keeps the wrap and alignment its base rule declares.
			&& array( 'display' => 'flex', 'direction' => 'row' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex' ), array( 'display' => 'block', 'wrap' => 'wrap', 'align_items' => 'stretch' ) )
			// A patch that first makes the box flex states the alignment its block base never declared.
			&& array( 'display' => 'flex', 'direction' => 'row', 'wrap' => 'nowrap', 'align_items' => 'stretch' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex' ), array( 'display' => 'block' ) )
			// In-form copy keeps the provider's wrap, but not its top alignment.
			&& array( 'display' => 'flex', 'align_items' => 'stretch' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_container_defaults( array( 'display' => 'flex' ), array(), false ),
		'transposed-flex-container-states-only-the-implicit-initial-direction-wrap-and-alignment'
	);
	// A child of a source flex container (or a box that declares a grow or
	// shrink) keeps the initial `flex: 0 1 auto` for what it leaves implicit,
	// not Jetpack's own full-row item flex.
	$assert(
		method_exists( Static_Site_Importer_Form_Layout_Projection::class, 'with_flex_item_defaults' )
			&& array( 'flex_grow' => '1', 'flex_shrink' => '1', 'flex_basis' => 'auto' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'flex_grow' => '1' ) )
			&& array( 'flex_grow' => '1', 'flex_basis' => '50%', 'flex_shrink' => '1' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'flex_grow' => '1', 'flex_basis' => '50%' ) )
			&& array( 'flex' => '1 1 0%' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'flex' => '1 1 0%' ), array(), true )
			&& array( 'flex_grow' => '0', 'flex_shrink' => '1' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'flex_grow' => '0' ), array( 'flex_basis' => '200px' ) )
			&& array( 'width' => '10rem' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'width' => '10rem' ) )
			&& array( 'display' => 'flex', 'flex_grow' => '0', 'flex_shrink' => '1', 'flex_basis' => 'auto' ) === Static_Site_Importer_Form_Layout_Projection::with_flex_item_defaults( array( 'display' => 'flex' ), array(), true ),
		'transposed-flex-item-states-only-the-implicit-initial-flex'
	);
	// A source field-group grid that groups every mapped control except a
	// submit sitting outside it (a submit is always the field container's
	// sibling in Jetpack's own rendering, never its descendant, so requiring
	// an exact match against every mapped control - submit included - made a
	// perfectly representable field-group box an unconditional loss) is now
	// transposed onto the form element, and the exempted submit is told to
	// span every column that grid produces instead of being auto-placed into
	// just one of them. The captured responsive condition and grid track list
	// use the exact shapes a real Tailwind v4 stylesheet compiles to: a
	// `width >= Nrem` media range (not the legacy `min-width:` prefix), a
	// `repeat(N, minmax(0, 1fr))` track list, and a leading-dot decimal inside
	// `calc()` - proving the overlay's value grammar admits all three.
	$grid_condition        = array( 'kind' => 'media', 'query' => '(width>=40rem)' );
	$grid_box_form         = array(
		'forms' => array( array(
			'selector'         => 'form.grid-fields',
			'controls'         => array(
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First' ),
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'second', 'label' => 'Second' ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
			'control_topology' => array(
				'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
				'nodes'  => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'fields' ),
					array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'label', 'class' => 'field' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
					array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'label', 'class' => 'field' ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ),
					array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
				),
			),
			'layout_graph'     => $v2_layout_graph( array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'grid-fields' ) ), 'layout' => array(), 'provenance' => array() ),
				array(
					'id'         => 'wrapper-0',
					'kind'       => 'container',
					'parent'     => 'form',
					'order'      => 0,
					'source'     => array( 'tag' => 'div', 'classes' => array( 'fields' ) ),
					// Base facts are unconditional (Tailwind's own `grid gap-5`,
					// present at every width); only the column count is
					// conditional (`sm:grid-cols-2`), exactly like a real
					// Tailwind v4 responsive field-group grid.
					'layout'     => array( 'display' => 'grid', 'gap' => 'calc(.25rem * 5)' ),
					'provenance' => array(
						array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.fields', 'condition' => null, 'properties' => array( 'display', 'gap' ) ),
					),
				),
			) ),
		) ),
	);
	$grid_box_form['forms'][0]['layout_graph']['variants'][] = array(
		'node'         => 'wrapper-0',
		'condition'    => $grid_condition,
		'layout_patch' => array( 'columns' => 'repeat(2,minmax(0,1fr))' ),
		'precedence'   => array(
			'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ),
		),
		'provenance'   => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.sm\:grid-cols-2', 'condition' => $grid_condition, 'properties' => array( 'grid-template-columns' ) ) ),
	);
	$grid_box_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $grid_box_form );
	$grid_box_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_box_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_box_css        = (string) ( $grid_box_row['provider_layout_overlay_css']['css'] ?? '' );
	$grid_box_losses     = array_column( $grid_box_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $grid_box_validation['errors'] ) && 'mapped' === ( $grid_box_row['status'] ?? '' ) && true === ( $grid_box_row['runtime_mapped'] ?? false ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $grid_box_losses, true ),
		'field-group-grid-missing-only-its-sibling-submit-is-a-representable-form-box',
		wp_json_encode( array( 'validation' => $grid_box_validation, 'row' => $grid_box_row ) )
	);
	$assert(
		null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $grid_box_row['provider_layout_overlay_css'] ?? null )
			&& str_contains( $grid_box_css, 'gap:calc(.25rem * 5)' )
			&& str_contains( $grid_box_css, '@media (width>=40rem){' )
			&& str_contains( $grid_box_css, 'grid-template-columns:repeat(2,minmax(0,1fr))' ),
		'form-box-grid-carries-modern-media-range-repeat-minmax-tracks-and-leading-dot-calc',
		$grid_box_css
	);
	$assert(
		str_contains( $grid_box_css, '.ssi-source-field-list' )
			&& str_contains( (string) ( $grid_box_row['block_markup'] ?? '' ), 'ssi-source-field-list' )
			&& ! str_contains( $grid_box_css, 'grid-column:1 / -1' ),
		'submit-beside-a-field-list-stays-outside-the-list-instead-of-spanning-its-tracks',
		wp_json_encode( array( 'css' => $grid_box_css, 'markup' => $grid_box_row['block_markup'] ?? '' ) )
	);
	$assert(
		str_contains( $grid_box_css, 'gap:calc(.25rem * 5)' )
			&& str_contains( $grid_box_css, '.ssi-source-field-list' )
			&& ! preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - \(\.25rem \* 5\)\)/', $grid_box_css ),
		'submit-outside-a-gapped-field-list-keeps-its-authored-margin-instead-of-cancelling-the-list-gap',
		$grid_box_css
	);
	$unsupported_wrapper_min_height = $grid_box_form;
	$unsupported_wrapper_min_height['forms'][0]['layout_graph']['nodes'][0]['layout']['min_height'] = '64px';
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsupported_wrapper_min_height )['errors'] ), 'current-producer-v2-layout-contract-rejects-wrapper-min-height-until-paired-producer-extension' );
	$dup_gap_form = $grid_box_form;
	$dup_gap_form['forms'][0]['layout_graph']['nodes'][0]['layout']     = array( 'gap' => 'calc(.25rem * 5)' );
	$dup_gap_form['forms'][0]['layout_graph']['nodes'][0]['provenance'] = array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.grid-fields', 'condition' => null, 'properties' => array( 'gap' ) ) );
	$dup_gap_css = (string) ( Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $dup_gap_form )['forms'] ?? array() ) )['forms'][0]['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		str_contains( $dup_gap_css, '.ssi-source-field-list' )
			&& str_contains( $dup_gap_css, 'gap:calc(.25rem * 5)' )
			&& ! preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - \(\.25rem \* 5\)\)/', $dup_gap_css ),
		'submit-beside-a-field-list-does-not-cancel-a-gap-the-form-node-already-declares',
		$dup_gap_css
	);
	$sibling_submit_css  = '.grid{display:grid}.gap-6{gap:1.5rem}'
		. '@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '.mt-9{margin-top:2.25rem}';
	$sibling_submit_html = '<style>' . $sibling_submit_css . '</style><form>'
		. '<div class="grid gap-6 sm:grid-cols-2">'
		. '<div><label>Name</label><input type="text"></div>'
		. '<div><label>Email</label><input type="email"></div>'
		. '<div><label>Message</label><textarea rows="6"></textarea></div>'
		. '</div>'
		. '<button type="submit" class="mt-9">Send message</button>'
		. '</form>';
	$sibling_submit_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $sibling_submit_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$sibling_submit_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $sibling_submit_source ) ) );
	$sibling_submit_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $sibling_submit_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$sibling_submit_overlay   = (string) ( $sibling_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $sibling_submit_validated['errors'] )
			&& 'mapped' === ( $sibling_submit_row['status'] ?? '' )
			&& true === ( $sibling_submit_row['runtime_mapped'] ?? false )
			&& str_contains( $sibling_submit_overlay, 'gap:1.5rem' )
			&& str_contains( $sibling_submit_overlay, '.ssi-source-field-list' )
			&& str_contains( (string) ( $sibling_submit_row['block_markup'] ?? '' ), 'ssi-source-field-list' )
			&& ! preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - 1\.5rem\)/', $sibling_submit_overlay )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-top:2\.25rem/', $sibling_submit_overlay )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*margin-block:0!important/', $sibling_submit_overlay ),
		'source-sibling-submit-keeps-its-authored-margin-and-does-not-also-consume-the-field-list-gap',
		wp_json_encode( array( 'validation' => $sibling_submit_validated, 'css' => $sibling_submit_overlay, 'markup' => $sibling_submit_row['block_markup'] ?? '' ) )
	);
	$assert(
		str_contains( $sibling_submit_overlay, 'display:grid' ),
		'field-list-overlay-carries-authored-grid-display',
		$sibling_submit_overlay
	);
	$assert(
		str_contains( $grid_box_css, 'display:grid' ),
		'form-box-grid-overlay-carries-authored-display',
		$grid_box_css
	);
	$col_span_css  = '.grid{display:grid}.gap-6{gap:1.5rem}'
		. '@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\\:col-span-2{grid-column:span 2 / span 2}}';
	$col_span_html = '<style>' . $col_span_css . '</style><form class="panel p-7">'
		. '<div class="grid gap-6 sm:grid-cols-2">'
		. '<div><label>Nominee name</label><input type="text"></div>'
		. '<div><label>Category</label><select><option>One</option></select></div>'
		. '<div><label>Organisation</label><input type="text"></div>'
		. '<div><label>Your name</label><input type="text"></div>'
		. '<div class="sm:col-span-2"><label>Why this nomination</label><textarea rows="6"></textarea></div>'
		. '<div class="sm:col-span-2"><label>Supporting links</label><input type="text"></div>'
		. '</div>'
		. '<button type="submit" class="mt-9">Submit nomination</button>'
		. '</form>';
	$col_span_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $col_span_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$col_span_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $col_span_source ) ) );
	$col_span_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $col_span_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$col_span_overlay   = (string) ( $col_span_row['provider_layout_overlay_css']['css'] ?? '' );
	$col_span_list_rule = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-source-field-list\{([^}]+)\}/', $col_span_overlay, $col_span_list ) ? $col_span_list[1] : '';
	$assert(
		empty( $col_span_validated['errors'] )
			&& 'mapped' === ( $col_span_row['status'] ?? '' )
			&& true === ( $col_span_row['runtime_mapped'] ?? false )
			&& str_contains( (string) ( $col_span_row['block_markup'] ?? '' ), 'ssi-source-field-list' )
			&& str_contains( $col_span_list_rule, 'display:grid' )
			&& str_contains( $col_span_list_rule, 'gap:1.5rem' )
			&& ! str_contains( $col_span_list_rule, 'grid-template-columns' ),
		'full-span-field-list-keeps-authored-display-and-leaves-column-tracks-to-the-source',
		wp_json_encode( array( 'validation' => $col_span_validated, 'css' => $col_span_overlay, 'list' => $col_span_list_rule, 'markup' => $col_span_row['block_markup'] ?? '' ) )
	);
	$class_display_form = $grid_box_form;
	$class_display_form['forms'][0]['layout_graph']['nodes'][1]['layout']     = array();
	$class_display_form['forms'][0]['layout_graph']['nodes'][1]['provenance'] = array();
	$class_display_form['forms'][0]['layout_graph']['variants']               = array();
	$class_display_form['forms'][0]['control_topology']['nodes'][0]['class']  = 'grid gap-6';
	$class_display_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $class_display_form );
	$class_display_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $class_display_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$class_display_css        = (string) ( $class_display_row['provider_layout_overlay_css']['css'] ?? '' );
	$class_display_markup     = (string) ( $class_display_row['block_markup'] ?? '' );
	$assert(
		empty( $class_display_validation['errors'] )
			&& 'mapped' === ( $class_display_row['status'] ?? '' )
			&& str_contains( $class_display_markup, 'grid gap-6' )
			&& str_contains( $class_display_markup, 'ssi-source-field-list' )
			&& str_contains( $class_display_css, 'display:grid' )
			&& str_contains( $class_display_css, '.ssi-source-field-list' )
			&& ! str_contains( $class_display_css, 'grid-template-columns' ),
		'class-only-field-list-display-outranks-the-provider-flex-default',
		wp_json_encode( array( 'css' => $class_display_css, 'markup' => $class_display_markup, 'row' => $class_display_row ) )
	);
	$flex_list_form = $grid_box_form;
	$flex_list_form['forms'][0]['layout_graph']['nodes'][1]['layout'] = array( 'display' => 'flex', 'direction' => 'column', 'gap' => '2rem' );
	$flex_list_form['forms'][0]['layout_graph']['nodes'][1]['provenance'][0]['properties'] = array( 'display', 'flex-direction', 'gap' );
	$flex_list_form['forms'][0]['layout_graph']['variants'] = array();
	$flex_list_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $flex_list_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$flex_list_css = (string) ( $flex_list_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		str_contains( $flex_list_css, 'display:flex' )
			&& str_contains( $flex_list_css, 'gap:2rem' )
			&& str_contains( $flex_list_css, '.ssi-source-field-list' )
			&& ! preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - 2rem\)/', $flex_list_css )
			&& ! str_contains( $flex_list_css, 'grid-column:1 / -1' ),
		'submit-outside-a-column-flex-field-list-keeps-its-authored-margin',
		$flex_list_css
	);
	// A captured field can carry its own two-level wrapper chain: a classless
	// outer box establishing `display: grid` (proven the same way a source
	// grid ever is, e.g. a mobile-only breakpoint's own track layout) around
	// a second, deeper box holding the label and control together. The
	// runtime always rebuilds that deeper box as the field shell's sole
	// child (`.ssi-field-row`, see
	// Static_Site_Importer_Provider_Form_Runtime::project_wrapper_classes()),
	// which is never placed on the shell's own grid tracks, so without an
	// explicit span it auto-places into a single implicit column instead of
	// the shell's full track set - collapsing the label and control inside
	// it to that one track's width. Regression for
	// https://github.com/Automattic/static-site-importer/issues/1772.
