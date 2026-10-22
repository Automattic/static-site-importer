import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'submit owns its box without overflowing or shifting neighbouring content', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-submit-box.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/submit-box.html', import.meta.url ), 'utf8' );
	const scope = fixture.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		for ( const width of [ 1440, 375 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.setContent( source );
			const measure = () => page.locator( 'form' ).evaluate( ( form ) => {
				const button = form.querySelector( 'button' ).getBoundingClientRect();
				const before = form.querySelector( 'textarea' ).getBoundingClientRect();
				const after = form.querySelector( 'p' ).getBoundingClientRect();
				return { height: button.height, gapBefore: button.top - before.bottom, gapAfter: after.top - button.bottom };
			} );
			const expected = await measure();
			assert.equal( expected.height, width === 1440 ? 48 : 56 );
			assert.equal( expected.gapBefore, 22 );
			assert.equal( expected.gapAfter, 22 );
			// Keep neutral neighbours unchanged; replace only the source submit with
			// the seeded Core button after the provider's runtime class projection.
			const neighbours = await page.locator( 'form' ).evaluate( ( form ) => [ form.firstElementChild.outerHTML, form.lastElementChild.outerHTML ] );
			await page.setContent( `<style>${ fixture.css }
				p{margin:0}
				.wp-block-button{display:flex;align-items:center}
				.wp-block-button__link{display:inline-block}
				${ fixture.overlay }</style><form class="${ scope }">${ neighbours[ 0 ] }${ fixture.button }${ neighbours[ 1 ] }</form>` );
			assert.deepEqual( await measure(), expected, `source geometry at ${ width }px` );
			const boxes = await page.locator( '.wp-block-button' ).evaluate( ( wrapper ) => {
				const outer = wrapper.getBoundingClientRect();
				const inner = wrapper.querySelector( 'button' ).getBoundingClientRect();
				return { top: inner.top - outer.top, bottom: outer.bottom - inner.bottom };
			} );
			assert.deepEqual( boxes, { top: 0, bottom: 0 } );
		}
	} finally {
		await browser.close();
	}
} );
