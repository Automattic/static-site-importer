<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: visual list/card layouts become editable groups.
 *
 * Run: php tests/smoke-visual-list-groups.php
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
    $core_root = \dirname($wp_html_api_path);
    if (\is_file($core_root . '/class-wp-token-map.php')) {
        require_once $core_root . '/class-wp-token-map.php';
    }
    if (\is_file($wp_html_api_path . '/html5-named-character-references.php')) {
        require_once $wp_html_api_path . '/html5-named-character-references.php';
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
            return \in_array($name, ['core/group', 'core/heading', 'core/html', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$flatten_block_names = static function (array $blocks) use (&$flatten_block_names): array {
    $names = [];
    foreach ($blocks as $block) {
        $names[] = $block['blockName'] ?? '';
        $names = \array_merge($names, $flatten_block_names($block['innerBlocks'] ?? []));
    }
    return $names;
};
$flatten_class_names = static function (array $blocks) use (&$flatten_class_names): array {
    $classes = [];
    foreach ($blocks as $block) {
        if (!empty($block['attrs']['className'])) {
            $classes[] = $block['attrs']['className'];
        }
        $classes = \array_merge($classes, $flatten_class_names($block['innerBlocks'] ?? []));
    }
    return $classes;
};
$visual_list_html = <<<'HTML'
<ol class="pipeline-steps">
  <li class="pipeline-step">
    <div class="step-number">01</div>
    <div class="step-content"><h3>Title</h3><p>Copy</p></div>
    <div class="step-visual"></div>
  </li>
</ol>
HTML;
$visual_blocks = html_to_blocks_raw_handler(['HTML' => $visual_list_html]);
$visual_names = $flatten_block_names($visual_blocks);
$visual_classes = \implode(' ', $flatten_class_names($visual_blocks));
$assert(($visual_blocks[0]['blockName'] ?? '') === 'core/group', 'visual-list-wrapper-is-group');
$assert(($visual_blocks[0]['attrs']['className'] ?? '') === 'pipeline-steps', 'visual-list-preserves-wrapper-class');
$assert(($visual_blocks[0]['innerBlocks'][0]['blockName'] ?? '') === 'core/group', 'visual-list-item-is-group');
$assert(($visual_blocks[0]['innerBlocks'][0]['attrs']['className'] ?? '') === 'pipeline-step', 'visual-list-item-preserves-class');
$assert(\in_array('core/heading', $visual_names, \true), 'visual-list-contains-heading');
$assert(\in_array('core/paragraph', $visual_names, \true), 'visual-list-contains-paragraph');
$assert(!\in_array('core/list', $visual_names, \true), 'visual-list-has-no-core-list');
$assert(!\in_array('core/list-item', $visual_names, \true), 'visual-list-has-no-core-list-item');
$assert(!\in_array('core/html', $visual_names, \true), 'visual-list-has-no-core-html');
$assert(\strpos($visual_classes, 'step-number') !== \false, 'visual-list-preserves-step-number-class');
$assert(\strpos($visual_classes, 'step-content') !== \false, 'visual-list-preserves-step-content-class');
$assert(\strpos($visual_classes, 'step-visual') !== \false, 'visual-list-preserves-step-visual-class');
$simple_blocks = html_to_blocks_raw_handler(['HTML' => '<ul><li>Plain text</li></ul>']);
$simple_names = $flatten_block_names($simple_blocks);
$assert(($simple_blocks[0]['blockName'] ?? '') === 'core/list', 'simple-list-stays-core-list');
$assert(\in_array('core/list-item', $simple_names, \true), 'simple-list-keeps-list-items');
$assert(!\in_array('core/group', $simple_names, \true), 'simple-list-has-no-groups');
$nested_blocks = html_to_blocks_raw_handler(['HTML' => '<ul><li>Parent<ul><li>Child</li></ul></li></ul>']);
$nested_names = $flatten_block_names($nested_blocks);
$assert(($nested_blocks[0]['blockName'] ?? '') === 'core/list', 'nested-list-stays-core-list');
$assert(\count(\array_keys($nested_names, 'core/list', \true)) === 2, 'nested-list-keeps-nested-list');
$assert(!\in_array('core/group', $nested_names, \true), 'nested-list-has-no-groups');
if ($failures) {
    \fwrite(\STDERR, \implode("\n", $failures) . "\n");
    exit(1);
}
\fwrite(\STDOUT, "PASS: {$assertions} visual list group assertions\n");
