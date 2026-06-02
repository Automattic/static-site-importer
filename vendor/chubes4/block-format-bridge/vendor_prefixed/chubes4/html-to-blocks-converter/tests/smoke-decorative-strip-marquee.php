<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: decorative strip/marquee spans with div separators convert natively.
 *
 * Run: php tests/smoke-decorative-strip-marquee.php
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
$html = <<<'HTML'
<div class="strip-track">
  <span class="strip-item accent">html → blocks<div class="strip-sep"></div></span>
  <span class="strip-item">wp:group + wp:paragraph<div class="strip-sep"></div></span>
  <span class="strip-item orange">one conversion pipeline<div class="strip-sep"></div></span>
  <span class="strip-item">Site Editor compatible<div class="strip-sep"></div></span>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$flat = $flatten_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$serialized = serialize_blocks($blocks);
$assert(!\in_array('core/html', $names, \true), 'strip-marquee-does-not-use-core-html', \implode(', ', $names));
$assert(\count($fallback_events) === 0, 'strip-marquee-emits-no-fallback-events', (string) \count($fallback_events));
$assert(\substr_count(\implode(',', $names), 'core/group') === 5, 'track-and-items-become-groups', \implode(', ', $names));
$assert(\substr_count(\implode(',', $names), 'core/paragraph') === 4, 'strip-item-labels-become-paragraphs', \implode(', ', $names));
$assert(\str_contains($serialized, 'strip-track'), 'track-class-survives', $serialized);
$assert(\str_contains($serialized, 'strip-item accent'), 'item-class-survives', $serialized);
$assert(\str_contains($serialized, 'html → blocks'), 'unicode-label-survives', $serialized);
$assert(\str_contains($serialized, 'wp:group + wp:paragraph'), 'block-syntax-label-survives', $serialized);
$assert(!\str_contains($serialized, 'strip-sep'), 'decorative-separators-are-dropped', $serialized);
$assert(!\str_contains($serialized, '<p><span'), 'no-span-wrapped-block-markup-in-paragraph', $serialized);
$studio_code_html = <<<'HTML'
<div class="hero-scroll-band">
  <div class="scroll-track">
    <span class="scroll-item">HTML-first generation</span>
    <span class="scroll-item">Block theme output</span>
    <span class="scroll-item">Static Site Importer</span>
    <span class="scroll-item">One-shot site builds</span>
  </div>
</div>
HTML;
$studio_code_blocks = html_to_blocks_raw_handler(['HTML' => $studio_code_html]);
$studio_code_flat = $flatten_blocks($studio_code_blocks);
$studio_code_names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $studio_code_flat);
$studio_code_serialized = serialize_blocks($studio_code_blocks);
$assert(!\in_array('core/html', $studio_code_names, \true), 'studio-code-scroller-does-not-use-core-html', \implode(', ', $studio_code_names));
$assert(\count($fallback_events) === 0, 'studio-code-scroller-emits-no-fallback-events', (string) \count($fallback_events));
$assert(\substr_count(\implode(',', $studio_code_names), 'core/group') === 2, 'studio-code-band-and-track-become-groups', \implode(', ', $studio_code_names));
$assert(\substr_count(\implode(',', $studio_code_names), 'core/paragraph') === 4, 'studio-code-scroll-items-stay-separate-paragraphs', \implode(', ', $studio_code_names));
$assert(\str_contains($studio_code_serialized, 'hero-scroll-band'), 'studio-code-band-class-survives', $studio_code_serialized);
$assert(\str_contains($studio_code_serialized, 'scroll-track'), 'studio-code-track-class-survives', $studio_code_serialized);
$assert(\substr_count($studio_code_serialized, '<p class="scroll-item">') === 4, 'studio-code-item-classes-survive-individually', $studio_code_serialized);
$assert(!\str_contains($studio_code_serialized, '<p><span class="scroll-item">'), 'studio-code-items-not-collapsed-into-one-paragraph', $studio_code_serialized);
$fallback_events = [];
$separator_only = html_to_blocks_raw_handler(['HTML' => '<div class="strip-sep"></div>']);
$assert($separator_only === [], 'standalone-strip-separator-is-ignored');
$assert(\count($fallback_events) === 0, 'standalone-strip-separator-emits-no-fallback-events', (string) \count($fallback_events));
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
