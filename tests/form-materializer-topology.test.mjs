import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { JSDOM, VirtualConsole } from 'jsdom';

const require = createRequire( import.meta.url );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
	virtualConsole: new VirtualConsole(),
} );
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty( globalThis, 'navigator', { value: dom.window.navigator, configurable: true } );
globalThis.HTMLElement = dom.window.HTMLElement;
globalThis.Node = dom.window.Node;
globalThis.DOMParser = dom.window.DOMParser;
globalThis.MutationObserver = dom.window.MutationObserver;
globalThis.getComputedStyle = dom.window.getComputedStyle;
globalThis.requestAnimationFrame = ( callback ) => setTimeout( callback, 0 );
globalThis.cancelAnimationFrame = ( id ) => clearTimeout( id );

const { registerCoreBlocks } = require( '@wordpress/block-library' );
const { getBlockType, parse, serialize, validateBlock } = require( '@wordpress/blocks' );

registerCoreBlocks();

test( 'shared-row topology materializes through the PHP provider adapter', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.match( output, /PASS form-materializer-smoke\.php \(\d+ assertions\)/ );
} );

test( 'Jetpack runtime preparation reports the missing canonical forms loader', () => {
	const code = String.raw`
namespace Automattic\Jetpack {
}
namespace {
	define( 'ABSPATH', getcwd() . '/' );
	class WP_Error {
		public function __construct( public $code, public $message = '', public $data = null ) {}
	}
	class Jetpack {
		public static function is_module_active( $module ) { return true; }
		public static function activate_default_modules( $min, $max, $modules, $network, $reactivate ) {}
	}
	class WP_Block_Type_Registry {
		public static function get_instance() { return new self(); }
		public function is_registered( $name ) { return false; }
	}
	function update_option( $name, $value, $autoload = null ) {}
	require 'includes/class-static-site-importer-form-seeder.php';
	$result = Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime();
	if ( ! ( $result instanceof WP_Error ) ) {
		exit( 1 );
	}
	echo $result->code;
}`;
	const output = execFileSync( 'php', [ '-r', code ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.equal( output, 'static_site_importer_jetpack_forms_loader_missing' );
} );

test( 'provider-constrained topology emits nested fields and an editor-valid core submit', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php', '--emit-topology-markup' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );
	const { markup, depth_markup: depthMarkup, cara_markup: caraMarkup } = JSON.parse( output );
	const warnings = [];
	const originalWarn = console.warn;
	const originalError = console.error;
	console.warn = ( ...args ) => warnings.push( args.join( ' ' ) );
	console.error = ( ...args ) => warnings.push( args.join( ' ' ) );
	let blocks;
	try {
		blocks = parse( markup );
	} finally {
		console.warn = originalWarn;
		console.error = originalError;
	}

	assert.equal( blocks.length, 1 );
	assert.doesNotMatch( markup, /<!-- wp:group/ );
	assert.equal( ( markup.match( /"width":50/g ) || [] ).length, 2 );
	assert.match( markup, /<!-- wp:jetpack\/field-text [^>]+ -->\n<div><!-- wp:jetpack\/label [^>]+ \/-->\n<!-- wp:jetpack\/input [^>]+ \/--><\/div>\n<!-- \/wp:jetpack\/field-text -->/ );
	assert.match( markup, /<!-- wp:jetpack\/field-email [^>]+ -->\n<div><!-- wp:jetpack\/label [^>]+ \/-->\n<!-- wp:jetpack\/input [^>]+ \/--><\/div>\n<!-- \/wp:jetpack\/field-email -->/ );
	assert.match( markup, /<!-- wp:jetpack\/field-textarea [^>]+ -->\n<div><!-- wp:jetpack\/label [^>]+ \/-->\n<!-- wp:jetpack\/input [^>]*"type":"textarea"[^>]* \/--><\/div>\n<!-- \/wp:jetpack\/field-textarea -->\n<!-- wp:button / );
	assert.match( markup, /<button type="submit" class="wp-block-button__link wp-element-button">Send<\/button>/ );
	assert.doesNotMatch( depthMarkup, /<!-- wp:group/ );
	assert.match( depthMarkup, /wp:jetpack\/field-text/ );
	assert.deepEqual( warnings, [], 'core block parsing emitted no warnings' );
	const coreButtons = [];
	const collectCoreButtons = ( generatedBlocks ) => {
		for ( const block of generatedBlocks ) {
			if ( block.name === 'core/button' ) coreButtons.push( block );
			collectCoreButtons( block.innerBlocks );
		}
	};
	collectCoreButtons( blocks );
	assert.equal( coreButtons.length, 1 );
	assert.equal( validateBlock( coreButtons[ 0 ] )[ 0 ], true );
	assert.match( serialize( coreButtons ), /form-button-submit is-submit/ );
	const caraBlocks = parse( caraMarkup );
	assert.equal( caraBlocks[ 0 ].name, 'core/heading' );
	assert.equal( caraBlocks[ 1 ].name, 'core/paragraph' );
	assert.match( caraMarkup, /"required":true/ );
	assert.match( caraMarkup, /wsite-button/ );
	assert.equal( serialize( parse( serialize( caraBlocks ) ) ), serialize( caraBlocks ), 'complete fallback graft is Gutenberg byte-stable after canonical serialization' );
} );

test( 'Jetpack provider definitions are explicitly unavailable to this core-only validation', () => {
	assert.equal( getBlockType( 'jetpack/contact-form' ), undefined );
} );
