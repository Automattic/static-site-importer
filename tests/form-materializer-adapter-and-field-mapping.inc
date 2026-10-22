<?php
/**
 * Form materializer smoke suite: provider adapter selection, manifest validation and Jetpack field mapping.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$punctuated_source_condition = array( 'kind' => 'media', 'query' => '(max-width: 48rem)' );
	$punctuated_source_form      = array(
		'forms' => array(
			array(
				'controls'     => array( array( 'tag' => 'input', 'type' => 'email' ) ),
				'layout_graph' => $v2_layout_graph(
					array(
						array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
					)
				),
			)
		)
	);
	$punctuated_source_form['forms'][0]['layout_graph']['variants'][] = array(
		'node'         => 'form',
		'condition'    => $punctuated_source_condition,
		'layout_patch' => array( 'display' => 'flex' ),
		'precedence'   => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ),
		'provenance'   => array( array( 'source_path' => 'website/comms-&-use-cases/index.html', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.contact-form', 'condition' => $punctuated_source_condition, 'properties' => array( 'display' ) ) ),
	);
	$assert( empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $punctuated_source_form )['errors'] ), 'form-layout-provenance-accepts-canonical-punctuated-artifact-path' );

	// --- Default provider selection -----------------------------------------
	$assert( 'jetpack' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-default-provider-jetpack' );
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-default-provider-woocommerce' );

	$form_adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'jetpack_contact_form' === ( $form_adapter['id'] ?? '' ), 'form-adapter-resolves-jetpack' );
	$assert( 'form' === ( $form_adapter['capability'] ?? '' ), 'form-adapter-capability' );
	$assert( 'allow_missing_jetpack' === ( $form_adapter['waiver_arg'] ?? '' ), 'form-adapter-waiver' );
	$assert( is_callable( $form_adapter['dependencies'][0]['preparation_callback'] ?? null ), 'form-adapter-prepares-provider-runtime' );
	$all_jetpack_blocks = $GLOBALS['ssi_jetpack_registered_form_blocks'];
	$GLOBALS['ssi_jetpack_registered_form_blocks'] = array( 'jetpack/contact-form', 'jetpack/field-text' );
	$assert( ! Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_available(), 'partial-provider-block-registration-is-unavailable' );
	$GLOBALS['ssi_jetpack_registered_form_blocks'] = $all_jetpack_blocks;
	$jetpack_dependency = $form_adapter['dependencies'][0] ?? array();
	$assert( in_array( 'jetpack/option', $jetpack_dependency['missing_apis'] ?? array(), true ), 'form-adapter-declares-field-children' );
	$assert( Static_Site_Importer_Jetpack_Forms_Runtime::required_block_types() === ( $jetpack_dependency['provider_readiness']['required_block_types'] ?? array() ), 'form-adapter-declares-every-emitted-block' );

	// --- Woo path unaffected -------------------------------------------------
	$product_adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$assert( 'woocommerce_simple_product' === ( $product_adapter['id'] ?? '' ), 'product-adapter-unchanged' );
	$assert( 'shop' === ( $product_adapter['capability'] ?? '' ), 'product-adapter-capability-shop' );
	$assert( 'allow_missing_woocommerce' === ( $product_adapter['waiver_arg'] ?? '' ), 'product-adapter-waiver-unchanged' );

	// --- Forms manifest validation rejects submit-only forms ----------------
	$submit_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array( array( 'selector' => 'form#x', 'controls' => array( array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) )
	);
	$assert( array() === $submit_only['forms'], 'submit-only-form-rejected' );
	$assert( ! empty( $submit_only['errors'] ), 'submit-only-form-error-recorded' );
	$responsive_identity_forms = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array(
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'a', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ) ),
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'b', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ) ),
		) )
	);
	$assert( empty( $responsive_identity_forms['errors'] ) && 2 === count( $responsive_identity_forms['forms'] ), 'responsive-form-identities-remain-distinct-during-validation' );

	// Truncated graphs remain producer fallback evidence, never runtime input.
	$truncated_css   = str_repeat( '@media (min-width:1px){', 9 ) . '.form{display:grid}' . str_repeat( '}', 9 );
	$truncated_forms = str_repeat( '<form class="form"><input name="email"><button type="submit">Send</button></form>', 8 );
	$truncated_result = ( new $artifact_compiler() )->compile(
		array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<style>' . $truncated_css . '</style>' . $truncated_forms ) )
	)->toArray();
	$truncated_fallbacks = array_values( array_filter( $truncated_result['fallbacks'] ?? array(), static fn( mixed $fallback ): bool => true === ( $fallback['layout_graph']['truncated'] ?? false ) ) );
	$truncated_forms_declaration = array_values( array_filter( $truncated_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( mixed $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) )[0] ?? array();
	$truncated_runtime_forms = $truncated_forms_declaration['payload']['entities'] ?? array();
	$truncated_validation    = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $truncated_runtime_forms ) );
	$assert( 8 === count( $truncated_fallbacks ) && 8 === count( $truncated_runtime_forms ) && array() === array_filter( $truncated_runtime_forms, static fn( mixed $form ): bool => array_key_exists( 'layout_graph', $form ) ) && empty( $truncated_validation['errors'] ), 'truncated-layout-graphs-are-omitted-before-strict-runtime-validation' );

	// --- Jetpack form seeder maps controls to contact-form blocks -----------
	$seed = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$assert( 'completed' === ( $seed['status'] ?? '' ), 'seed-status-completed' );
	$assert( 1 === ( $seed['counts']['mapped'] ?? 0 ), 'seed-one-form-mapped' );
	$row    = $seed['forms'][0] ?? array();
	$markup = (string) ( $row['block_markup'] ?? '' );
	$assert( true === ( $row['runtime_mapped'] ?? false ), 'seed-form-runtime-mapped' );
	$assert( 'mapped' === ( $row['mapping_decision']['status'] ?? '' ) && 8 === count( $row['mapping_decision']['supported_fields'] ?? array() ) && array() === ( $row['mapping_decision']['losses'] ?? null ), 'mapped-form-has-loss-free-provider-decision' );
	$assert( 8 === ( $row['field_count'] ?? 0 ), 'seed-eight-fields-mapped' );
	$assert( str_contains( $markup, 'wp:jetpack/contact-form' ), 'markup-contact-form' );
	$assert( str_contains( $markup, 'wp:jetpack/field-text' ), 'markup-field-text' );
	$assert( str_contains( $markup, 'wp:jetpack/field-email' ), 'markup-field-email' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone' ), 'markup-preserves-telephone-field-semantics' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone {"showCountrySelector":false' ) && str_contains( $markup, 'wp:jetpack/phone-input' ) && ! str_contains( $markup, '"type":"tel"' ), 'markup-telephone-uses-canonical-phone-input' );
	$assert( str_contains( $markup, 'wp:jetpack/field-number' ), 'markup-field-number' );
	$assert( str_contains( $markup, 'wp:jetpack/field-select' ), 'markup-field-select' );
	$assert( str_contains( $markup, 'wp:jetpack/field-radio' ), 'markup-field-radio' );
	$assert( str_contains( $markup, 'wp:jetpack/field-checkbox' ), 'markup-field-checkbox' );
	$assert( str_contains( $markup, 'wp:jetpack/field-textarea' ), 'markup-field-textarea' );
	$interleaved_form = array(
		'form'     => array(
			'interleaved_context' => true,
			'context_before'      => array( array( 'type' => 'heading', 'text' => 'Contact us' ) ),
			'context_after'       => array( array( 'type' => 'paragraph', 'text' => 'We will reply soon.' ) ),
		),
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ),
			array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
			array( 'tag' => 'input', 'type' => 'file', 'name' => 'attachment', 'label' => 'Attach files' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	$interleaved_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $interleaved_form ) ) )['forms'][0] ?? array();
	$interleaved_markup = (string) ( $interleaved_row['block_markup'] ?? '' );
	$assert( 'mapped' === ( $interleaved_row['status'] ?? '' ) && true === ( $interleaved_row['runtime_mapped'] ?? false ), 'interleaved-context-form-is-provider-mapped' );
	$assert( 3 === ( $interleaved_row['field_count'] ?? 0 ) && str_contains( $interleaved_markup, 'wp:jetpack/field-text' ) && str_contains( $interleaved_markup, 'wp:jetpack/field-email' ) && str_contains( $interleaved_markup, 'wp:jetpack/field-textarea' ), 'interleaved-context-supported-fields-remain-submittable-provider-fields' );
	$assert( str_contains( $interleaved_markup, 'wp:heading' ) && str_contains( $interleaved_markup, 'Contact us' ) && str_contains( $interleaved_markup, 'wp:paragraph' ) && str_contains( $interleaved_markup, 'We will reply soon.' ), 'interleaved-context-is-editable-block-content' );
	$assert( in_array( 'file', $interleaved_row['skipped_types'] ?? array(), true ) && 'file_upload' === ( $interleaved_row['unsupported_capabilities'][0]['capability'] ?? '' ) && 'jetpack_upload_endpoint_requires_connected_site_and_supported_plan' === ( $interleaved_row['unsupported_capabilities'][0]['reason_code'] ?? '' ) && str_contains( $interleaved_markup, 'Attach files' ) && str_contains( $interleaved_markup, 'requires Jetpack connection and a supported plan' ), 'unsupported-file-upload-is-diagnostic-and-retains-labelled-position' );
	// A form builder keeps its own status copy hidden in the form until a
	// submission succeeds (Wix: "Thanks for submitting!"). Jetpack renders its
	// own confirmation, so hidden copy is left out while visible copy stays
	// (Automattic/blocks-engine#2560).
	$status_entity = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'form'     => array(
				'context_after' => array(
					array( 'type' => 'paragraph', 'text' => 'Thanks for submitting!', 'hidden' => array( 'property' => 'visibility', 'value' => 'hidden', 'selector' => '#msg', 'source_path' => 'inline-style' ) ),
					array( 'type' => 'paragraph', 'text' => 'Something went wrong.', 'hidden' => array( 'property' => 'display', 'value' => 'none', 'selector' => '#err', 'source_path' => 'site.css' ) ),
					array( 'type' => 'paragraph', 'text' => 'We reply within a day.' ),
				),
			),
			'controls' => array(
				array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
		)
	);
	$status_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $status_entity ) ) )['forms'][0] ?? array();
	$status_markup = (string) ( $status_row['block_markup'] ?? '' );
	$assert(
		'visibility' === ( $status_entity['form']['context_after'][0]['hidden']['property'] ?? null ) && 'none' === ( $status_entity['form']['context_after'][1]['hidden']['value'] ?? null ) && ! isset( $status_entity['form']['context_after'][2]['hidden'] ),
		'producer-hidden-context-fact-survives-form-normalization',
		wp_json_encode( $status_entity['form'] )
	);
	$assert(
		'mapped' === ( $status_row['status'] ?? '' ) && str_contains( $status_markup, 'We reply within a day.' ) && ! str_contains( $status_markup, 'Thanks for submitting!' ) && ! str_contains( $status_markup, 'Something went wrong.' ),
		'hidden-form-status-copy-is-left-out-while-visible-copy-stays',
		$status_markup
	);
	$assert( str_contains( $markup, 'wp:button' ) && ! str_contains( $markup, 'wp:jetpack/button' ), 'markup-canonical-core-submit-button' );
	$assert( 1 === substr_count( $markup, '<!-- wp:button ' ) && str_contains( $markup, '<button type="submit" class="wp-block-button__link wp-element-button">Send message</button>' ), 'source-submit-control-emits-one-canonical-button' );
	$labelled_submit_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/subscribe', 'method' => 'post' ),
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
						array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send', 'class' => 'cta', 'label_classes' => 'cta-label typography-small' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $labelled_submit_markup, '<span class="cta-label typography-small">Send</span>' ) && ! str_contains( $labelled_submit_markup, '&lt;span' ), 'source-submit-label-element-is-saved-as-markup-rather-than-escaped-text', $labelled_submit_markup );
	$unsafe_label_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/subscribe', 'method' => 'post' ),
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
						array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send', 'class' => 'cta', 'label_classes' => 'ok "><script>alert(1)</script>' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $unsafe_label_markup, '<span class="ok">Send</span>' ) && ! str_contains( $unsafe_label_markup, '<script' ), 'submit-label-classes-that-are-not-plain-tokens-are-refused', $unsafe_label_markup );
	// A source submit can own a leading inline icon drawn inside its own control
	// box, ahead of the label; the producer captures it as a bounded
	// presentation-graph visual part on the control. The materialized button must
	// carry that icon into its saved content or the rendered submit loses the
	// icon and the authored gap beside it.
	$icon_submit_form = array(
		'forms' => array( array(
			'selector'           => 'form.contact',
			'form'               => array( 'action' => '/contact', 'method' => 'post' ),
			'controls'           => array(
				array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
				array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send Message', 'class' => 'w-full bg-primary flex items-center justify-center gap-2' ),
			),
			'presentation_graph' => array(
				'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ),
				'controls'           => array(),
				'visual_parts'       => array(
					array(
						'id'              => 'control-1-svg-0',
						'index'           => 1,
						'kind'            => 'inline_svg',
						'label_side'      => 'before',
						'source_selector' => 'form.contact > button:nth-of-type(1) > svg:nth-of-type(1)',
						'markup'          => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-send" aria-hidden="true"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"></path><path d="m21.854 2.147-10.94 10.939"></path></svg>',
						'source_css'      => array( 'state' => 'unknown' ),
					),
					array(
						'id'              => 'control-0-svg-0',
						'index'           => 0,
						'kind'            => 'inline_svg',
						'label_side'      => 'before',
						'source_selector' => 'form.contact > div:nth-of-type(1) > svg:nth-of-type(1)',
						'markup'          => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><circle cx="6" cy="6" r="5"></circle></svg>',
						'source_css'      => array( 'state' => 'unknown' ),
					),
				),
				'visual_groups'      => array(),
				'control_containers' => array(),
				'group_captions'     => array(),
				'variants'           => array(),
				'diagnostics'        => array(),
			),
		) ),
	);
	$icon_submit_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $icon_submit_form );
	$icon_submit_markup    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $icon_submit_validated['forms'] ?? array() ) )['forms'][0]['block_markup'] ?? '';
	$assert(
		empty( $icon_submit_validated['errors'] )
			&& 1 === preg_match( '/<button type="submit" class="wp-block-button__link wp-element-button[^"]*"><svg [^>]*class="lucide lucide-send"[^>]*>.*<\/svg>Send Message<\/button>/s', $icon_submit_markup )
			&& ! str_contains( $icon_submit_markup, '<circle cx="6"' )
			&& $icon_submit_markup === serialize_blocks( parse_blocks( $icon_submit_markup ) ),
		'source-submit-leading-inline-icon-is-carried-into-the-materialized-button-content',
		$icon_submit_markup
	);
	// Only parts that pass the portable inline-SVG admission are saved, and the
	// list is bounded, so a captured icon can never smuggle markup into the
	// saved button content.
	$direct_icon_block  = Static_Site_Importer_Form_Field_Markup::submit_button_block(
		'Send',
		'',
		array(
			'icon' => array(
				'<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8" aria-hidden="true"><circle cx="4" cy="4" r="3"></circle></svg>',
				'<svg onload="alert(1)" width="4" height="4"></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><script>alert(1)</script></svg>',
				str_repeat( ' ', 13000 ) . '<svg width="4" height="4"></svg>',
			),
		)
	);
	$direct_icon_markup = Static_Site_Importer_Form_Field_Markup::serialize_block( $direct_icon_block );
	$assert(
		str_contains( $direct_icon_markup, '<circle cx="4" cy="4" r="3"></circle>' )
			&& ! str_contains( $direct_icon_markup, 'onload' )
			&& ! str_contains( $direct_icon_markup, '<script' )
			&& str_contains( $direct_icon_markup, '>Send</button>' ),
		'submit-icon-parts-that-fail-the-portable-svg-admission-are-dropped-from-the-saved-button',
		$direct_icon_markup
	);
	$assert( str_contains( $markup, 'form-button-submit is-submit ssi-source-submit--source-submit ssi-provider-submit-presentation' ), 'source-submit-control-presentation-projects-onto-core-button' );
	// The source stylesheet governs this button, so the block claims no style
	// attribute it would then have to reproduce in saved markup. That agreement
	// with core's save() output is what keeps imported forms clean in the editor.
	$submit_attrs = array();
	foreach ( parse_blocks( $markup ) as $parsed_form ) {
		$collect = static function ( array $blocks, callable $collect ) use ( &$submit_attrs ): void {
			foreach ( $blocks as $parsed ) {
				if ( 'core/button' === ( $parsed['blockName'] ?? '' ) ) {
					$submit_attrs[] = $parsed['attrs'] ?? array();
				}
				$collect( $parsed['innerBlocks'] ?? array(), $collect );
			}
		};
		$collect( array( $parsed_form ), $collect );
	}
	$assert( 1 === count( $submit_attrs ) && ! array_key_exists( 'style', $submit_attrs[0] ), 'source-submit-block-claims-no-unrenderable-style-attribute', wp_json_encode( $submit_attrs ) );
	$assert( str_contains( $markup, 'hello@example.com' ), 'markup-mailto-recipient' );
	$assert( str_contains( $markup, '"options":["Sales","Support"]' ), 'markup-select-options' );
	$placeholder_select_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'select',
		'select',
		array(
			'name'    => 'membership_type',
			'label'   => 'Membership type',
			'options' => array(
				array( 'label' => 'Select', 'value' => '', 'placeholder' => true, 'disabled' => true, 'selected' => true ),
				array( 'label' => 'Ordinary member', 'value' => 'Ordinary member' ),
				array( 'label' => 'Life member', 'value' => 'Life member' ),
				array( 'label' => 'Overseas member', 'value' => 'Overseas member' ),
			),
		)
	);
	$placeholder_select_input = array();
	foreach ( $placeholder_select_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$placeholder_select_input = $inner;
			break;
		}
	}
	$assert( array( 'Ordinary member', 'Life member', 'Overseas member' ) === ( $placeholder_select_field['attrs']['options'] ?? null ), 'select-placeholder-option-is-omitted-from-the-provider-option-list', wp_json_encode( $placeholder_select_field ) );
	$assert( 'Select' === ( $placeholder_select_input['attrs']['placeholder'] ?? null ), 'select-placeholder-option-maps-onto-jetpack-input-placeholder', wp_json_encode( $placeholder_select_input ) );
	$assert( ! in_array( 'Select an option', $placeholder_select_field['attrs']['options'] ?? array(), true ), 'select-does-not-emit-a-synthetic-select-an-option-label', wp_json_encode( $placeholder_select_field ) );
	$placeholder_select_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/join', 'method' => 'post' ),
					'controls' => array(
						array(
							'tag'     => 'select',
							'type'    => 'select',
							'name'    => 'membership_type',
							'label'   => 'Membership type',
							'options' => array(
								array( 'label' => 'Select', 'value' => '', 'placeholder' => true, 'disabled' => true, 'selected' => true ),
								array( 'label' => 'Ordinary member' ),
								array( 'label' => 'Life member' ),
								array( 'label' => 'Overseas member' ),
							),
						),
						array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $placeholder_select_markup, '"options":["Ordinary member","Life member","Overseas member"]' ) && str_contains( $placeholder_select_markup, '"placeholder":"Select"' ) && ! str_contains( $placeholder_select_markup, 'Select an option' ), 'seeded-select-emits-source-placeholder-and-source-options-only', $placeholder_select_markup );
	// --- A leading empty-value prompt option is the field's toggle label even without the producer's prompt markers ---
	// Reduced from Automattic/static-site-importer#2238: the source select opens
	// with <option value="">Select a range (optional)</option> and carries neither
	// disabled nor selected, so the producer flags no placeholder option. Jetpack
	// renders the input's placeholder as togglelabel — its first <option value=''>
	// — so the prompt text must travel there and nowhere in the option list.
	$unflagged_prompt_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'select',
		'select',
		array(
			'name'    => 'budget',
			'label'   => 'Budget Range',
			'options' => array(
				array( 'label' => 'Select a range (optional)', 'value' => '' ),
				array( 'label' => 'Around $1,000', 'value' => 'low' ),
				array( 'label' => '$2,000+', 'value' => 'high' ),
			),
		)
	);
	$unflagged_prompt_input = array();
	foreach ( $unflagged_prompt_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$unflagged_prompt_input = $inner;
			break;
		}
	}
	$assert( array( 'Around $1,000', '$2,000+' ) === ( $unflagged_prompt_field['attrs']['options'] ?? null ) && 'Select a range (optional)' === ( $unflagged_prompt_input['attrs']['placeholder'] ?? null ), 'unflagged-leading-empty-value-select-option-maps-onto-the-provider-toggle-label', wp_json_encode( $unflagged_prompt_field ) );
	$select_probe_html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Select Probe</title>'
		. '<style>body{font-family:sans-serif;padding:32px}label{display:block;margin-bottom:6px}select,input,textarea{width:100%;padding:8px}</style></head>'
		. '<body><h2>Request a quote</h2><form><div><label>Your Name *</label><input name="name" required></div><div><label>Email *</label><input type="email" name="email" required></div>'
		. '<div><label>Budget Range</label><select name="budget"><option value="">Select a range (optional)</option><option value="low">Around $1,000</option><option value="high">$2,000+</option></select></div>'
		. '<div><label>Message *</label><textarea name="message" required rows="4"></textarea></div><button type="submit">Send</button></form></body></html>';
	$select_probe_source   = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'select-probe.html', 'files' => array( 'select-probe.html' => $select_probe_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$select_probe_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $select_probe_source ) ) );
	$select_probe_markup    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $select_probe_validated['forms'] ?? array() ) )['forms'][0]['block_markup'] ?? '';
	$assert( empty( $select_probe_validated['errors'] ) && '' !== $select_probe_markup, 'select-probe-fixture-compiles-and-maps', wp_json_encode( array( 'source' => $select_probe_source, 'errors' => $select_probe_validated['errors'] ?? array() ) ) );
	$assert( str_contains( $select_probe_markup, '"placeholder":"Select a range (optional)"' ) && ! str_contains( $select_probe_markup, '"options":["Select a range (optional)"' ), 'select-probe-prompt-maps-onto-the-provider-toggle-label', $select_probe_markup );
	$assert( str_contains( $select_probe_markup, '"options":["Around $1,000","$2,000+"]' ) && 1 === substr_count( $select_probe_markup, 'Select a range (optional)' ), 'select-probe-prompt-stays-off-the-provider-option-list', $select_probe_markup );
	$assert( ! str_contains( $select_probe_markup, 'Select an option' ), 'select-probe-leaves-no-synthetic-provider-prompt', $select_probe_markup );
	$assert( 1 === preg_match( '/<div class="wp-block-jetpack-contact-form form contact ssi-form-[a-f0-9]{12}">/', $markup ), 'markup-contact-form-wrapper-and-source-classes' );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-text (?=[^\n]*"required":true)(?![^\n]*"id":)(?=[^\n]*"className":"ssi-node-[a-f0-9]{12}")(?=[^\n]*"shareFieldAttributes":false)[^\n]* -->/', $markup ), 'markup-field-wrapper-keeps-provider-layout-class-and-leaves-field-id-to-provider' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/label {"label":"Your name","className":"source-label"} /-->' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"className":"source-field"} /-->' ), 'markup-field-canonical-label-and-input-children-carry-source-classes' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-select {"options":["Sales","Support"]' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"type":"dropdown"} /-->' ), 'markup-select-options-and-dropdown-input' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-radio {"options":["In person","Online"]' ) && str_contains( $markup, '<!-- wp:jetpack/options {"type":"radio"} -->' ), 'markup-radio-options-on-field-and-child-list' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-checkbox ' ) && str_contains( $markup, '<!-- wp:jetpack/option {"label":"Send me updates","isStandalone":true} /-->' ), 'markup-checkbox-uses-standalone-option-child' );

	// --- A control's own description reaches the provider as Jetpack's shared help-text attribute ---
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-email \{[^\n]*"helpText":"We only use this to contact you back\."[^\n]*\} -->/', $markup ), 'markup-described-control-carries-source-description-as-jetpack-help-text', $markup );
	$assert( 1 === substr_count( $markup, '"helpText"' ), 'markup-undescribed-controls-in-the-same-form-carry-no-help-text-attribute', $markup );

	// Jetpack declares `helpText` on every jetpack/field-* block, but a field
	// whose editor and frontend renderer never surface it (checkbox, radio -
	// the grouped fields this seeder emits as jetpack/field-checkbox(-multiple)
	// and jetpack/field-radio) must not silently store a value the visitor
	// never sees. A supported field type carries it as `helpText`; an
	// unsupported one reports the same `unsupported_control_attribute` loss
	// an unrepresentable numeric step already uses, rather than a
	// parallel mechanism.
	$described_url_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'url',
		array(
			'name'        => 'portfolio',
			'label'       => 'Portfolio URL',
			'placeholder' => 'https://yourportfolio.com',
			'description' => 'Link to your design work (Behance, Dribbble, personal site, etc.)',
		)
	);
	$assert( 'Link to your design work (Behance, Dribbble, personal site, etc.)' === ( $described_url_field['attrs']['helpText'] ?? null ), 'described-url-control-carries-jetpack-help-text-attribute', wp_json_encode( $described_url_field ) );
	$assert( array() === ( $described_url_field['losses'] ?? array() ), 'described-url-control-reports-no-loss', wp_json_encode( $described_url_field ) );
	$concatenated_description_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'text',
		array(
			'name'        => 'occupation',
			'label'       => 'Occupation / businessHelps the trade committee connect members.',
			'description' => 'Helps the trade committee connect members.',
		)
	);
	$concatenated_description_label = array();
	foreach ( $concatenated_description_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/label' === ( $inner['name'] ?? '' ) ) {
			$concatenated_description_label = $inner;
			break;
		}
	}
	$assert( 'Occupation / business' === ( $concatenated_description_label['attrs']['label'] ?? null ), 'concatenated-description-is-removed-from-the-label', wp_json_encode( $concatenated_description_label ) );
	$assert( 'Helps the trade committee connect members.' === ( $concatenated_description_field['attrs']['helpText'] ?? null ), 'concatenated-description-maps-onto-jetpack-help-text', wp_json_encode( $concatenated_description_field ) );
	$described_occupation_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/join', 'method' => 'post' ),
					'controls' => array(
						array(
							'tag'         => 'input',
							'type'        => 'text',
							'name'        => 'occupation',
							'label'       => 'Occupation / business',
							'description' => 'Helps the trade committee connect members.',
						),
						array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $described_occupation_markup, '<!-- wp:jetpack/label {"label":"Occupation / business"} /-->' ) && 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^\n]*"helpText":"Helps the trade committee connect members\."[^\n]*\} -->/', $described_occupation_markup ) && ! str_contains( $described_occupation_markup, 'businessHelps' ), 'seeded-described-text-field-keeps-label-and-help-text-apart', $described_occupation_markup );
	$undescribed_url_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'url',
		array(
			'name'        => 'portfolio',
			'label'       => 'Portfolio URL',
			'placeholder' => 'https://yourportfolio.com',
		)
	);
	$assert( ! array_key_exists( 'helpText', $undescribed_url_field['attrs'] ?? array() ), 'undescribed-url-control-carries-no-help-text-attribute', wp_json_encode( $undescribed_url_field ) );
	$described_checkbox_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'checkbox',
		array( 'name' => 'updates', 'label' => 'Send me updates', 'description' => 'We send about once a month.' )
	);
	$described_checkbox_losses = array_values( array_filter( $described_checkbox_field['losses'] ?? array(), static fn( array $loss ): bool => 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' ) && 'description' === ( $loss['attribute'] ?? '' ) ) );
	$assert( ! array_key_exists( 'helpText', $described_checkbox_field['attrs'] ?? array() ), 'checkbox-description-is-not-stored-where-jetpack-never-renders-it', wp_json_encode( $described_checkbox_field ) );
	$assert( 1 === count( $described_checkbox_losses ), 'checkbox-description-is-reported-as-an-unsupported-control-attribute-loss-instead-of-a-silent-drop', wp_json_encode( $described_checkbox_field ) );
	$described_radio_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'radio',
		array( 'name' => 'format', 'label' => 'In person', 'options' => array( 'In person', 'Online' ), 'description' => 'Choose whichever suits you.' )
	);
	$described_radio_losses = array_values( array_filter( $described_radio_field['losses'] ?? array(), static fn( array $loss ): bool => 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' ) && 'description' === ( $loss['attribute'] ?? '' ) ) );
	$assert( ! array_key_exists( 'helpText', $described_radio_field['attrs'] ?? array() ), 'radio-description-is-not-stored-where-jetpack-never-renders-it', wp_json_encode( $described_radio_field ) );
	$assert( 1 === count( $described_radio_losses ), 'radio-description-is-reported-as-an-unsupported-control-attribute-loss-instead-of-a-silent-drop', wp_json_encode( $described_radio_field ) );

	// jetpack/input declares min and max as numbers and does not declare step.
	// A bounded number control must become jetpack/field-number with those
	// attributes on the inner input; step stays a real unsupported-attribute loss.
	$bounded_number_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'number',
		array( 'name' => 'household', 'label' => 'Household size', 'min' => '1', 'max' => '50' )
	);
	$bounded_number_input = array();
	foreach ( $bounded_number_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$bounded_number_input = $inner;
			break;
		}
	}
	$assert( 'jetpack/field-number' === ( $bounded_number_field['name'] ?? '' ), 'bounded-number-control-maps-to-jetpack-field-number', wp_json_encode( $bounded_number_field ) );
	$assert( 1 === ( $bounded_number_input['attrs']['min'] ?? null ) && 50 === ( $bounded_number_input['attrs']['max'] ?? null ), 'bounded-number-control-carries-min-max-onto-jetpack-input', wp_json_encode( $bounded_number_input ) );
	$assert( array() === ( $bounded_number_field['losses'] ?? array() ), 'bounded-number-min-max-are-not-losses', wp_json_encode( $bounded_number_field ) );
	$stepped_number_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'number',
		array( 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' )
	);
	$stepped_number_input = array();
	foreach ( $stepped_number_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$stepped_number_input = $inner;
			break;
		}
	}
	$assert( 1 === ( $stepped_number_input['attrs']['min'] ?? null ) && 8 === ( $stepped_number_input['attrs']['max'] ?? null ) && ! array_key_exists( 'step', $stepped_number_input['attrs'] ?? array() ), 'stepped-number-control-carries-min-max-and-does-not-store-step', wp_json_encode( $stepped_number_input ) );
	$assert( array( 'step' ) === array_column( $stepped_number_field['losses'] ?? array(), 'attribute' ), 'stepped-number-control-reports-step-as-an-unsupported-control-attribute-loss', wp_json_encode( $stepped_number_field ) );

	// The same unsupported-attribute loss the seeder already gates provider
	// mapping on (see the numeric step overflow coverage below) also
	// gates a described checkbox: an invisible attribute is not a fidelity
	// win, so the seeder declines to claim the field is represented rather
	// than shipping a value the visitor will never see.
	$described_checkbox_seed = Static_Site_Importer_Form_Seeder::seed(
		array( 'forms' => array( array( 'selector' => 'form.updates', 'controls' => array(
			array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates', 'description' => 'We send about once a month.' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ),
		) ) ) )
	);
	$described_checkbox_row = $described_checkbox_seed['forms'][0] ?? array();
	$assert( false === ( $described_checkbox_row['runtime_mapped'] ?? true ) && 'form_receipt_loss_unaccepted' === ( $described_checkbox_row['reason'] ?? '' ) && in_array( 'description', array_column( $described_checkbox_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ), true ), 'seeder-declines-rather-than-silently-drops-an-unrenderable-checkbox-description', wp_json_encode( $described_checkbox_row ) );
	$assert( 'declined' === ( $described_checkbox_row['mapping_decision']['status'] ?? '' ) && ( $described_checkbox_row['field_blocks'] ?? null ) === ( $described_checkbox_row['mapping_decision']['supported_fields'] ?? null ) && ( $described_checkbox_row['form_receipt_unaccepted_losses'] ?? null ) === ( $described_checkbox_row['mapping_decision']['losses'] ?? null ), 'declined-form-keeps-exact-losses-in-provider-decision' );
	$callback_rows = array();
	add_filter( 'static_site_importer_form_receipt_loss_accepted', static function ( $accepted, $loss, $form, $emission_row ) use ( &$callback_rows ) {
		$callback_rows[] = array( 'loss' => $loss, 'form' => $form, 'row' => $emission_row );
		return true;
	} );
	$accepted_checkbox_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.updates', 'controls' => array(
		array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates', 'description' => 'We send about once a month.' ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ),
	) ) ) ) )['forms'][0] ?? array();
	unset( $GLOBALS['ssi_test_hooks']['static_site_importer_form_receipt_loss_accepted'] );
	$callback_row = $callback_rows[0]['row'] ?? array();
	$assert( 1 === count( $callback_rows ) && 'description' === ( $callback_rows[0]['loss']['attribute'] ?? '' ) && 'form.updates' === ( $callback_rows[0]['form']['selector'] ?? '' ) && 'mapped' === ( $callback_row['status'] ?? '' ) && true === ( $callback_row['runtime_mapped'] ?? false ) && ! empty( $callback_row['block_markup'] ) && isset( $callback_row['provider_layout_target_map'], $callback_row['provider_layout_overlay_css'] ) && ! isset( $callback_row['mapping_decision'] ) && ( $callback_row['computed_layout_receipt'] ?? null ) === ( $accepted_checkbox_row['computed_layout_receipt'] ?? null ) && 'mapped' === ( $accepted_checkbox_row['mapping_decision']['status'] ?? '' ) && array() === ( $accepted_checkbox_row['mapping_decision']['losses'] ?? null ), 'receipt-filter-sees-complete-original-row-and-exact-receipt-before-decision', wp_json_encode( $callback_rows ) );

	$responsive_seed = Static_Site_Importer_Form_Seeder::seed(
		array( 'forms' => array(
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'a', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'repeated-source-id', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ),
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'b', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'repeated-source-id', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ),
		) )
	);
	$responsive_rows = $responsive_seed['forms'] ?? array();
	$responsive_field_ids = array();
	$collect_field_ids    = static function ( array $blocks ) use ( &$collect_field_ids, &$responsive_field_ids ): void {
		foreach ( $blocks as $block ) {
			if ( str_starts_with( (string) ( $block['blockName'] ?? '' ), 'jetpack/field-' ) && array_key_exists( 'id', $block['attrs'] ?? array() ) ) {
				$responsive_field_ids[] = $block['attrs']['id'];
			}
			$collect_field_ids( $block['innerBlocks'] ?? array() );
		}
	};
	foreach ( $responsive_rows as $responsive_row ) {
		$collect_field_ids( parse_blocks( $responsive_row['block_markup'] ) );
	}
	// Jetpack derives a unique field ID per rendered form instance only when the
	// field carries no author ID, so neither a source ID nor a fixed ID is saved.
	$assert( 2 === count( $responsive_rows ) && array() === $responsive_field_ids && ! str_contains( implode( '', array_column( $responsive_rows, 'block_markup' ) ), 'repeated-source-id' ), 'responsive-form-instances-leave-field-ids-to-the-provider-per-rendered-instance', wp_json_encode( $responsive_field_ids ) );
	$marker_form = $responsive_identity_forms['forms'][0];
	$marker_form['controls'][0]['required'] = true;
	$marker_form['controls'][0]['label'] = 'Email';
	$marker_form['controls'][0]['required_text'] = '*';
	$marker_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $marker_form ) ) )['forms'][0];
	$assert( str_contains( $marker_row['block_markup'], '"requiredText":"*"' ), 'captured-required-marker-uses-existing-provider-label-api' );
	$markerless_form = $marker_form;
	unset( $markerless_form['controls'][0]['required_text'] );
	$markerless_form['controls'][0]['required_indicator'] = false;
	$markerless_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $markerless_form ) ) )['forms'][0];
	$assert( str_contains( $markerless_row['block_markup'], '"requiredIndicator":false' ) && ! str_contains( $markerless_row['block_markup'], '"requiredText"' ), 'captured-markerless-required-field-preserves-validation-without-a-provider-default-marker' );
	$assert( 2 === ( $responsive_seed['counts']['mapped'] ?? 0 ) && array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) === array_column( $responsive_rows, 'fallback_identity' ) && 2 === count( array_unique( array_map( static fn( array $row ): string => (string) preg_replace( '/.*\b(ssi-form-[a-f0-9]{12})\b.*/s', '$1', (string) ( $row['block_markup'] ?? '' ) ), $responsive_rows ) ) ), 'responsive-form-identities-produce-distinct-provider-blocks-and-receipts' );
	$responsive_entities = $responsive_identity_forms['forms'];
	foreach ( $responsive_entities as $index => &$responsive_entity ) {
		$responsive_entity['bindings'] = array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'contact.html', 'search_block_markup' => '<!-- wp:html --><form class="contact"></form><!-- /wp:html -->', 'occurrence' => $index + 1, 'role' => 'form' ) );
	}
	unset( $responsive_entity );
	$responsive_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
		array( 'entities' => array( 'responsive' => array( 'adapter' => Static_Site_Importer_Entity_Materializer_Registry::form_adapter(), 'manifest' => array( 'forms' => $responsive_entities ) ) ) ),
		array( 'responsive' => $responsive_seed )
	);
	$assert( is_array( $responsive_bindings ) && 2 === count( $responsive_bindings ) && array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) === array_column( $responsive_bindings, 'fallback_reconciliation_identity' ), 'responsive-form-identities-match-provider-results-to-every-binding' );
	$checkbox_group = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.preferences', 'controls' => array( array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'topics', 'label' => 'Topics', 'options' => array( 'Art', 'Events' ) ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ) ) ) ) ) );
	$checkbox_group_markup = (string) ( $checkbox_group['forms'][0]['block_markup'] ?? '' );
	$assert( str_contains( $checkbox_group_markup, '<!-- wp:jetpack/field-checkbox-multiple {"options":["Art","Events"]' ) && str_contains( $checkbox_group_markup, '<!-- wp:jetpack/options {"type":"checkbox"} -->' ), 'checkbox-group-uses-provider-multiple-field' );
	$sensitive_label = 'C:\\forms\\"quoted" --> < & support';
	$escaped_label = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.escaped', 'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'unsafe', 'label' => $sensitive_label ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) ) );
	$escaped_label_markup = (string) ( $escaped_label['forms'][0]['block_markup'] ?? '' );
	$expected_sensitive_attrs = serialize_block_attributes( array( 'label' => $sensitive_label ) );
	$assert( str_contains( $escaped_label_markup, '<!-- wp:jetpack/label ' . $expected_sensitive_attrs . ' /-->' ), 'field-attributes-byte-match-core-escaping', $escaped_label_markup );
	$assert( $sensitive_label === ( json_decode( $expected_sensitive_attrs, true )['label'] ?? null ) && ! str_contains( $expected_sensitive_attrs, '-->' ) && str_contains( $expected_sensitive_attrs, '\\u0022' ) && str_contains( $expected_sensitive_attrs, '\\u002d\\u002d' ) && str_contains( $expected_sensitive_attrs, '\\u003c' ) && str_contains( $expected_sensitive_attrs, '\\u003e' ) && str_contains( $expected_sensitive_attrs, '\\u0026' ), 'field-attributes-core-preserves-backslashes-and-escapes-comment-sensitive-characters', $expected_sensitive_attrs );
	$assert( $escaped_label_markup === serialize_blocks( parse_blocks( $escaped_label_markup ) ), 'field-attributes-round-trip-through-wordpress-block-parser', $escaped_label_markup );

	// --- Composed route forms materialize directly without caller seeding ----
	$route_form = '<main><form class="contact"><label>Email <input type="email" name="email" required></label><button type="submit">Contact me</button></form></main>';
	$composed_result = ( new $artifact_compiler() )->compile(
		array( 'entrypoint' => 'about.html', 'files' => array( 'about.html' => $route_form, 'contact.html' => $route_form ) )
	)->toArray();
	$composed_plan = $composed_result['source_reports']['wordpress_site_plan'] ?? array();
	$composed_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $composed_plan, array() );
	$composed_entities = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $composed_lifecycle, array( 'seed_entities' => false ) );
	$composed_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $composed_lifecycle, $composed_entities['reports'] ?? array() );
	$composed_form_receipt = reset( $composed_entities['reports'] );
	$assert( 2 === ( $composed_plan['quality']['metrics']['fallback_count'] ?? -1 ) && 2 === ( $composed_form_receipt['counts']['mapped'] ?? 0 ), 'composed-route-forms-produce-one-provider-receipt' );
	$assert( is_array( $composed_bindings ) && 2 === count( $composed_bindings ) && array() === array_filter( $composed_bindings, static fn( array $binding ): bool => 'jetpack' !== ( $binding['provider'] ?? '' ) || '' === ( $binding['fallback_reconciliation_identity'] ?? '' ) ), 'composed-route-forms-produce-identity-bound-provider-bindings', (string) wp_json_encode( array( 'receipt' => $composed_form_receipt, 'bindings' => $composed_bindings ) ) );

	// --- Generic topology preserves nested rows and source presentation hooks --
	$assert( empty( $validated_topology['errors'] ), 'topology-manifest-validates' );
	$topology_receipt = $topology_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$assert( ! str_contains( $topology_markup, 'wp:group' ), 'topology-avoids-unsupported-provider-wrapper-blocks' );
	$assert( 2 === substr_count( $topology_markup, '"width":50' ), 'topology-maps-proven-equal-grid-to-field-widths' );
	$assert( str_contains( $topology_markup, 'First name' ) && str_contains( $topology_markup, 'Email' ) && str_contains( $topology_markup, 'Message' ), 'topology-preserves-labels' );
	$assert( 1 === substr_count( $topology_markup, '<!-- wp:button ' ), 'topology-submit-control-emits-one-core-button-in-source-position' );
	$topology_ops = array_column( $topology_receipt['operations'] ?? array(), 'strategy' );
	$assert( 'applied' === ( $topology_receipt['status'] ?? '' ) && in_array( 'provider_equal_width_fields', $topology_ops, true ) && in_array( 'provider_interaction_carrier', $topology_ops, true ) && 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.5rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.5rem\);margin-block-start:0!important\}/', $expand_selector_lists( (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ) ) ), 'computed-layout-equal-grid-applies-with-bounded-receipt', wp_json_encode( array( 'ops' => $topology_ops, 'css' => $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ) ) );
	$assert( str_contains( $topology_markup, 'ssi-textarea-rows-2' ) && str_contains( (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' ) && ! str_contains( (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' ), 'topology-unstyled-source-textarea-carries-two-rows-and-neutralizes-the-provider-height' );
	$min_height_textarea_form = $topology_form;
	$min_height_textarea_form['forms'][0]['presentation_graph'] = array(
		'schema'   => 'generic/form-presentation-graph/v1',
		'controls' => array( array( 'index' => 2, 'control' => array( 'styles' => array( 'min_height' => '80px' ) ) ) ),
	);
	$min_height_textarea_seed = Static_Site_Importer_Form_Seeder::seed( $min_height_textarea_form );
	$min_height_textarea_css  = (string) ( $min_height_textarea_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' );
	$assert( str_contains( $min_height_textarea_css, 'min-height:80px' ) && str_contains( $min_height_textarea_css, 'height:auto' ), 'source-textarea-min-height-is-preserved-while-provider-fixed-height-is-released', $min_height_textarea_css );

	// A layout graph may contain the complete control ancestry while the producer
	// omits its parallel topology document. Recover that tree so proven grid rows
	// do not silently flatten into Jetpack's full-width default.
