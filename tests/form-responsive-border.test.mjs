import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { IMPORTED_THEME_LAYOUT_CSS, editorDocument, frontDocument } from './fixtures/form-role-box-parity-jetpack-models.mjs';

// A source form whose fields scope their border to a 1280px media query keeps
// that scope after the import (#2620). The seeded field carries no inline
// border style — an inline declaration would beat the media-scoped rule at
// every width — so the scoped overlay owns both widths: the source's
// unconditional `none` below the breakpoint and the styled border inside it.
// A field whose border is not responsive keeps its inline style, and a field
// with no unconditional border of its own keeps the browser default the
// source showed. Every surface renders the same computed border as the source
// page at desktop and phone widths.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const VIEWPORTS = [ 1440, 390 ];

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${ process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace( /\/$/, '' ) }/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

/** In-page: the computed top border and the inline style of each seeded field. */
function borders() {
	const box = ( node ) => {
		const style = getComputedStyle( node );
		return { style: style.borderTopStyle, width: style.borderTopWidth, color: style.borderTopColor, inline: node.getAttribute( 'style' ) ?? '', field: node.classList.contains( 'field' ) };
	};
	return {
		boxed: [ ...document.querySelectorAll( 'input.boxed' ) ].map( box ),
		plain: [ ...document.querySelectorAll( 'input.plain' ) ].map( box ),
	};
}

/** The border facts, without the DOM-only inline attribute. */
const paint = ( border ) => `${ border.style } ${ border.width } ${ border.color }`;

test( 'imported fields keep the source border scope on the front end and in the editor', async ( t ) => {
	assert.ok( chromium, 'Playwright is required; run `npm install`' );
	assert.ok( existsSync( chromium.executablePath() ), `Playwright Chromium is missing at ${ chromium.executablePath() }; run \`npx playwright install chromium\`` );
	assert.ok( existsSync( transformer ), `Blocks Engine transformer is missing at ${ transformer }` );
	const { forms } = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-responsive-border-fixture.php' ], { encoding: 'utf8', env: process.env, maxBuffer: 64 * 1024 * 1024 } ) );
	const browser = await chromium.launch();
	const results = [];
	try {
		const page = await browser.newPage();
		for ( const form of forms ) {
			const documents = {
				source: form.sourceHtml,
				front: frontDocument( form, { layoutCss: IMPORTED_THEME_LAYOUT_CSS } ),
				editor: editorDocument( form, { layoutCss: IMPORTED_THEME_LAYOUT_CSS } ),
			};
			for ( const width of VIEWPORTS ) {
				await page.setViewportSize( { width, height: 1200 } );
				for ( const [ surface, html ] of Object.entries( documents ) ) {
					await page.setContent( html );
					results.push( { form: form.name, surface, width, borders: await page.evaluate( borders ) } );
				}
			}
		}
	} finally {
		await browser.close();
	}
	const bySurface = ( width ) => Object.fromEntries( results.filter( ( row ) => row.width === width ).map( ( row ) => [ row.surface, row.borders ] ) );
	for ( const width of VIEWPORTS ) {
		const { source, front, editor } = bySurface( width );
		await t.test( `the imported form keeps the source borders at ${ width }px`, () => {
			// The two reduced fields of #2620: the media-scoped border at the
			// desktop width, the source's unconditional `none` below it.
			assert.equal( source.boxed.length, 3, 'the three media-scoped fields render' );
			if ( 1440 === width ) {
				for ( const [ index, border ] of source.boxed.entries() ) {
					assert.deepEqual(
						paint( border ),
						'solid 1px rgba(255, 255, 255, 0.7)',
						`source boxed field ${ index } at the desktop width`
					);
				}
			} else {
				for ( const [ index, border ] of source.boxed.entries() ) {
					assert.equal( border.style, border.field ? 'none' : 'inset', `source boxed field ${ index } below the breakpoint` );
				}
			}
			// Every field keeps the source paint on the front end and in the editor.
			assert.equal( front.boxed.length, source.boxed.length, 'the front end renders every media-scoped field' );
			assert.equal( editor.boxed.length, source.boxed.length, 'the editor renders every media-scoped field' );
			source.boxed.forEach( ( border, index ) => {
				assert.equal( paint( front.boxed[ index ] ), paint( border ), `front boxed field ${ index }` );
				assert.equal( paint( editor.boxed[ index ] ), paint( border ), `editor boxed field ${ index }` );
			} );
			// No media-scoped field relies on the dropped inline attribute.
			for ( const surfaceBorders of [ front.boxed, editor.boxed ] ) {
				for ( const [ index, border ] of surfaceBorders.entries() ) {
					assert.ok( ! /border-style/.test( border.inline ), `front/editor boxed field ${ index } carries no inline border style` );
				}
			}
			// The field whose border is not responsive keeps it at every width.
			assert.equal( source.plain.length, 1, 'the non-responsive field renders' );
			assert.equal( paint( source.plain[ 0 ] ), 'dashed 1px rgb(153, 153, 153)', 'source non-responsive field' );
			assert.equal( paint( front.plain[ 0 ] ), paint( source.plain[ 0 ] ), 'front non-responsive field' );
			assert.equal( paint( editor.plain[ 0 ] ), paint( source.plain[ 0 ] ), 'editor non-responsive field' );
			assert.ok( /border-style/.test( front.plain[ 0 ].inline ), 'front non-responsive field keeps its inline border style' );
			assert.ok( /border-style/.test( editor.plain[ 0 ].inline ), 'editor non-responsive field keeps its inline border style' );
		} );
	}
} );
