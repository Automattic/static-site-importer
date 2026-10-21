import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'reduced quote form preserves submit colours, hover and palette restyles', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-submit-colors.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/quote-form.html', import.meta.url ), 'utf8' );
	const scope = fixture.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		const colour = () => page.locator( 'button' ).evaluate( ( button ) => ( {
			background: getComputedStyle( button ).backgroundColor,
			text: getComputedStyle( button ).color,
		} ) );
		await page.setContent( source );
		const base = await colour();
		await page.locator( 'button' ).hover();
		const hover = await colour();
		assert.equal( base.background, 'rgb(37, 99, 235)' );
		assert.equal( hover.background, 'rgb(29, 78, 216)' );
		await page.mouse.move( 0, 0 );
		await page.setContent( `<style>${ fixture.css }${ fixture.overlay }</style><div class="${ scope }">${ fixture.button }</div>` );
		assert.deepEqual( await colour(), base );
		await page.locator( 'button' ).hover();
		assert.deepEqual( await colour(), hover );
		await page.mouse.move( 0, 0 );
		await page.addStyleTag( { content: ':root{--wp--preset--color--primary:#e11d48}' } );
		assert.equal( ( await colour() ).background, 'rgb(225, 29, 72)' );
		await page.locator( 'button' ).hover();
		assert.deepEqual( await colour(), hover );
	} finally {
		await browser.close();
	}
} );
