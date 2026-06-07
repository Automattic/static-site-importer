<?php

namespace BlockFormatBridge\Vendor;

/**
 * Plugin Name: HTML to Blocks Converter
 * Plugin URI: https://github.com/chubes4/html-to-blocks-converter
 * Description: Converts raw HTML to Gutenberg blocks — on write (wp_insert_post) and on read (REST API for the editor)
 * Version: 0.7.2
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: html-to-blocks-converter
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */
if (!\defined('ABSPATH')) {
    exit;
}
if (!\defined('HTML_TO_BLOCKS_CONVERTER_PATH')) {
    \define('HTML_TO_BLOCKS_CONVERTER_PATH', plugin_dir_path(__FILE__));
}
if (!\defined('HTML_TO_BLOCKS_CONVERTER_MIN_WP')) {
    \define('HTML_TO_BLOCKS_CONVERTER_MIN_WP', '6.4');
}
if (\version_compare(get_bloginfo('version'), \HTML_TO_BLOCKS_CONVERTER_MIN_WP, '<')) {
    \add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        \printf(
            /* translators: %s: minimum WordPress version */
            esc_html__('HTML to Blocks Converter requires WordPress %s or higher.', 'html-to-blocks-converter'),
            esc_html(\HTML_TO_BLOCKS_CONVERTER_MIN_WP)
        );
        echo '</p></div>';
    });
    return;
}
require_once \HTML_TO_BLOCKS_CONVERTER_PATH . 'library.php';
