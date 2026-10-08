#!/usr/bin/env node
/**
 * Regenerate tests/fixtures/dla-device-selection/ with Data Liberation Agent's real producer.
 *
 * Usage: node tools/regenerate-dla-device-selection-fixture.mjs <data-liberation-agent checkout>
 *
 * The checkout must be at the commit recorded in
 * Static_Site_Importer_Client_Script_Policy::DLA_DEVICE_SELECTION_SOURCE and have its dependencies installed
 * (`npm ci`). To move to a newer producer, update that constant and the policy's template pieces together,
 * check out the new commit, and run this script; the client-script policy smoke test then proves they agree.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const fixtureDir = join( root, 'tests/fixtures/dla-device-selection' );
const policy = readFileSync( join( root, 'includes/class-static-site-importer-client-script-policy.php' ), 'utf8' );
const commit = policy.match( /DLA_DEVICE_SELECTION_SOURCE = '([0-9a-f]{40})'/ )?.[ 1 ];
const dla = process.argv[ 2 ] ? resolve( process.argv[ 2 ] ) : '';

if ( ! commit ) throw new Error( 'DLA_DEVICE_SELECTION_SOURCE is missing from the client-script policy.' );
if ( ! dla ) throw new Error( 'Usage: node tools/regenerate-dla-device-selection-fixture.mjs <data-liberation-agent checkout>' );
const head = execFileSync( 'git', [ '-C', dla, 'rev-parse', 'HEAD' ], { encoding: 'utf8' } ).trim();
if ( head !== commit ) throw new Error( `Data Liberation Agent checkout is at ${ head }; check out ${ commit } first.` );
const tsx = join( dla, 'node_modules/.bin/tsx' );
if ( ! existsSync( tsx ) ) throw new Error( `Install Data Liberation Agent dependencies first (missing ${ tsx }).` );

// A neutral two-document page: desktop is the default, a mobile user agent selects the mobile document.
const program = `
import { assembleDeviceDocuments, installDeviceSelection } from ${ JSON.stringify( pathToFileURL( join( dla, 'src/lib/document-selection.ts' ) ).href ) };
const selection = {
	kind: 'device', id: 'fixture-platform', defaultDocument: 'desktop', documents: [ 'desktop', 'mobile' ], evidence: 'neutral fixture',
	rules: [ { userAgent: 'Mobile|Android|iPhone', flags: 'i', document: 'mobile' } ],
};
const assembly = assembleDeviceDocuments( {
	desktop: '<!DOCTYPE html><html lang="en" class="root-desktop"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>p{color:red}</style></head><body class="body-desktop" style="margin:0"><p>Desktop</p></body></html>',
	mobile: '<!DOCTYPE html><html lang="en" class="root-mobile"><head><meta charset="utf-8"><meta name="viewport" id="mobile-viewport" content="width=device-width, initial-scale=1, maximum-scale=1"><style>p{color:blue}</style></head><body class="body-mobile"><p>Mobile</p></body></html>',
}, selection );
process.stdout.write( installDeviceSelection( assembly.html, assembly ) );
`;
const scratch = mkdtempSync( join( tmpdir(), 'ssi-dla-fixture-' ) );
try {
	const entry = join( scratch, 'generate.mts' );
	writeFileSync( entry, program );
	const html = execFileSync( tsx, [ entry ], { cwd: dla, encoding: 'utf8' } );
	writeFileSync( join( fixtureDir, 'index.html' ), html.endsWith( '\n' ) ? html : html + '\n' );
	writeFileSync( join( fixtureDir, 'source.json' ), JSON.stringify( {
		producer: 'Automattic/data-liberation-agent src/lib/document-selection.ts installDeviceSelection()',
		commit,
		generator: 'tools/regenerate-dla-device-selection-fixture.mjs',
	}, null, '\t' ) + '\n' );
	process.stdout.write( `Regenerated ${ fixtureDir } from data-liberation-agent@${ commit }\n` );
} finally {
	rmSync( scratch, { recursive: true, force: true } );
}
