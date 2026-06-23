#!/usr/bin/env node
/**
 * End-to-end validation harness for the wordpress-is-dead fixture.
 *
 * Imports the fixture into the local Studio site, runs the PHP fidelity smokes,
 * then validates generated theme artifacts through Gutenberg's JS block parser.
 */

const path = require( 'node:path' );
const process = require( 'node:process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const { spawnSync } = require( 'node:child_process' );

const repoRoot = path.resolve( __dirname, '..' );
const args = process.argv.slice( 2 );
const skipImport = args.includes( '--skip-import' );
const jsonMode = args.includes( '--json' );
const sitePath = process.env.STATIC_SITE_IMPORTER_SITE_PATH || '/Users/chubes/Studio/intelligence-chubes4';
const wpCli = process.env.STATIC_SITE_IMPORTER_WP_CLI
  ? splitCommand( process.env.STATIC_SITE_IMPORTER_WP_CLI )
  : [ 'studio', 'wp', '--path', sitePath ];
const themeDir = path.resolve(
  args.find( ( arg ) => ! arg.startsWith( '--' ) ) ||
    process.env.STATIC_SITE_IMPORTER_THEME_DIR ||
    '/Users/chubes/Studio/intelligence-chubes4/wp-content/themes/wordpress-is-dead'
);
const fixture = path.join( repoRoot, 'tests/fixtures/wordpress-is-dead/index.html' );
const importFixture = skipImport ? fixture : prepareImportFixture( fixture );

const steps = [
  {
    name: 'Transformer adapter smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-transformer-adapter.php' ) ],
  },
  {
    name: 'Website artifact products manifest smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-website-artifact-products-manifest.php' ) ],
  },
  {
    name: 'Entity materializer registry smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-entity-materializer-registry.php' ) ],
  },
  {
    name: 'Website artifact document metadata smoke',
    command: wpCli[ 0 ],
    args: [
      ...wpCli.slice( 1 ),
      '--skip-plugins=static-site-importer',
      'eval-file',
      path.join( repoRoot, 'tests/smoke-website-artifact-document-metadata.php' ),
    ],
  },
  {
    name: 'Visual repair CSS smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-visual-repair-css.php' ) ],
  },
  {
    name: 'Export theme ability smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-export-theme-ability.php' ) ],
  },
  {
    name: 'URL import runtime smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-url-import-runtime.php' ) ],
  },
  {
    name: 'Codebox validation contract smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-codebox-validation-contract.php' ) ],
  },
  ! skipImport && {
    name: 'Import wordpress-is-dead fixture theme',
    command: wpCli[ 0 ],
    args: [
      ...wpCli.slice( 1 ),
      'static-site-importer',
      'import-theme',
      importFixture,
      '--slug=wordpress-is-dead',
      '--name=WordPress Is Dead',
      '--activate',
      '--overwrite',
    ],
  },
  {
    name: 'Generated theme JS block validation',
    command: process.execPath,
    args: [
      path.join( repoRoot, 'tests/smoke-generated-theme-block-validation.cjs' ),
      ...( jsonMode ? [ '--json' ] : [] ),
      themeDir,
    ],
  },
].filter( Boolean );

const results = [];

for ( const step of steps ) {
  if ( ! jsonMode ) {
    console.log( `\n==> ${ step.name }` );
  }

  const startedAt = Date.now();
  const result = spawnSync( step.command, step.args, {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: jsonMode ? 'pipe' : 'inherit',
    env: process.env,
  } );
  const stepResult = {
    name: step.name,
    command: [ step.command, ...step.args ],
    status: result.status,
    durationMs: Date.now() - startedAt,
  };

  if ( jsonMode ) {
    stepResult.stdout = result.stdout || '';
    stepResult.stderr = result.stderr || '';
  }

  results.push( stepResult );

  if ( result.error || result.status !== 0 ) {
    if ( result.error ) {
      stepResult.error = result.error.message;
    }
    finish( false, results );
  }
}

finish( true, results );

function finish( ok, stepResults ) {
  if ( jsonMode ) {
    console.log( JSON.stringify( { ok, themeDir, steps: stepResults }, null, 2 ) );
  } else if ( ok ) {
    console.log( `\nOK: full validation harness passed (${ stepResults.length } steps)` );
  } else {
    const failed = stepResults[ stepResults.length - 1 ];
    console.error( `\nFAIL: ${ failed.name } exited with status ${ failed.status }` );
  }

  process.exit( ok ? 0 : 1 );
}

function splitCommand( command ) {
  return command.trim().split( /\s+/ ).filter( Boolean );
}

function prepareImportFixture( entryFile ) {
  const sourceDir = path.dirname( entryFile );
  const tempDir = fs.mkdtempSync( path.join( os.tmpdir(), 'static-site-importer-validation-' ) );
  const fixtureDir = path.join( tempDir, path.basename( sourceDir ) );

  fs.cpSync( sourceDir, fixtureDir, { recursive: true } );

  return path.join( fixtureDir, path.basename( entryFile ) );
}
