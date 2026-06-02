<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: testimonial figure blockquotes convert without raw HTML fallback.
 *
 * Run: php tests/smoke-testimonial-figure-blockquote.php
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
            return \in_array($name, ['core/group', 'core/html', 'core/paragraph', 'core/quote'], \true);
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
<figure class="sc-quote__figure">
  <blockquote class="sc-quote__text">
    &ldquo;We used to spend half our prompt budget teaching the agent what a <code>wp:cover</code> block looks like. Now we just say &lsquo;build a hero&rsquo; and Studio Code handles the rest.&rdquo;
  </blockquote>
  <figcaption class="sc-quote__attribution">
    <span class="sc-quote__name">Studio Team</span>
    <span class="sc-quote__role">Automattic &mdash; Internal Dogfooding</span>
  </figcaption>
</figure>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$flat = $flatten_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$serialized = \html_entity_decode(serialize_blocks($blocks), \ENT_QUOTES, 'UTF-8');
$class_names = \array_filter(\array_map(static function ($block) {
    return $block['attrs']['className'] ?? '';
}, $flat));
$assert(\count($blocks) === 1, 'testimonial-single-top-level-wrapper', (string) \count($blocks));
$assert(!\in_array('core/html', $names, \true), 'testimonial-does-not-use-core-html', \implode(', ', $names));
$assert(!\str_contains($serialized, 'wp:html'), 'testimonial-serialized-has-no-wp-html', $serialized);
$assert(\count($fallback_events) === 0, 'testimonial-emits-no-fallback-events', (string) \count($fallback_events));
$assert(\in_array('core/group', $names, \true), 'testimonial-figure-becomes-group', \implode(', ', $names));
$assert(\in_array('core/quote', $names, \true), 'testimonial-blockquote-becomes-quote', \implode(', ', $names));
$assert(\in_array('core/paragraph', $names, \true), 'testimonial-attribution-becomes-paragraph', \implode(', ', $names));
$assert(\str_contains($serialized, 'wp:cover'), 'testimonial-inline-code-text-survives', $serialized);
$assert(\str_contains($serialized, '<code>wp:cover</code>'), 'testimonial-inline-code-markup-survives', $serialized);
$assert(\str_contains($serialized, 'Studio Team'), 'testimonial-attribution-name-survives', $serialized);
$assert(\str_contains($serialized, 'Automattic — Internal Dogfooding'), 'testimonial-attribution-role-survives', $serialized);
$assert(\in_array('sc-quote__figure', $class_names, \true), 'testimonial-figure-class-survives', \implode(', ', $class_names));
$assert(\in_array('sc-quote__text', $class_names, \true), 'testimonial-quote-class-survives', \implode(', ', $class_names));
$assert(\in_array('sc-quote__attribution', $class_names, \true), 'testimonial-attribution-class-survives', \implode(', ', $class_names));
$assert(\str_contains($serialized, 'sc-quote__name'), 'testimonial-name-class-survives', $serialized);
$assert(\str_contains($serialized, 'sc-quote__role'), 'testimonial-role-class-survives', $serialized);
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
