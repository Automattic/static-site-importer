import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Jetpack's front-end select chrome, including its separate border chevron.
const providerCSS = `.contact-form .contact-form__select-wrapper{position:relative;padding:16px;border:1px solid;background:#eee}
.contact-form .contact-form__select-wrapper select{appearance:none;border:0!important}
.contact-form .contact-form__select-wrapper .jetpack-field-dropdown__icon{position:absolute;right:12px;top:16px}
.contact-form .contact-form__select-wrapper .jetpack-field-dropdown__icon::after{content:"";display:block;width:8px;height:8px;border:solid;border-width:0 2px 2px 0;transform:rotate(45deg)}`;

for ( const width of [ 1440, 390 ] ) {
	test( `native select keeps one arrow at ${ width }px`, async () => {
		const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-native-select.php', '--json' ], { encoding: 'utf8' } ) );
		const scope = fixture.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
		const browser = await chromium.launch( { headless: true } );
		try {
			const page = await browser.newPage( { viewport: { width, height: 800 } } );
			const measure = () => page.locator( 'select' ).evaluate( node => {
				const style = getComputedStyle( node );
				return { appearance: style.appearance, width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height };
			} );
			await page.setContent( readFileSync( new URL( './fixtures/native-select-form.html', import.meta.url ), 'utf8' ) );
			const source = await measure();
			assert.equal( source.appearance, 'auto' );
			await page.setContent( `<style>${ providerCSS }${ fixture.css }${ fixture.overlay }</style><div class="${ scope }">${ fixture.markup }</div>` );
			assert.deepEqual( await measure(), source );
			assert.equal( await page.locator( '.jetpack-field-dropdown__icon' ).isVisible(), false, 'provider chevron is not painted beside the native arrow' );
			// The same provider control outside the materialized scope is untouched.
			await page.locator( 'body' ).evaluate( ( body, markup ) => body.insertAdjacentHTML( 'beforeend', markup ), fixture.plain );
			assert.equal( await page.locator( '.jetpack-field-dropdown__icon' ).last().isVisible(), true );
		} finally {
			await browser.close();
		}
	} );
}
