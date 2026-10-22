<?php
/**
 * Form materializer smoke suite: multi-row grids, flex field cells, labels and row spans.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$multi_row_css  = '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}'
		. '.grid{display:grid}.gap-5{gap:1.25rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$multi_row_html = '<style>' . $multi_row_css . '</style><form class="space-y-5">'
		. '<h2>Member details</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Full name</span><input required></label>'
		. '<label class="block"><span>Father name</span><input required></label>'
		. '<label class="block"><span>CNIC number</span><input required></label>'
		. '<label class="block"><span>Date of birth</span><input type="date" required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Membership type</span><select required><option>Ordinary member</option></select></label>'
		. '<label class="block"><span>Family members</span><input type="number" min="1" max="50" required></label>'
		. '</div>'
		. '<label class="block"><span>Residential address</span><textarea rows="3" required></textarea></label>'
		. '<label class="block"><span>Occupation</span><input></label>'
		. '<button type="submit">Submit registration</button>'
		. '</form>';
	$multi_row_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'membership.html', 'files' => array( 'membership.html' => $multi_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$multi_row_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $multi_row_source ) ) );
	$multi_row_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $multi_row_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$multi_row_markup    = (string) ( $multi_row_row['block_markup'] ?? '' );
	$multi_row_css_out   = $expand_selector_lists( (string) ( $multi_row_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$multi_row_ops       = array_column( $multi_row_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $multi_row_validated['errors'] ) && 'mapped' === ( $multi_row_row['status'] ?? '' ), 'multi-row-equal-column-grid-manifest-validates', wp_json_encode( $multi_row_validated ) );
	$assert(
		10 === ( $multi_row_row['field_count'] ?? 0 )
			&& 8 === substr_count( $multi_row_markup, '"width":50' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-email \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-select \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-number \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-date \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-date[\s\S]*?<!-- wp:jetpack\/input \{"style":\{"border":\{"style":"solid"\}\},"className":"ssi-node-[a-f0-9]{12}"\} \/\-->/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-textarea \{(?![^}]*"width":50)/', $multi_row_markup )
			&& in_array( 'provider_equal_width_fields', $multi_row_ops, true )
			&& 8 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.625rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.625rem\);margin-block-start:0!important\}/', $multi_row_css_out )
			&& $multi_row_markup === serialize_blocks( parse_blocks( $multi_row_markup ) ),
		'eight-field-two-column-grid-emits-jetpack-width-50-on-every-grid-child',
		wp_json_encode( array( 'ops' => $multi_row_ops, 'markup' => $multi_row_markup, 'css' => $multi_row_css_out, 'count' => substr_count( $multi_row_markup, '"width":50' ) ) )
	);
	// Real Tailwind v4 captures often keep `display:grid` as a class token while
	// layered `sm:grid-cols-2` never becomes a layout-graph node. Sibling count
	// is not column count: eight field boxes in that grid are still two columns.
	$class_only_html = '<form class="space-y-5">'
		. '<div class="grid gap-5">'
		. '<label class="block"><span>Full name</span><input required></label>'
		. '<label class="block"><span>Father name</span><input required></label>'
		. '<label class="block"><span>CNIC</span><input required></label>'
		. '<label class="block"><span>Date of birth</span><input type="date" required></label>'
		. '<label class="block"><span>Mobile</span><input type="tel" required></label>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Type</span><select required><option>Ordinary</option></select></label>'
		. '<label class="block"><span>Family</span><input type="number" required></label>'
		. '</div>'
		. '<label class="block"><span>Address</span><textarea rows="3" required></textarea></label>'
		. '<label class="block"><span>Occupation</span><input></label>'
		. '<button type="submit">Submit</button></form>';
	$class_only_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'membership.html', 'files' => array( 'membership.html' => $class_only_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$class_only_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $class_only_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$class_only_markup = (string) ( $class_only_row['block_markup'] ?? '' );
	$class_only_css    = $expand_selector_lists( (string) ( $class_only_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$assert(
		'mapped' === ( $class_only_row['status'] ?? '' )
			&& 8 === substr_count( $class_only_markup, '"width":50' )
			&& in_array( 'provider_equal_width_fields', array_column( $class_only_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'class-token-grid-with-eight-field-children-still-maps-to-jetpack-width-50',
		wp_json_encode( array( 'markup' => $class_only_markup, 'topo' => $class_only_source['control_topology']['nodes'][0]['class'] ?? null, 'layout' => $class_only_source['layout_graph'] ?? null ) )
	);
	$assert(
		str_contains( $class_only_css, '@media (max-width: 480px){' )
			&& 8 === preg_match_all( '/@media \(max-width: 480px\)\{\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{flex:1 1 100%;width:100%\}\}/', $class_only_css )
			&& 402 <= 480
			&& 1440 > 480,
		'class-token-equal-width-row-without-cascade-query-still-stacks-below-the-provider-wrap-breakpoint',
		$class_only_css
	);
	// A narrowing (max-width) variant is not the proven mobile-first widening
	// shape and must keep the existing decline: Jetpack cannot represent "two
	// columns by default that collapse to one," and this is not that either.
	$narrowing_grid_css      = '.grid{display:grid}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width<768px){.sm\\:grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}}';
	$narrowing_grid_html     = '<style>' . $narrowing_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-2 sm:grid-cols-1 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$narrowing_grid_source   = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $narrowing_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$narrowing_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $narrowing_grid_source ) ) );
	$narrowing_grid_row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $narrowing_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		'skipped' === ( $narrowing_grid_row['status'] ?? '' )
			&& 'form_receipt_loss_unaccepted' === ( $narrowing_grid_row['reason'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', array_column( $narrowing_grid_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'narrowing-max-width-grid-variant-keeps-the-wrapper-layout-decline',
		wp_json_encode( $narrowing_grid_row )
	);
	// Two variants on the same row (e.g. a third breakpoint changing the track
	// count again) is outside the single proven widening this rule accepts.
	$two_variant_grid_css    = '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media (width>=1024px){.lg\\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}}';
	$two_variant_grid_html   = '<style>' . $two_variant_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$two_variant_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $two_variant_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$two_variant_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $two_variant_grid_source ) ) );
	$two_variant_grid_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $two_variant_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		'skipped' === ( $two_variant_grid_row['status'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', array_column( $two_variant_grid_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'a-second-breakpoint-changing-the-track-count-again-keeps-the-wrapper-layout-decline',
		wp_json_encode( $two_variant_grid_row )
	);
	// Keep the responsive field grid and its sibling submit as physical blocks.
	// This source shape has two direct controls, not nested field wrappers.
	$native_grid_css = '.w-full{width:100%}.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-3{gap:.75rem}'
		. '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '.flex{display:flex}.justify-center{justify-content:center}';
	$native_grid_html = '<style>' . $native_grid_css . '</style><form class="w-full">'
		. '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><input type="text" name="first" placeholder="First name"><input type="email" name="email" placeholder="Email"></div>'
		. '<div class="flex justify-center"><button type="submit">Send</button></div></form>';
	$native_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $native_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$native_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $native_grid_source ) ) );
	$native_grid_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$native_grid_blocks = parse_blocks( (string) ( $native_grid_row['block_markup'] ?? '' ) );
	$native_grid_root = $native_grid_blocks[0]['innerBlocks'][0] ?? array();
	$native_grid_children = $native_grid_root['innerBlocks'] ?? array();
	$native_submit_group = $native_grid_blocks[0]['innerBlocks'][1] ?? array();
	$assert(
		empty( $native_grid_validated['errors'] )
			&& 1 === count( $native_grid_source['layout_graph']['variants'] ?? array() )
			&& 'mapped' === ( $native_grid_row['status'] ?? '' )
			&& true === ( $native_grid_row['runtime_mapped'] ?? false )
			&& 'core/group' === ( $native_grid_root['blockName'] ?? '' )
			&& array( 'jetpack/field-text', 'jetpack/field-email' ) === array_column( $native_grid_children, 'blockName' )
			&& 'core/group' === ( $native_submit_group['blockName'] ?? '' )
			&& array( 'core/button' ) === array_column( $native_submit_group['innerBlocks'] ?? array(), 'blockName' )
			&& 'Send' === ( $native_grid_row['submit_text'] ?? '' )
			&& empty( $native_grid_row['form_receipt_unaccepted_losses'] )
			&& str_contains( (string) ( $native_grid_row['provider_layout_overlay_css']['css'] ?? '' ), '@media (min-width:640px)' )
			&& (string) ( $native_grid_row['block_markup'] ?? '' ) === serialize_blocks( $native_grid_blocks ),
		'direct-input-grid-and-sibling-submit-preserve-proven-responsive-layout-in-native-groups',
		wp_json_encode( array( 'source' => $native_grid_source, 'row' => $native_grid_row, 'blocks' => $native_grid_blocks ) )
	);
	// A source can use empty labels as painted containers for several fields,
	// while a nested submit wrapper owns sizing independently of the button.
	$shared_label_html = '<style>.quote{display:flex;flex-direction:column;gap:16px}'
		. '.shared-fields{display:flex;flex-direction:column;gap:16px;width:100%;height:min-content;padding:0}'
		. '.field-box{display:flex;align-items:center;width:100%;height:60px;padding:12px;background:white;border-radius:10px}'
		. '.field-box input{width:100%;height:100%;padding:0;border-style:none}'
		. '.variant{display:contents}'
		. '.submit-shell{width:100%;height:60px;flex:0 0 auto}'
		. '.submit-shell button{width:100%;height:100%}</style>'
		. '<form class="quote"><label class="shared-fields"><div class="field-box"><input name="service" placeholder="Service" required></div>'
		. '<div class="field-box"><input name="location" placeholder="Location"></div></label>'
		. '<div class="variant"><div class="submit-shell"><button type="submit">Send</button></div></div>'
		. '<input name="website" style="position:absolute;transform:scale(0)"></form>';
	$shared_label_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $shared_label_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$shared_label_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $shared_label_source ) ) );
	$shared_label_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $shared_label_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$shared_label_markup = (string) ( $shared_label_row['block_markup'] ?? '' );
	$shared_label_blocks = parse_blocks( $shared_label_markup );
	$shared_label_group = $shared_label_blocks[0]['innerBlocks'][0] ?? array();
	$shared_submit_shell = $shared_label_blocks[0]['innerBlocks'][1]['innerBlocks'][0] ?? array();
	$shared_targets = array_column( $shared_label_row['provider_layout_target_map']['targets'] ?? array(), null, 'node' );
	$shared_css = (string) ( $shared_label_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $shared_label_validation['errors'] ) && true === ( $shared_label_row['runtime_mapped'] ?? false ) && empty( $shared_label_row['form_receipt_unaccepted_losses'] )
		&& 'label' === ( $shared_label_group['attrs']['tagName'] ?? '' ) && 2 === count( $shared_label_group['innerBlocks'] ?? array() )
		&& 'core/group' === ( $shared_submit_shell['blockName'] ?? '' ) && 'core/button' === ( $shared_submit_shell['innerBlocks'][0]['blockName'] ?? '' )
		&& isset( $shared_targets['wrapper-0'], $shared_targets['wrapper-4'] )
		&& str_contains( (string) ( $shared_label_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:60px' )
		&& $shared_label_markup === serialize_blocks( $shared_label_blocks ),
		'shared-empty-label-fields-and-nested-submit-box-have-physical-provider-targets', wp_json_encode( array( 'decision' => $shared_label_row['mapping_decision'] ?? null, 'markup' => $shared_label_markup, 'topology' => $shared_label_source['control_topology'] ?? null, 'layout' => $shared_label_source['layout_graph'] ?? null ) ) );
	$assert( 2 === ( $shared_label_row['field_count'] ?? 0 ) && ! str_contains( $shared_label_markup, '"label":"website"' )
		&& str_contains( $shared_label_markup, '"blockVisibility":false' ) && str_contains( $shared_label_markup, '"border":{"style":"none"}' )
		&& 1 === preg_match( '/\.ssi-native-control-shell-wrap(?:, [^{]+)?\{display:contents\}/', $shared_css ) && str_contains( $shared_css, 'border-radius:10px' )
		&& str_contains( $shared_css, '.ssi-native-form-topology form.jetpack-contact-form__form' )
		&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $shared_label_row['provider_layout_overlay_css'] ),
		'native-provider-presentation-hides-only-source-bookkeeping-and-keeps-control-shell-ownership', wp_json_encode( array( 'decision' => $shared_label_row['mapping_decision'] ?? null, 'css' => $shared_css, 'valid' => null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $shared_label_row['provider_layout_overlay_css'] ) ) ) );
	$unsafe_native_map = $shared_label_row['provider_layout_target_map'];
	$assert( str_contains( $shared_css, 'form.jetpack-contact-form__form.submission-success{display:none}' ), 'native-source-layout-retains-provider-submission-success-visibility' );
	$unsafe_native_map['targets'][0]['selector'] .= ', body';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $unsafe_native_map, $shared_label_source['layout_graph'] )['error'] ), 'native-form-selector-never-admits-an-unscoped-list-member' );
	$labelled_shared_source = $shared_label_source;
	$labelled_shared_source['controls'][0]['label'] = 'Service';
	$labelled_shared_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $labelled_shared_source ) ) );
	$labelled_shared_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_shared_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! str_contains( (string) ( $labelled_shared_row['block_markup'] ?? '' ), '<label class="wp-block-group' ), 'source-label-containers-never-nest-provider-field-labels' );
	$span_fact = static function ( string $id, ?string $parent, int $order, array $layout, array $properties ): array {
		return array(
			'id'         => $id,
			'kind'       => 'container',
			'parent'     => $parent,
			'order'      => $order,
			'source'     => array( 'tag' => 'div', 'classes' => array() ),
			'layout'     => $layout,
			'provenance' => array(
				array(
					'source_path'   => 'inline-style',
					'source_sha256' => str_repeat( 'a', 64 ),
					'selector'      => '[style]',
					'condition'     => null,
					'properties'    => $properties,
				),
			),
		);
	};
	$span_row_form = array(
		'selector'         => 'form.span-row',
		'controls'         => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First name' ),
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'last', 'label' => 'Last name' ),
			array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
			array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
		'control_topology' => array(
			'schema'    => 'generic/form-control-topology/v1',
			'max_depth' => 8,
			'max_nodes' => 128,
			'truncated' => false,
			'nodes'     => array(
				array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
				array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div' ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
				array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'div' ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ),
				array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'tag' => 'div' ),
				array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 2, 'control' => 2 ),
				array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 3 ),
				array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 4 ),
			),
		),
		'layout_graph'     => $v2_layout_graph(
			array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
				array(
					'id'         => 'wrapper-0',
					'kind'       => 'container',
					'parent'     => 'form',
					'order'      => 0,
					'source'     => array( 'tag' => 'div', 'classes' => array( 'field-row' ) ),
					'layout'     => array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)', 'width' => '100%', 'column_gap' => 'var(--form-column-spacing, 24px)' ),
					'provenance' => array(
						array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns', 'width' ) ),
						array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.field-row', 'condition' => null, 'properties' => array( 'column-gap' ) ),
					),
				),
				$span_fact( 'wrapper-1', 'wrapper-0', 0, array( 'column' => '1 / span 6', 'row' => '1 / span 1' ), array( 'grid-column', 'grid-row' ) ),
				$span_fact( 'wrapper-2', 'wrapper-0', 1, array( 'column' => '7 / span 6', 'row' => '1 / span 1' ), array( 'grid-column', 'grid-row' ) ),
				$span_fact( 'wrapper-3', 'wrapper-0', 2, array( 'column' => '1 / span 12', 'row' => '2 / span 1' ), array( 'grid-column', 'grid-row' ) ),
			)
		),
	);
	$span_row_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $span_row_form ) ) );
	$span_row_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $span_row_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$span_row_markup    = (string) ( $span_row_row['block_markup'] ?? '' );
	$span_row_css       = $expand_selector_lists( (string) ( $span_row_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$span_row_losses    = array_column( $span_row_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $span_row_validated['errors'] )
			&& 'mapped' === ( $span_row_row['status'] ?? '' )
			&& true === ( $span_row_row['runtime_mapped'] ?? false )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', $span_row_losses, true )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $span_row_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true )
			&& in_array( 'provider_grid_span_fields', array_column( $span_row_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& 2 === substr_count( $span_row_markup, '"width":50' )
			&& 1 === substr_count( $span_row_markup, '"width":100' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50\} -->\s*<div><!-- wp:jetpack\/label \{"label":"First name"\}/', $span_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50\} -->\s*<div><!-- wp:jetpack\/label \{"label":"Last name"\}/', $span_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-textarea \{[^}]*"width":100\} -->/', $span_row_markup )
			&& 2 === preg_match_all( '/width:calc\(50% - 12px\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 12px\)/', $span_row_css ),
		'twelve-column-grid-span-row-materializes-name-fields-side-by-side',
		wp_json_encode( array( 'validation' => $span_row_validated, 'row' => $span_row_row, 'markup' => $span_row_markup, 'css' => $span_row_css ) )
	);
	$flex_cell_html = '<style>.contact-row{display:flex;flex-direction:row;justify-content:space-between;align-items:center;width:100%;max-width:1000px}.contact-cell{width:50%;margin-right:5px;margin-left:5px}'
		. '@media screen and (max-width:479px){.contact-row{flex-direction:column}.contact-cell{width:100%;margin-left:0;margin-right:0}}'
		. '@media screen and (min-width:1920px){.contact-row{margin-left:0}}</style>'
		. '<form><div class="contact-row"><div class="contact-cell"><label>First name</label><input name="first" type="text"></div>'
		. '<div class="contact-cell"><label>Last name</label><input name="last" type="text"></div></div>'
		. '<div class="contact-row"><div class="contact-cell"><label>Phone</label><input name="phone" type="tel"></div>'
		. '<div class="contact-cell"><label>Postal code</label><input name="postal" type="text"></div></div>'
		. '<button type="submit">Send</button></form>';
	$flex_cell_form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $flex_cell_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$flex_cell_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $flex_cell_form ) ) );
	// The production entity has additional per-field structure that selects the
	// flattened projection. Keep this fixture minimal while exercising that path.
	foreach ( $flex_cell_validated['forms'][0]['layout_graph']['nodes'] as $layout_index => $flex_layout_node ) {
		if ( in_array( $flex_layout_node['id'] ?? '', array( 'wrapper-0', 'wrapper-3' ), true ) ) {
			$flex_cell_validated['forms'][0]['layout_graph']['nodes'][ $layout_index ]['layout']['max_width'] = '1000px';
		}
	}
	$flex_cell_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $flex_cell_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$flex_cell_css       = $expand_selector_lists( (string) ( $flex_cell_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$assert(
		empty( $flex_cell_validated['errors'] )
			&& 'mapped' === ( $flex_cell_row['status'] ?? '' )
			&& true === ( $flex_cell_row['runtime_mapped'] ?? false )
			&& in_array( 'provider_flex_cell_row_transposition', array_column( $flex_cell_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $flex_cell_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true )
			&& str_contains( $flex_cell_css, 'screen and (max-width:479px)' ),
		'flex-field-cell-rows-transpose-width-margin-and-phone-column-flow',
		wp_json_encode( array( 'validation' => $flex_cell_validated, 'row' => $flex_cell_row, 'css' => $flex_cell_css ) )
	);
	// A label that wraps its own control and is set to `display: block` stacks
	// one field in block flow, the provider's own field arrangement. Only its
	// margin is a box fact the provider does not carry; that is a named box
	// loss, not a topology decline.
	$block_label_html = '<style>.contact label{display:block;font-size:16px;margin-bottom:16px}.contact input,.contact textarea{display:block;width:100%}</style>'
		. '<section class="contact"><form action="https://example.com/submit" method="POST"><label>Your name<input name="name" required></label>'
		. '<label>Your email<input type="email" name="email" required></label><label>Message<textarea name="message" rows="4"></textarea></label>'
		. '<button type="submit">Send</button></form></section>';
	$block_label_form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $block_label_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$block_label_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $block_label_form ) ) );
	$block_label_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $block_label_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$block_label_losses    = array_column( $block_label_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $block_label_validated['errors'] )
			&& 'mapped' === ( $block_label_row['status'] ?? '' )
			&& true === ( $block_label_row['runtime_mapped'] ?? false )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', $block_label_losses, true )
			&& 3 === count( array_keys( $block_label_losses, 'provider_wrapper_box_unrepresentable', true ) )
			&& str_contains( (string) ( $block_label_row['block_markup'] ?? '' ), 'wp:jetpack/field-email' ),
		'block-flow-label-wrappers-map-with-named-box-losses',
		wp_json_encode( array( 'validation' => $block_label_validated, 'row' => $block_label_row ) )
	);
	// CSS Display blockifies a label that is a flex item, but not one in
	// ordinary block flow. Keep the reduced comment form neutral (#2203).
	foreach ( array( 'flex', 'block' ) as $label_parent_display ) {
		$item_label_html = '<!doctype html><html><head><meta charset="utf-8"><title>Comment</title><style>'
			. 'label{display:inline-block;margin-bottom:.5rem}.agree{display:' . $label_parent_display . ';align-items:center}.box textarea{width:100%}'
			. '</style></head><body><main><h1>Article</h1><div class="box"><textarea name="comment" rows="5" placeholder="Your comment..."></textarea>'
			. '<div class="agree"><label for="agree"><input id="agree" type="checkbox" checked> I agree to the <a href="/rules">rules</a></label></div>'
			. '<input type="submit" value="Add comment"></div></main></body></html>';
		$item_label_form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'comment.html', 'files' => array( 'comment.html' => $item_label_html ) ) )->toArray() )['fallbacks'][0] ?? array();
		$item_label_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $item_label_form ) ) );
		$item_label_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $item_label_validated['forms'] ?? array() ) )['forms'][0] ?? array();
		$item_label_losses    = array_column( $item_label_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
		if ( 'flex' === $label_parent_display ) {
			$assert(
				empty( $item_label_validated['errors'] )
					&& 'mapped' === ( $item_label_row['status'] ?? '' )
					&& true === ( $item_label_row['runtime_mapped'] ?? false )
					&& in_array( 'provider_wrapper_box_unrepresentable', $item_label_losses, true )
					&& ! in_array( 'provider_wrapper_layout_unrepresentable', $item_label_losses, true )
					&& str_contains( (string) ( $item_label_row['block_markup'] ?? '' ), 'wp:jetpack/contact-form' ),
				'flex-item-inline-block-label-maps-with-named-box-loss',
				wp_json_encode( array( 'validation' => $item_label_validated, 'row' => $item_label_row ) )
			);
		} else {
			$assert(
				empty( $item_label_validated['errors'] )
					&& 'mapped' !== ( $item_label_row['status'] ?? '' )
					&& in_array( 'provider_wrapper_layout_unrepresentable', $item_label_losses, true )
					&& ! in_array( 'provider_wrapper_box_unrepresentable', $item_label_losses, true ),
				'block-parent-inline-block-label-retains-topology-loss',
				wp_json_encode( array( 'validation' => $item_label_validated, 'row' => $item_label_row ) )
			);
		}
	}
	// A wrapper that groups some but not all fields (a first/last name row) and
	// declares `width: 100%` without any horizontal margin, padding or border is
	// still plain block flow: a block box's default content width. It must not
	// decline the mapping as unrepresentable topology.
	$group_width_html = '<style>.complex{width:100%}</style>'
		. '<form method="post" action="/contact/">'
		. '<div class="complex"><input type="text" name="first" aria-label="First"><input type="text" name="last" aria-label="Last"></div>'
		. '<div><input type="text" name="title" aria-label="Title"></div>'
		. '<button type="submit">Submit</button></form>';
	$group_width_form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $group_width_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$group_width_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $group_width_form ) ) );
	$group_width_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $group_width_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$group_width_losses    = array_column( $group_width_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $group_width_validated['errors'] )
			&& 'mapped' === ( $group_width_row['status'] ?? '' )
			&& true === ( $group_width_row['runtime_mapped'] ?? false )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', $group_width_losses, true )
			&& ! in_array( 'provider_wrapper_box_unrepresentable', $group_width_losses, true )
			&& 3 === substr_count( (string) ( $group_width_row['block_markup'] ?? '' ), '<!-- wp:jetpack/field-text' ),
		'full-width-field-group-wrapper-maps-as-block-flow',
		wp_json_encode( array( 'validation' => $group_width_validated, 'row' => $group_width_row ) )
	);
	// The same block-flow wrapper may declare its `width: 100%` only inside a
	// media query, leaving no base layout at all. That variant adds no fact
	// either, so the partial wrapper must still map rather than decline.
	$variant_width_html = '<style>@media (min-width:641px){.body{width:100%}}</style>'
		. '<form method="post" action="/contact/">'
		. '<div class="body"><input type="text" name="first" aria-label="First"><input type="email" name="email" aria-label="Email"></div>'
		. '<button type="submit">Send</button><p><label>Message<textarea name="message"></textarea></label></p></form>';
	$variant_width_form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $variant_width_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$variant_width_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $variant_width_form ) ) );
	$variant_width_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $variant_width_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$variant_width_losses    = array_column( $variant_width_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $variant_width_validated['errors'] )
			&& 'mapped' === ( $variant_width_row['status'] ?? '' )
			&& true === ( $variant_width_row['runtime_mapped'] ?? false )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', $variant_width_losses, true )
			&& str_contains( (string) ( $variant_width_row['block_markup'] ?? '' ), 'wp:jetpack/field-textarea' ),
		'media-only-full-width-field-group-wrapper-maps-as-block-flow',
		wp_json_encode( array( 'validation' => $variant_width_validated, 'row' => $variant_width_row ) )
	);
	// #2216: a partial block-flow field group contains a second block row
	// whose floated halves compensate the inter-column margin in their width.
	$nested_name_html = file_get_contents( __DIR__ . '/fixtures/nested-name-row.html' );
	$nested_name_seed = static function ( string $html ) use ( $artifact_compiler ): array {
		$form = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray() )['fallbacks'][0] ?? array();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
		return Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	};
	$nested_name_row = $nested_name_seed( $nested_name_html );
	$assert(
		'mapped' === ( $nested_name_row['status'] ?? '' )
			&& empty( $nested_name_row['form_receipt_unaccepted_losses'] )
			&& 2 === preg_match_all( '#<!-- wp:jetpack/field-text \{[^}]*"width":50#', (string) ( $nested_name_row['block_markup'] ?? '' ) )
			&& in_array( 'provider_compensated_percentage_halves', array_column( $nested_name_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'nested-floated-name-row-maps-as-responsive-equal-width-fields',
		wp_json_encode( $nested_name_row )
	);
	foreach ( array(
		'offset' => str_replace( 'padding-bottom:18px;position:relative', 'padding-bottom:18px;position:relative;top:12px', $nested_name_html ),
		'variant-offset' => str_replace( '@media(max-width:768px){', '@media(max-width:768px){.field-group{top:12px}', $nested_name_html ),
		'overflowing-base' => str_replace( 'margin-left:20px', 'margin-left:30px', $nested_name_html ),
		'overflowing-desktop' => str_replace( 'margin-left:9px', 'margin-left:30px', $nested_name_html ),
		'unsupported-width' => str_replace( 'calc(50% - 10px)', '280px', $nested_name_html ),
		'unsupported-gutter' => str_replace( 'margin-left:20px', 'margin-left:1rem', $nested_name_html ),
		'overlapping-queries' => str_replace( 'min-width:1024px', 'min-width:700px', $nested_name_html ),
	) as $case => $html ) {
		$negative = $nested_name_seed( $html );
		$assert( ! in_array( 'provider_compensated_percentage_halves', array_column( $negative['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) || 'mapped' !== ( $negative['status'] ?? '' ), 'nested-name-row-' . $case . '-keeps-unsupported-layout', wp_json_encode( $negative ) );
	}
	// Equal inline-block columns with a uniform trailing gutter, widened by a
	// matching `calc(100% + gutter)` row overhang, are an equal-width row: the
	// provider paints them as half-width fields separated by that gutter. An
	// overhang that does not match the gutter keeps the topology loss, and a
	// row widened only at a min-width query stacks below it.
	$gutter_row_fields = '<form method="post" action="/contact/">'
		. '<div class="row"><span><input type="text" name="first" aria-label="First"></span><span><input type="text" name="last" aria-label="Last"></span></div>'
		. '<input type="email" name="email" aria-label="Email"><button type="submit">Send</button></form>';
	$gutter_row_seed = static function ( string $css ) use ( $artifact_compiler, $gutter_row_fields ): array {
		$form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => '<style>' . $css . '</style>' . $gutter_row_fields ) ) )->toArray() )['fallbacks'][0] ?? array();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		return array(
			'errors'     => $validated['errors'] ?? array(),
			'row'        => $row,
			'losses'     => array_column( $row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ),
			'strategies' => array_column( $row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ),
			'halves'     => preg_match_all( '#<!-- wp:jetpack/field-text \{[^}]*"width":50#', (string) ( $row['block_markup'] ?? '' ) ),
			'css'        => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
		);
	};
	$gutter_row = $gutter_row_seed( '.row{width:calc(100% + 16px)}.row span{display:inline-block;box-sizing:border-box;width:50%;padding-right:16px}' );
	$assert(
		empty( $gutter_row['errors'] )
			&& 'mapped' === ( $gutter_row['row']['status'] ?? '' )
			&& in_array( 'provider_gutter_compensated_row_fields', $gutter_row['strategies'], true )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', $gutter_row['losses'], true )
			&& 2 === $gutter_row['halves']
			&& str_contains( $gutter_row['css'], 'flex-basis:calc(50% - 8px)' )
			&& 1 === substr_count( $gutter_row['css'], 'margin-right:16px' ),
		'gutter-compensated-percentage-row-maps-as-equal-width-fields',
		wp_json_encode( $gutter_row )
	);
	$mismatched_gutter_row = $gutter_row_seed( '.row{width:calc(100% + 30px)}.row span{display:inline-block;box-sizing:border-box;width:50%;padding-right:16px}' );
	$assert(
		'mapped' !== ( $mismatched_gutter_row['row']['status'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', $mismatched_gutter_row['losses'], true )
			&& ! in_array( 'provider_gutter_compensated_row_fields', $mismatched_gutter_row['strategies'], true ),
		'mismatched-row-overhang-keeps-wrapper-layout-loss',
		wp_json_encode( $mismatched_gutter_row )
	);
	$scoped_gutter_row = $gutter_row_seed( '.row{width:100%}@media only screen and (min-width:641px){.row{width:calc(100% + 15px)}.row span{display:inline-block;width:50%;padding-right:16px;margin-right:-4px}}@media only screen and (max-width:641px){.row span{display:block;margin-bottom:8px}}' );
	$assert(
		empty( $scoped_gutter_row['errors'] )
			&& 'mapped' === ( $scoped_gutter_row['row']['status'] ?? '' )
			&& in_array( 'provider_gutter_compensated_row_fields', $scoped_gutter_row['strategies'], true )
			&& in_array( 'provider_wrapper_box_unrepresentable', $scoped_gutter_row['losses'], true )
			&& 2 === $scoped_gutter_row['halves']
			&& 1 === preg_match( '/@media \(width<641px\)\{[^}]*flex:1 1 100%;width:100%;max-width:100%;margin-right:0\}/', $scoped_gutter_row['css'] ),
		'min-width-scoped-gutter-row-maps-and-stacks-below-its-query',
		wp_json_encode( $scoped_gutter_row )
	);
	// Desktop-first equal-column row: two tracks by default, one below a
	// max-width query. That is the provider's equal-width row with its stack
	// scoped to the source query. A narrowing that also changes another
	// property, an unequal narrowing, or a min-width collapse still declines.
	$narrowing_row_fields = '<form class="cf" novalidate><div class="row"><label class="field"><span>Name</span><input type="text" name="name" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label></div>'
		. '<label class="field"><span>Subject</span><input type="text" name="subject" required></label><label class="field"><span>Message</span><textarea name="message" rows="6" required></textarea></label><button type="submit">Send</button></form>';
	$narrowing_row_seed = static function ( string $css ) use ( $artifact_compiler, $narrowing_row_fields ): array {
		$form      = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => '<style>' . $css . '</style>' . $narrowing_row_fields ) ) )->toArray() )['fallbacks'][0] ?? array();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		return array(
			'errors' => $validated['errors'] ?? array(),
			'status' => $row['status'] ?? '',
			'losses' => array_column( $row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ),
			'halves' => preg_match_all( '#<!-- wp:jetpack/field-(?:text|email) \{[^}]*"width":50#', (string) ( $row['block_markup'] ?? '' ) ),
			'css'    => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
		);
	};
	foreach ( array(
		'fr'     => '.cf{display:grid;gap:1.1rem}.cf .row{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem}@media (max-width:720px){.cf .row{grid-template-columns:1fr}}',
		'minmax' => '.cf .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}@media (max-width:600px){.cf .row{grid-template-columns:minmax(0,1fr)}}',
	) as $narrowing_case => $narrowing_css ) {
		$narrowing_row = $narrowing_row_seed( $narrowing_css );
		$query         = 'fr' === $narrowing_case ? '(max-width:720px)' : '(max-width:600px)';
		$assert(
			empty( $narrowing_row['errors'] )
				&& 'mapped' === $narrowing_row['status']
				&& ! in_array( 'provider_wrapper_layout_unrepresentable', $narrowing_row['losses'], true )
				&& 2 === $narrowing_row['halves']
				&& 1 === preg_match( '/@media ' . preg_quote( $query, '/' ) . '\{[^{}]*-wrap\{flex:1 1 100%;width:100%\}/', $narrowing_row['css'] ),
			'desktop-first-equal-row-maps-and-stacks-at-its-max-width-query-' . $narrowing_case,
			wp_json_encode( $narrowing_row )
		);
	}
	foreach ( array(
		'gap'      => '.cf .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (max-width:600px){.cf .row{grid-template-columns:1fr;gap:4px}}',
		'unequal'  => '.cf .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (max-width:600px){.cf .row{grid-template-columns:1fr 2fr}}',
		'minwidth' => '.cf .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (min-width:600px){.cf .row{grid-template-columns:1fr}}',
	) as $declined_case => $declined_css ) {
		$declined_row = $narrowing_row_seed( $declined_css );
		$assert(
			'mapped' !== $declined_row['status'] && in_array( 'provider_wrapper_layout_unrepresentable', $declined_row['losses'], true ),
			'non-stacking-row-variant-keeps-wrapper-layout-loss-' . $declined_case,
			wp_json_encode( $declined_row )
		);
	}
	$real_entity_path = getenv( 'STATIC_SITE_IMPORTER_REAL_FORM_ENTITY' );
	if ( is_string( $real_entity_path ) && is_readable( $real_entity_path ) ) {
		$real_entity_data = json_decode( (string) file_get_contents( $real_entity_path ), true );
		$real_entity_form = is_array( $real_entity_data['entity'] ?? null ) ? $real_entity_data['entity'] : array();
		$real_entity_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $real_entity_form ) ) );
		$real_entity_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $real_entity_validation['forms'] ?? array() ) )['forms'][0] ?? array();
		$real_entity_losses = array_column( $real_entity_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
		$real_entity_css = (string) ( $real_entity_row['provider_layout_overlay_css']['css'] ?? '' );
		$assert(
			empty( $real_entity_validation['errors'] )
				&& 'mapped' === ( $real_entity_row['status'] ?? '' )
				&& true === ( $real_entity_row['runtime_mapped'] ?? false )
				&& ! in_array( 'provider_wrapper_layout_unrepresentable', $real_entity_losses, true )
				&& str_contains( $real_entity_css, 'screen and (max-width:479px)' )
				&& str_contains( $real_entity_css, 'screen and (min-width:1920px)' ),
			'retained-2078-declined-entity-materializes-and-retains-both-screen-variants',
			wp_json_encode( array( 'status' => $real_entity_row['status'] ?? null, 'losses' => $real_entity_losses, 'css' => $real_entity_css ) )
		);
	}
	// A source may author the same grid tracks at every width but add its
	// gutter only at desktop. The provider must preserve the conditional gap
	// rather than leaving a visually complete, non-submitting form behind.
	$conditional_gap_form = $span_row_form;
	$conditional_gap_form['layout_graph']['nodes'][1]['layout'] = array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)', 'width' => '100%' );
	$conditional_gap_form['layout_graph']['nodes'][1]['provenance'] = array_slice( $conditional_gap_form['layout_graph']['nodes'][1]['provenance'], 0, 1 );
	$conditional_gap_form['layout_graph']['variants'][] = array(
		'node' => 'wrapper-0',
		'condition' => array( 'kind' => 'media', 'query' => '(min-width:768px)' ),
		'layout_patch' => array( 'column_gap' => '12px' ),
		'precedence' => array( 'column-gap' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ),
		'provenance' => array( array(
			'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ),
			'selector' => '.field-row', 'condition' => array( 'kind' => 'media', 'query' => '(min-width:768px)' ),
			'properties' => array( 'column-gap' ),
		) ),
	);
	$conditional_gap_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $conditional_gap_form ) ) );
	$conditional_gap_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $conditional_gap_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$conditional_gap_css = (string) ( $conditional_gap_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $conditional_gap_validated['errors'] )
			&& 'mapped' === ( $conditional_gap_row['status'] ?? '' )
			&& in_array( 'provider_grid_span_fields', array_column( $conditional_gap_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $conditional_gap_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true )
			&& str_contains( $conditional_gap_css, '@media (min-width:768px)' )
			&& str_contains( $conditional_gap_css, 'width:calc(50% - 6px)' ),
		'proven-desktop-only-grid-gutter-keeps-real-provider-form-and-conditional-tracks',
		wp_json_encode( array( 'validation' => $conditional_gap_validated['errors'] ?? array(), 'status' => $conditional_gap_row['status'] ?? '', 'losses' => $conditional_gap_row['computed_layout_receipt']['losses'] ?? array(), 'css' => $conditional_gap_css ) )
	);
	$unclean_span_form = $span_row_form;
	$unclean_span_form['layout_graph']['nodes'][2]['layout']['column'] = '1 / span 5';
	$unclean_span_form['layout_graph']['nodes'][3]['layout']['column'] = '6 / span 7';
	$unclean_span_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unclean_span_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		'skipped' === ( $unclean_span_row['status'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', array_column( $unclean_span_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'span-that-does-not-map-to-a-jetpack-field-width-keeps-the-wrapper-layout-decline',
		wp_json_encode( $unclean_span_row )
	);
	$column_stack_html = '<style>.stack{display:flex;flex-direction:column;gap:24px;width:100%}</style><form class="stack">'
		. '<div style="display:grid;width:100%;grid-template-columns:repeat(12, 1fr);column-gap:24px">'
		. '<div style="grid-column:1 / span 6"><label>First name</label><input type="text" name="first"></div>'
		. '<div style="grid-column:7 / span 6"><label>Last name</label><input type="text" name="last"></div>'
		. '<div style="grid-column:1 / span 12"><label>Message</label><textarea name="message"></textarea></div>'
		. '<div style="grid-column:1 / span 3"><button type="submit">Send</button></div>'
		. '</div></form>';
	$column_stack_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $column_stack_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$column_stack_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $column_stack_source ) ) );
	$column_stack_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $column_stack_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$column_stack_markup    = (string) ( $column_stack_row['block_markup'] ?? '' );
	$column_stack_css       = (string) ( $column_stack_row['provider_layout_overlay_css']['css'] ?? '' );
	$column_stack_form_rule = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} > form\.jetpack-contact-form__form:not\(:has\(\.wp-block-jetpack-contact-form\)\), \.ssi-form-[a-f0-9]{12}:not\(:has\(> form\.jetpack-contact-form__form\)\)\{([^}]+)\}/', $column_stack_css, $column_stack_form ) ? $column_stack_form[1] : '';
	$assert(
		empty( $column_stack_validated['errors'] )
			&& 'mapped' === ( $column_stack_row['status'] ?? '' )
			&& true === ( $column_stack_row['runtime_mapped'] ?? false )
			&& in_array( 'provider_grid_span_fields', array_column( $column_stack_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& str_contains( $column_stack_markup, 'stack' )
			&& str_contains( $column_stack_form_rule, 'flex-direction:row' )
			&& str_contains( $column_stack_form_rule, 'flex-wrap:wrap' )
			&& str_contains( $column_stack_form_rule, 'gap:24px' )
			&& ! str_contains( $column_stack_form_rule, 'flex-direction:column' )
			&& 2 === substr_count( $column_stack_markup, '"width":50' )
			&& 1 === substr_count( $column_stack_markup, '"width":100' )
			&& str_contains( $column_stack_css, 'width:calc(25% - 18px)' ),
		'column-flex-form-root-does-not-stack-a-represented-grid-span-row',
		wp_json_encode( array( 'validation' => $column_stack_validated, 'row' => $column_stack_row, 'rule' => $column_stack_form_rule, 'css' => $column_stack_css ) )
	);
	// A source that deliberately sizes two textareas differently through their own
	// `rows` attribute - rather than an authored CSS height a cascade compiler could
	// capture - must not materialize both onto this provider's one fixed default;
	// each field's own row count must reach the rendered textarea.
