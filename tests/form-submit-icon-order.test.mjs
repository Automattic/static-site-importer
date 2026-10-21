import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'submit icons retain source order and spacing at desktop and mobile widths', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-submit-icon-order.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/submit-icon-order.html', import.meta.url ), 'utf8' );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		const geometry = () => page.locator( 'button' ).evaluateAll( buttons => buttons.map( button => {
			const origin = button.getBoundingClientRect();
			const walker = document.createTreeWalker( button, NodeFilter.SHOW_TEXT );
			let node;
			let label;
			while ( ( node = walker.nextNode() ) ) {
				if ( node.textContent.trim() === 'Send' ) {
					const range = document.createRange();
					const start = node.textContent.indexOf( 'Send' );
					range.setStart( node, start );
					range.setEnd( node, start + 4 );
					label = range.getBoundingClientRect();
				}
			}
			return {
				width: origin.width,
				height: origin.height,
				icons: [ ...button.querySelectorAll( 'svg' ) ].map( icon => {
					const box = icon.getBoundingClientRect();
					return { x: box.x - label.x, y: box.y - label.y, width: box.width, height: box.height };
				} ),
			};
		} ) );
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.setContent( source );
			const expected = await geometry();
			const html = fixture.forms.map( form => {
				const scope = form.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
				return `<style>${ form.overlay }</style><div class="${ scope }">${ form.button }</div>`;
			} ).join( '' );
			await page.setContent( `<style>${ fixture.css }</style>${ html }` );
			assert.deepEqual( await geometry(), expected, `source box geometry at ${ width }px` );
		}
	} finally {
		await browser.close();
	}
} );
