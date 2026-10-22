<?php
/**
 * Form materializer smoke suite: compiled artifact forms, label margins and grid placement.
 *
 * Loaded by tests/form-materializer-smoke.php, which provides the harness
 * ($assert), helper closures and shared fixtures.
 */

	$label_margin_css = static function ( string $html ) use ( $artifact_compiler ): array {
		$compiled  = ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		return array( $validated['errors'] ?? array(), (string) ( $row['status'] ?? '' ), (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ) );
	};
	list( $sibling_label_errors, $sibling_label_status, $sibling_label_css ) = $label_margin_css( '<style>.field{margin-bottom:18px}.field label{display:block;font-weight:700;margin-bottom:6px}</style><form method="post" action="/x"><div class="field"><label for="n">Your name</label><input id="n" name="name" type="text"></div><div class="field"><label for="e">Email</label><input id="e" name="email" type="email"></div><button type="submit">Send</button></form>' );
	$assert(
		empty( $sibling_label_errors )
			&& 'mapped' === $sibling_label_status
			&& 1 === preg_match( '/\.(ssi-form-[a-f0-9]{12})\.\1 \.ssi-node-[a-f0-9]{12}[^{]*\{[^}]*font-weight:700;margin-bottom:6px\}/', $sibling_label_css )
			&& ! str_contains( $sibling_label_css, 'margin-block:0!important' )
			&& 1 === preg_match( '/\n\.ssi-form-[a-f0-9]{12} \.ssi-field-row > label\{margin-block-end:0\}/', $sibling_label_css ),
		'sibling-label-authored-margin-outranks-the-provider-default-label-reset',
		$sibling_label_css
	);
	list( $wrapping_label_errors, $wrapping_label_status, $wrapping_label_css ) = $label_margin_css( '<style>.field{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1.1rem}</style><form method="post" action="/x"><label class="field"><span>Name</span><input name="name" type="text"></label><label class="field"><span>Email</span><input name="email" type="email"></label><button type="submit">Send</button></form>' );
	$assert(
		empty( $wrapping_label_errors )
			&& 'mapped' === $wrapping_label_status
			&& 1 === preg_match( '/\.(ssi-form-[a-f0-9]{12})\.\1 \.ssi-node-[a-f0-9]{12}, \.\1\.\1 \.ssi-node-[a-f0-9]{12}\{margin-block:0!important\}/', $wrapping_label_css ),
		'wrapping-label-field-box-margin-is-released-on-the-provider-caption',
		$wrapping_label_css
	);
	// A producer resolves a `grid gap-2` row's gap onto its sibling label as a
	// margin whose provenance is the row's own class rule. The front-end runtime
	// rebuilds that row with its source classes, so the stylesheet gap already
	// spaces the label there; the margin is released on the front end only. The
	// editor has no rebuilt row and keeps it. A label margin authored on the
	// label itself is not the row's, so it is never released.
	$row_gap_label = static function ( string $css ) use ( $artifact_compiler ): array {
		$html      = '<style>' . $css . '</style><form method="post" action="/x"><div class="grid gap-2"><label for="n">Name</label><input id="n" name="name" type="text"></div><div class="grid gap-2"><label for="e">Email</label><input id="e" name="email" type="email"></div><button type="submit">Send</button></form>';
		$compiled  = ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		return array( $validated['errors'] ?? array(), (string) ( $row['status'] ?? '' ), (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ), (string) ( $row['provider_layout_overlay_css']['editor_css'] ?? '' ) );
	};
	list( $row_gap_errors, $row_gap_status, $row_gap_css, $row_gap_editor_css ) = $row_gap_label( '.grid{display:grid}.gap-2{gap:.5rem}' );
	$assert(
		empty( $row_gap_errors )
			&& 'mapped' === $row_gap_status
			&& 2 === preg_match_all( '/\.(ssi-form-[a-f0-9]{12})\.\1 \.ssi-field-row > \.ssi-node-[a-f0-9]{12}\{margin-block-end:0!important\}/', $row_gap_css )
			&& 2 === preg_match_all( '/\.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-end:0?\.5rem/', $row_gap_editor_css )
			&& ! str_contains( $row_gap_editor_css, 'margin-block-end:0!important' ),
		'row-gap-carried-label-margin-is-released-on-the-front-end-and-kept-in-the-editor',
		wp_json_encode( array( 'css' => $row_gap_css, 'editor_css' => $row_gap_editor_css ) )
	);
	list( $own_margin_errors, $own_margin_status, $own_margin_css ) = $row_gap_label( '.grid{display:grid}.gap-2{gap:.5rem}label{margin-bottom:3px}' );
	$assert(
		empty( $own_margin_errors )
			&& 'mapped' === $own_margin_status
			&& 0 === preg_match( '/\{margin-(?:block-end|bottom):0!important\}/', $own_margin_css ),
		'label-margin-authored-on-the-label-is-not-released-as-a-row-gap',
		$own_margin_css
	);
	// Below 480px Jetpack's `flex-basis: 100%` on every button wrapper would
	// stretch a content-sized source submit across the form. The provider basis
	// is released with a reset weaker than the captured submit rule, so an
	// authored `width: 100%` still fills the row.
	$submit_basis_css = static function ( string $css ) use ( $artifact_compiler ): array {
		$compiled  = ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<style>' . $css . '</style><form method="post" action="/contact"><label for="n">Name</label><input id="n" name="name" type="text"><button type="submit" class="btn">Send</button></form>' ) ) )->toArray();
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		return array( $validated['errors'] ?? array(), (string) ( $row['status'] ?? '' ), (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ) );
	};
	list( $content_submit_errors, $content_submit_status, $content_submit_css ) = $submit_basis_css( '.btn{display:inline-flex;padding:10px 20px}' );
	$assert(
		empty( $content_submit_errors )
			&& 'mapped' === $content_submit_status
			&& 1 === preg_match( '/\n\.ssi-form-[a-f0-9]{12} div\.form-button-submit\{flex-basis:auto\}/', $content_submit_css ),
		'content-sized-submit-releases-the-provider-mobile-button-basis',
		$content_submit_css
	);
	list( $full_submit_errors, $full_submit_status, $full_submit_css ) = $submit_basis_css( '.btn{width:100%;padding:10px 20px}' );
	$assert(
		empty( $full_submit_errors )
			&& 'mapped' === $full_submit_status
			&& 1 === preg_match( '/\.(ssi-form-[a-f0-9]{12})\.\1 \.ssi-node-[a-f0-9]{12}\{[^}]*width:100%/', $full_submit_css )
			&& 1 === preg_match( '/\n\.ssi-form-[a-f0-9]{12} div\.form-button-submit\{flex-basis:auto\}/', $full_submit_css ),
		'full-width-submit-keeps-its-captured-width-over-the-released-basis',
		$full_submit_css
	);
	$center_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column;align-items:center}}' );
	$center_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $center_source ) ) );
	$center_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $center_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! str_contains( (string) ( $center_row['provider_layout_overlay_css']['css'] ?? '' ), 'align-self:stretch' ), 'artifact-form-align-items-center-does-not-stretch-submit' );
	$self_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column}button{align-self:flex-start}}' );
	$self_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $self_source ) ) );
	$self_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $self_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! str_contains( (string) ( $self_row['provider_layout_overlay_css']['css'] ?? '' ), 'align-self:stretch' ), 'artifact-form-align-self-override-does-not-stretch-submit' );
	$phone_presentation = $presentation_form;
	$phone_presentation['forms'][0]['controls'][0]['type'] = 'phone';
	$phone_presentation['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'background_color' => '#fff', 'border_color' => '#1e4b6e', 'border_radius' => '0', 'font_size' => '16px', 'line_height' => '24px', 'padding_block_start' => '8px', 'padding_block_end' => '8px', 'padding_inline_start' => '8px', 'padding_inline_end' => '8px', 'height' => '40px' ), array( 'background-color', 'border-color', 'border-radius', 'font-size', 'line-height', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end', 'height' ), 'input' ) ) );
	$phone_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['styles']['text_indent'] = '4px';
	$validated_phone_presentation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_presentation );
	$phone_presentation_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_phone_presentation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_presentation_css       = (string) ( $phone_presentation_row['provider_layout_overlay_css']['css'] ?? '' );
	$phone_presentation_target    = $phone_presentation_row['provider_layout_target_map']['presentation_targets'][0] ?? array();
	$phone_destinations = $phone_presentation_target['destinations'] ?? array();
	$phone_markup = (string) ( $phone_presentation_row['block_markup'] ?? '' );
	$phone_destination_hooks = array();
	foreach ( $phone_destinations as $destination ) {
		if ( is_array( $destination ) && preg_match( '/\.((?:ssi-node)-[a-f0-9]{12}-destination-(?:shell|primary|carrier|prefix))$/', (string) ( $destination['selector'] ?? '' ), $matches ) ) {
			$phone_destination_hooks[] = $matches[1];
		}
	}
	$assert( '0' === ( $phone_destinations[0]['resets']['text-indent'] ?? null ) && '0' === ( $phone_destinations[0]['resets']['gap'] ?? null ) && str_contains( $phone_presentation_css, 'text-indent:0!important' ) && str_contains( $phone_presentation_css, 'gap:0!important' ) && str_contains( $phone_presentation_css, 'text-indent:4px!important' ), 'phone-text-indentation-belongs-to-value-not-structural-prefix-container' );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $phone_presentation_row['provider_layout_overlay_css'] ?? array() ), 'composite-provider-destination-overlay-survives-stylesheet-admission' );
	$assert( empty( $validated_phone_presentation['errors'] ) && 4 === count( $phone_destinations ) && empty( $phone_destinations[0]['properties'] ) && str_contains( (string) ( $phone_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && 'revert' === ( $phone_destinations[1]['resets']['font-family'] ?? null ) && 'revert' === ( $phone_destinations[1]['resets']['line-height'] ?? null ) && str_contains( (string) ( $phone_destinations[3]['selector'] ?? '' ), '-destination-prefix' ) && 'flex' === ( $phone_destinations[3]['resets']['display'] ?? null ) && 'center' === ( $phone_destinations[3]['resets']['align-items'] ?? null ) && '100%' === ( $phone_destinations[3]['resets']['height'] ?? null ) && str_contains( $phone_presentation_css, 'background-color:#fff!important' ) && str_contains( $phone_presentation_css, 'border-color:#1e4b6e!important' ) && str_contains( $phone_presentation_css, 'padding-block-start:8px!important' ) && str_contains( $phone_presentation_css, 'padding-inline-end:8px!important' ) && str_contains( $phone_presentation_css, 'font-family:revert!important' ) && str_contains( $phone_presentation_css, 'padding:0!important;border:0!important;background:transparent!important;text-indent:0!important;gap:0!important' ) && str_contains( $phone_presentation_css, 'display:flex!important;align-items:center!important;height:100%!important' ), 'phone-presentation-keeps-input-styles-on-value-and-neutralizes-provider-added-shell', wp_json_encode( array( 'css' => $phone_presentation_css, 'target' => $phone_presentation_target ) ) );
	$assert( 4 === count( $phone_destination_hooks ) && empty( array_filter( $phone_destination_hooks, static fn( string $hook ): bool => ! str_contains( $phone_markup, $hook ) ) ), 'phone-markup-hooks-and-overlay-destinations-share-one-prepared-calculation', wp_json_encode( array( 'markup' => $phone_markup, 'hooks' => $phone_destination_hooks ) ) );
	$whitespace_phone_presentation                            = $phone_presentation;
	$whitespace_phone_presentation['forms'][0]['controls'][0]['type'] = ' tel ';
	$validated_whitespace_phone_presentation                  = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $whitespace_phone_presentation );
	$whitespace_phone_row                                     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_whitespace_phone_presentation['forms'] ?? array() ) )['forms'][0] ?? array();
	$whitespace_phone_destinations                             = $whitespace_phone_row['provider_layout_target_map']['presentation_targets'][0]['destinations'] ?? array();
	$whitespace_phone_markup                                  = (string) ( $whitespace_phone_row['block_markup'] ?? '' );
	$whitespace_phone_hooks                                   = array();
	foreach ( $whitespace_phone_destinations as $destination ) {
		if ( is_array( $destination ) && preg_match( '/\.((?:ssi-node)-[a-f0-9]{12}-destination-(?:shell|primary|carrier|prefix))$/', (string) ( $destination['selector'] ?? '' ), $matches ) ) {
			$whitespace_phone_hooks[] = $matches[1];
		}
	}
	$assert( empty( $validated_whitespace_phone_presentation['errors'] ) && 4 === count( $whitespace_phone_hooks ) && empty( array_filter( $whitespace_phone_hooks, static fn( string $hook ): bool => ! str_contains( $whitespace_phone_markup, $hook ) ) ), 'whitespace-padded-tel-shares-phone-markup-hooks-and-overlay-destinations', wp_json_encode( array( 'markup' => $whitespace_phone_markup, 'hooks' => $whitespace_phone_hooks ) ) );
	$editor_chrome_graph = array(
		'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'visual_parts' => array(), 'visual_groups' => array(), 'group_captions' => array(), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array( array( 'index' => 0, 'control' => $presentation_role( array( 'border' => '0' ), array( 'border' ), 'input' ) ) ),
		'control_containers' => array( array( 'index' => 0, 'source_selector' => '.field', 'styles' => array( 'border' => '1px solid rgba(30,75,110,.6)', 'background' => 'rgb(247,249,251)', 'border_radius' => '0' ), 'provenance' => array( array( 'source_path' => 'assets/forms.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field', 'condition' => null, 'properties' => array( 'border', 'background', 'border-radius' ) ) ) ) ),
	);
	$editor_chrome_form = $presentation_form;
	$editor_chrome_form['forms'][0]['presentation_graph'] = $editor_chrome_graph;
	$editor_chrome_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $editor_chrome_form );
	$editor_chrome_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $editor_chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$editor_chrome_overlay = $editor_chrome_row['provider_layout_overlay_css'] ?? array();
	$editor_chrome_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/editor-chrome', 'Editor Chrome', '', array(), array(), array( $editor_chrome_overlay ) );
	$editor_chrome_frontend = (string) ( $editor_chrome_writes['/tmp/editor-chrome/style.css'] ?? '' );
	$editor_chrome_editor = (string) ( $editor_chrome_writes['/tmp/editor-chrome/assets/css/editor-style.css'] ?? '' );
	$assert( empty( $editor_chrome_validation['errors'] ) && str_contains( (string) ( $editor_chrome_overlay['css'] ?? '' ), 'border:0' ) && str_contains( (string) ( $editor_chrome_overlay['css'] ?? '' ), 'border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0' ) && str_contains( (string) ( $editor_chrome_overlay['editor_css'] ?? '' ), '.editor-styles-wrapper ' ) && str_contains( $editor_chrome_editor, 'border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0' ) && str_contains( $editor_chrome_frontend, '1px solid rgba(30,75,110,.6)' ), 'control-container-chrome-reaches-the-rendered-field-wrapper-without-overwriting-the-inner-input-reset', wp_json_encode( $editor_chrome_overlay ) );
	$phone_editor_chrome_form                              = $editor_chrome_form;
	$phone_editor_chrome_form['forms'][0]['controls'][0]['type'] = 'phone';
	$phone_editor_chrome_form['forms'][0]['presentation_graph']['controls'][0]['control'] = $presentation_role( array( 'padding_inline_end' => '2px' ), array( 'padding-inline-end' ), 'input' );
	$phone_editor_chrome_validation                        = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_editor_chrome_form );
	$phone_editor_chrome_row                               = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $phone_editor_chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_editor_chrome_overlay                           = $phone_editor_chrome_row['provider_layout_overlay_css'] ?? array();
	$phone_editor_chrome_destinations                      = $phone_editor_chrome_row['provider_layout_target_map']['presentation_targets'][0]['destinations'] ?? array();
	$assert( empty( $phone_editor_chrome_validation['errors'] ) && 5 === count( $phone_editor_chrome_destinations ) && str_contains( (string) ( $phone_editor_chrome_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && '0' === ( $phone_editor_chrome_destinations[0]['resets']['padding'] ?? null ) && str_contains( (string) ( $phone_editor_chrome_destinations[2]['selector'] ?? '' ), '-destination-carrier' ) && str_contains( (string) ( $phone_editor_chrome_destinations[3]['selector'] ?? '' ), '-destination-prefix' ) && str_contains( (string) ( $phone_editor_chrome_destinations[4]['selector'] ?? '' ), 'ssi-node-' ) && str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), 'padding-inline-end:2px!important' ) && str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ) && str_contains( (string) ( $phone_editor_chrome_overlay['editor_css'] ?? '' ), 'border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0' ), 'phone-control-container-chrome-targets-the-rendered-field-wrapper-while-primary-and-carrier-destinations-retain-their-separate-ownership', wp_json_encode( $phone_editor_chrome_row ) );
	$unsafe_presentation = $presentation_form;
	$unsafe_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['styles']['background_image'] = 'url(https://example.test/tracker)';
	$oversized_presentation = $presentation_form;
	$oversized_presentation['forms'][0]['presentation_graph']['controls'] = array_fill( 0, 129, array() );
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_presentation )['errors'] ) && ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $oversized_presentation )['errors'] ), 'form-presentation-contract-rejects-unsafe-or-unbounded-input' );
	$candidate_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' );
	$candidate_root        = is_string( $candidate_root ) && '' !== $candidate_root ? rtrim( $candidate_root, '/\\' ) : dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer';
	$candidate_transformer = $candidate_root . '/php-transformer.php';
	if ( ! is_readable( $candidate_transformer ) && is_readable( $candidate_root . '/php-transformer/php-transformer.php' ) ) {
		$candidate_transformer = $candidate_root . '/php-transformer/php-transformer.php';
	}
	if ( ! is_readable( $candidate_transformer ) ) {
		throw new RuntimeException( 'The required Blocks Engine transformer is unavailable.' );
	}
	$candidate_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<link rel="stylesheet" href="style.css"><main><form><div><button type="button" aria-haspopup="listbox" aria-label="Phone country selector"><span class="sourcegroup"><svg class="globe" width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><svg class="chevron" width="16" height="16" viewBox="0 0 16 16"><path d="M4 7l4 4 4-4"/></svg></span></button><input type="tel" name="phone"></div></form></main>', 'style.css' => '.sourcegroup{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:8px}.globe{width:24px;color:rgb(30,75,110)}.chevron{width:16px}@media (min-width:769px){.sourcegroup{gap:4px}.chevron{width:12px}}' ) );
	$candidate_artifact['files']['style.css'] .= 'button{position:relative;flex-shrink:0;transform:translateX(0)}';
	$candidate_code = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $candidate_artifact, true ) . '));';
	$candidate_json = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $candidate_code ) );
	$candidate_result = is_string( $candidate_json ) ? json_decode( $candidate_json, true ) : null;
	if ( ! is_array( $candidate_result ) ) {
		throw new RuntimeException( 'The required Blocks Engine candidate compilation failed.' );
	}
	$candidate_plan = $candidate_result['source_reports']['wordpress_site_plan'] ?? array();
	$chrome_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<link rel="stylesheet" href="style.css"><form><div class="field"><input name="email"></div><div class="shared"><input name="first"><input name="last"></div><div class="plain"><input name="plain"></div></form>', 'style.css' => 'input{border:0}div{background:0 0}@media (min-width:769px){.field{border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0}}.shared{border:2px solid #111}' ) );
	$chrome_code = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $chrome_artifact, true ) . '));';
	$chrome_json = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $chrome_code ) );
	$chrome_result = is_string( $chrome_json ) ? json_decode( $chrome_json, true ) : null;
	$chrome_declaration = is_array( $chrome_result ) ? current( array_filter( $chrome_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) ) : array();
	$chrome_entity = $chrome_declaration['payload']['entities'][0] ?? array();
	$chrome_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $chrome_entity ) ) );
	$chrome_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$chrome_overlay = $chrome_row['provider_layout_overlay_css'] ?? array();
	$chrome_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/real-be-chrome', 'Real BE Chrome', '', array(), array(), array( $chrome_overlay ) );
	$assert( is_array( $chrome_result ) && array() === ( $chrome_entity['presentation_graph']['control_containers'][0]['styles'] ?? null ) && 1 === count( $chrome_entity['presentation_graph']['control_containers'] ?? array() ) && str_contains( (string) ( $chrome_overlay['css'] ?? '' ), 'border:0' ) && str_contains( (string) ( $chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ) && str_contains( (string) ( $chrome_writes['/tmp/real-be-chrome/assets/css/editor-style.css'] ?? '' ), 'border:1px solid rgba(30,75,110,.6)' ) && str_contains( (string) ( $chrome_writes['/tmp/real-be-chrome/style.css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ), 'real-blocks-engine-artifact-preserves-responsive-owned-wrapper-paint-through-frontend-and-editor-overlays', wp_json_encode( $chrome_row ) );
	$candidate_declaration = current( array_filter( $candidate_plan['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) );
	$visual_state_form = array( 'forms' => $candidate_declaration['payload']['entities'] ?? array() );
	$validated_visual_state = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $visual_state_form );
	$visual_state_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_visual_state['forms'] ?? array() ) )['forms'][0] ?? array();
	$visual_state = $visual_state_row['form_visual_state'] ?? array();
	Static_Site_Importer_Provider_Form_Runtime_V1::configure_visual_states( array( $visual_state ) );
	$visual_provider_html = Static_Site_Importer_Provider_Form_Runtime_V1::project_empty_country_visual_state( '<div class="grunion-field-wrap grunion-field-telephone-wrap ' . ( $visual_state['field_class'] ?? '' ) . '-wrap"><div class="jetpack-field__input-phone-wrapper" data-wp-context="{&quot;defaultCountry&quot;:&quot;&quot;}"><button class="jetpack-combobox-trigger"><span class="jetpack-combobox-trigger-arrow"><svg></svg></span><span class="jetpack-combobox-selected" data-wp-text="context.selectedCountry.value"></span></button><input type="hidden" id="g5-phone"></div></div>' );
	$auxiliary_target = $visual_state_row['provider_layout_target_map']['presentation_targets'][0]['destinations'][0] ?? array();
	$auxiliary_overlay = (string) ( $visual_state_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $validated_visual_state['errors'] ) && 'submit' === ( $validated_visual_state['forms'][0]['controls'][0]['type'] ?? '' ) && true === ( $visual_state_row['runtime_mapped'] ?? false ) && 2 === count( $visual_state['parts'] ?? array() ) && 'visual-group-' === substr( (string) ( $visual_state['group']['id'] ?? '' ), 0, 13 ) && str_contains( $visual_provider_html, '&quot;defaultCountry&quot;:&quot;US&quot;' ) && ! str_contains( $visual_provider_html, 'data-wp-bind--hidden="context.selectedCountry.value"' ) && str_contains( $visual_provider_html, 'data-wp-bind--hidden="!context.selectedCountry.value"' ) && str_contains( $visual_provider_html, '.jetpack-combobox-selected' ) && str_contains( $visual_provider_html, ':not([hidden])' ) && str_contains( $visual_provider_html, 'display:flex!important' ) && str_contains( $visual_provider_html, 'gap:8px!important' ) && str_contains( $visual_provider_html, 'gap:4px!important' ) && str_contains( $visual_provider_html, 'width:24px!important' ) && str_contains( $visual_provider_html, 'width:12px!important' ) && str_contains( $visual_provider_html, '@media (min-width:769px)' ) && str_contains( $visual_provider_html, 'jetpack-combobox-trigger-arrow' ) && str_contains( $visual_provider_html, (string) ( $visual_state['trigger_class'] ?? '' ) ) && str_contains( (string) ( $auxiliary_target['selector'] ?? '' ), '-destination-country-trigger' ) && str_contains( $auxiliary_overlay, 'position:relative!important' ) && str_contains( $auxiliary_overlay, 'flex-shrink:0!important' ) && str_contains( $auxiliary_overlay, 'transform:translateX(0)!important' ) && empty( $visual_state_row['form_receipt_unaccepted_losses'] ?? array() ) && ! str_contains( $visual_provider_html, 'base64' ), 'candidate-producer-submit-coercion-still-materializes-country-selector-with-functional-default', wp_json_encode( array( 'validation' => $validated_visual_state, 'row' => $visual_state_row, 'html' => $visual_provider_html ) ) );
	$marker_artifact = array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<link rel="stylesheet" href="style.css"><main><form><label class="source-label" for="inherited">Inherited <span aria-hidden="true">*</span></label><input id="inherited" name="inherited" required></form><form><label class="small-label" for="small">Small <span class="required-marker" aria-hidden="true">*</span></label><input id="small" name="small" required></form><form><label class="conditional-label" for="conditional">Conditional <span class="required-marker" aria-hidden="true">*</span></label><input id="conditional" name="conditional" required></form><form><label class="plain-label" for="plain">Plain</label><input id="plain" name="plain" required></form></main>',
			'style.css'  => '.source-label{font-size:14px;line-height:19.6px}.small-label{font-size:14px;line-height:19.6px}.small-label .required-marker{font-size:12px;line-height:16px}.conditional-label{--marker-size:14px;font-size:14px;line-height:19.6px}.conditional-label .required-marker{font-size:var(--marker-size);line-height:19.6px}@media (max-width:768px){.conditional-label .required-marker{font-size:12px;margin-left:2px}}.plain-label{font-size:14px;line-height:19.6px}',
		),
	);
	$marker_code   = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $marker_artifact, true ) . '));';
	$marker_json   = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $marker_code ) );
	$marker_result = is_string( $marker_json ) ? json_decode( $marker_json, true ) : null;
	$marker_declaration = is_array( $marker_result ) ? current( array_filter( $marker_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) ) : array();
	$marker_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $marker_declaration['payload']['entities'] ?? array() ) );
	$marker_rows       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $marker_validation['forms'] ?? array() ) )['forms'] ?? array();
	$marker_css        = array_map( static fn( array $row ): string => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ), $marker_rows );
	$assert( empty( $marker_validation['errors'] ) && 4 === count( $marker_rows ) && preg_match( '/\.ssi-node-[a-f0-9]{12} > \.grunion-label-required\{font-size:inherit!important\}/', $marker_css[0] ?? '' ) && str_contains( $marker_css[1] ?? '', ' > .grunion-label-required{font-size:inherit!important;font-size:12px!important;line-height:16px!important}' ) && str_contains( $marker_css[2] ?? '', ' > .grunion-label-required{font-size:inherit!important;font-size:14px!important;line-height:19.6px!important}' ) && str_contains( $marker_css[2] ?? '', '@media (max-width:768px){' ) && str_contains( $marker_css[2] ?? '', 'font-size:12px!important;margin-left:2px!important' ) && ! str_contains( $marker_css[3] ?? '', 'grunion-label-required' ), 'paired-BE-to-SSI-required-marker-projection-is-field-scoped-and-keeps-authored-base-and-conditional-linebox-facts', wp_json_encode( array( 'validation' => $marker_validation, 'css' => $marker_css ) ) );
	$malformed_visual_state = $visual_state_form;
	$malformed_visual_state['forms'][0]['presentation_graph']['visual_parts'][0]['markup'] = '<svg><script>alert(1)</script></svg>';
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $malformed_visual_state )['errors'] ), 'v2-visual-parts-reject-malformed-svg-at-intake' );
	$topology_seed_repeat = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$assert( $topology_markup === (string) ( $topology_seed_repeat['forms'][0]['block_markup'] ?? '' ), 'provider-layout-classes-are-stable-for-identical-source-form' );
	$field_list_form = $topology_form['forms'][0];
	foreach ( $field_list_form['control_topology']['nodes'] as &$field_list_node ) {
		++$field_list_node['depth'];
		if ( null === ( $field_list_node['parent'] ?? null ) ) {
			$field_list_node['parent'] = 'wrapper-4';
		}
	}
	unset( $field_list_node );
	array_unshift( $field_list_form['control_topology']['nodes'], array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ) );
	$field_list_form['layout_graph']['nodes'][0]['parent'] = 'wrapper-4';
	array_unshift( $field_list_form['layout_graph']['nodes'], array( 'id' => 'wrapper-4', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'field-list' ) ), 'layout' => array(), 'provenance' => array() ) );
	$field_list_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-4', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => '2rem' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'flex-direction' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'gap' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'f', 64 ), 'selector' => '.field-list', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) );
	$field_list_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $field_list_form ) ) );
	$field_list_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $field_list_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( str_contains( (string) ( $field_list_row['block_markup'] ?? '' ), 'form contact field-list ssi-form-' ) && in_array( 'provider_field_list_class_projection', array_column( $field_list_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! in_array( 'responsive_layout_ownership', array_column( $field_list_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'class-owned-field-list-wrapper-projects-onto-provider-container', wp_json_encode( array( 'validation' => $field_list_validation, 'row' => $field_list_row ) ) );

	$grid_submit_form = array(
		'selector' => 'form.grid-submit',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array(
			'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
			'nodes' => array(
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
				array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div' ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 1 ),
			),
		),
		'layout_graph' => $v2_layout_graph( array(
			array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'grid-submit' ) ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.grid-submit', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns' ) ) ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array( 'submit-cell' ) ), 'layout' => array( 'column' => 'span 4' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.submit-cell', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) ),
		) ),
	);
	$grid_submit_condition = array( 'kind' => 'media', 'query' => '(max-width: 767px)' );
	$grid_submit_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $grid_submit_condition, 'layout_patch' => array( 'column' => 'span 12' ), 'precedence' => array( 'grid-column' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.submit-cell', 'condition' => $grid_submit_condition, 'properties' => array( 'grid-column' ) ) ) );
	$grid_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_submit_form ) ) );
	$grid_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_submit_css = (string) ( $grid_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $grid_submit_validation['errors'] ) && 'mapped' === ( $grid_submit_row['status'] ?? '' ) && in_array( 'provider_grid_span_submit', array_column( $grid_submit_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $grid_submit_css, 'width:33.333%' ) && str_contains( $grid_submit_css, '@media (max-width: 767px)' ) && str_contains( $grid_submit_css, 'width:100%' ), 'proven-grid-span-submit-transposes-to-responsive-provider-width', wp_json_encode( array( 'validation' => $grid_submit_validation, 'row' => $grid_submit_row ) ) );
	$grid_area_submit_form = $grid_submit_form;
	$grid_area_submit_form['layout_graph']['nodes'][1]['layout'] = array( 'area' => '2 / 1 / span 1 / span 4' );
	$grid_area_submit_form['layout_graph']['nodes'][1]['provenance'][0]['properties'] = array( 'grid-area' );
	$grid_area_submit_form['layout_graph']['variants'] = array();
	$grid_area_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_area_submit_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $grid_area_submit_row['status'] ?? '' ) && str_contains( (string) ( $grid_area_submit_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:33.333%' ) && ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $grid_area_submit_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'proven-grid-area-submit-transposes-to-provider-width-without-claiming-row-placement', wp_json_encode( $grid_area_submit_row ) );
	$full_width_grid_field_form = array(
		'forms' => array( array(
			'selector' => 'form.full-width-grid-field',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-shell' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array(
				$layout_node( 'form', array(), 'form' ),
				array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)', 'gap' => '1rem' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns', 'gap' ) ) ) ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'column' => 'span 12' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell input', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) ),
			) ),
		) ),
	);
	$full_width_grid_field_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = '100%';
	$full_width_grid_field_form['forms'][0]['layout_graph']['nodes'][1]['provenance'][0]['properties'][] = 'width';
	$full_width_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $full_width_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$full_width_grid_field_css = (string) ( $full_width_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( 'mapped' === ( $full_width_grid_field_row['status'] ?? '' ) && in_array( 'provider_fullspan_grid_child', array_column( $full_width_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $full_width_grid_field_css, 'display:grid;grid-template-columns:repeat(12, 1fr);gap:1rem;width:100%' ) && str_contains( $full_width_grid_field_css, 'grid-column:span 12' ), 'full-span-single-field-grid-retains-proven-tracks-and-native-child-placement', wp_json_encode( $full_width_grid_field_row ) );
	$fullspan_child_runtime = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( '<div class="grunion-field-email-wrap ssi-node-a1b2c3d4e5f6-wrap ssi-source-wrapper-0--source-grid-wrap ssi-source-fullspan-child--ssi-node-0f1e2d3c4b5a-wrap"><label>Email</label><input type="email"></div>' );
	$assert( '<div class="grunion-field-email-wrap ssi-node-a1b2c3d4e5f6-wrap"><div class="ssi-field-row source-grid"><label>Email</label><div class="ssi-node-0f1e2d3c4b5a-wrap"><input type="email"></div></div></div>' === $fullspan_child_runtime, 'full-span-grid-rebuilds-a-real-value-child-inside-the-source-grid-container', $fullspan_child_runtime );
	$partial_grid_field_form = $full_width_grid_field_form;
	$partial_grid_field_form['forms'][0]['layout_graph']['nodes'][2]['layout']['column'] = 'span 6';
	$partial_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $partial_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $partial_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'partial-span-field-grid-does-not-claim-full-width-provider-ownership', wp_json_encode( $partial_grid_field_row ) );
	$explicit_start_grid = $full_width_grid_field_form;
	$explicit_start_grid['forms'][0]['layout_graph']['nodes'][2]['layout']['column'] = '1 / span 12';
	$explicit_start_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $explicit_start_grid )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( in_array( 'provider_fullspan_grid_child', array_column( $explicit_start_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $explicit_start_row['provider_layout_overlay_css']['css'] ?? '', 'grid-column:1 / span 12' ), 'explicit-grid-start-and-full-span-preserve-native-child-placement' );
	$nested_grid_field_form = $full_width_grid_field_form;
	$nested_grid_field_form['forms'][0]['control_topology']['nodes'][1]['parent'] = 'wrapper-1';
	$nested_grid_field_form['forms'][0]['control_topology']['nodes'][1]['depth'] = 2;
	array_splice( $nested_grid_field_form['forms'][0]['control_topology']['nodes'], 1, 0, array( array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div' ) ) );
	$nested_grid_field_form['forms'][0]['layout_graph']['nodes'][2]['parent'] = 'wrapper-1';
	array_splice( $nested_grid_field_form['forms'][0]['layout_graph']['nodes'], 2, 0, array( array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'area' => '2 / 1 / span 1 / span 12' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'grid-area' ) ) ) ) ) );
	$nested_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_grid_field_css = (string) ( $nested_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$nested_grid_field_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'column_gap' => '1.5rem' ), 'precedence' => array( 'column-gap' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'column-gap' ) ) ) );
	$nested_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_grid_field_css = (string) ( $nested_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( in_array( 'provider_fullspan_grid_branch', array_column( $nested_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $nested_grid_field_css, 'repeat(12, 1fr)' ) && str_contains( $nested_grid_field_css, 'grid-area:2 / 1 / span 1 / span 12' ) && str_contains( $nested_grid_field_css, '@media (max-width: 48rem)' ) && str_contains( $nested_grid_field_css, 'column-gap:1.5rem' ), 'nested-single-field-grid-keeps-proven-wrapper-grid-placement-and-responsive-gap', wp_json_encode( $nested_grid_field_row ) );
	$multi_control_grid_field_form = $full_width_grid_field_form;
	$multi_control_grid_field_form['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' );
	array_splice( $multi_control_grid_field_form['forms'][0]['control_topology']['nodes'], 2, 0, array( array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 2 ) ) );
	$multi_control_grid_field_form['forms'][0]['layout_graph']['nodes'][] = array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'column' => 'span 12' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell input', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) );
	$multi_control_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $multi_control_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $multi_control_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'multi-control-full-span-grid-does-not-claim-single-field-provider-ownership', wp_json_encode( $multi_control_grid_field_row ) );
	$variant_grid_field_form = $full_width_grid_field_form;
	$variant_grid_field_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'columns' => 'repeat(6, 1fr)' ), 'precedence' => array( 'grid-template-columns' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'grid-template-columns' ) ) ) );
	$variant_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $variant_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'responsive-grid-variant-does-not-claim-static-full-width-provider-ownership', wp_json_encode( $variant_grid_field_row ) );

	$grid_track_form = array(
		'selector' => 'form.source-grid',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ), array( 'tag' => 'input', 'type' => 'text', 'name' => 'cancel_note', 'label' => 'Cancel note' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
		'layout_graph' => $v2_layout_graph( array(
			array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'source-grid' ) ), 'layout' => array( 'display' => 'grid', 'columns' => '1fr 155.4px' ), 'provenance' => array() ),
			array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'button', 'classes' => array() ), 'layout' => array( 'column' => '2' ), 'provenance' => array(), 'sizing' => array( 'kind' => 'grid_track', 'axis' => 'inline', 'container' => 'form', 'grid_column' => '2' ) ),
			array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'form', 'order' => 2, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'justify_self' => 'start' ), 'provenance' => array() ),
		) ),
		'presentation_graph' => array( 'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(), 'controls' => array( array( 'index' => 1, 'control' => array( 'styles' => array( 'padding' => '5%' ), 'provenance' => array() ) ) ) ),
	);
	$grid_track_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_track_form ) ) );
	$grid_track_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_track_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_track_css        = (string) ( $grid_track_row['provider_layout_overlay_css']['css'] ?? '' );
	$source_control_width  = 155.4;
	$source_padding        = $source_control_width * 0.05;
	$content_control_hook  = 'ssi-node-' . substr( hash( 'sha256', 'ssi-form-' . substr( hash( 'sha256', "\nform.source-grid" ), 0, 12 ) . "\ncontrol-2" ), 0, 12 );
	preg_match( '/width:([0-9.]+)px/', $grid_track_css, $provider_width );
	$provider_padding = isset( $provider_width[1] ) ? (float) $provider_width[1] * 0.05 : 0.0;
	$assert( empty( $grid_track_validation['errors'] ) && 'mapped' === ( $grid_track_row['status'] ?? '' ) && in_array( 'provider_grid_track_control_width', array_column( $grid_track_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && 1 === substr_count( $grid_track_css, 'width:155.4px;flex:0 1 auto' ) && str_contains( $grid_track_css, 'padding:5%' ) && ! str_contains( $grid_track_css, '.' . $content_control_hook . '{width:' ) && abs( $source_padding - $provider_padding ) < 0.001, 'grid-track-submit-keeps-source-width-and-percentage-padding-when-provider-layout-differs', wp_json_encode( array( 'css' => $grid_track_css, 'source_width' => $source_control_width, 'source_padding' => $source_padding, 'provider_padding' => $provider_padding ) ) );
	$fixed_control_form = array(
		'forms' => array( array(
			'selector' => 'form.fixed-control',
			'controls' => array( array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ), array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
			'layout_graph' => $v2_layout_graph( array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'fixed-control' ) ), 'layout' => array(), 'provenance' => array() ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'textarea', 'classes' => array() ), 'layout' => array( 'width' => '611px' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ) ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ) ),
				array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'form', 'order' => 2, 'source' => array( 'tag' => 'button', 'classes' => array() ), 'layout' => array( 'width' => '280px', 'flex_grow' => '1' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width', 'flex-grow' ) ) ) ),
			) ),
		) ),
	);
	$fixed_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $fixed_control_form );
	$fixed_control_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $fixed_control_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$fixed_control_css        = (string) ( $fixed_control_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $fixed_control_validation['errors'] ) && str_contains( $fixed_control_css, '{width:611px}' ) && 1 === preg_match( '/-wrap\{flex:0 1 auto\}/', $fixed_control_css ) && str_contains( $fixed_control_css, 'width:100%' ) && ! str_contains( $fixed_control_css, 'width:100%;flex:0 1 auto' ) && str_contains( $fixed_control_css, 'width:280px;flex-grow:1' ) && ! str_contains( $fixed_control_css, 'width:280px;flex-grow:1;flex:0 1 auto' ), 'fixed-provenance-width-neutralizes-jetpack-wrapper-flex-without-changing-fluid-or-authored-flex-fields', $fixed_control_css );
	$responsive_fixed_control_form = $fixed_control_form;
	$responsive_fixed_control_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = '100%';
	$responsive_fixed_control_condition = array( 'kind' => 'media', 'query' => '(min-width: 769px)' );
	$responsive_fixed_control_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'control-0', 'condition' => $responsive_fixed_control_condition, 'layout_patch' => array( 'width' => '611px' ), 'precedence' => array( 'width' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.fixed-control textarea', 'condition' => $responsive_fixed_control_condition, 'properties' => array( 'width' ) ) ) );
	$responsive_fixed_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_fixed_control_form );
	$responsive_fixed_control_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_fixed_control_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$responsive_fixed_control_css        = (string) ( $responsive_fixed_control_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $responsive_fixed_control_validation['errors'] ) && str_contains( $responsive_fixed_control_css, '@media (min-width: 769px)' ) && str_contains( $responsive_fixed_control_css, '@media (min-width: 769px){.' ) && 1 === preg_match( '/@media \(min-width: 769px\)\{[^{}]+-wrap\{flex:0 1 auto\}\}/', $responsive_fixed_control_css ) && ! str_contains( $responsive_fixed_control_css, 'width:100%;flex:0 1 auto' ), 'responsive-fixed-provenance-width-neutralizes-jetpack-wrapper-flex-without-changing-base-fluid-width', $responsive_fixed_control_css );
	$responsive_grid_track_form                                      = $grid_track_form;
	$responsive_grid_track_condition                                 = array( 'kind' => 'media', 'query' => '(max-width: 48rem)' );
	$responsive_grid_track_form['layout_graph']['variants'][]        = array( 'node' => 'form', 'condition' => $responsive_grid_track_condition, 'layout_patch' => array( 'columns' => '1fr' ), 'precedence' => array( 'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 1, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.source-grid', 'condition' => $responsive_grid_track_condition, 'properties' => array( 'grid-template-columns' ) ) ) );
	$responsive_grid_track_validation                                = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $responsive_grid_track_form ) ) );
	$responsive_grid_track_row                                       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_grid_track_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$responsive_grid_track_css                                       = (string) ( $responsive_grid_track_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $responsive_grid_track_validation['errors'] ) && ! in_array( 'provider_grid_track_control_width', array_column( $responsive_grid_track_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! str_contains( $responsive_grid_track_css, 'width:155.4px' ), 'grid-track-sizing-skips-conditional-track-or-placement-changes', wp_json_encode( array( 'validation' => $responsive_grid_track_validation, 'row' => $responsive_grid_track_row ) ) );

	// V2 percentage facts replace only a complete, provenance-backed sibling row.
