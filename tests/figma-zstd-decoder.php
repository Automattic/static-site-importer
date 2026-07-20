<?php
/**
 * Behavioral checks for the Figma zstd decoder registration.
 *
 * Run from the repository root:
 * php tests/figma-zstd-decoder.php native
 * php -n tests/figma-zstd-decoder.php command
 *
 * @package StaticSiteImporter
 */

if ( 2 !== $argc || ! in_array( $argv[1], array( 'native', 'command' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/figma-zstd-decoder.php <native|command>\n" );
	exit( 2 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$filters = array();

function add_filter( string $hook, callable $callback ): bool {
	global $filters;
	$filters[ $hook ][] = $callback;
	return true;
}

function apply_filters( string $hook, $value ) {
	global $filters;
	foreach ( $filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

if ( 'native' === $argv[1] ) {
	if ( ! function_exists( 'zstd_uncompress' ) ) {
		function zstd_uncompress( string $compressed ): string {
			return 'native:' . $compressed;
		}
	}

	putenv( 'STATIC_SITE_IMPORTER_FIGMA_ZSTD_COMMAND=/definitely-not-a-zstd-command' );
} else {
	$command = tempnam( sys_get_temp_dir(), 'ssi-zstd-command-' );
	if ( false === $command ) {
		fwrite( STDERR, "Could not create zstd command fixture.\n" );
		exit( 1 );
	}
	file_put_contents( $command, "#!/bin/sh\ncat\n" );
	chmod( $command, 0700 );
	putenv( 'STATIC_SITE_IMPORTER_FIGMA_ZSTD_COMMAND=' . $command );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';

Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
$decoder = apply_filters( 'blocks_engine_figma_transformer_zstd_decoder', null );

if ( 'native' === $argv[1] ) {
	if ( ! is_callable( $decoder ) || ( ! extension_loaded( 'zstd' ) && 'native:compressed' !== $decoder( 'compressed' ) ) ) {
		fwrite( STDERR, "Native zstd decoder was not preferred.\n" );
		exit( 1 );
	}
} else {
	try {
		$result = is_callable( $decoder ) ? $decoder( 'compressed', array() ) : null;
	} finally {
		unlink( $command );
	}
	if ( ! is_array( $result ) || 'compressed' !== ( $result['data'] ?? null ) ) {
		fwrite( STDERR, "Zstd command fallback did not decode the payload.\n" );
		exit( 1 );
	}
}
