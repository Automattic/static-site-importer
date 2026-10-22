<?php
/**
 * Form materializer smoke suite: provider runtime projection, layout overlay and computed layout.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$plain_tel = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-telephone-wrap"><input type="tel" class="telephone wp-block-jetpack-input ssi-node-555555555555-destination-shell ssi-node-666666666666-destination-primary ssi-node-777777777777-destination-carrier ssi-node-888888888888-destination-prefix grunion-field"></div>' );
	$assert( str_contains( $plain_tel, 'ssi-node-666666666666-destination-primary' ) && ! str_contains( $plain_tel, '-destination-shell' ) && ! str_contains( $plain_tel, '-destination-carrier' ) && ! str_contains( $plain_tel, '-destination-prefix' ), 'plain-tel-keeps-only-the-primary-hook-so-the-prefix-height-reset-cannot-shrink-the-input', $plain_tel );
	$unmarked_provider_shell = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' );
	$shared_projection = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-shell-0--shared-border-wrap ssi-source-wrapper-prefix-1--prefix-box-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button>Country</button></div><input type="tel"></div></div>' );
	$shared_document = new DOMDocument();
	$shared_document->loadHTML( $shared_projection );
	$shared_xpath = new DOMXPath( $shared_document );
	$assert( 1 === $shared_xpath->query( '//div[@class="shared-border"]/div[@class="jetpack-field__input-phone-wrapper"]/input[@type="tel"]' )->length && 1 === $shared_xpath->query( '//div[@class="shared-border"]//div[@class="prefix-box"]//button' )->length, 'shared-border-wraps-both-prefix-and-value-in-the-rendered-provider-tree' );
	$assert( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' === $unmarked_provider_shell, 'wrapper-projection-does-not-rewrite-unmarked-provider-shells', $unmarked_provider_shell );
	$described_row_html = '<div class="grunion-field-textarea-wrap ssi-source-wrapper-2--flex-wrap ssi-source-wrapper-2--flex-col-wrap ssi-source-wrapper-2--gap-2-wrap"><label>Why this nomination stands up</label><textarea rows="6"></textarea><div class="contact-form__input-error"></div></div>';
	$described_row      = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $described_row_html );
	$assert(
		str_contains( $described_row, '<div class="ssi-field-row flex flex-col gap-2">' )
			&& str_contains( $described_row, '<label>Why this nomination stands up</label>' )
			&& 1 === preg_match( '/<div class="ssi-field-row flex flex-col gap-2">.*<label>Why this nomination stands up<\/label>.*<textarea\b/s', $described_row )
			&& ! str_contains( $described_row, 'grunion-field-textarea-wrap flex' ),
		'outermost-source-field-row-keeps-the-label-inside-the-restored-wrapper',
		$described_row
	);
	$described_source_html = '<style>.flex{display:flex}.flex-col{flex-direction:column}.gap-2{gap:.5rem}label{display:block}textarea{box-sizing:border-box;padding:12px;font-size:14px;line-height:20px;border:1px solid}</style><form>'
		. '<div class="flex flex-col gap-2"><label>Why this nomination stands up</label><textarea rows="6" required></textarea><p class="text-xs">Evidence, outcomes and dates carry more weight than adjectives.</p></div>'
		. '<button type="submit">Send</button></form>';
	$described_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $described_source_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$described_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $described_source ) ) );
	$described_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $described_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$described_markup    = (string) ( $described_seed['block_markup'] ?? '' );
	$described_css       = (string) ( $described_seed['provider_layout_overlay_css']['css'] ?? '' );
	$described_help_text = (string) ( $described_source['controls'][0]['description'] ?? '' );
	$assert(
		empty( $described_validated['errors'] )
			&& 'mapped' === ( $described_seed['status'] ?? '' )
			&& str_contains( $described_markup, 'ssi-textarea-rows-6' )
			&& str_contains( $described_css, 'display:contents' )
			&& str_contains( $described_css, 'contact-form__field-hints' )
			&& ( '' === $described_help_text || 1 === preg_match( '/<!-- wp:jetpack\/field-textarea \{[^\n]*"helpText":"Evidence, outcomes and dates carry more weight than adjectives\."[^\n]*\} -->/', $described_markup ) )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $described_seed['provider_layout_overlay_css'] ?? null ),
		'compiled-field-row-keeps-authored-textarea-rows-and-stops-hint-chrome-from-forming-its-own-box',
		wp_json_encode( array( 'errors' => $described_validated['errors'] ?? array(), 'markup' => $described_markup, 'css' => $described_css, 'description' => $described_help_text ) )
	);
	$wrapping_label_html = '<form><label class="block"><span>Occupation / business</span><input name="occupation"><span class="mt-1 block text-xs">Helps the trade committee connect members.</span></label><button type="submit">Send</button></form>';
	$wrapping_label_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'join.html', 'files' => array( 'join.html' => $wrapping_label_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$wrapping_label_control = $wrapping_label_source['controls'][0] ?? array();
	$wrapping_label_seed    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $wrapping_label_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$wrapping_label_markup  = (string) ( $wrapping_label_seed['block_markup'] ?? '' );
	$assert(
		! class_exists( $artifact_compiler )
			|| ! isset( $wrapping_label_control['description'] )
			|| (
				'Occupation / business' === ( $wrapping_label_control['label'] ?? null )
				&& 'Helps the trade committee connect members.' === ( $wrapping_label_control['description'] ?? null )
				&& 1 === preg_match( '/<!-- wp:jetpack\/label \{"label":"Occupation \\/ business"(?:,"className":"[^"]*")?\} \/-->/', $wrapping_label_markup )
				&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^\n]*"helpText":"Helps the trade committee connect members\."[^\n]*\} -->/', $wrapping_label_markup )
				&& ! str_contains( $wrapping_label_markup, 'businessHelps' )
			),
		'compiled-wrapping-label-keeps-description-off-the-label-and-on-jetpack-help-text',
		wp_json_encode( array( 'control' => $wrapping_label_control, 'markup' => $wrapping_label_markup ) )
	);

	// A source builder commonly hides a checkbox's native control behind a
	// custom-drawn box with `position: absolute` (paired with `opacity: 0`),
	// so its own captured presentation styles include `position` alongside
	// ordinary box/typography facts. `position` is deliberately outside the
	// vocabulary every ordinary control/label destination may represent (see
	// `Static_Site_Importer_Provider_Layout_Overlay::presentation_property_keys()`
	// vs `positioned_control_presentation_property_keys()`), so its absence
	// from the represented set is the documented universal exclusion working
	// as designed, not a per-field fidelity regression. Declining the whole
	// form over an intentionally unrepresentable property leaves an
	// email-plus-consent-plus-submit form a dead, unsubmittable control (see
	// https://github.com/Automattic/static-site-importer/issues/1773).
	$hidden_checkbox_html = '<style>.subscribe-checkbox{position:absolute;opacity:0;width:20px;height:20px;margin:0;padding:0;box-sizing:border-box;border:1px solid #000}</style><form>'
		. '<label>Email<input type="email" name="email"></label>'
		. '<label class="consent-row"><input type="checkbox" name="updates" class="subscribe-checkbox">Yes, subscribe me to your newsletter.</label>'
		. '<button type="submit">Sign Up</button></form>';
	$hidden_checkbox_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'subscribe.html', 'files' => array( 'subscribe.html' => $hidden_checkbox_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$hidden_checkbox_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_checkbox_source ) ) );
	$hidden_checkbox_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $hidden_checkbox_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		! class_exists( $artifact_compiler )
			|| (
				empty( $hidden_checkbox_validated['errors'] )
				&& 'mapped' === ( $hidden_checkbox_seed['status'] ?? '' )
				&& true === ( $hidden_checkbox_seed['runtime_mapped'] ?? false )
				&& empty( $hidden_checkbox_seed['form_receipt_unaccepted_losses'] ?? array() )
				&& in_array( 'jetpack/field-checkbox', $hidden_checkbox_seed['field_blocks'] ?? array(), true )
				&& in_array( 'jetpack/field-email', $hidden_checkbox_seed['field_blocks'] ?? array(), true )
			),
		'visually-hidden-checkbox-position-styling-does-not-decline-the-whole-form',
		wp_json_encode( array( 'errors' => $hidden_checkbox_validated['errors'] ?? array(), 'row' => $hidden_checkbox_seed ) )
	);

	// A label moved off-screen (`position:absolute; left:-9999px`) names its
	// control for screen readers and is hidden from view. Its offset without
	// the position would shift a visible caption, so the provider label
	// carries both.
	$offscreen_label_html      = '<form><label for="news-email" style="position:absolute;left:-9999px;">Email address</label>'
		. '<input type="email" id="news-email" name="email" placeholder="you@example.com"><button type="submit">Subscribe</button></form>';
	$offscreen_label_artifact  = array(
		'entrypoint' => 'news.html',
		'files'      => array( 'news.html' => $offscreen_label_html ),
	);
	$offscreen_label_source    = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( $offscreen_label_artifact )->toArray() )['fallbacks'][0] ?? array() : array();
	$offscreen_label_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $offscreen_label_source ) ) );
	$offscreen_label_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $offscreen_label_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$offscreen_label_css       = (string) ( $offscreen_label_seed['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		! class_exists( $artifact_compiler )
			|| (
				'mapped' === ( $offscreen_label_seed['status'] ?? '' )
				&& 1 === preg_match( '/\{[^}]*left:-9999px[^}]*\}/', $offscreen_label_css, $offscreen_label_rule )
				&& str_contains( $offscreen_label_rule[0], 'position:absolute' )
			),
		'offscreen-label-carries-its-position-with-its-offset',
		$offscreen_label_css
	);
	// Jetpack renders a text control inside its own field wrapper, and that
	// wrapper is the item of the field row. A source `input { flex: 1 }` beside
	// its button sizes the wrapper, not the input, so the row stays one line.
	$flex_field_artifact = array(
		'entrypoint' => 'news.html',
		'files'      => array( 'news.html' => '<form style="display:flex;gap:10px;"><input type="email" name="email" aria-label="Email" style="flex:1;"><button type="submit">Subscribe</button></form>' ),
	);
	$flex_field_source   = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( $flex_field_artifact )->toArray() )['fallbacks'][0] ?? array() : array();
	$flex_field_seed     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $flex_field_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$flex_field_css      = (string) ( $flex_field_seed['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		! class_exists( $artifact_compiler )
			|| (
				'mapped' === ( $flex_field_seed['status'] ?? '' )
				&& 1 === preg_match( '/ \.(ssi-node-[a-f0-9]{12})-wrap\{flex:1\}/', $flex_field_css, $flex_field_wrap )
				&& ! preg_match( '/ \.' . $flex_field_wrap[1] . '\{[^}]*flex:/', $flex_field_css )
			),
		'field-control-flex-item-facts-size-its-field-wrapper',
		$flex_field_css
	);
	// A source flex form without `align-items` stretches its items. Jetpack's
	// contact-form block defaults its flex layout to `verticalAlignment: top`
	// (`align-items:flex-start`), so the provider form restates the initial
	// stretch; an authored alignment stays the source's own.
	$aligned_form_artifact = array(
		'entrypoint' => 'news.html',
		'files'      => array( 'news.html' => '<form style="display:flex;gap:10px;align-items:center;"><input type="email" name="email" aria-label="Email" style="flex:1;"><button type="submit">Subscribe</button></form>' ),
	);
	$aligned_form_source   = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( $aligned_form_artifact )->toArray() )['fallbacks'][0] ?? array() : array();
	$aligned_form_seed     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $aligned_form_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$aligned_form_css      = (string) ( $aligned_form_seed['provider_layout_overlay_css']['css'] ?? '' );
	$form_layout_rule      = static function ( string $css ): string {
		return 1 === preg_match( '/:not\(:has\(> form\.jetpack-contact-form__form\)\)\{([^}]*)\}/', $css, $rule ) ? $rule[1] : '';
	};
	$assert(
		! class_exists( $artifact_compiler )
			|| (
				'mapped' === ( $aligned_form_seed['status'] ?? '' )
				&& str_contains( $form_layout_rule( $flex_field_css ), 'align-items:stretch' )
				&& str_contains( $form_layout_rule( $aligned_form_css ), 'align-items:center' )
				&& ! str_contains( $form_layout_rule( $aligned_form_css ), 'align-items:stretch' )
			),
		'provider-form-restates-a-source-flex-forms-initial-stretch-alignment',
		$flex_field_css . "\n" . $aligned_form_css
	);

	$declared_box_form = static function ( array $styles, array $layout, string $class ) use ( $presentation_role, $v2_layout_graph, $layout_node ): array {
		$properties = array();
		foreach ( array_keys( $styles ) as $key ) {
			$properties[] = str_replace( '_', '-', (string) $key );
		}
		$control_node                   = $layout_node( 'control-0', $layout, 'input' );
		$control_node['parent']         = 'form';
		$control_node['order']          = 1;
		$control_node['source']['classes'] = array( $class );
		$condition                      = array( 'kind' => 'media', 'query' => '(max-width: 48rem)' );
		$graph                          = $v2_layout_graph( array( $layout_node( 'form', array( 'display' => 'flex' ), 'form' ), $control_node ) );
		$graph['variants'][]            = array(
			'node'         => 'control-0',
			'condition'    => $condition,
			'layout_patch' => array( 'width' => $layout['width'], 'height' => $layout['height'] ),
			'precedence'   => array(
				'width'  => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ),
				'height' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ),
			),
			'provenance'   => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.box', 'condition' => $condition, 'properties' => array( 'width', 'height' ) ) ),
		);
		return array(
			'selector'           => 'form.consent',
			'controls'           => array(
				array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates', 'class' => $class ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
			'presentation_graph' => array(
				'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false,
				'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
				'controls' => array( array(
					'index'   => 0,
					'control' => $presentation_role( $styles, $properties, '.box' ),
					'label'   => $presentation_role( array( 'font_size' => '14px', 'color' => '#fff' ), array( 'font-size', 'color' ), 'label' ),
				) ),
			),
			'layout_graph'       => $graph,
		);
	};
	$seed_declared_box = static function ( array $form ) {
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $form ) ) );
		return array(
			'errors' => $validated['errors'] ?? array(),
			'row'    => Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array(),
		);
	};
	$hidden_native_box = $seed_declared_box( $declared_box_form(
		array( 'position' => 'absolute', 'width' => '1px', 'height' => '1px', 'margin' => '-1px', 'border' => '0', 'padding' => '0' ),
		array( 'height' => '1px', 'width' => '1px' ),
		'visually-hidden'
	) );
	$hidden_native_row   = $hidden_native_box['row'];
	$hidden_native_css   = (string) ( $hidden_native_row['provider_layout_overlay_css']['css'] ?? '' );
	$hidden_native_markup = (string) ( $hidden_native_row['block_markup'] ?? '' );
	$assert(
		empty( $hidden_native_box['errors'] )
			&& 'mapped' === ( $hidden_native_row['status'] ?? '' )
			&& true === ( $hidden_native_row['runtime_mapped'] ?? false )
			&& empty( $hidden_native_row['form_receipt_unaccepted_losses'] ?? array() )
			&& in_array( 'provider_visually_hidden_native_control', array_column( $hidden_native_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'visually-hidden-native-checkbox-maps-after-dropping-source-hiding-presentation',
		wp_json_encode( array( 'errors' => $hidden_native_box['errors'], 'row' => $hidden_native_row ) )
	);
	$assert(
		! str_contains( $hidden_native_css, 'height:1px' )
			&& ! str_contains( $hidden_native_css, 'width:1px' )
			&& str_contains( $hidden_native_css, 'font-size:14px' )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $hidden_native_row['provider_layout_overlay_css'] ?? null ),
		'visually-hidden-native-checkbox-overlay-drops-the-1px-box-and-keeps-label-typography',
		$hidden_native_css
	);
	$assert( ! str_contains( $hidden_native_markup, 'visually-hidden' ), 'visually-hidden-native-checkbox-markup-does-not-carry-the-hiding-class', $hidden_native_markup );
	foreach ( array( 'responsive-only', 'base-plus-responsive' ) as $case ) {
		$responsive_hidden = $declared_box_form(
			'responsive-only' === $case ? array() : array( 'width' => '1px', 'height' => '1px' ),
			array( 'height' => '1px', 'width' => '1px' ),
			'responsive-hidden-control'
		);
		$responsive_hidden['controls'][0]['required'] = true;
		$patch = 'responsive-only' === $case
			? array( 'position' => 'absolute', 'width' => '1px', 'height' => '1px', 'margin' => '-1px' )
			: array( 'position' => 'absolute', 'margin' => '-1px' );
		$responsive_hidden['presentation_graph']['variants'][] = array(
			'index' => 0, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:48rem)' ),
			'style_patch' => $patch, 'precedence' => array(),
			'provenance' => array(),
		);
		$responsive_hidden['presentation_graph']['variants'][] = array(
			'index' => 0, 'role' => 'label', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:48rem)' ),
			'style_patch' => array( 'font_size' => '18px' ), 'precedence' => array(), 'provenance' => array(),
		);
		$responsive_hidden_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $responsive_hidden ) ) )['forms'][0] ?? array();
		$responsive_hidden_css = (string) ( $responsive_hidden_row['provider_layout_overlay_css']['css'] ?? '' );
		$assert(
			'mapped' === ( $responsive_hidden_row['status'] ?? '' )
				&& in_array( 'provider_visually_hidden_native_control', array_column( $responsive_hidden_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
				&& ! str_contains( (string) ( $responsive_hidden_row['block_markup'] ?? '' ), 'responsive-hidden-control' )
				&& ! str_contains( $responsive_hidden_css, 'height:1px' ) && ! str_contains( $responsive_hidden_css, 'width:1px' )
				&& str_contains( $responsive_hidden_css, 'font-size:14px' )
				&& str_contains( $responsive_hidden_css, 'font-size:18px' )
				&& str_contains( (string) ( $responsive_hidden_row['block_markup'] ?? '' ), '"required":true' ),
			$case . '-native-consent-release-keeps-label-and-required-semantics',
			wp_json_encode( $responsive_hidden_row )
		);
	}
	foreach ( array(
		array( 'position' => 'static', 'width' => '16px', 'height' => '16px', 'margin' => '0' ),
		array( 'position' => 'absolute', 'width' => '1px', 'height' => '1px', 'margin' => '0' ),
	) as $patch ) {
		$regular_variant = $declared_box_form( array(), array( 'height' => $patch['height'], 'width' => $patch['width'] ), 'authored-responsive-control' );
		$regular_variant['presentation_graph']['variants'][] = array(
			'index' => 0, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:48rem)' ),
			'style_patch' => $patch, 'precedence' => array(), 'provenance' => array(),
		);
		$regular_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $regular_variant ) ) )['forms'][0] ?? array();
		$assert( str_contains( (string) ( $regular_row['block_markup'] ?? '' ), 'authored-responsive-control' ) && ! in_array( 'provider_visually_hidden_native_control', array_column( $regular_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'ordinary-responsive-control-without-proven-hiding-keeps-source-presentation', wp_json_encode( $regular_row ) );
	}
	$authored_small_box = $seed_declared_box( $declared_box_form(
		array( 'position' => 'static', 'width' => '16px', 'height' => '16px', 'margin' => '0', 'border' => '0', 'padding' => '0' ),
		array( 'height' => '16px', 'width' => '16px' ),
		'authored-box'
	) );
	$authored_small_row = $authored_small_box['row'];
	$authored_small_css = (string) ( $authored_small_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $authored_small_box['errors'] )
			&& 'mapped' === ( $authored_small_row['status'] ?? '' )
			&& str_contains( (string) ( $authored_small_row['block_markup'] ?? '' ), 'authored-box' )
			&& str_contains( $authored_small_css, 'height:16px' )
			&& str_contains( $authored_small_css, 'width:16px' )
			&& ! in_array( 'provider_visually_hidden_native_control', array_column( $authored_small_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'authored-static-small-checkbox-keeps-its-declared-box-and-class',
		wp_json_encode( array( 'errors' => $authored_small_box['errors'], 'css' => $authored_small_css, 'markup' => $authored_small_row['block_markup'] ?? '' ) )
	);
	$absolute_tight_box = $seed_declared_box( $declared_box_form(
		array( 'position' => 'absolute', 'width' => '1px', 'height' => '1px', 'margin' => '0', 'border' => '0', 'padding' => '0' ),
		array( 'height' => '1px', 'width' => '1px' ),
		'tight-control'
	) );
	$absolute_tight_row = $absolute_tight_box['row'];
	$absolute_tight_css = (string) ( $absolute_tight_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $absolute_tight_box['errors'] )
			&& 'mapped' === ( $absolute_tight_row['status'] ?? '' )
			&& str_contains( (string) ( $absolute_tight_row['block_markup'] ?? '' ), 'tight-control' )
			&& str_contains( $absolute_tight_css, 'height:1px' )
			&& str_contains( $absolute_tight_css, 'width:1px' )
			&& ! in_array( 'provider_visually_hidden_native_control', array_column( $absolute_tight_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'absolute-1px-checkbox-without-clip-or-negative-margin-is-unchanged',
		wp_json_encode( array( 'errors' => $absolute_tight_box['errors'], 'css' => $absolute_tight_css, 'markup' => $absolute_tight_row['block_markup'] ?? '' ) )
	);
	$clipped_box = $declared_box_form(
		array( 'position' => 'fixed', 'width' => '1px', 'height' => '1px', 'clip_path' => 'inset(50%)', 'border' => '0', 'padding' => '0' ),
		array( 'height' => '1px', 'width' => '1px' ),
		'clipped-control'
	);
	$clipped_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $clipped_box ) ) )['forms'][0] ?? array();
	$clipped_css = (string) ( $clipped_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		'mapped' === ( $clipped_row['status'] ?? '' )
			&& ! str_contains( $clipped_css, 'height:1px' )
			&& ! str_contains( $clipped_css, 'width:1px' )
			&& ! str_contains( (string) ( $clipped_row['block_markup'] ?? '' ), 'clipped-control' )
			&& in_array( 'provider_visually_hidden_native_control', array_column( $clipped_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'fixed-1px-checkbox-with-collapsing-clip-path-drops-hiding-presentation',
		wp_json_encode( array( 'css' => $clipped_css, 'markup' => $clipped_row['block_markup'] ?? '', 'row' => $clipped_row ) )
	);

	$help_text_atts = Static_Site_Importer_Provider_Form_Runtime_V1::project_help_text_attribute(
		array( 'helptext' => null ),
		array(),
		array( 'helpText' => 'Helps the trade committee connect members.' )
	);
	$assert( 'Helps the trade committee connect members.' === ( $help_text_atts['helptext'] ?? null ), 'block-helpText-reaches-the-shortcode-helptext-attribute', wp_json_encode( $help_text_atts ) );
	$projected_submit = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation(
		'<div class="wp-block-button ssi-source-submit--source-submit"><button class="wp-block-button__link">Send</button></div>',
		array( 'attrs' => array( 'className' => 'wp-block-button ssi-source-submit--source-submit' ) )
	);
	$assert( '<div class="wp-block-button"><button class="wp-block-button__link source-submit">Send</button></div>' === $projected_submit, 'provider-runtime-projects-submit-classes-without-inline-min-height-resets', $projected_submit );
	$styled_projected_submit = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation(
		'<div class="wp-block-button ssi-source-submit--source-submit" style="min-height:72px"><button class="wp-block-button__link" style="color:rgb(1, 2, 3);min-height:56px">Send</button></div>',
		array( 'attrs' => array( 'className' => 'wp-block-button ssi-source-submit--source-submit' ) )
	);
	$assert( str_contains( $styled_projected_submit, 'style="min-height:72px"' ) && str_contains( $styled_projected_submit, 'style="color:rgb(1, 2, 3);min-height:56px"' ), 'submit-runtime-preserves-existing-wrapper-and-control-inline-source-styles', $styled_projected_submit );
	$projected_submit_margin = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation(
		'<div class="wp-block-button ssi-source-submit--mt-9 ssi-source-submit--bg-gold"><button class="wp-block-button__link">Send</button></div>',
		array( 'attrs' => array( 'className' => 'wp-block-button ssi-source-submit--mt-9 ssi-source-submit--bg-gold' ) )
	);
	$assert(
		str_contains( $projected_submit_margin, 'class="wp-block-button mt-9"' )
			&& str_contains( $projected_submit_margin, 'class="wp-block-button__link bg-gold"' )
			&& ! str_contains( $projected_submit_margin, 'wp-block-button__link mt-9' )
			&& ! str_contains( $projected_submit_margin, 'ssi-source-submit--' ),
		'provider-runtime-keeps-authored-submit-margin-on-the-wrapper-instead-of-the-inner-link',
		$projected_submit_margin
	);
	$unproven_class_form = $class_owned_form;
	$unproven_class_form['layout_graph']['nodes'][1]['provenance'] = array();
	$unproven_class_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unproven_class_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $unproven_class_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'class-projection-alone-does-not-claim-layout-equivalence' );
	$structural_selector_form = $class_owned_form;
	$structural_selector_form['layout_graph']['nodes'][1]['provenance'][0]['selector'] = '.row-2 > .field';
	$structural_selector_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $structural_selector_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $structural_selector_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'ancestor-dependent-class-selector-does-not-survive-wrapper-flattening' );
	$root_graph = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'form' ) ) );
	$root_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $root_map );
	// A control container with no provider destination is the same loss on either
	// surface, named for neither (#2281).
	$unplaced_container = array(
		'control_containers' => array(
			array(
				'index'  => 7,
				'styles' => array( 'background_color' => '#fff' ),
			),
		),
	);
	$unplaced_codes     = array();
	foreach ( array( Static_Site_Importer_Provider_Layout_Overlay::SURFACE_FRONT_END, Static_Site_Importer_Provider_Layout_Overlay::SURFACE_EDITOR ) as $unplaced_surface ) {
		$unplaced_codes[ $unplaced_surface ] = array_column( Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $root_map, $unplaced_container, array(), $unplaced_surface )['losses'], 'reason_code' );
	}
	$assert(
		array( array( 'control_container_unsupported' ), array( 'control_container_unsupported' ) ) === array_values( array_map( static fn( array $codes ): array => array_values( array_filter( $codes, static fn( string $code ): bool => str_contains( $code, 'control_container' ) ) ), $unplaced_codes ) ),
		'unplaced-control-container-loss-names-no-surface',
		(string) wp_json_encode( $unplaced_codes )
	);
	$assert( str_contains( $root_overlay['css'], '.ssi-form-123456789abc > form.jetpack-contact-form__form, .ssi-form-123456789abc:not(:has(> form.jetpack-contact-form__form)){display:flex;flex-direction:row;gap:1rem}' ) && str_contains( $root_overlay['css'], '.ssi-form-123456789abc{position:relative;z-index:1;pointer-events:auto}' ) && 'provider_selector_transposition' === ( $root_overlay['operations'][0]['strategy'] ?? '' ) && 'provider_interaction_carrier' === ( $root_overlay['operations'][1]['strategy'] ?? '' ), 'provider-layout-root-targets-native-jetpack-form-with-an-interaction-carrier' );
	$calc_graph   = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'column', 'gap' => 'calc(32 * 1px)' ), 'form' ) ) );
	$calc_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $calc_graph, $root_map );
	$assert( str_contains( $calc_overlay['css'], 'gap:calc(32 * 1px)' ) && empty( $calc_overlay['losses'] ), 'authored-arithmetic-row-gap-reaches-the-provider-form-instead-of-the-runtime-default', wp_json_encode( $calc_overlay ) );
	$unbalanced_calc_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'gap' => 'calc(32 * 1px' ), 'form' ) ) ), $root_map );
	$assert( '' === $unbalanced_calc_overlay['css'] && 'unsafe_layout_value' === ( $unbalanced_calc_overlay['losses'][0]['reason_code'] ?? '' ), 'unbalanced-arithmetic-value-is-refused' );
	$unsafe_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'display' => 'url(https://example.test/x)' ), 'form' ) ) ), $root_map );
	$assert( '' === $unsafe_overlay['css'] && 'unsafe_layout_value' === ( $unsafe_overlay['losses'][0]['reason_code'] ?? '' ), 'provider-layout-overlay-rejects-unsafe-values' );
	$bad_map = $root_map; $bad_map['targets'][0]['selector'] = 'body .anything';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $bad_map, $root_graph )['error'] ), 'provider-layout-overlay-rejects-arbitrary-selectors' );
	$presentation_graph_fixture = array( 'controls' => array( array( 'index' => 0, 'control' => array( 'styles' => array( 'background_color' => '#fff', 'padding' => '8px', 'font_size' => '16px' ) ) ) ) );
	$destination_fixture = array( 'index' => 0, 'destinations' => array( array( 'role' => 'control', 'selector' => '.ssi-form-123456789abc .ssi-node-111111111111', 'properties' => array( 'background_color', 'padding' ), 'aliases' => array( 'background_color' => '--provider-input-background' ) ), array( 'role' => 'control', 'selector' => '.ssi-form-123456789abc .ssi-node-222222222222', 'properties' => array( 'font_size' ), 'resets' => array( 'flex' => '1 1 0', 'min-width' => '0' ) ) ) );
	$jetpack_destination_map = $root_map; $jetpack_destination_map['presentation_targets'] = array( $destination_fixture );
	$synthetic_destination_map = $jetpack_destination_map; $synthetic_destination_map['provider'] = 'synthetic';
	$jetpack_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $jetpack_destination_map, $presentation_graph_fixture );
	$synthetic_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $synthetic_destination_map, $presentation_graph_fixture );
	$assert( empty( $jetpack_destination_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $jetpack_destination_overlay['overlay'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $synthetic_destination_overlay['overlay'] ), 'partitioned-destinations-account-for-all-source-properties-and-pass-output-admission' );
	$editor_overlay = array( 'schema' => Static_Site_Importer_Provider_Layout_Overlay::OVERLAY_SCHEMA, 'css' => "/* Static Site Importer provider layout overlay: abcdef123456 */\n.ssi-form-123456789abc{display:flex}\n", 'editor_css' => "/* Static Site Importer editor control chrome: abcdef123456 */\n.editor-styles-wrapper .ssi-form-123456789abc .ssi-node-123456789abc{background:#fff}\n", 'sha256' => '', 'bytes' => 0 );
	$editor_overlay['sha256'] = hash( 'sha256', $editor_overlay['css'] ); $editor_overlay['bytes'] = strlen( $editor_overlay['css'] );
	$editor_overlay['editor_sha256'] = hash( 'sha256', $editor_overlay['editor_css'] ); $editor_overlay['editor_bytes'] = strlen( $editor_overlay['editor_css'] );
	$editor_overlay_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/editor-overlay', 'Editor overlay', '', array(), array(), array( $editor_overlay ), array( '/tmp/editor-overlay/style.css' => '/* base */', '/tmp/editor-overlay/assets/css/editor-style.css' => '/* editor */' ) );
	$malicious_editor_overlay = $editor_overlay; $malicious_editor_overlay['editor_css'] = "/* Static Site Importer editor control chrome: abcdef123456 */\n.editor-styles-wrapper body{background:#fff}\n"; $malicious_editor_overlay['editor_sha256'] = hash( 'sha256', $malicious_editor_overlay['editor_css'] ); $malicious_editor_overlay['editor_bytes'] = strlen( $malicious_editor_overlay['editor_css'] );
	$invalid_editor_hash = $editor_overlay; $invalid_editor_hash['editor_bytes']++;
	$oversized_editor_overlay = $editor_overlay; $oversized_editor_overlay['editor_css'] = str_repeat( 'a', 32769 ); $oversized_editor_overlay['editor_sha256'] = hash( 'sha256', $oversized_editor_overlay['editor_css'] ); $oversized_editor_overlay['editor_bytes'] = strlen( $oversized_editor_overlay['editor_css'] );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $editor_overlay ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $malicious_editor_overlay ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $invalid_editor_hash ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $oversized_editor_overlay ) && str_contains( $editor_overlay_writes['/tmp/editor-overlay/style.css'], 'provider layout overlay' ) && ! str_contains( $editor_overlay_writes['/tmp/editor-overlay/style.css'], 'editor control chrome' ) && str_contains( $editor_overlay_writes['/tmp/editor-overlay/assets/css/editor-style.css'], 'editor control chrome' ), 'editor-only overlays require bounded independently hashed admitted rules and survive existing stylesheet writes without changing frontend CSS' );
	$incomplete_destination_map = $jetpack_destination_map;
	array_pop( $incomplete_destination_map['presentation_targets'][0]['destinations'] );
	$incomplete_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $incomplete_destination_map, $presentation_graph_fixture );
	$assert( in_array( 'provider_structure_mismatch', array_column( $incomplete_destination_overlay['losses'], 'reason_code' ), true ), 'destination-property-partition-cannot-silently-drop-source-presentation' );
	$assert( str_contains( $jetpack_destination_overlay['css'], 'background-color:#fff;--provider-input-background:#fff;padding:8px' ) && str_contains( $jetpack_destination_overlay['css'], 'flex:1 1 0;min-width:0;font-size:16px' ) && $jetpack_destination_overlay['css'] === str_replace( 'provider: jetpack', 'provider: synthetic', $synthetic_destination_overlay['css'] ), 'jetpack-and-synthetic-destination-map-fixtures-use-the-same-generic-projector', $jetpack_destination_overlay['css'] );
	$malformed_destination_map = $jetpack_destination_map; $malformed_destination_map['presentation_targets'][0]['destinations'][0]['aliases']['background_color'] = 'background:url(x)';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $malformed_destination_map, $root_graph )['error'] ), 'provider-layout-overlay-rejects-malformed-declarative-destination-maps' );
	$responsive_root = $root_graph;
	$responsive_root['variants'] = array( array( 'node' => 'form', 'condition' => array( 'kind' => 'media', 'query' => '(min-width: 48rem)' ), 'layout_patch' => array( 'direction' => 'column' ) ) );
	$assert( str_contains( Static_Site_Importer_Provider_Layout_Overlay::compile( $responsive_root, $root_map )['css'], '@media (min-width: 48rem)' ), 'provider-layout-overlay-supports-bounded-media-condition' );
	$root_item = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'order' => 1 ), 'form' ) ) ), $root_map );
	$assert( 'direct_child_relationship_unrepresentable' === ( $root_item['losses'][0]['reason_code'] ?? '' ), 'jetpack-form-root-does-not-claim-direct-child-layout' );
	$item_graph = $layout_graph( array( $layout_node( 'control-0', array( 'order' => 1, 'flex_grow' => '1' ), 'input' ) ) );
	$item_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'control-0', 'selector' => '.ssi-form-123456789abc .ssi-node-123456789abc', 'capabilities' => array( 'item_layout', 'direct_child_layout' ) ) ) );
	$item_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( str_contains( $item_overlay['css'], 'order:1;flex-grow:1' ) && empty( $item_overlay['losses'] ), 'provider-layout-emits-item-properties-only-for-proven-direct-child-targets' );
	$prefix_margin_graph = $v2_layout_graph( array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'prefix' ) ), 'layout' => array( 'display' => 'flex', 'margin_inline_start' => '12px' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.prefix', 'condition' => null, 'properties' => array( 'display', 'margin-inline-start' ) ) ) ) ) );
	$prefix_margin_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'wrapper-0', 'selector' => '.ssi-form-123456789abc .ssi-node-123456789abc', 'capabilities' => array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' ) ) ) );
	$prefix_margin_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $prefix_margin_graph, $prefix_margin_map );
	$assert( str_contains( $prefix_margin_overlay['css'], 'margin-inline-start:12px' ) && empty( $prefix_margin_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $prefix_margin_overlay['overlay'] ), 'provider-layout-projects-logical-margin-longhands-onto-reconstructed-wrappers', wp_json_encode( $prefix_margin_overlay ) );
	$prefix_height_graph = $v2_layout_graph( array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'prefix' ) ), 'layout' => array( 'display' => 'block', 'height' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.prefix', 'condition' => null, 'properties' => array( 'display', 'height' ) ) ) ) ) );
	$prefix_height_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $prefix_height_graph, $prefix_margin_map );
	$assert( str_contains( $prefix_height_overlay['css'], 'height:100%' ) && empty( $prefix_height_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $prefix_height_overlay['overlay'] ), 'provider-layout-projects-explicit-height-onto-reconstructed-wrappers', wp_json_encode( $prefix_height_overlay ) );
	$justify_self_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'control-0', array( 'justify_self' => 'center' ), 'input' ) ) ), $item_map );
	$assert( str_contains( $justify_self_overlay['css'], 'justify-self:center' ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $justify_self_overlay['overlay'] ), 'provider-layout-validates-compiled-justify-self-css' );
	$item_map['targets'][0]['capabilities'] = array( 'item_layout' );
	$item_without_direct_child = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( '' === $item_without_direct_child['css'] && array( 'direct_child_relationship_unrepresentable', 'direct_child_relationship_unrepresentable' ) === array_column( $item_without_direct_child['losses'], 'reason_code' ), 'provider-layout-does-not-accept-inert-item-layout-capability' );
	$membership = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.membership', 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'number', 'name' => 'household', 'label' => 'Household size', 'min' => '1', 'max' => '50' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Register' ) ) ) ) ) );
	$membership_row    = $membership['forms'][0] ?? array();
	$membership_markup = (string) ( $membership_row['block_markup'] ?? '' );
	$assert( true === ( $membership_row['runtime_mapped'] ?? false ) && 'mapped' === ( $membership_row['status'] ?? '' ) && 0 === ( $membership['counts']['skipped'] ?? -1 ), 'number-min-max-does-not-decline-the-form', wp_json_encode( $membership_row ) );
	$assert( str_contains( $membership_markup, 'wp:jetpack/field-number' ) && str_contains( $membership_markup, 'wp:jetpack/field-email' ) && str_contains( $membership_markup, '"label":"Household size"' ), 'number-min-max-materializes-field-number-inside-the-form', $membership_markup );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/input \{[^\n]*"min":1[^\n]*"max":50[^\n]*\} \/-->/', $membership_markup ), 'number-min-max-are-serialized-onto-jetpack-input', $membership_markup );
	$assert( array() === array_column( $membership_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-min-max-are-not-receipt-losses', wp_json_encode( $membership_row['computed_layout_receipt'] ?? array() ) );
	$booking = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.booking', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request booking' ) ) ) ) ) );
	$booking_row = $booking['forms'][0] ?? array();
	$assert( 'Request booking' === ( $booking_row['submit_text'] ?? '' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '>Request booking</button>' ), 'canonical-control-text-preserves-request-booking-submit-label' );
	$assert( str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"label":"Guests"' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"min":1' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"max":8' ) && ! str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"step"' ) && array( 'step' ) === array_column( $booking_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-source-attributes-preserve-supported-min-max-and-report-step-loss' );
	$assert( false === ( $booking_row['runtime_mapped'] ?? true ) && array( 'step' ) === array_column( $booking_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ) && 1 === ( $booking_row['unaccepted_receipt_loss_count'] ?? 0 ), 'number-unsupported-step-gates-form-runtime-acceptance' );
	$assert( 'skipped' === ( $booking_row['status'] ?? '' ) && 0 === ( $booking['counts']['error'] ?? -1 ), 'gated-form-is-a-provider-decline-not-a-materialization-error' );
	$height_controls = array();
	for ( $height_index = 1; $height_index <= 17; ++$height_index ) {
		$height_controls[] = array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message-' . $height_index, 'height' => $height_index . 'px' );
	}
	$height_controls[] = array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' );
	$height_entity = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/many-heights.html',
			'selector'    => 'form.many-heights',
			'form'        => array( 'class' => 'many-heights', 'textarea_height_omitted_count' => 1 ),
			'controls'    => $height_controls,
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/many-heights.html', 'search_block_markup' => '<!-- wp:html --><form class="many-heights"></form><!-- /wp:html -->', 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $height_entity ) ) )['forms'][0] ?? array();
	$assert( false === ( $height_row['runtime_mapped'] ?? true ) && 'textarea_height_omitted' === ( $height_row['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && 1 === ( $height_row['unaccepted_receipt_loss_count'] ?? 0 ), 'omitted-textarea-heights-gate-form-runtime-acceptance' );
	$binding_ignored = $height_entity;
	$binding_ignored['bindings'][0]['occurrence'] = 0;
	$assert( 1 === ( Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $binding_ignored )['form']['textarea_height_omitted_count'] ?? 0 ), 'producer-presentation-does-not-depend-on-binding-html' );
	$multi_form_mismatch = $height_entity;
	$multi_form_mismatch['bindings'][0]['search_block_markup'] = '<form><input name="unexpected"></form>';
	$assert( 1 === ( Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $multi_form_mismatch )['form']['textarea_height_omitted_count'] ?? 0 ), 'producer-presentation-ignores-conflicting-binding-html' );
	$newsletter = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.newsletter', 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Subscribe to newsletter' ) ) ) ) ) );
	$assert( 'Subscribe to newsletter' === ( $newsletter['forms'][0]['submit_text'] ?? '' ), 'canonical-control-text-preserves-newsletter-submit-label' );
	if ( function_exists( 'parse_blocks' ) ) {
		$parsed_markup = parse_blocks( $topology_markup );
		$parsed_names = array();
		$walk_parsed_markup = static function ( array $parsed ) use ( &$walk_parsed_markup, &$parsed_names ): void {
			foreach ( $parsed as $block ) {
				if ( is_array( $block ) ) {
					$parsed_names[] = $block['blockName'] ?? null;
					$walk_parsed_markup( $block['innerBlocks'] ?? array() );
				}
			}
		};
		$walk_parsed_markup( $parsed_markup );
		$assert( array( 'jetpack/contact-form', 'jetpack/field-text', 'jetpack/label', 'jetpack/input', 'jetpack/field-email', 'jetpack/label', 'jetpack/input', 'jetpack/field-textarea', 'jetpack/label', 'jetpack/input', 'core/button' ) === $parsed_names, 'wordpress-parse-blocks-preserves-canonical-provider-grammar', wp_json_encode( $parsed_names ) );
	}
	$unsafe_graph = $topology_form;
	$unsafe_graph['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'grid', 'direction' => 'none', 'item_placement' => array( 'column' => 1 ) );
	$unsafe_graph_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_graph );
	$assert( empty( $unsafe_graph_validation['forms'] ) && str_contains( (string) ( $unsafe_graph_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'computed-layout-rejects-non-producer-item-placement-shape' );
	$unknown_layout_key = $topology_form;
	$unknown_layout_key['forms'][0]['layout_graph']['nodes'][0]['layout']['alignment'] = 'center';
	$unknown_layout_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_layout_key );
	$assert( empty( $unknown_layout_validation['forms'] ) && str_contains( (string) ( $unknown_layout_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'computed-layout-rejects-alignment-alias-at-runtime-boundary' );
	$unknown_layout_key['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'flex', 'direction' => 'row', 'justify' => 'center' );
	$unknown_layout_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_layout_key );
	$assert( empty( $unknown_layout_validation['forms'] ) && str_contains( (string) ( $unknown_layout_validation['errors'][0]['message'] ?? '' ), 'producer contract' ), 'computed-layout-rejects-justify-alias-at-runtime-boundary' );
	$responsive_flex = $topology_form;
	$responsive_condition = array( 'kind' => 'media', 'query' => '(min-width: 48rem)' );
	$responsive_flex['forms'][0]['layout_graph']['variants'] = array( array( 'node' => 'wrapper-0', 'condition' => $responsive_condition, 'layout_patch' => array( 'direction' => 'column', 'wrap' => 'wrap' ), 'precedence' => array( 'flex-direction' => array( 'source_order' => 4, 'specificity' => 10, 'important' => false ), 'flex-wrap' => array( 'source_order' => 4, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.row-2', 'condition' => $responsive_condition, 'properties' => array( 'flex-direction', 'flex-wrap' ) ) ) ) );
	$responsive_flex_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_flex );
	$assert( empty( $responsive_flex_validation['errors'] ) && array( 'flex-direction', 'flex-wrap' ) === array_keys( $responsive_flex_validation['forms'][0]['layout_graph']['variants'][0]['precedence'] ?? array() ), 'computed-layout-accepts-responsive-flex-css-provenance-property-names' );
	$unknown_variant_key = $responsive_flex;
	$unknown_variant_key['forms'][0]['layout_graph']['variants'][0]['precedence']['unknown-property'] = array( 'source_order' => 4, 'specificity' => 10, 'important' => false );
	$unknown_variant_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_variant_key );
	$assert( empty( $unknown_variant_validation['forms'] ) && str_contains( (string) ( $unknown_variant_validation['errors'][0]['message'] ?? '' ), 'precedence' ), 'computed-layout-rejects-unknown-producer-precedence-property' );

	// --- Computed layout maps only complete core/group flex facts --------------
	$layout_blocks = array( array( 'name' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'topologyId' => 'wrapper-0' ) );
	$complete_layout = array( 'display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '1rem', 'align_items' => 'center', 'justify_content' => 'space-between' );
	$complete_result = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', $complete_layout ) ) ) ), $layout_blocks );
	$complete_attrs = $complete_result['blocks'][0]['attrs'];
	$assert( array( 'type' => 'flex', 'orientation' => 'horizontal', 'flexWrap' => 'wrap', 'verticalAlignment' => 'center', 'justifyContent' => 'space-between' ) === $complete_attrs['layout'] && '1rem' === $complete_attrs['style']['spacing']['blockGap'], 'computed-layout-maps-wrap-gap-alignment-and-justification' );
	$nowrap_result = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', array( 'display' => 'flex', 'direction' => 'column', 'wrap' => 'nowrap' ) ) ) ) ), $layout_blocks );
	$assert( 'nowrap' === $nowrap_result['blocks'][0]['attrs']['layout']['flexWrap'] && 'vertical' === $nowrap_result['blocks'][0]['attrs']['layout']['orientation'], 'computed-layout-maps-source-nowrap' );
	$conflicting_gaps = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', array( 'display' => 'flex', 'direction' => 'row', 'row_gap' => '1rem', 'column_gap' => '2rem' ) ) ) ) ), $layout_blocks );
	$assert( 'conflicting_axis_gaps' === ( $conflicting_gaps['receipt']['losses'][0]['reason_code'] ?? '' ), 'computed-layout-defers-conflicting-gaps' );
	$form_flex = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row' ), 'form' ) ) ) ), $layout_blocks );
	$control_item = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'control-0', array( 'display' => 'flex', 'direction' => 'row', 'order' => 1 ), 'input' ) ) ) ), $layout_blocks );
	$assert( 'layout_target_unrepresentable' === ( $form_flex['receipt']['losses'][0]['reason_code'] ?? '' ) && 'layout_target_unrepresentable' === ( $control_item['receipt']['losses'][0]['reason_code'] ?? '' ), 'computed-layout-defers-form-and-control-item-facts' );
	$receipt_nodes = array();
	for ( $receipt_index = 0; $receipt_index < 33; ++$receipt_index ) $receipt_nodes[] = $layout_node( 'control-' . $receipt_index, array( 'display' => 'flex', 'direction' => 'row' ), 'input' );
	$capped_receipt = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( $receipt_nodes ) ), $layout_blocks )['receipt'];
	$assert( 32 === $capped_receipt['loss_count'] && 33 === $capped_receipt['losses_total'] && true === $capped_receipt['truncated'], 'computed-layout-receipt-caps-entries-at-32' );
	$gate_losses = array_fill( 0, 33, array( 'dimension' => 'topology', 'reason_code' => 'provider_wrapper_layout_unrepresentable' ) );
	$gate_overflow_receipt = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'topology_losses' => $gate_losses ), array() )['receipt'];
	$assert( 1 === ( $gate_overflow_receipt['gate_required_loss_overflow_count'] ?? 0 ) && 64 === strlen( (string) ( $gate_overflow_receipt['gate_required_loss_overflow_hash'] ?? '' ) ), 'computed-layout-records-gate-required-overflow-before-seeder-appends' );
	// Computed layout counts gated overflow with the seeder's own receipt policy.
