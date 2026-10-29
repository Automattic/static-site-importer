import { createServer } from 'node:http';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

// Operator-only fixture refresh. Point CAPTURE_PRODUCER_ROOT at a built public capture
// checkout. Normal tests use the committed, portable export and need no producer.
const root = process.env.CAPTURE_PRODUCER_ROOT;
if ( !root ) throw new Error( 'CAPTURE_PRODUCER_ROOT must name a built public capture checkout.' );
const { captureWebsite } = await import( pathToFileURL( resolve( root, 'dist/lib/capture.js' ) ) );
const { nextjsAdapter } = await import( pathToFileURL( resolve( root, 'dist/adapters/nextjs/index.js' ) ) );
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage();
const images = await page.evaluate( () => {
	const canvas = document.createElement( 'canvas' );
	canvas.width = 203; canvas.height = 90;
	canvas.getContext( '2d' ).fillRect( 0, 0, 100, 90 );
	return { original: canvas.toDataURL( 'image/webp' ).split( ',' )[ 1 ], proxy: canvas.toDataURL( 'image/jpeg' ).split( ',' )[ 1 ] };
} );
await browser.close();
const proxy = ( width ) => `/_next/image?url=%2Fpreview.webp&w=${ width }&q=75`;
const server = createServer( ( request, response ) => {
	const path = new URL( request.url, 'http://example.org' ).pathname;
	if ( path === '/garden-logo.webp' ) { response.setHeader( 'content-type', 'image/webp' ); response.end( Buffer.from( images.original, 'base64' ) ); }
	else if ( path === '/_next/image' || path === '/preview.webp' ) { response.setHeader( 'content-type', 'image/jpeg' ); response.end( Buffer.from( images.proxy, 'base64' ) ); }
	else if ( path === '/site.css' ) { response.setHeader( 'content-type', 'text/css' ); response.end( `.hero { background-image: url('${ proxy( 203 ) }'); height:90px; }` ); }
	else { response.setHeader( 'content-type', 'text/html' ); response.end( `<!doctype html><html><head><title>Image record fixture</title><link rel="icon" href="${ proxy( 67 ) }"><link rel="stylesheet" href="/site.css"></head><body><main><h1>Image record fixture</h1><a href="/garden-logo.webp" data-lightbox="garden"><img alt="Transparent garden logo" src="${ proxy( 67 ) }" srcset="${ proxy( 203 ) } 203w, ${ proxy( 67 ) } 67w"></a><div class="hero">Garden</div></main></body></html>` ); }
} );
await new Promise( ( done ) => server.listen( 0, '127.0.0.1', done ) );
const url = `http://127.0.0.1:${ server.address().port }/`;
const outputDir = resolve( process.env.CAPTURE_OUTPUT || '/tmp/ssi-image-record-capture' );
try {
	const result = await captureWebsite( { url, outputDir, trustedOrigins: [ url ], learnFluid: false, routeLimit: 1 }, { findAdapter: () => ( { ...nextjsAdapter, discover: async () => ( { urls: [] } ) } ) } );
	const receipt = JSON.parse( readFileSync( result.captureReceiptPath, 'utf8' ) );
	const directory = resolve( 'tests/fixtures/image-asset-capture' );
	const normalize = ( text ) => text.replaceAll( url.slice( 0, -1 ), 'https://example.org' );
	mkdirSync( directory, { recursive: true } );
	// Keep the real portable HTML, source stylesheet and verified original. Other
	// capture evidence (screenshots/timings) is unrelated to destination identity.
	const assets = receipt.assets.filter( ( asset ) => asset.original || asset.path.endsWith( '.css' ) );
	const files = [ { path: 'website/index.html', content: normalize( readFileSync( resolve( outputDir, 'website/index.html' ), 'utf8' ) ) } ];
	for ( const asset of assets ) {
		const bytes = readFileSync( resolve( outputDir, asset.path ) );
		files.push( asset.original ? { path: asset.path, content_base64: bytes.toString( 'base64' ) } : { path: asset.path, content: normalize( bytes.toString( 'utf8' ) ) } );
	}
	files.push( { path: 'capture-receipt.json', content: normalize( JSON.stringify( { schema: receipt.schema, websiteRoot: receipt.websiteRoot, assets } ) ) } );
	writeFileSync( resolve( directory, 'artifact.json' ), JSON.stringify( { entrypoint: 'website/index.html', files }, null, 2 ) + '\n' );
} finally {
	await new Promise( ( done ) => server.close( done ) );
}
