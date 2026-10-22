<?php
/**
 * Form materializer smoke suite: layout-graph grid rows mapped onto provider field widths.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$layout_only_form = $topology_form;
	unset( $layout_only_form['forms'][0]['control_topology'] );
	$layout_only_form['forms'][0]['layout_graph']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'row-2' ) ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)', 'gap' => '1rem' ), 'provenance' => array() ),
		array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'field' ) ), 'layout' => array(), 'provenance' => array() ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
		array( 'id' => 'wrapper-2', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array( 'field' ) ), 'layout' => array(), 'provenance' => array() ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
		array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'source' => array( 'tag' => 'textarea', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
	);
	$layout_only_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $layout_only_form );
	$layout_only_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $layout_only_validated['forms'] ?? array() ) );
	$layout_only_markup    = (string) ( $layout_only_seed['forms'][0]['block_markup'] ?? '' );
	$assert( empty( $layout_only_validated['errors'] ) && 2 === substr_count( $layout_only_markup, '"width":50' ) && in_array( 'provider_equal_width_fields', array_column( $layout_only_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'layout-graph-only-form-recovers-grid-topology-and-field-widths', wp_json_encode( $layout_only_seed ) );

	$responsive_equal_form = $topology_form;
	$responsive_equal_condition = array( 'kind' => 'media', 'query' => '(width>=40rem)' );
	$responsive_equal_form['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'grid', 'gap' => '1rem' );
	$responsive_equal_form['forms'][0]['layout_graph']['variants'][] = array(
		'node'         => 'wrapper-0',
		'condition'    => $responsive_equal_condition,
		'layout_patch' => array( 'columns' => 'repeat(2,minmax(0,1fr))' ),
		'precedence'   => array( 'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ),
		'provenance'   => array(
			array(
				'source_path'   => 'assets/form.css',
				'source_sha256' => str_repeat( 'd', 64 ),
				'selector'      => '.row-2',
				'condition'     => $responsive_equal_condition,
				'properties'    => array( 'grid-template-columns' ),
			),
		),
	);
	$responsive_equal_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_equal_form );
	$responsive_equal_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_equal_validated['forms'] ) );
	$responsive_equal_row       = $responsive_equal_seed['forms'][0] ?? array();
	$responsive_equal_css       = $expand_selector_lists( (string) ( $responsive_equal_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$responsive_equal_markup    = (string) ( $responsive_equal_row['block_markup'] ?? '' );
	$assert(
		empty( $responsive_equal_validated['errors'] )
			&& 'mapped' === ( $responsive_equal_row['status'] ?? '' )
			&& 2 === substr_count( $responsive_equal_markup, '"width":50' )
			&& in_array( 'provider_equal_width_fields', array_column( $responsive_equal_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& str_contains( $responsive_equal_css, '@media (width<40rem){' )
			&& 2 === preg_match_all( '/@media \(width<40rem\)\{\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{flex:1 1 100%;width:100%\}\}/', $responsive_equal_css )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.5rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.5rem\);margin-block-start:0!important\}/', $responsive_equal_css )
			&& ( 402 / 16 ) < 40
			&& ( 1440 / 16 ) >= 40
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $responsive_equal_row['provider_layout_overlay_css'] ?? null ),
		'responsive-equal-width-row-emits-inverted-overlay-media-full-width-at-402px-two-up-at-1440px',
		wp_json_encode( array( 'validation' => $responsive_equal_validated, 'css' => $responsive_equal_css, 'markup' => $responsive_equal_markup ) )
	);

	// --- A source utility-framework grid row materializes as provider field widths ---
	// Ward's row is a partial form subtree: the two grid children are neutral,
	// classless div shells, each containing one labelled field. A two-control
	// form with controls directly beneath the grid does not exercise this seam.
	$nested_grid_css  = '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-4{gap:1rem}'
		. '@media (width>=640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$nested_grid_html = '<style>' . $nested_grid_css . '</style><form class="space-y-4">'
		. '<div><label for="before-a">Before A</label><input id="before-a" name="before_a"></div>'
		. '<div><label for="before-b">Before B</label><input id="before-b" name="before_b"></div>'
		. '<div><label for="before-c">Before C</label><input id="before-c" name="before_c"></div>'
		. '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
		. '<div><label for="first">First</label><input id="first" name="first" required></div>'
		. '<div><label for="second">Second</label><input id="second" type="email" name="second" required></div>'
		. '</div>'
		. '<div><label>After<textarea name="after"></textarea></label></div>'
		. '<button type="submit">Send</button></form>';
	$nested_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $nested_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$nested_grid_nodes = array_column( $nested_grid_source['control_topology']['nodes'] ?? array(), null, 'id' );
	$nested_grid_parent = array_values( array_filter( $nested_grid_nodes, static fn( array $node ): bool => str_contains( (string) ( $node['class'] ?? '' ), 'grid-cols-1' ) ) )[0] ?? array();
	$nested_grid_shells = array_values( array_filter( $nested_grid_nodes, static fn( array $node ): bool => ( $node['parent'] ?? null ) === ( $nested_grid_parent['id'] ?? null ) && 'wrapper' === ( $node['kind'] ?? null ) && 'div' === ( $node['tag'] ?? null ) && '' === ( $node['class'] ?? '' ) ) );
	$assert( 'wrapper-3' === ( $nested_grid_parent['id'] ?? '' ) && 2 === count( $nested_grid_shells ) && array( 'wrapper-4', 'wrapper-5' ) === array_column( $nested_grid_shells, 'id' ), 'nested-neutral-grid-fixture-captures-classless-field-shells', wp_json_encode( $nested_grid_source['control_topology']['nodes'] ?? null ) );
	$nested_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $nested_grid_source ) ) );
	$nested_grid_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $nested_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_grid_markup = (string) ( $nested_grid_row['block_markup'] ?? '' );
	$nested_grid_overlay = $nested_grid_row['provider_layout_overlay_css'] ?? array();
	$nested_grid_css_out = $expand_selector_lists( (string) ( $nested_grid_overlay['css'] ?? '' ) );
	$assert( empty( $nested_grid_validated['errors'] ) && 'mapped' === ( $nested_grid_row['status'] ?? '' ) && array() === ( $nested_grid_row['mapping_decision']['losses'] ?? null ) && empty( $nested_grid_row['form_receipt_unaccepted_losses'] ?? array() ), 'nested-neutral-grid-row-maps-without-loss', wp_json_encode( array( 'validation' => $nested_grid_validated['errors'], 'row' => $nested_grid_row['reason'] ?? null, 'losses' => $nested_grid_row['mapping_decision']['losses'] ?? null ) ) );
	$assert( 6 === ( $nested_grid_row['field_count'] ?? 0 ) && 2 === substr_count( $nested_grid_markup, '"width":50' ) && 1 === preg_match( '/wp:jetpack\/field-text [^\n]*"width":50/', $nested_grid_markup ) && 1 === preg_match( '/wp:jetpack\/field-email [^\n]*"width":50/', $nested_grid_markup ) && ! str_contains( $nested_grid_markup, 'wp:group' ) && $nested_grid_markup === serialize_blocks( parse_blocks( $nested_grid_markup ) ), 'nested-neutral-grid-row-preserves-provider-fields-and-save-roundtrip', $nested_grid_markup );
	$assert( str_contains( $nested_grid_css_out, '@media (width<640px){' ) && 2 === preg_match_all( '/@media \(width<640px\)\{\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{flex:1 1 100%;width:100%\}\}/', $nested_grid_css_out ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $nested_grid_overlay ) && in_array( 'provider_equal_width_fields', array_column( $nested_grid_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'nested-neutral-grid-row-scoped-overlay-stacks-below-640px', $nested_grid_css_out );
	$assert( 1 === preg_match( '/field-text[\s\S]*field-text[\s\S]*field-email[\s\S]*field-textarea[\s\S]*wp:button/', $nested_grid_markup ), 'nested-neutral-grid-row-keeps-source-field-order', $nested_grid_markup );
	// Ward's grid differs from the neutral single-control shells above: the
	// second shell owns both a visible combobox and its hidden native value
	// carrier. This is the producer's direct-sibling choice contract: the
	// trigger is a combobox/listbox and the native select is hidden, adjacent,
	// and carries the submitted values. The producer proves the relationship
	// by exact selector, rather than inferring it from matching labels.
	$ward_html = '<style>' . $nested_grid_css . '</style><form class="space-y-4">'
		. '<div><div><div><input name="first" aria-label="First"></div><input name="second" aria-label="Second"></div></div>'
		. '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
		. '<div><label for="ward-phone">Phone</label><input id="ward-phone" name="phone" type="tel" required></div>'
		. '<div><label>Contact Preference</label><button type="button" role="combobox" aria-haspopup="listbox" aria-label="Contact Preference">Phone</button><select hidden aria-hidden="true" tabindex="-1" name="contact_preference" required><option value="email">Email</option><option value="phone" selected>Phone</option></select></div>'
		. '</div>'
		. '<div><label>Services</label><button type="button" role="combobox" aria-haspopup="listbox" aria-label="Services">Consulting</button><select hidden aria-hidden="true" tabindex="-1" name="services" required><option value="consulting" selected>Consulting</option><option value="support">Support</option></select></div>'
		. '<button type="submit">Send</button></form>';
	$ward_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $ward_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$ward_controls = $ward_source['controls'] ?? array();
	$ward_nodes = array_column( $ward_source['control_topology']['nodes'] ?? array(), null, 'id' );
	$assert( 'wrapper-3' === ( $ward_nodes['wrapper-4']['parent'] ?? null ) && 'wrapper-3' === ( $ward_nodes['wrapper-5']['parent'] ?? null ) && 'wrapper-4' === ( $ward_nodes['control-2']['parent'] ?? null ) && 'wrapper-5' === ( $ward_nodes['control-3']['parent'] ?? null ) && 'wrapper-5' === ( $ward_nodes['control-4']['parent'] ?? null ) && 'wrapper-6' === ( $ward_nodes['control-5']['parent'] ?? null ) && 'wrapper-6' === ( $ward_nodes['control-6']['parent'] ?? null ) && 'combobox' === ( $ward_controls[3]['role'] ?? null ) && 'combobox' === ( $ward_controls[5]['role'] ?? null ) && ! empty( $ward_controls[3]['choice_source_selector'] ) && ( $ward_controls[3]['choice_source_selector'] ?? null ) === ( $ward_controls[4]['selector'] ?? null ) && ( $ward_controls[5]['choice_source_selector'] ?? null ) === ( $ward_controls[6]['selector'] ?? null ) && ( $ward_controls[3]['options'] ?? null ) === ( $ward_controls[4]['options'] ?? null ) && ( $ward_controls[5]['options'] ?? null ) === ( $ward_controls[6]['options'] ?? null ), 'ward-paired-grid-fixture-proves-exact-native-select-link', wp_json_encode( array( $ward_controls, $ward_nodes ) ) );
	$ward_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $ward_source ) ) );
	$ward_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $ward_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$ward_markup = (string) ( $ward_row['block_markup'] ?? '' );
	$ward_css = (string) ( $ward_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $ward_validated['errors'] ) && 'mapped' === ( $ward_row['status'] ?? '' ) && array() === ( $ward_row['mapping_decision']['losses'] ?? null ) && 5 === ( $ward_row['field_count'] ?? 0 ), 'ward-linked-pairs-map-once-without-waived-loss', wp_json_encode( array( 'validation' => $ward_validated['errors'], 'row' => $ward_row['reason'] ?? '', 'losses' => $ward_row['mapping_decision']['losses'] ?? null ) ) );
	$assert( 2 === substr_count( $ward_markup, 'wp:jetpack/field-select {' ) && str_contains( $ward_markup, '"options":["Email","Phone"]' ) && str_contains( $ward_markup, '"options":["Consulting","Support"]' ) && 2 === substr_count( $ward_markup, '"width":50' ) && str_contains( $ward_css, '@media (width<640px){' ) && $ward_markup === serialize_blocks( parse_blocks( $ward_markup ) ), 'ward-linked-pairs-retain-nested-shell-and-responsive-two-track-grid', $ward_markup . "\n" . $ward_css );
	$choice_token = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $ward_controls[4]['options'] ?? null );
	$projected_choice = Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( '<div class="' . $choice_token . '"><select required><option value="">Select one option</option><option value="Email">Email</option><option value="Phone">Phone</option></select></div>' );
	$assert( str_contains( $ward_markup, $choice_token ) && str_contains( $projected_choice, '<option value="email">Email</option>' ) && str_contains( $projected_choice, '<option value="phone" selected="selected">Phone</option>' ) && str_contains( $projected_choice, '<select required>' ), 'ward-choice-provider-projects-distinct-values-and-selected-required-state', $projected_choice );
	$wrapped_choice = Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( '<div class="' . $choice_token . '-wrap"><select><option value="Email">Email</option><option value="Phone">Phone</option></select></div>' );
	$assert( str_contains( $wrapped_choice, 'value="phone" selected="selected"' ) && '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( array( array( 'label' => 'A', 'value' => 'a', 'selected' => true ), array( 'label' => 'B', 'value' => 'b', 'selected' => true ) ) ), 'choice-projection-handles-provider-wrap-suffix-and-rejects-ambiguous-selection', $wrapped_choice );
	$unlinked_ward = $ward_validated['forms'][0];
	$unlinked_ward['controls'][3]['choice_source_selector'] = 'form > select.other';
	$unlinked_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unlinked_ward ) ) )['forms'][0] ?? array();
	$assert( 1 === substr_count( (string) ( $unlinked_row['block_markup'] ?? '' ), '<!-- wp:button {"tagName":"button","type":"button"' ) && 2 === substr_count( (string) ( $unlinked_row['block_markup'] ?? '' ), 'wp:jetpack/field-select {' ) && 'mapped' !== ( $unlinked_row['status'] ?? '' ), 'unlinked-choice-does-not-borrow-hidden-select-or-claim-loss-free-grid', wp_json_encode( $unlinked_row['mapping_decision']['losses'] ?? null ) );
	// Reproduces a real base44/Tailwind CSS v4 contact form (labels are plain,
	// unassociated siblings with no `for`/`id`/`name`, exactly as captured): a
	// `grid grid-cols-1 md:grid-cols-2 gap-6` row stacks Name/Phone on narrow
	// viewports and only bands them into two equal columns at a proven
	// widening breakpoint. Tailwind v4 emits that breakpoint using the modern
	// CSS range syntax `(width>=768px)` rather than `(min-width:768px)`.
	// Jetpack field width has no responsive states, so the widened, two-up
	// state is what materializes; every other wrapper here is a redundant
	// single-control `<div>` a Jetpack field already owns as its own unit.
	$tailwind_grid_css   = '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$tailwind_grid_html  = '<style>' . $tailwind_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<div><label>Email *</label><input type="email" required></div>'
		. '<div><label>Project Type</label><select><option value="">Select a project type</option><option>Kitchen Renovation</option></select></div>'
		. '<div><label>Project Details *</label><textarea rows="5" placeholder="Tell us about your project..." required></textarea></div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$tailwind_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $tailwind_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$tailwind_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $tailwind_grid_source ) ) );
	$assert( empty( $tailwind_grid_validated['errors'] ), 'mobile-first-grid-row-manifest-validates', wp_json_encode( $tailwind_grid_validated ) );
	$tailwind_grid_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $tailwind_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$tailwind_grid_markup = (string) ( $tailwind_grid_row['block_markup'] ?? '' );
	$assert(
		'mapped' === ( $tailwind_grid_row['status'] ?? '' )
			&& empty( $tailwind_grid_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 5 === ( $tailwind_grid_row['field_count'] ?? 0 )
			&& 2 === substr_count( $tailwind_grid_markup, '"width":50' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/contact-form' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{"required":true[^}]*"width":50\} -->/', $tailwind_grid_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{"required":true[^}]*"width":50\} -->/', $tailwind_grid_markup )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-email' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-select' )
			// The select's leading <option value="">Select a project type</option> is
			// the field's toggle label (Automattic/static-site-importer#2238), not a
			// visitor-selectable option.
			&& str_contains( $tailwind_grid_markup, '"placeholder":"Select a project type"' )
			&& str_contains( $tailwind_grid_markup, '"options":["Kitchen Renovation"]' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-textarea' )
			&& str_contains( $tailwind_grid_markup, '"placeholder":"Tell us about your project..."' )
			&& str_contains( $tailwind_grid_markup, 'ssi-textarea-rows-5' )
			&& ! str_contains( $tailwind_grid_markup, 'wp:group' ),
		'mobile-first-tailwind-v4-grid-row-materializes-as-two-jetpack-fields-at-width-50',
		wp_json_encode( array( 'row' => $tailwind_grid_row, 'markup' => $tailwind_grid_markup ) )
	);
	$tailwind_grid_css_out = $expand_selector_lists( (string) ( $tailwind_grid_row['provider_layout_overlay_css']['css'] ?? '' ) );
	// A carried source sibling-stacking utility (Tailwind's `space-y-*`) is
	// authored against the ORIGINAL sibling relationships, at unbounded
	// specificity, and lands on the provider form unscoped. Once flattening
	// makes Name and Phone adjacent provider siblings that rule still matches
	// them, so a same-specificity reset cannot reliably out-rank it; the reset
	// must carry `!important` on every flattened field shell, paired or not,
	// or the source rule can still re-stack the row.
	$assert(
		empty( $tailwind_grid_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.75rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.75rem\);margin-block-start:0!important\}/', $tailwind_grid_css_out )
			&& 3 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{margin-block-start:0!important\}/', $tailwind_grid_css_out )
			&& ! str_contains( $tailwind_grid_css_out, 'margin-block-start:0}' )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $tailwind_grid_row['provider_layout_overlay_css'] ?? null ),
		'mobile-first-equal-column-row-overlay-compensates-jetpack-gap-and-flattened-sibling-margins',
		$tailwind_grid_css_out
	);
	// A submit control is never routed through Jetpack's grunion field renderer, so
	// its generated hook never gets the `-wrap` class suffix a field's does; it must
	// still be reset, because it sits as a flex item beside the flattened fields in
	// the same `space-y-*`-classed container and would otherwise carry both the
	// container's flex `gap` and the carried sibling-margin, doubling the space the
	// source spent once.
	$assert(
		1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{margin-block-start:0!important\}/', $tailwind_grid_css_out ),
		'mobile-first-equal-column-row-also-neutralizes-the-flattened-submit-siblings-margin',
		$tailwind_grid_css_out
	);
	$assert(
		str_contains( $tailwind_grid_css_out, '@media (width<768px){' )
			&& 2 === preg_match_all( '/@media \(width<768px\)\{\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{flex:1 1 100%;width:100%\}\}/', $tailwind_grid_css_out )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.75rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.75rem\);margin-block-start:0!important\}/', $tailwind_grid_css_out )
			&& 402 < 768
			&& 1440 >= 768,
		'mobile-first-equal-column-row-emits-inverted-overlay-media-so-402px-is-full-width-and-1440px-stays-two-up',
		$tailwind_grid_css_out
	);
	// A source form that occupies a page-grid item through a host wrapper (the
	// wrapper the provider container replaces) must keep that item's column span
	// on the materialized block, without matching any one utility class name.
	$host_span_css  = '.page-grid{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:3rem}'
		. '@media (width>=1024px){.page-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.form-host,.bp\\:span-2{grid-column:span 2}}'
		. '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '.px-8{padding-left:2rem;padding-right:2rem}.py-4{padding-top:1rem;padding-bottom:1rem}'
		. '.bg-foreground{background-color:rgb(0,0,0)}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.tracking-wide{letter-spacing:.025em}';
	$host_span_html = '<style>' . $host_span_css . '</style><div class="page-grid"><div class="form-host bp:span-2"><form class="stack">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<div><label>Email *</label><input type="email" required></div>'
		. '<div><label>Project Type</label><select><option value="">Select a project type</option><option>Kitchen Renovation</option></select></div>'
		. '<div><label>Project Details *</label><textarea rows="5" required></textarea></div>'
		. '<button type="submit" class="bg-foreground px-8 py-4 font-semibold uppercase tracking-wide">Submit</button>'
		. '</form></div><aside>contact</aside></div>';
	$host_span_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $host_span_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	if ( is_array( $host_span_source['binding'] ?? null ) && is_string( $host_span_source['binding']['search_block_markup'] ?? null ) ) {
		$host_span_source['bindings'] = array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'source_path'         => 'contact.html',
				'search_block_markup' => $host_span_source['binding']['search_block_markup'],
				'occurrence'          => 1,
				'role'                => 'form',
			),
		);
	}
	$host_span_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $host_span_source ) ) );
	$host_span_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $host_span_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$host_span_markup    = (string) ( $host_span_row['block_markup'] ?? '' );
	$host_span_css_out   = $expand_selector_lists( (string) ( $host_span_row['provider_layout_overlay_css']['css'] ?? '' ) );
	$assert( empty( $host_span_validated['errors'] ), 'host-wrapper-span-manifest-validates', wp_json_encode( $host_span_validated ) );
	$assert(
		'mapped' === ( $host_span_row['status'] ?? '' )
			&& empty( $host_span_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 2 === substr_count( $host_span_markup, '"width":50' )
			// The replaced host keeps its own element around the provider form, so
			// its column span stays on the page-grid item instead of the form.
			&& 1 === preg_match( '/^<!-- wp:custom\/layout-shell \{"wrappers":\[\{"tagName":"div","attributes":\{"class":"wp-block-group form-host bp:span-2 blocks-engine-css-owned-layout"\}\}\]\} --><div class="wp-block-group form-host bp:span-2 blocks-engine-css-owned-layout"><!-- wp:jetpack\/contact-form \{"className":"stack ssi-form-[a-f0-9]{12}"\} -->/', $host_span_markup )
			&& str_ends_with( $host_span_markup, '<!-- /wp:jetpack/contact-form --></div><!-- /wp:custom/layout-shell -->' )
			&& 1 === count( array_filter( parse_blocks( $host_span_markup ), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) )
			&& $host_span_markup === serialize_blocks( parse_blocks( $host_span_markup ) )
			&& in_array( 'provider_host_wrapper_restoration', array_column( $host_span_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& str_contains( $host_span_css_out, 'padding-top:1rem' )
			&& str_contains( $host_span_css_out, 'padding-right:2rem' )
			&& str_contains( $host_span_css_out, 'padding-bottom:1rem' )
			&& str_contains( $host_span_css_out, 'padding-left:2rem' )
			&& str_contains( $host_span_css_out, 'font-weight:600' )
			&& str_contains( $host_span_css_out, 'letter-spacing:.025em' )
			&& str_contains( $host_span_css_out, 'text-transform:uppercase' )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.75rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.75rem\);margin-block-start:0!important\}/', $host_span_css_out )
			&& preg_match( '/\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:0;margin:0;border:0\}/', $host_span_css_out )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $host_span_row['provider_layout_overlay_css'] ?? null )
			&& ! str_contains( $host_span_markup, 'wp:group' ),
		'provider-container-inherits-replaced-host-wrapper-column-span-and-authored-submit-box',
		wp_json_encode( array( 'row' => $host_span_row, 'markup' => $host_span_markup, 'css' => $host_span_css_out, 'binding' => $host_span_source['binding'] ?? null ) )
	);
	$host_span_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form ssi-form-123456789abc form-host">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'stack form-host ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $host_span_runtime, 'class="jetpack-contact-form-container stack form-host ssi-form-123456789abc"' )
			&& str_contains( $host_span_runtime, 'class="wp-block-jetpack-contact-form ssi-form-123456789abc"' ),
		'provider-runtime-moves-the-form-box-layout-role-onto-the-jetpack-page-grid-item',
		$host_span_runtime
	);
	$field_list_hoist = Static_Site_Importer_Provider_Form_Runtime_V1::project_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form panel grid gap-6 sm:grid-cols-2 ssi-form-123456789abc">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'panel grid gap-6 sm:grid-cols-2 ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $field_list_hoist, 'class="jetpack-contact-form-container panel ssi-form-123456789abc"' )
			&& str_contains( $field_list_hoist, 'class="wp-block-jetpack-contact-form grid gap-6 sm:grid-cols-2 ssi-form-123456789abc"' ),
		'provider-runtime-keeps-field-list-grid-classes-off-the-page-item-and-the-hoisted-box-class-off-the-field-list',
		$field_list_hoist
	);
	$field_list_wrap = Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form panel grid gap-6 sm:grid-cols-2 ssi-source-field-list ssi-form-123456789abc"><div class="grunion-field-text-wrap">fields</div><div class="wp-block-button form-button-submit is-submit"><button type="submit">Send</button></div></div></form></div>',
		array( 'attrs' => array( 'className' => 'panel grid gap-6 sm:grid-cols-2 ssi-source-field-list ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $field_list_wrap, 'class="wp-block-jetpack-contact-form panel ssi-form-123456789abc"' )
			&& str_contains( $field_list_wrap, '<div class="grid gap-6 sm:grid-cols-2 ssi-source-field-list"><div class="grunion-field-text-wrap">fields</div></div>' )
			&& str_contains( $field_list_wrap, 'form-button-submit is-submit' )
			&& ! str_contains( $field_list_wrap, 'wp-block-jetpack-contact-form panel grid gap-6' ),
		'provider-runtime-keeps-a-sibling-submit-outside-the-gapped-field-list',
		$field_list_wrap
	);

	$card_chrome_hoist = Static_Site_Importer_Provider_Form_Runtime_V1::project_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form space-y-5 rounded-lg border bg-card p-7 ssi-form-123456789abc">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'space-y-5 rounded-lg border bg-card p-7 ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $card_chrome_hoist, 'class="jetpack-contact-form-container rounded-lg border bg-card p-7 ssi-form-123456789abc"' )
			&& str_contains( $card_chrome_hoist, 'wp-block-jetpack-contact-form space-y-5 ssi-form-123456789abc' )
			&& ! preg_match( '/jetpack-contact-form-container[^"]*\bspace-y-5\b/', $card_chrome_hoist )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\bp-7\b/', $card_chrome_hoist )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\brounded-lg\b/', $card_chrome_hoist )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\bbg-card\b/', $card_chrome_hoist ),
		'provider-runtime-keeps-card-chrome-and-rhythm-utilities-off-the-page-item',
		$card_chrome_hoist
	);
	// The rhythm utility must stay with the submit inside the field list, though:
	// the source form element carried it, so its submit owns the authored top gap
	// as a child of the same rhythm. A fields-only wrapper split would drop it.
	$rhythm_wrap = Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form space-y-5 ssi-form-123456789abc"><div class="grunion-field-text-wrap">fields</div><div class="wp-block-button form-button-submit is-submit"><button type="submit">Send</button></div></div></form></div>',
		array( 'attrs' => array( 'className' => 'space-y-5 ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $rhythm_wrap, 'wp-block-jetpack-contact-form space-y-5 ssi-form-123456789abc' )
			&& str_contains( $rhythm_wrap, 'form-button-submit is-submit' )
			&& ! str_contains( $rhythm_wrap, '<div class="space-y-5">' ),
		'provider-runtime-keeps-the-source-rhythm-utility-around-the-fields-and-the-submit',
		$rhythm_wrap
	);
	// A source form that is itself the field grid (`<form class="grid sm:grid-cols-2">`
	// with its submit as a direct item) carries no source field-list marker: its
	// submit is an item of that grid, so it stays inside the list and keeps its
	// column span rather than falling out below the grid at full width.
	$own_grid_wrap = Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form grid gap-5 sm:grid-cols-2 ssi-form-123456789abc"><div class="grunion-field-text-wrap">fields</div><div class="wp-block-button form-button-submit is-submit"><button type="submit">Send</button></div></div></form></div>',
		array( 'attrs' => array( 'className' => 'grid gap-5 sm:grid-cols-2 ssi-form-123456789abc' ) )
	);
	$assert(
		1 === preg_match( '#<div class="grid gap-5 sm:grid-cols-2"><div class="grunion-field-text-wrap">fields</div><div class="wp-block-button form-button-submit is-submit">#', $own_grid_wrap ),
		'provider-runtime-keeps-a-source-grid-item-submit-inside-the-field-grid',
		$own_grid_wrap
	);
	// Grid placement a source context element declares (`sm:col-span-2` on the
	// form heading) is carried with its resolved presentation, conditions intact.
	$span_boxes = Static_Site_Importer_Form_Field_Markup::context_source_boxes(
		array(
			'form'                 => array(
				'context_before' => array(
					array(
						'type'            => 'heading',
						'level'           => 3,
						'text'            => 'Request an appointment',
						'source_selector' => 'form > h3:nth-of-type(1)',
					),
				),
			),
			'source_context_graph' => array(
				'nodes'    => array(
					array(
						'id'           => 'context-0',
						'kind'         => 'context',
						'parent'       => 'form',
						'layout'       => array(),
						'presentation' => array( 'styles' => array( 'font_size' => '1.5rem' ) ),
						'source'       => array( 'selector' => 'form > h3:nth-of-type(1)', 'tag' => 'h3', 'classes' => array( 'sm:col-span-2' ) ),
					),
				),
				'variants' => array(
					array(
						'node'         => 'context-0',
						'condition'    => array( 'type' => 'media', 'query' => '(min-width:40rem)' ),
						'layout_patch' => array( 'column' => 'span 2/span 2' ),
					),
				),
			),
		)
	);
	$span_fallback = $span_boxes['fallbacks'][0] ?? array();
	$assert(
		'1.5rem' === ( $span_fallback['styles']['font_size'] ?? null )
			&& 'span 2/span 2' === ( $span_fallback['variants'][0]['styles']['grid_column'] ?? null )
			&& '(min-width:40rem)' === ( $span_fallback['variants'][0]['condition']['query'] ?? null ),
		'context-heading-keeps-its-source-grid-column-span',
		wp_json_encode( $span_boxes )
	);
	// The container presentation paints the source form's own box once; the
	// layout graph's copy of those box facts on the form node must not paint it
	// a second time on the provider form inside that box.
	$deduped_graph = Static_Site_Importer_Form_Box_Transposition::without_container_box_facts(
		array(
			'nodes'    => array(
				array(
					'id'     => 'form',
					'layout' => array( 'display' => 'grid', 'padding' => '1.5rem' ),
				),
			),
			'variants' => array(
				array(
					'node'         => 'form',
					'condition'    => array( 'type' => 'media', 'query' => '(min-width:48rem)' ),
					'layout_patch' => array( 'padding' => '2rem' ),
				),
				array(
					'node'         => 'form',
					'condition'    => array( 'type' => 'media', 'query' => '(min-width:40rem)' ),
					'layout_patch' => array( 'grid_template_columns' => 'repeat(2,minmax(0,1fr))' ),
				),
			),
		),
		array(
			'schema'   => 'generic/form-container-presentation/v1',
			'styles'   => array( 'padding' => '1.5rem' ),
			'variants' => array( array( 'styles' => array( 'padding' => '2rem' ) ) ),
		)
	);
	$assert(
		array( 'display' => 'grid' ) === $deduped_graph['nodes'][0]['layout']
			&& 1 === count( $deduped_graph['variants'] )
			&& isset( $deduped_graph['variants'][0]['layout_patch']['grid_template_columns'] ),
		'container-presentation-owns-the-source-form-box-once',
		wp_json_encode( $deduped_graph )
	);
	// A container `margin` shorthand owns the form node's margin longhands, the
	// way the layout graph records the same source declaration.
	$shorthand_graph = Static_Site_Importer_Form_Box_Transposition::without_container_box_facts(
		array(
			'nodes' => array(
				array(
					'id'     => 'form',
					'layout' => array(
						'display'      => 'flex',
						'gap'          => '10px',
						'margin_top'   => '18px',
						'margin_right' => 'auto',
						'margin_left'  => 'auto',
					),
				),
			),
		),
		array(
			'schema' => 'generic/form-container-presentation/v1',
			'styles' => array(
				'display'   => 'flex',
				'margin'    => '18px auto 0',
				'max_width' => '440px',
			),
		)
	);
	$assert(
		array(
			'display' => 'flex',
			'gap'     => '10px',
		) === $shorthand_graph['nodes'][0]['layout'],
		'container-margin-shorthand-owns-the-form-margin-longhands',
		wp_json_encode( $shorthand_graph )
	);
	// In-form heading + a nested `grid sm:grid-cols-2` name/phone row. The heading
	// is copy inside the form (producer `context_before`), so it is an inner block
	// of jetpack/contact-form rather than a page-grid sibling. The row maps through
	// provider_equal_width_fields onto Jetpack `width: 50`; its grid classes must
	// not be copied onto the form container.
	$in_form_css  = '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}.rounded-lg{border-radius:.5rem}.border{border-width:1px}.p-7{padding:1.75rem}'
		. '.grid{display:grid}.gap-5{gap:1.25rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$in_form_html = '<style>' . $in_form_css . '</style><div class="page-grid"><section class="intro"><h1>Contact</h1></section>'
		. '<form class="space-y-5 rounded-lg border bg-card p-7">'
		. '<h2 class="font-serif text-2xl text-card-foreground">Send a message</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Your name</span><input required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '</div>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Subject</span><input required></label>'
		. '<label class="block"><span>Message</span><textarea rows="5" required></textarea></label>'
		. '<button type="submit">Send message</button>'
		. '</form></div>';
	$in_form_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $in_form_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	if ( is_array( $in_form_source['binding'] ?? null ) && is_string( $in_form_source['binding']['search_block_markup'] ?? null ) ) {
		$in_form_source['bindings'] = array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'source_path'         => 'contact.html',
				'search_block_markup' => $in_form_source['binding']['search_block_markup'],
				'occurrence'          => 1,
				'role'                => 'form',
			),
		);
	}
	$in_form_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $in_form_source ) ) );
	$in_form_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $in_form_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$in_form_markup    = (string) ( $in_form_row['block_markup'] ?? '' );
	$in_form_parsed    = array_values( array_filter( parse_blocks( $in_form_markup ), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$in_form_contact   = $in_form_parsed[0] ?? array();
	$in_form_inners    = array_values( array_filter( $in_form_contact['innerBlocks'] ?? array(), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$in_form_attrs     = is_array( $in_form_contact['attrs'] ?? null ) ? $in_form_contact['attrs'] : array();
	$in_form_class     = (string) ( $in_form_attrs['className'] ?? '' );
	$in_form_ops       = array_column( $in_form_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $in_form_validated['errors'] ) && 'mapped' === ( $in_form_row['status'] ?? '' ), 'in-form-heading-and-field-row-manifest-validates', wp_json_encode( $in_form_validated ) );
	$assert(
		1 === count( $in_form_parsed )
			&& 'jetpack/contact-form' === ( $in_form_contact['blockName'] ?? '' )
			&& 'core/heading' === ( $in_form_inners[0]['blockName'] ?? '' )
			&& 'Send a message' === trim( wp_strip_all_tags( (string) ( $in_form_inners[0]['innerHTML'] ?? '' ) ) )
			&& ( '' === (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' )
				|| ( (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' ) === ( $in_form_inners[0]['attrs']['className'] ?? '' )
					&& str_contains( (string) ( $in_form_inners[0]['innerHTML'] ?? '' ), 'class="wp-block-heading ' . (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' ) . '"' ) ) )
			&& ! preg_match( '/<!-- wp:heading[\s\S]*<!-- wp:jetpack\/contact-form /', $in_form_markup )
			&& 2 === substr_count( $in_form_markup, '"width":50' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50/', $in_form_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{[^}]*"width":50/', $in_form_markup )
			&& in_array( 'provider_equal_width_fields', $in_form_ops, true )
			&& ! preg_match( '/(?:^|\s)grid(?:\s|$)/', $in_form_class )
			&& ! str_contains( $in_form_class, 'grid-cols-2' )
			&& str_contains( $in_form_class, 'space-y-5' )
			&& $in_form_markup === serialize_blocks( parse_blocks( $in_form_markup ) ),
		'in-form-heading-stays-inside-contact-form-and-name-phone-use-jetpack-width-not-host-grid',
		wp_json_encode(
			array(
				'ops'     => $in_form_ops,
				'class'   => $in_form_class,
				'inners'  => array_column( $in_form_inners, 'blockName' ),
				'markup'  => $in_form_markup,
				'context' => $in_form_source['form']['context_before'] ?? null,
			)
		)
	);
	// #2159: flattening an equal-width row resets every field's and the submit's
	// source stacking margin, so the provider form must carry that stacking
	// margin as its row gap. A gap borrowed from the row (or none at all) left
	// the field groups collapsed against each other. The column gap stays the
	// one the field tracks were sized against. The gap lands in one rule whose
	// two selectors address the provider's flex container in both nestings: the
	// form when the scoped block wraps it, and the scoped block when Jetpack
	// 16.3's form wraps it (the form then steps aside).
	$rhythm_plain_html = static fn( string $grid_css ): string => '<style>.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}' . $grid_css . '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}label{display:block}input,textarea{display:block;width:100%}</style>'
		. '<form class="space-y-5"><div class="grid sm:grid-cols-2"><div><label for="n">Name</label><input id="n"></div><div><label for="e">Email</label><input id="e" type="email"></div></div>'
		. '<div><label for="m">Message</label><textarea id="m"></textarea></div><button type="submit">Send</button></form>';
	// Tailwind's real stacking rule: a reversible calc margin, a utility-classed
	// `grid grid-cols-1 sm:grid-cols-2 gap-5` row, and `block mb-1.5` labels
	// without `for`.
	$rhythm_tailwind_html = '<style>.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem * var(--tw-space-y-reverse))}'
		. '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-5{gap:1.25rem}.block{display:block}.mb-1\\.5{margin-bottom:.375rem}.w-full{width:100%}'
		. '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}</style>'
		. '<form class="space-y-5"><div class="grid grid-cols-1 sm:grid-cols-2 gap-5"><div><label class="block mb-1.5">Name</label><input class="w-full" name="name"></div><div><label class="block mb-1.5">Email</label><input class="w-full" name="email" type="email"></div></div>'
		. '<div><label class="block mb-1.5">Message</label><textarea class="w-full" name="message"></textarea></div><button type="submit">Send</button></form>';
	foreach ( array(
		'issue-fixture'          => array( $rhythm_plain_html( '.grid{display:grid;gap:1.25rem}' ), '1.25rem', '0\.625rem' ),
		'row-gap-differs'        => array( $rhythm_plain_html( '.grid{display:grid;gap:1rem}' ), '1rem', '0\.5rem' ),
		'row-without-gap'        => array( $rhythm_plain_html( '.grid{display:grid}' ), '1.5rem', '0\.75rem' ),
		'tailwind-calc-stacking' => array( $rhythm_tailwind_html, '1.25rem', '0\.625rem' ),
	) as $rhythm_case => list( $rhythm_html, $rhythm_column_gap, $rhythm_half_gap ) ) {
		$rhythm_source   = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $rhythm_html ) ) )->toArray() )['fallbacks'][0] ?? array();
		$rhythm_row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $rhythm_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
		$rhythm_raw_css  = (string) ( $rhythm_row['provider_layout_overlay_css']['css'] ?? '' );
		$rhythm_css      = $expand_selector_lists( $rhythm_raw_css );
		$rhythm_gap_body = '\{(?:gap:[^;}]+;)?row-gap:1\.25rem;column-gap:' . preg_quote( $rhythm_column_gap, '/' ) . '\}';
		$assert(
			'mapped' === ( $rhythm_row['status'] ?? '' )
				&& in_array( 'provider_equal_width_fields', array_column( $rhythm_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
				&& 1 === preg_match( '/^(\.ssi-form-[a-f0-9]{12}) > form\.jetpack-contact-form__form:not\(:has\(\.wp-block-jetpack-contact-form\)\), \1:not\(:has\(> form\.jetpack-contact-form__form\)\)' . $rhythm_gap_body . '$/m', $rhythm_raw_css )
				&& 1 === preg_match( '/^\.ssi-form-[a-f0-9]{12}:not\(:has\(> form\.jetpack-contact-form__form\)\)' . $rhythm_gap_body . '$/m', $rhythm_css )
				&& 2 === preg_match_all( '/\.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - ' . $rhythm_half_gap . '\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - ' . $rhythm_half_gap . '\);margin-block-start:0!important\}/', $rhythm_css )
				&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $rhythm_row['provider_layout_overlay_css'] ?? null ),
			'flattened-row-stacking-reset-carries-source-rhythm-as-form-row-gap-' . $rhythm_case,
			$rhythm_css
		);
	}
	// A `grid gap-2` field wrapper's gap reaches the control as a transposed
	// `margin-block-start` whose provenance is the wrapper's own class rule. The
	// runtime rebuilds that wrapper with its source classes, which already space
	// the control, so the overlay must not repeat the gap on the control target.
	$gap_row_css    = '.grid{display:grid}.gap-2{gap:.5rem}.h-11{height:2.75rem}.w-full{width:100%}';
	$gap_row_html   = '<style>' . $gap_row_css . '</style><form>'
		. '<div class="grid gap-2"><label for="n" class="text-sm">Name</label><input id="n" class="h-11 w-full" required></div>'
		. '<div class="grid gap-2"><label for="s" class="text-sm">Service</label><select id="s" class="h-11 w-full"><option>Oil</option><option>Brakes</option></select></div>'
		. '<button type="submit">Send</button></form>';
	$gap_row_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $gap_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$gap_row_facts  = array();
	foreach ( $gap_row_source['layout_graph']['nodes'] ?? array() as $gap_row_node ) {
		if ( is_array( $gap_row_node ) && str_starts_with( (string) ( $gap_row_node['id'] ?? '' ), 'control-' ) && isset( $gap_row_node['layout']['margin_block_start'] ) ) {
			$gap_row_facts[] = $gap_row_node['id'];
		}
	}
	$gap_row_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $gap_row_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$gap_row_css_out = (string) ( $gap_row_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		array() !== $gap_row_facts
			&& 'mapped' === ( $gap_row_row['status'] ?? '' )
			&& str_contains( (string) ( $gap_row_row['block_markup'] ?? '' ), 'ssi-source-wrapper-' )
			&& ! str_contains( $gap_row_css_out, 'margin-block-start' ),
		'field-wrapper-gap-is-painted-once-by-the-restored-source-wrapper',
		wp_json_encode( array( 'facts' => $gap_row_facts, 'status' => $gap_row_row['status'] ?? null, 'css' => $gap_row_css_out ) )
	);
	$bare_row_html = '<form class="space-y-5"><h2>Send a message</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Your name</span><input required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '</div>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<button type="submit">Send</button></form>';
	$bare_row_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $bare_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$bare_row_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $bare_row_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$bare_row_markup = (string) ( $bare_row_row['block_markup'] ?? '' );
	$bare_row_class  = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $bare_row_markup, $bare_row_attrs ) ) {
		$bare_row_decoded = json_decode( $bare_row_attrs[1], true );
		$bare_row_class   = is_array( $bare_row_decoded ) ? (string) ( $bare_row_decoded['className'] ?? '' ) : '';
	}
	$assert(
		'mapped' === ( $bare_row_row['status'] ?? '' )
			&& 2 === substr_count( $bare_row_markup, '"width":50' )
			&& in_array( 'provider_equal_width_fields', array_column( $bare_row_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& ! preg_match( '/(?:^|\s)grid(?:\s|$)/', $bare_row_class )
			&& ! str_contains( $bare_row_class, 'grid-cols-2' ),
		'class-token-grid-row-without-layout-node-still-maps-to-jetpack-field-width',
		wp_json_encode( array( 'row' => $bare_row_row, 'class' => $bare_row_class, 'markup' => $bare_row_markup ) )
	);
	// One 2-column grid holding eight fields (four visual rows) plus two full-width
	// fields outside it. Column count comes from the resolved track list, not from
	// sibling count, so every grid child materializes at Jetpack `width: 50`.
