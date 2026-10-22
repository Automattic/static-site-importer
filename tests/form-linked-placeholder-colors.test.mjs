import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test( 'linked frontend and editor stylesheets preserve source placeholder colours', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/smoke-form-linked-placeholder-colors.php', '--json' ], { encoding: 'utf8' } ) );
	const source = readFileSync( new URL( './fixtures/linked-placeholder-form.html', import.meta.url ), 'utf8' );
	const sourceCss = readFileSync( new URL( './fixtures/assets/form.css', import.meta.url ), 'utf8' );
	const scope = fixture.overlay.match( /\.ssi-form-[a-f0-9]{12}/ )[ 0 ].slice( 1 );
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
		let linkedCss = sourceCss;
		await page.route( 'https://fixture.test/**', ( route ) => route.fulfill( {
			contentType: route.request().url().endsWith( '.css' ) ? 'text/css' : 'text/html',
			body: route.request().url().endsWith( '.css' ) ? linkedCss : source,
		} ) );
		const colours = () => page.locator( 'input,textarea' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => ( {
			color: getComputedStyle( node, '::placeholder' ).color,
			opacity: getComputedStyle( node, '::placeholder' ).opacity,
		} ) ) );
		for ( const width of [ 600, 1000 ] ) {
			await page.setViewportSize( { width, height: 700 } );
			linkedCss = sourceCss;
			await page.goto( 'https://fixture.test/index.html' );
			const base = await colours();
			assert.equal( base[ 1 ].color, 'rgb(156, 163, 175)' );
			assert.equal( base[ 0 ].color, width < 800 ? 'rgb(156, 163, 175)' : 'rgb(37, 99, 235)' );
			await page.locator( 'textarea' ).focus();
			const focused = await colours();
			assert.equal( focused[ 1 ].color, 'rgb(190, 18, 60)' );
			for ( const [ path, css ] of Object.entries( fixture.assets ) ) {
				linkedCss = css;
				const editor = path.endsWith( '.editor.css' );
				await page.setContent( `<link rel="stylesheet" href="https://fixture.test/${ path }"><style>${ provider }${ editor ? fixture.editor : fixture.css }</style><div class="editor-styles-wrapper"><form class="contact-form ${ scope }">${ controls }</form></div>` );
				await page.locator( 'link' ).evaluate( ( link ) => link.sheet ? undefined : new Promise( ( resolve ) => link.addEventListener( 'load', resolve, { once: true } ) ) );
				assert.deepEqual( await colours(), base, path );
				await page.locator( 'textarea' ).focus();
				assert.deepEqual( await colours(), focused, path );
				await page.locator( 'textarea' ).blur();
				await page.addStyleTag( { content: ':root{--hint-color:#15803d}' } );
				assert.equal( ( await colours() )[ 1 ].color, 'rgb(21, 128, 61)', 'authored variables remain live' );
			}
		}
	} finally {
		await browser.close();
	}
} );
