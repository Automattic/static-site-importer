<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: branded links with decorative nested spans convert to native blocks.
 *
 * Run: php tests/smoke-branded-link-spans.php
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
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\parse_blocks')) {
    function parse_blocks($content)
    {
        if (\preg_match('/^<!-- wp:freeform -->(.*)<!-- \/wp:freeform -->$/s', \trim((string) $content), $matches)) {
            return [['blockName' => 'core/freeform', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $matches[1], 'innerContent' => [$matches[1]]]];
        }
        return [];
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            if ('core/html' === $name) {
                $output .= '<!-- wp:html -->' . ($block['attrs']['content'] ?? $block['innerHTML'] ?? '') . '<!-- /wp:html -->';
                continue;
            }
            if ('core/freeform' === $name) {
                $output .= '<!-- wp:freeform -->' . ($block['innerHTML'] ?? '') . '<!-- /wp:freeform -->';
                continue;
            }
            $output .= '<!-- wp:' . \substr($name, 5) . ' -->';
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
$brand_cases = ['simple-span-brand' => ['html' => '<a class="brand" href="#top" aria-label="Studio Code home"><span class="mark"></span><span>Studio Code</span></a>', 'snippets' => ['href="#top"', 'aria-label="Studio Code home"', 'class="brand"', '<span class="mark"></span>', '<span>Studio Code</span>']], 'formatted-span-brand' => ['html' => '<a class="brand" href="#top" aria-label="Wickstead Refill Works home"><span class="brand-mark" aria-hidden="true">W</span><span><strong>Wickstead</strong><em>Refill Works</em></span></a>', 'snippets' => ['href="#top"', 'aria-label="Wickstead Refill Works home"', 'class="brand"', '<span class="brand-mark" aria-hidden="true">W</span>', '<strong>Wickstead</strong>', '<em>Refill Works</em>']], 'div-wrapped-logo-brand' => ['html' => '<a class="footer-logo" href="#"><div class="footer-logo-mark"><img src="/logo.svg" alt="" decoding="async" width="16" height="16" aria-hidden="true"></div>Relay Atlas</a>', 'snippets' => ['href="#"', 'class="footer-logo"', '<span class="footer-logo-mark"><img src="/logo.svg" alt="" decoding="async" width="16" height="16" aria-hidden="true"></span>', 'Relay Atlas']]];
foreach ($brand_cases as $case_name => $case) {
    foreach ([$case['html'], '<!-- wp:freeform -->' . $case['html'] . '<!-- /wp:freeform -->'] as $index => $html) {
        $serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $html]));
        $label = $case_name . '-' . (0 === $index ? 'raw' : 'freeform');
        $assert(!\str_contains($serialized, '<!-- wp:html -->'), $label . '-avoids-core-html', $serialized);
        $assert(!\str_contains($serialized, '<!-- wp:freeform -->'), $label . '-avoids-core-freeform', $serialized);
        $assert(\str_contains($serialized, '<!-- wp:paragraph'), $label . '-uses-paragraph-block', $serialized);
        foreach ($case['snippets'] as $snippet) {
            $assert(\str_contains($serialized, $snippet), $label . '-preserves-' . \md5($snippet), $serialized);
        }
        $assert(!\str_contains($serialized, '<div'), $label . '-normalizes-block-wrappers', $serialized);
    }
}
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
