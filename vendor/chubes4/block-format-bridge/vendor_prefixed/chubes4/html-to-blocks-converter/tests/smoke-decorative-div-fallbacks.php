<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: decorative layout divs convert to native blocks.
 *
 * Run: php tests/smoke-decorative-div-fallbacks.php
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
$fallback_events = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $fallback_events;
        if ('html_to_blocks_unsupported_html_fallback' === $hook_name) {
            $fallback_events[] = $args;
        }
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
$flatten_blocks = static function (array $blocks) use (&$flatten_blocks): array {
    $flat = [];
    foreach ($blocks as $block) {
        $flat[] = $block;
        $flat = \array_merge($flat, $flatten_blocks($block['innerBlocks'] ?? []));
    }
    return $flat;
};
$html = <<<HTML
<div class="ss-hero-scroll">
  <div class="ss-hero-scroll-line"></div>
  <span>Scroll</span>
</div>
<div class="hero-bg"></div>
<div class="hero-pattern"></div>
<div class="ss-hero-grain"></div>
<div class="ss-product-divider"></div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$flat = $flatten_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$class_names = \array_filter(\array_map(static function ($block) {
    return $block['attrs']['className'] ?? '';
}, $flat));
$contents = \implode('\n', \array_map(static function ($block) {
    return (string) ($block['attrs']['content'] ?? $block['innerHTML'] ?? '');
}, $flat));
$assert(!\in_array('core/html', $names, \true), 'decorative-divs-do-not-use-core-html', \implode(', ', $names));
$assert(\count($fallback_events) === 0, 'decorative-divs-emit-no-fallback-events', (string) \count($fallback_events));
$assert(\in_array('core/group', $names, \true), 'decorative-divs-use-group-blocks', \implode(', ', $names));
$assert(\in_array('core/paragraph', $names, \true), 'scroll-label-uses-paragraph-block', \implode(', ', $names));
$assert(\str_contains($contents, 'Scroll'), 'scroll-label-text-survives', $contents);
$assert(\in_array('ss-hero-scroll', $class_names, \true), 'hero-scroll-class-survives', \implode(', ', $class_names));
$assert(\in_array('ss-hero-scroll-line', $class_names, \true), 'hero-scroll-line-class-survives', \implode(', ', $class_names));
$assert(\in_array('hero-bg', $class_names, \true), 'empty-background-class-survives', \implode(', ', $class_names));
$assert(\in_array('hero-pattern', $class_names, \true), 'empty-pattern-class-survives', \implode(', ', $class_names));
$assert(\in_array('ss-hero-grain', $class_names, \true), 'empty-grain-class-survives', \implode(', ', $class_names));
$assert(\in_array('ss-product-divider', $class_names, \true), 'empty-divider-class-survives', \implode(', ', $class_names));
$assert(\count($blocks) === 5, 'empty-decorative-divs-are-not-dropped', (string) \count($blocks));
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
