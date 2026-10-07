import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const [ url, requestPath, evidence, phase ] = process.argv.slice( 2 );
assert.ok( url && requestPath && evidence && phase, 'supply URL, retained source request, evidence directory and phase' );
const request = JSON.parse( await readFile( requestPath, 'utf8' ) );
const html = request.source.files.find( ( file ) => file.path === request.source.entrypoint ).content;
const browser = await chromium.launch( { headless: true } );
try {
	const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
	const painted = () => page.waitForFunction( () => {
		const canvas = document.querySelector( '#acceptance-chart' );
		if ( ! canvas ) return false;
		const pixel = canvas.getContext( '2d' ).getImageData( 50, 30, 1, 1 ).data;
		return pixel[0] === 220 && pixel[1] === 20 && pixel[2] === 60 && pixel[3] === 255;
	} );
	await page.setContent( html, { waitUntil: 'domcontentloaded' } );
	await painted();
	const sourceBytes = await page.locator( '#acceptance-chart' ).screenshot( { path: `${evidence}/${phase}-source-runtime.png` } );
	const scripts = [];
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'response', ( response ) => {
		if ( response.request().resourceType() === 'script' ) scripts.push( { url: response.url(), status: response.status() } );
	} );
	await page.goto( url, { waitUntil: 'networkidle' } );
	await writeFile( `${evidence}/${phase}-frontend.html`, await page.content() );
	await writeFile( `${evidence}/${phase}-script-responses.json`, JSON.stringify( { scripts, errors }, null, 2 ) );
	console.log( JSON.stringify( { phase, scripts, errors, canvases: await page.locator( 'canvas' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.outerHTML ) ) } ) );
	await painted();
	const importedBytes = await page.locator( '#acceptance-chart' ).screenshot( { path: `${evidence}/${phase}-import-runtime.png` } );
	assert.deepEqual( errors, [], 'frontend runtime has no JavaScript errors' );
	const source = PNG.sync.read( sourceBytes );
	const imported = PNG.sync.read( importedBytes );
	assert.equal( imported.width, source.width );
	assert.equal( imported.height, source.height );
	const diff = new PNG( { width: source.width, height: source.height } );
	const changedPixels = pixelmatch( source.data, imported.data, diff.data, source.width, source.height, { threshold: 0.1 } );
	await writeFile( `${evidence}/${phase}-runtime-diff.png`, PNG.sync.write( diff ) );
	assert.equal( changedPixels, 0, 'source/import runtime visual region has exact parity' );
	assert.ok( scripts.some( ( script ) => script.url.includes( '/wp-content/themes/' ) && script.status === 200 ), 'theme-owned frontend script served successfully' );
	assert.ok( scripts.every( ( script ) => ! script.url.includes( '/wp-content/plugins/ssi-' ) ), 'no generated companion script URL' );
	const link = page.getByRole( 'link', { name: 'Source home route', exact: true } );
	const target = new URL( await link.getAttribute( 'href' ), url );
	assert.equal( target.origin, new URL( url ).origin, 'internal route resolves to this destination site' );
	assert.ok( ! target.pathname.endsWith( '.html' ), 'source internal link rewritten to native destination route' );
	const result = { phase, url, visualRegion: '#acceptance-chart', changedPixels, scripts, internalLink: target.href };
	await writeFile( `${evidence}/${phase}-runtime-browser.json`, JSON.stringify( result, null, 2 ) );
	console.log( JSON.stringify( result ) );
} finally {
	await browser.close();
}
