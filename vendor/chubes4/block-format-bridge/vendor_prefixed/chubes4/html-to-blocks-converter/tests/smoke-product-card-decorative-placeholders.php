<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: product-card decorative placeholders convert without raw HTML fallback.
 *
 * Run: php tests/smoke-product-card-decorative-placeholders.php
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
    $flat = [];
    foreach ($blocks as $block) {
        $flat[] = $block;
        $flat = \array_merge($flat, $flatten_blocks($block['innerBlocks'] ?? []));
    }
    return $flat;
};
$class_names = static function (array $blocks) use ($flatten_blocks): array {
    return \array_filter(\array_map(static function ($block) {
        return $block['attrs']['className'] ?? '';
    }, $flatten_blocks($blocks)));
};
$block_names = static function (array $blocks) use ($flatten_blocks): array {
    return \array_map(static function ($block) {
        return $block['blockName'] ?? '';
    }, $flatten_blocks($blocks));
};
$html = <<<'HTML'
<div class="category-img wood-walnut"></div>
<div class="category-img wood-maple"></div>
<div class="category-img wood-cherry"></div>
<div class="category-img" style="background: linear-gradient(160deg, #4a6741 0%, #3d5535 40%, #6b7c5e 70%, #2e4028 100%)"></div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$names = $block_names($blocks);
$classes = $class_names($blocks);
$serialized = serialize_blocks($blocks);
$assert(\count($blocks) === 4, 'category-placeholders-are-not-dropped', (string) \count($blocks));
$assert(!\in_array('core/html', $names, \true), 'category-placeholders-do-not-use-core-html', \implode(', ', $names));
$assert(\count($fallback_events) === 0, 'category-placeholders-emit-no-fallback-events', (string) \count($fallback_events));
$assert(\in_array('core/group', $names, \true), 'category-placeholders-use-group-blocks', \implode(', ', $names));
$assert(\in_array('category-img wood-walnut', $classes, \true), 'category-placeholder-classes-survive', \implode(', ', $classes));
$assert(\str_contains($serialized, 'linear-gradient(160deg'), 'category-placeholder-gradient-survives', $serialized);
$assert(!\str_contains($serialized, '<!-- wp:html -->'), 'category-serialized-output-has-no-wp-html', $serialized);
$fallback_events = [];
$figure_html = <<<'HTML'
<figure class="gallery-item animated" style="transition-delay:0.04s">
  <div class="gallery-item-bg" style="background: linear-gradient(135deg, #5c3d2e 0%, #4a3020 40%, #7a5540 70%, #3d2b1f 100%)"></div>
  <figcaption class="gallery-caption">Walnut board in daily use — @mollyskitchen</figcaption>
</figure>
HTML;
$figure_blocks = html_to_blocks_raw_handler(['HTML' => $figure_html]);
$figure_names = $block_names($figure_blocks);
$figure_classes = $class_names($figure_blocks);
$figure_serialized = \html_entity_decode(serialize_blocks($figure_blocks), \ENT_QUOTES, 'UTF-8');
$assert(\count($figure_blocks) === 1, 'gallery-figure-single-wrapper', (string) \count($figure_blocks));
$assert(!\in_array('core/html', $figure_names, \true), 'gallery-figure-does-not-use-core-html', \implode(', ', $figure_names));
$assert(\count($fallback_events) === 0, 'gallery-figure-emits-no-fallback-events', (string) \count($fallback_events));
$assert(\in_array('core/group', $figure_names, \true), 'gallery-figure-uses-groups', \implode(', ', $figure_names));
$assert(\in_array('core/paragraph', $figure_names, \true), 'gallery-caption-becomes-paragraph', \implode(', ', $figure_names));
$assert(\in_array('gallery-item animated', $figure_classes, \true), 'gallery-figure-classes-survive', \implode(', ', $figure_classes));
$assert(\in_array('gallery-item-bg', $figure_classes, \true), 'gallery-bg-class-survives', \implode(', ', $figure_classes));
$assert(\in_array('gallery-caption', $figure_classes, \true), 'gallery-caption-class-survives', \implode(', ', $figure_classes));
$assert(\str_contains($figure_serialized, 'Walnut board in daily use — @mollyskitchen'), 'gallery-caption-text-survives', $figure_serialized);
$assert(\str_contains($figure_serialized, 'linear-gradient(135deg'), 'gallery-gradient-survives', $figure_serialized);
$assert(!\str_contains($figure_serialized, '<!-- wp:html -->'), 'gallery-serialized-output-has-no-wp-html', $figure_serialized);
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
