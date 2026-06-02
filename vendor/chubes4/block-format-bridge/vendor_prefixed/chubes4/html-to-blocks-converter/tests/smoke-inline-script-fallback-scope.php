<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: inline script fallback stays scoped to the script node.
 *
 * Run: php tests/smoke-inline-script-fallback-scope.php
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
            return \in_array($name, ['core/group', 'core/heading', 'core/html', 'core/paragraph'], \true);
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
<div class="sc-page">
  <section class="sc-before">
    <h2>Before the script</h2>
    <p>This normal content should remain editable native blocks.</p>
  </section>
  <script>
  (function () {
    var els = document.querySelectorAll('.sc-reveal');
    els.forEach(function (el) { el.classList.add('sc-hidden'); });
  })();
  </script>
  <section class="sc-after">
    <h2>After the script</h2>
    <p>Content after the unsupported script should also convert normally.</p>
  </section>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$serialized = serialize_blocks($blocks);
$groups = $collect_blocks($blocks, 'core/group');
$headings = $collect_blocks($blocks, 'core/heading');
$paragraphs = $collect_blocks($blocks, 'core/paragraph');
$fallbacks = $collect_blocks($blocks, 'core/html');
$assert(\count($blocks) === 1 && ($blocks[0]['blockName'] ?? '') === 'core/group', 'root-page-becomes-group', $serialized);
$assert(\count($groups) >= 3, 'page-and-sections-become-groups', $serialized);
$assert(\count($headings) === 2, 'normal-headings-are-native', $serialized);
$assert(\count($paragraphs) === 2, 'normal-copy-is-native', $serialized);
$assert(\str_contains($serialized, 'Before the script'), 'before-heading-survives', $serialized);
$assert(\str_contains($serialized, 'After the script'), 'after-heading-survives', $serialized);
$assert(\str_contains($serialized, '<!-- wp:heading'), 'serialized-has-native-heading', $serialized);
$assert(\str_contains($serialized, '<!-- wp:paragraph'), 'serialized-has-native-paragraph', $serialized);
$assert(\count($fallbacks) === 1, 'only-script-falls-back-to-html', $serialized);
$fallback_content = $fallbacks[0]['attrs']['content'] ?? '';
$assert(\str_starts_with(\trim($fallback_content), '<script'), 'fallback-starts-at-script', $fallback_content);
$assert(\str_ends_with(\trim($fallback_content), '</script>'), 'fallback-ends-at-script', $fallback_content);
$assert(\str_contains($fallback_content, "document.querySelectorAll('.sc-reveal')"), 'fallback-preserves-script-content', $fallback_content);
$assert(!\str_contains($fallback_content, 'sc-page'), 'fallback-does-not-wrap-root-page', $fallback_content);
$assert(!\str_contains($fallback_content, 'sc-before'), 'fallback-does-not-wrap-before-section', $fallback_content);
$assert(!\str_contains($fallback_content, 'sc-after'), 'fallback-does-not-wrap-after-section', $fallback_content);
$assert(\count($fallback_events) === 1, 'script-fallback-emits-one-event', (string) \count($fallback_events));
$event = $fallback_events[0] ?? [];
$assert(($event[0] ?? '') === $fallback_content, 'event-html-is-scoped-script', \print_r($event, \true));
$assert(($event[1]['reason'] ?? '') === 'no_transform', 'event-reason-is-no-transform', \print_r($event, \true));
$assert(($event[1]['tag_name'] ?? '') === 'SCRIPT', 'event-tag-name-is-script', \print_r($event, \true));
$assert(($event[2]['blockName'] ?? '') === 'core/html', 'event-block-is-core-html', \print_r($event, \true));
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
