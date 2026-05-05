<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: dimensioned SVG source images serialize as Gutenberg-resized images.
 *
 * Run: php tests/smoke-resized-svg-image-serialization.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\class_exists('WP_HTML_Processor', \false)) {
    $wp_html_api_candidates = \array_filter([\getenv('WP_HTML_API_PATH') ? \getenv('WP_HTML_API_PATH') : '', '/wordpress/wp-includes/html-api', '/Users/chubes/Studio/intelligence-chubes4/wp-includes/html-api']);
    $wp_html_api_path = '';
    foreach ($wp_html_api_candidates as $candidate) {
        if (\is_file(\rtrim($candidate, '/') . '/class-wp-html-processor.php')) {
            $wp_html_api_path = \rtrim($candidate, '/');
            break;
        }
    }
    if ('' === $wp_html_api_path) {
        \fwrite(\STDERR, "FAIL: WP_HTML_Processor is unavailable. Set WP_HTML_API_PATH to wp-includes/html-api.\n");
        exit(1);
    }
    foreach (['class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-doctype-info.php', 'class-wp-html-unsupported-exception.php', 'class-wp-html-token.php', 'class-wp-html-tag-processor.php', 'class-wp-html-stack-event.php', 'class-wp-html-open-elements.php', 'class-wp-html-active-formatting-elements.php', 'class-wp-html-processor-state.php', 'class-wp-html-processor.php'] as $file) {
        require_once $wp_html_api_path . '/' . $file;
    }
}
foreach (['esc_attr', 'esc_html', 'esc_url'] as $function_name) {
    if (!\function_exists($function_name)) {
        eval('function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return wp_strip_all_tags($text);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\get_shortcode_regex')) {
    function get_shortcode_regex()
    {
        return '(?!)';
    }
}
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
    }
}
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        public static function get_instance()
        {
            return new self();
        }
        public function is_registered($name)
        {
            return \in_array($name, ['core/group', 'core/html', 'core/image'], \true);
        }
        public function get_registered($name)
        {
            return (object) ['attributes' => []];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_block_attributes')) {
    function serialize_block_attributes($attributes)
    {
        return empty($attributes) ? '' : ' ' . \json_encode($attributes, \JSON_UNESCAPED_SLASHES);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_block')) {
    function serialize_block($block)
    {
        $name = $block['blockName'] ?? '';
        return '<!-- wp:' . \substr($name, 5) . serialize_block_attributes($block['attrs'] ?? []) . ' -->' . ($block['innerHTML'] ?? '') . '<!-- /wp:' . \substr($name, 5) . ' -->';
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$svg_blocks = html_to_blocks_raw_handler(['HTML' => '<img src="http://localhost:9215/wp-content/themes/relay-atlas/assets/icons/main-index-html-1-a435c8e7fff6536a.svg" width="14" height="14">']);
$svg = $svg_blocks[0] ?? [];
$bitmap = HTML_To_Blocks_Block_Factory::create_block('core/image', ['url' => 'https://cdn.example.com/photo.jpg', 'alt' => 'Photo', 'width' => '900', 'height' => '600']);
$serialized_svg = serialize_block($svg);
$serialized_bitmap = serialize_block($bitmap);
$assert(($svg['blockName'] ?? '') === 'core/image', 'svg-source-converts-to-core-image', \var_export($svg, \true));
$assert(($svg['attrs']['width'] ?? '') === '14', 'svg-source-width-becomes-block-attribute', \var_export($svg['attrs'] ?? [], \true));
$assert(($svg['attrs']['height'] ?? '') === '14', 'svg-source-height-becomes-block-attribute', \var_export($svg['attrs'] ?? [], \true));
$assert(\str_contains($serialized_svg, '<figure class="wp-block-image is-resized">'), 'svg-figure-has-is-resized-class', $serialized_svg);
$assert(\str_contains($serialized_svg, 'alt=""'), 'svg-image-serializes-empty-alt', $serialized_svg);
$assert(\str_contains($serialized_svg, 'style="width:14px;height:14px"'), 'svg-image-serializes-valid-resized-style', $serialized_svg);
$assert(!\str_contains($serialized_svg, 'style="width:14;height:14"'), 'svg-image-does-not-serialize-unitless-resized-style', $serialized_svg);
$assert(!\str_contains($serialized_svg, 'width="14"'), 'svg-image-omits-raw-width-attribute', $serialized_svg);
$assert(!\str_contains($serialized_svg, 'height="14"'), 'svg-image-omits-raw-height-attribute', $serialized_svg);
$assert(\str_contains($serialized_bitmap, 'width="900"'), 'bitmap-image-keeps-width-attribute', $serialized_bitmap);
$assert(\str_contains($serialized_bitmap, 'height="600"'), 'bitmap-image-keeps-height-attribute', $serialized_bitmap);
echo 'Assertions: ' . $assertions . \PHP_EOL;
if (empty($failures)) {
    echo 'ALL PASS' . \PHP_EOL;
    exit(0);
}
echo 'FAILURES (' . \count($failures) . '):' . \PHP_EOL;
foreach ($failures as $failure) {
    echo '  - ' . $failure . \PHP_EOL;
}
exit(1);
