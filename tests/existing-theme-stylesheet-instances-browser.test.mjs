import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// An existing-theme import publishes stylesheets through the companion loader.
// The materializer smoke imports linked A,B,A pages (ordinary rules and a named
// !important layer), media-differing occurrences and three routes, then records
// the enqueue sequence the generated loader emits for each imported page. Here
// Chromium compares each source page against that published sequence, which
// WordPress prints as one <link> per enqueued handle in enqueue order.
//
// Playwright is a devDependency installed by CI; skip where Chromium is absent.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};
const skip = chromium && existsSync( chromium.executablePath() )
	? false
	: 'playwright chromium is not installed; run `npm install` and `npx playwright install chromium`';

test( 'existing-theme companion loader replays each page stylesheet cascade', { skip }, async () => {
	const scratch = mkdtempSync( join( tmpdir(), 'ssi-stylesheet-instances-' ) );
	const evidencePath = join( scratch, 'evidence.json' );
	let browser;
	let server;
	try {
		try {
			execFileSync( 'php', [ '-d', 'memory_limit=2G', 'tests/smoke-wordpress-site-plan-materializer.php', `--existing-theme-stylesheet-instances-json=${ evidencePath }` ], { stdio: [ 'ignore', 'ignore', 'pipe' ], env: process.env } );
		} catch ( error ) {
			// The smoke asserts the published sequence too; compare the cascade it
			// emitted before failing so the browser difference is reported.
			if ( ! existsSync( evidencePath ) ) throw error;
		}
		const evidence = JSON.parse( readFileSync( evidencePath, 'utf8' ) );
		const files = new Map();
		for ( const [ path, content ] of Object.entries( evidence.source ) ) files.set( `/source/${ path }`, content );
		for ( const [ path, content ] of Object.entries( evidence.published_css ) ) files.set( `/published/${ path }`, content );
		const escape = value => value.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' );
		for ( const [ route, styles ] of Object.entries( evidence.published ) ) {
			const links = styles.map( style => `<link rel="stylesheet" id="${ escape( style.handle ) }-css" href="/published/${ escape( style.target ) }" media="${ escape( style.media ) }">` ).join( '' );
			files.set( `/published-page/${ route }`, `<!doctype html><html><head>${ links }</head><body>${ evidence.pages[ route ] }</body></html>` );
		}
		server = createServer( ( request, response ) => {
			const path = decodeURIComponent( new URL( request.url, 'http://local' ).pathname );
			response.statusCode = files.has( path ) ? 200 : 404;
			response.setHeader( 'Content-Type', path.endsWith( '.css' ) ? 'text/css' : 'text/html' );
			response.end( files.get( path ) ?? '' );
		} );
		await new Promise( resolve => server.listen( 0, '127.0.0.1', resolve ) );
		const origin = `http://127.0.0.1:${ server.address().port }`;
		browser = await chromium.launch( { headless: true } );
		const observe = async ( path, width ) => {
			const page = await browser.newPage( { viewport: { width, height: 400 } } );
			await page.goto( `${ origin }${ path }`, { waitUntil: 'load' } );
			const colors = await page.evaluate( () => Object.fromEntries( [ 'ordinary', 'named' ].map( name => [ name, getComputedStyle( document.querySelector( `.${ name }` ) ).color ] ) ) );
			await page.close();
			return colors;
		};
		const routes = Object.keys( evidence.published ).sort();
		assert.deepEqual( routes, [ 'about.html', 'index.html', 'media.html' ] );
		const red = 'rgb(255, 0, 0)';
		const blue = 'rgb(0, 0, 255)';
		const expected = { 'index.html': { ordinary: red, named: red }, 'about.html': { ordinary: blue, named: blue } };
		let comparisons = 0;
		for ( const width of [ 390, 768, 1440 ] ) {
			for ( const route of routes ) {
				const source = await observe( `/source/${ route }`, width );
				// Guard the fixture: the source cascade is the A,B,A winner itself.
				const sourceExpected = expected[ route ] ?? ( width >= 1200 ? { ordinary: red, named: red } : { ordinary: blue, named: blue } );
				assert.deepEqual( source, sourceExpected, `${ route } / ${ width }px: source fixture cascade` );
				assert.deepEqual( await observe( `/published-page/${ route }`, width ), source, `${ route } / ${ width }px: the existing-theme published cascade matches the source` );
				++comparisons;
			}
		}
		assert.equal( comparisons, 9 );
	} finally {
		await browser?.close();
		if ( server ) await new Promise( resolve => server.close( resolve ) );
		rmSync( scratch, { recursive: true, force: true } );
	}
} );
