import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { editorDocument, frontDocument } from './fixtures/form-role-box-parity-jetpack-models.mjs';

// Every declared form overlay part that reaches the editor must give its
// front-end box and its editor box the same role box. Source forms seeded by
// tests/fixtures/form-role-box-parity-fixture.php emit every declared part;
// each part's front end (Jetpack's server render through the provider runtime
// filters) and editor (Jetpack's edit components) are rendered at the same
// width with the materialized style.css / editor-style.css, and the element
// realising the part on each surface is measured.
//
// PAIRS is keyed by part id. Each pair names the source form and target
// (or control) that carries the part, and role locators for the provider
// element that realises it on each surface. A pair is only measured once the
// part's declared selectors realise on those elements: the outermost match of
// the recorded front-end selector is the front locator's element, and the
// outermost match of the derived editor selector is the editor locator's
// element. A part whose editor selector does not land on its role is a real
// mismatch, as is a measured difference over 1px.
//
// Playwright is a devDependency installed by CI. Browser acceptance must not
// silently skip: a missing Chromium fails the run.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const TOLERANCE = 1;
const VIEWPORT = { width: 1200, height: 1600 };

// `front` and `editor` are role locators within the rendered form. `frontBox`
// and `editorBox` name the element that takes the box half of a composite
// realisation (labels: the declared box goes to Jetpack's label block wrapper
// in the editor): height, margins and padding are measured on the box,
// borders, background and font size on the role element. `paddingFrom: 'node'`
// takes padding from the role element too, for a box that paints nothing.
// `margins` adds the block margins the part carries.
const FIELD = ( n ) => `[data-jp-field-id$="-field-${ n }"]`;
const PAIRS = {
	form: { form: 'controls', origin: 'form', front: 'form.jetpack-contact-form__form', editor: ':scope > [data-type="jetpack/contact-form"]' },
	form_box: { form: 'form_box', origin: 'form-box', front: ':scope > .jetpack-contact-form-container', editor: ':scope > [data-type="jetpack/contact-form"]' },
	native_form: { form: 'native_form', origin: 'form', front: 'form.jetpack-contact-form__form', editor: '[data-type="jetpack/contact-form"] > .jetpack-contact-form' },
	native_form_box: { form: 'native_form', origin: 'form-box', front: ':scope > .jetpack-contact-form-container', editor: ':scope > [data-type="jetpack/contact-form"]' },
	field_list: { form: 'field_list', origin: 'field-list', front: '.wp-block-jetpack-contact-form > .ssi-source-field-list:has([data-jp-field-id])', editor: '[data-type="jetpack/contact-form"] > .jetpack-contact-form' },
	field: { form: 'controls', origin: 'field-5', front: FIELD( 5 ), editor: '[data-type="jetpack/field-checkbox"]' },
	wrap: { form: 'controls', origin: 'wrapper-0', front: FIELD( 0 ), editor: '[data-type="jetpack/field-text"]' },
	box: { form: 'controls', origin: 'control-0', front: `${ FIELD( 0 ) } input.grunion-field`, editor: '[data-type="jetpack/field-text"] [data-type="jetpack/input"]' },
	submit_link_box: { form: 'form_box', origin: 'control-2', front: '.wp-block-button > .wp-block-button__link', editor: '[data-type="core/button"] > .wp-block-button__link' },
	country_trigger: { form: 'country', origin: 'control-0', front: 'button.jetpack-combobox-trigger', editor: 'button.jetpack-combobox-trigger' },
	control: { form: 'controls', origin: 'control-0', front: `${ FIELD( 0 ) } input.grunion-field`, editor: '[data-type="jetpack/field-text"] [data-type="jetpack/input"]' },
	sibling_stacked: { form: 'controls', origin: 'control-0', front: `${ FIELD( 0 ) } input.grunion-field`, editor: '[data-type="jetpack/field-text"] [data-type="jetpack/input"]', margins: true },
	phone_primary: { form: 'controls', origin: 'control-2', front: `${ FIELD( 2 ) } input[type="tel"]`, editor: '[data-type="jetpack/phone-input"]' },
	phone_sibling_stacked: { form: 'controls', origin: 'control-2', front: `${ FIELD( 2 ) } input[type="tel"]`, editor: '[data-type="jetpack/phone-input"]', margins: true },
	// The editor's dropdown toggle is both the select wrapper and the select:
	// the wrapper's box (height, margins) is the front wrapper, its paint
	// (padding, border, background, font) the select inside it.
	select_wrapper: { form: 'controls', origin: 'control-3', front: '.contact-form__select-wrapper select', frontBox: '.contact-form__select-wrapper', paddingFrom: 'node', editor: '[data-type="jetpack/field-select"] [data-type="jetpack/input"]' },
	select_control: { form: 'controls', origin: 'control-3', front: '.contact-form__select-wrapper select', editor: '[data-type="jetpack/field-select"] [data-type="jetpack/input"]' },
	select_sibling_stacked: { form: 'controls', origin: 'control-3', front: '.contact-form__select-wrapper select', editor: '[data-type="jetpack/field-select"] [data-type="jetpack/input"]', margins: true },
	// Jetpack renders no element around the textarea: the declared container
	// selector lands on the textarea itself on the front end and on the field
	// block in the editor, so those are the boxes the part draws.
	control_container: { form: 'controls', origin: 'control-4', front: `${ FIELD( 4 ) } textarea`, editor: '[data-type="jetpack/field-textarea"]' },
	checkbox_control: { form: 'controls', origin: 'control-5', front: `${ FIELD( 5 ) } input[type="checkbox"]`, editor: 'input.jetpack-field-option__checkbox' },
	choice_label: { form: 'controls', origin: 'control-5', front: `${ FIELD( 5 ) } label.grunion-field-label`, editor: '.jetpack-field-option__label-wrapper > .rich-text' },
	choice_label_box: { form: 'controls', origin: 'control-5', front: `${ FIELD( 5 ) } .contact-form__checkbox-wrap`, editor: '[data-type="jetpack/field-checkbox"] .jetpack-field-option__label-wrapper > .rich-text', editorBox: '[data-type="jetpack/field-checkbox"] > [data-type="jetpack/option"]' },
	label: { form: 'controls', origin: 'control-0', front: `${ FIELD( 0 ) } label.grunion-field-label`, editor: '[data-type="jetpack/field-text"] [data-type="jetpack/label"] > label', editorBox: '[data-type="jetpack/field-text"] [data-type="jetpack/label"]' },
	wrapping_label_reset: { form: 'controls', origin: 'control-6', front: `${ FIELD( 6 ) } legend.grunion-field-label`, editor: '[data-type="jetpack/field-radio"] [data-type="jetpack/label"] > label', editorBox: '[data-type="jetpack/field-radio"] [data-type="jetpack/label"]', margins: true },
	required_marker: { form: 'controls', origin: 'control-0', front: `${ FIELD( 0 ) } .grunion-label-required`, editor: '[data-type="jetpack/field-text"] [data-type="jetpack/label"] > .required' },
	submit_wrapper: { form: 'controls', origin: 'control-8', front: '.wp-block-button', editor: '[data-type="core/button"]' },
	submit_link: { form: 'controls', origin: 'control-8', front: '.wp-block-button > .wp-block-button__link', editor: '[data-type="core/button"] > .wp-block-button__link' },
	submit_link_margin_reset: { form: 'controls', origin: 'control-8', front: '.wp-block-button > .wp-block-button__link', editor: '[data-type="core/button"] > .wp-block-button__link', margins: true },
	submit_caption: { form: 'controls', origin: 'control-8', front: '.wp-block-button__link > span', editor: '.wp-block-button__link > span' },
	submit_input_height_reset: { form: 'controls', origin: 'control-8', front: '.wp-block-button', editor: '[data-type="core/button"]' },
};

// Real mismatches, each filed under #2145 with its measured numbers.
const TODO = {
	form: 'https://github.com/Automattic/static-site-importer/issues/2165',
	native_form_box: 'https://github.com/Automattic/static-site-importer/issues/2166',
	native_form: 'https://github.com/Automattic/static-site-importer/issues/2166',
	field_list: 'https://github.com/Automattic/static-site-importer/issues/2167',
	box: 'https://github.com/Automattic/static-site-importer/issues/2169',
	wrap: 'https://github.com/Automattic/static-site-importer/issues/2169',
	country_trigger: 'https://github.com/Automattic/static-site-importer/issues/2170',
	control_container: 'https://github.com/Automattic/static-site-importer/issues/2171',
	checkbox_control: 'https://github.com/Automattic/static-site-importer/issues/2172',
	choice_label: 'https://github.com/Automattic/static-site-importer/issues/2172',
	choice_label_box: 'https://github.com/Automattic/static-site-importer/issues/2172',
	label: 'https://github.com/Automattic/static-site-importer/issues/2173',
	required_marker: 'https://github.com/Automattic/static-site-importer/issues/2173',
};

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${ process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace( /\/$/, '' ) }/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

let fixture;
function loadFixture() {
	if ( ! fixture ) {
		assert.ok( existsSync( transformer ), `Blocks Engine transformer is missing at ${ transformer }` );
		fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-role-box-parity-fixture.php' ], { encoding: 'utf8', env: process.env, maxBuffer: 64 * 1024 * 1024 } ) );
	}
	return fixture;
}

/** In-page: resolve the role element, check selector realisation, measure the box. */
function probe( { locator, boxLocator, paddingFrom, selectors } ) {
	const root = document.getElementById( 'ssi-role-root' );
	const found = root.querySelectorAll( locator );
	const node = found[ 0 ] ?? null;
	const box = boxLocator ? root.querySelector( boxLocator ) : node;
	const outermost = ( selector ) => {
		const matches = [ ...document.querySelectorAll( selector ) ];
		return matches.filter( ( element ) => ! matches.some( ( other ) => other !== element && other.contains( element ) ) );
	};
	const describe = ( element ) => element ? `<${ element.tagName.toLowerCase() } class="${ element.getAttribute( 'class' ) ?? '' }">` : 'nothing';
	const realisation = selectors.map( ( { selector, expect } ) => {
		const target = 'box' === expect ? box : node;
		const lands = outermost( selector );
		// Any other outermost match must draw no box (Jetpack's trailing empty
		// field-list wrapper, say).
		const others = lands.filter( ( element ) => element !== target );
		const ok = Boolean( target ) && lands.includes( target ) && others.every( ( element ) => 0 === element.getClientRects().length || ( 0 === element.getBoundingClientRect().width && 0 === element.getBoundingClientRect().height ) );
		return { selector, expect, ok, lands: lands.map( describe ), role: describe( target ) };
	} );
	if ( ! node || ! box ) {
		return { locatorCount: found.length, realisation };
	}
	const px = ( value ) => parseFloat( value ) || 0;
	const inner = getComputedStyle( node );
	const outer = getComputedStyle( box );
	const padded = 'node' === paddingFrom ? inner : outer;
	return {
		locatorCount: found.length,
		realisation,
		metrics: {
			height: box.getBoundingClientRect().height,
			paddingBlockStart: px( padded.paddingTop ),
			paddingBlockEnd: px( padded.paddingBottom ),
			paddingInlineStart: px( padded.paddingLeft ),
			paddingInlineEnd: px( padded.paddingRight ),
			borderTopWidth: px( inner.borderTopWidth ),
			borderRightWidth: px( inner.borderRightWidth ),
			borderBottomWidth: px( inner.borderBottomWidth ),
			borderLeftWidth: px( inner.borderLeftWidth ),
			backgroundColor: inner.backgroundColor,
			fontSize: px( inner.fontSize ),
			marginBlockStart: px( outer.marginTop ),
			marginBlockEnd: px( outer.marginBottom ),
		},
	};
}

const MARGIN_METRICS = [ 'marginBlockStart', 'marginBlockEnd' ];

/** Differences over the tolerance between the two surfaces' metrics. */
function differences( front, editor, pair ) {
	const out = [];
	for ( const [ key, value ] of Object.entries( front ) ) {
		if ( MARGIN_METRICS.includes( key ) && ! pair.margins ) {
			continue;
		}
		const other = editor[ key ];
		const same = 'number' === typeof value ? Math.abs( value - other ) <= TOLERANCE : value === other;
		if ( ! same ) {
			out.push( `${ key }: front ${ value } vs editor ${ other }` );
		}
	}
	return out;
}

/** The emitted instance a pair measures. */
function instanceFor( part, pair ) {
	const form = loadFixture().forms.find( ( candidate ) => candidate.name === pair.form );
	assert.ok( form, `${ part }: unknown source form ${ pair.form }` );
	const instance = form.instances.find( ( candidate ) => candidate.part === part && candidate.origin === pair.origin );
	assert.ok( instance, `${ part }: ${ pair.form } emits no ${ part } for ${ pair.origin }` );
	return { form, instance };
}

test( 'the pair table covers every declared, non-dropped form overlay part', () => {
	const { declared, forms } = loadFixture();
	const expected = Object.keys( declared ).filter( ( part ) => ! declared[ part ].dropped ).sort();
	assert.deepEqual( Object.keys( PAIRS ).sort(), expected, 'one role-box pair per declared non-dropped part (enumerated as tests/smoke-form-overlay-editor-parts.php does)' );
	// Every emitted instance is a declared part, and dropped parts stay dropped.
	for ( const form of forms ) {
		for ( const instance of form.instances ) {
			assert.ok( instance.part in declared, `${ form.name } ${ instance.origin }: undeclared part ${ instance.part }` );
			assert.equal( instance.dropped, declared[ instance.part ].dropped, `${ form.name } ${ instance.part }` );
		}
	}
	for ( const [ part, pair ] of Object.entries( PAIRS ) ) {
		instanceFor( part, pair );
	}
} );

/** In-page: each row element's top (relative to the first) and width. */
function rowBoxes( locators ) {
	const root = document.getElementById( 'ssi-role-root' );
	return locators.map( ( locator ) => {
		const found = root.querySelectorAll( locator );
		const rect = found[ 0 ]?.getBoundingClientRect();
		return { locator, count: found.length, top: rect?.top ?? null, width: rect?.width ?? null };
	} ).map( ( box, index, boxes ) => ( { ...box, top: null === box.top ? null : box.top - boxes[ 0 ].top } ) );
}

// A `space-y-*` form whose first row is a two-column grid of fields
// (`grid sm:grid-cols-2 gap-5`), then a full-width textarea and the submit
// (#2241). The front end lays the fields out as half-width items of Jetpack's
// wrapping flex field container; the editor must place each field block at
// the same top with the same width.
const TWO_COLUMN_ROWS = [
	{ front: FIELD( 0 ), editor: '[data-type="jetpack/field-text"]' },
	{ front: FIELD( 1 ), editor: '[data-type="jetpack/field-email"]' },
	{ front: FIELD( 2 ), editor: '[data-type="jetpack/field-textarea"]' },
	{ front: '.wp-block-button', editor: '[data-type="core/button"]' },
];

test( 'a two-column field row keeps its columns in the editor', async () => {
	assert.ok( chromium, 'Playwright is required; run `npm install`' );
	const form = loadFixture().forms.find( ( candidate ) => 'two_column' === candidate.name );
	assert.ok( form, 'the fixture seeds the two_column source form' );
	const browser = await chromium.launch();
	let front;
	let editor;
	try {
		const page = await browser.newPage( { viewport: VIEWPORT } );
		await page.setContent( frontDocument( form ) );
		front = await page.evaluate( rowBoxes, TWO_COLUMN_ROWS.map( ( row ) => row.front ) );
		await page.setContent( editorDocument( form ) );
		editor = await page.evaluate( rowBoxes, TWO_COLUMN_ROWS.map( ( row ) => row.editor ) );
	} finally {
		await browser.close();
	}
	if ( process.env.SSI_ROLE_BOX_REPORT ) {
		console.log( JSON.stringify( { front, editor }, null, '\t' ) );
	}
	for ( const boxes of [ front, editor ] ) {
		for ( const box of boxes ) {
			assert.equal( box.count, 1, `${ box.locator } names exactly one element` );
		}
	}
	// The front end itself lays the first two fields out side by side.
	assert.ok( Math.abs( front[ 1 ].top - front[ 0 ].top ) <= TOLERANCE, 'front end: the two-column fields share a row' );
	assert.ok( front[ 2 ].top > front[ 0 ].top, 'front end: the textarea starts a new row' );
	const mismatches = [];
	for ( const [ index, row ] of TWO_COLUMN_ROWS.entries() ) {
		for ( const metric of [ 'top', 'width' ] ) {
			if ( Math.abs( front[ index ][ metric ] - editor[ index ][ metric ] ) > TOLERANCE ) {
				mismatches.push( `${ row.editor } ${ metric }: front ${ front[ index ][ metric ] } vs editor ${ editor[ index ][ metric ] }` );
			}
		}
	}
	assert.deepEqual( mismatches, [], `editor field rows differ from the front end by more than ${ TOLERANCE }px` );
} );

test( 'each declared form overlay part draws the same role box on the front end and in the editor', async ( t ) => {
	assert.ok( chromium, 'Playwright is required; run `npm install`' );
	assert.ok( existsSync( chromium.executablePath() ), `Playwright Chromium is missing at ${ chromium.executablePath() }; run \`npx playwright install chromium\`` );
	const { forms } = loadFixture();
	const browser = await chromium.launch();
	const results = {};
	try {
		const page = await browser.newPage( { viewport: VIEWPORT } );
		for ( const form of forms ) {
			const parts = Object.entries( PAIRS ).filter( ( [ , pair ] ) => pair.form === form.name );
			if ( ! parts.length ) {
				continue;
			}
			await page.setContent( frontDocument( form ) );
			for ( const [ part, pair ] of parts ) {
				const { instance } = instanceFor( part, pair );
				const selectors = [ { selector: instance.front, expect: pair.frontBox ? 'box' : 'node' } ];
				results[ part ] = { instance, front: await page.evaluate( probe, { locator: pair.front, boxLocator: pair.frontBox, paddingFrom: pair.paddingFrom, selectors } ) };
			}
			await page.setContent( editorDocument( form ) );
			for ( const [ part, pair ] of parts ) {
				const { instance } = results[ part ];
				// The editor selector, and for a split realisation the box selector.
				const selectors = instance.editor.map( ( selector, index ) => ( { selector, expect: 1 === index ? 'box' : 'node' } ) );
				results[ part ].editor = await page.evaluate( probe, { locator: pair.editor, boxLocator: pair.editorBox, paddingFrom: pair.paddingFrom, selectors } );
			}
		}
	} finally {
		await browser.close();
	}
	if ( process.env.SSI_ROLE_BOX_REPORT ) {
		console.log( JSON.stringify( results, null, '\t' ) );
	}
	for ( const [ part, pair ] of Object.entries( PAIRS ) ) {
		await t.test( part, TODO[ part ] ? { todo: TODO[ part ] } : {}, () => {
			const { front, editor } = results[ part ];
			assert.equal( front.locatorCount, 1, `${ part }: front locator ${ pair.front } names exactly one element` );
			assert.equal( editor.locatorCount, 1, `${ part }: editor locator ${ pair.editor } names exactly one element` );
			for ( const [ surface, probed ] of [ [ 'front end', front ], [ 'editor', editor ] ] ) {
				for ( const check of probed.realisation ) {
					assert.ok( check.ok, `${ part }: on the ${ surface } ${ check.selector } lands on ${ check.lands.join( ', ' ) || 'nothing' }, not its role ${ check.role }` );
				}
			}
			assert.deepEqual( differences( front.metrics, editor.metrics, pair ), [], `${ part }: role box differs by more than ${ TOLERANCE }px` );
		} );
	}
} );
