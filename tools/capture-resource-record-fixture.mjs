import { createServer } from 'node:http';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

// Operator-only fixture refresh. Point CAPTURE_PRODUCER_ROOT at a built public capture
// checkout. Normal tests use the committed, portable export and need no producer.
const root = process.env.CAPTURE_PRODUCER_ROOT;
if ( !root ) throw new Error( 'CAPTURE_PRODUCER_ROOT must name a built public capture checkout.' );
const { captureWebsite } = await import( pathToFileURL( resolve( root, 'dist/lib/capture.js' ) ) );
// The producer's licensed fixtures: OFL Roboto subsets (see FONT-LICENSE there), a
// generated one-page PDF, a ZIP archive and a tiny silent WebM.
const resources = resolve( root, 'test/fixtures/resource-assets' );
const served = new Map();
for ( const name of [ 'roboto-latin-400-normal.woff2', 'roboto-latin-400-normal.woff', 'roboto-greek-400-normal.woff2', 'roboto-greek-400-normal.woff', 'terms.pdf', 'download.zip', 'movie.webm' ] ) {
	served.set( '/' + name, readFileSync( resolve( resources, name ) ) );
}
served.set( '/poster.png', Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64' ) );
let css = '';
for ( const [ subset, range ] of [ [ 'latin', 'U+0000-00FF' ], [ 'greek', 'U+0370-03FF' ] ] ) {
	css += `@font-face{font-family:Garden Sans;font-weight:400;font-style:normal;unicode-range:${ range };src:url('/roboto-${ subset }-400-normal.woff2') format('woff2'),url('/roboto-${ subset }-400-normal.woff') format('woff');}`;
}
const server = createServer( ( request, response ) => {
	const path = new URL( request.url, 'http://example.org' ).pathname;
	// Lying response headers must not become verified content types.
	if ( served.has( path ) ) { response.writeHead( 200, { 'content-type': 'application/octet-stream' } ); response.end( served.get( path ) ); }
	else if ( path === '/fonts.css' ) { response.setHeader( 'content-type', 'text/css' ); response.end( css ); }
	else { response.setHeader( 'content-type', 'text/html' ); response.end( `<!doctype html><html><head><title>Resource record fixture</title><link rel="stylesheet" href="/fonts.css"></head><body><main><h1 style="font-family:'Garden Sans'">Garden terms and tours α</h1><nav><a href="/terms.pdf?download=1">Terms</a></nav><a href="/terms.pdf?download=2" download>Download the terms</a><a download href="/download.zip">Archive</a><video controls poster="/poster.png"><source src="/movie.webm" type="video/webm"></video></main></body></html>` ); }
} );
await new Promise( ( done ) => server.listen( 0, '127.0.0.1', done ) );
const url = `http://127.0.0.1:${ server.address().port }/`;
const outputDir = resolve( process.env.CAPTURE_OUTPUT || '/tmp/ssi-resource-record-capture' );
try {
	const result = await captureWebsite( { url, outputDir, trustedOrigins: [ url ], learnFluid: false, routeLimit: 1 }, { findAdapter: () => ( { id: 'fixture', detect: () => true, discover: async () => ( { urls: [] } ) } ) } );
	const receipt = JSON.parse( readFileSync( result.captureReceiptPath, 'utf8' ) );
	const directory = resolve( 'tests/fixtures/resource-asset-capture' );
	const normalize = ( text ) => text.replaceAll( url.slice( 0, -1 ), 'https://example.org' );
	mkdirSync( directory, { recursive: true } );
	// Keep the real portable HTML, source stylesheet and every verified original. Other
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
