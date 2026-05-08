<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Import static HTML sites into WordPress pages or block themes using Block Format Bridge.
 * Version: 0.4.0
 * Author: Chris Huber
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php' ) ) {
	require_once STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php';
}

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-admin.php';

if ( defined( 'WP_CLI' ) ) {
	require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-cli-command.php';
}

add_action(
	'plugins_loaded',
	static function (): void {
		Static_Site_Importer_Admin::register();

		if ( defined( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'static-site-importer', 'Static_Site_Importer_CLI_Command' );
		}
	}
);
