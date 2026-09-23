#!/usr/bin/env node

/**
 * Post-import pipeline step: project an imported page's sections through
 * interchangeable layout adapters, keeping only what a real render proves
 * safe.
 *
 * For each content section (`lib/layout-placement-capture.mjs`), the
 * adapters are tried in preference order by applying a
 * `static-site-importer/layout-plan/v1` (via `wp static-site-importer
 * project-layout --plan=...`) that carries the already-accepted sections plus
 * this candidate, rendering the live frontend at a fixed set of widths, and
 * gating the result with `lib/layout-projection-gate.mjs`. The first adapter
 * whose render passes the gate is kept; otherwise the section falls back to
 * `none` and the failures are recorded. The whole run always starts by
 * restoring any prior projection, so it is idempotent: running it twice on
 * the same page produces the same final content.
 *
 * `--wp`, `--admin-user`/`--admin-password`, and page rendering are the only
 * WordPress-shaped inputs; the render gate itself
 * (`lib/layout-projection-gate.mjs`) is pure and browser-free, which is why
 * it lives in `lib/` and is unit tested without WordPress or a browser (see
 * `tests/layout-projection-gate.test.mjs`). This orchestrator is exercised
 * the same way: `runWpCli` and `measureSection` are injectable, so
 * `tests/project-imported-layout.test.mjs` can run the whole adapter/gate
 * loop against a fake WP-CLI runner and a static fixture server, with no
 * WordPress install and no real browser.
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { PATH_ATTRIBUTE, captureLayoutSections } from '../lib/layout-placement-capture.mjs';
import { DEFAULT_GATE_WIDTHS, DEFAULT_REFERENCE_WIDTH, evaluateSection } from '../lib/layout-projection-gate.mjs';

export const PLAN_SCHEMA = 'static-site-importer/layout-plan/v1';
export const RUN_SCHEMA = 'static-site-importer/layout-projection-run/v1';

// `none` short-circuits before the projector ever resolves a host path (see
// Static_Site_Importer_Layout_Projector::project()), so any syntactically
// valid placement model restores a page regardless of its real structure.
const RESTORE_PLACEMENT = {
	schema: 'static-site-importer/layout-placement/v1',
	host: { path: '0', viewports: { 1440: { x: 0, y: 0, width: 1, height: 1 } } },
	items: [ { path: '0.0', viewports: { 1440: { x: 0, y: 0, width: 1, height: 1 } } } ],
};

/**
 * Split a `--wp` command prefix ("studio wp" or "wp --path=<root>") into argv.
 *
 * @param {string} wpCommand Command prefix.
 * @param {Array<string>} extra Trailing arguments.
 * @returns {Array<string>}
 */
export function buildArgv( wpCommand, extra ) {
	const prefix = String( wpCommand ).trim().split( /\s+/ ).filter( Boolean );
	return [ ...prefix, ...extra ];
}

/**
 * Default WP-CLI runner: a real child process.
 *
 * @param {Array<string>} argv Full argv, including the `wp`/`studio` prefix.
 * @returns {{status:number,stdout:string,stderr:string}}
 */
export function defaultRunWpCli( argv ) {
	const result = spawnSync( argv[ 0 ], argv.slice( 1 ), { encoding: 'utf8' } );
	return {
		status: result.status ?? ( result.error ? 1 : 0 ),
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? ( result.error ? String( result.error.message ) : '' ),
	};
}

/**
 * Interpret one `project-layout` WP-CLI invocation's raw process output.
 *
 * @param {{status:number,stdout:string,stderr:string}} raw Runner output.
 * @returns {{ok:boolean,adapterUnavailable?:boolean,receipt?:object,error?:string,stdout:string,stderr:string,status:number}}
 */
export function interpretProjectLayoutRun( raw ) {
	const { status, stdout, stderr } = raw;
	if ( /requires unregistered block types/.test( stderr ) ) {
		return { ok: false, adapterUnavailable: true, stdout, stderr, status };
	}
	const receipt = lastJsonLine( stdout );
	if ( ! receipt ) {
		return { ok: false, error: 'wp_cli_no_receipt', stdout, stderr, status };
	}
	return { ok: 0 === status, receipt, stdout, stderr, status };
}

function lastJsonLine( stdout ) {
	const lines = String( stdout ).split( '\n' ).map( line => line.trim() ).filter( Boolean );
	for ( let index = lines.length - 1; index >= 0; index-- ) {
		try {
			return JSON.parse( lines[ index ] );
		} catch {
			// Not the JSON line (WP-CLI warnings/notices may precede it); keep looking.
		}
	}
	return null;
}

function writeTempJson( tmpDir, prefix, value ) {
	const file = path.join( tmpDir, `${ prefix }-${ process.pid }-${ randomBytes( 6 ).toString( 'hex' ) }.json` );
	fs.writeFileSync( file, JSON.stringify( value ) );
	return file;
}

/**
 * Restore any prior projection on a page (`--adapter=none`).
 *
 * @param {(argv:Array<string>)=>{status:number,stdout:string,stderr:string}} runWpCli
 * @param {string} wpCommand
 * @param {number} pageId
 * @param {string} tmpDir
 * @returns {ReturnType<typeof interpretProjectLayoutRun>}
 */
function restorePriorProjection( runWpCli, wpCommand, pageId, tmpDir ) {
	const placementFile = writeTempJson( tmpDir, 'ssi-layout-restore', RESTORE_PLACEMENT );
	try {
		const argv = buildArgv( wpCommand, [
			'static-site-importer', 'project-layout',
			`--page=${ pageId }`,
			`--placement=${ placementFile }`,
			'--adapter=none',
			'--user=admin',
		] );
		return interpretProjectLayoutRun( runWpCli( argv ) );
	} finally {
		fs.rmSync( placementFile, { force: true } );
	}
}

/**
 * Apply one `layout-plan/v1` to a page through WP-CLI.
 *
 * @param {(argv:Array<string>)=>{status:number,stdout:string,stderr:string}} runWpCli
 * @param {string} wpCommand
 * @param {number} pageId
 * @param {{schema:string,sections:Array<{placement:object,adapter:string}>}} plan
 * @param {string} tmpDir
 * @returns {ReturnType<typeof interpretProjectLayoutRun>}
 */
function applyPlan( runWpCli, wpCommand, pageId, plan, tmpDir ) {
	const planFile = writeTempJson( tmpDir, 'ssi-layout-plan', plan );
	try {
		const argv = buildArgv( wpCommand, [
			'static-site-importer', 'project-layout',
			`--page=${ pageId }`,
			`--plan=${ planFile }`,
			'--user=admin',
		] );
		return interpretProjectLayoutRun( runWpCli( argv ) );
	} finally {
		fs.rmSync( planFile, { force: true } );
	}
}

/**
 * Resolve a section's item elements and measure them in the browser.
 *
 * After a Canvas projection the placed items live one level deeper, inside
 * the `tabor/canvas` child; this first tries the host's direct marked
 * children, then falls back to the one marked grandchild whose own marked
 * children match the original item count, and reports items in the recorded
 * source item order either way.
 *
 * Runs inside `page.evaluate`; must be a plain, self-contained function.
 *
 * @param {{attribute:string,hostPath:string,itemPaths:Array<string>}} args
 * @returns {Array<object>|null}
 */
/* c8 ignore start -- exercised only inside a real browser context. */
function resolveAndMeasureInBrowser( { attribute, hostPath, itemPaths, itemSelector } ) {
	const host = document.querySelector( `[${ attribute }="${ hostPath }"]` );
	if ( ! host ) {
		return null;
	}
	const measure = ( element, index ) => {
		const rect = element.getBoundingClientRect();
		return {
			path: itemPaths[ index ],
			x: rect.x,
			y: rect.y,
			width: rect.width,
			height: rect.height,
			clientWidth: element.clientWidth,
			clientHeight: element.clientHeight,
			scrollWidth: element.scrollWidth,
			scrollHeight: element.scrollHeight,
			pageOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
		};
	};
	// The adapter declares where its rendered items live (for example a
	// Canvas grid's item wrappers), in placement order.
	if ( itemSelector ) {
		const rendered = Array.from( host.querySelectorAll( `:scope ${ itemSelector }` ) );
		return rendered.length === itemPaths.length ? rendered.map( measure ) : null;
	}
	const nearestMarkedAncestor = element => {
		let parent = element.parentElement;
		while ( parent && ! parent.hasAttribute( attribute ) ) {
			parent = parent.parentElement;
		}
		return parent;
	};
	const marked = Array.from( host.querySelectorAll( `[${ attribute }]` ) );
	const directChildren = marked.filter( element => nearestMarkedAncestor( element ) === host );

	let resolved = null;
	if ( directChildren.length === itemPaths.length ) {
		resolved = directChildren;
	} else {
		for ( const child of directChildren ) {
			const grandchildren = marked.filter( element => nearestMarkedAncestor( element ) === child );
			if ( grandchildren.length === itemPaths.length ) {
				resolved = grandchildren;
				break;
			}
		}
	}
	if ( ! resolved ) {
		return null;
	}

	return resolved.map( measure );
}
/* c8 ignore stop */

function captureUrl( origin, pageId ) {
	const url = new URL( origin );
	url.searchParams.set( 'p', String( pageId ) );
	url.searchParams.set( 'ssi_layout_capture', '1' );
	return url.toString();
}

async function loginAsAdmin( page, origin, adminUser, adminPassword ) {
	if ( ! adminUser || ! adminPassword ) {
		return;
	}
	const loginUrl = new URL( '/wp-login.php', origin ).toString();
	await page.goto( loginUrl );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPassword );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

function defaultCaptureSections( { origin, adminUser, adminPassword, pageId, widths } ) {
	return async () => {
		const { chromium } = await import( 'playwright' );
		const browser = await chromium.launch();
		try {
			const page = await browser.newPage();
			await loginAsAdmin( page, origin, adminUser, adminPassword );
			await page.goto( captureUrl( origin, pageId ) );
			return await captureLayoutSections( page, widths );
		} finally {
			await browser.close();
		}
	};
}

function defaultMeasureSection( { origin, adminUser, adminPassword, pageId } ) {
	return async ( { hostPath, itemPaths, width, itemSelector = '' } ) => {
		const { chromium } = await import( 'playwright' );
		const browser = await chromium.launch();
		try {
			const page = await browser.newPage();
			await loginAsAdmin( page, origin, adminUser, adminPassword );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( captureUrl( origin, pageId ) );
			await page.waitForLoadState( 'networkidle' );
			return await page.evaluate( resolveAndMeasureInBrowser, { attribute: PATH_ATTRIBUTE, hostPath, itemPaths, itemSelector } );
		} finally {
			await browser.close();
		}
	};
}

/**
 * Normalize and validate `projectImportedLayout` options.
 *
 * @param {object} input
 * @returns {object}
 */
function normalizeOptions( input ) {
	const pageId = Number( input.pageId );
	if ( ! Number.isInteger( pageId ) || pageId <= 0 ) {
		throw new Error( 'projectImportedLayout requires a positive integer pageId.' );
	}
	const wpCommand = String( input.wpCommand || '' ).trim();
	if ( '' === wpCommand ) {
		throw new Error( 'projectImportedLayout requires a wpCommand prefix (for example "studio wp" or "wp --path=<site root>").' );
	}
	const adapters = Array.isArray( input.adapters ) ? input.adapters.map( String ).filter( Boolean ) : [];
	if ( 0 === adapters.length ) {
		throw new Error( 'projectImportedLayout requires at least one adapter in preference order.' );
	}
	return {
		origin: input.origin ? String( input.origin ) : '',
		pageId,
		wpCommand,
		adapters,
		adminUser: input.adminUser ? String( input.adminUser ) : '',
		adminPassword: input.adminPassword ? String( input.adminPassword ) : '',
		widths: Array.isArray( input.widths ) && input.widths.length > 0 ? input.widths.map( Number ) : DEFAULT_GATE_WIDTHS,
		referenceWidth: input.referenceWidth ? Number( input.referenceWidth ) : DEFAULT_REFERENCE_WIDTH,
		receiptPath: input.receiptPath ? String( input.receiptPath ) : '',
		tmpDir: input.tmpDir ? String( input.tmpDir ) : os.tmpdir(),
		runWpCli: input.runWpCli ?? defaultRunWpCli,
		captureSections: input.captureSections ?? defaultCaptureSections( input ),
		measureSection: input.measureSection ?? defaultMeasureSection( input ),
	};
}

/**
 * Project one page's sections through interchangeable layout adapters.
 *
 * @param {object} input
 * @param {string} input.origin Frontend origin the page renders on.
 * @param {number} input.pageId Page id.
 * @param {string} input.wpCommand WP-CLI command prefix (`--user=admin` is added automatically).
 * @param {Array<string>} input.adapters Adapter ids in preference order (`none` is always the implicit fallback).
 * @param {string} [input.adminUser] Admin username for a logged-in capture render.
 * @param {string} [input.adminPassword] Admin password for a logged-in capture render.
 * @param {Array<number>} [input.widths] Gate widths. Defaults to the render gate's defaults.
 * @param {number} [input.referenceWidth] Reference width. Defaults to the render gate's default (1440).
 * @param {string} [input.receiptPath] Where to write the run receipt, if given.
 * @param {string} [input.tmpDir] Directory for scratch plan/placement files.
 * @param {(argv:Array<string>)=>{status:number,stdout:string,stderr:string}} [input.runWpCli] Injectable WP-CLI runner.
 * @param {()=>Promise<{sections:Array<object>,skipped:Array<object>}>} [input.captureSections] Injectable section capture.
 * @param {(args:{hostPath:string,itemPaths:Array<string>,width:number})=>Promise<Array<object>|null>} [input.measureSection] Injectable per-width item measurement.
 * @returns {Promise<object>} The `static-site-importer/layout-projection-run/v1` receipt.
 */
export async function projectImportedLayout( input ) {
	const options = normalizeOptions( input );
	const { runWpCli, captureSections, measureSection } = options;

	// 1. Restore any prior projection, so section discovery always sees the
	// original content and every candidate render starts from a clean slate.
	const restoreOutcome = restorePriorProjection( runWpCli, options.wpCommand, options.pageId, options.tmpDir );
	if ( ! restoreOutcome.ok && ! restoreOutcome.receipt ) {
		throw new Error( `projectImportedLayout: failed to restore the prior projection on page ${ options.pageId }: ${ restoreOutcome.error ?? restoreOutcome.stderr }` );
	}

	// 2. Capture every content section from the restored (original) content.
	const captured = await captureSections();
	const sections = Array.isArray( captured.sections ) ? captured.sections : [];
	const skipped = Array.isArray( captured.skipped ) ? captured.skipped : [];

	const accepted = [];
	const receiptSections = [];

	// The unprojected sections, measured once at every gate width: overlaps and
	// overflow the original already shows at a width are not projection defects.
	const baselines = [];
	for ( const section of sections ) {
		const itemPaths = section.items.map( item => item.path );
		const byWidth = {};
		for ( const width of options.widths ) {
			byWidth[ width ] = ( await measureSection( { hostPath: section.host.path, itemPaths, width } ) ) ?? [];
		}
		baselines.push( byWidth );
	}

	for ( let index = 0; index < sections.length; index++ ) {
		const section = sections[ index ];
		const hostPath = section.host.path;
		const itemPaths = section.items.map( item => item.path );
		const tried = [];
		let chosenAdapter = null;

		// 3. Try each adapter in preference order.
		for ( const adapterId of options.adapters ) {
			const candidatePlan = {
				schema: PLAN_SCHEMA,
				sections: [
					...accepted,
					{ placement: section, adapter: adapterId },
					...sections.slice( index + 1 ).map( remaining => ( { placement: remaining, adapter: 'none' } ) ),
				],
			};
			const outcome = applyPlan( runWpCli, options.wpCommand, options.pageId, candidatePlan, options.tmpDir );

			if ( outcome.adapterUnavailable ) {
				tried.push( { adapter: adapterId, result: 'adapter_unavailable' } );
				continue;
			}
			if ( ! outcome.ok || ! outcome.receipt || true !== outcome.receipt.applied ) {
				tried.push( { adapter: adapterId, result: 'not_applied', detail: outcome.receipt ?? outcome.error ?? outcome.stderr } );
				continue;
			}

			// Gate the items the adapter actually placed, where it says they render.
			const sectionReceipt = Array.isArray( outcome.receipt.sections ) ? outcome.receipt.sections[ index ] ?? {} : {};
			const lostPaths = new Set( ( sectionReceipt.losses ?? [] ).map( loss => loss.item ) );
			const placedPaths = itemPaths.filter( path => ! lostPaths.has( path ) );
			const itemSelector = String( sectionReceipt.rendered_item_selector ?? '' );
			const measurementsByWidth = {};
			let unresolved = false;
			for ( const width of options.widths ) {
				const items = await measureSection( { hostPath, itemPaths: placedPaths, width, itemSelector } );
				if ( ! items ) {
					unresolved = true;
					break;
				}
				measurementsByWidth[ width ] = items;
			}
			if ( unresolved ) {
				tried.push( { adapter: adapterId, result: 'items_unresolved' } );
				continue;
			}

			const baselineByWidth = Object.fromEntries( Object.entries( baselines[ index ] ).map( ( [ width, items ] ) => [ width, items.filter( item => placedPaths.includes( item.path ) ) ] ) );
			const evaluation = evaluateSection( measurementsByWidth, options.referenceWidth, baselineByWidth );
			tried.push( { adapter: adapterId, result: evaluation.pass ? 'passed' : 'failed_gate', failures: evaluation.failures } );
			if ( evaluation.pass ) {
				// 4. Keep the first adapter that passes.
				chosenAdapter = adapterId;
				break;
			}
		}

		// 4. Otherwise fall back to `none`; the failures above already explain why.
		const finalAdapter = chosenAdapter ?? 'none';
		accepted.push( { placement: section, adapter: finalAdapter } );
		receiptSections.push( {
			host: hostPath,
			items: itemPaths.length,
			adapters_tried: tried,
			adapter: finalAdapter,
		} );
	}

	// 5. Apply the final plan (nothing to apply when no section was found).
	const finalOutcome = 0 === accepted.length ? { ok: true, receipt: null } : applyPlan( runWpCli, options.wpCommand, options.pageId, { schema: PLAN_SCHEMA, sections: accepted }, options.tmpDir );
	if ( ! finalOutcome.ok ) {
		throw new Error( `projectImportedLayout: failed to apply the final layout plan on page ${ options.pageId }: ${ [ finalOutcome.error, String( finalOutcome.stderr || '' ).trim().split( '\n' ).slice( -5 ).join( ' | ' ), String( finalOutcome.stdout || '' ).trim().split( '\n' ).slice( -3 ).join( ' | ' ) ].filter( Boolean ).join( ': ' ) }` );
	}

	// 6. Write the run receipt.
	const receipt = {
		schema: RUN_SCHEMA,
		page: options.pageId,
		sections: receiptSections,
		skipped,
		final: finalOutcome.receipt,
	};

	if ( '' !== options.receiptPath ) {
		fs.writeFileSync( options.receiptPath, JSON.stringify( receipt, null, 2 ) );
	}

	return receipt;
}

/**
 * Read the `layout_projection` instructions an import receipt recorded.
 *
 * @param {string} receiptInPath Path to an import CLI receipt.
 * @returns {{adapters:Array<string>,pages:Array<number>}|null}
 */
export function readLayoutProjectionInstructions( receiptInPath ) {
	const receipt = JSON.parse( fs.readFileSync( receiptInPath, 'utf8' ) );
	const projection = receipt.layout_projection ?? receipt.response?.layout_projection ?? null;
	if ( ! projection || 'requested' !== projection.status ) {
		return null;
	}
	return {
		adapters: Array.isArray( projection.adapters ) ? projection.adapters.map( String ) : [],
		pages: Array.isArray( projection.pages ) ? projection.pages.map( Number ) : [],
	};
}

function toCamel( value ) {
	return value.replace( /-([a-z])/g, ( _match, letter ) => letter.toUpperCase() );
}

/**
 * Parse `tools/project-imported-layout.mjs` CLI arguments.
 *
 * @param {Array<string>} args `process.argv.slice(2)`.
 * @returns {object}
 */
export function parseProjectImportedLayoutArgs( args ) {
	const options = {};
	for ( let index = 0; index < args.length; index++ ) {
		const arg = args[ index ];
		if ( '--help' === arg || '-h' === arg ) {
			return { help: true };
		}
		if ( ! arg.startsWith( '--' ) ) {
			continue;
		}
		const [ rawKey, inline ] = arg.slice( 2 ).split( '=' );
		options[ toCamel( rawKey ) ] = undefined === inline ? args[ ++index ] : inline;
	}
	return options;
}

function printHelp() {
	process.stdout.write(
		'Usage: node tools/project-imported-layout.mjs --origin <url> --page <id> --wp "<command prefix>" --adapters <id,id,...> ' +
		'[--admin-user <user> --admin-password <password>] [--widths <w,w,...>] [--reference-width <n>] [--receipt <file>] [--receipt-in <import-receipt.json>]\n'
	);
}

async function main() {
	const raw = parseProjectImportedLayoutArgs( process.argv.slice( 2 ) );
	if ( raw.help ) {
		printHelp();
		return;
	}

	let pages = raw.page ? [ Number( raw.page ) ] : [];
	let adapters = raw.adapters ? String( raw.adapters ).split( ',' ).map( value => value.trim() ).filter( Boolean ) : [];

	if ( raw.receiptIn ) {
		const instructions = readLayoutProjectionInstructions( raw.receiptIn );
		if ( instructions ) {
			pages = pages.length > 0 ? pages : instructions.pages;
			adapters = adapters.length > 0 ? adapters : instructions.adapters;
		}
	}

	if ( 0 === pages.length ) {
		throw new Error( 'Provide --page=<id>, or --receipt-in=<import receipt> that recorded page ids.' );
	}
	if ( 0 === adapters.length ) {
		throw new Error( 'Provide --adapters=<id,id,...>, or --receipt-in=<import receipt> that recorded adapters.' );
	}
	if ( ! raw.wp ) {
		throw new Error( 'Provide --wp="<command prefix>" (for example "studio wp" or "wp --path=<site root>").' );
	}

	const widths = raw.widths ? String( raw.widths ).split( ',' ).map( value => Number( value.trim() ) ) : undefined;
	const referenceWidth = raw.referenceWidth ? Number( raw.referenceWidth ) : undefined;

	const receipts = [];
	for ( const pageId of pages ) {
		receipts.push(
			await projectImportedLayout( {
				origin: raw.origin,
				pageId,
				wpCommand: raw.wp,
				adapters,
				adminUser: raw.adminUser,
				adminPassword: raw.adminPassword,
				widths,
				referenceWidth,
			} )
		);
	}

	if ( raw.receipt ) {
		fs.writeFileSync( raw.receipt, JSON.stringify( 1 === receipts.length ? receipts[ 0 ] : receipts, null, 2 ) );
	}
	process.stdout.write( `${ JSON.stringify( 1 === receipts.length ? receipts[ 0 ] : receipts ) }\n` );
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	main().catch( error => {
		process.stderr.write( `${ error.message }\n` );
		process.exitCode = 1;
	} );
}
