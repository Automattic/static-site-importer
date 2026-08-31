<?php
/**
 * Plugin Name: Static Site Importer Playground Demo
 * Description: Demo-only importer UI for the Static Site Importer Playground experience.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: static-site-importer
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporterPlaygroundDemo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PLAYGROUND_DEMO_PATH', plugin_dir_path( __FILE__ ) );

require_once STATIC_SITE_IMPORTER_PLAYGROUND_DEMO_PATH . 'includes/block.php';

add_action( 'init', 'static_site_importer_playground_demo_register_block' );
