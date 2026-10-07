import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

// All writes are Codebox disposable-runtime writes or evidence in an explicit directory.
const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const baseline = process.argv.includes( '--baseline' );
const releasedAcceptance = process.argv.includes( '--released' );
if ( baseline && releasedAcceptance ) throw new Error( 'Choose --baseline or --released.' );
const sourceOverride = ! baseline && ! releasedAcceptance;
if ( process.versions.node !== '26.10.0' ) throw new Error( 'Run with Node26.10.0.' );
const cli = process.env.WP_CODEBOX_CLI;
const released = process.env.SSI_RELEASED_ROOT;
const compiler = process.env.SSI_SHARED_CHROME_COMPILER_ROOT;
const parent = process.env.SSI_SHARED_CHROME_EVIDENCE_ROOT;
if ( ! cli || ! released || ! parent || ( sourceOverride && ! compiler ) ) {
	throw new Error( 'Set WP_CODEBOX_CLI, SSI_RELEASED_ROOT, SSI_SHARED_CHROME_EVIDENCE_ROOT and (for source-override mode) SSI_SHARED_CHROME_COMPILER_ROOT.' );
}
for ( const path of [ cli, join( released, 'vendor/autoload.php' ), parent, ...( sourceOverride ? [ join( compiler, 'src/ArtifactCompiler/ArtifactCompiler.php' ) ] : [] ) ] ) {
	if ( ! existsSync( path ) ) throw new Error( `Missing required input: ${ path }` );
}
cases: for ( const sourceRoot of [ '', 'website/' ] ) for ( const ingress of [ 'artifact', 'files', 'zip' ] ) {
const evidence = mkdtempSync( join( resolve( parent ), 'shared-chrome-' ) );
const config = JSON.parse( readFileSync( join( root, 'tests/acceptance/shared-html-chrome-codebox.json' ), 'utf8' ) );
config.blueprint.steps[ 0 ].consts.SSI_SHARED_CHROME_CANDIDATE = sourceOverride;
config.blueprint.steps[ 0 ].consts.SSI_SHARED_CHROME_RELEASED = releasedAcceptance;
config.blueprint.steps[ 0 ].consts.SSI_SHARED_CHROME_COMPILER_VERSION = JSON.parse( readFileSync( join( root, 'composer.json' ), 'utf8' ) ).require[ 'automattic/blocks-engine-php-transformer' ];
config.blueprint.steps[ 0 ].consts.SSI_SHARED_CHROME_ROOT = sourceRoot;
config.blueprint.steps[ 0 ].consts.SSI_SHARED_CHROME_INGRESS = ingress;
config.mounts = [
	{ source: root, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' },
	{ source: join( resolve( released ), 'vendor' ), target: '/wordpress/wp-content/plugins/static-site-importer/vendor', mode: 'readonly' },
];
if ( sourceOverride ) config.mounts.push( { source: resolve( compiler ), target: '/wordpress/wp-content/plugins/owning-compiler', mode: 'readonly' } );
config.steps = [ { command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/shared-html-chrome-wordpress.php' ) }` ] } ];
const input = join( evidence, 'workload.json' );
writeFileSync( input, JSON.stringify( config, null, 2 ) );
const run = spawnSync( process.execPath, [ cli, 'run-wordpress-workload', '--input-file', input, '--artifacts', join( evidence, 'artifacts' ), '--format=json' ], { cwd: evidence, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 600_000 } );
writeFileSync( join( evidence, 'stdout.json' ), run.stdout ?? '' );
writeFileSync( join( evidence, 'stderr.txt' ), run.stderr ?? '' );
if ( run.error ) throw run.error;
const result = JSON.parse( run.stdout );
console.log( JSON.stringify( { mode: baseline ? 'baseline-adapter-only' : releasedAcceptance ? 'released-acceptance' : 'candidate-acceptance', evidence, success: result.success, commands: result.result?.commands, failure: result.result?.failure_summary ?? result.error }, null, 2 ) );
const oracle = baseline ? 'baseline-adapter-passed' : 'shared-chrome-acceptance-passed';
const observed = ( result.result?.commands ?? [] ).some( command => command.stdout_tail?.includes( `"status":"${ oracle }"` ) );
if ( run.status !== 0 || result.success !== true || ! observed ) {
  process.exitCode = 1;
  break cases;
}
}
