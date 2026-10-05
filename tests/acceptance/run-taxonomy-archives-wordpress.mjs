import { mkdirSync, mkdtempSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const engineRoot = resolve( process.env.BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT ?? join( root, '../blocks-engine-2468/php-transformer' ) );
const cli = process.env.WP_CODEBOX_CLI ?? '/home/chubes/.local/bin/wp-codebox';
const evidenceRoot = resolve( process.env.SSI_TAXONOMY_EVIDENCE ?? join( root, 'artifacts/taxonomy-archives' ) );
if ( ! statSync( join( engineRoot, 'src/WordPressSitePlan/TaxonomyProjection.php' ), { throwIfNoEntry: false } )?.isFile() ) {
	throw new Error( 'Set BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT to the paired producer candidate source checkout.' );
}
mkdirSync( evidenceRoot, { recursive: true } );
const sessionDir = mkdtempSync( join( tmpdir(), 'ssi-taxonomy-wordpress-' ) );
const workloadFile = join( sessionDir, 'workload.json' );
const workload = {
	schema: 'wp-codebox/wordpress-workload-run/v1',
	wordpress_version: process.env.SSI_TAXONOMY_WORDPRESS_VERSION ?? '6.6.2',
	blueprint: { steps: [ { step: 'defineWpConfigConsts', consts: { SSI_TAXONOMY_DISPOSABLE_TEST: true } } ] },
	mounts: [
		{ source: root, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' },
		{ source: engineRoot, target: '/wordpress/wp-content/plugins/blocks-engine-candidate', mode: 'readonly' },
	],
	steps: [
		{ command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/taxonomy-archives-wordpress.php' ) }` ] },
		{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/', 'wait-for=domcontentloaded', 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
	],
};
writeFileSync( workloadFile, JSON.stringify( workload, null, 2 ) );
writeFileSync( join( evidenceRoot, 'workload.json' ), JSON.stringify( workload, null, 2 ) );

const command = spawnSync( cli, [ 'run-wordpress-workload', '--input-file', workloadFile, '--artifacts', join( evidenceRoot, 'artifacts' ), '--format=json' ], {
	encoding: 'utf8',
	maxBuffer: 64 * 1024 * 1024,
	timeout: 20 * 60 * 1000,
} );
if ( command.error ) throw command.error;
let result;
try {
	result = JSON.parse( command.stdout );
} catch ( error ) {
	writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
	writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
	throw new Error( `WP Codebox returned non-JSON output: ${ error.message }` );
}
writeFileSync( join( evidenceRoot, 'workload.json' ), JSON.stringify( workload, null, 2 ) );
writeFileSync( join( evidenceRoot, 'result.json' ), JSON.stringify( result, null, 2 ) );
writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
const phpStep = ( result.executions ?? [] ).find( step => 'wordpress.run-php' === step.command );
const browserStep = ( result.executions ?? [] ).find( step => 'wordpress.browser-page-load' === step.command );
let browser;
try {
	browser = JSON.parse( browserStep?.stdout ?? '{}' );
} catch {
	browser = {};
}
const snapshots = [];
const visit = directory => {
	for ( const entry of readdirSync( directory, { withFileTypes: true } ) ) {
		const path = join( directory, entry.name );
		if ( entry.isDirectory() ) visit( path );
		else if ( 'snapshot.html' === entry.name && path.includes( '/files/browser/' ) ) snapshots.push( path );
	}
};
const artifactRoot = join( evidenceRoot, 'artifacts' );
if ( result.success === true && statSync( artifactRoot, { throwIfNoEntry: false } )?.isDirectory() ) visit( artifactRoot );
snapshots.sort( ( left, right ) => statSync( right ).mtimeMs - statSync( left ).mtimeMs );
const snapshotPath = snapshots[0] ?? '';
const snapshot = snapshotPath ? readFileSync( snapshotPath, 'utf8' ) : '';
const browserAssertions = {
	sourceArchiveHeading: snapshot.includes( '<h1 class="wp-block-heading">Personal</h1>' ),
	dynamicPostAppears: snapshot.includes( 'Added after import' ),
	sourceMemberOrderingPreserved: snapshot.indexOf( 'Story 12</a>' ) < snapshot.indexOf( 'Story 11</a>' ),
	sharedHeaderRetained: snapshot.includes( 'Shared source header' ),
	sharedFooterRetainedOnce: 1 === snapshot.split( 'Shared source footer' ).length - 1,
	nativePaginationRendered: snapshot.includes( 'Next Page' ),
	unrelatedPostExcluded: ! snapshot.includes( 'Outside the archive' ),
	requestedSourceRoute: new URL( browser.finalUrl ?? 'http://invalid/' ).pathname.replace( /\/$/, '' ) === '/writing/category/personal',
	noBrowserErrors: 0 === ( browser.summary?.errors ?? -1 ),
};
const success = result.success === true && command.status === 0 && phpStep?.exitCode === 0 && String( phpStep?.stdout ?? '' ).includes( 'Taxonomy archive WordPress store acceptance passed.' ) && browserStep?.exitCode === 0 && Object.values( browserAssertions ).every( Boolean );
writeFileSync( join( evidenceRoot, 'browser-assertions.json' ), JSON.stringify( { success: Object.values( browserAssertions ).every( Boolean ), snapshot: snapshotPath, assertions: browserAssertions }, null, 2 ) );
console.log( JSON.stringify( {
	success,
	wpCodebox: spawnSync( cli, [ 'version' ], { encoding: 'utf8' } ).stdout.trim(),
	evidenceRoot,
	workloadStatus: result.status ?? null,
	executions: ( result.executions ?? [] ).map( step => ( { command: step.command, exitCode: step.exitCode, stdout: step.stdout, stderr: step.stderr } ) ),
	browserAssertions,
	failure: result.result?.failure_summary ?? result.error?.message ?? null,
}, null, 2 ) );
if ( ! success ) process.exitCode = 1;
