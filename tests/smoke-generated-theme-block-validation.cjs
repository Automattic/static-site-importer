#!/usr/bin/env node
/**
 * Smoke test: generated static importer theme artifacts pass Gutenberg block validation.
 *
 * Run after importing the wordpress-is-dead fixture theme:
 * npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
 *
 * Or set STATIC_SITE_IMPORTER_THEME_DIR=/path/to/theme.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const process = require( 'node:process' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
  virtualConsole: new VirtualConsole(),
} );
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty( globalThis, 'navigator', {
  value: dom.window.navigator,
  configurable: true,
} );
globalThis.HTMLElement = dom.window.HTMLElement;
globalThis.Node = dom.window.Node;
globalThis.DOMParser = dom.window.DOMParser;
globalThis.MutationObserver = dom.window.MutationObserver;
globalThis.getComputedStyle = dom.window.getComputedStyle;
globalThis.requestAnimationFrame = ( callback ) => setTimeout( callback, 0 );
globalThis.cancelAnimationFrame = ( id ) => clearTimeout( id );

const { registerCoreBlocks } = require( '@wordpress/block-library' );
const { parse, validateBlock } = require( '@wordpress/blocks' );

registerCoreBlocks();

const repoRoot = path.resolve( __dirname, '..' );
const args = process.argv.slice( 2 );
const jsonMode = args.includes( '--json' );
const pathArg = args.find( ( arg ) => ! arg.startsWith( '--' ) );
const defaultThemeDir = process.env.WP_CONTENT_DIR
  ? path.join( process.env.WP_CONTENT_DIR, 'themes', 'wordpress-is-dead' )
  : path.join( repoRoot, 'wordpress-is-dead' );
const themeDir = path.resolve(
  pathArg ||
    process.env.STATIC_SITE_IMPORTER_THEME_DIR ||
    defaultThemeDir
);

if ( ! fs.existsSync( themeDir ) ) {
  reportResult( {
    ok: false,
    themeDir,
    filesChecked: 0,
    blocksChecked: 0,
    invalidBlocks: 1,
    failures: [ {
      file: '(theme)',
      path: '(theme)',
      blockName: '(theme)',
      reasons: [
        `Theme directory does not exist: ${ themeDir }`,
        'Import the fixture first or pass a theme path as the first argument.',
      ],
    } ],
  } );
  process.exit( 1 );
}

const targetFiles = [
  ...listFiles( path.join( themeDir, 'parts' ), '.html' ).map( ( file ) => path.join( 'parts', file ) ),
  ...listFiles( path.join( themeDir, 'patterns' ), '.php' ).map( ( file ) => path.join( 'patterns', file ) ),
  ...listFiles( path.join( themeDir, 'templates' ), '.html' ).map( ( file ) => path.join( 'templates', file ) ),
];

const failures = [];
let blockCount = 0;

for ( const relativePath of targetFiles ) {
  const filePath = path.join( themeDir, relativePath );
  if ( ! fs.existsSync( filePath ) ) {
    failures.push( {
      file: relativePath,
      path: '(file)',
      blockName: '(file)',
      reasons: [ `Missing generated artifact: ${ relativePath }` ],
    } );
    continue;
  }

  const content = extractBlockContent( fs.readFileSync( filePath, 'utf8' ) );
  checkReferencedTemplateParts( content, relativePath );
  const blocks = withConsoleSilenced( () => parse( content ) );
  blockCount += countBlocks( blocks );
  validateBlocks( blocks, relativePath );
}

function checkReferencedTemplateParts( content, relativePath ) {
  for ( const match of content.matchAll( /<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/g ) ) {
    let attrs = {};
    try {
      attrs = JSON.parse( match[1] );
    } catch ( error ) {
      continue;
    }
    const slug = String( attrs.slug || '' ).replace( /[^a-z0-9_-]/gi, '' );
    if ( ! slug ) {
      continue;
    }
    const partPath = path.join( themeDir, 'parts', `${ slug }.html` );
    if ( ! fs.existsSync( partPath ) ) {
      failures.push( {
        file: relativePath,
        path: `parts/${ slug }.html`,
        blockName: 'core/template-part',
        reasons: [ `Referenced template part does not exist: parts/${ slug }.html` ],
      } );
    }
  }
}

if ( failures.length ) {
  reportResult( buildResult( false ) );
  process.exit( 1 );
}

reportResult( buildResult( true ) );

function listFiles( directory, extension ) {
  if ( ! fs.existsSync( directory ) ) {
    return [];
  }

  return fs
    .readdirSync( directory )
    .filter( ( file ) => file.endsWith( extension ) )
    .sort( ( left, right ) => left.localeCompare( right ) );
}

function extractBlockContent( content ) {
  const phpClose = content.indexOf( '?>' );
  return ( phpClose === -1 ? content : content.slice( phpClose + 2 ) ).trim();
}

function validateBlocks( blocks, file, trail = [] ) {
  blocks.forEach( ( block, index ) => {
    const blockPath = [ ...trail, `${ block.name || 'unknown' }[${ index }]` ];
    const [ isValid, validationIssues = [] ] = withConsoleSilenced( () => validateBlock( block ) );
    if ( ! isValid ) {
      failures.push( {
        file,
        path: blockPath.join( ' > ' ),
        blockName: block.name || 'unknown',
        reasons: normalizeIssues( validationIssues ),
      } );
    }

    validateBlocks( block.innerBlocks || [], file, blockPath );
  } );
}

function normalizeIssues( issues ) {
  if ( ! issues.length ) {
    return [ 'validateBlock() returned false without a reason.' ];
  }

  return issues.map( formatIssue );
}

function formatIssue( issue ) {
  if ( typeof issue === 'string' ) {
    return issue;
  }

  if ( Array.isArray( issue?.args ) ) {
    const [ template, ...args ] = issue.args;

    if ( String( template ).startsWith( 'Block validation failed for `%s`' ) ) {
      return `${ args[ 0 ] } save output does not match stored content; generated=${ truncate( args[ 2 ] ) }; stored=${ truncate( args[ 3 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected attributes %o' ) ) {
      return `Expected attributes ${ formatValue( args[ 0 ] ) }; saw ${ formatValue( args[ 1 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected token of type `%s`' ) ) {
      return `Expected ${ args[ 0 ] } ${ formatValue( args[ 1 ] ) }; saw ${ args[ 2 ] } ${ formatValue( args[ 3 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected end of content' ) ) {
      return `Expected end of content; saw ${ formatValue( args[ 0 ] ) }`;
    }

    return `${ template } ${ args.map( formatValue ).join( ' ' ) }`;
  }

  if ( issue?.message ) {
    return issue.message;
  }

  return truncate( JSON.stringify( issue ) );
}

function formatValue( value ) {
  if ( value?.type && value?.tagName ) {
    const attributes = ( value.attributes || [] )
      .map( ( [ name, attributeValue ] ) => `${ name }="${ attributeValue }"` )
      .join( ' ' );
    return `<${ value.tagName }${ attributes ? ` ${ attributes }` : '' }>`;
  }

  if ( Array.isArray( value ) ) {
    return value
      .map( ( item ) => ( Array.isArray( item ) ? `${ item[ 0 ] }="${ item[ 1 ] }"` : formatValue( item ) ) )
      .join( ', ' );
  }

  if ( 'string' === typeof value ) {
    return truncate( value );
  }

  return truncate( JSON.stringify( value ) );
}

function truncate( value, length = 180 ) {
  const stringValue = String( value ).replace( /\s+/g, ' ' ).trim();
  return stringValue.length > length ? `${ stringValue.slice( 0, length - 1 ) }…` : stringValue;
}

function countBlocks( blocks ) {
  return blocks.reduce( ( total, block ) => total + 1 + countBlocks( block.innerBlocks || [] ), 0 );
}

function buildResult( ok ) {
  return {
    ok,
    themeDir,
    filesChecked: targetFiles.length,
    blocksChecked: blockCount,
    invalidBlocks: failures.length,
    failures,
    byBlockName: summarizeFailures( 'blockName' ),
    byFile: summarizeFailures( 'file' ),
  };
}

function summarizeFailures( key ) {
  const summary = {};
  for ( const failure of failures ) {
    const value = failure[ key ] || 'unknown';
    summary[ value ] = ( summary[ value ] || 0 ) + 1;
  }
  return Object.fromEntries(
    Object.entries( summary ).sort( ( left, right ) => right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] ) )
  );
}

function reportResult( result ) {
  if ( jsonMode ) {
    console.log( JSON.stringify( result, null, 2 ) );
    return;
  }

  if ( result.ok ) {
    console.log( `OK: JS block validation smoke passed (${ result.blocksChecked } blocks across ${ result.filesChecked } files)` );
    return;
  }

  console.error( `Block validation failed: ${ result.invalidBlocks } invalid block(s) in ${ result.filesChecked } file(s).` );
  printSummary( 'By block', result.byBlockName );
  printSummary( 'By file', result.byFile );

  for ( const failure of result.failures ) {
    console.error( `FAIL ${ failure.file } ${ failure.path }` );
    for ( const reason of failure.reasons ) {
      console.error( `  - ${ reason }` );
    }
  }
}

function printSummary( label, summary ) {
  const entries = Object.entries( summary || {} );
  if ( ! entries.length ) {
    return;
  }

  console.error( `${ label }:` );
  for ( const [ name, count ] of entries ) {
    console.error( `  - ${ name }: ${ count }` );
  }
}

function withConsoleSilenced( callback ) {
  const methods = [ 'debug', 'error', 'group', 'groupCollapsed', 'groupEnd', 'info', 'log', 'warn' ];
  const originalConsole = new Map( methods.map( ( method ) => [ method, console[ method ] ] ) );
  const originalWindowConsole = window.console;
  const originalStderrWrite = process.stderr.write;
  const originalStdoutWrite = process.stdout.write;
  const silentConsole = { ...console };

  for ( const method of methods ) {
    console[ method ] = () => {};
    silentConsole[ method ] = () => {};
  }
  window.console = silentConsole;
  process.stderr.write = () => true;
  process.stdout.write = () => true;

  try {
    return callback();
  } finally {
    for ( const [ method, original ] of originalConsole ) {
      console[ method ] = original;
    }
    window.console = originalWindowConsole;
    process.stderr.write = originalStderrWrite;
    process.stdout.write = originalStdoutWrite;
  }
}
