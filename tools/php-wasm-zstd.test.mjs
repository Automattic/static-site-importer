import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve( import.meta.dirname, '..' );
const pagesBase = 'https://automattic.github.io/static-site-importer/playground/extensions';
const manifestUrl = `${ pagesBase }/latest/static-site-importer-zstd-php8.5-jspi.manifest.json`;

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

test( 'manifest publishes a PHP 8.5 JSPI zstd artifact through the CORS-capable Pages contract', async () => {
	const manifest = JSON.parse( await readFile( path.join( root, 'docs/playground/extensions/zstd-php8.5-jspi.manifest.json' ), 'utf8' ) );
	assert.equal( manifest.name, 'zstd' );
	assert.equal( manifest.mode, 'php-extension' );
	assert.deepEqual( manifest.artifacts, [ {
		phpVersion: '8.5',
		sourcePath: `${ pagesBase }/latest/static-site-importer-zstd-php8.5-jspi.so`,
	} ] );
} );

test( 'Playground starts PHP 8.5 and both README launch links boot without an optional extension', async () => {
	const [ blueprint, readme ] = await Promise.all( [
		readFile( path.join( root, 'docs/playground/blueprint.json' ), 'utf8' ),
		readFile( path.join( root, 'README.md' ), 'utf8' ),
	] );
	const parsedBlueprint = JSON.parse( blueprint );
	assert.equal( parsedBlueprint.preferredVersions.php, '8.5' );
	assert.ok( ! parsedBlueprint.steps.some( ( step ) => step.step === 'runPHP' && step.code.includes( "extension_loaded( 'zstd' )" ) ) );
	const links = [ ...readme.matchAll( /\]\((https:\/\/playground\.wordpress\.net\/\?[^)]+)\)/g ) ].map( ( match ) => match[ 1 ] );
	assert.equal( links.length, 2 );
	for ( const link of links ) {
		const url = new URL( link );
		assert.equal( url.searchParams.get( 'php' ), '8.5' );
		assert.equal( url.searchParams.get( 'php-extension' ), null );
		assert.equal( url.searchParams.get( 'blueprint-url' ), 'https://automattic.github.io/static-site-importer/playground/latest/blueprint.json' );
	}
} );

test( 'release publication keeps immutable assets and blueprints separate from the latest convenience paths', async () => {
	const [ workflow, publication ] = await Promise.all( [
		readFile( path.join( root, '.github/workflows/release-php-wasm-zstd.yml' ), 'utf8' ),
		readFile( path.join( root, 'docs/playground/publication-contract.md' ), 'utf8' ),
	] );
	assert.match( workflow, /workflow_dispatch:/ );
	assert.match( workflow, /RELEASE_TAG: \$\{\{ github\.event\.release\.tag_name \|\| inputs\.release_tag \}\}/ );
	assert.match( workflow, /PHP_WASM_ZSTD_ASSET_BASE_URL: https:\/\/automattic\.github\.io\/static-site-importer\/playground\/extensions\/\$\{\{ env\.RELEASE_TAG \}\}/ );
	assert.match( workflow, /playground\/extensions\/\$RELEASE_TAG/ );
	assert.match( workflow, /playground\/extensions\/latest/ );
	assert.match( workflow, /releases\/download\/\$RELEASE_TAG/ );
	assert.match( workflow, /access-control-allow-origin/ );
	assert.match( workflow, /playground\/\$RELEASE_TAG\/static-site-importer-playground-demo\.zip/ );
	assert.match( workflow, /DEMO_PACKAGE_SHA256/ );
	assert.match( workflow, /git -C "\$pages_dir" add \.nojekyll "playground\/\$RELEASE_TAG\.blueprint\.json" "playground\/\$RELEASE_TAG\/static-site-importer-playground-demo\.zip"/ );
	assert.match( workflow, /git -C "\$pages_dir" diff --cached --quiet/ );
	assert.doesNotMatch( workflow, /gh release upload/ );
	assert.ok(
		workflow.indexOf( 'Verify the immutable safe Playground launch' ) < workflow.indexOf( 'Advance the safe README blueprint alias' ),
		'the mutable README alias must advance only after immutable browser verification',
	);
	assert.ok(
		workflow.indexOf( 'Verify the exact README Playground launch' ) < workflow.indexOf( 'Verify the optional zstd Playground launch end to end' ),
		'the safe README launch must be independent of optional extension verification',
	);
	assert.ok(
		workflow.indexOf( 'Verify the optional zstd Playground launch end to end' ) < workflow.indexOf( 'Advance the verified extension aliases' ),
		'extension aliases must advance only after their browser verification',
	);
	assert.match( publication, /\{\{RELEASE_TAG\}\}/ );
	assert.match( publication, /\{\{DEMO_PACKAGE_SHA256\}\}/ );
	assert.match( publication, /Homeboy remains the sole\s+release owner/ );
} );
