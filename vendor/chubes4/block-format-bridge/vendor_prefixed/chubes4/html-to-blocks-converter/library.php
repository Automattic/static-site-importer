<?php

namespace BlockFormatBridge\Vendor;

/**
 * Library entry point for html-to-blocks-converter.
 *
 * Composer consumers autoload this file (`autoload.files`) to make the
 * zero-configuration HTML → blocks automation available without requiring
 * the standalone plugin to be separately activated. The raw conversion API
 * is loaded alongside the automatic write/read hooks.
 *
 * Multiple bundled copies can coexist: each registers its version and
 * initializer, then `HTML_To_Blocks_Versions` initializes the highest
 * registered version on `plugins_loaded:1`.
 *
 * @package HTML_To_Blocks_Converter
 */
if (!\defined('ABSPATH')) {
    return;
}
$html_to_blocks_library_path = __DIR__;
$html_to_blocks_library_version = '0.7.2';
if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_Versions', \false)) {
    require_once $html_to_blocks_library_path . '/includes/class-html-to-blocks-versions.php';
}
$html_to_blocks_initializer = static function () use ($html_to_blocks_library_path): void {
    if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_HTML_Element', \false)) {
        require_once $html_to_blocks_library_path . '/includes/class-html-element.php';
    }
    if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_Block_Factory', \false)) {
        require_once $html_to_blocks_library_path . '/includes/class-block-factory.php';
    }
    if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_Attribute_Parser', \false)) {
        require_once $html_to_blocks_library_path . '/includes/class-attribute-parser.php';
    }
    if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_SVG_Icon_Classifier', \false)) {
        require_once $html_to_blocks_library_path . '/includes/class-svg-icon-classifier.php';
    }
    if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_classify_inline_svg_icon')) {
        require_once $html_to_blocks_library_path . '/includes/svg-icon-functions.php';
    }
    if (!\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_Transform_Registry', \false)) {
        require_once $html_to_blocks_library_path . '/includes/class-transform-registry.php';
    }
    $html_to_blocks_raw_handler_callback = 'BlockFormatBridge\Vendor\html_to_blocks_raw_handler';
    if (!\function_exists($html_to_blocks_raw_handler_callback)) {
        require_once $html_to_blocks_library_path . '/raw-handler.php';
    }
    require_once $html_to_blocks_library_path . '/includes/hooks.php';
};
$html_to_blocks_register = static function () use ($html_to_blocks_library_version, $html_to_blocks_initializer): void {
    HTML_To_Blocks_Versions::instance()->register($html_to_blocks_library_version, $html_to_blocks_initializer);
};
HTML_To_Blocks_Versions::register_hooks();
if (did_action('plugins_loaded') && !doing_action('plugins_loaded')) {
    $html_to_blocks_register();
    HTML_To_Blocks_Versions::initialize_latest_version();
} else {
    \add_action('plugins_loaded', $html_to_blocks_register, 0);
}
