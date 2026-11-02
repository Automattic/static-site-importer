import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'submit carriers preserve author inheritance and paint like link buttons (#2507)', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-submit-carriers.php' ], { encoding: 'utf8' } ) );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		const presentation = ( selector ) => page.locator( selector ).evaluate( ( node ) => {
			const style = getComputedStyle( node );
			return Object.fromEntries( [ 'fontFamily', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'backgroundColor', 'borderTopWidth', 'borderTopStyle', 'borderTopColor' ].map( ( key ) => [ key, style[ key ] ] ) );
		} );
		await page.setContent( fixture.source );
		const sourceSubmit = await presentation( 'button' );
		const sourceLink = await presentation( 'a' );
		assert.equal( sourceSubmit.fontFamily, '"Courier New", monospace' );
		await page.setContent( `<style>${ fixture.css }</style>${ fixture.html }` );
		assert.deepEqual( await presentation( 'button' ), sourceSubmit, 'submit keeps source typography, padding, background and border' );
		assert.deepEqual( await presentation( 'a' ), sourceLink, 'link button is unchanged' );
		for ( const selector of [ '.wp-block-buttons', '.wp-block-button' ] ) {
			const wrappers = await page.locator( selector ).evaluateAll( ( nodes ) => nodes.map( ( node ) => {
				const style = getComputedStyle( node );
				return { carrier: node.classList.contains( 'blocks-engine-role-control' ), sourceClass: node.classList.contains( 'cta' ), padding: style.padding, background: style.backgroundColor, border: style.borderWidth, font: style.fontFamily };
			} ) );
			assert.equal( wrappers.length, 2 );
			assert.deepEqual( wrappers[ 1 ], wrappers[ 0 ], 'submit and link wrappers share carrier placement and do not double paint' );
			assert.deepEqual( wrappers[ 1 ], { carrier: true, sourceClass: false, padding: '0px', background: 'rgba(0, 0, 0, 0)', border: '0px', font: sourceSubmit.fontFamily } );
		}
		assert.equal( await page.locator( '.cta' ).count(), 0, 'source class is represented by subject carriers, not repeated on inner controls' );
	} finally {
		await browser.close();
	}
} );

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
