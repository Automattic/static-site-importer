import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const session = mkdtempSync( join( tmpdir(), 'ssi-author-users-' ) );
const workload = {
	schema: 'wp-codebox/wordpress-workload-run/v1',
	wordpress_version: '7.1',
	blueprint: { steps: [ { step: 'defineWpConfigConsts', consts: { SSI_AUTHOR_DISPOSABLE_TEST: true } } ] },
	mounts: [ { source: root, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' } ],
	steps: [ { command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/author-users-wordpress.php' ) }` ] } ],
};
const input = join( session, 'workload.json' );
writeFileSync( input, JSON.stringify( workload ) );
const command = spawnSync( process.env.WP_CODEBOX_CLI ?? '/home/chubes/.local/bin/wp-codebox', [ 'run-wordpress-workload', '--input-file', input, '--artifacts', join( session, 'artifacts' ), '--format=json' ], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 1200000 } );
if ( command.error ) throw command.error;
console.log( command.stdout );
console.error( command.stderr );
const result = JSON.parse( command.stdout );
const php = result.executions?.find( step => step.command === 'wordpress.run-php' );
if ( command.status !== 0 || ! result.success || php?.exitCode !== 0 || ! php.stdout.includes( 'Author users WordPress acceptance passed.' ) ) process.exitCode = 1;
