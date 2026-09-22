import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import test from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
let chromium;
try {
	({ chromium } = require( 'playwright' ));
} catch ( error ) {
	const fallback = process.env.SSI_PLAYWRIGHT_PACKAGE || '/Users/chubes/Developer/data-liberation-agent@fix-nexus-331-rating/node_modules/playwright';
	try {
		({ chromium } = require( fallback ));
	} catch {
		throw error;
	}
}

const generatedMarkup = execFileSync(
	'php',
	[
		'-r',
		`define( 'ABSPATH', getcwd() . '/' ); require 'includes/class-static-site-importer-provider-form-runtime.php'; echo Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_bridge( '<form id="choice-form"><div data-blocks-engine-choice-group="true"><button type="button">One</button><button type="button">Two</button><button type="button">Three</button></div><div class="ssi-choice-provider-bridge"><input type="radio" name="rating" value="choice-0"><input type="radio" name="rating" value="choice-1"><input type="radio" name="rating" value="choice-2"></div></form>' );`,
	],
	{ cwd: process.cwd(), encoding: 'utf8' }
);

async function waitFor( page, predicate ) {
	await page.waitForFunction( predicate, null, { timeout: 3000 } );
}

test( 'generated choice bridge follows clicks, keyboard changes, reset, and reinitialization', async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.setContent( generatedMarkup );

		const initial = await page.locator( '#choice-form input[type="radio"]:checked' ).count();
		assert.equal( initial, 0, 'unknown initial choice remains unselected' );

		await page.evaluate( () => {
			const group = document.querySelector( '[data-blocks-engine-choice-group="true"]' );
			const choices = [ ...group.querySelectorAll( 'button' ) ];
			choices.forEach( ( choice, index ) => choice.dataset.blocksEngineChoiceIndex = String( index ) );
			const select = ( index ) => {
				group.setAttribute( 'data-blocks-engine-choice-selection', JSON.stringify( { observed_choice_key: `choice-${ index }`, source_value: null, selected_index: index } ) );
			};
			group.addEventListener( 'click', ( event ) => {
				const choice = event.target.closest( 'button' );
				if ( choice ) select( Number( choice.dataset.blocksEngineChoiceIndex ) );
			} );
			group.addEventListener( 'keydown', ( event ) => {
				if ( event.key !== 'ArrowRight' ) return;
				event.preventDefault();
				select( ( Number( event.target.dataset.blocksEngineChoiceIndex ) + 1 ) % choices.length );
			} );
		} );

		await page.locator( '#choice-form button' ).nth( 1 ).click();
		await waitFor( page, () => document.querySelector( '#choice-form input[value="choice-1"]' )?.checked === true );
		assert.deepEqual( await page.locator( '#choice-form input:checked' ).evaluateAll( ( inputs ) => inputs.map( ( input ) => input.value ) ), [ 'choice-1' ] );
		assert.deepEqual( await page.locator( '#choice-form' ).evaluate( ( form ) => [ ...new FormData( form ).entries() ] ), [ [ 'rating', 'choice-1' ] ] );

		await page.locator( '#choice-form button' ).nth( 1 ).focus();
		await page.keyboard.press( 'ArrowRight' );
		await waitFor( page, () => document.querySelector( '#choice-form input[value="choice-2"]' )?.checked === true );
		assert.equal( await page.locator( '#choice-form input:checked' ).inputValue(), 'choice-2' );
		assert.equal( JSON.parse( await page.locator( '[data-blocks-engine-choice-group="true"]' ).getAttribute( 'data-blocks-engine-choice-selection' ) ).source_value, null );

		await page.locator( '#choice-form' ).evaluate( ( form ) => form.reset() );
		await waitFor( page, () => document.querySelector( '#choice-form input:checked' ) === null );

		await page.locator( '#choice-form' ).evaluate( ( form ) => {
			const replacement = form.cloneNode( true );
			form.replaceWith( replacement );
		} );
		await waitFor( page, () => window.__ssi_choice_provider_bridge_v1__?.entries?.length === 1 );
		await page.locator( '#choice-form [data-blocks-engine-choice-group="true"]' ).evaluate( ( group ) => group.setAttribute( 'data-blocks-engine-choice-selection', JSON.stringify( { observed_choice_key: 'choice-0', source_value: null, selected_index: 0 } ) ) );
		await waitFor( page, () => document.querySelector( '#choice-form input[value="choice-0"]' )?.checked === true );
		assert.equal( await page.locator( '#choice-form input:checked' ).inputValue(), 'choice-0' );
	} finally {
		await browser.close();
	}
} );
