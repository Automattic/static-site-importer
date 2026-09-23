import assert from 'node:assert/strict';
import test from 'node:test';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { CAPTURE_VIEWPORTS, MODEL_SCHEMA, captureLayoutPlacement } from '../lib/layout-placement-capture.mjs';

const fixtureUrl = pathToFileURL( 'tests/fixtures/layout-placement-capture-fixture.html' ).href;

// Playwright is a devDependency installed by CI (`npm ci` plus
// `npx playwright install chromium`); the deterministic gate environment runs
// without installed npm packages, or with the `playwright` package present but
// no downloaded browser binary. Load it lazily and confirm the chromium
// executable actually exists on disk so the suite skips instead of failing
// module resolution or a browser launch, and still exercises the capture
// helper wherever the full browser toolchain is present.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};
const chromiumExecutableAvailable = chromium ? existsSync( chromium.executablePath() ) : false;
const skipWithoutPlaywright = chromiumExecutableAvailable
	? false
	: 'playwright chromium is not installed; run `npm install` and `npx playwright install chromium` to run this browser capture suite';

test( 'capture helper returns the placement model for the marked fixture section', { skip: skipWithoutPlaywright }, async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto( fixtureUrl );
		const model = await captureLayoutPlacement( page );

		assert.equal( model.schema, MODEL_SCHEMA );
		assert.equal( model.host.path, '0' );
		assert.deepEqual( model.items.map( item => item.path ), [ '0.0', '0.1', '0.2' ] );
		// JavaScript orders integer-like object keys ascending; the PHP model
		// normalizer is what pins the widest-first capture order.
		assert.deepEqual( Object.keys( model.host.viewports ), [ '390', '700', '1440' ] );

		assert.deepEqual( model.host.viewports[ '1440' ], { x: 120, y: 0, width: 1200, height: 420 } );
		assert.deepEqual( model.host.viewports[ '700' ], { x: 30, y: 0, width: 640, height: 300 } );
		assert.deepEqual( model.host.viewports[ '390' ], { x: 20, y: 0, width: 350, height: 200 } );

		assert.deepEqual( model.items[ 0 ].viewports[ '1440' ], { x: 120, y: 0, width: 300, height: 200 } );
		assert.deepEqual( model.items[ 1 ].viewports[ '1440' ], { x: 570, y: 0, width: 750, height: 420 } );
		assert.deepEqual( model.items[ 2 ].viewports[ '1440' ], { x: 120, y: 336, width: 150, height: 42 } );
		assert.deepEqual( model.items[ 2 ].viewports[ '700' ], { x: 30, y: 240, width: 150, height: 42 } );
		assert.deepEqual( model.items[ 2 ].viewports[ '390' ], { x: 0, y: 0, width: 0, height: 0 } );
	} finally {
		await browser.close();
	}
} );

test( 'captured model validates through the SSI placement model contract', { skip: skipWithoutPlaywright }, async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto( fixtureUrl );
		const model = await captureLayoutPlacement( page, CAPTURE_VIEWPORTS );
		const code = String.raw`
namespace {
	define( 'ABSPATH', getcwd() . '/' );
	require 'includes/class-static-site-importer-layout-placement-model.php';
	$result = Static_Site_Importer_Layout_Placement_Model::validate( json_decode( getenv( 'MODEL_JSON' ), true ) );
	echo count( $result['errors'] ) === 0 ? 'valid' : 'invalid';
}`;
		const output = execFileSync( 'php', [ '-r', code ], {
			cwd: process.cwd(),
			encoding: 'utf8',
			env: { ...process.env, MODEL_JSON: JSON.stringify( model ) },
		} );
		assert.equal( output.trim(), 'valid' );
	} finally {
		await browser.close();
	}
} );

test( 'capture helper rejects a page without a marked host section', { skip: skipWithoutPlaywright }, async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.setContent( '<main><div data-ssi-block-path="0">No nested markers here.</div></main>' );
		await assert.rejects( () => captureLayoutPlacement( page ), /No marked host section found/ );
	} finally {
		await browser.close();
	}
} );
