<?php
/**
 * Form materializer smoke suite: form presentation: typography, submit styling and control boxes.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$grid_shell_form = array(
		'forms' => array( array(
			'selector'         => 'form.grid-shell',
			'controls'         => array(
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First name' ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
			'control_topology' => array(
				'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
				'nodes'  => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
					array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div', 'class' => 'row' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
				),
			),
			'layout_graph'     => array(
				'schema' => 'generic/computed-layout-graph/v1', 'basis' => 'source_css_cascade', 'truncated' => false,
				'limits' => array( 'nodes' => 128, 'depth' => 8, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(),
				'nodes'  => array(
					array(
						'id'         => 'wrapper-0',
						'kind'       => 'container',
						'parent'     => null,
						'order'      => 0,
						'source'     => array( 'tag' => 'div', 'classes' => array() ),
						'layout'     => array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)' ),
						'provenance' => array(
							array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns' ) ),
						),
					),
				),
			),
		) ),
	);
	$grid_shell_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $grid_shell_form );
	$grid_shell_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_shell_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_shell_css        = (string) ( $grid_shell_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $grid_shell_validation['errors'] )
			&& 'mapped' === ( $grid_shell_row['status'] ?? '' )
			&& true === ( $grid_shell_row['runtime_mapped'] ?? false )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $grid_shell_row['provider_layout_overlay_css'] ?? null )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{[^}]*display:grid[^}]*\}/', $grid_shell_css )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap > \.ssi-field-row\{grid-column:1 \/ -1\}/', $grid_shell_css ),
		'field-shells-own-grid-spans-its-rebuilt-label-control-row-across-every-track',
		$grid_shell_css
	);
	$interactive_button_shell_form = array(
		'forms' => array( array(
			'selector'  => 'form.feedback',
			'controls'  => array_merge(
				array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Your name' ) ),
				array_fill( 0, 5, array( 'tag' => 'button', 'type' => 'button' ) ),
				array( array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) )
			),
			'control_topology' => array(
				'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
				'nodes'  => array_merge(
					array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div' ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 1, 'tag' => 'div' ),
					),
					array_map(
						static fn ( int $index ): array => array( 'id' => 'control-' . ( $index + 1 ), 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => $index, 'depth' => 2, 'control' => $index + 1 ),
						array( 0, 1, 2, 3, 4 )
					),
					array( array( 'id' => 'control-6', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 6 ) )
				),
			),
			'layout_graph' => $v2_layout_graph( array(
				array( 'id' => 'wrapper-2', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'flex', 'gap-1' ) ), 'layout' => array( 'display' => 'flex', 'gap' => '0.25rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.flex.gap-1', 'condition' => null, 'properties' => array( 'display', 'gap' ) ) ) ),
			) ),
			'presentation_graph' => array(
				'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false,
				'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'controls' => array(), 'visual_groups' => array(), 'control_containers' => array(), 'group_captions' => array(), 'variants' => array(), 'diagnostics' => array(),
				'visual_parts' => array_map(
					static fn ( int $index ): array => array( 'id' => 'control-' . $index . '-svg-0', 'index' => $index, 'kind' => 'inline_svg', 'label_side' => 'before', 'source_selector' => 'form button:nth-of-type(' . ( $index - 3 ) . ') > svg', 'markup' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M1 1h22v22H1z"/></svg>', 'intrinsic_size' => array( 'width' => 24, 'height' => 24 ), 'source_css' => array( 'state' => 'unknown' ) ),
					array( 4, 5, 6, 7, 8 )
				),
			),
		) ),
	);
	$interactive_button_shell_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $interactive_button_shell_form );
	$interactive_button_shell_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $interactive_button_shell_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $interactive_button_shell_validation['errors'] ) && 'skipped' === ( $interactive_button_shell_row['status'] ?? '' ) && false === ( $interactive_button_shell_row['runtime_mapped'] ?? true ) && 'form_receipt_loss_unaccepted' === ( $interactive_button_shell_row['reason'] ?? '' ) && 1 === ( $interactive_button_shell_row['unaccepted_receipt_loss_count'] ?? 0 ), 'captured-visual-button-controls-remain-loss-gated-without-rating-semantics', wp_json_encode( $interactive_button_shell_row ) );
	$popup_form = array(
		'selector' => 'form.picker',
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'appointment', 'label' => 'Appointment', 'label_id' => 'appointment-label', 'readonly' => true ),
			array( 'tag' => 'button', 'type' => 'button', 'label' => 'Open picker', 'aria_haspopup' => 'dialog', 'aria_describedby' => 'appointment-label' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
		'control_topology' => array(
			'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
			'nodes' => array(
				array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'picker-field' ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
				array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
			),
		),
	);
	$popup_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $popup_form ) ) );
	$popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $popup_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$popup_strategies = array_column( $popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( true === ( $popup_row['runtime_mapped'] ?? false ) && 1 === ( $popup_row['field_count'] ?? 0 ) && in_array( 'provider_auxiliary_popup_control', $popup_strategies, true ) && ! str_contains( (string) ( $popup_row['block_markup'] ?? '' ), 'Open picker' ), 'related-popup-button-is-superseded-by-editable-provider-field', wp_json_encode( $popup_row ) );
	$unrelated_popup_form = $popup_form;
	$unrelated_popup_form['control_topology']['nodes'][2]['parent'] = null;
	$unrelated_popup_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unrelated_popup_form ) ) );
	$unrelated_popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unrelated_popup_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_auxiliary_popup_control', array_column( $unrelated_popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'popup-button-without-shared-field-topology-is-not-superseded', wp_json_encode( $unrelated_popup_row ) );
	$phone_popup_form = $popup_form;
	$phone_popup_form['controls'][0] = array( 'tag' => 'button', 'type' => 'button', 'label' => 'Phone. Select a country code', 'aria_haspopup' => 'listbox' );
	$phone_popup_form['controls'][1] = array( 'tag' => 'input', 'type' => 'phone', 'name' => 'phone', 'label' => 'Phone' );
	$phone_popup_form['controls'][2] = array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' );
	$phone_popup_form['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'phone-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span', 'class' => 'country-picker' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
	);
	$phone_popup_form['layout_graph'] = $v2_layout_graph( array(
		$layout_node( 'form', array(), 'form' ),
		array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'phone-shell' ) ), 'layout' => array( 'display' => 'flex', 'align_items' => 'center' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.phone-shell', 'condition' => null, 'properties' => array( 'display', 'align-items' ) ) ) ),
		array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'country-picker' ) ), 'layout' => array( 'display' => 'block' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.country-picker', 'condition' => null, 'properties' => array( 'display' ) ) ) ),
	) );
	$phone_popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $phone_popup_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_popup_losses = array_column( $phone_popup_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( str_contains( (string) $phone_popup_row['block_markup'], 'ssi-source-wrapper-shell-0\u002d\u002dphone-shell' ), 'common-source-ancestor-targets-composite-shell-rather-than-only-value' );
	$assert( 'mapped' === ( $phone_popup_row['status'] ?? '' ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $phone_popup_losses, true ) && in_array( 'provider_auxiliary_popup_control', array_column( $phone_popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! str_contains( (string) ( $phone_popup_row['block_markup'] ?? '' ), 'ssi-source-wrapper-1\u002d\u002dcountry-picker' ), 'owned-phone-country-popup-does-not-transfer-auxiliary-wrappers-to-value-input', wp_json_encode( $phone_popup_row ) );
	$unrelated_adjacent_phone_popup = $phone_popup_form;
	$unrelated_adjacent_phone_popup['controls'][0]['aria_haspopup'] = 'false';
	$unrelated_adjacent_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unrelated_adjacent_phone_popup ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	// A composite shell wrapping every mapped control except a submit that sits
	// outside it (Jetpack's own architecture: a submit is always the form's
	// sibling, never a field-group descendant) is now a representable form box,
	// so this materializes instead of loss-gating; the popup-supersession
	// exemption itself - the actual behavior under test - is unaffected.
	$assert( 'mapped' === ( $unrelated_adjacent_phone_row['status'] ?? '' ) && str_contains( (string) ( $unrelated_adjacent_phone_row['block_markup'] ?? '' ), 'type="button"' ) && ! in_array( 'provider_auxiliary_popup_control', array_column( $unrelated_adjacent_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'plain-button-with-aria-haspopup-false-remains-native-and-is-not-superseded', wp_json_encode( $unrelated_adjacent_phone_row ) );
	$mobile_phone_form = $phone_popup_form;
	$mobile_phone_form['fallback_identity'] = str_repeat( 'c', 64 );
	$mobile_phone_form['controls'] = array(
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ),
		array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'company', 'label' => 'Company' ),
		array( 'tag' => 'button', 'type' => 'button', 'label' => 'Phone. Phone. Select a country code' ),
		array( 'tag' => 'input', 'type' => 'phone', 'name' => 'phone', 'label' => 'Phone' ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	);
	$mobile_phone_form['control_topology']['nodes'] = array(
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ),
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 3, 'depth' => 0, 'tag' => 'div', 'class' => 'phone-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span', 'class' => 'country-picker' ),
		array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 3 ),
		array( 'id' => 'control-4', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 4 ),
		array( 'id' => 'control-5', 'kind' => 'control', 'parent' => null, 'order' => 4, 'depth' => 0, 'control' => 5 ),
	);
	$mobile_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $mobile_phone_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $mobile_phone_row['status'] ?? '' ) && true === ( $mobile_phone_row['runtime_mapped'] ?? false ) && str_repeat( 'c', 64 ) === ( $mobile_phone_row['fallback_identity'] ?? '' ) && empty( $mobile_phone_row['form_receipt_unaccepted_losses'] ?? array() ) && in_array( 'provider_auxiliary_popup_control', array_column( $mobile_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'mobile-country-selector-without-popup-metadata-materializes-and-retains-fallback-receipt-identity', wp_json_encode( $mobile_phone_row ) );
	$incompatible_mobile_phone_form = $mobile_phone_form;
	$incompatible_mobile_phone_form['controls'][3]['aria_haspopup'] = 'tooltip';
	$incompatible_mobile_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $incompatible_mobile_phone_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $incompatible_mobile_phone_row['status'] ?? '' ) && str_contains( (string) ( $incompatible_mobile_phone_row['block_markup'] ?? '' ), 'type="button"' ) && ! in_array( 'provider_auxiliary_popup_control', array_column( $incompatible_mobile_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'explicitly-incompatible-country-popup-remains-native-and-loss-gated', wp_json_encode( $incompatible_mobile_phone_row ) );
	$validated_presentation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $presentation_form );
	$presentation_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_presentation['forms'] ) )['forms'][0] ?? array();
	$presentation_markup    = (string) ( $presentation_row['block_markup'] ?? '' );
	$presentation_css       = (string) ( $presentation_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $validated_presentation['errors'] ) && str_contains( $presentation_css, 'font-family:revert;background-color:transparent;border:0;padding:8px 0;font-size:16px;line-height:24px' ) && ! str_contains( $presentation_css, 'line-height:24px;line-height:revert' ) && str_contains( $presentation_css, 'margin:0;font-size:14px;font-weight:400;line-height:1.4;margin-bottom:8px' ) && str_contains( $presentation_css, 'font-family:revert;line-height:revert;min-height:0;background-color:rgb(254,126,3);color:#fff;border:0;border-radius:100px;padding:11px 15px;font-size:16px' ), 'bounded-form-presentation-releases-provider-defaults-before-source-longhand-and-paint-facts', $presentation_css );
	$submit_descriptor = Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor( 'ssi-form-123456789abc', 3, 'submit', array( 'control' => true ) );
	$submit_wrapper_destination = $submit_descriptor['destinations'][0] ?? array();
	$submit_control_destination = $submit_descriptor['destinations'][1] ?? array();
	$assert( isset( $submit_control_destination['resets']['min-height'] ) && '0' === $submit_control_destination['resets']['min-height'] && ! in_array( 'min_height', $submit_wrapper_destination['properties'] ?? array(), true ) && ! isset( $submit_wrapper_destination['resets']['min-height'] ), 'provider-default-min-height-reset-is-inner-control-only-and-source-wrapper-layout-remains-separate' );
	$expanded_presentation = $presentation_form;
	$expanded_presentation['forms'][0]['presentation_graph']['limits']['rules_per_role'] = 96;
	$expanded_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['provenance'] = array_fill( 0, 17, $expanded_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['provenance'][0] );
	$expanded_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $expanded_presentation );
	$expanded_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $expanded_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $expanded_validation['errors'] ) && str_contains( (string) ( $expanded_row['provider_layout_overlay_css']['css'] ?? '' ), 'font-size:16px;line-height:24px' ), 'expanded bounded producer graph preserves authored form typography', wp_json_encode( $expanded_validation['errors'] ?? array() ) );
	$responsive_typography = $expanded_presentation;
	$responsive_typography['forms'][0]['presentation_graph']['controls'][0]['control'] = $presentation_role( array( 'font_family' => 'Georgia', 'line_height' => '1.75' ), array( 'font-family', 'line-height' ), 'input' );
	$responsive_typography['forms'][0]['presentation_graph']['variants'] = array( array(
		'index' => 0, 'role' => 'control',
		'condition' => array( 'kind' => 'media', 'query' => '(min-width: 1536px)' ),
		'style_patch' => array( 'font_size' => '18px' ),
		'precedence' => array( 'font-size' => array( 'source_order' => 1, 'specificity' => 1, 'important' => false ) ),
		'provenance' => array( array( 'source_path' => 'assets/forms.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => 'input', 'condition' => array( 'kind' => 'media', 'query' => '(min-width: 1536px)' ), 'properties' => array( 'font-size' ) ) ),
	) );
	$responsive_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_typography );
	$responsive_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$responsive_css = (string) ( $responsive_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $responsive_validation['errors'] ) && str_contains( $responsive_css, 'font-family:Georgia;line-height:1.75' ) && str_contains( $responsive_css, '@media (min-width: 1536px)' ) && 1 === preg_match( '/@media \(min-width: 1536px\)\{[^}]*font-size:18px[^}]*\}/', $responsive_css ) && 0 === preg_match( '/@media \(min-width: 1536px\)\{[^}]*font-family:revert/', $responsive_css ), 'responsive font size retains base family and line height instead of reverting them', $responsive_css );
	// System font stacks such as Tailwind's default `--font-sans` exceed 160 bytes.
	$long_stack = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"';
	$long_stack_presentation = $expanded_presentation;
	$long_stack_presentation['forms'][0]['presentation_graph']['controls'][0]['control'] = $presentation_role( array( 'font_family' => $long_stack ), array( 'font-family' ), 'input' );
	$long_stack_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $long_stack_presentation );
	$long_stack_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $long_stack_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( strlen( $long_stack ) > 160 && empty( $long_stack_validation['errors'] ) && str_contains( (string) ( $long_stack_row['provider_layout_overlay_css']['css'] ?? '' ), 'font-family:' . $long_stack ), 'form-presentation-carries-long-authored-font-stack', wp_json_encode( $long_stack_validation['errors'] ?? array() ) . ' ' . (string) ( $long_stack_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$unbounded_presentation = $expanded_presentation;
	$unbounded_presentation['forms'][0]['presentation_graph']['limits']['rules_per_role'] = 97;
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unbounded_presentation )['errors'] ), 'presentation graph still rejects an unrecognized rule budget' );
	// Vendored stylesheets keep their upstream package filenames, so artifact paths
	// carry punctuation such as `@` that the canonical artifact path contract allows.
	$punctuated_presentation_form = $presentation_form;
	$punctuated_presentation_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'padding' => '8px' ), array( 'padding' ), 'input' ) ) );
	$punctuated_presentation_form['forms'][0]['presentation_graph']['controls'][0]['control']['provenance'][0]['source_path'] = 'website/external/5a53b0b68d356c47/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
	$punctuated_presentation_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $punctuated_presentation_form );
	$assert( empty( $punctuated_presentation_validation['errors'] ), 'form-presentation-provenance-accepts-canonical-punctuated-artifact-path', wp_json_encode( $punctuated_presentation_validation['errors'] ?? array() ) );
	$traversing_presentation_form = $punctuated_presentation_form;
	$traversing_presentation_form['forms'][0]['presentation_graph']['controls'][0]['control']['provenance'][0]['source_path'] = 'website/../../etc/passwd';
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $traversing_presentation_form )['errors'] ), 'form-presentation-provenance-still-rejects-traversing-artifact-path' );
	$native_line_height_form = $presentation_form;
	$native_line_height_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'padding' => '8px' ), array( 'padding' ), 'input' ) ) );
	$native_line_height_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_line_height_form );
	$native_line_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_line_height_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$native_default_css = (string) ( $native_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $native_line_height_validation['errors'] ) && str_contains( $native_default_css, 'font-family:revert;line-height:revert;padding:8px' ) && ! str_contains( $native_default_css, 'Arial' ), 'provider-native-typography-reverts-to-the-browser-default-when-the-source-omits-it', $native_default_css );
	$authored_typography_form = $native_line_height_form;
	$authored_typography_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'font_family' => 'Georgia', 'line_height' => '1.5' ), array( 'font-family', 'line-height' ), 'input' ) ) );
	$authored_typography_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_typography_form );
	$authored_typography_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_typography_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_typography_css = (string) ( $authored_typography_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_typography_validation['errors'] ) && str_contains( $authored_typography_css, 'font-family:Georgia;line-height:1.5' ) && ! str_contains( $authored_typography_css, 'font-family:Arial' ) && ! str_contains( $authored_typography_css, 'line-height:normal' ), 'authored-control-typography-overrides-provider-default-neutralization', $authored_typography_css );
	$native_submit_form = $presentation_form;
	$native_submit_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'padding' => '8px' ), array( 'padding' ), 'button' ) ) );
	$native_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_submit_form );
	$native_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $native_submit_validation['errors'] ) && str_contains( (string) ( $native_submit_row['provider_layout_overlay_css']['css'] ?? '' ), 'font-family:revert;line-height:revert;min-height:0;padding:8px' ), 'provider-submit-releases-unowned-defaults-before-source-padding', wp_json_encode( $native_submit_row ) );
	// A submit control is often a bare direct child of its source container,
	// unlike every other field, which sits inside its own wrapping box. A
	// sibling-stacking utility (Tailwind's `space-y-*`) that matches direct
	// children therefore captures a real vertical margin fact against the
	// button itself, even though the provider's own field gap already
	// reproduces that inter-sibling spacing structurally. The captured fact
	// must still be represented (no receipt loss for a real cascade match),
	// but an unconditional, later, `!important` reset must win the cascade
	// so the button's own wrapper does not carry that spacing twice.
	$submit_sibling_margin_form = $presentation_form;
	$submit_sibling_margin_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'margin_top' => 'calc(1.5rem * calc(1 - var(--tw-space-y-reverse)))', 'margin_bottom' => 'calc(1.5rem * var(--tw-space-y-reverse))' ), array( 'margin-top', 'margin-bottom' ), 'button' ) ) );
	$submit_sibling_margin_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_sibling_margin_form );
	$submit_sibling_margin_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_sibling_margin_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_sibling_margin_css        = (string) ( $submit_sibling_margin_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $submit_sibling_margin_validation['errors'] )
			&& array() === ( $submit_sibling_margin_row['form_receipt_unaccepted_losses'] ?? array() )
			&& str_contains( $submit_sibling_margin_css, 'margin-top:calc(1.5rem * calc(1 - var(--tw-space-y-reverse)))' )
			&& 1 === preg_match( '/> \.wp-block-button__link\{margin-block:0!important\}$/m', trim( $submit_sibling_margin_css ) )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $submit_sibling_margin_row['provider_layout_overlay_css'] ?? null ),
		'captured-submit-sibling-margin-is-represented-but-an-unconditional-important-reset-wins-the-cascade',
		wp_json_encode( array( 'css' => $submit_sibling_margin_css, 'losses' => $submit_sibling_margin_row['form_receipt_unaccepted_losses'] ?? array() ) )
	);
	// The reset covers the block axis only. An authored inline margin on the
	// submit (a newsletter row's `margin-left: 10px` between the field and the
	// button) is the row's own spacing and stays on the rendered link. The row
	// is compiled by Blocks Engine from neutral markup: classless paragraphs
	// styled by id inside a flex row, as a newsletter widget renders them.
	$signup_row_html = '<style>.signup-row{display:flex;align-items:flex-start}'
		. 'form p#signup-email{flex-grow:1;margin:0;padding:0}'
		. '.signup-row p#signup-submit{display:flex;justify-content:center;margin:0;padding:0}</style>'
		. '<form method="post" id="signup"><div class="signup-row">'
		. '<p id="signup-email"><input required type="email" name="email" aria-label="Email" placeholder="Email" style="padding:15px 23px;border-width:1px"></p>'
		. '<p id="signup-submit"><input type="hidden" name="action" value="signup">'
		. '<button type="submit" style="padding:15px 23px;margin:0;margin-left:10px">Join</button></p>'
		. '</div></form>';
	$signup_row_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'signup.html', 'files' => array( 'signup.html' => $signup_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$signup_row_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $signup_row_source ) ) );
	$signup_row_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $signup_row_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$signup_row_css       = (string) ( $signup_row_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $signup_row_validated['errors'] )
			&& 'mapped' === ( $signup_row_row['status'] ?? '' )
			&& array() === ( $signup_row_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 1 === preg_match( '/form\.jetpack-contact-form__form[^{]*\{[^}]*display:flex[^}]*flex-wrap:nowrap[^}]*\}/', $signup_row_css )
			&& 1 === preg_match( '/-wrap\{flex-grow:1;flex-shrink:1;flex-basis:auto\}/', $signup_row_css )
			&& 1 === preg_match( '/\{display:flex;justify-content:center;flex-grow:0;flex-shrink:1;flex-basis:auto\}/', $signup_row_css )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*margin-left:10px/', $signup_row_css )
			&& 1 === preg_match( '/> \.wp-block-button__link\{margin-block:0!important\}$/m', $signup_row_css )
			&& ! str_contains( $signup_row_css, 'margin:0!important' ),
		'compiled-signup-row-keeps-a-non-wrapping-row-the-field-basis-and-the-submit-inline-margin',
		wp_json_encode( array( 'errors' => $signup_row_validated['errors'] ?? array(), 'status' => $signup_row_row['status'] ?? '', 'reason' => $signup_row_row['reason'] ?? '', 'losses' => $signup_row_row['form_receipt_unaccepted_losses'] ?? array(), 'css' => $signup_row_css ) )
	);
	// The same sibling-stacking utility also matches every flat input after the
	// first. The provider wraps each field in its own box and spaces those boxes
	// with the form gap, so the margin captured against the input itself would
	// add a second gap inside each wrapper. Only a margin that came from a
	// sibling-combinator rule is neutralised; an authored margin on the control
	// is left alone.
	$stacked_margin_styles = array( 'margin_top' => 'calc(1.25rem * calc(1 - 0))', 'margin_bottom' => 'calc(1.25rem * 0)' );
	$stacked_margin_fixture = static function ( string $selector, string $margin_top, int $index ) use ( $presentation_form, $presentation_role ): array {
		$form = $presentation_form;
		$form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => $index, 'control' => $presentation_role( array( 'margin_top' => $margin_top, 'height' => '3rem' ), array( 'margin-top', 'height' ), $selector ) ) );
		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $form );
		$row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validation['forms'] ?? array() ) )['forms'][0] ?? array();
		return array( 'errors' => $validation['errors'] ?? array(), 'row' => $row, 'css' => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ) );
	};
	foreach ( array( 'input' => 0, 'textarea' => 2 ) as $stacked_label => $stacked_index ) {
		$stacked = $stacked_margin_fixture( '.space-y-5>:not([hidden])~:not([hidden])', $stacked_margin_styles['margin_top'], $stacked_index );
		$assert(
			empty( $stacked['errors'] )
				&& array() === ( $stacked['row']['form_receipt_unaccepted_losses'] ?? array() )
				&& str_contains( $stacked['css'], 'margin-top:' . $stacked_margin_styles['margin_top'] )
				&& 1 === preg_match( '/\{margin-block:0!important\}/', $stacked['css'] )
				&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $stacked['row']['provider_layout_overlay_css'] ?? null ),
			'sibling-stacking-margin-on-a-wrapped-' . $stacked_label . '-is-represented-but-reset-so-the-form-gap-is-not-doubled',
			wp_json_encode( array( 'css' => $stacked['css'], 'losses' => $stacked['row']['form_receipt_unaccepted_losses'] ?? array() ) )
		);
	}
	$authored_margin = $stacked_margin_fixture( 'input', '12px', 0 );
	$assert( empty( $authored_margin['errors'] ) && str_contains( $authored_margin['css'], 'margin-top:12px' ) && ! str_contains( $authored_margin['css'], 'margin-block:0' ), 'authored-control-margin-without-a-sibling-combinator-is-kept', $authored_margin['css'] );
	$authored_submit_line_height_form = $presentation_form;
	$authored_submit_line_height_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'padding' => '16px', 'font_size' => '11.2px', 'line_height' => '16.8px' ), array( 'padding', 'font-size', 'line-height' ), 'button' ) ) );
	$authored_submit_line_height_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_submit_line_height_form );
	$authored_submit_line_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_submit_line_height_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_submit_line_height_css = (string) ( $authored_submit_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_submit_line_height_validation['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{font-family:revert;min-height:0;padding:16px;font-size:11\.2px;line-height:16\.8px\}/', $authored_submit_line_height_css ) && ! str_contains( $authored_submit_line_height_css, 'line-height:16.8px;line-height:revert' ), 'authored-submit-line-height-reaches-the-rendered-button-after-provider-default-release', $authored_submit_line_height_css );
	$assert( preg_match( '/wp:jetpack\/label .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/wp:jetpack\/input .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/wp:button .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/\.ssi-form-([a-f0-9]{12})\.ssi-form-\1 \.ssi-node-[a-f0-9]{12}/', $presentation_css ) && str_contains( $presentation_css, '> .wp-block-button__link{' ), 'form-presentation-targets-use-deterministic-provider-subparts-with-authoritative-scope-specificity', $presentation_markup );
	$variant_only_presentation = $presentation_form;
	$variant_condition         = array( 'kind' => 'media', 'query' => '(min-width:769px)' );
	$variant_role              = $presentation_role( array( 'height' => '40px' ), array( 'height' ), 'input' );
	$variant_role['provenance'][0]['condition'] = $variant_condition;
	$variant_only_presentation['forms'][0]['presentation_graph']['controls'] = array();
	$variant_only_presentation['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 0, 'role' => 'control', 'condition' => $variant_condition, 'style_patch' => $variant_role['styles'], 'precedence' => array( 'height' => array( 'source_order' => 1, 'specificity' => 1, 'important' => false ) ), 'provenance' => $variant_role['provenance'] ) );
	$validated_variant_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_only_presentation );
	$variant_only_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_variant_only['forms'] ) )['forms'][0] ?? array();
	$assert( empty( $validated_variant_only['errors'] ) && str_contains( (string) ( $variant_only_row['block_markup'] ?? '' ), 'ssi-node-' ) && str_contains( (string) ( $variant_only_row['provider_layout_overlay_css']['css'] ?? '' ), '@media (min-width:769px){' ) && str_contains( (string) ( $variant_only_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:40px' ), 'variant-only-form-presentation-creates-provider-targets', wp_json_encode( $variant_only_row ) );
	$all_controls_presentation = $presentation_form;
	$all_controls_presentation['forms'][0]['controls'][1] = array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'One' ) ) );
	$all_controls_presentation['forms'][0]['presentation_graph']['controls'] = array(
		array( 'index' => 0, 'control' => $presentation_role( array( 'border' => '1px solid #111', 'padding' => '7px' ), array( 'border', 'padding' ), 'input' ) ),
		array( 'index' => 1, 'control' => $presentation_role( array( 'border' => '2px solid #222', 'padding' => '8px' ), array( 'border', 'padding' ), 'select' ) ),
		array( 'index' => 2, 'control' => $presentation_role( array( 'border' => '3px solid #333', 'min_height' => '9rem' ), array( 'border', 'min-height' ), 'textarea' ) ),
		array( 'index' => 3, 'control' => $presentation_role( array( 'background_color' => '#444', 'padding' => '9px 12px' ), array( 'background-color', 'padding' ), 'button' ) ),
	);
	$all_controls_presentation['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 2, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:48rem)' ), 'style_patch' => array( 'min_height' => '6rem' ), 'precedence' => array( 'min-height' => array( 'source_order' => 2, 'specificity' => 1, 'important' => false ) ), 'provenance' => array() ) );
	$validated_all_controls = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $all_controls_presentation );
	$all_controls_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_all_controls['forms'] ?? array() ) )['forms'][0] ?? array();
	$all_controls_markup    = (string) ( $all_controls_row['block_markup'] ?? '' );
	$all_controls_css       = (string) ( $all_controls_row['provider_layout_overlay_css']['css'] ?? '' );
	$all_controls_targets   = $all_controls_row['provider_layout_target_map']['presentation_targets'] ?? array();
	$all_controls_hooks     = array();
	foreach ( $all_controls_targets as $target ) {
		$selector = $target['destinations'][0]['selector'] ?? '';
		if ( is_array( $target ) && preg_match( '/\.ssi-node-[a-f0-9]{12}/', (string) $selector, $hook ) ) {
			$all_controls_hooks[] = substr( $hook[0], 1 );
		}
	}
	$assert( empty( $validated_all_controls['errors'] ) && 4 === count( $all_controls_hooks ) && empty( array_filter( $all_controls_hooks, static fn( string $hook ): bool => ! str_contains( $all_controls_markup, $hook ) || ! str_contains( $all_controls_css, '.' . $hook ) ) ) && str_contains( $all_controls_css, 'font-family:revert;line-height:revert;border:1px solid #111;padding:7px' ) && 1 === preg_match( '/\.ssi-node-[a-f0-9]{12} select\{font-family:revert!important;line-height:revert!important;appearance:auto!important;border:2px solid #222!important;padding:8px!important\}/', $all_controls_css ) && str_contains( $all_controls_css, 'font-family:revert;line-height:revert;border:3px solid #333;min-height:9rem;display:inline-block' ) && str_contains( $all_controls_css, 'font-family:revert;line-height:revert;min-height:0;background-color:#444;padding:9px 12px' ) && str_contains( $all_controls_css, '@media (max-width:48rem){' ) && str_contains( $all_controls_css, '> .wp-block-button__link{font-family:revert;line-height:revert;min-height:0;background-color:#444;padding:9px 12px}' ) && ! str_contains( $all_controls_css, 'control-shell' ) && ! str_contains( $all_controls_css, 'control-hook' ), 'presentation-overlay-releases-unowned-typography-before-source-properties', wp_json_encode( array( 'markup' => $all_controls_markup, 'css' => $all_controls_css, 'targets' => $all_controls_targets ) ) );
	$submit_width_form = $presentation_form;
	$submit_width_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'width' => '100%' ), array( 'width' ), 'button' ) ) );
	$submit_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_width_form );
	$submit_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $submit_width_validation['errors'] ) && preg_match( '/\.ssi-node-[a-f0-9]{12}\{width:100%\}/', (string) ( $submit_width_row['provider_layout_overlay_css']['css'] ?? '' ) ), 'source-submit-width-targets-the-wrapper-instead-of-its-shrink-wrapped-inner-button' );
	$submit_block_form = $presentation_form;
	$submit_block_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'display' => 'block' ), array( 'display' ), 'button' ) ) );
	$submit_block_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_block_form );
	$submit_block_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_block_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_block_css = (string) ( $submit_block_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $submit_block_validation['errors'] ) && 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}\{display:block\}/', $submit_block_css ) && ! str_contains( $submit_block_css, '> .wp-block-button__link{display:block}' ), 'source-block-submit-display-targets-the-core-button-wrapper-for-automatic-full-row-width', $submit_block_css );
	$positioned_submit_form = $presentation_form;
	$positioned_submit_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'display' => 'flex', 'inset' => '0', 'position' => 'absolute' ), array( 'display', 'inset', 'position' ), 'button' ) ) );
	$positioned_submit_form['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 3, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:768px)' ), 'style_patch' => array( 'display' => 'flex', 'inset' => '0', 'position' => 'absolute' ), 'precedence' => array( 'display' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ), 'inset' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ), 'position' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ) ), 'provenance' => array() ) );
	$positioned_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $positioned_submit_form );
	$positioned_submit_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $positioned_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$positioned_submit_css        = (string) ( $positioned_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $positioned_submit_validation['errors'] ) && 'mapped' === ( $positioned_submit_row['status'] ?? '' ) && true === ( $positioned_submit_row['runtime_mapped'] ?? false ) && empty( $positioned_submit_row['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $positioned_submit_css, '{display:flex}' ) && ! str_contains( $positioned_submit_css, 'inset:0' ) && ! str_contains( $positioned_submit_css, 'position:absolute' ), 'positioned-submit-maps-without-stretching-the-inner-button-over-the-form', $positioned_submit_css );
	$submit_min_width_form = $presentation_form;
	$submit_min_width_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'min_width' => '100%' ), array( 'min-width' ), 'button' ) ) );
	$submit_min_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_min_width_form );
	$submit_min_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_min_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_min_width_css = (string) ( $submit_min_width_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $submit_min_width_validation['errors'] ) && preg_match( '/\.ssi-node-[a-f0-9]{12}\{min-width:100%\}/', $submit_min_width_css ) && ! str_contains( $submit_min_width_css, '> .wp-block-button__link{min-width:100%}' ), 'source-submit-min-width-targets-the-wrapper-instead-of-its-inner-button', $submit_min_width_css );
	// A producer can capture a submit button's own presentation as a bounded
	// per-control style rather than running it through the full source-CSS-cascade
	// presentation_graph compiler. Before this seam was wired up, that capture only
	// flagged the button with the ssi-provider-submit-presentation marker class -
	// the source's real background, text color, typography, and full-width intent
	// never reached the rendered button, so it fell back to a transparent,
	// default-sized wp-element-button. The seam must resolve this capture through
	// the same provider layout overlay every other captured control already uses.
	$submit_control_style_form = $topology_form;
	$submit_control_style_form['forms'][0]['controls'][3]['presentation'] = array(
		'style' => array(
			'width'      => '100%',
			'color'      => array(
				'background' => 'oklch(0.2689 0.0057 156.83)',
				'text'       => 'oklch(0.956 0.0115 84.58)',
			),
			'typography' => array(
				'fontSize'      => '12px',
				'fontWeight'    => '600',
				'letterSpacing' => '1.92px',
				'textTransform' => 'uppercase',
			),
			'border'     => array( 'radius' => '9999px' ),
			'spacing'    => array(
				'padding' => array(
					'top'    => '1rem',
					'right'  => '2rem',
					'bottom' => '1rem',
					'left'   => '2rem',
				),
			),
		),
	);
	$validated_submit_control_style = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_control_style_form );
	$submit_control_style_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_control_style['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_control_style_markup    = (string) ( $submit_control_style_row['block_markup'] ?? '' );
	$submit_control_style_css       = (string) ( $submit_control_style_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_control_style['errors'] )
			&& 'mapped' === ( $submit_control_style_row['status'] ?? '' )
			&& str_contains( $submit_control_style_markup, 'ssi-provider-submit-presentation' )
			&& str_contains( $submit_control_style_css, '> .wp-block-button__link{font-family:revert;line-height:revert;min-height:0;background-color:oklch(0.2689 0.0057 156.83);color:oklch(0.956 0.0115 84.58);font-size:12px;font-weight:600;letter-spacing:1.92px;text-transform:uppercase;border-radius:9999px;padding-top:1rem;padding-right:2rem;padding-bottom:1rem;padding-left:2rem}' )
			&& preg_match( '/\.ssi-node-[a-f0-9]{12}\{width:100%\}/', $submit_control_style_css )
			&& ! str_contains( $submit_control_style_css, '> .wp-block-button__link{width:100%' ),
		'source-submit-control-style-capture-resolves-through-the-provider-overlay-instead-of-an-inert-marker-class',
		wp_json_encode( array( 'markup' => $submit_control_style_markup, 'css' => $submit_control_style_css ) )
	);
	// The saved block still claims no style attribute of its own: the overlay
	// paints the button, so the saved markup keeps agreeing with core/button's
	// own save() output and the imported form stays clean in the editor.
	$submit_control_style_attrs = array();
	foreach ( parse_blocks( $submit_control_style_markup ) as $parsed_submit_control_style_form ) {
		$collect_submit_control_style = static function ( array $blocks, callable $collect ) use ( &$submit_control_style_attrs ): void {
			foreach ( $blocks as $parsed ) {
				if ( 'core/button' === ( $parsed['blockName'] ?? '' ) ) {
					$submit_control_style_attrs[] = $parsed['attrs'] ?? array();
				}
				$collect( $parsed['innerBlocks'] ?? array(), $collect );
			}
		};
		$collect_submit_control_style( array( $parsed_submit_control_style_form ), $collect_submit_control_style );
	}
	$assert( 1 === count( $submit_control_style_attrs ) && ! array_key_exists( 'style', $submit_control_style_attrs[0] ), 'source-submit-control-style-capture-still-claims-no-unrenderable-block-style-attribute', wp_json_encode( $submit_control_style_attrs ) );
	$submit_style_line_height_form = $submit_control_style_form;
	$submit_style_line_height_form['forms'][0]['controls'][3]['presentation']['style']['typography']['lineHeight'] = '16.8px';
	$validated_submit_style_line_height = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_style_line_height_form );
	$submit_style_line_height_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_style_line_height['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_style_line_height_css       = (string) ( $submit_style_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_style_line_height['errors'] )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*font-family:revert;[^}]*line-height:16\.8px/', $submit_style_line_height_css )
			&& ! str_contains( $submit_style_line_height_css, 'line-height:16.8px;line-height:revert' ),
		'captured-submit-style-line-height-reaches-the-rendered-button-geometry',
		$submit_style_line_height_css
	);
	// The cascade-resolved graph is authoritative for every property it
	// resolved; the per-control capture fills only what the graph omits.
	$submit_unresolved_form = $submit_control_style_form;
	$submit_unresolved_form['forms'][0]['controls'][3]['presentation']['style']['typography']['fontFamily'] = 'var(--body-font,unset)';
	$submit_unresolved_form['forms'][0]['controls'][3]['presentation']['style']['typography']['fontSize']   = '17px';
	$submit_unresolved_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(
			array(
				'index'   => 3,
				'control' => array(
					'styles'     => array( 'font_family' => 'Georgia', 'font_size' => '16px' ),
					'provenance' => array(),
				),
			),
		),
	);
	$validated_submit_unresolved = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_unresolved_form );
	$submit_unresolved_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_unresolved['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_unresolved_css       = (string) ( $submit_unresolved_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_unresolved['errors'] )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*font-family:Georgia;/', $submit_unresolved_css )
			&& ! str_contains( $submit_unresolved_css, 'var(--body-font' )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*font-size:16px;/', $submit_unresolved_css )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*font-weight:600;/', $submit_unresolved_css ),
		'resolved-graph-submit-facts-win-and-the-capture-fills-the-rest',
		$submit_unresolved_css
	);
	$submit_preflight_form = $submit_control_style_form;
	$submit_preflight_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(
			array(
				'index'   => 3,
				'control' => array(
					'styles'     => array( 'background_color' => 'rgb(0,0,0)', 'padding' => '0', 'padding_top' => '.75rem', 'font_weight' => 'inherit' ),
					'provenance' => array(),
				),
			),
		),
	);
	$validated_submit_preflight = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_preflight_form );
	$submit_preflight_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_preflight['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_preflight_css       = (string) ( $submit_preflight_row['provider_layout_overlay_css']['css'] ?? '' );
	// A resolved padding side keeps the whole padding family on the graph: the
	// capture's longhands cannot split the box the cascade resolved.
	$assert(
		empty( $validated_submit_preflight['errors'] )
			&& str_contains( $submit_preflight_css, 'padding:0;padding-top:.75rem;' )
			&& ! str_contains( $submit_preflight_css, 'padding-right:2rem' )
			&& ! str_contains( $submit_preflight_css, 'padding-top:1rem' )
			&& str_contains( $submit_preflight_css, 'font-weight:inherit' )
			&& str_contains( $submit_preflight_css, 'background-color:rgb(0,0,0)' )
			&& str_contains( $submit_preflight_css, 'border-radius:9999px' ),
		'resolved-graph-submit-box-wins-over-the-per-control-capture',
		$submit_preflight_css
	);
	foreach ( array( '' => 'inherit', 'font-weight:600;' => '600' ) as $source_weight => $expected_weight ) {
		$label_artifact = ( new $artifact_compiler() )->compile( array(
			'entrypoint' => 'index.html',
			'files' => array( 'index.html' => '<style>form{font-weight:400}label{font-size:15px;' . $source_weight . '}</style><form><label for="name">Driver name</label><input id="name" name="name"><button>Send</button></form>' ),
		) )->toArray();
		$label_manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $label_artifact['fallbacks'][0] ) ) );
		$label_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $label_manifest['forms'] ?? array() ) )['forms'][0] ?? array();
		$label_overlay = $label_row['provider_layout_overlay_css'] ?? null;
		$assert( empty( $label_manifest['errors'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $label_overlay ) && str_contains( $label_overlay['css'] ?? '', 'font-weight:' . $expected_weight ) && ( '' === $source_weight || ! str_contains( $label_overlay['css'] ?? '', 'font-weight:inherit' ) ), 'artifact-label-font-weight-preserves-' . $expected_weight );
	}
	$status_manifest = array( 'selector' => 'form', 'controls' => array( array( 'tag' => 'input', 'name' => 'name', 'type' => 'text' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send' ) ), 'form' => array( 'trailing_status' => array( 'role' => 'status', 'id' => 'form-status', 'margin_top' => '0.5rem' ) ) );
	$status_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $status_manifest ) ) );
	$status_markup = $status_seed['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $status_markup, '<output id="form-status" class="wp-block-group" style="margin-top:0.5rem"></output>' ) && ! str_contains( $status_markup, 'wp:html' ), 'empty-source-status-retains-native-output-and-authored-spacing' );
	$editor_form = $presentation_form;
	$editor_form['forms'][0]['form']['container_presentation'] = array( 'schema' => 'generic/form-container-presentation/v1', 'styles' => array( 'max_width' => '500px', 'margin' => '0 auto', 'text_align' => 'left' ), 'provenance' => array(), 'variants' => array() );
	$editor_manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $editor_form );
	$editor_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $editor_manifest['forms'] ) )['forms'][0];
	$editor_css = $editor_row['provider_layout_overlay_css']['editor_css'] ?? '';
	// The form box is painted on Jetpack's front-end form container. The editor
	// renders no such element, so it paints the box on the block wrapper (#2281,
	// #2321): a `form{}` source rule cannot reach that `<div>` itself. Jetpack's
	// editor styles make that wrapper `border-box`, so the source's initial
	// `content-box` is restated with it.
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $editor_row['provider_layout_overlay_css'] ) && str_contains( $editor_row['provider_layout_overlay_css']['css'] ?? '', '.jetpack-contact-form-container{max-width:500px;margin:0 auto;text-align:left}' ) && ! str_contains( $editor_css, 'jetpack-contact-form-container' ) && 1 === preg_match( '/\.editor-styles-wrapper (\.ssi-form-[a-f0-9]{12})\1\{max-width:500px;margin:0 auto;text-align:left;box-sizing:content-box\}/', $editor_css ) && str_contains( $editor_css, ' > label{' ) && str_contains( $editor_css, 'font:-webkit-small-control;' ), 'editor-paints-the-source-form-box-on-the-block-wrapper-with-labels-and-native-button-typography', $editor_css );
	// The captured form box's own padding is the same kind of bounded source-CSS-cascade
	// evidence every other captured control already carries. It must reach the rendered
	// page, not only editor chrome, and it must still resolve when a captured field
	// wrapper (control_containers) is also present on the same presentation graph -
	// both destinations previously shared one loop variable, so the wrapper's own row
	// silently clobbered the form box's captured styles before this ever compiled.
	$container_padding_form = $topology_form;
	$container_padding_form['forms'][0]['form']['container_presentation'] = array( 'schema' => 'generic/form-container-presentation/v1', 'styles' => array( 'padding' => '36px' ), 'provenance' => array(), 'variants' => array() );
	$container_padding_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(),
		'visual_parts' => array(),
		'visual_groups' => array(),
		'control_containers' => array( array( 'index' => 0, 'source_selector' => '.field', 'styles' => array( 'background_color' => '#fff' ), 'provenance' => array() ) ),
		'group_captions' => array(),
	);
	$validated_container_padding = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $container_padding_form );
	$container_padding_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_container_padding['forms'] ?? array() ) )['forms'][0] ?? array();
	$container_padding_css       = (string) ( $container_padding_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_container_padding['errors'] )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $container_padding_row['provider_layout_overlay_css'] ?? null )
			&& preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:36px\}/', $container_padding_css ),
		'captured-form-container-padding-reaches-the-rendered-page-alongside-a-captured-field-wrapper',
		wp_json_encode( array( 'css' => $container_padding_css, 'validation' => $validated_container_padding ) )
	);
	$assert(
		preg_match( '/\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:0;margin:0;border:0\}/', $container_padding_css )
			&& preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:36px\}/', $container_padding_css )
			&& ! preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:0/', $container_padding_css )
			&& ! str_contains( $container_padding_css, 'padding:0!important' ),
		'provider-container-box-reset-is-lower-priority-than-authored-container-padding',
		$container_padding_css
	);
	// Jetpack's front-end form element sits between the container that paints the
	// source form's box and the block wrapper that lays out its fields. A source
	// rule written against the form (`.newsletter form {...}`) also matches it, so
	// it adds no box of its own, until Jetpack hides it after a submission. The
	// editor has no such element.
	$assert(
		preg_match( '/(?:^|\n)\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container > form\.jetpack-contact-form__form:has\(> \.wp-block-jetpack-contact-form\):not\(\.submission-success\)\{display:contents\}/', $container_padding_css )
			&& ! str_contains( (string) ( $container_padding_row['provider_layout_overlay_css']['editor_css'] ?? '' ), 'jetpack-contact-form__form:has(' )
			&& ! str_contains( (string) ( $status_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ), 'jetpack-contact-form__form:has(' ),
		'provider-front-end-form-adds-no-box-under-a-painted-source-form-container',
		$container_padding_css
	);
	// A provider select is a wrapper nest: Jetpack parks input className on
	// `.contact-form__select-wrapper` and paints that wrapper, then independently
	// pads the nested `<select>`. The authored box must resolve to one node, the
	// inner control, while width stays on the wrapper so it does not shrink-wrap.
	$select_box_form = array(
		'forms' => array( array(
			'selector'           => 'form.select-field',
			'controls'           => array( array( 'tag' => 'select', 'type' => 'select', 'name' => 'kind', 'label' => 'Kind' ) ),
			'presentation_graph' => array(
				'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
				'controls' => array( array( 'index' => 0, 'control' => array( 'styles' => array( 'padding' => '12px 16px', 'border' => '1px solid #ccc', 'background' => '#fff', 'width' => '100%' ), 'provenance' => array() ) ) ),
			),
		) ),
	);
	$select_box_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $select_box_form );
	$select_box_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $select_box_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$select_box_css        = (string) ( $select_box_row['provider_layout_overlay_css']['css'] ?? '' );
	// Jetpack renders the native control with `appearance: none`, dropping the
	// platform chevron the authored select had. The authored padding and border
	// already reach the native control; the appearance revert must ride with
	// them, or the select keeps provider chrome while claiming authored
	// presentation.
	$select_appearance_rule = 1 === preg_match( '/\.ssi-node-[a-f0-9]{12} select\{([^}]+)\}/', $all_controls_css, $select_appearance ) ? $select_appearance[1] : '';
	$assert(
		str_contains( $select_appearance_rule, 'appearance:auto' )
			&& str_contains( $select_appearance_rule, 'padding:8px' )
			&& str_contains( $select_appearance_rule, 'border:2px solid #222' )
			&& 1 !== preg_match( '/\.ssi-node-[a-f0-9]{12}\{[^}]*appearance:auto/', $all_controls_css ),
		'authored-select-appearance-reaches-the-native-control-not-its-provider-wrapper',
		$select_appearance_rule
	);

	$select_inner_rule     = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12} select\{([^}]+)\}/', $select_box_css, $select_inner ) ? $select_inner[1] : '';
	$select_wrapper_rule   = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{([^}]+)\}/', $select_box_css, $select_wrapper ) ? $select_wrapper[1] : '';
	$assert(
		empty( $select_box_validation['errors'] )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $select_box_row['provider_layout_overlay_css'] ?? null )
			&& str_contains( $select_inner_rule, 'padding:12px 16px' )
			&& str_contains( $select_inner_rule, 'border:1px solid #ccc' )
			&& str_contains( $select_inner_rule, 'background:#fff' )
			&& ! str_contains( $select_inner_rule, 'width:100%' )
			&& str_contains( $select_wrapper_rule, 'width:100%' )
			&& str_contains( $select_wrapper_rule, 'padding:0!important' )
			&& str_contains( $select_wrapper_rule, 'border:0!important' )
			&& ! str_contains( $select_wrapper_rule, 'padding:12px 16px' )
			&& ! str_contains( $select_wrapper_rule, 'border:1px solid #ccc' ),
		'authored-select-box-reaches-the-nested-control-once-and-width-stays-on-the-wrapper',
		wp_json_encode( array( 'css' => $select_box_css, 'inner' => $select_inner_rule, 'wrapper' => $select_wrapper_rule, 'validation' => $select_box_validation ) )
	);
	$stretch_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column}}' );
	$stretch_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $stretch_source ) ) );
	$stretch_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $stretch_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$stretch_css    = (string) ( $stretch_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $stretch_form['errors'] ) && 2 === count( $stretch_source['control_topology']['nodes'] ?? array() ) && 1 === count( $stretch_source['layout_graph']['nodes'] ?? array() ) && str_contains( $stretch_css, '@media (min-width: 768px){.ssi-form-') && str_contains( $stretch_css, '{align-self:stretch}' ) && in_array( 'form_layout_intent_flex_stretch_submit', array_column( $stretch_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'artifact-form-responsive-column-stretch-targets-the-generated-submit-wrapper', $stretch_css );
	$inherited_submit_source = $compile_form( 'html{line-height:1.5}button{padding:16px;font-size:.7rem;width:100%;background:gold}' );
	$inherited_submit_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $inherited_submit_source ) ) );
	$inherited_submit_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $inherited_submit_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$inherited_submit_css    = (string) ( $inherited_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	// A browser does not inherit document line-height or font family into a
	// `<button>`: it keeps the control default (`normal`, the UA font) unless
	// the source opts in. The overlay releases provider button typography to
	// that default rather than inheriting the document line box.
	$assert( empty( $inherited_submit_form['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{font-family:revert;line-height:revert;min-height:0;[^}]*font-size:\.7rem/', $inherited_submit_css ), 'unopted-submit-keeps-the-browser-control-line-height-and-releases-the-provider-min-height', $inherited_submit_css );
	$opted_submit_source = $compile_form( 'html{line-height:1.5}button,input{font:inherit}button{padding:16px;font-size:.7rem}' );
	$opted_submit_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $opted_submit_source ) ) );
	$opted_submit_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $opted_submit_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$opted_submit_css    = (string) ( $opted_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $opted_submit_form['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{[^}]*line-height:revert;[^}]*font:inherit;[^}]*font-size:\.7rem/', $opted_submit_css ), 'submit-that-opts-into-font-inherit-inherits-the-document-line-box-after-the-reset', $opted_submit_css );
	$authored_button_line_height_source = $compile_form( 'button{padding:16px;font-size:.7rem;line-height:16.8px;background:gold;color:#fff}' );
	$authored_button_line_height_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $authored_button_line_height_source ) ) );
	$authored_button_line_height_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_button_line_height_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_button_line_height_css    = (string) ( $authored_button_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_button_line_height_form['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{[^}]*line-height:16\.8px;/', $authored_button_line_height_css ) && ! str_contains( $authored_button_line_height_css, 'line-height:16.8px;line-height:revert' ), 'artifact-authored-submit-line-height-reaches-the-rendered-button-geometry', $authored_button_line_height_css );
	// A sibling `for` label's authored margin spaces its caption from the
	// control. The provider-default label reset is weaker than the captured label
	// rule, so that margin survives. A label that wraps its control is the field
	// box the rebuilt row already spaces; its margin is released on the caption.
