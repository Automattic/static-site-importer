<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: generic code-display divs convert without HTML fallback.
 *
 * Run: php tests/smoke-code-display-divs.php
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
            $output .= $block['innerHTML'] ?? '';
            $output .= serialize_blocks($block['innerBlocks'] ?? []);
            $output .= '<!-- /wp:' . \substr($name, 5) . '-->';
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
<div class="code-pane">
  <div class="code-pane-header">
    <span>Before: Agent prompt engineering</span>
    <span class="badge-before">Fragile</span>
  </div>
  <div class="code-body">
    <div class="cm">/* prompt fragment trying to produce block markup */</div>
    <div style="margin-top:10px">Generate a hero section with a heading and button.</div>
  </div>
</div>
<div class="code-pane">
  <div class="code-pane-header">
    <span>After: Plain HTML in, blocks out</span>
    <span class="badge-after">Stable</span>
  </div>
  <div class="code-body">
    <div class="cm">&lt;!-- just describe the design --&gt;</div>
    <div style="margin-top:10px"><span class="tag">&lt;section</span> class="hero"<span class="tag">&gt;</span></div>
  </div>
</div>
<div class="ws-code"><span class="hl">studio-code</span> "Build a consulting firm site"</div>
<div class="ws-code">→ <span class="hl">tmp/static-site/index.html</span> created</div>
<div class="syntax-highlight code-body">
  <span class="cm">&lt;!-- Agent writes normal HTML --&gt;</span><br>
  <span class="tag">&lt;section</span> <span class="attr">class</span>=<span class="str">"hero"</span><span class="tag">&gt;</span><br>
  <span class="tag">&lt;/section&gt;</span>
</div>
<div class="code-output">
  &lt;!-- wp:group --&gt;<br>
  &lt;!-- wp:heading --&gt;<br>
  &lt;h1&gt;Launch Faster&lt;/h1&gt;
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$serialized = serialize_blocks($blocks);
$fallbacks = $collect_blocks($blocks, 'core/html');
$groups = $collect_blocks($blocks, 'core/group');
$paragraphs = $collect_blocks($blocks, 'core/paragraph');
$preformatted = $collect_blocks($blocks, 'core/preformatted');
$assert(\count($fallbacks) === 0, 'code-display-divs-do-not-fallback', $serialized);
$assert(\count($groups) >= 4, 'code-pane-wrappers-become-groups', $serialized);
$assert(\count($paragraphs) === 2, 'code-pane-headers-become-paragraphs', $serialized);
$assert(\count($preformatted) === 6, 'code-bodies-outputs-and-ws-code-become-preformatted', $serialized);
$assert(\str_contains($serialized, 'code-pane') && \str_contains($serialized, 'code-pane-header'), 'code-pane-classes-survive', $serialized);
$assert(\str_contains($serialized, 'Before: Agent prompt engineering') && \str_contains($serialized, 'Fragile'), 'before-header-text-survives', $serialized);
$assert(\str_contains($serialized, 'After: Plain HTML in, blocks out') && \str_contains($serialized, 'Stable'), 'after-header-text-survives', $serialized);
$assert(\str_contains($serialized, 'prompt fragment trying to produce block markup'), 'comment-like-code-text-survives', $serialized);
$assert(\str_contains($serialized, '&lt;section') && \str_contains($serialized, 'class="hero"'), 'escaped-html-code-survives', $serialized);
$assert(\str_contains($serialized, 'studio-code') && \str_contains($serialized, 'tmp/static-site/index.html'), 'ws-code-text-survives', $serialized);
$assert(\str_contains($serialized, 'wp-block-preformatted ws-code'), 'ws-code-class-survives', $serialized);
$assert(\str_contains($serialized, 'Agent writes normal HTML') && \str_contains($serialized, 'class</span>=<span class="str">"hero"'), 'syntax-highlight-code-body-survives', $serialized);
$assert(\str_contains($serialized, '&lt;!-- wp:group --&gt;') && \str_contains($serialized, '&lt;h1&gt;Launch Faster&lt;/h1&gt;'), 'code-output-text-survives', $serialized);
$assert(\str_contains($serialized, 'wp-block-preformatted syntax-highlight code-body') && \str_contains($serialized, 'wp-block-preformatted code-output'), 'display-body-output-classes-survive', $serialized);
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
