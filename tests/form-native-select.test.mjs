import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'source-owned select keeps one native arrow at desktop and mobile widths', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-native-select.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/native-select-form.html', import.meta.url ), 'utf8' );
	const scope = fixture.markup.match( /ssi-form-[a-f0-9]{12}/ )[ 0 ];
	const input = JSON.parse( fixture.markup.match( /<!-- wp:jetpack\/input (.*?) -->/ )[ 1 ] );
	const field = JSON.parse( fixture.markup.match( /<!-- wp:jetpack\/field-select (.*?) -->/ )[ 1 ] );
	// Reduced Jetpack dropdown DOM and relevant provider CSS. The generated
	// hooks come from the seeded blocks, not a fabricated overlay target.
	const dropdown = ( hook = '' ) => `<div class="contact-form__select-wrapper ${ hook }"><select><option>General question</option><option>Support</option></select><div class="jetpack-field-dropdown__icon"></div></div>`;
	const provider = `.contact-form .contact-form__select-wrapper{position:relative}.contact-form .contact-form__select-wrapper select{appearance:none;width:100%;height:40px;padding:8px;box-sizing:border-box}.contact-form .contact-form__select-wrapper .jetpack-field-dropdown__icon{position:absolute;right:12px;top:12px;width:12px;height:12px}.contact-form .contact-form__select-wrapper .jetpack-field-dropdown__icon::after{content:"";display:block;width:8px;height:8px;border-right:2px solid;border-bottom:2px solid;transform:rotate(45deg)}`;
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 800 } );
			await page.setContent( source );
			const sourceSelect = await page.locator( 'select' ).evaluate( ( select ) => ( { appearance: getComputedStyle( select ).appearance, width: select.getBoundingClientRect().width, height: select.getBoundingClientRect().height } ) );
			await page.setContent( `<style>${ provider }${ fixture.overlay }</style><div class="contact-form jetpack-contact-form-container ${ scope }"><form class="jetpack-contact-form__form"><div class="grunion-field-wrap ${ field.className }-wrap"><div class="${ field.className }">${ dropdown( input.className ) }</div></div></form></div><div class="contact-form ordinary">${ dropdown() }</div>` );
			const imported = page.locator( `.${ scope } select` );
			assert.deepEqual( await imported.evaluate( ( select ) => ( { appearance: getComputedStyle( select ).appearance, width: select.getBoundingClientRect().width, height: select.getBoundingClientRect().height } ) ), sourceSelect );
			assert.equal( await page.locator( `.${ scope } .jetpack-field-dropdown__icon` ).evaluate( ( icon ) => icon.getClientRects().length ), 0, 'no second chevron box' );
			assert.equal( await page.locator( '.ordinary .jetpack-field-dropdown__icon' ).evaluate( ( icon ) => getComputedStyle( icon ).display ), 'block', 'ordinary provider decoration is untouched' );
		}
	} finally {
		await browser.close();
	}
} );
