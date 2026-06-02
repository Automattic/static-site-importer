<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: empty decorative icon placeholders are ignored safely.
 *
 * Run: php tests/smoke-empty-decorative-icon-placeholders.php
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
$convert = static function (string $html) use ($flatten_blocks): array {
    global $fallback_events;
    $fallback_events = [];
    $blocks = html_to_blocks_raw_handler(['HTML' => $html]);
    $flat = $flatten_blocks($blocks);
    return [$blocks, $flat, $fallback_events];
};
[$blocks, $flat, $events] = $convert('<div class="usecase-icon" style="background:#e0fff3; position:absolute; opacity:0"></div>');
$assert($blocks === [], 'absolute-empty-usecase-icon-is-dropped', \json_encode($blocks));
$assert(\count($events) === 0, 'absolute-empty-usecase-icon-emits-no-fallback', (string) \count($events));
[$blocks, $flat, $events] = $convert('<span class="feature-icon" style="opacity:0" aria-hidden="true"></span><p>Feature copy.</p>');
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$assert(!\in_array('core/html', $names, \true), 'hidden-empty-feature-icon-avoids-core-html', \implode(', ', $names));
$assert(\in_array('core/paragraph', $names, \true), 'neighbor-content-survives-after-icon-drop', \implode(', ', $names));
$assert(!\str_contains(\json_encode($blocks), 'feature-icon'), 'hidden-empty-feature-icon-is-not-wrapped-in-paragraph', \json_encode($blocks));
$assert(\count($events) === 0, 'hidden-empty-feature-icon-emits-no-fallback', (string) \count($events));
[$blocks, $flat, $events] = $convert('<div class="usecase-icon" style="position:absolute" aria-label="Use case"></div>');
$assert(\count($events) === 1, 'accessible-empty-icon-still-falls-back', (string) \count($events));
$assert(($blocks[0]['blockName'] ?? '') === 'core/html', 'accessible-empty-icon-preserved-as-core-html', \json_encode($blocks));
[$blocks, $flat, $events] = $convert('<div class="usecase-icon" style="position:absolute" data-icon="arrow"></div>');
$assert(\count($events) === 1, 'data-bearing-empty-icon-still-falls-back', (string) \count($events));
$assert(($blocks[0]['blockName'] ?? '') === 'core/html', 'data-bearing-empty-icon-preserved-as-core-html', \json_encode($blocks));
[$blocks, $flat, $events] = $convert('<div class="usecase-icon" style="position:absolute" onclick="alert(1)"></div>');
$assert(\count($events) === 1, 'interactive-empty-icon-still-falls-back', (string) \count($events));
$assert(($blocks[0]['blockName'] ?? '') === 'core/html', 'interactive-empty-icon-preserved-as-core-html', \json_encode($blocks));
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
