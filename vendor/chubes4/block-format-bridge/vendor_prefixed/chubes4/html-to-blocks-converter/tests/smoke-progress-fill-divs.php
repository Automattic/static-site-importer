<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: empty decorative progress/fill divs convert to native blocks.
 *
 * Run: php tests/smoke-progress-fill-divs.php
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
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = \array_diff_key($block['attrs'] ?? [], ['content' => \true]);
            $attrs_json = empty($attrs) ? '' : ' ' . \json_encode($attrs, \JSON_UNESCAPED_SLASHES);
            if ('core/html' === $name) {
                $output .= '<!-- wp:html -->' . ($block['attrs']['content'] ?? $block['innerHTML'] ?? '') . '<!-- /wp:html -->';
                continue;
            }
            $output .= '<!-- wp:' . \substr($name, 5) . $attrs_json . ' -->';
            $output .= $block['innerContent'][0] ?? $block['innerHTML'] ?? '';
            $output .= serialize_blocks($block['innerBlocks'] ?? []);
            $inner_content = $block['innerContent'] ?? [];
            $output .= \end($inner_content) ? \end($inner_content) : '';
            $output .= '<!-- /wp:' . \substr($name, 5) . ' -->';
        }
        return $output;
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
$html = <<<'HTML'
<div class="finance-bar">
  <div class="finance-fill" style="width: 74%"></div>
  <div class="finance-fill seafoam" style="width: 17%"></div>
  <div class="finance-fill sand" style="width: 9%"></div>
  <div class="timeline-fill static-site-importer-decorative-layer" style="width:62%"></div>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$flat = $flatten_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$class_names = \array_filter(\array_map(static function ($block) {
    return $block['attrs']['className'] ?? '';
}, $flat));
$widths = \array_values(\array_filter(\array_map(static function ($block) {
    return $block['attrs']['style']['dimensions']['width'] ?? '';
}, $flat)));
$serialized = serialize_blocks($blocks);
$assert(!\in_array('core/html', $names, \true), 'progress-fill-divs-do-not-use-core-html', \implode(', ', $names));
$assert(\count($fallback_events) === 0, 'progress-fill-divs-emit-no-fallback-events', (string) \count($fallback_events));
$assert(\substr_count(\implode(',', $names), 'core/group') === 5, 'progress-fill-divs-use-group-blocks', \implode(', ', $names));
$assert(\in_array('finance-bar', $class_names, \true), 'progress-wrapper-class-survives', \implode(', ', $class_names));
$assert(\in_array('finance-fill', $class_names, \true), 'progress-fill-class-survives', \implode(', ', $class_names));
$assert(\in_array('finance-fill seafoam', $class_names, \true), 'progress-fill-extra-class-survives', \implode(', ', $class_names));
$assert(\in_array('finance-fill sand', $class_names, \true), 'progress-fill-second-extra-class-survives', \implode(', ', $class_names));
$assert(\in_array('timeline-fill static-site-importer-decorative-layer', $class_names, \true), 'timeline-fill-class-survives', \implode(', ', $class_names));
$assert($widths === [], 'empty-decorative-fill-widths-are-dropped', \implode(', ', $widths));
$assert(!\str_contains($serialized, 'style="width:'), 'serialized-fill-widths-are-dropped', $serialized);
$assert(\str_contains($serialized, '<div class="wp-block-group timeline-fill static-site-importer-decorative-layer"></div>'), 'serialized-timeline-fill-has-no-raw-style', $serialized);
$assert(!\str_contains($serialized, '<!-- wp:html -->'), 'serialized-output-has-no-wp-html', $serialized);
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
