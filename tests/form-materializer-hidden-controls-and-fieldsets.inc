<?php
/**
 * Form materializer smoke suite: deep topologies, hidden controls and fieldsets.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$deep_width_form = array(
		'selector' => 'form.deep-widths',
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First' ),
			array( 'tag' => 'input', 'type' => 'email', 'name' => 'second', 'label' => 'Second' ),
			array( 'tag' => 'input', 'type' => 'tel', 'name' => 'third', 'label' => 'Third' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	$deep_width_topology = array();
	$deep_width_graph    = array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'deep-widths' ) ), 'layout' => array(), 'provenance' => array() ) );
	$parent = null;
	$graph_parent = 'form';
	for ( $depth = 0; $depth < 9; ++$depth ) {
		$id = 'wrapper-' . $depth;
		$deep_width_topology[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $depth, 'tag' => 'div' );
		$deep_width_graph[] = array( 'id' => $id, 'kind' => 'container', 'parent' => $graph_parent, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array(), 'provenance' => array() );
		$parent = $id;
		$graph_parent = $id;
	}
	foreach ( array( 9 => 'table', 10 => 'tbody', 11 => 'tr' ) as $wrapper => $tag ) {
		$id = 'wrapper-' . $wrapper;
		$deep_width_topology[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $wrapper, 'tag' => $tag );
		$deep_width_graph[] = array( 'id' => $id, 'kind' => 'container', 'parent' => $graph_parent, 'order' => 0, 'source' => array( 'tag' => $tag, 'classes' => array() ), 'layout' => array(), 'provenance' => array() );
		$parent = $id;
		$graph_parent = $id;
	}
	foreach ( array( 12, 14, 16 ) as $column => $cell_id ) {
		$field_id = $cell_id + 1;
		$deep_width_topology[] = array( 'id' => 'wrapper-' . $cell_id, 'kind' => 'wrapper', 'parent' => 'wrapper-11', 'order' => $column, 'depth' => 12, 'tag' => 'td' );
		$deep_width_topology[] = array( 'id' => 'wrapper-' . $field_id, 'kind' => 'wrapper', 'parent' => 'wrapper-' . $cell_id, 'order' => 0, 'depth' => 13, 'tag' => 'div', 'class' => 'field' );
		$deep_width_topology[] = array( 'id' => 'control-' . $column, 'kind' => 'control', 'parent' => 'wrapper-' . $field_id, 'order' => 0, 'depth' => 14, 'control' => $column );
		$deep_width_graph[] = array(
			'id' => 'wrapper-' . $cell_id, 'kind' => 'container', 'parent' => 'wrapper-11', 'order' => $column,
			'source' => array( 'tag' => 'td', 'classes' => array() ), 'layout' => array( 'width' => '33.333333333333%' ),
			'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ),
		);
	}
	$deep_width_topology[] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 3 );
	$deep_width_form['control_topology'] = array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'nodes' => $deep_width_topology, 'truncated' => false );
	$deep_width_form['layout_graph'] = $v2_layout_graph( $deep_width_graph );
	$responsive_width_conditions = array(
		array( 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 992px)' ), 'layout_patch' => array( 'width' => '50%' ) ),
		array( 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 767px)' ), 'layout_patch' => array( 'display' => 'block', 'width' => '100%' ) ),
	);
	foreach ( array( 12, 14, 16 ) as $cell_id ) {
		foreach ( $responsive_width_conditions as $responsive_width ) {
			$condition = $responsive_width['condition'];
			$precedence = array();
			foreach ( array_keys( $responsive_width['layout_patch'] ) as $property ) {
				$precedence[ $property ] = array( 'source_order' => 1, 'specificity' => 10, 'important' => false );
			}
			$deep_width_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-' . $cell_id, 'condition' => $condition, 'layout_patch' => $responsive_width['layout_patch'], 'precedence' => $precedence, 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.column', 'condition' => $condition, 'properties' => array_keys( $responsive_width['layout_patch'] ) ) ) );
		}
	}
	$deep_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $deep_width_form ) ) );
	$deep_width_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $deep_width_validation['forms'] ?? array() ) );
	$deep_width_row = $deep_width_seed['forms'][0] ?? array();
	$deep_width_markup = (string) ( $deep_width_row['block_markup'] ?? '' );
	$deep_width_receipt = $deep_width_row['computed_layout_receipt'] ?? array();
	$assert( empty( $deep_width_validation['errors'] ) && 'mapped' === ( $deep_width_row['status'] ?? '' ) && true === ( $deep_width_row['runtime_mapped'] ?? false ), 'v2-deep-percentage-row-validates-and-materializes', wp_json_encode( array( 'validation' => $deep_width_validation, 'row' => $deep_width_row ) ) );
	$assert( 3 === substr_count( $deep_width_markup, '"width":33.333' ) && ! str_contains( $deep_width_markup, '<table' ), 'v2-deep-percentage-row-maps-three-provider-field-widths', $deep_width_markup );
	$deep_width_overlay_css = (string) ( $deep_width_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( in_array( 'provider_percentage_width_fields', array_column( $deep_width_receipt['operations'] ?? array(), 'strategy' ), true ) && empty( $deep_width_receipt['losses'] ) && empty( $deep_width_row['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $deep_width_overlay_css, '@media (max-width: 992px)' ) && str_contains( $deep_width_overlay_css, 'width:50%' ) && str_contains( $deep_width_overlay_css, 'display:block;width:100%' ), 'v2-responsive-percentage-row-has-proven-field-overlays', wp_json_encode( $deep_width_row ) );

	$unsafe_variant_form = $deep_width_form;
	$unsafe_variant_form['layout_graph']['variants'][0]['layout_patch'] = array( 'display' => 'none', 'width' => '50%' );
	$unsafe_variant_form['layout_graph']['variants'][0]['precedence']['display'] = array( 'source_order' => 1, 'specificity' => 10, 'important' => false );
	$unsafe_variant_form['layout_graph']['variants'][0]['provenance'][0]['properties'][] = 'display';
	$unsafe_variant_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unsafe_variant_form ) ) );
	$unsafe_variant_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_variant_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unsafe_variant_row['status'] ?? '' ) && ! str_contains( (string) ( $unsafe_variant_row['block_markup'] ?? '' ), '"width":33.333' ), 'unsafe-percentage-variant-fails-closed' );

	$hidden_bookkeeping_form = array(
		'selector' => 'form.runtime-controls',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'token' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
		'layout_graph' => $layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'display' => 'none' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display' ) ) ) ) ) ),
	);
	$hidden_bookkeeping_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_bookkeeping_form ) ) );
	$hidden_bookkeeping_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $hidden_bookkeeping_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $hidden_bookkeeping_row['status'] ?? '' ) && in_array( 'provider_omitted_runtime_controls', array_column( $hidden_bookkeeping_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $hidden_bookkeeping_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'hidden-runtime-bookkeeping-wrapper-is-bounded-and-receipted', wp_json_encode( $hidden_bookkeeping_row ) );
	$inline_hidden_bookkeeping_form = $hidden_bookkeeping_form;
	$inline_hidden_bookkeeping_form['controls'][0] = array( 'tag' => 'input', 'type' => 'text', 'name' => '_app_id' );
	$inline_hidden_bookkeeping_form['layout_graph']['nodes'][1]['layout'] = array( 'display' => 'none' );
	$inline_hidden_bookkeeping_form['layout_graph']['nodes'][1]['provenance'] = array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display' ) ) );
	$inline_hidden_bookkeeping_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $inline_hidden_bookkeeping_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $inline_hidden_bookkeeping_row['status'] ?? '' ) && empty( $inline_hidden_bookkeeping_row['form_receipt_unaccepted_losses'] ?? array() ) && in_array( 'provider_omitted_runtime_controls', array_column( $inline_hidden_bookkeeping_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'inline-hidden-private-text-bookkeeping-is-omitted-without-visibility-loss', wp_json_encode( $inline_hidden_bookkeeping_row ) );
	$hidden_variant_form = $hidden_bookkeeping_form;
	$hidden_variant_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'display' => 'block' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.runtime', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'display' ) ) ) );
	$hidden_variant_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_variant_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $hidden_variant_row['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', array_column( $hidden_variant_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'responsive-hidden-wrapper-remains-unrepresented' );

	$hidden_native_select = array(
		'selector' => 'form.enhanced-select',
		'controls' => array( array( 'tag' => 'select', 'type' => 'select', 'name' => 'choice', 'label' => 'Choice', 'options' => array( 'One' ) ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'replacement-shell' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ) ) ),
		'layout_graph' => $v2_layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'replacement-shell' ) ), 'layout' => array( 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.replacement-shell', 'condition' => null, 'properties' => array( 'width' ) ) ) ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'select', 'classes' => array( 'enhanced' ) ), 'layout' => array( 'display' => 'none', 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.enhanced', 'condition' => null, 'properties' => array( 'display', 'width' ) ) ) ) ) ),
	);
	$hidden_native_select_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_native_select ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $hidden_native_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility', array_column( $hidden_native_select_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! preg_match( '/\.ssi-node-[a-f0-9]{12}(?:-wrap)?(?: > [^{]+)?\{display:none\}/', (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ) ) && str_contains( (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:100%' ), 'provider-native-controls-replace-hidden-display-and-retain-other-layout', wp_json_encode( $hidden_native_select_row ) );
	$unproven_hidden_select = $hidden_native_select;
	$unproven_hidden_select['layout_graph']['nodes'][2]['provenance'][0]['selector'] = '.replacement-shell .enhanced';
	$unproven_hidden_select_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unproven_hidden_select ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unproven_hidden_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility_unrepresentable', array_column( $unproven_hidden_select_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'source-hidden-native-control-without-replacement-evidence-fails-closed', wp_json_encode( $unproven_hidden_select_row ) );

	// A custom-drawn checkbox: `.custom-check input { display:none }` hides the
	// native input and the box's sibling label draws its replacement. The box
	// becomes the provider's choice wrap, so the same source rules hide and
	// redraw the provider checkbox beside its own label.
	$custom_checkbox = array(
		'selector' => 'form.consent',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'checkbox', 'id' => 'consent-updates', 'name' => 'updates', 'value' => '1', 'label' => 'Send me updates' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div', 'class' => 'consent-row' ), array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span', 'class' => 'custom-check' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
		'layout_graph' => $v2_layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array( 'consent-row' ) ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'custom-check' ) ), 'layout' => array( 'display' => 'block' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'f', 64 ), 'selector' => '.custom-check', 'condition' => null, 'properties' => array( 'display' ) ) ) ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'display' => 'none' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'f', 64 ), 'selector' => '.custom-check input', 'condition' => null, 'properties' => array( 'display' ) ) ) ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'form', 'order' => 2, 'source' => array( 'tag' => 'button', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ) ) ),
	);
	$custom_checkbox_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $custom_checkbox ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $custom_checkbox_row['status'] ?? '' ) && empty( $custom_checkbox_row['form_receipt_unaccepted_losses'] ?? array() ) && in_array( 'provider_native_control_visibility', array_column( $custom_checkbox_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'custom-drawn-checkbox-box-proves-hidden-native-replacement', wp_json_encode( $custom_checkbox_row ) );
	$custom_checkbox_class = '';
	foreach ( parse_blocks( (string) ( $custom_checkbox_row['block_markup'] ?? '' ) ) as $custom_checkbox_block ) {
		foreach ( $custom_checkbox_block['innerBlocks'] ?? array() as $custom_checkbox_field ) {
			if ( 'jetpack/field-checkbox' === ( $custom_checkbox_field['blockName'] ?? '' ) ) {
				$custom_checkbox_class = (string) ( $custom_checkbox_field['attrs']['className'] ?? '' );
			}
		}
	}
	$custom_checkbox_tokens   = array_values( array_filter( explode( ' ', $custom_checkbox_class ) ) );
	$custom_checkbox_shell    = '<div class="grunion-field-wrap grunion-field-checkbox-wrap ' . implode( '-wrap ', $custom_checkbox_tokens ) . '-wrap"><div class=\'contact-form__checkbox-wrap\'><input id=\'consent-updates\' type=\'checkbox\' name=\'updates\' value=\'Yes\' class=\'checkbox\'/> ' . "\n" . '<label for=\'consent-updates\' class=\'grunion-field-label checkbox\'>Send me updates</label></div></div>';
	$custom_checkbox_rendered = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $custom_checkbox_shell );
	$assert( in_array( 'ssi-source-choice-box--custom-check', $custom_checkbox_tokens, true ) && 1 === preg_match( '/<div class="[^"]*\bcontact-form__checkbox-wrap\b[^"]*\bcustom-check\b[^"]*">\s*<input[^>]*type="checkbox"[^>]*>\s*<label for="consent-updates"/', $custom_checkbox_rendered ), 'custom-drawn-checkbox-box-wraps-provider-input-and-sibling-label', $custom_checkbox_rendered );
	$unboxed_checkbox = $custom_checkbox;
	$unboxed_checkbox['layout_graph']['nodes'][4]['provenance'][0]['selector'] = '.consent-row input';
	$unboxed_checkbox_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unboxed_checkbox ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unboxed_checkbox_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility_unrepresentable', array_column( $unboxed_checkbox_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'hidden-checkbox-keyed-on-a-non-enclosing-ancestor-fails-closed', wp_json_encode( $unboxed_checkbox_row ) );
	$unlabelled_checkbox = $custom_checkbox;
	unset( $unlabelled_checkbox['controls'][1]['label'] );
	$unlabelled_checkbox_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unlabelled_checkbox ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unlabelled_checkbox_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility_unrepresentable', array_column( $unlabelled_checkbox_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'hidden-checkbox-without-a-label-to-draw-its-replacement-fails-closed', wp_json_encode( $unlabelled_checkbox_row ) );

	$partial_width_form = $deep_width_form;
	$partial_width_form['layout_graph']['nodes'][13]['layout']['width'] = '30%';
	$partial_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $partial_width_form ) ) );
	$partial_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $partial_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $partial_width_row['status'] ?? '' ) && ! str_contains( (string) ( $partial_width_row['block_markup'] ?? '' ), '"width":33.333' ) && ! str_contains( (string) ( $partial_width_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:50%' ), 'partial-percentage-row-fails-closed' );
	$multiple_controls_form = $deep_width_form;
	$multiple_controls_form['control_topology']['nodes'][21]['parent'] = 'wrapper-12';
	$multiple_controls_form['control_topology']['nodes'][21]['order'] = 1;
	$multiple_controls_form['control_topology']['nodes'][21]['depth'] = 13;
	$multiple_controls_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $multiple_controls_form ) ) );
	$multiple_controls_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $multiple_controls_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $multiple_controls_validation['errors'] ) && 'skipped' === ( $multiple_controls_row['status'] ?? '' ) && ! str_contains( (string) ( $multiple_controls_row['block_markup'] ?? '' ), '"width":33.333' ), 'multiple-controls-in-percentage-branch-fail-closed' );
	$v1_with_width = $deep_width_form;
	$v1_with_width['layout_graph']['schema'] = 'generic/computed-layout-graph/v1';
	$v1_with_width['layout_graph']['limits']['depth'] = 8;
	$v1_with_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $v1_with_width ) ) );
	$assert( empty( $v1_with_width_validation['forms'] ) && str_contains( (string) ( $v1_with_width_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'v1-graph-rejects-v2-width-vocabulary' );
	$v1_depth_16 = $topology_form;
	$v1_depth_16['forms'][0]['layout_graph']['limits']['depth'] = 16;
	$v1_depth_16_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $v1_depth_16 );
	$assert( empty( $v1_depth_16_validation['forms'] ) && str_contains( (string) ( $v1_depth_16_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'v1-graph-rejects-v2-depth-limit' );
	$v2_depth_8 = $deep_width_form;
	$v2_depth_8['layout_graph']['limits']['depth'] = 8;
	$v2_depth_8_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $v2_depth_8 ) ) );
	$assert( empty( $v2_depth_8_validation['forms'] ) && str_contains( (string) ( $v2_depth_8_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'v2-graph-rejects-v1-depth-limit' );
	$unproven_table_form = $deep_width_form;
	$unproven_table_form['layout_graph']['nodes'][13]['provenance'] = array();
	$unproven_table_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unproven_table_form ) ) );
	$unproven_table_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unproven_table_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$unproven_reasons = array_column( $unproven_table_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( 'skipped' === ( $unproven_table_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $unproven_reasons, true ), 'unproven-table-semantics-remain-gated', wp_json_encode( $unproven_table_row ) );
	$labelled_width_form = $deep_width_form;
	$labelled_width_form['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$labelled_width_form['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_width_form['layout_graph']['nodes'][1]['source']['tag'] = 'fieldset';
	$labelled_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $labelled_width_form ) ) );
	$labelled_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$labelled_width_reasons = array_column( $labelled_width_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( 'skipped' === ( $labelled_width_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $labelled_width_reasons, true ) && 3 === substr_count( (string) ( $labelled_width_row['block_markup'] ?? '' ), '"width":33.333' ), 'percentage-width-proof-does-not-accept-labelled-fieldset-semantics', wp_json_encode( $labelled_width_row ) );
	$placeholder_email_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array(
			'forms' => array(
				array(
					'selector' => 'form.newsletter',
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'placeholder' => 'Email Address' ),
						array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Claim My Reward' ),
					),
					'control_topology' => array(
						'schema'    => 'generic/form-control-topology/v1',
						'max_depth' => 8,
						'max_nodes' => 128,
						'truncated' => false,
						'nodes'     => array(
							array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
							array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
						),
					),
				),
			),
		)
	);
	$placeholder_email_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $placeholder_email_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$placeholder_email_markup = (string) ( $placeholder_email_row['block_markup'] ?? '' );
	$assert( empty( $placeholder_email_validation['errors'] ) && 'mapped' === ( $placeholder_email_row['status'] ?? '' ) && str_contains( $placeholder_email_markup, '"placeholder":"Email Address"' ) && ! str_contains( $placeholder_email_markup, '"label":"Email Address"' ), 'placeholder-only email fields keep placeholder without inventing a label', $placeholder_email_markup );
	$name_fieldset_form = array(
		'forms' => array(
			array(
				'selector'         => 'form.contact',
				'form'             => array( 'class' => 'react-form-contents' ),
				'controls'         => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'fname', 'label' => 'First Name' ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'lname', 'label' => 'Last Name' ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'control_topology' => array(
					'schema'     => 'generic/form-control-topology/v1',
					'max_depth'  => 8,
					'max_nodes'  => 128,
					'truncated'  => false,
					'nodes'      => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'form-item fields name', 'fieldset_semantics' => 'labelled_group' ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'field first-name' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'control' => 0 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'tag' => 'div', 'class' => 'field last-name' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'div', 'class' => 'field email' ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-4', 'order' => 0, 'depth' => 2, 'control' => 2 ),
						array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'control' => 3 ),
					),
				),
				'layout_graph'     => $v2_layout_graph(
					array(
						$layout_node( 'form', array(), 'form' ),
						array(
							'id'         => 'wrapper-0',
							'kind'       => 'container',
							'parent'     => 'form',
							'order'      => 0,
							'source'     => array( 'tag' => 'div', 'classes' => array( 'field-list' ) ),
							'layout'     => array(),
							'provenance' => array(),
						),
						array(
							'id'         => 'wrapper-1',
							'kind'       => 'container',
							'parent'     => 'wrapper-0',
							'order'      => 0,
							'source'     => array( 'tag' => 'fieldset', 'classes' => array( 'form-item', 'fields', 'name' ) ),
							'layout'     => array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ),
							'provenance' => array(
								array(
									'source_path'  => 'assets/form.css',
									'source_sha256' => str_repeat( 'e', 64 ),
									'selector'     => '.form-item.fields.name',
									'condition'    => null,
									'properties'   => array( 'display', 'flex-direction', 'gap' ),
								),
							),
						),
					)
				),
			),
		),
	);
	$name_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $name_fieldset_form );
	$name_fieldset_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $name_fieldset_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$name_fieldset_reasons    = array_column( $name_fieldset_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$name_fieldset_ops        = array_column( $name_fieldset_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $name_fieldset_validation['errors'] ) && 'mapped' === ( $name_fieldset_row['status'] ?? '' ) && true === ( $name_fieldset_row['runtime_mapped'] ?? false ) && in_array( 'provider_labelled_text_fieldset_projection', $name_fieldset_ops, true ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), $name_fieldset_reasons ) && str_contains( (string) ( $name_fieldset_row['block_markup'] ?? '' ), 'First Name' ) && str_contains( (string) ( $name_fieldset_row['block_markup'] ?? '' ), 'Last Name' ), 'nested-labelled-name-fieldset-materializes-without-semantic-loss', wp_json_encode( $name_fieldset_row ) );
	$phone_fieldset_form = array(
		'forms' => array(
			array(
				'selector'         => 'form.contact',
				'controls'         => array(
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'control_topology' => array(
					'schema'    => 'generic/form-control-topology/v1',
					'max_depth' => 8,
					'max_nodes' => 128,
					'truncated' => false,
					'nodes'     => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div', 'class' => 'field email' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'form-item fields phone', 'fieldset_semantics' => 'labelled_group' ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'field' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'control' => 2 ),
					),
				),
				'layout_graph'     => $v2_layout_graph(
					array(
						$layout_node( 'form', array(), 'form' ),
						array(
							'id'         => 'wrapper-2',
							'kind'       => 'container',
							'parent'     => 'form',
							'order'      => 1,
							'source'     => array( 'tag' => 'fieldset', 'classes' => array( 'form-item', 'fields', 'phone' ) ),
							'layout'     => array(),
							'provenance' => array(),
						),
					)
				),
			),
		),
	);
	$phone_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_fieldset_form );
	$phone_fieldset_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $phone_fieldset_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_fieldset_reasons    = array_column( $phone_fieldset_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$phone_fieldset_ops        = array_column( $phone_fieldset_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $phone_fieldset_validation['errors'] ) && 'mapped' === ( $phone_fieldset_row['status'] ?? '' ) && true === ( $phone_fieldset_row['runtime_mapped'] ?? false ) && in_array( 'provider_labelled_text_fieldset_projection', $phone_fieldset_ops, true ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), $phone_fieldset_reasons ), 'nested-labelled-phone-fieldset-without-legend-materializes', wp_json_encode( $phone_fieldset_row ) );
	$plain_fieldset_form = array(
		'forms' => array(
			array(
				'selector'         => 'form.contact',
				'controls'         => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'id' => 'name', 'label' => 'Name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'id' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'id' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
				),
				'control_topology' => array(
					'schema'    => 'generic/form-control-topology/v1',
					'max_depth' => 8,
					'max_nodes' => 128,
					'truncated' => false,
					'nodes'     => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'fields' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 2, 'control' => 2 ),
						array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 3, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'fields-item', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-4', 'order' => 0, 'depth' => 2, 'control' => 3 ),
						array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 4, 'depth' => 0, 'control' => 4 ),
					),
				),
				'layout_graph'     => $v2_layout_graph(
					array(
						$layout_node( 'form', array(), 'form' ),
					)
				),
			),
		),
	);
	$plain_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $plain_fieldset_form );
	$plain_fieldset_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $plain_fieldset_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$plain_fieldset_reasons    = array_column( $plain_fieldset_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$plain_fieldset_ops        = array_column( $plain_fieldset_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$plain_fieldset_markup     = (string) ( $plain_fieldset_row['block_markup'] ?? '' );
	$plain_fieldset_targets    = array_column(
		array_filter( $plain_fieldset_row['computed_layout_receipt']['operations'] ?? array(), static fn( array $operation ): bool => 'provider_plain_single_field_fieldset_projection' === ( $operation['strategy'] ?? '' ) ),
		'target_hash'
	);
	$assert( empty( $plain_fieldset_validation['errors'] ) && 'mapped' === ( $plain_fieldset_row['status'] ?? '' ) && true === ( $plain_fieldset_row['runtime_mapped'] ?? false ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), $plain_fieldset_reasons ), 'nested-plain-single-field-fieldsets-materialize-without-semantic-loss', wp_json_encode( $plain_fieldset_row ) );
	$assert( 4 === count( $plain_fieldset_targets ) && 4 === count( array_unique( $plain_fieldset_targets ) ) && array_values( array_intersect( $plain_fieldset_targets, array( hash( 'sha256', 'wrapper-1' ), hash( 'sha256', 'wrapper-2' ), hash( 'sha256', 'wrapper-3' ), hash( 'sha256', 'wrapper-4' ) ) ) ) === array( hash( 'sha256', 'wrapper-1' ), hash( 'sha256', 'wrapper-2' ), hash( 'sha256', 'wrapper-3' ), hash( 'sha256', 'wrapper-4' ) ), 'nested-plain-fieldset-receipt-represents-exactly-the-four-source-fieldsets', wp_json_encode( $plain_fieldset_ops ) );
	$assert( str_contains( $plain_fieldset_markup, 'Name' ) && str_contains( $plain_fieldset_markup, 'Email' ) && str_contains( $plain_fieldset_markup, 'Phone' ) && str_contains( $plain_fieldset_markup, 'Message' ) && str_contains( $plain_fieldset_markup, 'wp:jetpack/field-telephone' ) && str_contains( $plain_fieldset_markup, 'wp:jetpack/field-textarea' ), 'nested-plain-fieldset-fields-stay-editable-provider-blocks-with-labels', $plain_fieldset_markup );
	$assert( $plain_fieldset_markup === serialize_blocks( parse_blocks( $plain_fieldset_markup ) ), 'nested-plain-fieldset-markup-round-trips-through-wordpress-block-parser', $plain_fieldset_markup );
	$assert( ! in_array( 'provider_plain_single_field_fieldset_projection', $name_fieldset_ops, true ), 'labelled-text-fieldset-is-not-consumed-by-plain-single-field-projection', wp_json_encode( $name_fieldset_ops ) );
	Static_Site_Importer_Provider_Form_Runtime_V1::register();
	$plain_fieldset_form_block = null;
	foreach ( parse_blocks( $plain_fieldset_markup ) as $plain_fieldset_candidate ) {
		if ( 'jetpack/contact-form' === ( $plain_fieldset_candidate['blockName'] ?? '' ) ) {
			$plain_fieldset_form_block = $plain_fieldset_candidate;
			break;
		}
	}
	$plain_fieldset_fields = array_values( array_filter( is_array( $plain_fieldset_form_block['innerBlocks'] ?? null ) ? $plain_fieldset_form_block['innerBlocks'] : array(), static fn( array $block ): bool => str_starts_with( (string) ( $block['blockName'] ?? '' ), 'jetpack/field-' ) ) );
	$plain_fieldset_rendered = array();
	foreach ( $plain_fieldset_fields as $plain_fieldset_field ) {
		$plain_fieldset_class_name = (string) ( $plain_fieldset_field['attrs']['className'] ?? '' );
		$plain_fieldset_tokens     = array_values( array_filter( explode( ' ', $plain_fieldset_class_name ) ) );
		$plain_fieldset_wrap       = array() === $plain_fieldset_tokens ? '' : implode( '-wrap ', $plain_fieldset_tokens ) . '-wrap';
		$plain_fieldset_type       = substr( (string) ( $plain_fieldset_field['blockName'] ?? '' ), strlen( 'jetpack/field-' ) );
		$plain_fieldset_label      = '';
		foreach ( $plain_fieldset_field['innerBlocks'] ?? array() as $plain_fieldset_inner ) {
			if ( 'jetpack/label' === ( $plain_fieldset_inner['blockName'] ?? '' ) ) {
				$plain_fieldset_label = (string) ( $plain_fieldset_inner['attrs']['label'] ?? '' );
			}
		}
		$plain_fieldset_control = 'textarea' === $plain_fieldset_type ? '<textarea name="message"></textarea>' : '<input type="' . ( 'telephone' === $plain_fieldset_type ? 'tel' : ( 'email' === $plain_fieldset_type ? 'email' : 'text' ) ) . '">';
		$plain_fieldset_shell   = '<div class="grunion-field-' . $plain_fieldset_type . '-wrap ' . $plain_fieldset_wrap . '"><label>' . $plain_fieldset_label . '</label>' . $plain_fieldset_control . '</div>';
		$plain_fieldset_rendered[] = apply_filters( 'grunion_contact_form_field_html', $plain_fieldset_shell, $plain_fieldset_label, null );
	}
	$plain_fieldset_message_class = '';
	if ( preg_match( '/^<fieldset\b([^>]*)>/', (string) ( $plain_fieldset_rendered[3] ?? '' ), $plain_fieldset_open ) ) {
		$plain_fieldset_message_class = 1 === preg_match( '/\bclass=(["\'])(.*?)\1/', $plain_fieldset_open[1], $plain_fieldset_class_match ) ? $plain_fieldset_class_match[2] : '';
	}
	$assert( 4 === count( $plain_fieldset_rendered ) && array( 'Name', 'Email', 'Phone', 'Message' ) === array_map( static fn( string $html ): string => 1 === preg_match( '/<label>(.*?)<\/label>/', $html, $label_match ) ? $label_match[1] : '', $plain_fieldset_rendered ), 'rendered-provider-fields-keep-source-label-order', wp_json_encode( $plain_fieldset_rendered ) );
	$assert( 4 === count( array_filter( $plain_fieldset_rendered, static fn( string $html ): bool => str_starts_with( $html, '<fieldset' ) && str_contains( $html, '<label>' ) && ! str_contains( $html, 'ssi-source-semantic-wrapper' ) ) ) && 'fields-item' === $plain_fieldset_message_class && str_contains( (string) ( $plain_fieldset_fields[3]['attrs']['className'] ?? '' ), 'ssi-source-semantic-wrapper-1--fieldset--fields-item' ), 'provider-filter-restores-fieldset-ancestry-and-safe-classes', wp_json_encode( $plain_fieldset_rendered ) );
	$assert( $plain_fieldset_rendered === array_map( static fn( string $html ): string => apply_filters( 'grunion_contact_form_field_html', $html, '', null ), $plain_fieldset_rendered ), 'rendered-fieldset-restoration-is-idempotent' );
	$nested_plain_fieldset_form = $plain_fieldset_form;
	$nested_plain_fieldset_form['forms'][0]['controls'] = array(
		array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	);
	$nested_plain_fieldset_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'outer-group', 'fieldset_semantics' => 'plain_group' ),
		array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'fieldset', 'class' => 'inner-group', 'fieldset_semantics' => 'plain_group' ),
		array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'tag' => 'p', 'class' => 'row' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 4, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
	);
	$nested_plain_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_plain_fieldset_form );
	$nested_plain_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $nested_plain_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_plain_hashes     = array_column( array_filter( $nested_plain_row['computed_layout_receipt']['operations'] ?? array(), static fn( array $operation ): bool => 'provider_plain_single_field_fieldset_projection' === ( $operation['strategy'] ?? '' ) ), 'target_hash' );
	$nested_plain_block      = null;
	foreach ( parse_blocks( (string) ( $nested_plain_row['block_markup'] ?? '' ) ) as $nested_plain_candidate ) {
		if ( 'jetpack/contact-form' === ( $nested_plain_candidate['blockName'] ?? '' ) ) {
			$nested_plain_block = $nested_plain_candidate;
			break;
		}
	}
	$nested_plain_field = null;
	foreach ( is_array( $nested_plain_block['innerBlocks'] ?? null ) ? $nested_plain_block['innerBlocks'] : array() as $nested_plain_inner ) {
		if ( str_starts_with( (string) ( $nested_plain_inner['blockName'] ?? '' ), 'jetpack/field-' ) ) {
			$nested_plain_field = $nested_plain_inner;
			break;
		}
	}
	$nested_plain_tokens = array_values( array_filter( explode( ' ', (string) ( $nested_plain_field['attrs']['className'] ?? '' ) ) ) );
	$nested_plain_wrap   = array() === $nested_plain_tokens ? '' : implode( '-wrap ', $nested_plain_tokens ) . '-wrap';
	$nested_plain_rendered = apply_filters( 'grunion_contact_form_field_html', '<div class="grunion-field-email-wrap ' . $nested_plain_wrap . '"><label>Email</label><input type="email"></div>', 'Email', null );
	$assert( empty( $nested_plain_validation['errors'] ) && 'mapped' === ( $nested_plain_row['status'] ?? '' ) && array( hash( 'sha256', 'wrapper-1' ), hash( 'sha256', 'wrapper-2' ) ) === array_values( $nested_plain_hashes ) && 1 === preg_match( '/^<fieldset class="outer-group"><fieldset class="inner-group"><div class="grunion-field-email-wrap\b/', $nested_plain_rendered ) && 1 === preg_match( '/<div class="row ssi-node-[a-f0-9]{12}">/', $nested_plain_rendered ) && ! str_contains( $nested_plain_rendered, '<p' ) && str_contains( $nested_plain_rendered, '<label>Email</label>' ) && ! str_contains( $nested_plain_rendered, 'ssi-source-semantic-wrapper' ), 'nested-plain-fieldset-ancestry-restores-outer-fieldset-then-inner-fieldset-and-keeps-the-paragraph-class-on-the-field-row', $nested_plain_rendered );
	$peer_root_fieldset_form = array(
		'forms' => array(
			array(
				'selector'          => 'form.inquiry',
				'controls'          => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'id' => 'name', 'label' => 'Name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'id' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'id' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
				),
				'control_topology'  => array(
					'schema'    => 'generic/form-control-topology/v1',
					'max_depth' => 8,
					'max_nodes' => 128,
					'truncated' => false,
					'nodes'     => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 1, 'control' => 1 ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => null, 'order' => 2, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 1, 'control' => 2 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => null, 'order' => 3, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
						array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 1, 'control' => 3 ),
						array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 4, 'depth' => 0, 'control' => 4 ),
					),
				),
				'sibling_relations' => array( 'schema' => 'generic/form-sibling-relations/v1', 'max_pairs' => 128, 'truncated' => false, 'pairs' => array( array( 'control' => 0 ), array( 'control' => 1 ), array( 'control' => 2 ), array( 'control' => 3 ) ) ),
				'layout_graph'      => $v2_layout_graph( array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'inquiry' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => '1.2rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.inquiry', 'condition' => null, 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) ) ) ),
			),
		),
	);
	$peer_root_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $peer_root_fieldset_form );
	$peer_root_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $peer_root_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$peer_root_ops        = array_column( $peer_root_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$peer_root_targets    = array_column( array_filter( $peer_root_row['computed_layout_receipt']['operations'] ?? array(), static fn( array $operation ): bool => 'provider_plain_single_field_fieldset_projection' === ( $operation['strategy'] ?? '' ) ), 'target_hash' );
	$peer_root_css        = (string) ( $peer_root_row['provider_layout_overlay_css']['css'] ?? '' );
	$peer_root_block      = null;
	foreach ( parse_blocks( (string) ( $peer_root_row['block_markup'] ?? '' ) ) as $peer_root_candidate ) {
		if ( 'jetpack/contact-form' === ( $peer_root_candidate['blockName'] ?? '' ) ) {
			$peer_root_block = $peer_root_candidate;
			break;
		}
	}
	$peer_root_fields = array_values( array_filter( is_array( $peer_root_block['innerBlocks'] ?? null ) ? $peer_root_block['innerBlocks'] : array(), static fn( array $block ): bool => str_starts_with( (string) ( $block['blockName'] ?? '' ), 'jetpack/field-' ) ) );
	$peer_root_rendered = array();
	foreach ( $peer_root_fields as $peer_root_field ) {
		$peer_root_tokens = array_values( array_filter( explode( ' ', (string) ( $peer_root_field['attrs']['className'] ?? '' ) ) ) );
		$peer_root_wrap   = array() === $peer_root_tokens ? '' : implode( '-wrap ', $peer_root_tokens ) . '-wrap';
		$peer_root_type   = substr( (string) ( $peer_root_field['blockName'] ?? '' ), strlen( 'jetpack/field-' ) );
		$peer_root_label  = '';
		foreach ( $peer_root_field['innerBlocks'] ?? array() as $peer_root_inner ) {
			if ( 'jetpack/label' === ( $peer_root_inner['blockName'] ?? '' ) ) {
				$peer_root_label = (string) ( $peer_root_inner['attrs']['label'] ?? '' );
			}
		}
		$peer_root_control = 'textarea' === $peer_root_type ? '<textarea name="message"></textarea>' : '<input type="' . ( 'telephone' === $peer_root_type ? 'tel' : ( 'email' === $peer_root_type ? 'email' : 'text' ) ) . '">';
		$peer_root_rendered[] = apply_filters( 'grunion_contact_form_field_html', '<div class="grunion-field-' . $peer_root_type . '-wrap ' . $peer_root_wrap . '"><label>' . $peer_root_label . '</label>' . $peer_root_control . '</div>', $peer_root_label, null );
	}
	$assert( empty( $peer_root_validation['errors'] ) && 'mapped' === ( $peer_root_row['status'] ?? '' ) && true === ( $peer_root_row['runtime_mapped'] ?? false ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), array_column( $peer_root_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) ) && array( hash( 'sha256', 'wrapper-0' ), hash( 'sha256', 'wrapper-1' ), hash( 'sha256', 'wrapper-2' ), hash( 'sha256', 'wrapper-3' ) ) === array_values( $peer_root_targets ) && ! in_array( 'provider_plain_root_fieldset_projection', $peer_root_ops, true ) && in_array( 'provider_direct_label_control_gap', $peer_root_ops, true ) && str_contains( $peer_root_css, 'gap:1.2rem' ), 'peer-root-plain-single-field-fieldsets-materialize-and-keep-label-gap', wp_json_encode( $peer_root_row ) );
	$assert( array( 'Name', 'Email', 'Phone', 'Message' ) === array_map( static fn( string $html ): string => 1 === preg_match( '/<label>(.*?)<\/label>/', $html, $label_match ) ? $label_match[1] : '', $peer_root_rendered ) && 4 === count( array_filter( $peer_root_rendered, static fn( string $html ): bool => 1 === substr_count( $html, '<fieldset' ) && str_starts_with( $html, '<fieldset>' ) && ! str_contains( $html, 'ssi-source-semantic-wrapper' ) ) ), 'peer-root-rendered-fieldset-is-exactly-one-ancestor-per-labelled-control', wp_json_encode( $peer_root_rendered ) );
	$lone_root_fieldset_form = $peer_root_fieldset_form;
	$lone_root_fieldset_form['forms'][0]['controls'] = array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ) );
	$lone_root_fieldset_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
	);
	$lone_root_fieldset_form['forms'][0]['sibling_relations']['pairs'] = array( array( 'control' => 0 ) );
	$lone_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $lone_root_fieldset_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$lone_root_ops = array_column( $lone_root_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( 'mapped' === ( $lone_root_row['status'] ?? '' ) && in_array( 'provider_plain_root_fieldset_projection', $lone_root_ops, true ) && ! in_array( 'provider_plain_single_field_fieldset_projection', $lone_root_ops, true ), 'all-controls-root-fieldset-is-not-duplicated-as-a-single-field-wrapper', wp_json_encode( $lone_root_ops ) );
	$ambiguous_plain_fieldset_form = $plain_fieldset_form;
	$ambiguous_plain_fieldset_form['forms'][0]['controls'] = array(
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First' ),
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'last', 'label' => 'Last' ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	);
	$ambiguous_plain_fieldset_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'fields' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'control' => 1 ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ),
	);
	$ambiguous_plain_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $ambiguous_plain_fieldset_form );
	$ambiguous_plain_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $ambiguous_plain_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$ambiguous_plain_reasons    = array_column( $ambiguous_plain_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( empty( $ambiguous_plain_validation['errors'] ) && 'skipped' === ( $ambiguous_plain_row['status'] ?? '' ) && 'form_receipt_loss_unaccepted' === ( $ambiguous_plain_row['reason'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $ambiguous_plain_reasons, true ), 'multi-field-plain-fieldset-remains-semantically-gated', wp_json_encode( $ambiguous_plain_row ) );
	$select_plain_fieldset_form = $plain_fieldset_form;
	$select_plain_fieldset_form['forms'][0]['controls'] = array(
		array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'Support', 'value' => 'support' ) ) ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	);
	$select_plain_fieldset_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'fields' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'fieldset_semantics' => 'plain_group' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
	);
	$select_plain_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $select_plain_fieldset_form );
	$select_plain_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $select_plain_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$select_plain_reasons    = array_column( $select_plain_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( empty( $select_plain_validation['errors'] ) && 'skipped' === ( $select_plain_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $select_plain_reasons, true ), 'plain-fieldset-around-unsupported-control-remains-gated', wp_json_encode( $select_plain_row ) );
	$neutral_div_fieldset_form = $plain_fieldset_form;
	foreach ( $neutral_div_fieldset_form['forms'][0]['control_topology']['nodes'] as &$neutral_div_node ) {
		if ( 'fieldset' === ( $neutral_div_node['tag'] ?? '' ) ) {
			$neutral_div_node['tag'] = 'div';
			unset( $neutral_div_node['fieldset_semantics'], $neutral_div_node['class'] );
		}
	}
	unset( $neutral_div_node );
	$neutral_div_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $neutral_div_fieldset_form );
	$neutral_div_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $neutral_div_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$neutral_div_ops        = array_column( $neutral_div_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $neutral_div_validation['errors'] ) && 'mapped' === ( $neutral_div_row['status'] ?? '' ) && ! in_array( 'unsupported_semantic_wrapper', array_column( $neutral_div_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ) && ! in_array( 'provider_plain_single_field_fieldset_projection', $neutral_div_ops, true ) && ! str_contains( (string) ( $neutral_div_row['block_markup'] ?? '' ), 'ssi-source-semantic-wrapper-' ), 'neutral-div-field-wrappers-stay-flat-without-fieldset-projection', wp_json_encode( $neutral_div_row ) );
	$unlabelled_plain_fieldset_form = $select_plain_fieldset_form;
	$unlabelled_plain_fieldset_form['forms'][0]['controls'][0] = array( 'tag' => 'input', 'type' => 'text', 'name' => 'name' );
	$unlabelled_plain_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unlabelled_plain_fieldset_form );
	$unlabelled_plain_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unlabelled_plain_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$unlabelled_plain_ops        = array_column( $unlabelled_plain_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $unlabelled_plain_validation['errors'] ) && 'skipped' === ( $unlabelled_plain_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', array_column( $unlabelled_plain_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ) && ! in_array( 'provider_plain_single_field_fieldset_projection', $unlabelled_plain_ops, true ), 'plain-fieldset-around-unlabelled-control-remains-gated', wp_json_encode( $unlabelled_plain_row ) );
	$labelled_textarea_form = $select_plain_fieldset_form;
	$labelled_textarea_form['forms'][0]['controls'][0] = array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' );
	$labelled_textarea_form['forms'][0]['control_topology']['nodes'][1]['fieldset_semantics'] = 'labelled_group';
	$labelled_textarea_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_textarea_form );
	$labelled_textarea_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_textarea_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$labelled_textarea_ops        = array_column( $labelled_textarea_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $labelled_textarea_validation['errors'] ) && 'skipped' === ( $labelled_textarea_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', array_column( $labelled_textarea_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ) && ! array_intersect( array( 'provider_plain_single_field_fieldset_projection', 'provider_labelled_text_fieldset_projection' ), $labelled_textarea_ops ), 'labelled-textarea-fieldset-remains-semantically-gated', wp_json_encode( $labelled_textarea_row ) );
	$deep_topology_form = $topology_form;
	$deep_nodes         = array();
	$parent             = null;
	for ( $depth = 0; $depth < 9; ++$depth ) {
		$id           = 'wrapper-deep-' . $depth;
		$deep_nodes[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $depth, 'tag' => 'div' );
		$parent       = $id;
	}
	$deep_nodes[] = array( 'id' => 'control-deep-0', 'kind' => 'control', 'parent' => $parent, 'order' => 0, 'depth' => 9, 'control' => 0 );
	for ( $control = 1; $control < 4; ++$control ) {
		$deep_nodes[] = array( 'id' => 'control-deep-' . $control, 'kind' => 'control', 'parent' => null, 'order' => $control, 'depth' => 0, 'control' => $control );
	}
	$deep_topology_form['forms'][0]['control_topology'] = array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'nodes' => $deep_nodes, 'truncated' => false );
	$validated_deep_topology = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $deep_topology_form );
	$deep_topology_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_deep_topology['forms'] ?? array() ) );
	$assert( empty( $validated_deep_topology['errors'] ) && 1 === count( $deep_topology_seed['forms'] ?? array() ), 'depth-nine-topology-validates-and-materializes' );
	$overdeep_topology_form = $deep_topology_form;
	$overdeep_topology_form['forms'][0]['control_topology']['max_depth'] = 17;
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $overdeep_topology_form )['errors'] ), 'topology-depth-above-supported-bound-rejects' );
	$provider_map = $topology_seed['forms'][0]['provider_layout_target_map'] ?? array();
	$provider_map_selectors = array_column( is_array( $provider_map['targets'] ?? null ) ? $provider_map['targets'] : array(), 'selector' );
	$assert( 'generic/provider-layout-target-map/v1' === ( $provider_map['schema'] ?? '' ) && 2 <= preg_match_all( '/^\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap$/m', implode( "\n", $provider_map_selectors ) ), 'equal-width-field-shells-are-the-flattened-row-overlay-targets', wp_json_encode( $provider_map ) );
	$class_owned_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $class_owned_form ) ) );
	$class_owned_losses = $class_owned_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$class_owned_markup = (string) ( $class_owned_seed['forms'][0]['block_markup'] ?? '' );
	$assert( ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $class_owned_losses, 'reason_code' ), true ) && str_contains( $class_owned_markup, 'ssi-source-wrapper-1\u002d\u002dfield' ), 'class-owned-single-field-layout-projects-with-provider-suffixed-wrapper-hook', $class_owned_markup );
	$classless_owned_form = $class_owned_form;
	$classless_owned_form['control_topology']['nodes'][1]['tag'] = 'div';
	$classless_owned_form['control_topology']['nodes'][1]['class'] = '';
	$classless_owned_form['layout_graph']['nodes'][1]['source']['classes'] = array();
	$classless_owned_form['layout_graph']['nodes'][1]['provenance'][0] = array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'flex-direction' ) );
	$classless_owned_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $classless_owned_form ) ) );
	$classless_owned_losses = $classless_owned_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$classless_owned_markup = (string) ( $classless_owned_seed['forms'][0]['block_markup'] ?? '' );
	$assert( ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $classless_owned_losses, 'reason_code' ), true ) && str_contains( $classless_owned_markup, 'ssi-source-wrapper-1\u002d\u002dssi-node-' ), 'proven-classless-single-field-layout-projects-through-a-generated-wrapper-hook', $classless_owned_markup );
	$projected_wrapper = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper--field-wrap"><input class="ssi-source-wrapper--field source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="ssi-field-row field"><input class="source-input"></div></div>' === $projected_wrapper, 'provider-runtime-rebuilds-source-wrapper-inside-field-shell', $projected_wrapper );
	$layout_wrapper = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap ssi-node-123456789abc-wrap ssi-source-wrapper--field-wrap"><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap ssi-node-123456789abc-wrap"><div class="ssi-field-row field"><input class="source-input"></div></div>' === $layout_wrapper, 'provider-runtime-keeps-the-generated-layout-hook-on-the-field-shell', $layout_wrapper );
	$layered_wrapper = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper-6--carrier-wrap ssi-source-wrapper-8--input-shell-wrap"><label>Name</label><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="ssi-field-row carrier"><label>Name</label><div class="input-shell"><input class="source-input"></div></div></div>' === $layered_wrapper, 'provider-runtime-keeps-the-label-inside-the-outermost-source-wrapper', $layered_wrapper );
	$projected_controls = implode( '', array_map( array( Static_Site_Importer_Provider_Form_Runtime_V1::class, 'project_wrapper_classes' ), array( '<div class="grunion-field-text-wrap ssi-source-wrapper-2--control-shell-wrap"><input class="control-hook"></div>', '<div class="grunion-field-textarea-wrap ssi-source-wrapper-2--control-shell-wrap"><textarea class="control-hook"></textarea></div>', '<div class="grunion-field-select-wrap ssi-source-wrapper-2--control-shell-wrap"><select class="control-hook"><option>One</option></select></div>' ) ) );
	$assert( 3 === substr_count( $projected_controls, '<div class="ssi-field-row control-shell">' ) && 3 === substr_count( $projected_controls, 'class="control-hook"' ) && ! str_contains( $projected_controls, 'ssi-source-wrapper-' ), 'wrapper-projection-preserves-input-textarea-and-select-control-relationships', $projected_controls );
	$phone_wrapper = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_wrapper, '<div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div>' ) && str_contains( $phone_wrapper, '<div class="control-shell"><input class="jetpack-field__input-element" type="tel"></div><input type="hidden" name="full-phone">' ), 'phone-wrapper-restoration-targets-value-control-without-wrapping-country-search-or-hidden-value', $phone_wrapper );
	$assert( 1 === substr_count( $phone_wrapper, 'class="control-shell"' ) && $phone_wrapper === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $phone_wrapper ), 'phone-wrapper-restoration-is-single-target-and-idempotent' );
	$prefix_projection = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-0--input-shell-wrap ssi-source-wrapper-prefix-1--country-wrapper-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button data-wp-on--click="actions.toggle">Country</button><input class="jetpack-combobox-search" type="search"><template data-wp-each="context.countries"><span>Country</span></template></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="value"></div></div>' );
	$assert( str_contains( $prefix_projection, '<div class="country-wrapper"><div class="jetpack-field__input-prefix">' ) && str_contains( $prefix_projection, '<div class="input-shell"><input class="jetpack-field__input-element" type="tel"></div>' ) && str_contains( $prefix_projection, 'data-wp-on--click="actions.toggle"' ) && str_contains( $prefix_projection, '<template data-wp-each="context.countries"><span>Country</span></template>' ) && $prefix_projection === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $prefix_projection ), 'auxiliary-ownership-restores-prefix-and-primary-in-separate-branches-with-runtime-bindings-intact', $prefix_projection );
	$phone_composite = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell ssi-node-222222222222-destination-primary ssi-node-333333333333-destination-carrier"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_composite, 'jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell' ) && str_contains( $phone_composite, '<div class="control-shell ssi-node-333333333333-destination-carrier"><input class="jetpack-field__input-element ssi-node-222222222222-destination-primary" type="tel">' ) && ! str_contains( $phone_composite, 'jetpack-combobox-search ssi-node-' ), 'phone-composite-projection-moves-adapter-declared-primary-and-carrier-hooks-to-the-actual-tel-control', $phone_composite );
	$phone_prefix_chrome = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-phone-wrap"><div class="jetpack-field__input-phone-wrapper ssi-node-444444444444-destination-prefix"><div class="jetpack-field__input-prefix"><div class="jetpack-custom-combobox"><button type="button">Country</button></div></div><input class="jetpack-field__input-element" type="tel"></div></div>' );
	$assert( str_contains( $phone_prefix_chrome, 'jetpack-field__input-prefix ssi-node-444444444444-destination-prefix' ) && str_contains( $phone_prefix_chrome, 'jetpack-custom-combobox ssi-node-444444444444-destination-prefix' ) && ! str_contains( $phone_prefix_chrome, 'jetpack-field__input-phone-wrapper ssi-node-444444444444-destination-prefix' ) && $phone_prefix_chrome === Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $phone_prefix_chrome ), 'phone-prefix-chrome-projection-moves-the-stretch-hook-onto-provider-prefix-and-combobox', $phone_prefix_chrome );
	// A tel field without the country selector is a plain input. The shell, carrier, and prefix hooks would otherwise stay on it, and the prefix reset (`height:100%`) overrides the captured input height.
