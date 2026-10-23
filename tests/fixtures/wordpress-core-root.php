<?php
/**
 * Locate or provision the pinned source-only WordPress fixture for CLI tests.
 *
 * @package StaticSiteImporter
 */

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
if ( ! $wp_root ) {
	$wp_root = getenv( 'HOMEBOY_WORDPRESS_CORE_ROOT' );
}
if ( ! $wp_root ) {
	$wp_root = dirname( __DIR__, 2 ) . '/vendor/wordpress-core';
	if ( ! is_readable( $wp_root . '/wp-includes/blocks.php' ) ) {
		passthru( 'bash ' . escapeshellarg( dirname( __DIR__, 2 ) . '/tools/prepare-wordpress-core-fixture.sh' ), $fixture_status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- CLI test provisions the pinned source-only fixture, never a database or site.
		if ( 0 !== $fixture_status ) {
			exit( 1 );
		}
	}
}
return rtrim( $wp_root, '/\\' );
