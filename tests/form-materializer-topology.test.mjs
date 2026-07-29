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
const { getBlockType, parse, serialize, validateBlock } = require( '@wordpress/blocks' );

registerCoreBlocks();

test( 'nested shared-row topology materializes through the PHP provider adapter', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.match( output, /PASS form-materializer-smoke\.php \(\d+ assertions\)/ );
} );

test( 'core Group preserves every PHP-generated wrapper at all supported depths without validation warnings', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php', '--emit-topology-markup' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );
	const { markup, depth_markup: depthMarkup } = JSON.parse( output );
	const warnings = [];
	const originalWarn = console.warn;
	const originalError = console.error;
	console.warn = ( ...args ) => warnings.push( args.join( ' ' ) );
	console.error = ( ...args ) => warnings.push( args.join( ' ' ) );
	let blocks;
	let depthBlocks;
	try {
		blocks = parse( markup );
		depthBlocks = parse( depthMarkup );
	} finally {
		console.warn = originalWarn;
		console.error = originalError;
	}

	const group = blocks[ 0 ].innerBlocks[ 0 ];
	assert.equal( blocks.length, 1 );
	assert.equal( group.name, 'core/group' );
	assert.equal( group.attributes.tagName, 'section' );
	assert.equal( group.innerBlocks[ 0 ].attributes.className, 'field' );
	assert.ok( markup.includes( '<!-- wp:group {"className":"row-2","anchor":"contact-row","tagName":"section","layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap"}} -->\n<section id="contact-row" class="wp-block-group row-2 is-layout-flex">' ) );
	assert.ok( markup.includes( '<!-- /wp:group -->\n<!-- wp:group {"className":"field standalone"} -->' ) );
	assert.deepEqual( warnings, [], 'core block parsing emitted no warnings' );
	const validateGeneratedGroups = ( generatedBlocks ) => {
		for ( const block of generatedBlocks ) {
			if ( block.name === 'core/group' ) {
				assert.equal( validateBlock( block )[ 0 ], true, `core/group at generated depth is editor-valid` );
			}
			validateGeneratedGroups( block.innerBlocks );
		}
	};
	validateGeneratedGroups( blocks );
	validateGeneratedGroups( depthBlocks );
	let depth = 0;
	let nested = depthBlocks[ 0 ].innerBlocks[ 0 ];
	while ( nested?.name === 'core/group' ) {
		depth++;
		nested = nested.innerBlocks[ 0 ];
	}
	assert.equal( depth, 8, 'PHP materializes the maximum supported wrapper depth' );
	const roundTrip = parse( serialize( [ group ] ) );
	assert.equal( roundTrip[ 0 ].attributes.tagName, 'section' );
	assert.equal( roundTrip[ 0 ].innerBlocks[ 0 ].name, 'core/group' );
} );

test( 'Jetpack provider definitions are explicitly unavailable to this core-only validation', () => {
	assert.equal( getBlockType( 'jetpack/contact-form' ), undefined );
} );
