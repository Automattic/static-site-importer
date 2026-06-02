<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: safe inline SVG icons expose sanitized placeholder metadata.
 *
 * Run: php tests/smoke-inline-svg-icon-classification.php
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
            return \in_array($name, ['core/html', 'core/paragraph'], \true);
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
$safe_svg_events = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $fallback_events, $safe_svg_events;
        if ('html_to_blocks_unsupported_html_fallback' === $hook_name) {
            $fallback_events[] = $args;
        }
        if ('html_to_blocks_safe_inline_svg_icon' === $hook_name) {
            $safe_svg_events[] = $args;
        }
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-svg-icon-classifier.php';
require_once $repo_root . '/includes/svg-icon-functions.php';
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
$collect_blocks = static function (array $blocks, string $name) use (&$collect_blocks): array {
    $matches = [];
    foreach ($blocks as $block) {
        if (($block['blockName'] ?? '') === $name) {
            $matches[] = $block;
        }
        if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
            $matches = \array_merge($matches, $collect_blocks($block['innerBlocks'], $name));
        }
    }
    return $matches;
};
$safe_svg = '<svg class="icon icon-arrow" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Arrow"><title>Arrow</title><path fill="#fff" d="M4 12h14"/><polyline points="14 6 20 12 14 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
$safe = HTML_To_Blocks_SVG_Icon_Classifier::classify($safe_svg);
$helper = html_to_blocks_classify_inline_svg_icon($safe_svg);
$assert(!empty($safe['is_safe']), 'classifier-accepts-safe-svg', $safe['reason'] ?? '');
$assert(!empty($helper['is_safe']), 'helper-accepts-safe-svg', $helper['reason'] ?? '');
$assert(\str_contains($safe['svg'], '<svg'), 'classifier-returns-sanitized-svg', $safe['svg'] ?? '');
$assert(!\str_contains($safe['svg'], 'xmlns'), 'classifier-strips-nonessential-attrs', $safe['svg'] ?? '');
$assert(($safe['metadata']['kind'] ?? '') === 'inline-svg-icon', 'classifier-returns-kind-metadata');
$assert(($safe['metadata']['viewBox'] ?? '') === '0 0 24 24', 'classifier-returns-viewbox-metadata');
$safe_blocks = html_to_blocks_raw_handler(['HTML' => $safe_svg]);
$icons = $collect_blocks($safe_blocks, 'html-to-blocks/svg-icon');
$fallbacks = $collect_blocks($safe_blocks, 'core/html');
$assert(\count($icons) === 1, 'safe-svg-emits-placeholder-block');
$assert(\count($fallbacks) === 0, 'safe-svg-emits-no-core-html');
$assert(\count($fallback_events) === 0, 'safe-svg-emits-no-fallback-diagnostic');
$assert(\count($safe_svg_events) === 1, 'safe-svg-emits-detection-event');
$assert(($icons[0]['attrs']['metadata']['kind'] ?? '') === 'inline-svg-icon', 'placeholder-exposes-metadata');
$assert(\str_contains($icons[0]['attrs']['svg'] ?? '', '<polyline'), 'placeholder-exposes-sanitized-payload');
$reference_svgs = ['use-href' => '<svg viewBox="0 0 24 24"><use href="#shape"/></svg>', 'image-data' => '<svg viewBox="0 0 24 24"><image href="data:image/png;base64,abc"/></svg>', 'fill-url-ref' => '<svg viewBox="0 0 24 24"><path fill="url(#paint)" d="M0 0h24v24H0z"/></svg>'];
foreach ($reference_svgs as $label => $reference_svg) {
    $reference = HTML_To_Blocks_SVG_Icon_Classifier::classify($reference_svg);
    $assert(empty($reference['is_safe']), 'classifier-rejects-reference-svg-' . $label, $reference['reason'] ?? '');
}
$benchmark_svgs = ['sidebar-icon-rect-circle-path' => '<svg class="sidebar-icon" viewbox="0 0 18 18" fill="none"><rect x="2" y="2" width="6" height="6" rx="1.5" fill="currentColor"></rect><circle cx="13" cy="5" r="3" fill="#1a4fdb" fill-opacity="0.24"></circle><path d="M3 13h12" fill="currentColor" fill-opacity=".72"></path></svg>', 'feature-icon-rect-circle-path' => '<svg width="22" height="22" viewbox="0 0 22 22" fill="none"><rect x="3" y="3" width="7" height="7" rx="2" fill="#1a4fdb"></rect><circle cx="15.5" cy="6.5" r="3.5" fill="currentColor" fill-opacity="0.4"></circle><path d="M4 17h14" fill="#ffcc00" fill-opacity="1"></path></svg>'];
foreach ($benchmark_svgs as $label => $benchmark_svg) {
    $benchmark_classification = HTML_To_Blocks_SVG_Icon_Classifier::classify($benchmark_svg);
    $benchmark_blocks = html_to_blocks_raw_handler(['HTML' => $benchmark_svg]);
    $benchmark_icons = $collect_blocks($benchmark_blocks, 'html-to-blocks/svg-icon');
    $benchmark_fallbacks = $collect_blocks($benchmark_blocks, 'core/html');
    $benchmark_tags = $benchmark_icons[0]['attrs']['metadata']['tags'] ?? [];
    $benchmark_sanitized = $benchmark_icons[0]['attrs']['svg'] ?? '';
    $assert(!empty($benchmark_classification['is_safe']), $label . '-classifier-accepts-benchmark-svg', $benchmark_classification['reason'] ?? '');
    $assert(\count($benchmark_icons) === 1, $label . '-emits-placeholder-block', \var_export($benchmark_blocks, \true));
    $assert(\count($benchmark_fallbacks) === 0, $label . '-emits-no-core-html', \var_export($benchmark_blocks, \true));
    $assert(\in_array('rect', $benchmark_tags, \true) && \in_array('circle', $benchmark_tags, \true) && \in_array('path', $benchmark_tags, \true), $label . '-records-primitive-tags', \implode(', ', $benchmark_tags));
    $assert(\str_contains($benchmark_sanitized, 'fill-opacity'), $label . '-preserves-safe-paint-opacity', $benchmark_sanitized);
}
$assert(\count($fallback_events) === 0, 'benchmark-safe-svgs-emit-no-fallback-diagnostics');
$unsafe_svg = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path onclick="alert(1)" d="M0 0h24v24H0z"/></svg>';
$unsafe = HTML_To_Blocks_SVG_Icon_Classifier::classify($unsafe_svg);
$unsafe_blocks = html_to_blocks_raw_handler(['HTML' => $unsafe_svg]);
$unsafe_icons = $collect_blocks($unsafe_blocks, 'html-to-blocks/svg-icon');
$unsafe_html = $collect_blocks($unsafe_blocks, 'core/html');
$assert(empty($unsafe['is_safe']), 'classifier-rejects-active-svg', $unsafe['reason'] ?? '');
$assert(\count($unsafe_icons) === 0, 'unsafe-svg-emits-no-placeholder');
$assert(\count($unsafe_html) === 1, 'unsafe-svg-stays-on-fallback-path');
$assert(\count($fallback_events) === 1, 'unsafe-svg-emits-fallback-diagnostic');
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
