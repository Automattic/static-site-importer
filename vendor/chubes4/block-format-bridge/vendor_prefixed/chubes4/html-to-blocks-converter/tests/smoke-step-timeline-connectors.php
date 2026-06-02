<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: step timeline wrappers with connector arrows recurse to native blocks.
 *
 * Run: php tests/smoke-step-timeline-connectors.php
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
$html = <<<'HTML'
<div class="sc-steps">
  <div class="sc-step sc-reveal">
    <span class="sc-step-num">01 &mdash; Write</span>
    <h3 class="sc-step-title">Describe your site in plain HTML</h3>
    <p class="sc-step-desc">Use any HTML you like &mdash; semantic tags, CSS classes, full creative freedom. No block syntax, no page builder constraints. Just code.</p>
  </div>
  <div class="sc-step-connector">&rarr;</div>
  <div class="sc-step sc-reveal">
    <span class="sc-step-num">02 &mdash; Convert</span>
    <h3 class="sc-step-title">Studio converts it to blocks</h3>
    <p class="sc-step-desc">Studio Code intelligently maps your HTML structure to the right WordPress core blocks &mdash; headings, paragraphs, images, buttons, groups, and more.</p>
  </div>
  <div class="sc-step-connector">&rarr;</div>
  <div class="sc-step sc-reveal">
    <span class="sc-step-num">03 &mdash; Edit</span>
    <h3 class="sc-step-title">Refine in the Site Editor</h3>
    <p class="sc-step-desc">Every converted block is fully editable. Hand the site to a client, continue building visually &mdash; zero re-work, zero lock-in.</p>
  </div>
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$serialized = serialize_blocks($blocks);
$names = $flatten_block_names($blocks);
$assert(\count($blocks) === 1, 'single-step-timeline-wrapper');
$assert(($blocks[0]['blockName'] ?? '') === 'core/group', 'timeline-wrapper-is-group');
$assert(!\in_array('core/html', $names, \true), 'timeline-does-not-use-core-html', \implode(', ', $names));
$assert(\substr_count(\implode(',', $names), 'core/group') === 4, 'outer-and-step-wrappers-are-groups', \implode(', ', $names));
$assert(\substr_count(\implode(',', $names), 'core/paragraph') === 8, 'step-numbers-descriptions-and-connectors-are-paragraphs', \implode(', ', $names));
$assert(\substr_count(\implode(',', $names), 'core/heading') === 3, 'step-titles-are-headings', \implode(', ', $names));
foreach (['sc-steps', 'sc-step sc-reveal', 'sc-step-connector', 'sc-step-num', 'sc-step-title', 'sc-step-desc', '01 &mdash; Write', '02 &mdash; Convert', '03 &mdash; Edit', 'Describe your site in plain HTML', 'Studio converts it to blocks', 'Refine in the Site Editor', 'Use any HTML you like', 'Studio Code intelligently maps your HTML structure', 'Every converted block is fully editable'] as $expected) {
    $assert(\strpos($serialized, $expected) !== \false, 'preserves-' . \substr(\md5($expected), 0, 8), 'Missing: ' . $expected);
}
$assert(\substr_count($serialized, '&rarr;') === 2, 'connector-arrows-preserved', $serialized);
$assert(!\str_contains($serialized, '<!-- wp:html --><div class="sc-steps">'), 'wrapper-is-not-core-html-fallback', $serialized);
$empty_connector_html = <<<'HTML'
<div class="workflow-steps">
  <div class="workflow-step"><p>Plan</p></div>
  <div class="step-connector"></div>
  <div class="workflow-step"><p>Build</p></div>
</div>
HTML;
$empty_connector_blocks = html_to_blocks_raw_handler(['HTML' => $empty_connector_html]);
$empty_connector_serialized = serialize_blocks($empty_connector_blocks);
$empty_connector_names = $flatten_block_names($empty_connector_blocks);
$assert(!\in_array('core/html', $empty_connector_names, \true), 'empty-connectors-do-not-use-core-html', \implode(', ', $empty_connector_names));
$assert(\substr_count(\implode(',', $empty_connector_names), 'core/group') === 4, 'empty-connectors-are-preserved-as-groups', \implode(', ', $empty_connector_names));
$assert(\str_contains($empty_connector_serialized, 'step-connector'), 'empty-connector-class-survives', $empty_connector_serialized);
$assert(\str_contains($empty_connector_serialized, 'Plan'), 'empty-connector-neighbor-before-survives', $empty_connector_serialized);
$assert(\str_contains($empty_connector_serialized, 'Build'), 'empty-connector-neighbor-after-survives', $empty_connector_serialized);
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
