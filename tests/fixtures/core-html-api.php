<?php
/** Load the real core tokenizer without booting a site or database. */
$ssi_wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
if ( ! $ssi_wp_root ) {
	$ssi_wp_root = getenv( 'HOMEBOY_WORDPRESS_CORE_ROOT' );
}
if ( ! $ssi_wp_root ) {
	$ssi_wp_root = dirname( __DIR__, 2 ) . '/vendor/wordpress-core';
	if ( ! is_readable( $ssi_wp_root . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {
		// Direct PHP and node --test runs need the same pinned fixture as CI.
		// Explicit runtime roots above remain authoritative and fail if invalid.
		// Browser suites can start several PHP fixtures concurrently.
		$ssi_fixture_lock = fopen( sys_get_temp_dir() . '/ssi-core-fixture-' . md5( $ssi_wp_root ) . '.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CLI bootstrap needs a cross-process provisioning lock.
		if ( false === $ssi_fixture_lock || ! flock( $ssi_fixture_lock, LOCK_EX ) ) {
			fwrite( STDERR, "FAIL: Cannot lock WordPress fixture provisioning.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone dependency bootstrap reports to CLI stderr.
			exit( 1 );
		}
		passthru( 'bash ' . escapeshellarg( dirname( __DIR__, 2 ) . '/tools/prepare-wordpress-core-fixture.sh' ), $ssi_fixture_status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- Standalone CLI tests provision their pinned dependency through the shared setup script.
		flock( $ssi_fixture_lock, LOCK_UN );
		fclose( $ssi_fixture_lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the standalone provisioning lock handle.
		unset( $ssi_fixture_lock );
		if ( 0 !== $ssi_fixture_status ) {
			exit( (int) $ssi_fixture_status );
		}
		unset( $ssi_fixture_status );
	}
}
$ssi_html_api = rtrim( (string) $ssi_wp_root, '/\\' ) . '/wp-includes/html-api/';
if ( ! is_readable( $ssi_html_api . 'class-wp-html-tag-processor.php' ) ) {
	fwrite( STDERR, "FAIL: Core HTML API unavailable; supply STATIC_SITE_IMPORTER_WP_ROOT, HOMEBOY_WORDPRESS_CORE_ROOT, or vendored WordPress core.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone dependency bootstrap reports to CLI stderr.
	exit( 1 );
}
require_once dirname( $ssi_html_api ) . '/class-wp-token-map.php';
foreach ( array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-doctype-info.php', 'class-wp-html-tag-processor.php' ) as $ssi_core_file ) {
	require_once $ssi_html_api . $ssi_core_file;
}
if ( ! method_exists( 'WP_HTML_Tag_Processor', 'get_full_comment_text' ) ) {
	fwrite( STDERR, "FAIL: Core HTML API requires WordPress 6.7 or newer.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone dependency bootstrap reports to CLI stderr.
	exit( 1 );
}
unset( $ssi_wp_root, $ssi_html_api, $ssi_core_candidate, $ssi_core_file );
