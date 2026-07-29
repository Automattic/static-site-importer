import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { JSDOM, VirtualConsole } from 'jsdom';

const require = createRequire( import.meta.url );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
	virtualConsole: new VirtualConsole(),
} );
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty( globalThis, 'navigator', { value: dom.window.navigator, configurable: true } );
globalThis.HTMLElement = dom.window.HTMLElement;
globalThis.Node = dom.window.Node;
globalThis.DOMParser = dom.window.DOMParser;
globalThis.MutationObserver = dom.window.MutationObserver;
globalThis.getComputedStyle = dom.window.getComputedStyle;
globalThis.requestAnimationFrame = ( callback ) => setTimeout( callback, 0 );
globalThis.cancelAnimationFrame = ( id ) => clearTimeout( id );

const { registerCoreBlocks } = require( '@wordpress/block-library' );
const { parse, serialize, validateBlock } = require( '@wordpress/blocks' );

registerCoreBlocks();

test( 'nested shared-row topology materializes through the PHP provider adapter', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.match( output, /PASS form-materializer-smoke\.php \(\d+ assertions\)/ );
} );

test( 'core Group preserves supported wrapper tags in parsed editor-valid topology', () => {
	const markup = '<!-- wp:group {"className":"row-2","anchor":"contact-row","tagName":"section"} -->\n<section id="contact-row" class="wp-block-group row-2">\n<!-- wp:group {"className":"field"} -->\n<div class="wp-block-group field">\n<!-- wp:paragraph --><p>First name</p><!-- /wp:paragraph -->\n</div>\n<!-- /wp:group -->\n</section>\n<!-- /wp:group -->';
	const blocks = parse( markup );

	assert.equal( blocks.length, 1 );
	assert.equal( blocks[ 0 ].name, 'core/group' );
	assert.equal( blocks[ 0 ].attributes.tagName, 'section' );
	assert.equal( blocks[ 0 ].innerBlocks[ 0 ].attributes.className, 'field' );
	for ( const block of [ blocks[ 0 ], ...blocks[ 0 ].innerBlocks, ...blocks[ 0 ].innerBlocks[ 0 ].innerBlocks ] ) {
		assert.equal( validateBlock( block )[ 0 ], true, `${ block.name } is editor-valid` );
	}
	const roundTrip = parse( serialize( blocks ) );
	assert.equal( roundTrip[ 0 ].attributes.tagName, 'section' );
	assert.equal( roundTrip[ 0 ].innerBlocks[ 0 ].innerBlocks[ 0 ].name, 'core/paragraph' );
} );
