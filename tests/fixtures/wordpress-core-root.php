<?php
/**
 * Locate or provision the pinned source-only WordPress fixture for CLI tests.
 *
 * @package StaticSiteImporter
 */

// A runner may inherit a site path from another host. Prefer usable explicit
// roots, but fall back to real, pinned Core sources when those paths are absent.
foreach ( array( 'STATIC_SITE_IMPORTER_WP_ROOT', 'HOMEBOY_WORDPRESS_CORE_ROOT' ) as $variable ) {
	$candidate = getenv( $variable );
	if ( ! $candidate ) {
		continue;
	}
	$candidate = rtrim( $candidate, '/\\' );
	if ( is_readable( $candidate . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) && is_readable( $candidate . '/wp-includes/blocks.php' ) ) {
		return $candidate;
	}
	fwrite( STDERR, 'WARNING: ' . $variable . ' is not a readable Core source tree; trying the next fixture source.' . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone fixture bootstrap reports to CLI stderr.
}

$wp_root = dirname( __DIR__, 2 ) . '/vendor/wordpress-core';
if ( ! is_readable( $wp_root . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) || ! is_readable( $wp_root . '/wp-includes/blocks.php' ) ) {
	// Browser suites can start several PHP fixtures concurrently.
	$lock = fopen( sys_get_temp_dir() . '/ssi-core-fixture-' . md5( $wp_root ) . '.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native file handle is required for the fixture provisioning lock.
	if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
		fwrite( STDERR, "FAIL: Cannot lock WordPress fixture provisioning.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone fixture bootstrap reports to CLI stderr.
		exit( 1 );
	}
	// The script honors an explicit root; omit unavailable host-specific roots
	// only for this child process so it can provision the pinned local fixture.
	passthru( 'env -u STATIC_SITE_IMPORTER_WP_ROOT -u HOMEBOY_WORDPRESS_CORE_ROOT bash ' . escapeshellarg( dirname( __DIR__, 2 ) . '/tools/prepare-wordpress-core-fixture.sh' ), $fixture_status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- CLI-only bootstrap invokes the pinned Core provisioning script.
	flock( $lock, LOCK_UN );
	fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native fixture provisioning lock handle.
	if ( 0 !== $fixture_status ) {
		exit( 1 );
	}
}
return $wp_root;
