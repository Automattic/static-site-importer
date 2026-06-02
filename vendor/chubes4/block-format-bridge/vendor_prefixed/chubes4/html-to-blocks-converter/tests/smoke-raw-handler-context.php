<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: raw-handler context reaches top-level and nested transforms.
 *
 * Run: php tests/smoke-raw-handler-context.php
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
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        public static function get_instance()
        {
            return new self();
        }
        public function is_registered($name)
        {
            return \in_array($name, ['core/group', 'core/html', 'core/paragraph'], \true);
        }
        public function get_registered($name)
        {
            return (object) ['attributes' => []];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
foreach (['esc_attr', 'esc_html', 'esc_url'] as $function_name) {
    if (!\function_exists($function_name)) {
        eval('function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return \strip_tags((string) $text);
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
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';
$transforms_property = new \ReflectionProperty(HTML_To_Blocks_Transform_Registry::class, 'transforms');
$transforms_property->setValue(null, [['blockName' => 'core/group', 'priority' => 1, 'isMatch' => static function ($element) {
    return $element->get_tag_name() === 'DIV';
}, 'transform' => static function ($element, $handler) {
    return HTML_To_Blocks_Block_Factory::create_block('core/group', [], $handler(['HTML' => $element->get_inner_html()]));
}], ['blockName' => 'core/paragraph', 'priority' => 1, 'isMatch' => static function ($element) {
    return $element->get_tag_name() === 'P';
}, 'transform' => static function ($element, $handler = null, $args = []) {
    $source = $args['context']['source'] ?? 'missing';
    $mode = $args['mode'] ?? 'missing';
    $label = \trim(wp_strip_all_tags($element->get_inner_html()));
    return HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => $label . ':' . $source . ':' . $mode]);
}]]);
$blocks = html_to_blocks_raw_handler(['HTML' => '<p>Top</p><div><p>Nested</p></div>', 'context' => ['source' => 'smoke'], 'mode' => 'import']);
$top_content = $blocks[0]['attrs']['content'] ?? '';
$nested_content = $blocks[1]['innerBlocks'][0]['attrs']['content'] ?? '';
$assertions = 2;
$failures = [];
if ('Top:smoke:import' !== $top_content) {
    $failures[] = 'FAIL [top-level-transform-context]: ' . $top_content;
}
if ('Nested:smoke:import' !== $nested_content) {
    $failures[] = 'FAIL [nested-transform-context]: ' . $nested_content;
}
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
