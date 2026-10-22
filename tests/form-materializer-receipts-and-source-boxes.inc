<?php
/**
 * Form materializer smoke suite: receipts, provider availability, real source boxes and mesh forms.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$textarea_overflow_losses   = array_fill( 0, 32, array( 'dimension' => 'layout', 'reason_code' => 'provider_wrapper_layout_unrepresentable' ) );
	$textarea_overflow_losses[] = array( 'dimension' => 'control', 'reason_code' => 'textarea_height_omitted', 'omitted_count' => 1 );
	$textarea_overflow_receipt  = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'topology_losses' => $textarea_overflow_losses ), array() )['receipt'];
	$assert( 1 === ( $textarea_overflow_receipt['gate_required_loss_overflow_count'] ?? 0 ) && Static_Site_Importer_Form_Mapping_Decision::requires_gate( end( $textarea_overflow_losses ) ), 'computed-layout-gates-overflow-with-the-form-mapping-decision-policy', wp_json_encode( $textarea_overflow_receipt ) );
	// Both receipt paths bound gated overflow identically: the overflowing gated loss takes the last slot.
	$seeder_overflow_receipt = array();
	Static_Site_Importer_Form_Mapping_Decision::append_receipt_entries( $seeder_overflow_receipt, 'losses', $textarea_overflow_losses );
	$bounded_losses = static function ( array $receipt ): array {
		$facts = array_intersect_key( $receipt, array_flip( array( 'losses', 'losses_total', 'loss_count', 'truncated', 'gate_required_loss_overflow_count', 'gate_required_loss_overflow_hash' ) ) );
		ksort( $facts );
		return $facts;
	};
	$assert( 32 === count( $textarea_overflow_receipt['losses'] ) && 33 === $textarea_overflow_receipt['losses_total'] && end( $textarea_overflow_losses ) === $textarea_overflow_receipt['losses'][31] && $bounded_losses( $seeder_overflow_receipt ) === $bounded_losses( $textarea_overflow_receipt ), 'receipt-bounders-share-one-gated-overflow-policy', wp_json_encode( array( $textarea_overflow_receipt, $seeder_overflow_receipt ) ) );
	$overflow_nodes = array();
	for ( $receipt_index = 0; $receipt_index < 33; ++$receipt_index ) $overflow_nodes[] = $layout_node( 'wrapper-' . $receipt_index, array( 'display' => 'flex', 'direction' => 'row' ) );
	$overflow_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.overflow', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'label' => 'Guests', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ), 'layout_graph' => $layout_graph( $overflow_nodes ) ) ) ) );
	$overflow_receipt = $overflow_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$assert( 34 === ( $overflow_receipt['losses_total'] ?? 0 ) && 32 === count( $overflow_receipt['losses'] ?? array() ) && true === ( $overflow_receipt['truncated'] ?? false ) && 1 === ( $overflow_receipt['gate_required_loss_overflow_count'] ?? 0 ) && 64 === strlen( (string) ( $overflow_receipt['gate_required_loss_overflow_hash'] ?? '' ) ) && in_array( 'unsupported_control_attribute', array_column( $overflow_receipt['losses'] ?? array(), 'reason_code' ), true ), 'seeder-retains-gate-required-loss-while-preserving-overflow-totals', wp_json_encode( $overflow_receipt ) );
	$overflow_row = $overflow_seed['forms'][0] ?? array();
	$assert( false === ( $overflow_row['runtime_mapped'] ?? true ) && 'form_receipt_gate_loss_overflow' === ( $overflow_row['form_receipt_unaccepted_losses'][1]['reason_code'] ?? '' ), 'gate-required-receipt-overflow-fails-runtime-acceptance', wp_json_encode( $overflow_row ) );
	$assert( 'declined' === ( $overflow_row['mapping_decision']['status'] ?? '' ) && ( $overflow_row['mapping_decision']['losses'] ?? null ) === ( $overflow_row['form_receipt_unaccepted_losses'] ?? null ) && 32 === count( $overflow_receipt['losses'] ?? array() ) && ! in_array( 'form_receipt_gate_loss_overflow', array_column( $overflow_receipt['losses'] ?? array(), 'reason_code' ), true ), 'overflow-decision-retains-synthetic-gate-loss-without-changing-bounded-receipt' );
	$variant_only = $topology_form;
	$variant_only['forms'][0]['layout_graph']['nodes'] = array( $layout_node( 'wrapper-0', array(), 'section' ) );
	$variant_only['forms'][0]['layout_graph']['variants'] = array();
	for ( $variant_index = 0; $variant_index < 256; ++$variant_index ) {
		$condition = array( 'kind' => 'media', 'query' => '(min-width: ' . $variant_index . 'px)' );
		$variant_only['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $condition, 'layout_patch' => array( 'display' => 'flex' ), 'precedence' => array( 'display' => array( 'source_order' => $variant_index, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.row-2', 'condition' => $condition, 'properties' => array( 'display' ) ) ) );
	}
	$variant_only_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_only );
	$variant_only_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $variant_only_validation['forms'] ) );
	$variant_only_receipt = $variant_only_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$variant_only_loss = $variant_only_receipt['losses'][0] ?? array();
	$assert( empty( $variant_only_validation['errors'] ) && 256 === count( $variant_only_validation['forms'][0]['layout_graph']['variants'] ?? array() ) && 2 === ( $variant_only_receipt['losses_total'] ?? 0 ) && 'provider_wrapper_layout_unrepresentable' === ( $variant_only_loss['reason_code'] ?? '' ) && 'responsive_layout_ownership' === ( $variant_only_receipt['losses'][1]['reason_code'] ?? '' ) && 256 === ( $variant_only_receipt['losses'][1]['variant_count'] ?? 0 ) && 64 === strlen( (string) ( $variant_only_receipt['losses'][1]['variant_hash'] ?? '' ) ), 'computed-layout-variant-only-retains-bounded-provider-and-responsive-losses', wp_json_encode( $variant_only_receipt ) );
	$semantic_topology = $topology_form;
	$semantic_topology['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$semantic_topology['forms'][0]['control_topology']['nodes'][1]['tag'] = 'label';
	$semantic_topology['forms'][0]['layout_graph']['nodes'] = array();
	$semantic_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $semantic_topology );
	$semantic_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $semantic_validation['forms'] ) );
	$semantic_markup = (string) ( $semantic_seed['forms'][0]['block_markup'] ?? '' );
	$semantic_losses = $semantic_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$assert( 2 === count( $semantic_losses ) && 'semantic' === ( $semantic_losses[0]['dimension'] ?? '' ) && ! str_contains( $semantic_markup, '<fieldset' ) && ! str_contains( $semantic_markup, '<label' ), 'semantic-wrapper-losses-cover-topology-wrappers-without-layout-graph-nodes' );
	$neutral_span = $topology_form;
	$neutral_span['forms'][0]['control_topology']['nodes'][1]['tag'] = 'span';
	$neutral_span_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $neutral_span );
	$neutral_span_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $neutral_span_validation['forms'] ?? array() ) );
	$assert( empty( $neutral_span_validation['errors'] ) && 'mapped' === ( $neutral_span_seed['forms'][0]['status'] ?? '' ) && ! in_array( 'unsupported_semantic_wrapper', array_column( $neutral_span_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'neutral-span-wrapper-flattens-without-semantic-loss', wp_json_encode( array( 'validation' => $neutral_span_validation, 'seed' => $neutral_span_seed ) ) );
	$plain_root_fieldset = $topology_form;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'plain_group';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['class'] = 'source-root';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['order'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][6]['depth'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['order'] = 3;
	$plain_root_fieldset['forms'][0]['layout_graph']['nodes'] = array(
		$layout_node( 'wrapper-0', array(), 'fieldset' ),
	);
	$plain_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $plain_root_fieldset );
	$plain_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $plain_root_fieldset_validation['forms'] ?? array() ) );
	$plain_root_fieldset_markup = (string) ( $plain_root_fieldset_seed['forms'][0]['block_markup'] ?? '' );
	$assert( empty( $plain_root_fieldset_validation['errors'] ) && 'mapped' === ( $plain_root_fieldset_seed['forms'][0]['status'] ?? '' ) && empty( $plain_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $plain_root_fieldset_markup, 'ssi-source-root-fieldset\u002d\u002dsource-root' ) && in_array( 'provider_plain_root_fieldset_projection', array_column( $plain_root_fieldset_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! in_array( 'provider_plain_single_field_fieldset_projection', array_column( $plain_root_fieldset_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'provider-form-transports-proven-plain-root-fieldset-grouping', wp_json_encode( array( 'validation' => $plain_root_fieldset_validation, 'seed' => $plain_root_fieldset_seed ) ) );
	$plain_root_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form" action="/submit"><div class="wp-block-jetpack-contact-form ssi-source-root-fieldset ssi-source-root-fieldset--source-root"><div class="grunion-field-text-wrap"><label>Name</label><input name="name"><svg><path d="M0 0"></path></svg></div><div class="wp-block-button"><button type="submit">Send</button></div></div><input type="hidden" name="_wpnonce" value="nonce"></form></div>',
		array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) )
	);
	$assert( str_contains( $plain_root_runtime, '<fieldset class="source-root"><div class="wp-block-jetpack-contact-form"><div class="grunion-field-text-wrap"><label>Name</label><input name="name"><svg><path d="M0 0"></path></svg></div><div class="wp-block-button"><button type="submit">Send</button></div></div></fieldset><input type="hidden" name="_wpnonce" value="nonce">' ) && ! str_contains( $plain_root_runtime, 'ssi-source-root-fieldset' ) && 1 === substr_count( $plain_root_runtime, '<form ' ) && $plain_root_runtime === Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset( $plain_root_runtime, array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) ) ), 'provider-runtime-wraps-only-jetpack-native-field-list-and-preserves-handler-and-svg-markup', $plain_root_runtime );
	$plain_root_min_width_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form ssi-source-root-fieldset ssi-source-root-fieldset--source-root"><div class="grunion-field-text-wrap"><input></div></div><input type="hidden" name="_wpnonce"></form></div>',
		array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) )
	);
	$assert( str_contains( $plain_root_min_width_runtime, '<fieldset class="source-root"><div class="wp-block-jetpack-contact-form">' ) && ! str_contains( $plain_root_min_width_runtime, 'min-width' ) && ! str_contains( $plain_root_min_width_runtime, '264px' ), 'provider-runtime-leaves-authored-fieldset-min-width-rules-to-the-source-class', $plain_root_min_width_runtime );
	$unmarked_plain_root_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset( '<div class="wp-block-jetpack-contact-form"><form class="jetpack-contact-form__form"><div class="grunion-field-text-wrap"><input></div></form></div>', array( 'attrs' => array() ) );
	$assert( ! str_contains( $unmarked_plain_root_runtime, '<fieldset' ), 'provider-runtime-does-not-affect-forms-without-a-proven-plain-root-fieldset' );
	$partial_plain_root_fieldset = $plain_root_fieldset;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['parent'] = null;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['depth'] = 0;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['order'] = 1;
	$partial_plain_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $partial_plain_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_plain_root_fieldset = $plain_root_fieldset;
	$nested_plain_root_fieldset['forms'][0]['control_topology']['nodes'][1]['tag'] = 'fieldset';
	$nested_plain_root_fieldset['forms'][0]['control_topology']['nodes'][1]['fieldset_semantics'] = 'plain_group';
	$nested_plain_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_plain_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$disabled_root_fieldset = $plain_root_fieldset;
	$disabled_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'disabled_group';
	$disabled_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $disabled_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $partial_plain_root_row['status'] ?? '' ) && 'skipped' === ( $nested_plain_root_row['status'] ?? '' ) && 'skipped' === ( $disabled_root_row['status'] ?? '' ) && ! str_contains( (string) ( $partial_plain_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ) && ! str_contains( (string) ( $nested_plain_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ) && ! str_contains( (string) ( $disabled_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ), 'partial-nested-and-disabled-fieldsets-remain-loss-gated' );
	$labelled_root_fieldset = $plain_root_fieldset;
	$labelled_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_root_fieldset );
	$labelled_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_root_fieldset_validation['forms'] ?? array() ) );
	$assert( 'skipped' === ( $labelled_root_fieldset_seed['forms'][0]['status'] ?? '' ) && false === ( $labelled_root_fieldset_seed['forms'][0]['runtime_mapped'] ?? true ) && 'unsupported_semantic_wrapper' === ( $labelled_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && ! in_array( 'provider_radio_fieldset_equivalent', array_column( $labelled_root_fieldset_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'provider-form-declines-labelled-root-fieldset-without-semantic-equivalence', wp_json_encode( array( 'validation' => $labelled_root_fieldset_validation, 'seed' => $labelled_root_fieldset_seed ) ) );
	$labelled_radio_group = array(
		'forms' => array(
			array(
				'selector' => 'form.wixui-form',
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'Chakra Healing Session', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'Custom Healing Session' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'bindings' => array(
					array(
						'schema' => 'generic/block-binding/v1', 'source_path' => 'website/cchfeedback/index.html', 'occurrence' => 1, 'role' => 'form',
						'search_block_markup' => '<form><div id="comp-kf7in602" class="wixui-radio-button-group"><fieldset role="radiogroup" aria-required="true"><legend><div data-testid="groupLabel">What was your treatment?</div></legend><div data-testid="radioGroup"><label><input type="radio" required name="comp-kf7in602" value="Chakra Healing Session"></label><label><input type="radio" name="comp-kf7in602" value="Custom Healing Session"></label></div></fieldset></div></form>',
					),
				),
				'control_topology' => array(
					'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false,
					'nodes' => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'labelled_group', 'legend' => 'What was your treatment?' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div' ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'label' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'control' => 0 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'tag' => 'label' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
					),
				),
			),
		),
	);
	$labelled_radio_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_radio_group );
	$labelled_radio_seed       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_radio_validation['forms'] ?? array() ) );
	$labelled_radio_row        = $labelled_radio_seed['forms'][0] ?? array();
	$labelled_radio_markup     = (string) ( $labelled_radio_row['block_markup'] ?? '' );
	$labelled_radio_receipt    = $labelled_radio_row['computed_layout_receipt'] ?? array();
	$assert( empty( $labelled_radio_validation['errors'] ) && 'mapped' === ( $labelled_radio_row['status'] ?? '' ) && true === ( $labelled_radio_row['runtime_mapped'] ?? false ) && 1 === ( $labelled_radio_row['field_count'] ?? 0 ), 'labelled-radio-fieldset-materializes-one-runtime-field', wp_json_encode( array( 'validation' => $labelled_radio_validation, 'seed' => $labelled_radio_seed ) ) );
	$assert( 1 === substr_count( $labelled_radio_markup, '<!-- wp:jetpack/field-radio ' ) && str_contains( $labelled_radio_markup, '<!-- wp:jetpack/label {"label":"What was your treatment?"} /-->' ) && str_contains( $labelled_radio_markup, '"options":["Chakra Healing Session","Custom Healing Session"]' ) && str_contains( $labelled_radio_markup, '"required":true' ), 'labelled-radio-fieldset-serializes-legend-required-state-and-ordered-options', $labelled_radio_markup );
	$assert( 'provider_radio_fieldset_equivalent' === ( $labelled_radio_receipt['operations'][0]['strategy'] ?? '' ) && 'semantic' === ( $labelled_radio_receipt['operations'][0]['dimension'] ?? '' ) && ! in_array( 'unsupported_semantic_wrapper', array_column( $labelled_radio_receipt['losses'] ?? array(), 'reason_code' ), true ), 'labelled-radio-fieldset-receipt-represents-semantics-without-waiver', wp_json_encode( $labelled_radio_receipt ) );
	$assert( $labelled_radio_markup === serialize_blocks( parse_blocks( $labelled_radio_markup ) ), 'labelled-radio-fieldset-serialized-block-round-trips-through-wordpress', $labelled_radio_markup );
	$ambiguous_radio_group = $labelled_radio_group;
	$ambiguous_radio_group['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'No preference' );
	$ambiguous_radio_group['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 3 );
	$ambiguous_radio_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $ambiguous_radio_group );
	$ambiguous_radio_seed       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $ambiguous_radio_validation['forms'] ?? array() ) );
	$assert( 'skipped' === ( $ambiguous_radio_seed['forms'][0]['status'] ?? '' ) && 'unsupported_semantic_wrapper' === ( $ambiguous_radio_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ), 'ambiguous-labelled-radio-fieldset-remains-loss-gated', wp_json_encode( $ambiguous_radio_seed ) );
	$legacy_without_submit = $forms_manifest;
	array_pop( $legacy_without_submit['forms'][0]['controls'] );
	$legacy_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( $legacy_without_submit );
	$legacy_without_submit_markup = (string) ( $legacy_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $legacy_without_submit_markup, '<!-- wp:button ' ) && str_contains( $legacy_without_submit_markup, '>Submit</button>' ), 'legacy-form-without-submit-keeps-default-provider-button' );
	$topology_without_submit = $topology_form;
	array_pop( $topology_without_submit['forms'][0]['controls'] );
	array_pop( $topology_without_submit['forms'][0]['control_topology']['nodes'] );
	$validated_topology_without_submit = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $topology_without_submit );
	$assert( empty( $validated_topology_without_submit['errors'] ), 'topology-without-submit-manifest-validates' );
	$topology_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology_without_submit['forms'] ) );
	$topology_without_submit_markup = (string) ( $topology_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $topology_without_submit_markup, '<!-- wp:button ' ) && str_contains( $topology_without_submit_markup, '>Submit</button>' ), 'topology-without-submit-gets-one-default-provider-button' );
	$invalid_topology = $topology_form;
	$invalid_topology['forms'][0]['control_topology']['truncated'] = true;
	$invalid_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $invalid_topology );
	$assert( empty( $invalid_validation['forms'] ) && str_contains( (string) ( $invalid_validation['errors'][0]['message'] ?? '' ), 'truncated' ), 'topology-truncation-is-reported-not-flattened' );
	$unsupported_tag = $topology_form;
	$unsupported_tag['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$unsupported_tag_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsupported_tag );
	$assert( ! empty( $unsupported_tag_validation['forms'] ) && empty( $unsupported_tag_validation['errors'] ), 'topology-canonical-wrapper-vocabulary-remains-compatible' );
	$unsupported_control = $topology_form;
	$unsupported_control['forms'][0]['controls'][1] = array( 'tag' => 'input', 'type' => 'file', 'name' => 'attachment', 'label' => 'Attachment' );
	$unsupported_control['forms'][0]['layout_graph'] = $layout_graph( array( $layout_node( 'control-1', array( 'display' => 'none' ), 'input' ) ) );
	$unsupported_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsupported_control );
	$unsupported_control_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsupported_control_validation['forms'] ) );
	$unsupported_control_row = $unsupported_control_seed['forms'][0] ?? array();
	$unsupported_control_losses = array_values( array_filter( $unsupported_control_row['computed_layout_receipt']['losses'] ?? array(), static fn ( $loss ): bool => 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' ) ) );
	$unsupported_control_markup = (string) ( $unsupported_control_row['block_markup'] ?? '' );
	$assert( empty( $unsupported_control_validation['errors'] ) && array( 'file' ) === ( $unsupported_control_row['skipped_types'] ?? array() ), 'unsupported-file-control-keeps-provider-skipped-type-diagnostic', wp_json_encode( $unsupported_control_row ) );
	$assert( empty( $unsupported_control_losses ) && empty( $unsupported_control_row['form_receipt_unaccepted_losses'] ) && 'file_upload' === ( $unsupported_control_row['unsupported_capabilities'][0]['capability'] ?? '' ), 'hidden-file-control-loss-is-replaced-by-targeted-upload-capability-diagnostic', wp_json_encode( $unsupported_control_row ) );
	$assert( 'mapped' === ( $unsupported_control_row['status'] ?? '' ) && str_contains( $unsupported_control_markup, 'First name' ) && str_contains( $unsupported_control_markup, 'Attachment' ) && str_contains( $unsupported_control_markup, 'Message' ) && strpos( $unsupported_control_markup, 'Attachment' ) < strpos( $unsupported_control_markup, 'Message' ) && str_contains( $unsupported_control_markup, 'requires Jetpack connection and a supported plan' ), 'unsupported-file-control-preserves-labelled-position-with-explicit-upload-limit', $unsupported_control_markup );
	$hidden_control = $topology_form;
	$hidden_control['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'ucfid', 'value' => '980337499904279388' );
	$hidden_control['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 4 );
	$hidden_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $hidden_control );
	$hidden_control_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $hidden_control_validation['forms'] ) );
	$hidden_control_row = $hidden_control_seed['forms'][0] ?? array();
	$hidden_control_markup = (string) ( $hidden_control_row['block_markup'] ?? '' );
	$hidden_control_losses = array_values( array_filter( $hidden_control_row['computed_layout_receipt']['losses'] ?? array(), static fn ( $loss ): bool => 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' ) ) );
	$assert( empty( $hidden_control_validation['errors'] ) && array() === $hidden_control_losses, 'hidden-control-plumbing-records-no-topology-loss' );
	$assert( 'mapped' === ( $hidden_control_row['status'] ?? '' ) && empty( $hidden_control_row['form_receipt_unaccepted_losses'] ), 'hidden-control-plumbing-keeps-the-form-materializable' );
	$assert( str_contains( $hidden_control_markup, 'First name' ) && str_contains( $hidden_control_markup, 'Message' ) && ! str_contains( $hidden_control_markup, 'ucfid' ), 'hidden-control-plumbing-is-dropped-without-disturbing-authored-fields' );
	// Search mode buttons are not lead-form submits, and a nested source label still
	// maps to Jetpack's one field label when it owns exactly one provider field.
	$native_controls = array(
		'forms' => array(
			array(
				'selector' => 'form.property-search',
				'controls' => array(
					array( 'tag' => 'button', 'type' => 'button', 'text' => 'Buy', 'class' => 'mode-active' ),
					array( 'tag' => 'button', 'type' => 'button', 'text' => 'Rent', 'class' => 'mode-idle' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'property_type', 'label' => 'Property type', 'options' => array( 'House', 'Apartment' ) ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'location', 'label' => 'Location' ),
					array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Search' ),
				),
				'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false, 'nodes' => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
					array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
					array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 3 ),
					array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 4 ),
				) ),
			),
			array(
				'selector' => 'form.valuation',
				'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request valuation' ) ),
				'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false, 'nodes' => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'label' ),
					array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
				) ),
			),
		),
	);
	$native_controls_valid = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_controls );
	$native_controls_seed  = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_controls_valid['forms'] ?? array() ) );
	$native_search         = $native_controls_seed['forms'][0] ?? array();
	$native_lead           = $native_controls_seed['forms'][1] ?? array();
	$assert( empty( $native_controls_valid['errors'] ) && 'mapped' === ( $native_search['status'] ?? '' ) && 'mapped' === ( $native_lead['status'] ?? '' ) && 2 === ( $native_search['field_count'] ?? 0 ) && 1 === ( $native_lead['field_count'] ?? 0 ), 'native-buttons-and-nested-labels-materialize-as-separate-provider-forms', wp_json_encode( $native_controls_seed ) );
	$assert( 2 === substr_count( (string) ( $native_search['block_markup'] ?? '' ), 'type="button"' ) && str_contains( (string) ( $native_search['block_markup'] ?? '' ), '>Buy</button>' ) && str_contains( (string) ( $native_search['block_markup'] ?? '' ), '>Rent</button>' ) && empty( $native_lead['form_receipt_unaccepted_losses'] ), 'native-mode-buttons-and-one-control-nested-label-keep-their-semantics', wp_json_encode( array( $native_search['computed_layout_receipt'] ?? array(), $native_lead['computed_layout_receipt'] ?? array() ) ) );
	$assert( (string) ( $native_search['block_markup'] ?? '' ) === serialize_blocks( parse_blocks( (string) ( $native_search['block_markup'] ?? '' ) ) ) && (string) ( $native_lead['block_markup'] ?? '' ) === serialize_blocks( parse_blocks( (string) ( $native_lead['block_markup'] ?? '' ) ) ), 'native-control-provider-markup-round-trips-through-wordpress' );
	$list_wrapper = $topology_form;
	$list_wrapper['forms'][0]['control_topology']['nodes'][0]['tag'] = 'ul';
	$list_wrapper['forms'][0]['layout_graph']['nodes'] = array();
	$list_wrapper_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $list_wrapper );
	$list_wrapper_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $list_wrapper_validation['forms'] ) );
	$list_wrapper_row = $list_wrapper_seed['forms'][0] ?? array();
	$list_wrapper_markup = (string) ( $list_wrapper_row['block_markup'] ?? '' );
	$assert( 'mapped' === ( $list_wrapper_row['status'] ?? '' ) && empty( $list_wrapper_row['form_receipt_unaccepted_losses'] ), 'list-grouping-wrapper-keeps-the-form-materializable' );
	$assert( str_contains( $list_wrapper_markup, 'First name' ) && str_contains( $list_wrapper_markup, 'Email' ) && ! str_contains( $list_wrapper_markup, '<ul' ), 'list-grouping-wrapper-flattens-into-provider-fields' );
	$deep_topology = $topology_form;
	$deep_nodes = array();
	for ( $depth = 0; $depth < 8; ++$depth ) {
		$deep_nodes[] = array( 'id' => 'wrapper-' . $depth, 'kind' => 'wrapper', 'parent' => 0 === $depth ? null : 'wrapper-' . ( $depth - 1 ), 'order' => 0, 'depth' => $depth, 'class' => 'depth-' . $depth );
	}
	$deep_nodes[] = array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-7', 'order' => 0, 'depth' => 8, 'control' => 0 );
	$deep_nodes[] = array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 );
	$deep_nodes[] = array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 );
	$deep_nodes[] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 3 );
	$deep_topology['forms'][0]['control_topology']['nodes'] = $deep_nodes;
	$deep_topology['forms'][0]['layout_graph']['nodes'] = array();
	$deep_topology_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $deep_topology );
	$deep_topology_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $deep_topology_validation['forms'] ) );
	$deep_topology_markup = (string) ( $deep_topology_seed['forms'][0]['block_markup'] ?? '' );
	$assert( empty( $deep_topology_validation['errors'] ) && ! str_contains( $deep_topology_markup, '<!-- wp:group ' ) && str_contains( $deep_topology_markup, 'First name' ), 'deep-topology-flattens-without-losing-provider-fields' );

	// --- Provider blocks are never claimed without the provider runtime --------
	$GLOBALS['ssi_jetpack_form_blocks_available'] = false;
	$unavailable_seed                              = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$unavailable_row                               = $unavailable_seed['forms'][0] ?? array();
	$assert( 'failed' === ( $unavailable_seed['status'] ?? '' ) && 'static_site_importer_form_provider_unavailable' === ( $unavailable_seed['code'] ?? '' ), 'seed-unavailable-provider-fails-explicitly' );
	$assert( 1 === ( $unavailable_seed['counts']['skipped'] ?? 0 ), 'seed-unavailable-provider-skips-form' );
	$assert( 'provider_unavailable' === ( $unavailable_row['reason'] ?? '' ), 'seed-unavailable-provider-reason' );
	$assert( false === ( $unavailable_row['runtime_mapped'] ?? true ), 'seed-unavailable-provider-not-runtime-mapped' );
	$assert( empty( $unavailable_row['block_markup'] ), 'seed-unavailable-provider-emits-no-block-markup' );
	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	// Canonical declaration bindings carry authored presentation into the adapter.
	$cara_entity    = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/contact.html',
			'selector'    => 'form.contact-form',
			'form'        => array(
				'class'               => 'contact-form',
				'context_before'      => array(
					array( 'type' => 'heading', 'level' => 2, 'text' => 'Contact Me', 'class' => 'font-serif text-2xl' ),
					array( 'type' => 'paragraph', 'text' => '* Indicates required field' ),
				),
				'submit_presentation' => array(
					'text'          => 'Submit',
					'classes'       => array( 'wsite-button' ),
					'label_classes' => array( 'wsite-button-inner' ),
				),
			),
			'controls'    => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'aria-required' => 'true' ), array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'aria-required' => 'true', 'height' => '200px' ), array( 'tag' => 'input', 'type' => 'submit' ) ),
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/contact.html', 'search_block_markup' => '<!-- wp:html --><form class="contact-form"></form><!-- /wp:html -->', 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$cara_grafted   = (string) ( Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $cara_entity ) ) )['forms'][0]['block_markup'] ?? '' );
	$cara_parsed    = array_values( array_filter( parse_blocks( $cara_grafted ), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$cara_contact   = $cara_parsed[0] ?? array();
	$cara_inners    = array_column( array_values( array_filter( $cara_contact['innerBlocks'] ?? array(), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) ), 'blockName' );
	$assert( str_contains( $cara_grafted, '>Contact Me</h2>' ) && str_contains( $cara_grafted, 'class="wp-block-heading font-serif text-2xl"' ) && str_contains( $cara_grafted, '"className":"font-serif text-2xl"' ) && str_contains( $cara_grafted, '<p>* Indicates required field</p>' ) && str_contains( $cara_grafted, '"required":true' ) && str_contains( $cara_grafted, 'wsite-button' ), 'canonical-binding-presentation-reaches-provider-markup' );
	$assert(
		1 === count( $cara_parsed )
			&& 'jetpack/contact-form' === ( $cara_contact['blockName'] ?? '' )
			&& array( 'core/heading', 'core/paragraph' ) === array_slice( $cara_inners, 0, 2 ),
		'canonical-in-form-context-is-emitted-as-contact-form-inner-blocks',
		wp_json_encode( array( 'names' => array_column( $cara_parsed, 'blockName' ), 'inners' => $cara_inners, 'markup' => $cara_grafted ) )
	);


	// --- Provider override routes to a different registered adapter ----------
	add_filter(
		'static_site_importer_entity_materializers',
		static function ( array $adapters ): array {
			$adapters['gravity_forms_adapter'] = array(
				'id'         => 'gravity_forms_adapter',
				'capability' => 'form',
				'provider'   => 'gravity_forms',
				'waiver_arg' => 'allow_missing_gravity_forms',
				'rollback_contract_id' => 'test/gravity-forms-rollback/v1',
			);
			return $adapters;
		}
	);
	add_filter( 'ssi_form_plugin', static fn ( string $provider ): string => 'gravity_forms' );

	$assert( 'gravity_forms' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-provider-override' );
	$overridden = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'gravity_forms_adapter' === ( $overridden['id'] ?? '' ), 'form-adapter-routes-to-override' );
	// Shop capability stays on the default provider despite the form override.
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-provider-unaffected-by-form-override' );

	// --- Real source boxes keep their own elements and their own layout ------
	// These entities are the retained materialization evidence from a Wix import whose
	// forms were previously declined outright. Each field sits inside nested source
	// boxes addressed by ancestor-dependent selectors, and the source drives display
	// through its own custom properties.
	$kmr = array();
	foreach ( array( 0, 1, 2, 3 ) as $kmr_index ) {
		$kmr_fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/kmr-form-' . $kmr_index . '.json' ), true );
		$kmr_valid   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $kmr_fixture ) ) );
		$assert( empty( $kmr_valid['errors'] ), 'kmr-source-form-' . $kmr_index . '-validates', wp_json_encode( $kmr_valid['errors'] ?? array() ) );
		$kmr[ $kmr_index ] = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $kmr_valid['forms'] ?? array() ) )['forms'][0] ?? array();
	}
	foreach ( array( 0 => 8, 1 => 8, 2 => 2 ) as $kmr_index => $expected_fields ) {
		$kmr_row    = $kmr[ $kmr_index ];
		$kmr_fields = array_values( array_filter( $kmr_row['field_blocks'] ?? array(), static fn( string $name ): bool => 'core/button' !== $name ) );
		$assert(
			'mapped' === ( $kmr_row['status'] ?? '' ) && true === ( $kmr_row['runtime_mapped'] ?? false ) && array() === ( $kmr_row['form_receipt_unaccepted_losses'] ?? array() ),
			'kmr-source-form-' . $kmr_index . '-materializes-natively',
			wp_json_encode( array_column( $kmr_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) )
		);
		$assert( $expected_fields === count( $kmr_fields ), 'kmr-source-form-' . $kmr_index . '-preserves-every-field', wp_json_encode( $kmr_fields ) );
		$assert( str_contains( (string) ( $kmr_row['block_markup'] ?? '' ), '<!-- wp:jetpack/contact-form' ), 'kmr-source-form-' . $kmr_index . '-is-a-native-provider-block' );
	}
	$assert( 'Send' === ( $kmr[0]['submit_text'] ?? '' ) && 'JOIN' === ( $kmr[2]['submit_text'] ?? '' ), 'kmr-source-forms-preserve-their-submit-labels' );
	$kmr_css = (string) ( $kmr[2]['provider_layout_overlay_css']['css'] ?? '' );
	$kmr_map = array_column( $kmr[2]['provider_layout_target_map']['targets'] ?? array(), 'selector', 'node' );
	$kmr_scope = (string) ( $kmr[2]['provider_layout_target_map']['scope'] ?? '' );
	$assert( $kmr_scope === ( $kmr_map['form-box'] ?? '' ) && str_contains( $kmr_css, $kmr_scope . '{align-self:start;grid-area:4 / 1 / 5 / 2' ) === false && str_contains( $kmr_css, $kmr_scope . '{align-self:start;grid-area:1 / 1 / 2 / 2;justify-self:start}' ), 'source-form-box-placement-lands-on-the-provider-block-wrapper', $kmr_css );
	preg_match( '/:not\(:has\(> form\.jetpack-contact-form__form\)\)\{([^}]*grid-template-columns:100%[^}]*)\}/', $kmr_css, $kmr_grid_rule );
	$kmr_grid_declarations = array_column( \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer::declarations( $kmr_grid_rule[1] ?? '', array( 'display', 'grid-template-columns', 'gap' ) ), 'value', 'name' );
	$assert( str_contains( $kmr_css, ' > form.jetpack-contact-form__form:not(:has(.wp-block-jetpack-contact-form)), ' ) && '100%' === ( $kmr_grid_declarations['grid-template-columns'] ?? '' ) && 'grid' === ( $kmr_grid_declarations['display'] ?? '' ) && '0' === ( $kmr_grid_declarations['gap'] ?? '' ), 'all-controls-source-box-establishes-the-provider-form-container', $kmr_css );
	$assert( 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}-wrap\{[^}]*grid-area:4 \/ 1 \/ 5 \/ 2[^}]*width:156px(?:;[^}]*)?\}/', $kmr_css ), 'single-field-source-box-keeps-its-own-grid-placement', $kmr_css );
	$assert( str_contains( $kmr_css, 'display:var(--display)' ) && str_contains( $kmr_css, 'justify-content:var(--label-align)' ), 'source-owned-custom-properties-survive-transposition', $kmr_css );
	$mixed_row_graph = array(
		'schema'   => 'generic/computed-layout-graph/v2',
		'nodes'    => array(
			array( 'id' => 'grid', 'kind' => 'container', 'layout' => array( 'display' => 'grid', 'columns' => '100%' ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '1 / 1 / 2 / 2' ) ),
			array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '1 / 1 / 2 / 2' ) ),
			array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '2 / 1 / 3 / 2', 'width' => '611px' ) ),
		),
		'variants' => array(),
	);
	$mixed_row_result = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $mixed_row_graph );
	$mixed_row_layout = array_column( $mixed_row_result['nodes'], 'layout', 'id' );
	$uniform_row_result = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( array(
		'schema'   => 'generic/computed-layout-graph/v2',
		'nodes'    => array(
			array( 'id' => 'grid', 'kind' => 'container', 'layout' => array( 'display' => 'grid', 'columns' => '100%' ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '4 / 1 / 5 / 2' ) ),
			array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '4 / 1 / 5 / 2' ) ),
		),
		'variants' => array(),
	) );
	$assert(
		! isset( $mixed_row_layout['wrapper-0']['area'] ) && ! isset( $mixed_row_layout['wrapper-2']['area'] ) && '611px' === ( $mixed_row_layout['wrapper-2']['width'] ?? '' ) && array( 'display' => 'grid' ) === $mixed_row_layout['grid'] && array( 'display' => 'grid', 'columns' => '100%' ) === array_column( $uniform_row_result['nodes'], 'layout', 'id' )['grid'] && '4 / 1 / 5 / 2' === ( array_column( $uniform_row_result['nodes'], 'layout', 'id' )['wrapper-0']['area'] ?? '' ),
		'source-rows-that-neither-pair-with-each-box-nor-share-one-band-drop-their-provider-placement',
		wp_json_encode( array( 'mixed' => $mixed_row_layout, 'uniform' => array_column( $uniform_row_result['nodes'], 'layout', 'id' ) ) )
	);
	// Explicit placement does not depend on the provider's row sequence: when every
	// child keeps its own provider element, the mixed rows are kept as authored (#2005).
	$placed_all    = array( 'wrapper-0' => 'a', 'wrapper-1' => 'b', 'wrapper-2' => 'c' );
	$placed_layout = array_column( Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $mixed_row_graph, null, $placed_all )['nodes'], 'layout', 'id' );
	$partly_placed = array_column( Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $mixed_row_graph, null, array( 'wrapper-0' => 'a', 'wrapper-2' => 'c' ) )['nodes'], 'layout', 'id' );
	$assert(
		'1 / 1 / 2 / 2' === ( $placed_layout['wrapper-0']['area'] ?? '' ) && '1 / 1 / 2 / 2' === ( $placed_layout['wrapper-1']['area'] ?? '' ) && '2 / 1 / 3 / 2' === ( $placed_layout['wrapper-2']['area'] ?? '' ) && array( 'display' => 'grid', 'columns' => '100%' ) === $placed_layout['grid']
			&& ! isset( $partly_placed['wrapper-0']['area'] ) && array( 'display' => 'grid' ) === $partly_placed['grid'],
		'explicitly-placed-mesh-rows-keep-their-source-grid-only-when-every-child-is-a-provider-element',
		wp_json_encode( array( 'placed' => $placed_layout, 'partly' => $partly_placed ) )
	);
	// A sibling that another strategy already represented is absent from the overlay
	// graph; on its own the remainder looks like an ordered sequence.
	$reduced_row_graph          = $mixed_row_graph;
	$reduced_row_graph['nodes'] = array_values( array_filter( $mixed_row_graph['nodes'], static fn ( array $node ): bool => 'wrapper-1' !== $node['id'] ) );
	$reduced_row_layout         = array_column( Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $reduced_row_graph, $mixed_row_graph )['nodes'], 'layout', 'id' );
	$assert(
		! isset( $reduced_row_layout['wrapper-2']['area'] ) && '611px' === ( $reduced_row_layout['wrapper-2']['width'] ?? '' ) && isset( $reduced_row_layout['wrapper-0'] ) && ! isset( $reduced_row_layout['wrapper-0']['area'] ),
		'partly-represented-sibling-sets-are-judged-against-the-complete-source-graph',
		wp_json_encode( $reduced_row_layout )
	);
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $kmr[2]['provider_layout_overlay_css'] ?? null ), 'kmr-overlay-passes-stylesheet-admission' );
	// Wix's mesh layout makes the form and its first box `position: relative` and
	// leaves the field grid inside them `static` (#2005). All three collapse into the
	// provider's one form element; a relative box without insets paints where a
	// static one does and stays the containing block of everything inside the
	// static box, so the pair is not a layout contradiction. The fixture is the
	// captured device-split (desktop + phone) entity, with selector diagnostics
	// trimmed.
	$mesh_form = static function ( array $wrapper_patch ): array {
		$fixture = json_decode( (string) gzdecode( (string) file_get_contents( __DIR__ . '/fixtures/wix-mesh-contact-form-v3.json.gz' ) ), true );
		foreach ( $fixture['layout_graph']['variants'] as &$variant ) {
			if ( 'wrapper-1' !== $variant['node'] ) {
				continue;
			}
			foreach ( $wrapper_patch as $fact => $value ) {
				$variant['layout_patch'][ $fact ]         = $value;
				$variant['precedence'][ $fact ]           = $variant['precedence']['position'];
				$variant['provenance'][0]['properties'][] = $fact;
			}
			ksort( $variant['layout_patch'] );
			ksort( $variant['precedence'] );
			$variant['provenance'][0]['properties'] = array_values( array_unique( $variant['provenance'][0]['properties'] ) );
		}
		unset( $variant );
		$valid = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fixture ) ) );
		$row   = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $valid['forms'] ?? array() ) )['forms'][0] ?? array();
		return array( 'errors' => $valid['errors'] ?? array(), 'row' => $row, 'unaccepted' => array_column( $row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) );
	};
	$mesh_static_grid = $mesh_form( array() );
	$assert(
		empty( $mesh_static_grid['errors'] ) && 'mapped' === ( $mesh_static_grid['row']['status'] ?? '' ) && true === ( $mesh_static_grid['row']['runtime_mapped'] ?? false ) && array() === $mesh_static_grid['unaccepted'] && str_contains( (string) ( $mesh_static_grid['row']['block_markup'] ?? '' ), '<!-- wp:jetpack/contact-form' ),
		'static-field-grid-inside-relative-form-boxes-merges-into-the-provider-form',
		wp_json_encode( array( 'errors' => $mesh_static_grid['errors'], 'status' => $mesh_static_grid['row']['status'] ?? null, 'unaccepted' => $mesh_static_grid['unaccepted'] ) )
	);
	$mesh_markup = (string) ( $mesh_static_grid['row']['block_markup'] ?? '' );
	$assert(
		array( 'jetpack/field-text', 'jetpack/field-text', 'jetpack/field-email', 'jetpack/field-textarea' ) === array_values( array_filter( $mesh_static_grid['row']['field_blocks'] ?? array(), static fn( string $name ): bool => 'core/button' !== $name ) )
			&& 'Send' === ( $mesh_static_grid['row']['submit_text'] ?? '' )
			&& str_contains( $mesh_markup, 'First Name' ) && str_contains( $mesh_markup, 'Last Name' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-email (?=[^\n]*"required":true)[^\n]* -->/', $mesh_markup ),
		'merged-wix-mesh-form-keeps-its-fields-labels-required-email-and-submit-text',
		$mesh_markup
	);
	$mesh_static_grid_css = (string) ( $mesh_static_grid['row']['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		3 === preg_match_all( '/-wrap\{[^}]*grid-area:1 \/ 1 \/ 2 \/ 2[^}]*left:(?:19|219|419)px[^}]*width:180px[^}]*\}/', $mesh_static_grid_css ) && str_contains( $mesh_static_grid_css, 'grid-template-columns:100%' ) && 1 === preg_match( '/-wrap\{[^}]*grid-area:2 \/ 1 \/ 3 \/ 2[^}]*\}/', $mesh_static_grid_css ),
		'wix-mesh-row-keeps-its-three-fields-in-one-source-grid-cell',
		$mesh_static_grid_css
	);
	$assert( ! str_contains( $mesh_static_grid_css, 'position:static' ), 'merged-static-field-grid-does-not-unset-the-relative-form-box', $mesh_static_grid_css );
	$assert( 1 === preg_match( '/^\.ssi-form-[a-f0-9]{12}\{pointer-events:auto\}$/m', $mesh_static_grid_css ), 'merged-mesh-box-pointer-events-none-does-not-disable-the-provider-form', $mesh_static_grid_css );
	// Every presentation fact in a responsive capture is scoped to a media query,
	// so the form has no unconditional presentation pass. Provider-default resets
	// still belong outside every query: without them Jetpack's
	// `min-height: var(--jetpack--contact-form--input-height)` (46px at runtime)
	// floors the 36px source Send button and its wrapper.
	$mesh_unconditional_css = (string) preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $mesh_static_grid_css );
	$assert(
		1 === preg_match( '/(?:^|\})\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? \.ssi-node-[a-f0-9]{12} > \.wp-block-button__link\{[^}]*min-height:0[;}]/m', $mesh_unconditional_css ),
		'media-only-mesh-submit-link-still-releases-the-provider-min-height',
		$mesh_unconditional_css
	);
	$assert(
		1 === preg_match( '/(?:^|\})\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? \.ssi-node-[a-f0-9]{12}\{--jetpack--contact-form--input-height:auto\}/m', $mesh_unconditional_css ),
		'mesh-submit-button-wrapper-releases-the-provider-min-height-so-the-source-height-holds',
		$mesh_unconditional_css
	);
	$mesh_static_inset = $mesh_form( array( 'left' => '12px' ) );
	$assert(
		empty( $mesh_static_inset['errors'] ) && 'skipped' === ( $mesh_static_inset['row']['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', $mesh_static_inset['unaccepted'], true ),
		'static-box-with-an-inert-inset-still-fails-closed-against-a-relative-form-box',
		wp_json_encode( array( 'errors' => $mesh_static_inset['errors'], 'status' => $mesh_static_inset['row']['status'] ?? null, 'unaccepted' => $mesh_static_inset['unaccepted'] ) )
	);
	$mesh_absolute_grid = $mesh_form( array( 'position' => 'absolute' ) );
	$assert(
		empty( $mesh_absolute_grid['errors'] ) && 'skipped' === ( $mesh_absolute_grid['row']['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', $mesh_absolute_grid['unaccepted'], true ),
		'absolute-field-grid-inside-relative-form-boxes-still-fails-closed',
		wp_json_encode( array( 'errors' => $mesh_absolute_grid['errors'], 'status' => $mesh_absolute_grid['row']['status'] ?? null, 'unaccepted' => $mesh_absolute_grid['unaccepted'] ) )
	);
	// A source box chain deeper than the provider's own element pair cannot keep every
	// box, so it stays a decline instead of claiming an equivalence it cannot hold.
	$assert(
		'skipped' === ( $kmr[3]['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', array_column( $kmr[3]['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'source-box-chain-deeper-than-the-provider-shape-fails-closed',
		wp_json_encode( array_column( $kmr[3]['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) )
	);
	$unsafe_custom_property = Static_Site_Importer_Provider_Layout_Overlay::compile(
		$layout_graph( array( $layout_node( 'form', array( 'display' => 'var(--display); color:red' ), 'form' ) ) ),
		$root_map
	);
	$assert( '' === $unsafe_custom_property['css'] && 'unsafe_layout_value' === ( $unsafe_custom_property['losses'][0]['reason_code'] ?? '' ), 'custom-property-passthrough-still-rejects-injected-declarations' );
