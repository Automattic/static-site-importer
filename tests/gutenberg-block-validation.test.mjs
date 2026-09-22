import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	VALIDATION_METHOD,
	ensureRuntime,
	registerValidationBlockType,
	validateMarkup,
} = require( '../lib/gutenberg-block-validation.cjs' );

ensureRuntime();
const { createElement } = require( '@wordpress/element' );

const SAVE_MISMATCH_NAME = 'test/save-mismatch';
const HEALTHY_MARKUP = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
const SAVE_MISMATCH_MARKUP = '<!-- wp:test/save-mismatch {"name":"country","required":true,"disabled":true} --><select name="country" required disabled></select><!-- /wp:test/save-mismatch -->';
const HEALTHY_CUSTOM_MARKUP = '<!-- wp:test/save-mismatch {"name":"country"} --><select name="country"></select><!-- /wp:test/save-mismatch -->';

registerValidationBlockType( SAVE_MISMATCH_NAME, {
	apiVersion: 3,
	title: 'Save mismatch',
	category: 'widgets',
	attributes: {
		name: { type: 'string', default: '' },
		required: { type: 'boolean', default: false },
		disabled: { type: 'boolean', default: false },
	},
	save( { attributes } ) {
		return createElement( 'select', { name: attributes.name } );
	},
} );

test( 'validateBlock flags stored markup that registered save() would not reproduce', () => {
	const result = validateMarkup( SAVE_MISMATCH_MARKUP, { file: 'posts/page-welfare.post_content' } );

	assert.equal( result.validation_method, VALIDATION_METHOD );
	assert.equal( result.validation_method, 'wp.blocks.validateBlock' );
	assert.equal( result.ok, false );
	assert.equal( result.invalidBlocks, 1 );
	assert.equal( result.blocksChecked, 1 );
	assert.equal( result.failures[ 0 ].blockName, SAVE_MISMATCH_NAME );
	assert.equal( result.results[ 0 ].isValid, false );
	assert.match(
		result.failures[ 0 ].reasons.join( ' ' ),
		/required|disabled|save output does not match|Expected attributes|Expected token/i
	);
} );

test( 'validateBlock does not flag a healthy document', () => {
	const result = validateMarkup( HEALTHY_MARKUP, { file: 'posts/page-home.post_content' } );

	assert.equal( result.validation_method, 'wp.blocks.validateBlock' );
	assert.equal( result.ok, true );
	assert.equal( result.invalidBlocks, 0 );
	assert.equal( result.blocksChecked, 1 );
	assert.equal( result.results[ 0 ].name, 'core/paragraph' );
	assert.equal( result.results[ 0 ].isValid, true );
	assert.deepEqual( result.failures, [] );
} );

test( 'validateBlock does not flag a custom block whose save() matches stored markup', () => {
	const result = validateMarkup( HEALTHY_CUSTOM_MARKUP, { file: 'posts/page-contact.post_content' } );

	assert.equal( result.ok, true );
	assert.equal( result.invalidBlocks, 0 );
	assert.equal( result.results[ 0 ].name, SAVE_MISMATCH_NAME );
	assert.equal( result.results[ 0 ].isValid, true );
} );
