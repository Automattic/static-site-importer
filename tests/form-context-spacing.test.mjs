import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'trailing context copy keeps the source submit spacing', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-context-spacing.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/form-context-spacing.html', import.meta.url ), 'utf8' );
	// Dynamic Jetpack fields have no saved controls. Simulate its field wrapper
	// and input shape, retaining the materializer's actual identity classes.
	const markup = fixture.markup.replace( /<!-- wp:jetpack\/field-[\w-]+ (.*?) -->([\s\S]*?)<!-- \/wp:jetpack\/field-[\w-]+ -->/g, ( _, json, content ) => {
		const attrs = JSON.parse( json );
		const classes = attrs.className.split( /\s+/ ).map( ( name ) => `${ name }-wrap` ).join( ' ' );
		return `<div class="grunion-field-wrap ${ classes }" style="width:${ attrs.width || 100 }%">${ content
			.replace( /<!-- wp:jetpack\/label (.*?) -->/, ( _label, data ) => `<label>${ JSON.parse( data ).label }</label>` )
			.replace( /<!-- wp:jetpack\/input .*? -->/, '<input>' ) }</div>`;
	} );
	const browser = await chromium.launch( { headless: true } );
	try {
		for ( const width of [ 390, 1440 ] ) {
			const page = await browser.newPage( { viewport: { width, height: 900 } } );
			const spacing = () => page.locator( 'p.note' ).evaluate( ( note ) => note.getBoundingClientRect().top - note.previousElementSibling.getBoundingClientRect().bottom );
			await page.setContent( source );
			const expected = await spacing();
			assert.equal( expected, 16 );
			// Jetpack supplies the wrapping flex host; SSI supplies its gap and
			// field/submit/context margins through the emitted stylesheets.
			await page.setContent( `<style>${ fixture.css }${ fixture.overlay }
			.wp-block-jetpack-contact-form{display:flex;flex-wrap:wrap}
			.grunion-field-wrap,.wp-block-paragraph{flex-basis:100%}
			</style>${ markup }` );
			assert.equal( await spacing(), expected, `submit-to-note spacing at ${ width }px` );
			await page.close();
		}
	} finally {
		await browser.close();
	}
} );
