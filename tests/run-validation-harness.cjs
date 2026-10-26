#!/usr/bin/env node
/**
 * End-to-end validation harness for the wordpress-is-dead fixture.
 *
 * Imports the fixture into a disposable Studio site, runs the PHP fidelity smokes,
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
const keepSite = args.includes( '--keep-site' );
const ownsSite = ! process.env.STATIC_SITE_IMPORTER_SITE_PATH;
const sitePath = process.env.STATIC_SITE_IMPORTER_SITE_PATH ||
  fs.mkdtempSync( path.join( os.tmpdir(), 'static-site-importer-site-' ) );
const results = [];
let cleanedUp = false;
let fixtureTempDir;

// Also clean up if fixture preparation or another synchronous operation throws.
process.on( 'exit', () => {
  if ( ! cleanup() ) {
    process.exitCode = 1;
  }
} );
const wpCli = process.env.STATIC_SITE_IMPORTER_WP_CLI
  ? splitCommand( process.env.STATIC_SITE_IMPORTER_WP_CLI )
  : [ 'studio', 'wp', '--path', sitePath ];
const themeDir = path.resolve(
  args.find( ( arg ) => ! arg.startsWith( '--' ) ) ||
    process.env.STATIC_SITE_IMPORTER_THEME_DIR ||
    path.join( sitePath, 'wp-content/themes/wordpress-is-dead' )
);
const fixture = path.join( repoRoot, 'tests/fixtures/wordpress-is-dead/index.html' );
if ( ownsSite && ! runStep( {
  name: 'Create disposable Studio site',
  command: 'studio',
  args: [ 'site', 'create', '--path', sitePath, '--skip-browser' ],
} ) ) {
  finish( false, results );
}
const importFixture = skipImport ? fixture : prepareImportFixture( fixture );

const steps = [
  {
    name: 'Site identity smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-site-identity.php' ) ],
  },
  {
    name: 'Entity materializer registry smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-entity-materializer-registry.php' ) ],
  },
  {
    name: 'Provider presentation smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-provider-presentation.php' ) ],
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
    name: 'Product handoff contract smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-product-handoff-contract.php' ) ],
  },
  {
    name: 'Woo product finding materialization smoke',
    command: 'php',
    args: [ path.join( repoRoot, 'tests/smoke-product-materializer.php' ) ],
  },
  {
    name: 'Website artifact document metadata smoke',
    command: wpCli[ 0 ],
    args: [
      ...wpCli.slice( 1 ),
      `--require=${ path.join( repoRoot, 'static-site-importer.php' ) }`,
      '--skip-plugins=static-site-importer',
      'eval-file',
      path.join( repoRoot, 'tests/smoke-website-artifact-document-metadata.php' ),
    ],
  },
  {
    name: 'Static interactive artifact smoke',
    command: wpCli[ 0 ],
    args: [
      ...wpCli.slice( 1 ),
      `--require=${ path.join( repoRoot, 'static-site-importer.php' ) }`,
      '--skip-plugins=static-site-importer',
      'eval-file',
      path.join( repoRoot, 'tests/smoke-static-interactive-artifact.php' ),
    ],
  },
  ! skipImport && {
    name: 'Import wordpress-is-dead fixture theme',
    command: wpCli[ 0 ],
    args: [
      ...wpCli.slice( 1 ),
      `--require=${ path.join( repoRoot, 'static-site-importer.php' ) }`,
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

for ( const step of steps ) {
  if ( ! runStep( step ) ) {
    finish( false, results );
  }
}

finish( true, results );

function runStep( step ) {
  if ( ! jsonMode ) {
    console.log( `\n==> ${ step.name }` );
  }

  const startedAt = Date.now();
  const result = spawnSync( step.command, step.args, {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: jsonMode ? 'pipe' : 'inherit',
    // Standalone PHP smokes load WordPress core from the target site.
    env: { ...process.env, STATIC_SITE_IMPORTER_WP_ROOT: process.env.STATIC_SITE_IMPORTER_WP_ROOT || sitePath },
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
    return false;
  }

  return true;
}

function cleanup() {
  if ( cleanedUp ) {
    return true;
  }
  cleanedUp = true;

  if ( fixtureTempDir ) {
    fs.rmSync( fixtureTempDir, { recursive: true, force: true } );
  }
  if ( ! ownsSite ) {
    return true;
  }
  if ( keepSite ) {
    console.error( `Kept Studio site: ${ sitePath }` );
    return true;
  }

  const deleted = runStep( {
    name: 'Delete disposable Studio site',
    command: 'studio',
    args: [ 'site', 'delete', '--path', sitePath, '--no-files' ],
  } );
  fs.rmSync( sitePath, { recursive: true, force: true } );
  if ( ! deleted ) {
    console.error( `Studio site cleanup failed for: ${ sitePath }` );
  }
  return deleted;
}

function finish( ok, stepResults ) {
  ok = cleanup() && ok;
  if ( jsonMode ) {
    console.log( JSON.stringify( { ok, sitePath, themeDir, steps: stepResults }, null, 2 ) );
  } else if ( ok ) {
    console.log( `\nOK: full validation harness passed (${ stepResults.length } steps)` );
  } else {
    const failed = stepResults.find( ( step ) => step.status !== 0 );
    console.error( `\nFAIL: ${ failed.name } exited with status ${ failed.status }` );
  }

  process.exit( ok ? 0 : 1 );
}

function splitCommand( command ) {
  return command.trim().split( /\s+/ ).filter( Boolean );
}

function prepareImportFixture( entryFile ) {
  const sourceDir = path.dirname( entryFile );
  fixtureTempDir = fs.mkdtempSync( path.join( os.tmpdir(), 'static-site-importer-validation-' ) );
  const fixtureDir = path.join( fixtureTempDir, path.basename( sourceDir ) );

  fs.cpSync( sourceDir, fixtureDir, { recursive: true } );

  return path.join( fixtureDir, path.basename( entryFile ) );
}
