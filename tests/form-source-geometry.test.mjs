import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { IMPORTED_THEME_LAYOUT_CSS, editorDocument, frontDocument } from './fixtures/form-role-box-parity-jetpack-models.mjs';

// An imported form keeps the source form's geometry. Each reduced source form
// in tests/fixtures/form-source-geometry-fixture.php is rendered as the source
// page, as the front end (Jetpack's server render through the provider runtime
// filters) and as the editor canvas, at phone, tablet and desktop widths. The
// form box and every control (and the submit) must sit at the same place, at
// the same size, on all three surfaces.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const TOLERANCE = 1;
const VIEWPORTS = [ 390, 768, 1440 ];

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${ process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace( /\/$/, '' ) }/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

// Per surface: the form box, and the boxes standing for each source control in
// source order (Jetpack renders one field per control, then the submit).
const SURFACES = {
	source: {
		form: 'main > form',
		controls: 'form :is(input:not([type="hidden"]), select, textarea, button[type="submit"])',
	},
	front: {
		form: '.wp-block-jetpack-contact-form > .wp-block-group',
		controls: '[data-jp-field-id] :is(input:not([type="hidden"]):not(.jetpack-combobox-search), select, textarea), .wp-block-button__link',
	},
	editor: {
		form: '[data-type="jetpack/contact-form"] [data-type="core/group"]',
		controls: ':is([data-type="jetpack/input"], [data-type="jetpack/phone-input"], input.jetpack-field-option__checkbox, [data-type="core/button"] .wp-block-button__link)',
	},
};

/** In-page: the form box and each control box, relative to the form's origin. */
function measure( { form, controls } ) {
	const root = document.querySelector( form );
	if ( ! root ) {
		return null;
	}
	const origin = root.getBoundingClientRect();
	const box = ( node ) => {
		const rect = node.getBoundingClientRect();
		return { left: rect.left - origin.left, top: rect.top - origin.top, width: rect.width, height: rect.height };
	};
	return { form: { width: origin.width, height: origin.height }, controls: [ ...document.querySelectorAll( controls ) ].map( box ) };
}

/** Differences over the tolerance between two surfaces' boxes. */
function differences( expected, actual ) {
	const out = [];
	const compare = ( label, a, b ) => {
		for ( const key of Object.keys( a ) ) {
			if ( Math.abs( a[ key ] - b[ key ] ) > TOLERANCE ) {
				out.push( `${ label } ${ key }: ${ Math.round( a[ key ] * 10 ) / 10 } vs ${ Math.round( b[ key ] * 10 ) / 10 }` );
			}
		}
	};
	compare( 'form', expected.form, actual.form );
	if ( expected.controls.length !== actual.controls.length ) {
		out.push( `control count: ${ expected.controls.length } vs ${ actual.controls.length }` );
		return out;
	}
	expected.controls.forEach( ( control, index ) => compare( `control ${ index }`, control, actual.controls[ index ] ) );
	return out;
}

test( 'imported forms keep the source form geometry on the front end and in the editor', async ( t ) => {
	assert.ok( chromium, 'Playwright is required; run `npm install`' );
	assert.ok( existsSync( chromium.executablePath() ), `Playwright Chromium is missing at ${ chromium.executablePath() }; run \`npx playwright install chromium\`` );
	assert.ok( existsSync( transformer ), `Blocks Engine transformer is missing at ${ transformer }` );
	const { forms } = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-source-geometry-fixture.php' ], { encoding: 'utf8', env: process.env, maxBuffer: 64 * 1024 * 1024 } ) );
	const browser = await chromium.launch();
	const results = [];
	try {
		const page = await browser.newPage();
		for ( const form of forms ) {
			const documents = { source: `<!doctype html><html><body>${ form.sourceHtml }</body></html>`, front: frontDocument( form, { layoutCss: IMPORTED_THEME_LAYOUT_CSS } ), editor: editorDocument( form, { layoutCss: IMPORTED_THEME_LAYOUT_CSS } ) };
			for ( const width of VIEWPORTS ) {
				await page.setViewportSize( { width, height: 1200 } );
				const boxes = {};
				for ( const [ surface, html ] of Object.entries( documents ) ) {
					await page.setContent( html );
					boxes[ surface ] = await page.evaluate( measure, SURFACES[ surface ] );
				}
				results.push( { form: form.name, width, boxes } );
			}
		}
	} finally {
		await browser.close();
	}
	if ( process.env.SSI_GEOMETRY_REPORT ) {
		console.log( JSON.stringify( results, null, '\t' ) );
	}
	for ( const { form, width, boxes } of results ) {
		await t.test( `${ form } at ${ width }px`, () => {
			for ( const surface of [ 'source', 'front', 'editor' ] ) {
				assert.ok( boxes[ surface ], `${ surface }: the form box renders` );
			}
			// The front end against the source, and the editor against the front end.
			assert.deepEqual( [
				...differences( boxes.source, boxes.front ).map( ( line ) => `front ${ line }` ),
				...differences( boxes.front, boxes.editor ).map( ( line ) => `editor ${ line }` ),
			], [] );
		} );
	}
} );
