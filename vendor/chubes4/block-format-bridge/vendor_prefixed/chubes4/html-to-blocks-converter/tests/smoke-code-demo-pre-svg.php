<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: code-demo wrappers convert natively while SVG fallback stays scoped.
 *
 * Run: php tests/smoke-code-demo-pre-svg.php
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
            return \in_array($name, ['core/group', 'core/html', 'core/paragraph', 'core/preformatted'], \true);
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
$html = <<<'HTML'
<div class="sc-hero__code-demo">
  <div class="sc-code-panel sc-code-panel--before">
    <div class="sc-code-panel__label">Your HTML</div>
    <pre class="sc-code-block"><code class="language-html"><span class="tok-tag">&lt;section</span> <span class="tok-attr">class</span>=<span class="tok-value">&quot;hero&quot;</span><span class="tok-tag">&gt;</span>
  <span class="tok-tag">&lt;h1&gt;</span>Build Something<span class="tok-tag">&lt;/h1&gt;</span>
<span class="tok-tag">&lt;/section&gt;</span></code></pre>
  </div>
  <div class="sc-code-arrow">
    <svg width="40" height="40" viewBox="0 0 40 40" fill="none"><path d="M8 20h24M24 12l8 8-8 8" stroke="#00e5d4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span class="sc-code-arrow__label">Studio Code</span>
  </div>
  <div class="sc-code-panel sc-code-panel--after">
    <div class="sc-code-panel__label">WordPress Blocks</div>
    <pre class="sc-code-block"><code class="language-html"><span class="tok-comment">&lt;!-- wp:cover --&gt;</span>
<span class="tok-tag">&lt;div</span> <span class="tok-attr">class</span>=<span class="tok-value">&quot;wp-block-cover&quot;</span><span class="tok-tag">&gt;</span>...</code></pre>
  </div>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$serialized = serialize_blocks($blocks);
$groups = $collect_blocks($blocks, 'core/group');
$fallbacks = $collect_blocks($blocks, 'core/html');
$paragraphs = $collect_blocks($blocks, 'core/paragraph');
$preformatted = $collect_blocks($blocks, 'core/preformatted');
$assert(\count($blocks) === 1 && ($blocks[0]['blockName'] ?? '') === 'core/group', 'demo-wrapper-becomes-group', $serialized);
$assert(\count($groups) >= 4, 'demo-panels-and-arrow-become-groups', $serialized);
$assert(\count($paragraphs) >= 3, 'labels-become-native-paragraphs', $serialized);
$assert(\count($preformatted) === 2, 'pre-code-panels-become-preformatted', $serialized);
$assert(\str_contains($serialized, 'sc-hero__code-demo'), 'demo-wrapper-class-survives', $serialized);
$assert(\str_contains($serialized, 'sc-code-panel--before') && \str_contains($serialized, 'sc-code-panel--after'), 'panel-classes-survive', $serialized);
$assert(\str_contains($serialized, 'Your HTML') && \str_contains($serialized, 'WordPress Blocks') && \str_contains($serialized, 'Studio Code'), 'labels-survive', $serialized);
$assert(\str_contains($serialized, '&lt;section') && \str_contains($serialized, '&lt;!-- wp:cover --&gt;'), 'escaped-code-survives', $serialized);
$assert(\str_contains($serialized, 'tok-tag') && \str_contains($serialized, 'tok-comment'), 'syntax-span-classes-survive', $serialized);
$assert(\str_contains($serialized, 'language-html') && \str_contains($serialized, 'sc-code-block'), 'code-and-pre-classes-survive', $serialized);
$assert(\count($fallbacks) === 1, 'only-svg-falls-back-to-html', $serialized);
$fallback_content = $fallbacks[0]['attrs']['content'] ?? '';
$assert(\str_starts_with(\trim($fallback_content), '<svg'), 'fallback-starts-at-svg', $fallback_content);
$assert(!\str_contains($fallback_content, 'sc-hero__code-demo'), 'fallback-does-not-wrap-demo', $fallback_content);
$assert(!\str_contains($fallback_content, 'sc-code-panel'), 'fallback-does-not-wrap-panels', $fallback_content);
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
