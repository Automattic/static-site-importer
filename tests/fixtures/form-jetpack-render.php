<?php
/**
 * A model of Jetpack Forms' server render of seeded form block markup, passed
 * through this plugin's real provider runtime filters in their registered
 * priority order. Shared by the form browser fixtures.
 *
 * The model mirrors projects/packages/forms/src/contact-form/
 * class-contact-form.php (form container, form, error wrapper),
 * class-contact-form-field.php (render_field shell, render_label,
 * render_input_field, render_textarea_field, render_select_field,
 * render_telephone_field, render_radio_field, render_checkbox_field,
 * get_error_div) and class-contact-form-plugin.php (block attributes to
 * shortcode attributes) at Automattic/jetpack 9c19e18ca8f5. Interactivity
 * attributes and hidden inputs that render no box are omitted.
 *
 * Requires `form-seeder-cli-stubs.php` to be loaded first.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI test helper: stubs WordPress symbols outside a WordPress runtime.

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
	// Contact_Form_Field::__construct(): without an author ID, Jetpack derives
	// the field ID from the per-instance form ID. Modelled as the field's
	// position within its form.
	$id          = (string) ( $attrs['id'] ?? '' );
	$id          = '' !== $id ? $id : 'g1-field-' . ( $GLOBALS['ssi_role_box_field_index']++ );
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
	// Blocks Engine's generated layout shell saves its authored wrapper
	// chain (LayoutShellBlockGenerator savedContent()) around its children.
	if ( str_ends_with( $block['name'], '/layout-shell' ) ) {
		$open  = '';
		$close = '';
		foreach ( (array) ( $block['attrs']['wrappers'] ?? array() ) as $wrapper ) {
			$tag = (string) ( $wrapper['tagName'] ?? 'div' );
			$open .= '<' . $tag;
			foreach ( (array) ( $wrapper['attributes'] ?? array() ) as $name => $value ) {
				$open .= true === $value ? ' ' . $name : ' ' . $name . '="' . ssi_role_box_esc( (string) $value ) . '"';
			}
			$open .= '>';
			$close = '</' . $tag . '>' . $close;
		}
		$inner = '';
		foreach ( $block['innerBlocks'] as $child ) {
			$inner .= ssi_role_box_render_block( $child );
		}
		return $open . $inner . $close;
	}
	// Static core blocks render their saved markup.
	if ( in_array( $block['name'], array( 'core/heading', 'core/paragraph' ), true ) ) {
		return trim( (string) $block['innerHTML'] );
	}
	fwrite( STDERR, 'unmodelled block ' . $block['name'] . "\n" );
	exit( 1 );
}

/** Model of Jetpack's form render (Contact_Form::parse) plus the form render_block filters. */
function ssi_role_box_render_form( array $form ): string {
	$class_name = trim( (string) ( $form['attrs']['className'] ?? '' ) );
	$body       = '';
	$GLOBALS['ssi_role_box_field_index'] = 0;
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
	return $html;
}

