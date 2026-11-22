import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

test( 'imported submit presentation yields to native typography and spacing', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-submit-carriers.php' ], { encoding: 'utf8' } ) );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		await page.setContent( `<style>${ fixture.css }</style>${ fixture.html }` );
		const button = page.locator( 'button[type="submit"]' );
		const measure = () => button.evaluate( ( node ) => {
			const style = getComputedStyle( node );
			return { fontSize: style.fontSize, paddingTop: style.paddingTop };
		} );
		assert.deepEqual( await measure(), { fontSize: '16px', paddingTop: '12px' } );
		// core/button saves custom inspector settings on the inner link.
		await button.evaluate( ( node ) => {
			node.style.fontSize = '37px';
			node.style.paddingTop = '23px';
			node.classList.add( 'has-custom-font-size' );
		} );
		assert.deepEqual( await measure(), { fontSize: '37px', paddingTop: '23px' } );
	} finally {
		await browser.close();
	}
} );
