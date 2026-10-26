import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

// Minimal models of Jetpack Forms' two surfaces for the role-box parity test
// (tests/form-role-box-parity.test.mjs), taken from the public Jetpack source
// at Automattic/jetpack 9c19e18ca8f5 (projects/packages/forms/src/...).
//
// Only rules that can change a measured box (height, padding, border,
// background, font size, margin) or the display that sizes it are kept, with
// their selectors and values verbatim. Anything else in the source files
// (states, variations, inset label styles, notices, placeholders) is left out.

// contact-form/css/grunion.scss: the front-end form stylesheet.
export const JETPACK_FRONT_CSS = `
:root {
	--jetpack--contact-form--border: 1px solid #8c8f94;
	--jetpack--contact-form--border-color: #8c8f94;
	--jetpack--contact-form--border-size: 1px;
	--jetpack--contact-form--border-style: solid;
	--jetpack--contact-form--border-radius: 0px;
	--jetpack--contact-form--input-padding: 16px;
	--jetpack--contact-form--font-size: 16px;
}
:where(
	.contact-form input[type="text"],
	.contact-form input[type="email"],
	.contact-form input[type="tel"],
	.contact-form input[type="url"],
	.contact-form input[type="number"],
	.contact-form input[type="time"],
	.contact-form textarea,
	.contact-form .jetpack-field__input-element-wrap .jetpack-field__input-element
) {
	box-sizing: border-box;
	width: 100%;
	padding: 16px;
	font: inherit;
	border: 1px solid #8c8f94;
	border-radius: 0;
	background-color: var(--jetpack--contact-form--input-background, field);
}
:where(.contact-form textarea) { display: block; height: 200px; }
.contact-form .grunion-field {
	padding-left: max(var(--jetpack--contact-form--input-padding-left, 16px), var(--jetpack--contact-form--border-radius));
	padding-right: max(var(--jetpack--contact-form--input-padding-left, 16px), var(--jetpack--contact-form--border-radius));
}
.contact-form .grunion-field-wrap input,
.contact-form .grunion-field-wrap textarea { margin: 0; }
.contact-form select { min-width: 150px; }
.contact-form input[type="radio"],
.contact-form input[type="checkbox"] { min-width: 1em; width: 1em; height: 1em; margin: 0 0.75rem 0 0; opacity: 1; }
.contact-form input[type="checkbox"] { top: 0; margin-left: 0; border-radius: 4px; }
.contact-form label { float: none; display: block; }
.contact-form :where(legend.grunion-field-label),
.contact-form :where(label) { margin-bottom: 0.25em; font-weight: 700; }
:where(.contact-form legend.grunion-field-label) { padding-inline: 0; }
.contact-form :where(label.consent) { font-size: var(--jetpack--contact-form--label--font-size, 16px); font-weight: 400; }
.contact-form label.consent { display: flex; align-items: center; }
.contact-form :where(label.checkbox-multiple),
.contact-form :where(label.radio),
.contact-form :where(label.checkbox) { font-weight: inherit; }
.contact-form label.checkbox-multiple,
.contact-form label.radio { margin-bottom: 0; flex: 1; }
.contact-form .grunion-checkbox-multiple-options,
.contact-form .grunion-radio-options { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; margin: 0; }
:where(.contact-form .grunion-checkbox-multiple-options),
:where(.contact-form .grunion-radio-options) { border: none; padding: 0; }
.contact-form .grunion-checkbox-multiple-options .contact-form-field,
.contact-form .grunion-radio-options .contact-form-field { display: flex; align-items: baseline; margin: 0; }
.contact-form :where(label span.required),
.contact-form :where(.grunion-label-required) { font-size: 85%; margin-left: 0.25em; font-weight: 400; }
.wp-block-jetpack-contact-form:not(.is-layout-flex) {
	display: flex; flex-wrap: wrap; justify-content: flex-start; flex-direction: row; flex-grow: 1;
	gap: var(--wp--style--block-gap, 1.5rem);
}
.wp-block-jetpack-contact-form:not(.is-layout-flex) > *:not(.wp-block-jetpack-button,.wp-block-button) { flex: 0 0 100%; box-sizing: border-box; }
:where(.jetpack-contact-form-container) { border-width: 0; }
.wp-block-jetpack-contact-form .grunion-field-wrap { box-sizing: border-box; position: relative; flex: 1 1 100%; width: 100%; }
.wp-block-jetpack-contact-form .grunion-field-wrap :where(:not(.is-single-input-form) > .grunion-field-wrap) { display: flex; flex-direction: column; }
.contact-form .contact-form__select-wrapper { position: relative; padding: 0; }
.contact-form :where(.contact-form__select-wrapper) {
	background-color: var(--jetpack--contact-form--input-background, field);
	border: var(--jetpack--contact-form--border);
	border-color: var(--jetpack--contact-form--border-color);
	border-radius: var(--jetpack--contact-form--border-radius);
	border-style: var(--jetpack--contact-form--border-style);
	border-width: var(--jetpack--contact-form--border-left-size, var(--jetpack--contact-form--border-size));
	color: var(--jetpack--contact-form--text-color);
	font-family: var(--jetpack--contact-form--font-family);
	font-size: var(--jetpack--contact-form--font-size);
}
.contact-form .contact-form__select-element-wrapper { display: flex; }
.contact-form :where(.contact-form__select-element-wrapper),
.contact-form :where(.contact-form__select-wrapper select) {
	border: 0 !important; background: inherit !important; border-radius: inherit !important;
	font-family: inherit; font-size: inherit; color: inherit; font-weight: inherit; line-height: inherit;
	text-decoration: inherit; text-transform: inherit; letter-spacing: inherit; font-style: inherit;
	z-index: 1; border-width: 0 !important;
}
.contact-form .contact-form__select-wrapper select {
	box-sizing: border-box; width: 100%; appearance: none; text-overflow: ellipsis; white-space: nowrap;
	padding: var(--jetpack--contact-form--input-padding, 16px);
}
.contact-form__input-error { margin: 0; opacity: 0; max-height: 0; overflow: hidden; display: flex; }
.contact-form__error { display: none; }
`;

// contact-form/css/jetpack-forms-layout.scss: the block stylesheet block.json
// registers for jetpack/contact-form, so it loads on both surfaces.
export const JETPACK_LAYOUT_CSS = `
.wp-block-jetpack-contact-form-container.is-layout-flex > .wp-block-group,
.wp-block-jetpack-contact-form.is-layout-flex > .wp-block-group,
.wp-block-jetpack-contact-form-is-layout-flex > .wp-block-group,
.wp-block-jetpack-contact-form-container.is-layout-flex > .wp-block-columns,
.wp-block-jetpack-contact-form.is-layout-flex > .wp-block-columns,
.wp-block-jetpack-contact-form-is-layout-flex > .wp-block-columns { box-sizing: border-box; width: 100%; }
.wp-block-jetpack-contact-form-container.is-layout-flex.is-horizontal,
.wp-block-jetpack-contact-form.is-layout-flex.is-horizontal,
.wp-block-jetpack-contact-form-is-layout-flex.is-horizontal { flex-flow: row wrap; }
.wp-block-jetpack-contact-form-container.is-layout-flex.is-horizontal .wp-block-button,
.wp-block-jetpack-contact-form.is-layout-flex.is-horizontal .wp-block-button,
.wp-block-jetpack-contact-form-is-layout-flex.is-horizontal .wp-block-button { flex: 0 0 auto; }
.wp-block-jetpack-contact-form-container.is-layout-flex.is-horizontal .wp-block-button .wp-block-button__link,
.wp-block-jetpack-contact-form.is-layout-flex.is-horizontal .wp-block-button .wp-block-button__link,
.wp-block-jetpack-contact-form-is-layout-flex.is-horizontal .wp-block-button .wp-block-button__link { word-break: normal; }
`;

// contact-form/css/phone-field.scss: the front-end phone composite.
export const JETPACK_FRONT_PHONE_CSS = `
.wp-block-jetpack-field-telephone.wp-block-jetpack-input-wrap.grunion-field-wrap .jetpack-field__input-phone-wrapper,
.wp-block-jetpack-field-phone.wp-block-jetpack-input-wrap.grunion-field-wrap .jetpack-field__input-phone-wrapper {
	display: flex; gap: 8px;
	background-color: var(--jetpack--contact-form--input-background);
	border-color: var(--jetpack--contact-form--border-color, currentColor);
	border-width: var(--jetpack--contact-form--border-size);
	border-style: var(--jetpack--contact-form--border-style);
	border-radius: var(--jetpack--contact-form--border-radius);
	font-family: var(--jetpack--contact-form--font-family);
	font-size: var(--jetpack--contact-form--font-size);
	padding: var(--jetpack--contact-form--input-padding);
	line-height: var(--jetpack--contact-form--line-height);
	align-items: center;
}
.wp-block-jetpack-field-telephone.wp-block-jetpack-input-wrap.grunion-field-wrap .jetpack-field__input-phone-wrapper .jetpack-field__input-element,
.wp-block-jetpack-field-phone.wp-block-jetpack-input-wrap.grunion-field-wrap .jetpack-field__input-phone-wrapper .jetpack-field__input-element {
	border: 0; font: inherit; flex: 1; outline: none; color: inherit; background: none; width: inherit;
	box-sizing: border-box; resize: none; padding: 0; min-width: unset; min-height: unset; z-index: 1;
}
`;

// contact-form/css/combobox.scss: the country trigger, loaded on the front
// end and imported by blocks/input-phone/edit.jsx in the editor.
export const JETPACK_COMBOBOX_CSS = `
.jetpack-custom-combobox { letter-spacing: normal; background-color: inherit; }
.jetpack-custom-combobox button.jetpack-combobox-trigger:not(:active):not(:hover),
.jetpack-custom-combobox button.jetpack-combobox-trigger:not(:active):hover,
.jetpack-custom-combobox button.jetpack-combobox-trigger:focus { background-color: transparent; color: inherit; }
.jetpack-custom-combobox .jetpack-combobox-trigger {
	width: 100%; border: 0 solid #ddd; border-radius: 4px; background: transparent; background-color: transparent;
	display: flex; align-items: center; justify-content: space-between; font: inherit; line-height: inherit;
	padding-block: 0; padding-inline: 0; gap: 0.4em; color: inherit; height: unset;
}
.jetpack-custom-combobox .jetpack-combobox-trigger .jetpack-combobox-trigger-arrow { display: flex; align-items: center; justify-content: center; width: 0.6em; margin-left: 4px; }
.jetpack-custom-combobox .jetpack-combobox-trigger .jetpack-combobox-trigger-arrow svg { width: 100%; height: 100%; }
.jetpack-custom-combobox .jetpack-combobox-trigger .jetpack-combobox-selected { white-space: nowrap; }
.jetpack-custom-combobox .jetpack-combobox-dropdown { position: absolute; }
`;

// blocks/contact-form/editor.scss: the editor form and field stylesheet.
export const JETPACK_EDITOR_CSS = `
:where(.jetpack-contact-form) { border-width: 0; }
:where(.is-classic-layout) .jetpack-contact-form,
.jetpack-contact-form.has-no-jetpack-form-layout { display: flex; flex-wrap: wrap; justify-content: flex-start; gap: var(--wp--style--block-gap, 1.5rem); }
.jetpack-contact-form.has-no-jetpack-form-layout .wp-block { max-width: 100%; }
.jetpack-contact-form.has-no-jetpack-form-layout > .wp-block { flex: 0 0 100%; margin-top: 0; margin-bottom: 0; }
.jetpack-contact-form.has-no-jetpack-form-layout > div { flex: 1 1 100%; }
.wp-block-jetpack-contact-form .wp-block { max-width: 100%; }
.wp-block-jetpack-contact-form .wp-block.wp-block-button,
.wp-block-jetpack-contact-form .wp-block.wp-block-jetpack-button {
	flex: 0 0 auto; min-height: var(--jetpack--contact-form--input-height, auto); line-height: 1; margin-block: 0;
}
.wp-block-jetpack-contact-form .wp-block.wp-block-button .wp-block-button__link,
.wp-block-jetpack-contact-form .wp-block.wp-block-jetpack-button .wp-block-button__link {
	min-height: var(--jetpack--contact-form--input-height, auto);
	padding-top: var(--jetpack--contact-form--input-padding-top);
	padding-bottom: var(--jetpack--contact-form--input-padding-top);
}
.jetpack-field-label { display: flex; flex-direction: row; flex-wrap: wrap; justify-content: flex-start; align-items: baseline; margin-bottom: 0; }
.jetpack-field-label .jetpack-field-label__input.jetpack-field-label__input { cursor: text; margin-bottom: 0.25em; }
.jetpack-field-label .required,
.jetpack-field-option .required { word-break: normal; color: unset; font-size: 72%; margin-inline-start: 0.25em; font-weight: 400; }
.jetpack-field-label__input { min-height: unset; pointer-events: auto; padding: 0; }
.jetpack-field .jetpack-field__textarea { display: block; min-height: 200px; }
.jetpack-field .jetpack-field__input,
.jetpack-field .jetpack-field__textarea { box-sizing: border-box; box-shadow: unset; padding: var(--jetpack--contact-form--input-padding, 16px); margin: 0; }
.jetpack-field .jetpack-option__type,
.jetpack-field.jetpack-field-checkbox .jetpack-field-option__checkbox { margin: 0 0.75rem 0 0; border-color: currentColor; opacity: 1; }
.jetpack-field .jetpack-field-option { display: flex; align-items: center; justify-content: flex-start; margin: unset; }
.jetpack-field .jetpack-field-option .wp-block { margin: unset; }
:where(.jetpack-field .jetpack-field__input, .jetpack-field .jetpack-field__textarea) { padding: 16px; margin: 0; }
:where(.jetpack-field .jetpack-field__textarea) {
	border: var(--jetpack--contact-form--border, 1px solid #8c8f94);
	border-color: var(--jetpack--contact-form--border-color, #8c8f94);
	border-style: var(--jetpack--contact-form--border-style, solid);
	border-width: var(--jetpack--contact-form--border-left-size, 1px);
}
:where(.jetpack-field .jetpack-field__input, .jetpack-field .jetpack-field__textarea, .jetpack-field .jetpack-field-dropdown__toggle) { font: inherit; border: 1px solid #8c8f94; border-radius: 0; }
:where(.jetpack-field .jetpack-field-dropdown__toggle) { background-color: var(--jetpack--contact-form--input-background); }
.jetpack-field:not(.is-style-plain) .jetpack-field-option__checkbox,
.jetpack-field:not(.is-style-plain) .jetpack-option__type {
	align-items: center; display: flex; justify-content: center; margin: 0 10px 0 0; position: relative; pointer-events: none;
	width: 1em !important; height: 1em !important; box-shadow: none !important; appearance: none !important; outline: none !important;
	box-sizing: border-box; font-size: inherit; min-width: 1em; min-height: 1em;
	margin-inline-end: calc(var(--jetpack--contact-form--font-size) / 2); padding: 0;
	border: solid 1px currentColor; border-radius: 4px; color: currentColor;
	transform: translateY(0.15em); background-color: var(--jetpack--contact-form--input-background, field);
}
.jetpack-field:not(.is-style-plain) .jetpack-field-option.field-option-radio .jetpack-option__type { border-radius: 50%; }
.jetpack-field:not(.is-style-plain) .jetpack-field-option__checkbox,
.jetpack-field:not(.is-style-plain) .jetpack-field-option { display: flex; align-items: center; justify-content: flex-start; }
.jetpack-field:not(.is-style-plain) .jetpack-field-option > input { margin: 0 5px 0 0; }
.jetpack-field:not(.is-style-plain) .jetpack-field-option.field-option-checkbox,
.jetpack-field:not(.is-style-plain) .jetpack-field-option.field-option-radio { display: flex; align-items: baseline; flex-wrap: nowrap; }
.jetpack-field-multiple__list.jetpack-field-multiple__list {
	list-style-type: none; margin: 0; padding-left: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 12px; flex: 1;
}
.jetpack-field-checkbox, .jetpack-field-consent { display: flex; }
.jetpack-field-checkbox { align-items: baseline; }
.jetpack-field-dropdown { width: 100%; display: inline-flex; flex-direction: column; font-family: var(--jetpack--contact-form--font-family); }
.jetpack-field-dropdown__wrapper { position: relative; }
.jetpack-field-dropdown__toggle {
	color: var(--jetpack--contact-form--text-color); font-size: var(--jetpack--contact-form--font-size, 16px);
	padding: var(--jetpack--contact-form--input-padding, 16px); border-color: var(--jetpack--contact-form--border-color);
	display: flex; align-items: center; justify-content: space-between;
}
.jetpack-field-dropdown__icon {
	width: 0.8em; height: 0.8em; position: relative; display: flex; align-items: center; justify-content: center;
	box-sizing: border-box; border: 2px solid transparent; overflow: visible; margin-right: 4px; color: inherit; font-size: inherit;
}
`;

// blocks/input-phone/editor.scss: the editor phone composite.
export const JETPACK_EDITOR_PHONE_CSS = `
.jetpack-field.jetpack-field-phone .jetpack-field__input,
.jetpack-field.jetpack-field-telephone .jetpack-field__input {
	box-sizing: border-box;
	border-color: var(--jetpack--contact-form--border-color, currentColor);
	border-radius: var(--jetpack--contact-form--border-radius);
	border-width: var(--jetpack--contact-form--border-size);
	font-size: var(--jetpack--contact-form--font-size);
	line-height: var(--jetpack--contact-form--line-height);
	background-color: var(--jetpack--contact-form--input-background);
}
.jetpack-field.jetpack-field-phone .jetpack-field__input .jetpack-field__input-element:not(.jetpack-field__input-prefix .jetpack-field__input-element),
.jetpack-field.jetpack-field-telephone .jetpack-field__input .jetpack-field__input-element:not(.jetpack-field__input-prefix .jetpack-field__input-element) {
	font: inherit; line-height: inherit; border: 0; color: inherit; outline: none; background-color: transparent;
	padding: 0; letter-spacing: inherit; flex: 1; box-sizing: border-box; resize: none; min-height: unset;
}
.jetpack-field.jetpack-field-phone .jetpack-field__input,
.jetpack-field.jetpack-field-telephone .jetpack-field__input { display: flex; gap: 8px; }
.jetpack-field.jetpack-field-phone .jetpack-field__input .jetpack-field__input-prefix,
.jetpack-field.jetpack-field-telephone .jetpack-field__input .jetpack-field__input-prefix {
	font: inherit; line-height: inherit; border: 0; color: inherit; display: flex; max-width: 40%; background-color: inherit; align-items: center;
}
`;

// Core's base layout styles for the default theme.json (blockGap 24px), as
// WP_Theme_JSON::get_layout_styles() prints them, served to both surfaces, plus
// the container CSS layout support generates for jetpack/contact-form's
// block.json default layout (wp-includes/block-supports/layout.php: flex,
// justifyContent left, verticalAlignment top) under a fixed container class.
export const CORE_LAYOUT_CSS = `
body .is-layout-flex { display: flex; }
.is-layout-flex { flex-wrap: wrap; align-items: center; }
.is-layout-flex > :is(*, div) { margin: 0; }
:root :where(.is-layout-flow) > :first-child { margin-block-start: 0; }
:root :where(.is-layout-flow) > :last-child { margin-block-end: 0; }
:root :where(.is-layout-flow) > * { margin-block-start: 24px; margin-block-end: 0; }
:root :where(.is-layout-flex) { gap: 24px; }
.wp-container-jetpack-contact-form-is-layout-default { justify-content: flex-start; align-items: flex-start; }
`;

// The same base layout styles for an imported site's generated theme.json,
// which sets styles.spacing.blockGap to false with blockGap support on: core
// prints no flow or flex gap rules and an empty --wp--style--block-gap
// (WP_Theme_JSON::get_root_layout_rules()), so source spacing stays in charge.
export const IMPORTED_THEME_LAYOUT_CSS = `
body .is-layout-flex { display: flex; }
.is-layout-flex { flex-wrap: wrap; align-items: center; }
.is-layout-flex > :is(*, div) { margin: 0; }
:root { --wp--style--block-gap: ; }
.wp-container-jetpack-contact-form-is-layout-default { justify-content: flex-start; align-items: flex-start; }
`;

const escapeAttr = ( value ) => String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
const escapeText = ( value ) => String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' );
const classes = ( ...names ) => names.filter( Boolean ).join( ' ' ).replace( /\s+/g, ' ' ).trim();

/** Inline styles block supports put on the block element (border style only here). */
function supportStyle( attrs ) {
	const style = attrs?.style?.border?.style;
	return style ? ` style="border-style:${ escapeAttr( style ) }"` : '';
}

/**
 * The block element's attributes from useBlockProps: `block-editor-block-list__block
 * wp-block`, the block's generated and custom classes, and Gutenberg's `data-type`.
 */
function blockProps( name, attrs, ...own ) {
	const generated = `wp-block-${ name.replace( /^core\//, '' ).replace( '/', '-' ) }`;
	return `class="${ escapeAttr( classes( 'block-editor-block-list__block wp-block', generated, ...own, attrs?.className ) ) }" data-type="${ escapeAttr( name ) }"`;
}

/**
 * The legacy layout classes block-editor/src/hooks/layout.js adds from the
 * used layout (the block's attribute, else its block.json default).
 */
function legacyLayoutClasses( layout ) {
	return classes(
		layout.orientation && `is-${ layout.orientation }`,
		layout.justifyContent && `is-content-justification-${ layout.justifyContent }`,
		'nowrap' === layout.flexWrap && 'is-nowrap'
	);
}

/**
 * Blocks Engine's generated layout shell in the editor (LayoutShellBlockGenerator
 * edit()): the authored wrapper chain, the outermost merged with useBlockProps
 * minus `block-editor-block-list__block`, and the innermost holding the inner
 * blocks through useInnerBlocksProps.
 */
function layoutShellEditorHtml( block, children ) {
	const wrappers = block.attrs.wrappers ?? [];
	let html = children;
	for ( let index = wrappers.length - 1; index >= 0; index-- ) {
		const { tagName = 'div', attributes = {} } = wrappers[ index ];
		const attrs = { ...attributes };
		const own = [];
		if ( 0 === index ) {
			own.push( 'wp-block', `wp-block-${ block.name.replace( '/', '-' ) }` );
			attrs[ 'data-type' ] = block.name;
		}
		if ( wrappers.length - 1 === index ) {
			own.push( 'block-editor-block-list__layout' );
		}
		attrs.class = classes( ...own, attributes.class );
		const serialized = Object.entries( attrs ).filter( ( [ , value ] ) => '' !== value ).map( ( [ key, value ] ) => ( true === value ? ` ${ key }` : ` ${ key }="${ escapeAttr( value ) }"` ) ).join( '' );
		html = `<${ tagName }${ serialized }>${ html }</${ tagName }>`;
	}
	return html;
}

/**
 * Jetpack's editor DOM for the seeded block tree, as each block's edit
 * component renders it with the default form style.
 *
 * @param {object} block  Parsed block `{ name, attrs, innerBlocks, innerHTML }`.
 * @param {object} parent Enclosing field block, for the context it provides.
 */
export function editorBlockHtml( block, parent = null ) {
	const { name, attrs } = block;
	const inner = () => block.innerBlocks.map( ( child ) => editorBlockHtml( child, name.startsWith( 'jetpack/field-' ) ? block : parent ) ).join( '' );
	switch ( name ) {
		// blocks/contact-form/edit.tsx: block wrapper, then the inner-blocks
		// container with formClassnames (the BlockEdit className, i.e. generated
		// plus custom class, block-editor/src/components/block-edit/edit.js) and
		// the layout classes Gutenberg's layout hook (block-editor/src/hooks/layout.js)
		// passes through useInnerBlocksProps.
		case 'jetpack/contact-form': {
			if ( attrs.layout ) {
				throw new Error( 'no editor model for a contact-form layout attribute' );
			}
			return `<div ${ blockProps( name, attrs ) }><div class="${ classes( 'wp-block-jetpack-contact-form', attrs.className, 'jetpack-contact-form', 'has-no-jetpack-form-layout', 'block-editor-block-list__layout', legacyLayoutClasses( { type: 'flex', flexWrap: 'wrap', orientation: 'horizontal', justifyContent: 'left', verticalAlignment: 'top' } ), 'is-layout-flex', 'wp-container-jetpack-contact-form-is-layout-default', 'wp-block-jetpack-contact-form-is-layout-flex' ) }">${ inner() }</div></div>`;
		}
		// block-library/src/group/edit.js: useInnerBlocksProps( blockProps ).
		case 'core/group': {
			const layout = attrs.layout?.type ?? 'flow';
			return `<div ${ blockProps( name, attrs, 'block-editor-block-list__layout', legacyLayoutClasses( attrs.layout ?? {} ), `is-layout-${ layout }`, `wp-block-group-is-layout-${ layout }` ) }>${ inner() }</div>`;
		}
		// block-library/src/button/edit.js: wrapper plus a RichText (div) whose
		// class is the edit className (generated + custom) and the link classes.
		case 'core/button': {
			const content = /<button[^>]*>([\s\S]*)<\/button>/.exec( block.innerHTML )?.[1] ?? '';
			return `<div ${ blockProps( name, attrs ) }><div role="textbox" contenteditable="true" class="${ classes( 'wp-block-button', attrs.className, 'wp-block-button__link', 'wp-element-button', 'block-editor-rich-text__editable rich-text' ) }">${ content }</div></div>`;
		}
		// blocks/field-checkbox/edit.jsx: useInnerBlocksProps( blockProps ), no control div.
		case 'jetpack/field-checkbox':
			return `<div ${ blockProps( name, attrs, 'jetpack-field jetpack-field-checkbox', 'block-editor-block-list__layout' ) }>${ inner() }</div>`;
		// blocks/field-single-choice/edit.jsx (field-radio): useInnerBlocksProps( blockProps ).
		case 'jetpack/field-radio':
			return `<div ${ blockProps( name, attrs, 'jetpack-field jetpack-field-multiple', 'block-editor-block-list__layout' ) }>${ inner() }</div>`;
		// blocks/field-select/edit.jsx.
		case 'jetpack/field-select':
			return `<div ${ blockProps( name, attrs, 'jetpack-field jetpack-field-dropdown' ) }><div class="jetpack-field__control jetpack-field-dropdown__wrapper block-editor-block-list__layout">${ inner() }</div></div>`;
		// blocks/field-telephone/edit.jsx and field-textarea/edit.jsx, then
		// shared/components/jetpack-field.jsx for the text-like fields.
		case 'jetpack/field-telephone':
			return `<div ${ blockProps( name, attrs, 'jetpack-field jetpack-field-phone jetpack-field-telephone' ) }><div class="jetpack-field__control block-editor-block-list__layout">${ inner() }</div></div>`;
		case 'jetpack/field-textarea':
			return `<div ${ blockProps( name, attrs, 'jetpack-field jetpack-field-textarea' ) }><div class="jetpack-field__control block-editor-block-list__layout">${ inner() }</div></div>`;
		case 'jetpack/field-text':
		case 'jetpack/field-email':
		case 'jetpack/field-url':
		case 'jetpack/field-number':
			return `<div ${ blockProps( name, attrs, 'jetpack-field' ) }><div class="jetpack-field__control block-editor-block-list__layout">${ inner() }</div></div>`;
		// blocks/label/edit.jsx: plain (default style) wrapper, label RichText
		// and the required RichText when the field is required.
		case 'jetpack/label': {
			if ( false === attrs.metadata?.blockVisibility ) {
				return '';
			}
			const required = parent?.attrs?.required && false !== attrs.requiredIndicator
				? `<span class="required rich-text">${ escapeText( attrs.requiredText ?? '(required)' ) }</span>`
				: '';
			return `<div ${ blockProps( name, attrs, 'jetpack-field-label' ) }><label class="jetpack-field-label__input block-editor-rich-text__editable rich-text">${ escapeText( attrs.label ?? '' ) }</label>${ required }</div>`;
		}
		// blocks/input/edit.jsx: getInputClass( type ) on the block element.
		case 'jetpack/input': {
			const style = supportStyle( attrs );
			if ( 'dropdown' === attrs.type ) {
				return `<div ${ blockProps( name, attrs, 'jetpack-field-dropdown__toggle' ) }${ style }><div class="rich-text">${ escapeText( attrs.placeholder ?? 'Select one option' ) }</div><span class="jetpack-field-dropdown__icon"></span></div>`;
			}
			if ( 'textarea' === attrs.type ) {
				return `<textarea ${ blockProps( name, attrs, 'jetpack-field__textarea' ) }${ style }></textarea>`;
			}
			return `<input type="text" ${ blockProps( name, attrs, 'jetpack-field__input' ) }${ style }>`;
		}
		// blocks/input-phone/edit.jsx and shared/components/searchable-combobox.jsx.
		case 'jetpack/phone-input': {
			const prefix = parent?.attrs?.showCountrySelector
				? '<div class="jetpack-field__input-prefix"><div class="jetpack-custom-combobox"><button class="jetpack-combobox-trigger" type="button"><span class="jetpack-combobox-selected">US</span><span class="jetpack-combobox-trigger-arrow"><svg width="10" height="6" viewBox="0 0 10 6"><path d="M1 1L5 5L9 1"/></svg></span><span class="jetpack-combobox-selected">+1</span></button></div></div>'
				: '';
			return `<div ${ blockProps( name, attrs, 'jetpack-field__input jetpack-field__input-phone' ) }${ supportStyle( attrs ) }>${ prefix }<input class="jetpack-field__input-element" type="text"></div>`;
		}
		// blocks/options/edit.jsx.
		case 'jetpack/options':
			return `<ul ${ blockProps( name, attrs, 'jetpack-field-multiple__list', 'block-editor-block-list__layout' ) }>${ inner() }</ul>`;
		// blocks/option/edit.jsx: standalone (checkbox, consent) or list option.
		case 'jetpack/option': {
			const type = parent?.name === 'jetpack/field-radio' ? 'radio' : 'checkbox';
			const label = `<div class="wp-block rich-text">${ escapeText( attrs.label ?? '' ) }</div>`;
			if ( attrs.isStandalone ) {
				return `<div ${ blockProps( name, attrs, `jetpack-field-option field-option-${ type }` ) }><input class="jetpack-field-option__checkbox" type="${ type }"><div class="jetpack-field-option__label-wrapper">${ label }</div></div>`;
			}
			return `<li ${ blockProps( name, attrs, `jetpack-field-option field-option-${ type }` ) }><input type="${ type }" class="jetpack-option__type" tabindex="-1">${ label }</li>`;
		}
		// Static core blocks: the saved element is the editor element, with the
		// block list classes and the block type merged onto it.
		case 'core/heading':
		case 'core/paragraph':
			return block.innerHTML.trim().replace( /^<([a-z0-9]+)((?:\s[^>]*)?)>/i, ( tag, element, rest ) => {
				const saved = /\sclass="([^"]*)"/.exec( rest )?.[1] ?? '';
				return `<${ element }${ rest.replace( /\sclass="[^"]*"/, '' ) } class="${ escapeAttr( classes( 'block-editor-block-list__block wp-block', saved ) ) }" data-type="${ escapeAttr( name ) }">`;
			} );
		default:
			if ( name.endsWith( '/layout-shell' ) ) {
				return layoutShellEditorHtml( block, inner() );
			}
			throw new Error( `no editor model for ${ name }` );
	}
}

// Core block-library's button stylesheet (core/button's block style), served
// to both surfaces.
const BUTTON_CSS = readFileSync( createRequire( import.meta.url ).resolve( '@wordpress/block-library/build-style/button/style.css' ), 'utf8' );

/** The front end: block styles, then the theme's style.css, in a block theme's site wrapper. `layoutCss` as for editorDocument. */
export function frontDocument( form, { layoutCss = CORE_LAYOUT_CSS } = {} ) {
	const providerCss = [ BUTTON_CSS, layoutCss, JETPACK_FRONT_CSS, JETPACK_LAYOUT_CSS, JETPACK_FRONT_PHONE_CSS, JETPACK_COMBOBOX_CSS ].join( '\n' );
	return `<!doctype html><html><head><style>${ providerCss }</style><style>${ form.css }</style></head>`
		+ `<body><div class="wp-site-blocks"><div id="ssi-role-root">${ form.frontHtml }</div></div></body></html>`;
}

/**
 * The iframed block editor canvas: editor-style.css is added untransformed
 * (block-editor/src/components/block-canvas/index.js passes no scope when
 * iframed), the body carries `editor-styles-wrapper`, and blocks render in
 * the post content root container. `layoutCss` picks the theme's core layout
 * styles (default theme, or IMPORTED_THEME_LAYOUT_CSS).
 */
export function editorDocument( form, { layoutCss = CORE_LAYOUT_CSS } = {} ) {
	const providerCss = [ BUTTON_CSS, layoutCss, JETPACK_EDITOR_CSS, JETPACK_LAYOUT_CSS, JETPACK_EDITOR_PHONE_CSS, JETPACK_COMBOBOX_CSS ].join( '\n' );
	const blocks = form.blocks.map( ( block ) => editorBlockHtml( block ) ).join( '' );
	return `<!doctype html><html><head><style>${ providerCss }</style><style>${ form.editorCss }</style></head>`
		+ `<body class="editor-styles-wrapper block-editor-iframe__body"><div id="ssi-role-root" class="is-root-container is-layout-flow wp-block-post-content block-editor-block-list__layout">${ blocks }</div></body></html>`;
}
