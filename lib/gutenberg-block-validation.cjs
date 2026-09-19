/**
 * Gutenberg save-contract validation via @wordpress/blocks validateBlock.
 *
 * PHP parse_blocks/serialize_blocks cannot detect a registered save() mismatch.
 * This runtime re-runs save() and compares it to stored markup.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const process = require( 'node:process' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SCHEMA = 'static-site-importer/gutenberg-block-validation/v1';
const VALIDATION_METHOD = 'wp.blocks.validateBlock';
const VALIDATION_PROVIDER = '@wordpress/blocks';

let runtime = null;

function ensureRuntime() {
	if ( runtime ) {
		return runtime;
	}

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
	const {
		getBlockType,
		parse,
		registerBlockType,
		unregisterBlockType,
		validateBlock,
	} = require( '@wordpress/blocks' );

	registerCoreBlocks();

	runtime = {
		getBlockType,
		parse,
		registerBlockType,
		unregisterBlockType,
		validateBlock,
	};
	return runtime;
}

function registerValidationBlockType( name, settings ) {
	const { getBlockType, registerBlockType, unregisterBlockType } = ensureRuntime();
	if ( getBlockType( name ) ) {
		unregisterBlockType( name );
	}
	return registerBlockType( name, settings );
}

function extractBlockContent( content ) {
	const phpClose = content.indexOf( '?>' );
	return ( phpClose === -1 ? content : content.slice( phpClose + 2 ) ).trim();
}

function validateMarkup( markup, options = {} ) {
	const { parse } = ensureRuntime();
	const file = options.file || '(markup)';
	const content = extractBlockContent( String( markup || '' ) );
	const blocks = withConsoleSilenced( () => parse( content ) );
	const visited = visitBlocks( blocks, file );
	return buildDocumentResult( {
		file,
		ok: visited.failures.length === 0,
		blocksChecked: visited.blocksChecked,
		invalidBlocks: visited.failures.length,
		failures: visited.failures,
		results: visited.results,
	} );
}

function validateDocuments( documents ) {
	const files = Array.isArray( documents ) ? documents : [];
	const failures = [];
	const results = [];
	let blocksChecked = 0;
	for ( const document of files ) {
		const file = document.path || document.file || '(document)';
		const documentResult = validateMarkup( document.content || document.markup || '', { file } );
		blocksChecked += documentResult.blocksChecked;
		failures.push( ...documentResult.failures );
		results.push( ...documentResult.results );
	}
	return buildAggregateResult( {
		ok: failures.length === 0,
		filesChecked: files.length,
		blocksChecked,
		invalidBlocks: failures.length,
		failures,
		results,
	} );
}

function validateThemeDirectory( themeDir ) {
	const resolved = path.resolve( themeDir );
	if ( ! fs.existsSync( resolved ) ) {
		return buildAggregateResult( {
			ok: false,
			themeDir: resolved,
			filesChecked: 0,
			blocksChecked: 0,
			invalidBlocks: 1,
			failures: [ {
				file: '(theme)',
				path: '(theme)',
				blockName: '(theme)',
				reasons: [
					`Theme directory does not exist: ${ resolved }`,
					'Import the fixture first or pass a theme path as the first argument.',
				],
			} ],
			results: [],
		} );
	}

	const targetFiles = [
		...listFiles( path.join( resolved, 'parts' ), '.html' ).map( ( file ) => path.join( 'parts', file ) ),
		...listFiles( path.join( resolved, 'patterns' ), '.php' ).map( ( file ) => path.join( 'patterns', file ) ),
		...listFiles( path.join( resolved, 'templates' ), '.html' ).map( ( file ) => path.join( 'templates', file ) ),
	];

	const documents = [];
	const failures = [];
	for ( const relativePath of targetFiles ) {
		const filePath = path.join( resolved, relativePath );
		if ( ! fs.existsSync( filePath ) ) {
			failures.push( {
				file: relativePath,
				path: '(file)',
				blockName: '(file)',
				reasons: [ `Missing generated artifact: ${ relativePath }` ],
			} );
			continue;
		}
		const content = fs.readFileSync( filePath, 'utf8' );
		failures.push( ...referencedTemplatePartFailures( extractBlockContent( content ), relativePath, resolved ) );
		documents.push( { path: relativePath, content } );
	}

	const validated = validateDocuments( documents );
	const allFailures = [ ...failures, ...validated.failures ];
	return buildAggregateResult( {
		ok: allFailures.length === 0,
		themeDir: resolved,
		filesChecked: targetFiles.length,
		blocksChecked: validated.blocksChecked,
		invalidBlocks: allFailures.length,
		failures: allFailures,
		results: validated.results,
	} );
}

function visitBlocks( blocks, file, trail = [] ) {
	const { validateBlock } = ensureRuntime();
	const failures = [];
	const results = [];
	let blocksChecked = 0;

	const visit = ( items, pathTrail ) => {
		items.forEach( ( block, index ) => {
			const blockPath = [ ...pathTrail, `${ block.name || 'unknown' }[${ index }]` ];
			blocksChecked += 1;
			const [ isValid, validationIssues = [] ] = withConsoleSilenced( () => validateBlock( block ) );
			const issues = isValid ? [] : normalizeIssues( validationIssues );
			results.push( {
				name: block.name || 'unknown',
				isValid: Boolean( isValid ),
				issues,
				path: blockPath.join( ' > ' ),
			} );
			if ( ! isValid ) {
				failures.push( {
					file,
					path: blockPath.join( ' > ' ),
					blockName: block.name || 'unknown',
					reasons: issues,
				} );
			}
			visit( block.innerBlocks || [], blockPath );
		} );
	};

	visit( blocks, trail );
	return { failures, results, blocksChecked };
}

function referencedTemplatePartFailures( content, relativePath, themeDir ) {
	const failures = [];
	for ( const match of content.matchAll( /<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/g ) ) {
		let attrs = {};
		try {
			attrs = JSON.parse( match[ 1 ] );
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
	return failures;
}

function listFiles( directory, extension ) {
	if ( ! fs.existsSync( directory ) ) {
		return [];
	}

	return fs
		.readdirSync( directory )
		.filter( ( file ) => file.endsWith( extension ) )
		.sort( ( left, right ) => left.localeCompare( right ) );
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

function summarizeFailures( failures, key ) {
	const summary = {};
	for ( const failure of failures ) {
		const value = failure[ key ] || 'unknown';
		summary[ value ] = ( summary[ value ] || 0 ) + 1;
	}
	return Object.fromEntries(
		Object.entries( summary ).sort( ( left, right ) => right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] ) )
	);
}

function buildDocumentResult( result ) {
	return {
		schema: SCHEMA,
		validation_method: VALIDATION_METHOD,
		validation_provider: VALIDATION_PROVIDER,
		...result,
	};
}

function buildAggregateResult( result ) {
	return {
		schema: SCHEMA,
		validation_method: VALIDATION_METHOD,
		validation_provider: VALIDATION_PROVIDER,
		...result,
		byBlockName: summarizeFailures( result.failures || [], 'blockName' ),
		byFile: summarizeFailures( result.failures || [], 'file' ),
	};
}

function reportResult( result, jsonMode = false ) {
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

module.exports = {
	SCHEMA,
	VALIDATION_METHOD,
	VALIDATION_PROVIDER,
	ensureRuntime,
	extractBlockContent,
	registerValidationBlockType,
	reportResult,
	validateDocuments,
	validateMarkup,
	validateThemeDirectory,
};
