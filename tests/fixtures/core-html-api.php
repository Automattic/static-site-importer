<?php
/** Load the real core tokenizer without booting a site or database. */
$ssi_wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
if ( ! $ssi_wp_root ) {
	foreach ( array( '/vendor/johnpbloch/wordpress-core', '/vendor/wordpress-core' ) as $ssi_core_candidate ) {
		$ssi_wp_root = dirname( __DIR__, 2 ) . $ssi_core_candidate;
		if ( is_readable( $ssi_wp_root . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {
			break;
		}
	}
}
$ssi_html_api = rtrim( (string) $ssi_wp_root, '/\\' ) . '/wp-includes/html-api/';
if ( ! is_readable( $ssi_html_api . 'class-wp-html-tag-processor.php' ) ) {
	fwrite( STDERR, "FAIL: Core HTML API unavailable; supply STATIC_SITE_IMPORTER_WP_ROOT or vendored WordPress core.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone dependency bootstrap reports to CLI stderr.
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
