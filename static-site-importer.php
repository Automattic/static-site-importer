<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Materialize compiled website artifacts into WordPress block themes.
 * Version: 1.1.4
 * Author: Chris Huber
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: blocks-engine-php-transformer
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_VERSION', '1.1.4' );

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-import-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-plugin-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-asset-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document-metadata-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-page-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-stylesheet-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-product-handoff-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-transformer-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-exporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
