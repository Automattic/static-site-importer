import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

test( 'submit presentation and wrapper resets yield to native block controls', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-submit-carriers.php' ], { encoding: 'utf8' } ) );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		await page.setContent( `<style>${ fixture.css }</style>${ fixture.html }` );
		const button = page.locator( 'button[type="submit"]' );
		assert.deepEqual( await button.evaluate( ( node ) => [ getComputedStyle( node ).fontSize, getComputedStyle( node ).paddingTop ] ), [ '16px', '12px' ] );
		// Both Core's wrapper and its link can carry saved block-support styles.
		for ( const target of [ button, page.locator( '.wp-block-button' ).last() ] ) {
			await target.evaluate( ( node ) => {
				node.style.fontSize = '37px';
				node.style.paddingTop = '23px';
				node.classList.add( 'has-custom-font-size' );
			} );
			assert.deepEqual( await target.evaluate( ( node ) => [ getComputedStyle( node ).fontSize, getComputedStyle( node ).paddingTop ] ), [ '37px', '23px' ] );
		}
	} finally {
		await browser.close();
	}
} );
