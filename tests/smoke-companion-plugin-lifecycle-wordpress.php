<?php
/**
 * WordPress-runtime lifecycle proof for generated companion registrations.
 *
 * Run: wp eval-file tests/smoke-companion-plugin-lifecycle-wordpress.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
require_once $root . '/static-site-importer.php';

$slug    = 'lifecycle-' . substr( md5( uniqid( '', true ) ), 0, 8 );
$payload = array(
	'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => $slug,
	'blocks'    => array(
		array( 'name' => 'authored-input', 'block_json' => array( 'name' => 'blocks-engine/authored-input', 'title' => 'Authored Input' ), 'render' => '<input type="text">' ),
		array( 'name' => 'authored-select', 'block_json' => array( 'name' => 'blocks-engine/authored-select', 'title' => 'Authored Select' ), 'render' => '<select><option>One</option></select>' ),
	),
);
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$report     = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload );
$registry   = WP_Block_Type_Registry::get_instance();
$failures   = array();

foreach ( is_array( $descriptor ) ? $descriptor['block_names'] : array() as $name ) {
	$owner = $GLOBALS['static_site_importer_companion_block_owners'][ $name ] ?? array();
	if ( ! $registry->is_registered( $name ) || ! is_array( $owner ) || ( $descriptor['plugin_file'] ?? '' ) !== ( $owner['plugin_file'] ?? '' ) || ( $descriptor['revision'] ?? '' ) !== ( $owner['revision'] ?? '' ) ) {
		$failures[] = $name;
	}
}

if ( 'registered' !== ( $report['registration']['status'] ?? '' ) || ! empty( $failures ) ) {
	fwrite( STDERR, 'Generated companion lifecycle verification failed: ' . implode( ', ', $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "OK: WordPress companion lifecycle registry/owner verification passed\n" );
