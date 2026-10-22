import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'materialized placeholders preserve source colours, responsive rules and focus', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-placeholder-colors.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/placeholder-form.html', import.meta.url ), 'utf8' );
	const scope = fixture.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
	// Reduced Jetpack server-render model: field attributes come from seeded blocks.
	const fields = [ ...fixture.markup.matchAll( /<!-- wp:jetpack\/input (.*?) -->/g ) ].map( ( match ) => JSON.parse( match[ 1 ] ) );
	assert.equal( fields.length, 2 );
	const controls = fields.map( ( attrs, index ) => {
		const tag = index === 0 ? 'input' : 'textarea';
		return `<div class="grunion-field-wrap"><${ tag } class="grunion-field ${ attrs.className ?? '' }" placeholder="${ attrs.placeholder }">${ tag === 'textarea' ? '</textarea>' : '' }</div>`;
	} ).join( '' );
	const provider = '.contact-form input::placeholder,.contact-form textarea::placeholder{color:inherit}';
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		const colours = () => page.locator( 'input,textarea' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => getComputedStyle( node, '::placeholder' ).color ) );
		for ( const width of [ 600, 1000 ] ) {
			await page.setViewportSize( { width, height: 700 } );
			await page.setContent( source );
			const base = await colours();
			assert.equal( base[ 1 ], 'rgb(156, 163, 175)' );
			assert.equal( base[ 0 ], width < 800 ? 'rgb(156, 163, 175)' : 'rgb(37, 99, 235)' );
			await page.locator( 'textarea' ).focus();
			const focused = await colours();
			assert.equal( focused[ 1 ], 'rgb(190, 18, 60)' );
			for ( const stylesheet of [ fixture.css, fixture.editor ] ) {
				await page.setContent( `<style>${ provider }${ stylesheet }</style><div class="editor-styles-wrapper"><form class="contact-form ${ scope }">${ controls }</form></div>` );
				assert.deepEqual( await colours(), base );
				await page.locator( 'textarea' ).focus();
				assert.deepEqual( await colours(), focused );
			}
			await page.locator( 'textarea' ).blur();
			await page.addStyleTag( { content: ':root{--hint-color:#15803d}' } );
			assert.equal( ( await colours() )[ 1 ], 'rgb(21, 128, 61)' );
			await page.addStyleTag( { content: `.${ scope } input::placeholder,.${ scope } textarea::placeholder{color:#15803d!important}` } );
			assert.deepEqual( await colours(), [ 'rgb(21, 128, 61)', 'rgb(21, 128, 61)' ] );
		}
		await page.setContent( `<style>${ provider }${ fixture.fallback }</style><form class="contact-form ${ scope }">${ controls }</form>` );
		const fallback = await colours();
		await page.setContent( '<style>input,textarea{color:#123456}</style><input placeholder="Name"><textarea placeholder="Message"></textarea>' );
		assert.deepEqual( fallback, await colours(), 'unauthored placeholders retain browser defaults' );
	} finally {
		await browser.close();
	}
} );
