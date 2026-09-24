import assert from 'node:assert/strict';
import test from 'node:test';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { CAPTURE_VIEWPORTS, MODEL_SCHEMA, SECTIONS_SCHEMA, captureLayoutPlacement, captureLayoutSections } from '../lib/layout-placement-capture.mjs';

const fixtureUrl = pathToFileURL( 'tests/fixtures/layout-placement-capture-fixture.html' ).href;
const sectionsFixtureUrl = pathToFileURL( 'tests/fixtures/layout-sections-capture-fixture.html' ).href;

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

test( 'captureLayoutSections descends single-child wrappers and skips a section without items', { skip: skipWithoutPlaywright }, async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto( sectionsFixtureUrl );
		const result = await captureLayoutSections( page, [ 1440 ] );

		assert.equal( result.schema, SECTIONS_SCHEMA );
		assert.equal( result.sections.length, 2 );
		assert.deepEqual( result.skipped, [ { path: '0.1.0', reason: 'section_without_items' } ] );

		const [ hero, footer ] = result.sections;

		// 0.0 -> 0.0.0 -> 0.0.0.0 is a chain of marked single-child wrappers;
		// the host is the element where descent finally finds 2+ items.
		assert.equal( hero.schema, MODEL_SCHEMA );
		assert.equal( hero.host.path, '0.0.0.0' );
		assert.deepEqual( hero.items.map( item => item.path ), [ '0.0.0.0.0', '0.0.0.0.1', '0.0.0.0.2' ] );
		assert.deepEqual( hero.host.viewports[ '1440' ], { x: 0, y: 0, width: 1200, height: 200 } );
		assert.deepEqual( hero.items[ 0 ].viewports[ '1440' ], { x: 0, y: 0, width: 100, height: 100 } );
		assert.deepEqual( hero.items[ 1 ].viewports[ '1440' ], { x: 100, y: 0, width: 100, height: 100 } );
		assert.deepEqual( hero.items[ 2 ].viewports[ '1440' ], { x: 200, y: 0, width: 100, height: 100 } );

		// 0.2 already has 2 marked direct children, so no descent is needed.
		assert.equal( footer.host.path, '0.2' );
		assert.deepEqual( footer.items.map( item => item.path ), [ '0.2.0', '0.2.1' ] );
		assert.deepEqual( footer.host.viewports[ '1440' ], { x: 0, y: 250, width: 1200, height: 80 } );
		assert.deepEqual( footer.items[ 0 ].viewports[ '1440' ], { x: 0, y: 250, width: 40, height: 80 } );
		assert.deepEqual( footer.items[ 1 ].viewports[ '1440' ], { x: 40, y: 250, width: 40, height: 80 } );
	} finally {
		await browser.close();
	}
} );

test( 'every captured section validates through the SSI placement model contract', { skip: skipWithoutPlaywright }, async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto( sectionsFixtureUrl );
		const result = await captureLayoutSections( page, CAPTURE_VIEWPORTS );
		const code = String.raw`
namespace {
	define( 'ABSPATH', getcwd() . '/' );
	require 'includes/class-static-site-importer-layout-placement-model.php';
	$models = json_decode( getenv( 'MODELS_JSON' ), true );
	foreach ( $models as $model ) {
		$result = Static_Site_Importer_Layout_Placement_Model::validate( $model );
		if ( count( $result['errors'] ) !== 0 ) {
			echo 'invalid';
			exit;
		}
	}
	echo 'valid';
}`;
		const output = execFileSync( 'php', [ '-r', code ], {
			cwd: process.cwd(),
			encoding: 'utf8',
			env: { ...process.env, MODELS_JSON: JSON.stringify( result.sections ) },
		} );
		assert.equal( output.trim(), 'valid' );
	} finally {
		await browser.close();
	}
} );
