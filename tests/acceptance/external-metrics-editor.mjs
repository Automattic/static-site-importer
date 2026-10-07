#!/usr/bin/env node
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const required = [ 'SSI_EXTERNAL_METRICS_WP_URL', 'SSI_EXTERNAL_METRICS_POST_ID', 'SSI_EXTERNAL_METRICS_USER', 'SSI_EXTERNAL_METRICS_PASSWORD', 'SSI_EXTERNAL_METRICS_EVIDENCE' ];
for ( const key of required ) assert.ok( process.env[ key ], `Missing ${ key }` );
const evidenceDir = process.env.SSI_EXTERNAL_METRICS_EVIDENCE;
await mkdir( evidenceDir, { recursive: true } );
const runtimeEvidence = JSON.parse( await readFile( `${ evidenceDir }/runtime.jsonl`, 'utf8' ) );
const expectedValue = runtimeEvidence.alias_cache?.github_value;
const expectedForks = runtimeEvidence.alias_cache?.github_forks_value;
assert.equal( typeof expectedValue, 'string', 'Independent GitHub runtime proof supplies the exact current editor value.' );
assert.equal( typeof expectedForks, 'string', 'Independent GitHub runtime proof supplies the exact current sibling field.' );
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
const errors = [];
const restSaves = [];
const refreshRequests = [];
const savedPayloads = [];
const isPageSave = ( request ) => {
	const url = new URL( request.url() );
	const route = url.searchParams.get( 'rest_route' ) || url.pathname.replace( /^.*\/wp-json/, '' );
	return route.replace( /\/$/, '' ) === `/wp/v2/pages/${ process.env.SSI_EXTERNAL_METRICS_POST_ID }` && [ 'POST', 'PUT', 'PATCH' ].includes( request.method() );
};
page.on( 'pageerror', ( error ) => errors.push( error.stack || error.message ) );
page.on( 'request', ( request ) => { if ( request.url().includes( 'external-metrics' ) ) refreshRequests.push( { method: request.method(), url: request.url() } ); } );
page.on( 'console', ( message ) => { if ( message.type() === 'error' ) errors.push( message.text() ); } );
page.on( 'response', async ( response ) => {
	const request = response.request();
	if ( isPageSave( request ) ) {
		restSaves.push( response.status() );
		try { const body = await response.json(); savedPayloads.push( { status: response.status(), content: body.content?.raw || null } ); } catch {}
	}
} );
const base = process.env.SSI_EXTERNAL_METRICS_WP_URL.replace( /\/$/, '' );
const postId = process.env.SSI_EXTERNAL_METRICS_POST_ID;
try {
	await page.goto( `${ base }/wp-login.php`, { waitUntil: 'networkidle' } );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.SSI_EXTERNAL_METRICS_USER );
	await page.getByRole( 'textbox', { name: 'Password' } ).fill( process.env.SSI_EXTERNAL_METRICS_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( `${ base }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	await page.waitForTimeout( 1000 );
	await page.keyboard.press( 'Escape' ).catch( () => {} );
	const welcomeClose = page.getByRole( 'button', { name: /close|dismiss/i } );
	if ( await welcomeClose.count() ) { await welcomeClose.last().click().catch( () => {} ); }
	const welcomeOverlay = page.locator( '.components-modal__screen-overlay' );
	if ( await welcomeOverlay.isVisible().catch( () => false ) ) {
		const welcomeAction = welcomeOverlay.getByRole( 'button', { name: /close|get started/i } ).first();
		if ( await welcomeAction.count() ) {
			await welcomeAction.click();
			await welcomeOverlay.waitFor( { state: 'hidden', timeout: 5000 } );
		} else {
			await page.screenshot( { path: `${ evidenceDir }/editor-welcome-debug.png`, fullPage: true } );
			await writeFile( `${ evidenceDir }/editor-welcome-debug.json`, JSON.stringify( { body: ( await page.locator( 'body' ).innerText() ).slice( 0, 2000 ), buttons: await welcomeOverlay.getByRole( 'button' ).allTextContents() }, null, 2 ) );
			throw new Error( 'WordPress editor welcome guide is visible without its expected Close or Get started action.' );
		}
	}
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const metricBlock = canvas.locator( '[data-type="core/paragraph"]' ).first();
	await metricBlock.click();
	const settingsButton = page.getByRole( 'button', { name: 'Settings' } );
	if ( await settingsButton.count() && 'true' !== await settingsButton.getAttribute( 'aria-pressed' ) ) { await settingsButton.click(); }
	const targetClientId = await page.evaluate( () => {
		const visit = ( blocks ) => {
			for ( const block of blocks || [] ) {
				if ( block.attributes?.metadata?.bindings?.content?.args?.metric_id === 'editor-detach-metric' ) { return block.clientId; }
				const nested = visit( block.innerBlocks );
				if ( nested ) { return nested; }
			}
			return null;
		};
		return visit( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	} );
	assert.ok( targetClientId, 'Controlled native GitHub metric paragraph is present in the generated companion editor.' );
	const targetBlock = canvas.locator( `[data-block="${ targetClientId }"]` );
	await targetBlock.click();
	const blockTab = page.getByRole( 'tab', { name: 'Block' } );
	if ( await blockTab.count() && 'true' !== await blockTab.getAttribute( 'aria-selected' ) ) { await blockTab.click(); }
	const metricPanel = page.getByRole( 'button', { name: 'External metric', exact: true } ).first();
	await metricPanel.waitFor( { timeout: 5000 } ).catch( async () => {
		const selected = await page.evaluate( () => { const id = window.wp.data.select( 'core/block-editor' ).getSelectedBlockClientId(); return id ? window.wp.data.select( 'core/block-editor' ).getBlock( id ) : null; } );
		const runtime = await page.evaluate( () => ( { hooks: !! window.wp?.hooks, components: !! window.wp?.components, blockEditor: !! window.wp?.blockEditor, config: window.ssiExternalMetricConfig || null } ) );
		const scripts = await page.locator( 'script[src*="external-metric"]' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.src ) );
		await page.screenshot( { path: `${ evidenceDir }/editor-debug.png`, fullPage: true } );
		await writeFile( `${ evidenceDir }/editor-debug.json`, JSON.stringify( { selected, scripts, runtime, errors, body: ( await page.locator( 'body' ).innerText() ).slice( 0, 2000 ) }, null, 2 ) );
		throw new Error( 'External metric inspector was not registered; state retained in editor-debug.json.' );
	} );
	try {
		await metricPanel.click( { timeout: 5000 } );
	} catch ( error ) {
		const selected = await page.evaluate( () => { const id = window.wp.data.select( 'core/block-editor' ).getSelectedBlockClientId(); return id ? window.wp.data.select( 'core/block-editor' ).getBlock( id ) : null; } );
		const buttons = await page.getByRole( 'button' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => ( { text: node.innerText, disabled: node.disabled, expanded: node.getAttribute( 'aria-expanded' ), label: node.getAttribute( 'aria-label' ) } ) ).filter( ( item ) => item.text.includes( 'External' ) || item.label?.includes( 'External' ) ) );
		await page.screenshot( { path: `${ evidenceDir }/editor-disabled-source-control.png`, fullPage: true } );
		await writeFile( `${ evidenceDir }/editor-disabled-source-control.json`, JSON.stringify( { selected, buttons, body: ( await page.locator( 'body' ).innerText() ).slice( 0, 4000 ), error: error.message }, null, 2 ) );
		throw error;
	}
	await page.getByText( /Source: github\.repository-information · Metric: stargazers_count/ ).waitFor();
	const sourceIdentity = await page.getByText( /Source: github\.repository-information · Metric: stargazers_count/ ).innerText();
	const contentBeforeRefresh = await page.evaluate( async ( id ) => ( await window.wp.apiFetch( { path: '/wp/v2/pages/' + id + '?context=edit' } ) ).content.raw, postId );
	const dirtyBeforeRefresh = await page.evaluate( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
	assert.equal( dirtyBeforeRefresh, false, 'The controlled fallback page is initially clean.' );
	assert.equal( await page.evaluate( () => typeof window.wp.apiFetch ), 'function', 'WordPress API fetch is available to editor controls.' );
	const refreshPromise = page.waitForResponse( ( response ) => response.url().includes( 'external-metrics' ) && 'POST' === response.request().method(), { timeout: 10000 } ).catch( async () => {
		await writeFile( `${ evidenceDir }/editor-refresh-debug.json`, JSON.stringify( { requests: refreshRequests, errors, body: ( await page.locator( 'body' ).innerText() ).slice( 0, 1500 ) }, null, 2 ) );
		throw new Error( 'The editor refresh control did not issue its REST request.' );
	} );
	await page.getByRole( 'button', { name: 'Refresh value' } ).click();
	const refreshResponse = await refreshPromise;
	assert.equal( refreshResponse.status(), 200, 'Editor refresh endpoint succeeds.' );
	const refreshResult = await refreshResponse.json();
	assert.equal( refreshResult.status, 'fresh', 'Editor refresh reports the canonical provider receipt status.' );
	assert.equal( refreshResult.value, expectedValue, 'Editor refresh returns the independently checked current GitHub stars value.' );
	assert.equal( refreshResult.receipt?.status, 'fresh', 'Editor refresh includes the actual canonical freshness receipt.' );
	assert.ok( Number.isInteger( refreshResult.receipt?.fetched_at ), 'Fresh editor value exposes its fetch timestamp.' );
	await page.getByText( `Current value: ${ expectedValue } · Freshness: fresh`, { exact: true } ).waitFor();
	const contentAfterRefresh = await page.evaluate( async ( id ) => ( await window.wp.apiFetch( { path: '/wp/v2/pages/' + id + '?context=edit' } ) ).content.raw, postId );
	assert.deepEqual( contentAfterRefresh, contentBeforeRefresh, 'Editor refresh changes no persisted post content.' );
	assert.equal( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() ), false, 'Refresh does not dirty the editor post.' );
	const beforeDetach = await page.evaluate( () => {
		const clientId = window.wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
		return window.wp.data.select( 'core/block-editor' ).getBlock( clientId );
	} );
	assert.equal( beforeDetach.attributes.metadata.bindings.content.source, 'ssi/external-metric' );
	await page.getByRole( 'button', { name: 'Detach to static text' } ).click();
	const detached = await page.evaluate( () => {
		const clientId = window.wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
		return window.wp.data.select( 'core/block-editor' ).getBlock( clientId );
	} );
	assert.equal( detached.attributes.metadata?.bindings?.content, undefined, 'Detach removes only the external text binding.' );
	assert.equal( detached.attributes.content, expectedValue, 'Detach freezes the visibly refreshed current GitHub value without another text edit.' );
	assert.equal( detached.attributes.metadata?.name, 'Preserve target metadata', 'Detach preserves the target block metadata.' );
	assert.equal( detached.attributes.metadata?.custom, 'preserve-me', 'Detach preserves unrelated target metadata.' );
	const siblingBlock = await page.evaluate( () => {
		const visit = ( blocks ) => {
			for ( const block of blocks || [] ) {
				if ( block.attributes?.metadata?.name === 'Preserve sibling binding' ) { return block; }
				const nested = visit( block.innerBlocks );
				if ( nested ) { return nested; }
			}
			return null;
		};
		return visit( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	} );
	assert.equal( siblingBlock?.attributes?.metadata?.bindings?.content?.args?.metric_id, 'editor-sibling-metric', 'Detach preserves the sibling native metric binding.' );
	const editedPost = await page.evaluate( () => ( { dirty: window.wp.data.select( 'core/editor' ).isEditedPostDirty(), content: window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' ) } ) );
	assert.equal( editedPost.dirty, true, 'Detaching the current value creates an unsaved editor edit without a compensating text edit.' );
	assert.ok( editedPost.content.includes( expectedValue ), 'Canonical edited post serialization contains the frozen current value.' );
	await page.getByRole( 'button', { name: /^Save$/ } ).click();
	await page.waitForFunction( () => { const editor = window.wp.data.select( 'core/editor' ); return ! editor.isSavingPost() && ! editor.isEditedPostDirty(); } );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '[data-type="core/paragraph"]' ).first().waitFor();
	const saved = await page.evaluate( async ( { id, value } ) => {
		const post = await window.wp.apiFetch( { path: '/wp/v2/pages/' + id + '?context=edit' } );
		const raw = post.content.raw;
		const blockFor = ( marker, closing ) => {
			const markerOffset = raw.indexOf( marker );
			if ( markerOffset < 0 ) { return ''; }
			const blockStart = raw.lastIndexOf( '<!-- wp:', markerOffset );
			const blockEnd = raw.indexOf( closing, markerOffset );
			return blockStart >= 0 && blockEnd >= 0 ? raw.slice( blockStart, blockEnd + closing.length ) : '';
		};
		return {
			raw,
			targetMarkup: blockFor( '<p>' + value + '</p>', '<!-- /wp:paragraph -->' ),
			siblingMarkup: blockFor( 'editor-sibling-metric', '<!-- /wp:paragraph -->' ),
		};
	}, { id: postId, value: expectedValue } );
	assert.ok( saved.targetMarkup.includes( `<p>${ expectedValue }</p>` ), 'The detached current GitHub value persists as static text after editor reload.' );
	assert.equal( saved.targetMarkup.includes( 'ssi/external-metric' ), false, 'Detached target remains unbound after editor reload.' );
	assert.ok( saved.targetMarkup.includes( 'Preserve target metadata' ), 'Target metadata survives save and reload.' );
	assert.ok( saved.targetMarkup.includes( 'preserve-me' ), 'Unrelated target metadata survives save and reload.' );
	assert.ok( saved.siblingMarkup.includes( 'ssi/external-metric' ) && saved.siblingMarkup.includes( 'editor-sibling-metric' ), 'Sibling binding survives save and reload.' );
	assert.ok( restSaves.some( ( status ) => status >= 200 && status < 300 ), 'Editor save returned a successful WordPress REST response.' );
	await page.goto( `${ base }/?page_id=${ postId }`, { waitUntil: 'domcontentloaded' } );
	const frontendText = await page.locator( 'body' ).innerText();
	assert.ok( frontendText.includes( expectedValue ), 'Frontend renders the saved current GitHub value as static text.' );
	assert.ok( frontendText.includes( expectedForks ), 'Sibling bound block refreshes from the newly updated shared source response.' );
	assert.doesNotMatch( frontendText, /captured-editor-forks/, 'Sibling GitHub binding does not fall back to its captured value after the shared response cache hit.' );
	assert.ok( frontendText.includes( '<em>pending</em> "quoted" & &' ), 'Frontend renders captured literal markup tags, quotes and entity characters as text.' );
	assert.equal( await page.locator( 'em' ).filter( { hasText: 'pending' } ).count(), 0, 'Captured literal fallback does not become an HTML emphasis element.' );
	assert.deepEqual( errors, [], 'Editor emitted no browser errors.' );
	await page.screenshot( { path: `${ evidenceDir }/editor-saved.png`, fullPage: true } );
	const result = { schema: 'ssi/external-metric-editor-acceptance/v1', status: 'passed', core: '7.1', postId, sourceIdentity, refresh: { status: refreshResponse.status(), result: refreshResult }, dirtyBeforeRefresh, dirtyAfterRefresh: false, saved, frontendText, restSaves, browserErrors: errors };
	await writeFile( `${ evidenceDir }/editor.json`, `${ JSON.stringify( result, null, 2 ) }\n` );
	console.log( JSON.stringify( result ) );
} finally {
	await browser.close();
}
