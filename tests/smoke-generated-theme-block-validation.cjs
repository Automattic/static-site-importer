#!/usr/bin/env node
/**
 * Smoke test: generated static importer theme artifacts pass Gutenberg block validation.
 *
 * Run after importing the wordpress-is-dead fixture theme:
 * npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
 *
 * Or set STATIC_SITE_IMPORTER_THEME_DIR=/path/to/theme.
 */

const path = require( 'node:path' );
const process = require( 'node:process' );
const { reportResult, validateThemeDirectory } = require( '../lib/gutenberg-block-validation.cjs' );

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

const result = validateThemeDirectory( themeDir );
reportResult( result, jsonMode );
process.exit( result.ok ? 0 : 1 );
