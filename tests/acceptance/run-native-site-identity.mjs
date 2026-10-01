import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const cli = process.env.WP_CODEBOX_CLI;
if ( ! cli ) throw new Error( 'Set WP_CODEBOX_CLI to the installed native WP Codebox CLI entrypoint.' );
const evidence = mkdtempSync( join( tmpdir(), 'ssi-native-identity-' ) );
const compiler = process.env.SSI_TEMPLATE_COMPILER_ROOT ? resolve( process.env.SSI_TEMPLATE_COMPILER_ROOT ) : null;
const mounts = [ { source: root, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' } ];
if ( compiler ) {
	mounts.push( { source: compiler, target: '/wordpress/wp-content/plugins/owning-compiler', mode: 'readonly' } );
	mounts.push( { source: join( compiler, 'vendor' ), target: '/wordpress/wp-content/plugins/owning-compiler/vendor', mode: 'readonly' } );
}
const input = join( evidence, 'workload.json' );
writeFileSync( input, JSON.stringify( {
	schema: 'wp-codebox/wordpress-workload-run/v1', wordpress_version: 'latest',
	blueprint: { steps: [ { step: 'defineWpConfigConsts', consts: { SSI_NATIVE_IDENTITY_DISPOSABLE_TEST: true, SSI_NATIVE_TEMPLATE_ORACLE: !! compiler } } ] },
	mounts,
	steps: [ { command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/native-site-identity-wordpress.php' ) }` ] } ],
} ) );
const run = spawnSync( process.execPath, [ cli, 'run-wordpress-workload', '--input-file', input, '--artifacts', join( evidence, 'artifacts' ), '--format=json' ], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 300_000 } );
if ( run.error ) throw run.error;
const result = JSON.parse( run.stdout );
writeFileSync( join( evidence, 'result.json' ), JSON.stringify( result, null, 2 ) );
console.log( JSON.stringify( { success: result.success, evidence, stdout: ( result.executions ?? [] ).map( step => step.stdout ), failure: result.result?.failure_summary ?? result.error?.message }, null, 2 ) );
if ( run.status !== 0 || result.success !== true ) process.exitCode = 1;
