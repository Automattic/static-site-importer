<?php
/**
 * Plugin Name: Block Format Bridge
 * Plugin URI: https://github.com/chubes4/block-format-bridge
 * Description: Orchestrates bidirectional content format conversion (HTML, Blocks, Markdown) via a unified adapter API. Composes existing plugins/libraries — owns no parsing logic of its own.
 * Version: 0.8.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: block-format-bridge
 * Requires at least: 6.4
 * Requires PHP: 8.1
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BFB_PLUGIN_PATH' ) ) {
	define( 'BFB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'BFB_MIN_WP' ) ) {
	define( 'BFB_MIN_WP', '6.4' );
}
if ( ! defined( 'BFB_MIN_PHP' ) ) {
	define( 'BFB_MIN_PHP', '8.1' );
}

if ( version_compare( get_bloginfo( 'version' ), BFB_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum WordPress version */
				esc_html__( 'Block Format Bridge requires WordPress %s or higher.', 'block-format-bridge' ),
				esc_html( BFB_MIN_WP )
			);
			echo '</p></div>';
		}
	);
	return;
}

$bfb_min_php = (string) constant( 'BFB_MIN_PHP' );
// @phpstan-ignore-next-line if.alwaysFalse -- Runtime guard is required for installs below the analysis PHP target.
if ( version_compare( PHP_VERSION, $bfb_min_php, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum PHP version */
				esc_html__( 'Block Format Bridge requires PHP %s or higher.', 'block-format-bridge' ),
				esc_html( (string) constant( 'BFB_MIN_PHP' ) )
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once BFB_PLUGIN_PATH . 'library.php';
