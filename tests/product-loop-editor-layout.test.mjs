import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

test( 'an imported product loop keeps its cards in the source grid inside the editor canvas', async () => {
	const css = execFileSync( 'php', [ '-r', 'define( "ABSPATH", __DIR__ . "/" ); require "includes/class-static-site-importer-product-grid-runtime.php"; echo Static_Site_Importer_Product_Grid_Runtime::editor_layout_css();' ], { encoding: 'utf8' } );
	// The editor's product-collection markup: collection, inner block list,
	// WooCommerce's flex product list and one item per product.
	const cards = Array.from( { length: 6 }, () => '<li class="wc-block-product"><article class="card">Card</article></li>' ).join( '' );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage( { viewport: { width: 1000, height: 600 } } );
		await page.setContent( `<style>body{margin:0}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;width:860px}.wc-block-product-template.is-flex-container{display:flex;flex-wrap:wrap;gap:16px;list-style:none;margin:0;padding:0}.wc-block-product-template.columns-6>li{width:calc(16.6667% - 14px)}${ css }</style>`
			+ `<div class="grid"><div class="wp-block-woocommerce-product-collection ssi-item-template-loop"><div class="block-editor-block-list__layout"><ul class="wc-block-product-template is-flex-container columns-6">${ cards }</ul></div></div></div>` );
		const boxes = await page.locator( 'article.card' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.getBoundingClientRect().toJSON() ) );
		assert.deepEqual( boxes.map( ( box ) => Math.round( box.width ) ), [ 200, 200, 200, 200, 200, 200 ] );
		assert.deepEqual( boxes.map( ( box ) => Math.round( box.x ) ), [ 0, 220, 440, 660, 0, 220 ] );
	} finally {
		await browser.close();
	}
} );
