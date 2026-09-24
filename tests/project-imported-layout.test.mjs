import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import test from 'node:test';
import {
	PLAN_SCHEMA,
	RUN_SCHEMA,
	buildArgv,
	interpretProjectLayoutRun,
	projectImportedLayout,
	readLayoutProjectionInstructions,
	captureWidthsForViewport,
	readSiteViewport,
} from '../tools/project-imported-layout.mjs';

const PAGE_ID = 42;

/**
 * A real static HTTP server standing in for the imported page's frontend.
 *
 * The fake WP-CLI runner (below) records which adapter is currently applied
 * to each section host; this server looks that up and returns the
 * pre-authored item geometry for that (host, adapter, width) combination as
 * JSON, so the injected `measureSection` can fetch it over a real HTTP round
 * trip without a browser or WordPress.
 */
function startFixtureServer( state ) {
	const server = http.createServer( ( request, response ) => {
		const url = new URL( request.url, 'http://127.0.0.1' );
		const hostPath = url.searchParams.get( 'host' );
		const width = url.searchParams.get( 'width' );
		const adapter = state.appliedAdapterByHost[ hostPath ] ?? 'none';
		const items = state.geometry?.[ hostPath ]?.[ adapter ]?.[ width ] ?? [];
		response.setHeader( 'content-type', 'application/json' );
		response.end( JSON.stringify( items ) );
	} );
	return new Promise( ( resolve, reject ) => {
		server.listen( 0, '127.0.0.1', () => resolve( server ) );
		server.once( 'error', reject );
	} );
}

function makeMeasureSection( origin ) {
	return async ( { hostPath, width } ) => {
		const response = await fetch( `${ origin }/measure?host=${ encodeURIComponent( hostPath ) }&width=${ width }` );
		return response.json();
	};
}

function makeCaptureSections( sections, skipped = [] ) {
	return async () => ( { sections, skipped } );
}

/**
 * A fake WP-CLI runner standing in for `wp static-site-importer project-layout`.
 *
 * It reads the `--plan=<file>` (or `--placement=<file> --adapter=none` restore
 * call) the orchestrator wrote, updates `state.appliedAdapterByHost` so the
 * fixture server serves the right geometry next, and returns the same shape
 * of stdout/stderr a real WP-CLI process would.
 */
function makeFakeRunWpCli( state ) {
	return argv => {
		state.calls.push( [ ...argv ] );
		const planArg = argv.find( arg => arg.startsWith( '--plan=' ) );
		const placementArg = argv.find( arg => arg.startsWith( '--placement=' ) );
		const adapterArg = argv.find( arg => arg.startsWith( '--adapter=' ) );

		if ( planArg ) {
			const plan = JSON.parse( fs.readFileSync( planArg.slice( '--plan='.length ), 'utf8' ) );
			assert.equal( plan.schema, PLAN_SCHEMA );
			const unavailable = plan.sections.find( section => state.unavailableAdapters.includes( section.adapter ) );
			if ( unavailable ) {
				return {
					status: 1,
					stdout: '',
					stderr: `Error: The ${ unavailable.adapter } layout adapter requires unregistered block types: tabor/canvas.\n`,
				};
			}
			state.appliedAdapterByHost = {};
			for ( const section of plan.sections ) {
				state.appliedAdapterByHost[ section.placement.host.path ] = section.adapter;
			}
			const allNone = plan.sections.every( section => 'none' === section.adapter );
			const receipt = {
				schema: 'static-site-importer/layout-projection-receipt/v1',
				page: PAGE_ID,
				adapter: 1 === plan.sections.length ? plan.sections[ 0 ].adapter : 'plan',
				applied: true,
				dry_run: false,
				sections: plan.sections.map( section => ( {
					placement: 'plan',
					host: section.placement.host.path,
					adapter: section.adapter,
					applied: true,
					placed: 'none' === section.adapter ? 0 : section.placement.items.length,
					reason: '',
					losses: [],
				} ) ),
				restored: allNone,
				snapshot_stored: ! allNone,
				content_sha: { before: 'before-sha', after: 'after-sha' },
			};
			return { status: 0, stdout: `${ JSON.stringify( receipt ) }\n`, stderr: '' };
		}

		if ( placementArg && adapterArg === '--adapter=none' ) {
			// The restore call at the start of every run.
			state.appliedAdapterByHost = {};
			const receipt = {
				schema: 'static-site-importer/layout-projection-receipt/v1',
				page: PAGE_ID,
				adapter: 'none',
				applied: true,
				dry_run: false,
				sections: [ { placement: placementArg.slice( '--placement='.length ), host: '0', adapter: 'none', applied: true, placed: 0, reason: '', losses: [] } ],
				restored: state.hadSnapshot,
				snapshot_stored: false,
				content_sha: { before: 'x', after: 'x' },
			};
			return { status: 0, stdout: `${ JSON.stringify( receipt ) }\n`, stderr: '' };
		}

		return { status: 1, stdout: '', stderr: 'Error: unrecognized invocation in the fake WP-CLI runner.\n' };
	};
}

const item = ( path, x, y, width = 100, height = 40 ) => ( {
	path, x, y, width, height,
	clientWidth: width, clientHeight: height, scrollWidth: width, scrollHeight: height,
} );

const model = ( hostPath, itemPaths ) => ( {
	schema: 'static-site-importer/layout-placement/v1',
	host: { path: hostPath, viewports: { 1440: { x: 0, y: 0, width: 1200, height: 200 } } },
	items: itemPaths.map( path => ( { path, viewports: { 1440: { x: 0, y: 0, width: 100, height: 40 } } } ) ),
} );

async function withFixtureServer( state, run ) {
	const server = await startFixtureServer( state );
	const { port } = server.address();
	try {
		await run( `http://127.0.0.1:${ port }` );
	} finally {
		await new Promise( resolve => server.close( resolve ) );
	}
}

test( 'keeps the first adapter that passes the render gate', async () => {
	const section = model( '0', [ '0.0', '0.1', '0.2' ] );
	const state = {
		calls: [],
		unavailableAdapters: [],
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: {
			0: {
				canvas: {
					1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
					390: [ item( '0.0', 0, 0 ), item( '0.1', 0, 50 ), item( '0.2', 0, 100 ) ],
				},
			},
		},
	};

	await withFixtureServer( state, async origin => {
		const receipt = await projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440, 390 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ section ] ),
			measureSection: makeMeasureSection( origin ),
		} );

		assert.equal( receipt.schema, RUN_SCHEMA );
		assert.equal( receipt.sections.length, 1 );
		assert.equal( receipt.sections[ 0 ].adapter, 'canvas' );
		assert.equal( receipt.sections[ 0 ].host, '0' );
		assert.equal( receipt.sections[ 0 ].items, 3 );
		assert.equal( receipt.sections[ 0 ].adapters_tried.length, 1 );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].adapter, 'canvas' );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].result, 'passed' );
		assert.deepEqual( receipt.sections[ 0 ].adapters_tried[ 0 ].failures, [] );
	} );
} );

test( 'falls back to the second adapter when the first fails the render gate', async () => {
	const section = model( '0', [ '0.0', '0.1', '0.2' ] );
	const state = {
		calls: [],
		unavailableAdapters: [],
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: {
			0: {
				canvas: {
					// Clean at the reference width, but squeezed into an overlap at 390 -
					// a projection defect the reference layout didn't have.
					1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
					390: [ item( '0.0', 0, 0 ), item( '0.1', 10, 5 ), item( '0.2', 0, 100 ) ],
				},
				'core-grid': {
					1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
					390: [ item( '0.0', 0, 0 ), item( '0.1', 0, 50 ), item( '0.2', 0, 100 ) ],
				},
			},
		},
	};

	await withFixtureServer( state, async origin => {
		const receipt = await projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440, 390 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ section ] ),
			measureSection: makeMeasureSection( origin ),
		} );

		assert.equal( receipt.sections[ 0 ].adapter, 'core-grid' );
		assert.equal( receipt.sections[ 0 ].adapters_tried.length, 2 );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].adapter, 'canvas' );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].result, 'failed_gate' );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].failures[ 0 ].width, 390 );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 0 ].failures[ 0 ].kind, 'overlap' );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 1 ].adapter, 'core-grid' );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 1 ].result, 'passed' );
	} );
} );

test( 'falls back to none when every adapter fails the render gate', async () => {
	const section = model( '0', [ '0.0', '0.1', '0.2' ] );
	const overlapping = {
		1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
		390: [ item( '0.0', 0, 0 ), item( '0.1', 10, 5 ), item( '0.2', 0, 100 ) ],
	};
	const state = {
		calls: [],
		unavailableAdapters: [],
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: { 0: { canvas: overlapping, 'core-grid': overlapping } },
	};

	await withFixtureServer( state, async origin => {
		const receipt = await projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440, 390 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ section ] ),
			measureSection: makeMeasureSection( origin ),
		} );

		assert.equal( receipt.sections[ 0 ].adapter, 'none' );
		assert.equal( receipt.sections[ 0 ].adapters_tried.length, 2 );
		assert.ok( receipt.sections[ 0 ].adapters_tried.every( row => 'failed_gate' === row.result ) );
		// The final applied plan really did fall back to none for this section.
		assert.equal( state.appliedAdapterByHost[ '0' ], 'none' );
	} );
} );

test( 'reports an adapter with missing dependencies as adapter_unavailable and keeps trying', async () => {
	const section = model( '0', [ '0.0', '0.1', '0.2' ] );
	const state = {
		calls: [],
		unavailableAdapters: [ 'canvas' ],
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: {
			0: {
				'core-grid': {
					1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
					390: [ item( '0.0', 0, 0 ), item( '0.1', 0, 50 ), item( '0.2', 0, 100 ) ],
				},
			},
		},
	};

	await withFixtureServer( state, async origin => {
		const receipt = await projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440, 390 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ section ] ),
			measureSection: makeMeasureSection( origin ),
		} );

		assert.equal( receipt.sections[ 0 ].adapter, 'core-grid' );
		assert.deepEqual( receipt.sections[ 0 ].adapters_tried[ 0 ], { adapter: 'canvas', result: 'adapter_unavailable' } );
		assert.equal( receipt.sections[ 0 ].adapters_tried[ 1 ].result, 'passed' );
	} );
} );

test( 'is idempotent: running twice on the same page produces the same final content', async () => {
	const sectionA = model( '0', [ '0.0', '0.1', '0.2' ] );
	const sectionB = model( '1', [ '1.0', '1.1' ] );
	const state = {
		calls: [],
		unavailableAdapters: [ 'canvas' ], // canvas is never available; core-grid decides each section
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: {
			0: {
				'core-grid': {
					1440: [ item( '0.0', 0, 0 ), item( '0.1', 110, 0 ), item( '0.2', 220, 0 ) ],
					390: [ item( '0.0', 0, 0 ), item( '0.1', 0, 50 ), item( '0.2', 0, 100 ) ],
				},
			},
			1: {
				'core-grid': {
					// Clean at the reference width, overlapping at 390 - core-grid fails
					// section B too, so it deterministically falls back to none.
					1440: [ item( '1.0', 0, 0 ), item( '1.1', 110, 0 ) ],
					390: [ item( '1.0', 0, 0 ), item( '1.1', 10, 5 ) ],
				},
			},
		},
	};

	await withFixtureServer( state, async origin => {
		const run = () => projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440, 390 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ sectionA, sectionB ] ),
			measureSection: makeMeasureSection( origin ),
		} );

		const first = await run();
		const second = await run();
		assert.deepEqual( first, second );
		assert.equal( first.sections[ 0 ].adapter, 'core-grid' );
		assert.equal( first.sections[ 1 ].adapter, 'none' );
	} );
} );

test( 'the run receipt reports host, item count, adapters tried, chosen adapter, and skipped sections', async () => {
	const section = model( '0', [ '0.0', '0.1' ] );
	const skipped = [ { path: '0.1.0', reason: 'section_without_items' } ];
	const state = {
		calls: [],
		unavailableAdapters: [ 'canvas', 'core-grid' ],
		hadSnapshot: false,
		appliedAdapterByHost: {},
		geometry: {},
	};

	await withFixtureServer( state, async origin => {
		const receipt = await projectImportedLayout( {
			pageId: PAGE_ID,
			wpCommand: 'wp',
			adapters: [ 'canvas', 'core-grid' ],
			widths: [ 1440 ],
			runWpCli: makeFakeRunWpCli( state ),
			captureSections: makeCaptureSections( [ section ], skipped ),
			measureSection: makeMeasureSection( origin ),
		} );

		assert.deepEqual( receipt, {
			schema: RUN_SCHEMA,
			page: PAGE_ID,
			sections: [
				{
					host: '0',
					items: 2,
					adapters_tried: [
						{ adapter: 'canvas', result: 'adapter_unavailable' },
						{ adapter: 'core-grid', result: 'adapter_unavailable' },
					],
					adapter: 'none',
				},
			],
			skipped,
			final: receipt.final,
		} );
		assert.equal( receipt.final.applied, true );
		assert.equal( receipt.final.adapter, 'none' );
	} );
} );

test( 'buildArgv splits a multi-word --wp command prefix', () => {
	assert.deepEqual( buildArgv( 'studio wp', [ 'static-site-importer', 'project-layout' ] ), [ 'studio', 'wp', 'static-site-importer', 'project-layout' ] );
	assert.deepEqual( buildArgv( 'wp --path=/var/www/site', [ 'import' ] ), [ 'wp', '--path=/var/www/site', 'import' ] );
} );

test( 'interpretProjectLayoutRun distinguishes unavailable adapters, missing receipts, and success', () => {
	assert.deepEqual( interpretProjectLayoutRun( { status: 1, stdout: '', stderr: 'Error: The canvas layout adapter requires unregistered block types: tabor/canvas.\n' } ), {
		ok: false,
		adapterUnavailable: true,
		stdout: '',
		stderr: 'Error: The canvas layout adapter requires unregistered block types: tabor/canvas.\n',
		status: 1,
	} );
	assert.deepEqual( interpretProjectLayoutRun( { status: 1, stdout: 'Warning: noise\n', stderr: 'boom' } ), {
		ok: false,
		error: 'wp_cli_no_receipt',
		stdout: 'Warning: noise\n',
		stderr: 'boom',
		status: 1,
	} );
	const receipt = { applied: true };
	assert.deepEqual( interpretProjectLayoutRun( { status: 0, stdout: `notice\n${ JSON.stringify( receipt ) }\n`, stderr: '' } ), {
		ok: true,
		receipt,
		stdout: `notice\n${ JSON.stringify( receipt ) }\n`,
		stderr: '',
		status: 0,
	} );
} );

test( 'readLayoutProjectionInstructions reads a requested layout_projection block from an import receipt', () => {
	const path = `${ process.env.TMPDIR || '/tmp' }/ssi-import-receipt-${ process.pid }.json`;
	fs.writeFileSync( path, JSON.stringify( {
		schema: 'static-site-importer/import-cli-receipt/v1',
		status: 'completed',
		layout_projection: { status: 'requested', adapters: [ 'canvas', 'core-grid' ], pages: [ 12, 13 ] },
	} ) );
	try {
		assert.deepEqual( readLayoutProjectionInstructions( path ), { adapters: [ 'canvas', 'core-grid' ], pages: [ 12, 13 ] } );
	} finally {
		fs.rmSync( path, { force: true } );
	}
} );

test( 'readLayoutProjectionInstructions returns null when no projection was requested', () => {
	const path = `${ process.env.TMPDIR || '/tmp' }/ssi-import-receipt-none-${ process.pid }.json`;
	fs.writeFileSync( path, JSON.stringify( { schema: 'static-site-importer/import-cli-receipt/v1', status: 'completed' } ) );
	try {
		assert.equal( readLayoutProjectionInstructions( path ), null );
	} finally {
		fs.rmSync( path, { force: true } );
	}
} );

test( 'capture widths land inside the site\'s own desktop, tablet and mobile ranges', () => {
	assert.deepEqual( captureWidthsForViewport( null ), [ 1440, 631, 390 ] );
	assert.deepEqual( captureWidthsForViewport( { tablet: '959px' } ), [ 1440, 720, 390 ] );
	assert.deepEqual( captureWidthsForViewport( { tablet: '768px', mobile: '600px' } ), [ 1440, 684, 390 ] );
	assert.deepEqual( captureWidthsForViewport( { tablet: '900px', mobile: '360px' } ), [ 1440, 630, 360 ] );
} );

test( 'readSiteViewport reads the active theme viewport through WP-CLI', () => {
	const calls = [];
	const runner = argv => {
		calls.push( argv );
		return { status: 0, stdout: 'Notice\n{"tablet":"959px"}\n', stderr: '' };
	};
	assert.deepEqual( readSiteViewport( runner, 'studio wp' ), { tablet: '959px' } );
	assert.deepEqual( calls[ 0 ].slice( 0, 3 ), [ 'studio', 'wp', 'eval' ] );
	assert.equal( readSiteViewport( () => ( { status: 0, stdout: '[]', stderr: '' } ), 'wp' ), null );
	assert.equal( readSiteViewport( () => ( { status: 1, stdout: '', stderr: 'boom' } ), 'wp' ), null );
} );
