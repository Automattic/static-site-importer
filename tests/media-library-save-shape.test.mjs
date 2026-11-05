import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const require = createRequire( import.meta.url );
const { ensureRuntime, validateMarkup } = require( '../lib/gutenberg-block-validation.cjs' );
ensureRuntime();
const { createBlock, parse, serialize, validateBlock, registerBlockType } = require( '@wordpress/blocks' );
const { createElement } = require( '@wordpress/element' );
const vm = require( 'node:vm' );
const definition = JSON.parse( execFileSync( 'php', [ '-r', `
require 'vendor/autoload.php';
$generator = new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\CapturedChoiceGroupBlockGenerator();
echo json_encode($generator->definition('fixture/captured-choice-group'));
` ], { encoding: 'utf8' } ) );
// Execute the real generated save contract with an empty InnerBlocks fixture.
vm.runInNewContext( definition.assets[ 'index.js' ], { window: { wp: {
	blocks: { registerBlockType: ( name, settings ) => registerBlockType( name, { ...definition.block_json, ...settings } ) },
	blockEditor: { InnerBlocks: { Content: () => null } },
	element: { createElement },
} } } );

test( 'choice config image markup and saved data attribute bind identically and validate', () => {
	const config = JSON.stringify( { choices: [ { markup: `<img data-v="1" src="${ source }" alt="First">` }, { markup: '<img src="https://example.test/themes/source/child.png" alt="Second">' } ], label: 'Neutral & unchanged' } );
	const original = serialize( createBlock( 'fixture/captured-choice-group', { config } ) );
	assert.equal( validateMarkup( original ).ok, true );
	const bound = bind( original );
	const block = parse( bound )[ 0 ];
	assert.equal( validateBlock( block )[ 0 ], true );
	const choices = JSON.parse( block.attributes.config ).choices;
	assert.match( choices[ 0 ].markup, /https:\/\/example.test\/uploads\/7.png/ );
	assert.match( choices[ 1 ].markup, /https:\/\/example.test\/uploads\/8.png/ );
	assert.equal( validateMarkup( bound ).ok, true );
	assert.equal( bind( bound ), bound );
} );
test( 'capture record compiles to an editable attachment-bound image that validates in Gutenberg', () => {
	const dir = mkdtempSync( join( tmpdir(), 'ssi-record-validation-' ) );
	try {
		const path = join( dir, 'markup.html' );
		execFileSync( 'php', [ fileURLToPath( new URL( './smoke-image-asset-records.php', import.meta.url ) ) ], { env: { ...process.env, SSI_IMAGE_RECORD_MARKUP: path } } );
		const markup = readFileSync( path, 'utf8' );
		assert.match( markup, /"id":73/ );
		assert.equal( validateMarkup( markup ).ok, true );
	} finally {
		rmSync( dir, { recursive: true, force: true } );
	}
} );

const source = 'https://example.test/themes/source/photo.png';
const bind = ( markup ) => execFileSync( 'php', [ fileURLToPath( new URL( './fixtures/media-library-save-shape.php', import.meta.url ) ) ], { input: markup, encoding: 'utf8' } );
// The compiler retains the media URL in its attribute metadata before binding.
const compilerMarkup = ( block ) => serialize( block ).replaceAll( '<!-- wp:media-text {', `<!-- wp:media-text {"mediaUrl":"${ source }",` );

test( 'materialized media-text matches registered save with attachment URL and ID', () => {
	const original = compilerMarkup( createBlock( 'core/media-text', { mediaUrl: source, mediaType: 'image', mediaAlt: 'Example' }, [ createBlock( 'core/paragraph', { content: 'Editable copy' } ) ] ) );
	assert.equal( validateMarkup( original ).ok, true );
	const bound = bind( original );
	const block = parse( bound )[ 0 ];
	assert.equal( block.attributes.mediaId, 7 );
	assert.equal( block.attributes.mediaUrl, 'https://example.test/uploads/7.png' );
	assert.equal( validateMarkup( bound ).ok, true );
} );

test( 'a source-only media URL remains valid without inventing a mediaId image class', () => {
	const original = serialize( createBlock( 'core/media-text', { mediaUrl: source, mediaType: 'image', mediaAlt: 'Example' } ) );
	const bound = bind( original );
	assert.equal( parse( bound )[ 0 ].attributes.mediaUrl, 'https://example.test/uploads/7.png' );
	assert.equal( validateMarkup( bound ).ok, true );
} );

test( 'nested and following image owners retain their own saved image classes', () => {
	const image = () => createBlock( 'core/image', { url: 'https://example.test/themes/source/child.png', alt: 'Child' } );
	const media = createBlock( 'core/media-text', { mediaUrl: source, mediaType: 'image', mediaAlt: 'Example' }, [ image() ] );
	const original = compilerMarkup( createBlock( 'core/group', {}, [ media, image() ] ) );
	const bound = bind( original );
	const group = parse( bound )[ 0 ];
	assert.equal( group.innerBlocks[ 0 ].attributes.mediaId, 7 );
	assert.equal( group.innerBlocks[ 0 ].innerBlocks[ 0 ].attributes.id, 8 );
	assert.equal( group.innerBlocks[ 1 ].attributes.id, 8 );
	assert.equal( ( bound.match( /wp-image-8/g ) || [] ).length, 2 );
	assert.equal( validateMarkup( bound ).ok, true );
} );

test( 'foreign image identity rebinds coherently while registered figure and border presentation stay valid', () => {
	const original = serialize( createBlock( 'core/image', {
		id: 987654321, url: source, alt: 'Original image', caption: 'Original caption',
		className: 'source-presentation wp-image-987654321', sizeSlug: 'full',
		href: 'https://example.org/art', width: '120px', height: '80px',
		style: { border: { color: '#123456', width: '2px' } },
	} ) );
	assert.equal( validateMarkup( original ).ok, true );
	const bound = bind( original );
	const block = parse( bound )[ 0 ];
	assert.equal( block.attributes.id, 7 );
	assert.equal( block.attributes.url, 'https://example.test/uploads/7.png' );
	assert.equal( block.attributes.className, 'source-presentation wp-image-987654321' );
	assert.match( bound, /class="has-border-color wp-image-7"/ );
	assert.equal( validateMarkup( bound ).ok, true );
	assert.equal( bind( bound ), bound );
} );
