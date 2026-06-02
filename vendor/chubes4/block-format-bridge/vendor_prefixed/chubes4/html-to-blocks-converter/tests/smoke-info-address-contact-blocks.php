<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: info blocks with hours, address, and contact links convert natively.
 *
 * Run: php tests/smoke-info-address-contact-blocks.php
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
            return \in_array($name, ['core/group', 'core/heading', 'core/html', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
$flatten_block_names = static function (array $blocks) use (&$flatten_block_names): array {
    $names = [];
    foreach ($blocks as $block) {
        $names[] = $block['blockName'] ?? '';
        $names = \array_merge($names, $flatten_block_names($block['innerBlocks'] ?? []));
    }
    return $names;
};
$fixtures = ['hours' => ['html' => <<<'HTML'
<div class="ss-info-block ss-reveal">
  <h3>Hours</h3>
  <ul class="ss-hours-list">
    <li><span class="day">Tuesday &ndash; Friday</span><span>7:00 am &ndash; 3:00 pm</span></li>
    <li><span class="day">Saturday</span><span>7:00 am &ndash; 2:00 pm</span></li>
    <li><span class="day">Sunday</span><span>8:00 am &ndash; 1:00 pm</span></li>
    <li><span class="day">Monday</span><span>Closed</span></li>
  </ul>
</div>
HTML
, 'expected' => ['Hours', '<span class="day">Tuesday &ndash; Friday</span><span>7:00 am &ndash; 3:00 pm</span>', '<span class="day">Saturday</span><span>7:00 am &ndash; 2:00 pm</span>', '<span class="day">Sunday</span><span>8:00 am &ndash; 1:00 pm</span>', '<span class="day">Monday</span><span>Closed</span>']], 'contact' => ['html' => <<<'HTML'
<div class="ss-info-block ss-reveal">
  <h3>Find Us</h3>
  <address class="ss-address">
    <strong>Salt &amp; Star Bakery</strong>
    412 King Street<br>
    Charleston, SC 29403
  </address>
  <a href="tel:+18435550192" class="ss-contact-link">+1 (843) 555-0192</a><br><br>
  <a href="mailto:hello@saltandstar.com" class="ss-contact-link">hello@saltandstar.com</a>
</div>
HTML
, 'expected' => ['Find Us', '<strong>Salt &amp; Star Bakery</strong>', '412 King Street<br>', 'Charleston, SC 29403', 'href="tel:+18435550192"', '+1 (843) 555-0192', 'href="mailto:hello@saltandstar.com"', 'hello@saltandstar.com']]];
foreach ($fixtures as $name => $fixture) {
    $blocks = html_to_blocks_raw_handler(['HTML' => $fixture['html']]);
    $serialized = serialize_blocks($blocks);
    $names = $flatten_block_names($blocks);
    $assert(\count($blocks) === 1, $name . '-single-top-level-wrapper');
    $assert(!\in_array('core/html', $names, \true), $name . '-does-not-fallback-to-core-html', $serialized);
    $assert(\in_array('core/group', $names, \true), $name . '-uses-group-for-info-wrapper', \implode(',', $names));
    $assert(\in_array('core/heading', $names, \true), $name . '-heading-block-created', \implode(',', $names));
    foreach ($fixture['expected'] as $expected) {
        $assert(\strpos($serialized, $expected) !== \false, $name . '-preserves-' . \substr(\md5($expected), 0, 8), 'Missing: ' . $expected . "\n" . $serialized);
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
