#!/usr/bin/env node
/**
 * Browser acceptance for a companion block in a materialized SSI site.
 *
 * The disposable runtime is created by tools/run-editor-companion-acceptance.sh.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile, writeFile } from 'node:fs/promises';

const required = [ 'SSI_EDITOR_WP_URL', 'SSI_EDITOR_POST_ID', 'SSI_EDITOR_NEGATIVE_POST_ID', 'SSI_EDITOR_USER', 'SSI_EDITOR_PASSWORD', 'SSI_EDITOR_INVENTORY', 'SSI_EDITOR_EVIDENCE_DIR' ];
const missing = required.filter( ( name ) => ! process.env[ name ] );
if ( missing.length ) {
	throw new Error( `Missing required environment: ${ missing.join( ', ' ) }` );
}

const baseUrl = process.env.SSI_EDITOR_WP_URL.replace( /\/$/, '' );
const postId = process.env.SSI_EDITOR_POST_ID;
const negativePostId = process.env.SSI_EDITOR_NEGATIVE_POST_ID;
const inventory = JSON.parse( await readFile( process.env.SSI_EDITOR_INVENTORY, 'utf8' ) );
const receipt = ( await readFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/import-result.jsonl`, 'utf8' ) ).trim().split( '\n' ).map( ( line ) => JSON.parse( line ) ).at( -1 );
const validation = JSON.parse( await readFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/import-validation-result.json`, 'utf8' ) );
assert.equal( receipt.schema, 'static-site-importer/import-cli-receipt/v1', 'public CLI receipt is present' );
assert.equal( receipt.status, 'completed', 'import completed through the public CLI' );
assert.equal( receipt.response.fixture_diagnostics.quality_counts.consistent, true, 'public quality counts agree within their owning phases' );
assert.equal( validation.quality_pass, true, 'persisted validation passed' );
assert.equal( validation.fail_import, false, 'persisted validation retains no import failure' );
const expectedBlockNames = [ ...new Set( inventory.documents.flatMap( ( document ) => document.blocks ) ) ];
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
const browserErrors = [];
const saveResponses = [];
const isPageSaveRequest = ( request ) => {
	const url = new URL( request.url() );
	const route = url.searchParams.get( 'rest_route' ) || url.pathname.replace( /^.*\/wp-json/, '' );
	return route.replace( /\/$/, '' ) === `/wp/v2/pages/${ postId }` && [ 'POST', 'PUT', 'PATCH' ].includes( request.method() );
};
page.on( 'pageerror', ( error ) => browserErrors.push( error.stack || error.message ) );
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		browserErrors.push( message.text() );
	}
} );
page.on( 'response', async ( response ) => {
	const request = response.request();
	if ( ! isPageSaveRequest( request ) ) {
		return;
	}
	saveResponses.push( { method: request.method(), status: response.status(), url: response.url() } );
} );
const selectedBlock = () => page.evaluate( () => {
	const clientId = window.wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
	const block = clientId ? window.wp.data.select( 'core/block-editor' ).getBlock( clientId ) : null;
	return { clientId, name: block?.name || null };
} );
const editorSaveState = () => page.evaluate( () => {
	const editor = window.wp.data.select( 'core/editor' );
	return {
		isSavingPost: editor.isSavingPost(),
		isEditedPostDirty: editor.isEditedPostDirty(),
		didPostSaveRequestSucceed: editor.didPostSaveRequestSucceed(),
	};
} );
const saveButtonState = () => page.getByRole( 'button', { name: /^Save$/ } ).evaluateAll( ( buttons ) => buttons.map( ( button ) => ( {
	outerHTML: button.outerHTML,
	disabled: button.disabled,
	ariaDisabled: button.getAttribute( 'aria-disabled' ),
	ariaBusy: button.getAttribute( 'aria-busy' ),
	} ) ) );

try {
	await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'networkidle' } );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.SSI_EDITOR_USER );
	await page.getByRole( 'textbox', { name: 'Password' } ).fill( process.env.SSI_EDITOR_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' } );
	await page.getByRole( 'button', { name: /Edit|Edit with Gutenberg/ } ).click().catch( () => {} );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const missingEditorBlocks = await page.evaluate( ( names ) => names.filter( ( name ) => ! window.wp.blocks.getBlockType( name ) ), expectedBlockNames );
	assert.deepEqual( missingEditorBlocks, [], 'every persisted page and generated theme block is registered in Gutenberg' );
	const documentValidation = await page.evaluate( ( documents ) => documents.map( ( document ) => {
		const failures = [];
		let blocksChecked = 0;
		const visit = ( blocks ) => {
			for ( const block of blocks ) {
				blocksChecked++;
				const [ valid ] = window.wp.blocks.validateBlock( block );
				if ( ! valid || [ 'core/html', 'core/freeform' ].includes( block.name ) ) {
					failures.push( block.name );
				}
				visit( block.innerBlocks || [] );
			}
		};
		visit( window.wp.blocks.parse( document.markup ) );
		return { path: document.path, blocksChecked, failures };
	} ), inventory.documents );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/document-validation.json`, JSON.stringify( documentValidation, null, 2 ) + '\n' );
	assert.deepEqual( documentValidation.filter( ( document ) => document.failures.length ), [], 'all persisted page and theme documents pass Gutenberg save-contract validation' );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '.editor-styles-wrapper' ).waitFor();
	const welcome = page.locator( '.components-modal__screen-overlay' );
	if ( await welcome.isVisible() ) {
		await welcome.getByRole( 'button', { name: /Close|Get started/ } ).first().click();
	}
	const preview = canvas.locator( 'iframe[title="Map"]' );
	const block = canvas.locator( '[data-type$="/visual-iframe"]' );
	await page.screenshot( { path: `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/editor-baseline.png`, fullPage: true } );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/selection-before-click.json`, JSON.stringify( await selectedBlock(), null, 2 ) + '\n' );
	await block.hover();
	await page.mouse.down();
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/selection-after-mousedown.json`, JSON.stringify( await selectedBlock(), null, 2 ) + '\n' );
	await page.mouse.up();
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/selection-after-mouseup.json`, JSON.stringify( await selectedBlock(), null, 2 ) + '\n' );
	const inspector = page.getByText( 'Embedded content', { exact: true } );
	if ( ! await inspector.isVisible() ) {
		await page.getByRole( 'button', { name: 'Settings', exact: true } ).click();
	}
	await page.getByRole( 'tab', { name: 'Block', exact: true } ).click( { force: true } );
	await inspector.waitFor();
	await page.getByRole( 'textbox', { name: 'URL', exact: true } ).fill( 'https://example.test/updated-map' );
	await page.getByRole( 'textbox', { name: 'Title', exact: true } ).fill( 'Updated map' );
	await page.getByRole( 'textbox', { name: 'Width', exact: true } ).fill( '720' );
	await page.getByRole( 'combobox', { name: 'Loading', exact: true } ).selectOption( 'eager' );
	await page.screenshot( { path: `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/editor-desktop.png`, fullPage: true } );
	const saveButton = page.getByRole( 'button', { name: /^Save$/ } );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/save-before-click.json`, JSON.stringify( { buttons: await saveButtonState(), editor: await editorSaveState() }, null, 2 ) + '\n' );
	assert.equal( await saveButton.isEnabled(), true, 'editor exposes an enabled Save action after changing iframe attributes' );
	const successfulSave = page.waitForResponse( ( response ) => isPageSaveRequest( response.request() ) && response.ok(), { timeout: 15000 } );
	await saveButton.click();
	const visibleSaveButtons = page.getByRole( 'button', { name: /^Save$/ } );
	if ( await visibleSaveButtons.count() > 1 ) {
		await visibleSaveButtons.nth( 1 ).click();
	}
	await successfulSave;
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/editor' );
		return ! editor.isSavingPost() && ! editor.isEditedPostDirty() && editor.didPostSaveRequestSucceed();
	}, null, { timeout: 15000 } );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/save-after-success.json`, JSON.stringify( { buttons: await saveButtonState(), editor: await editorSaveState(), responses: saveResponses }, null, 2 ) + '\n' );

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const reloadedCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const reloadedPreview = reloadedCanvas.locator( 'iframe[title="Updated map"]' );
	await reloadedPreview.waitFor();
	assert.equal( await reloadedCanvas.locator( '.block-editor-warning' ).count(), 0, 'saved companion block is editor-valid after reload' );
	assert.equal( await reloadedPreview.getAttribute( 'src' ), 'https://example.test/updated-map', 'editor reload preserves structured URL' );
	assert.equal( await reloadedPreview.getAttribute( 'width' ), '720', 'editor reload preserves structured width' );
	assert.equal( await reloadedPreview.getAttribute( 'loading' ), 'eager', 'editor reload preserves structured loading mode' );

	await page.goto( `${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'domcontentloaded' } );
	const frontend = page.locator( 'iframe[title="Updated map"]' );
	await frontend.waitFor();
	assert.equal( await frontend.getAttribute( 'src' ), 'https://example.test/updated-map', 'frontend renders saved URL' );
	assert.equal( await frontend.getAttribute( 'width' ), '720', 'frontend renders saved width' );
	assert.equal( await frontend.getAttribute( 'loading' ), 'eager', 'frontend renders saved loading mode' );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	const mobileBox = await page.locator( 'iframe[title="Updated map"]' ).boundingBox();
	assert.ok( mobileBox && mobileBox.width <= 390, 'frontend iframe fits the mobile viewport' );
	await page.screenshot( { path: `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/frontend-mobile.png`, fullPage: true } );

	await page.setViewportSize( { width: 1440, height: 1000 } );
	await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ negativePostId }&action=edit`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const invalidCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const invalidWarning = invalidCanvas.getByText( 'Block contains unexpected or invalid content.' );
	const invalidBlock = invalidCanvas.locator( '[data-type$="/visual-iframe"]' );
	const invalidValidationState = await page.evaluate( () => {
		const store = window.wp.data.select( 'core/block-editor' );
		const flattenBlocks = ( blocks ) => blocks.flatMap( ( block ) => [ block, ...flattenBlocks( block.innerBlocks || [] ) ] );
		const block = flattenBlocks( store.getBlocks() ).find( ( candidate ) => candidate.name.endsWith( '/visual-iframe' ) );
		return {
			clientId: block?.clientId || null,
			validity: block && typeof store.getBlockValidity === 'function' ? store.getBlockValidity( block.clientId ) : null,
			validationError: block && typeof store.getBlockValidationError === 'function' ? store.getBlockValidationError( block.clientId ) : null,
		};
	} );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/invalid-validation-state.json`, JSON.stringify( {
		...invalidValidationState,
		warningCount: await invalidWarning.count(),
		blockHtml: await invalidBlock.evaluate( ( node ) => node.outerHTML ),
	}, null, 2 ) + '\n' );
	assert.ok( await invalidWarning.count(), 'Gutenberg displays the invalid companion warning in the editor canvas' );
	await page.screenshot( { path: `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/invalid-companion.png`, fullPage: true } );
	console.log( JSON.stringify( { ok: true, postId, documents: inventory.documents.length, editorBlocks: expectedBlockNames, persistedAttributes: [ 'src', 'title', 'width', 'loading' ] } ) );
} finally {
	await page.screenshot( { path: `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/editor-final-state.png`, fullPage: true } ).catch( () => {} );
	await page.evaluate( () => Array.from( document.querySelectorAll( 'button' ) ).map( ( button ) => ( {
		text: button.textContent.trim(),
		ariaLabel: button.getAttribute( 'aria-label' ),
		disabled: button.disabled,
		ariaDisabled: button.getAttribute( 'aria-disabled' ),
		ariaBusy: button.getAttribute( 'aria-busy' ),
		outerHTML: button.outerHTML,
	} ) ) ).then( ( buttons ) => writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/editor-ui-state.json`, JSON.stringify( { buttons }, null, 2 ) + '\n' ) ).catch( () => {} );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/save-responses.json`, JSON.stringify( saveResponses, null, 2 ) + '\n' ).catch( () => {} );
	await writeFile( `${ process.env.SSI_EDITOR_EVIDENCE_DIR }/browser-errors.json`, JSON.stringify( browserErrors, null, 2 ) + '\n' ).catch( () => {} );
	await browser.close();
}
