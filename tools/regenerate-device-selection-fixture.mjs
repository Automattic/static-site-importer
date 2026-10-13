#!/usr/bin/env node
/**
 * Regenerate tests/fixtures/device-selection/ from a capture producer's device-selection module.
 *
 * Usage: node tools/regenerate-device-selection-fixture.mjs <module> --producer <name@revision> [--runner <bin>]
 *
 * <module> is the producer side of the device-selection contract (docs/contracts/website-artifact-evidence.md):
 * it exports assembleDeviceDocuments( documents, selection ) and installDeviceSelection( html, assembly ).
 * --runner executes the generated entry (default: node); pass a TypeScript runner such as a checkout's
 * node_modules/.bin/tsx for a .ts module. The client-script policy smoke then proves the producer's scripts match
 * the policy template byte for byte.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { fileURLToPath, pathToFileURL } from 'node:url';

const usage = 'Usage: node tools/regenerate-device-selection-fixture.mjs <module> --producer <name@revision> [--runner <bin>]';
const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const fixtureDir = join( root, 'tests/fixtures/device-selection' );
const { values, positionals } = parseArgs( { allowPositionals: true, options: { producer: { type: 'string' }, runner: { type: 'string', default: process.execPath } } } );
const module = positionals[ 0 ] ? resolve( positionals[ 0 ] ) : '';

if ( ! module || ! values.producer ) throw new Error( usage );
if ( ! existsSync( module ) ) throw new Error( `Producer module not found: ${ module }` );

// A neutral two-document page: desktop is the default, a mobile user agent selects the mobile document.
const program = `
import { assembleDeviceDocuments, installDeviceSelection } from ${ JSON.stringify( pathToFileURL( module ).href ) };
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
const scratch = mkdtempSync( join( tmpdir(), 'ssi-device-selection-fixture-' ) );
try {
	const entry = join( scratch, 'generate.mjs' );
	writeFileSync( entry, program );
	const html = execFileSync( values.runner, [ entry ], { cwd: dirname( module ), encoding: 'utf8' } );
	writeFileSync( join( fixtureDir, 'index.html' ), html.endsWith( '\n' ) ? html : html + '\n' );
	writeFileSync( join( fixtureDir, 'source.json' ), JSON.stringify( {
		producer: values.producer,
		generator: 'tools/regenerate-device-selection-fixture.mjs',
	}, null, '\t' ) + '\n' );
	process.stdout.write( `Regenerated ${ fixtureDir } from ${ values.producer }\n` );
} finally {
	rmSync( scratch, { recursive: true, force: true } );
}
