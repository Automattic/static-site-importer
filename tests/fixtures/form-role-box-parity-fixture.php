<?php
/**
 * Seed source forms that together emit every declared form overlay part, and
 * export, per form, what the browser role-box parity test needs to render both
 * surfaces of each part:
 *
 * - the materialized `style.css` and `editor-style.css` (the source CSS plus
 *   the provider overlay, assembled by `Stylesheet_Materializer::stylesheet_writes()`);
 * - the front-end HTML: a model of Jetpack Forms' server render of the seeded
 *   block markup, passed through this plugin's real provider runtime filters
 *   in their registered priority order;
 * - the parsed block tree, from which the test builds Jetpack's editor DOM;
 * - every emitted part instance: its front-end selector from the recorded
 *   target map and its editor selectors derived through the declared editor
 *   realisation (`editor_surface_part()` and the projection's own selector
 *   derivation);
 * - the declared parts, enumerated exactly as
 *   `tests/smoke-form-overlay-editor-parts.php` enumerates them.
 *
 * The Jetpack front-end model mirrors projects/packages/forms/src/contact-form/
 * class-contact-form.php (form container, form, error wrapper),
 * class-contact-form-field.php (render_field shell, render_label,
 * render_input_field, render_textarea_field, render_select_field,
 * render_telephone_field, render_radio_field, render_checkbox_field,
 * get_error_div) and class-contact-form-plugin.php (block attributes to
 * shortcode attributes: labelclasses, inputclasses, optionclasses,
 * fieldwrapperclasses) at Automattic/jetpack 9c19e18ca8f5. Interactivity
 * attributes and hidden inputs that render no box are omitted.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	require_once __DIR__ . '/form-overlay-declared-parts.php';
	// Jetpack's own native-topology projection and submit preparation use the
	// core HTML API, as they do in WordPress.
	require_once __DIR__ . '/core-html-api.php';
	// The helpers the tag processor's set_attribute() calls (WordPress 7.x and
	// 6.8 respectively); core defines them in wp-includes/utf8.php,
	// kses.php and formatting.php.
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ): string {
			return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8', false );
		}
	}
	if ( ! function_exists( 'wp_has_noncharacters' ) ) {
		function wp_has_noncharacters( string $text ): bool {
			return 1 === preg_match( '~\xEF(?:\xB7[\x90-\xAF]|\xBF[\xBE\xBF])|(?:\xF0[\x9F\xAF\xBF]|[\xF1-\xF3][\x8F\x9F\xAF\xBF]|\xF4\x8F)\xBF[\xBE\xBF]~', $text );
		}
	}
	if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
		function wp_kses_uri_attributes(): array {
			return array( 'action', 'href', 'src', 'formaction' );
		}
	}
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;

	/**
	 * Source forms. No single source emits every part, so each part is measured
	 * on the form that emits it.
	 *
	 * - `controls`: text (required, with a source required marker), email,
	 *   plain tel, select, a textarea inside a source shell, a consent checkbox
	 *   whose source label wraps the input, a radio group in a fieldset, and a
	 *   submit button with a caption span.
	 * - `form_box`: a padded, filled source form placed in a page grid, with a
	 *   source submit row.
	 * - `field_list`: a source field list that wraps the fields but not the submit.
	 * - `native_form`: a page-grid form whose flex column the provider form keeps
	 *   natively.
	 * - `country`: a phone field with a source country-picker trigger.
	 */
	$sources = array(
		'controls'    => '<style>*,::before,::after{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}'
			. '.f{display:grid;gap:20px;padding:24px;background-color:#f4f4f5}'
			. '.row>:not([hidden])~:not([hidden]){margin-top:6px}'
			. '.lbl{font-size:14px;font-weight:600;color:#18181b}'
			. '.req{color:#dc2626;font-size:12px;margin-left:2px}'
			. '.ctl{width:100%;height:44px;padding:4px 12px;border:1px solid #71717a;border-radius:6px;background-color:#ffffff;font-size:16px}'
			. '.area{width:100%;padding:12px;border:1px solid #71717a;background-color:#ffffff;font-size:16px}'
			. '.shell{background-color:#e4e4e7;border:2px solid #a1a1aa;padding:6px}'
			. '.consent{display:flex;align-items:center;gap:8px;font-size:14px;padding:4px 0}'
			. '.consent input{width:18px;height:18px}'
			. 'fieldset{border:1px solid #d4d4d8;padding:12px;margin:0}'
			. '.opt{display:block;font-size:15px;padding:2px 0}'
			. '.btn{background-color:#2563eb;color:#fff;padding:10px 20px;border:0;border-radius:6px;font-size:15px}'
			. '.btn .cap{font-weight:700;letter-spacing:1px}</style>'
			. '<main><form class="f">'
			. '<div class="row"><label for="name" class="lbl">Name <span class="req" aria-hidden="true">*</span></label><input id="name" name="name" type="text" required class="ctl"></div>'
			. '<div class="row"><label for="email" class="lbl">Email</label><input id="email" name="email" type="email" class="ctl"></div>'
			. '<div class="row"><label for="phone" class="lbl">Phone</label><input id="phone" name="phone" type="tel" class="ctl"></div>'
			. '<div class="row"><label for="topic" class="lbl">Topic</label><select id="topic" name="topic" class="ctl"><option>One</option><option>Two</option></select></div>'
			. '<div><label for="message" class="lbl">Message</label><div class="shell"><textarea id="message" name="message" rows="4" class="area"></textarea></div></div>'
			. '<label class="consent"><input type="checkbox" name="consent"> I agree to the terms</label>'
			. '<fieldset><legend class="lbl">Plan</legend><label class="opt"><input type="radio" name="plan" value="a"> Basic</label><label class="opt"><input type="radio" name="plan" value="b"> Pro</label></fieldset>'
			. '<button type="submit" class="btn"><span class="cap">Send</span></button>'
			. '</form></main>',
		'form_box'    => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.page{display:grid;grid-template-columns:1fr 1fr;gap:24px}.form{grid-column:2;padding:20px;background:#eee;display:grid;gap:10px}label{display:block}input{padding:8px;border:1px solid #999}.actions{padding-top:12px}.actions button{padding:10px 18px;background:#333;color:#fff;border:0}</style>'
			. '<main class="page"><div>Intro</div><form class="form"><div><label for="e">Email</label><input id="e" name="e" type="email"></div><div><label for="n">Name</label><input id="n" name="n"></div><div class="actions"><button type="submit">Send</button></div></form></main>',
		'field_list'  => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.field-list{display:flex;flex-direction:column;gap:16px}.name-row{border:0;padding:0;margin:0}label{display:block;margin-bottom:4px}input{padding:8px;border:1px solid #a1a1aa}</style>'
			. '<form><div class="field-list"><fieldset class="name-row"><legend>Name</legend><div class="field"><label for="first">First name</label><input id="first" name="first"></div><div class="field"><label for="last">Last name</label><input id="last" name="last"></div></fieldset><div class="field"><label for="email">Email</label><input id="email" name="email" type="email"></div></div><button type="submit">Send</button></form>',
		'native_form' => '<style>body{margin:0;font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.page{display:grid;grid-template-columns:1fr 1fr;gap:24px}.form{grid-column:2;display:flex;flex-direction:column;gap:12px;padding:20px;background:#eee}label{display:block;margin-bottom:4px}input{width:100%;padding:8px;border:1px solid #999}.actions{padding-top:8px;display:flex}.actions button{padding:10px 18px;background:#333;color:#fff;border:0}</style>'
			. '<main class="page"><div>Intro</div><form class="form"><div><label for="e">Email</label><input id="e" name="e" type="email"></div><div class="actions"><button type="submit">Send</button></div></form></main>',
		'country'     => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.sourcegroup{display:flex;align-items:center;gap:8px}.globe{width:24px}.chevron{width:16px}.ph{display:flex;align-items:center;border:1px solid #999;background:#fff}.ph button{position:relative;flex-shrink:0;padding:6px 10px;border:0;background:#fafafa}.ph input{flex:1;border:0;padding:8px}</style>'
			. '<main><form><div class="ph"><button type="button" aria-haspopup="listbox" aria-label="Phone country selector"><span class="sourcegroup"><svg class="globe" width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><svg class="chevron" width="16" height="16" viewBox="0 0 16 16"><path d="M4 7l4 4 4-4"/></svg></span></button><input type="tel" name="phone"></div><button type="submit">Send</button></form></main>',
	);

	/**
	 * Parse serialized block markup into `{name, attrs, innerBlocks, innerHTML}`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function ssi_role_box_parse_blocks( string $markup ): array {
		preg_match_all( '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*)(?:\s+(\{.*?\}))?\s+(\/)?-->/s', $markup, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$root  = array( 'innerBlocks' => array() );
		$stack = array( &$root );
		foreach ( $tokens as $token ) {
			$closer = '' !== $token[1][0];
			$name   = $token[2][0];
			$end    = $token[0][1] + strlen( $token[0][0] );
			if ( $closer ) {
				$block              = &$stack[ count( $stack ) - 1 ];
				$block['innerHTML'] = substr( $markup, $block['start'], $token[0][1] - $block['start'] );
				unset( $block['start'], $block );
				array_pop( $stack );
				continue;
			}
			$attrs  = isset( $token[3] ) && '' !== $token[3][0] ? json_decode( $token[3][0], true ) : array();
			$parent = &$stack[ count( $stack ) - 1 ];
			$parent['innerBlocks'][] = array(
				'name'        => $name,
				'attrs'       => is_array( $attrs ) ? $attrs : array(),
				'innerBlocks' => array(),
				'innerHTML'   => '',
				'start'       => $end,
			);
			$child = &$parent['innerBlocks'][ count( $parent['innerBlocks'] ) - 1 ];
			unset( $parent );
			if ( isset( $token[4] ) && '' !== $token[4][0] ) {
				unset( $child['start'], $child );
				continue;
			}
			$stack[] = &$child;
			unset( $child );
		}
		return $root['innerBlocks'];
	}

	function ssi_role_box_esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}

	/** Block-support classes and inline styles Jetpack derives for an inner block. */
	function ssi_role_box_block_support( array $attrs ): array {
		$style = '';
		if ( is_string( $attrs['style']['border']['style'] ?? null ) ) {
			$style .= 'border-style:' . $attrs['style']['border']['style'] . ';';
		}
		return array( 'class' => trim( (string) ( $attrs['className'] ?? '' ) ), 'style' => $style );
	}

	/** Jetpack's per-field input error region (get_error_div). */
	function ssi_role_box_error_div( string $id, string $type ): string {
		return '<div id="' . ssi_role_box_esc( $id . '-' . $type . '-error' ) . '" class="contact-form__input-error"><span class="contact-form__warning-icon" aria-hidden="true"><i></i></span><span data-wp-text="context.fieldErrorMessage"></span></div>';
	}

	/**
	 * Model of Jetpack's server render of one field block, then the field-html
	 * filters the provider runtime registers (priority 10, 20, 30).
	 */
	function ssi_role_box_render_field( array $block ): string {
		$type        = substr( $block['name'], strlen( 'jetpack/field-' ) );
		$attrs       = $block['attrs'];
		$id          = (string) ( $attrs['id'] ?? '' );
		$required    = ! empty( $attrs['required'] );
		$class       = trim( (string) ( $attrs['className'] ?? '' ) );
		$label       = '';
		$required_tx = '(required)';
		$label_cls   = '';
		$label_shown = true;
		$input_cls   = '';
		$input_style = '';
		$option_cls  = '';
		$options     = array();
		$show_cc     = ! empty( $attrs['showCountrySelector'] );
		// Contact_Form_Plugin::block_attributes_to_shortcode_attributes().
		foreach ( $block['innerBlocks'] as $inner ) {
			$support = ssi_role_box_block_support( $inner['attrs'] );
			if ( 'jetpack/label' === $inner['name'] ) {
				$label       = (string) ( $inner['attrs']['label'] ?? '' );
				$required_tx = is_string( $inner['attrs']['requiredText'] ?? null ) ? $inner['attrs']['requiredText'] : $required_tx;
				$label_cls   = trim( 'wp-block-jetpack-label ' . $support['class'] );
				$label_shown = false !== ( $inner['attrs']['metadata']['blockVisibility'] ?? null );
			} elseif ( 'jetpack/input' === $inner['name'] || 'jetpack/phone-input' === $inner['name'] ) {
				$input_cls   = trim( 'wp-block-jetpack-input ' . $support['class'] );
				$input_style = $support['style'];
			} elseif ( 'jetpack/option' === $inner['name'] ) {
				$label       = (string) ( $inner['attrs']['label'] ?? '' );
				$option_cls  = trim( 'wp-block-jetpack-option ' . $support['class'] );
			} elseif ( 'jetpack/options' === $inner['name'] ) {
				foreach ( $inner['innerBlocks'] as $option ) {
					$option_support = ssi_role_box_block_support( $option['attrs'] );
					$options[]      = array(
						'label' => trim( (string) ( $option['attrs']['label'] ?? '' ) ),
						'class' => trim( $option_support['class'] . ' wp-block-jetpack-option' ),
					);
				}
			}
		}
		$wrapper_classes = 'wp-block-jetpack-field-' . $type;
		// Contact_Form_Field::__construct(): input and option classes join the field class.
		$class = trim( $class . ( '' !== $input_cls ? ' ' . $input_cls : '' ) . ( '' !== $option_cls ? ' ' . $option_cls : '' ) );
		// Contact_Form_Field::render_field().
		$class       .= ' grunion-field';
		$field_class = "class='" . $type . ' ' . ssi_role_box_esc( $class ) . "' ";
		$wrap        = implode( '-wrap ', array_filter( explode( ' ', $class ) ) ) . '-wrap';
		if ( '' === $label && ! $required ) {
			$wrap .= ' no-label';
		}
		$required_mark = static fn(): string => $required ? '<span class="grunion-label-required" aria-hidden="true">' . $required_tx . '</span>' : '';
		$render_label  = static function ( string $label_type, string $for ) use ( $label_shown, $label_cls, $label, $required_mark ): string {
			if ( ! $label_shown ) {
				return '';
			}
			$classes = 'grunion-field-label' . ( '' !== $label_type ? ' ' . $label_type : '' ) . ( '' !== $label_cls ? ' ' . $label_cls : '' );
			return "<label for='" . ssi_role_box_esc( $for ) . "' class=\"" . ssi_role_box_esc( $classes ) . '">' . $label . $required_mark() . "</label>\n";
		};
		$style_attr = '' !== $input_style ? ' style="' . ssi_role_box_esc( $input_style ) . '"' : '';
		$html       = "\n<div style=\"\" class='" . ssi_role_box_esc( $wrapper_classes . ' grunion-field-' . $type . '-wrap ' . $wrap ) . "' data-jp-field-id='" . ssi_role_box_esc( $id ) . "'>\n";
		switch ( $type ) {
			case 'telephone':
				if ( ! $show_cc ) {
					$html .= $render_label( 'telephone', $id ) . "<input type='tel' name='" . ssi_role_box_esc( $id ) . "' id='" . ssi_role_box_esc( $id ) . "' " . $field_class . ( $required ? "required='true' " : '' ) . $style_attr . " />\n" . ssi_role_box_error_div( $id, 'tel' );
					break;
				}
				$class_names = trim( $type . ' ' . $class );
				$html       .= $render_label( 'phone', $id . '-number' )
					. '<div class="jetpack-field__input-phone-wrapper  ' . ssi_role_box_esc( $class_names ) . '"' . ( '' !== $input_style ? ' style="' . ssi_role_box_esc( $input_style ) . '"' : ' style=""' ) . ' data-wp-context=\'{"fieldId":"' . ssi_role_box_esc( $id ) . '","defaultCountry":"","showCountrySelector":true}\'>'
					. '<div class="jetpack-field__input-prefix"><div class="jetpack-custom-combobox">'
					. '<button class="jetpack-combobox-trigger" type="button"><span class="jetpack-combobox-selected wp-exclude-emoji">&#8203;</span>'
					. '<span class="jetpack-combobox-trigger-arrow"><svg width="10" height="6" viewBox="0 0 10 6" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
					. '<span class="jetpack-combobox-selected"></span></button>'
					. '<div class="jetpack-combobox-dropdown " style="" hidden><input class="jetpack-combobox-search" type="text"><div class="jetpack-combobox-options"></div></div>'
					. '</div></div>'
					. '<input class="jetpack-field__input-element" type="tel" id="' . ssi_role_box_esc( $id . '-number' ) . '" name="' . ssi_role_box_esc( $id . '-number' ) . '" />'
					. '<input type="hidden" id="' . ssi_role_box_esc( $id ) . '" name="' . ssi_role_box_esc( $id ) . '" />'
					. '</div>' . ssi_role_box_error_div( $id, 'telephone' );
				break;
			case 'textarea':
				$html .= $render_label( 'textarea', 'contact-form-comment-' . $id ) . "<textarea style='" . ssi_role_box_esc( $input_style ) . "' name='" . ssi_role_box_esc( $id ) . "' id='contact-form-comment-" . ssi_role_box_esc( $id ) . "' rows='20' " . $field_class . ( $required ? 'required' : '' ) . "></textarea>\n" . ssi_role_box_error_div( $id, 'textarea' );
				break;
			case 'select':
				$select_class = preg_replace( "/class=['\"]([^'\"]*)['\"]/", 'class="contact-form__select-wrapper $1"', $field_class );
				$html        .= $render_label( 'select', $id ) . '<div ' . $select_class . " style='" . ssi_role_box_esc( $input_style ) . "'>\t<span class='contact-form__select-element-wrapper'><select name='" . ssi_role_box_esc( $id ) . "' id='" . ssi_role_box_esc( $id ) . "'>\n\t\t<option value=''>Select an option</option>\n";
				foreach ( (array) ( $attrs['options'] ?? array() ) as $option ) {
					$html .= "\t\t<option value='" . ssi_role_box_esc( (string) $option ) . "'>" . ssi_role_box_esc( (string) $option ) . "</option>\n";
				}
				$html .= "\t</select><span class='jetpack-field-dropdown__icon'></span></span>\n</div>\n" . ssi_role_box_error_div( $id, 'select' );
				break;
			case 'checkbox':
				$checkbox_label = 'grunion-field-label checkbox' . ( '' !== $option_cls ? ' ' . $option_cls : '' );
				$html          .= "<div class='contact-form__checkbox-wrap' style='' ><input id='" . ssi_role_box_esc( $id ) . "' type='checkbox' name='" . ssi_role_box_esc( $id ) . "' value='Yes' " . $field_class . ( $required ? "required aria-required='true'" : '' ) . "/> \n<label for='" . ssi_role_box_esc( $id ) . "' class='" . ssi_role_box_esc( $checkbox_label ) . "' style=''>" . $label . $required_mark() . "</label>\n<div class='clear-form'></div>\n</div>" . ssi_role_box_error_div( $id, 'checkbox' );
				break;
			case 'radio':
				$legend = $label_shown ? '<legend class="' . ssi_role_box_esc( 'grunion-field-label' . ( '' !== $label_cls ? ' ' . $label_cls : '' ) ) . '"><span class="grunion-label-text">' . $label . '</span>' . ( $required ? '<span class="grunion-label-required">' . $required_tx . '</span>' : '' ) . "</legend>\n" : '';
				$html  .= "<fieldset id='" . ssi_role_box_esc( $id . '-label' ) . "' class='jetpack-field-multiple__fieldset' >" . $legend . "<div class='grunion-radio-options wp-block-jetpack-options' style=''>";
				foreach ( $options as $option ) {
					$value = $option['label'];
					$html .= "<label class='contact-form-field " . ssi_role_box_esc( $option['class'] ) . "'><input id='" . ssi_role_box_esc( $id . '-' . $value ) . "' type='radio' name='" . ssi_role_box_esc( $id ) . "' value='" . ssi_role_box_esc( $value ) . "' " . $field_class . ( $required ? "required aria-required='true'" : '' ) . "/> <span class='grunion-radio-label radio'><span class='grunion-field-text'>" . ssi_role_box_esc( $value ) . '</span></span></label>';
				}
				$html .= '</div>' . ssi_role_box_error_div( $id, 'radio' ) . '</fieldset>';
				break;
			default:
				$html .= $render_label( $type, $id ) . "<input type='" . ( 'email' === $type ? 'email' : 'text' ) . "' name='" . ssi_role_box_esc( $id ) . "' id='" . ssi_role_box_esc( $id ) . "' " . $field_class . ( $required ? "required='true' aria-required='true' " : '' ) . $style_attr . " />\n" . ssi_role_box_error_div( $id, $type );
		}
		$html .= "\t</div>\n";
		// Contact_Form_Field::render(): grunion_contact_form_field_html, in the
		// runtime's registered priority order (10, 20, 30).
		$html = Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $html );
		$html = Static_Site_Importer_Provider_Form_Runtime_V1::project_empty_country_visual_state( $html );
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( $html );
	}

	/** Model of a core/button render, with its render_block filter. */
	function ssi_role_box_render_button( array $block ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $block['innerHTML'], array( 'attrs' => $block['attrs'] ) );
	}

	/**
	 * Core layout support's classes for a block's layout attribute
	 * (wp_render_layout_support_flag); the matching rules come from global styles.
	 */
	function ssi_role_box_layout_classes( string $block_slug, array $layout, string $default ): string {
		$type    = (string) ( $layout['type'] ?? $default );
		$classes = array();
		// wp_render_layout_support_flag(): the legacy classes come from the
		// block's own layout attribute only, never the block.json default.
		if ( ! empty( $layout['orientation'] ) ) {
			$classes[] = 'is-' . $layout['orientation'];
		}
		if ( ! empty( $layout['justifyContent'] ) ) {
			$classes[] = 'is-content-justification-' . $layout['justifyContent'];
		}
		if ( 'nowrap' === ( $layout['flexWrap'] ?? '' ) ) {
			$classes[] = 'is-nowrap';
		}
		$classes[] = 'is-layout-' . $type;
		$classes[] = 'wp-block-' . $block_slug . '-is-layout-' . $type;
		return implode( ' ', $classes );
	}

	/** Render one inner block of the form. */
	function ssi_role_box_render_block( array $block ): string {
		if ( str_starts_with( $block['name'], 'jetpack/field-' ) ) {
			return ssi_role_box_render_field( $block );
		}
		if ( 'core/button' === $block['name'] ) {
			return ssi_role_box_render_button( $block );
		}
		if ( 'core/group' === $block['name'] ) {
			$inner = '';
			foreach ( $block['innerBlocks'] as $child ) {
				$inner .= ssi_role_box_render_block( $child );
			}
			$class = trim( 'wp-block-group ' . (string) ( $block['attrs']['className'] ?? '' ) . ' ' . ssi_role_box_layout_classes( 'group', (array) ( $block['attrs']['layout'] ?? array() ), 'flow' ) );
			return '<div class="' . ssi_role_box_esc( $class ) . '">' . $inner . '</div>';
		}
		fwrite( STDERR, 'unmodelled block ' . $block['name'] . "\n" );
		exit( 1 );
	}

	/** Model of Jetpack's form render (Contact_Form::parse) plus the form render_block filters. */
	function ssi_role_box_render_form( array $form ): string {
		$class_name = trim( (string) ( $form['attrs']['className'] ?? '' ) );
		$body       = '';
		foreach ( $form['innerBlocks'] as $inner ) {
			$body .= ssi_role_box_render_block( $inner );
		}
		// Contact_Form::parse(): the form error wrapper precedes the first core button.
		$error = '<div class="contact-form__error"><span class="contact-form__warning-icon" aria-hidden="true"><i></i></span><span class="contact-form__error-message" tabindex="-1"></span><ul aria-label="Form errors"></ul></div><div class="contact-form__error" tabindex="-1"></div>';
		$at    = strpos( $body, '<div class="wp-block-button' );
		$body  = false === $at ? $body . $error : substr( $body, 0, $at ) . $error . substr( $body, $at );
		// The saved block wrapper is the form body; layout support (block.json's
		// default flex layout) adds its classes to that `wp-block-jetpack-contact-form` element.
		if ( isset( $form['attrs']['layout'] ) ) {
			fwrite( STDERR, "unmodelled contact-form layout attribute\n" );
			exit( 1 );
		}
		// The block.json default layout (flex, justifyContent left, verticalAlignment
		// top) makes layout support add a container class with generated CSS; the
		// test serves that CSS (CORE_LAYOUT_CSS) under this fixed class name.
		$list = '<div class="wp-block-jetpack-contact-form ' . ssi_role_box_esc( $class_name ) . ' ' . ssi_role_box_layout_classes( 'jetpack-contact-form', array(), 'flex' ) . ' wp-container-jetpack-contact-form-is-layout-default">' . $body . '</div>';
		$html = "<div data-test='contact-form' id='contact-form-1' class='jetpack-contact-form-container '>\n"
			. "<form action='#' id='jp-form-1' method='post' class='contact-form commentsblock jetpack-contact-form__form has-no-jetpack-form-layout' novalidate >\n"
			. "<input type='hidden' name='jetpack_contact_form_jwt' value='' />\n"
			. $list
			. "\t\t<input type='hidden' name='contact-form-id' value='1' />\n"
			. "</form>\n</div>";
		// render_block_jetpack/contact-form filters at priorities 5, 6 and 10.
		$block = array( 'attrs' => $form['attrs'] );
		$html  = Static_Site_Importer_Provider_Form_Runtime_V1::project_form_container_placement( $html, $block );
		$html  = Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper( $html, $block );
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset( $html, $block );
	}

	/** A destination's identity without its part metadata. */
	function ssi_role_box_destination_key( array $destination ): string {
		unset( $destination['part'], $destination['editor'], $destination['class'] );
		return (string) wp_json_encode( $destination );
	}

	/**
	 * Every part instance the recorded target map emits, with its front-end
	 * selector and derived editor selectors.
	 *
	 * Layout targets carry their part through their node kind and selector
	 * shape, as `provider_layout_target_map()` assigns it. Presentation
	 * destinations recover their declared part by matching the recorded
	 * destination against the descriptor enumeration at the same control index.
	 */
	function ssi_role_box_instances( array $map ): array {
		$scope           = substr( (string) $map['scope'], 1 );
		$editor_selector = new ReflectionMethod( Static_Site_Importer_Form_Layout_Projection::class, 'editor_selector' );
		$instances = array();
		$add       = static function ( string $part, array $entry, string $origin, ?array $declared = null ) use ( &$instances, $editor_selector, $scope ): void {
			$surface   = Static_Site_Importer_Form_Layout_Projection::editor_surface_part( array( 'part' => $part ) + ( isset( $declared['editor'] ) ? array( 'editor' => $declared['editor'] ) : array() ) + $entry );
			$selectors = array();
			if ( empty( $surface['drop'] ) ) {
				$selectors[] = $editor_selector->invoke( null, $entry['selector'], $surface, '.' . $scope );
				if ( isset( $surface['split'] ) ) {
					// The split box destination keeps the front-end selector.
					$selectors[] = $entry['selector'];
				}
			}
			$instances[] = array(
				'part'      => $part,
				'origin'    => $origin,
				'front'     => $entry['selector'],
				'editor'    => $selectors,
				'dropped'   => ! empty( $surface['drop'] ),
				'resets'    => $entry['resets'] ?? array(),
			);
		};
		foreach ( $map['targets'] as $target ) {
			$node     = (string) $target['node'];
			$selector = (string) $target['selector'];
			$native   = str_contains( $selector, 'ssi-native-form-topology' );
			if ( 'form' === $node ) {
				$part = $native ? 'native_form' : 'form';
			} elseif ( 'form-box' === $node ) {
				$part = $native ? 'native_form_box' : 'form_box';
			} elseif ( 'field-list' === $node ) {
				$part = 'field_list';
			} elseif ( preg_match( '/^field-[0-9]+$/D', $node ) ) {
				$part = 'field';
			} elseif ( str_ends_with( $selector, ' > .wp-block-button__link' ) ) {
				$part = 'submit_link_box';
			} elseif ( preg_match( '/ \.ssi-node-[a-f0-9]{12}-wrap$/D', $selector ) ) {
				$part = 'wrap';
			} else {
				$part = 'box';
			}
			$add( $part, $target, $node );
		}
		foreach ( $map['presentation_targets'] as $target ) {
			$lookup = array();
			foreach ( ssi_editor_parts_descriptor_destinations( $scope, (int) $target['index'] ) as $destination ) {
				$lookup[ ssi_role_box_destination_key( $destination ) ][ $destination['part'] ] = $destination;
			}
			foreach ( $target['destinations'] as $destination ) {
				if ( preg_match( '/-destination-country-trigger$/D', (string) $destination['selector'] ) ) {
					// The phone country trigger is the only destination the
					// descriptor builder does not emit; the target map adds it.
					$parts = array( 'country_trigger' );
				} else {
					$parts = array_keys( $lookup[ ssi_role_box_destination_key( $destination ) ] ?? array() );
				}
				if ( 1 !== count( $parts ) ) {
					fwrite( STDERR, 'ambiguous or unknown destination part for ' . $destination['selector'] . ': ' . implode( ',', $parts ) . "\n" );
					exit( 1 );
				}
				$add( $parts[0], $destination, 'control-' . $target['index'], $lookup[ ssi_role_box_destination_key( $destination ) ][ $parts[0] ] ?? null );
			}
		}
		return $instances;
	}

	$forms = array();
	foreach ( $sources as $name => $html ) {
		$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
		$fallback = $compiled['fallbacks'][0] ?? array();
		if ( 'country' === $name ) {
			// Producer contract: a telephone control the producer classifies as a
			// phone composite (a country picker beside the number input) arrives
			// as type `phone`; that is what selects Jetpack's country selector.
			foreach ( $fallback['controls'] ?? array() as $index => $control ) {
				if ( 'tel' === ( $control['type'] ?? null ) ) {
					$fallback['controls'][ $index ]['type'] = 'phone';
				}
			}
		}
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
		$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
		if ( 'mapped' !== ( $row['status'] ?? null ) ) {
			fwrite( STDERR, "source form {$name} did not map: " . wp_json_encode( array( 'status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null, 'losses' => $row['form_receipt_unaccepted_losses'] ?? null ) ) . "\n" );
			exit( 1 );
		}
		Static_Site_Importer_Provider_Form_Runtime_V1::configure_visual_states( isset( $row['form_visual_state'] ) ? array( $row['form_visual_state'] ) : array() );
		preg_match_all( '/<style>(.*?)<\/style>/s', $html, $styles );
		$writes   = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Role Box Parity', implode( "\n", $styles[1] ), array(), array(), array( $row['provider_layout_overlay_css'] ) );
		$blocks   = ssi_role_box_parse_blocks( (string) $row['block_markup'] );
		$forms[]  = array(
			'name'      => $name,
			'scope'     => substr( (string) $row['provider_layout_target_map']['scope'], 1 ),
			'blocks'    => $blocks,
			'frontHtml' => ssi_role_box_render_form( $blocks[0] ),
			'css'       => $writes['/theme/style.css'],
			'editorCss' => $writes['/theme/assets/css/editor-style.css'],
			'instances' => ssi_role_box_instances( $row['provider_layout_target_map'] ),
		);
	}
	$declared = array();
	foreach ( ssi_editor_parts_declared( '.ssi-form-000000000000' ) as $part => $surface ) {
		$declared[ $part ] = array( 'dropped' => ! empty( $surface['drop'] ) );
	}
	echo (string) wp_json_encode( array( 'declared' => $declared, 'forms' => $forms ), JSON_UNESCAPED_SLASHES );
}
