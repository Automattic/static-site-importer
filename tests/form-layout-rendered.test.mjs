import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace(/\/$/, '')}/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';
const skip = chromium && existsSync( chromium.executablePath() ) && existsSync( transformer )
	? false
	: 'playwright chromium and the blocks engine transformer are required';

test( 'span-6 fields share a row under a source column-flex rule and the span-3 submit stays within a quarter row', { skip }, async () => {
	const raw = execFileSync( 'php', [ 'tests/fixtures/form-layout-rendered-fixture.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
		env: process.env,
	} );
	const seeded = JSON.parse( raw );
	assert.equal( seeded.status, 'mapped', raw );
	assert.equal( seeded.fields.length, 3 );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		const fields = seeded.fields.map( ( field ) => `<div class="wp-block-jetpack-field-text grunion-field-text-wrap ${field.classes}" data-field="${field.label}"><label>${field.label}</label><input></div>` ).join( '' );
		await page.setContent( `<!doctype html><style>
			body{margin:0}
			.page{width:883px;margin:47px}
			.stack{display:flex;flex-direction:column;gap:24px;width:100%;background:#123456;padding:13px 17px}
			.wp-block-jetpack-contact-form{display:flex;flex-direction:row;flex-wrap:wrap;gap:1.5rem}
			:where(.has-no-jetpack-form-layout) .wp-block-jetpack-contact-form>:not(.wp-block-button){box-sizing:border-box;flex:0 0 100%}
			.wp-block-button{display:block;width:100%}
			${seeded.css}
		</style><div class="page"><form class="contact-form has-no-jetpack-form-layout"><div class="wp-block-jetpack-contact-form ${seeded.className}">${fields}<div class="wp-block-button ${seeded.submit.classes}" data-field="Send"><button type="submit">${seeded.submit.text}</button></div></div></form></div>` );
		const boxes = await page.locator( '[data-field]' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => {
			const rect = node.getBoundingClientRect();
			return { label: node.getAttribute( 'data-field' ), top: rect.top, left: rect.left, width: rect.width, parent: node.parentElement.getBoundingClientRect().width };
		} ) );
		const first = boxes.find( ( box ) => box.label === 'First name' );
		const last = boxes.find( ( box ) => box.label === 'Last name' );
		const send = boxes.find( ( box ) => box.label === 'Send' );
		assert.ok( first && last && send, JSON.stringify( boxes ) );
		assert.equal( first.top, last.top, JSON.stringify( boxes ) );
		assert.notEqual( first.left, last.left, JSON.stringify( boxes ) );
		assert.ok( send.width <= send.parent * 0.25 + 24, JSON.stringify( boxes ) );
		const container = await page.locator( '.wp-block-jetpack-contact-form' ).evaluate( ( node ) => {
			const style = getComputedStyle( node );
			return { background: style.backgroundColor, paddingTop: style.paddingTop, paddingRight: style.paddingRight, paddingBottom: style.paddingBottom, paddingLeft: style.paddingLeft };
		} );
		assert.equal( container.background, 'rgb(18, 52, 86)', JSON.stringify( container ) );
		assert.equal( container.paddingTop, '13px', JSON.stringify( container ) );
		assert.equal( container.paddingRight, '17px', JSON.stringify( container ) );
		assert.equal( container.paddingBottom, '13px', JSON.stringify( container ) );
		assert.equal( container.paddingLeft, '17px', JSON.stringify( container ) );
	} finally {
		await browser.close();
	}
} );
