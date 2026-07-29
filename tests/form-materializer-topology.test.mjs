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
const { parse, serialize, validateBlock, registerBlockType } = require( '@wordpress/blocks' );

registerCoreBlocks();
for ( const name of [ 'jetpack/contact-form', 'jetpack/field-text', 'jetpack/field-email', 'jetpack/field-textarea', 'jetpack/label', 'jetpack/input', 'jetpack/button' ] ) {
	registerBlockType( name, { title: name, category: 'widgets', attributes: {}, save: () => null } );
}

test( 'nested shared-row topology materializes through the PHP provider adapter', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.match( output, /PASS form-materializer-smoke\.php \(\d+ assertions\)/ );
} );

test( 'core Group preserves supported wrapper tags in parsed editor-valid topology', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php', '--emit-topology-markup' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );
	const { markup } = JSON.parse( output );
	const blocks = parse( markup );

	const group = blocks[ 0 ].innerBlocks[ 0 ];
	assert.equal( blocks.length, 1 );
	assert.equal( group.name, 'core/group' );
	assert.equal( group.attributes.tagName, 'section' );
	assert.equal( group.innerBlocks[ 0 ].attributes.className, 'field' );
	for ( const block of [ group, ...group.innerBlocks ] ) {
		assert.equal( validateBlock( block )[ 0 ], true, `${ block.name } is editor-valid` );
	}
	const roundTrip = parse( serialize( [ group ] ) );
	assert.equal( roundTrip[ 0 ].attributes.tagName, 'section' );
	assert.equal( roundTrip[ 0 ].innerBlocks[ 0 ].name, 'core/group' );
} );
