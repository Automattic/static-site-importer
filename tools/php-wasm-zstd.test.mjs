import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve( import.meta.dirname, '..' );
const releaseBase = 'https://github.com/Automattic/static-site-importer/releases/latest/download';
const manifestUrl = `${ releaseBase }/static-site-importer-zstd-php8.5-jspi.manifest.json`;

test( 'PHP.wasm zstd build uses pinned JSPI-compatible upstream sources', async () => {
	const build = await readFile( path.join( root, 'tools/build-php-wasm-zstd.sh' ), 'utf8' );
	assert.match( build, /EXT_ZSTD_REF='0bf5825ad683e637211a0eacec4fe545992f5b67'/ );
	assert.match( build, /LIBZSTD_REF='63779c798237346c2b245c546c40b72a5a5913fe'/ );
	assert.match( build, /PHP_WASM_COMPILE_EXTENSION_VERSION='3\.1\.45'/ );
	assert.match( build, /@php-wasm\/compile-extension@\$PHP_WASM_COMPILE_EXTENSION_VERSION/ );
	assert.match( build, /--php-versions 8\.5/ );
	assert.match( build, /--extra-cflags '-U__x86_64__'/ );
	assert.doesNotMatch( build, /pecl|zstd -d|\/usr\/bin\/zstd/i );
} );

test( 'manifest publishes a PHP 8.5 JSPI zstd artifact through the release contract', async () => {
	const manifest = JSON.parse( await readFile( path.join( root, 'docs/playground/extensions/zstd-php8.5-jspi.manifest.json' ), 'utf8' ) );
	assert.equal( manifest.name, 'zstd' );
	assert.equal( manifest.mode, 'php-extension' );
	assert.deepEqual( manifest.artifacts, [ {
		phpVersion: '8.5',
		sourcePath: `${ releaseBase }/static-site-importer-zstd-php8.5-jspi.so`,
	} ] );
} );

test( 'Playground starts PHP 8.5 and both README launch links load zstd before boot', async () => {
	const [ blueprint, readme ] = await Promise.all( [
		readFile( path.join( root, 'docs/playground/blueprint.json' ), 'utf8' ),
		readFile( path.join( root, 'README.md' ), 'utf8' ),
	] );
	assert.equal( JSON.parse( blueprint ).preferredVersions.php, '8.5' );
	const links = [ ...readme.matchAll( /\]\((https:\/\/playground\.wordpress\.net\/\?[^)]+)\)/g ) ].map( ( match ) => match[ 1 ] );
	assert.equal( links.length, 2 );
	for ( const link of links ) {
		const url = new URL( link );
		assert.equal( url.searchParams.get( 'php' ), '8.5' );
		assert.equal( url.searchParams.get( 'php-extension' ), manifestUrl );
		assert.equal( url.searchParams.get( 'blueprint-url' ), 'https://raw.githubusercontent.com/Automattic/static-site-importer/main/docs/playground/blueprint.json' );
	}
} );
