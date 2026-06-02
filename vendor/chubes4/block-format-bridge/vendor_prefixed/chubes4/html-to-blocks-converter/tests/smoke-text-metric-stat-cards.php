<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: text-only metric/stat cards become editable blocks.
 *
 * Run: php tests/smoke-text-metric-stat-cards.php
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
$unsupported_fallback_events = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $unsupported_fallback_events;
        if ('html_to_blocks_unsupported_html_fallback' === $hook_name) {
            $unsupported_fallback_events[] = $args;
        }
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = \array_diff_key($block['attrs'] ?? [], ['content' => \true, 'text' => \true]);
            $attrs_json = empty($attrs) ? '' : ' ' . \json_encode($attrs, \JSON_UNESCAPED_SLASHES);
            if ('core/html' === $name) {
                $output .= '<!-- wp:html -->' . ($block['attrs']['content'] ?? $block['innerHTML'] ?? '') . '<!-- /wp:html -->';
                continue;
            }
            $output .= '<!-- wp:' . \substr($name, 5) . $attrs_json . ' -->';
            $output .= $block['innerHTML'] ?? '';
            $output .= serialize_blocks($block['innerBlocks'] ?? []);
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
    $flattened = [];
    foreach ($blocks as $block) {
        $flattened[] = $block;
        $flattened = \array_merge($flattened, $flatten_blocks($block['innerBlocks'] ?? []));
    }
    return $flattened;
};
$stat_cards_html = <<<'HTML'
<div class="stats">
  <div class="stat"><span class="num">~60s</span><div class="label">Time to ship this site</div></div>
  <div class="stat"><span class="num">0</span><div class="label">Plugins installed</div></div>
  <div class="stat"><span class="num">$0</span><div class="label">Annual theme renewals</div></div>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $stat_cards_html]);
$serialized = serialize_blocks($blocks);
$flat = $flatten_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$classes = \implode(' ', \array_filter(\array_map(static function ($block) {
    return $block['attrs']['className'] ?? '';
}, $flat)));
$assert(\count($blocks) === 1, 'stat-cards-single-wrapper');
$assert(($blocks[0]['blockName'] ?? '') === 'core/group', 'stat-cards-wrapper-is-group');
$assert(!\in_array('core/html', $names, \true), 'stat-cards-do-not-use-core-html', \implode(', ', $names));
$assert(\count($unsupported_fallback_events) === 0, 'stat-cards-emit-no-fallback-events', (string) \count($unsupported_fallback_events));
$assert(\substr_count(\implode(',', $names), 'core/group') === 4, 'stat-cards-create-wrapper-and-card-groups', \implode(', ', $names));
$assert(\substr_count(\implode(',', $names), 'core/paragraph') === 6, 'stat-cards-create-text-paragraphs', \implode(', ', $names));
foreach (['stats', 'stat', 'num', 'label'] as $class_name) {
    $assert(\strpos($classes, $class_name) !== \false || \strpos($serialized, 'class="' . $class_name) !== \false, 'stat-cards-preserve-' . $class_name . '-class', $serialized);
}
foreach (['Time to ship this site', 'Plugins installed', 'Annual theme renewals'] as $text) {
    $assert(\substr_count($serialized, $text) === 1, 'stat-cards-preserve-once-' . $text, $serialized);
}
foreach (['~60s', '0', '$0'] as $metric_value) {
    $assert(\substr_count($serialized, '<span class="num">' . $metric_value . '</span>') === 1, 'stat-cards-preserve-once-' . $metric_value, $serialized);
}
$unsafe_html = '<div data-widget="stats"><script>alert(1)</script><div class="label">Dynamic label</div></div>';
$unsupported_fallback_events = [];
$unsafe_blocks = html_to_blocks_raw_handler(['HTML' => $unsafe_html]);
$unsafe_names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flatten_blocks($unsafe_blocks));
$assert(\in_array('core/html', $unsafe_names, \true), 'unsafe-widget-still-falls-back', \implode(', ', $unsafe_names));
$assert(\count($unsupported_fallback_events) > 0, 'unsafe-widget-emits-fallback-event');
if ($failures) {
    \fwrite(\STDERR, \implode("\n", $failures) . "\n");
    exit(1);
}
\fwrite(\STDOUT, "PASS: {$assertions} text metric/stat card assertions\n");
