<?php
/**
 * Behavioral checks for the Figma zstd decoder registration.
 *
 * Run from the repository root:
 * php tests/figma-zstd-decoder.php native
 * php -n tests/figma-zstd-decoder.php command
 * php -n tests/figma-zstd-decoder.php unavailable
 * php -n -d disable_functions=proc_open tests/figma-zstd-decoder.php disabled
 *
 * @package StaticSiteImporter
 */

if ( 2 !== $argc || ! in_array( $argv[1], array( 'native', 'command', 'unavailable', 'disabled' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/figma-zstd-decoder.php <native|command|unavailable|disabled>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
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

	putenv( 'STATIC_SITE_IMPORTER_FIGMA_ZSTD_COMMAND=/definitely-not-a-zstd-command' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- CLI fixture selects the decoder under test.
} elseif ( in_array( $argv[1], array( 'command', 'disabled' ), true ) ) {
	$command = tempnam( sys_get_temp_dir(), 'ssi-zstd-command-' );
	if ( false === $command ) {
		fwrite( STDERR, "Could not create zstd command fixture.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
		exit( 1 );
	}
	file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI fixture writes a local decoder stub.
		$command,
		'#!' . PHP_BINARY . "\n<?php\n"
		. '$input = stream_get_contents( STDIN );' . "\n"
		. '$probe = base64_decode( \'KLUv/QRYcQAAc3NpLXpzdGQtcHJvYmVUFxFH\', true );' . "\n"
		. 'fwrite( STDOUT, $input === $probe ? \'ssi-zstd-probe\' : $input );' . "\n"
	);
	chmod( $command, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- CLI fixture must be executable.
	putenv( 'STATIC_SITE_IMPORTER_FIGMA_ZSTD_COMMAND=' . $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- CLI fixture selects the decoder under test.
} else {
	$command = tempnam( sys_get_temp_dir(), 'ssi-not-zstd-command-' );
	if ( false === $command ) {
		fwrite( STDERR, "Could not create invalid zstd command fixture.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
		exit( 1 );
	}
	file_put_contents( $command, "#!/bin/sh\ncat\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI fixture writes a local decoder stub.
	chmod( $command, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- CLI fixture must be executable.
	putenv( 'STATIC_SITE_IMPORTER_FIGMA_ZSTD_COMMAND=' . $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- CLI fixture selects the decoder under test.
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';

Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
$decoder = apply_filters( 'blocks_engine_figma_transformer_zstd_decoder', null );

if ( in_array( $argv[1], array( 'unavailable', 'disabled' ), true ) ) {
	try {
		if ( Static_Site_Importer_Figma_Import::zstd_decoder_available() ) {
			fwrite( STDERR, "Unavailable zstd command was advertised as available.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
			exit( 1 );
		}
	} finally {
		unlink( $command ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the CLI fixture file.
	}
} elseif ( 'native' === $argv[1] ) {
	if ( ! is_callable( $decoder ) || ( ! extension_loaded( 'zstd' ) && 'native:compressed' !== $decoder( 'compressed' ) ) ) {
		fwrite( STDERR, "Native zstd decoder was not preferred.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
		exit( 1 );
	}
} else {
	try {
		if ( ! Static_Site_Importer_Figma_Import::zstd_decoder_available() ) {
			fwrite( STDERR, "Working zstd command was not advertised as available.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
			exit( 1 );
		}
		$result = is_callable( $decoder ) ? $decoder( 'compressed', array() ) : null;
	} finally {
		unlink( $command ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the CLI fixture file.
	}
	if ( ! is_array( $result ) || 'compressed' !== ( $result['data'] ?? null ) ) {
		fwrite( STDERR, "Zstd command fallback did not decode the payload.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI harness writes to STDERR.
		exit( 1 );
	}
}
