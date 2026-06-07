<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: simple definition lists become native list blocks.
 *
 * Run: php tests/smoke-definition-list-transforms.php
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
            return \in_array($name, ['core/heading', 'core/html', 'core/group', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
        return \trim(\strip_tags((string) $text));
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
<article class="product-card"><h3>Brownie Depth Set</h3><dl class="card-meta"><div class="meta-row"><dt class="meta-label">Best seller</dt><dd class="meta-value">Brownie Depth Set</dd></div><div class="meta-row"><dt class="meta-label">Use case</dt><dd class="meta-value">Glossy tops · dense crumb · deep cocoa</dd></div></dl></article>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$names = $flatten_block_names($blocks);
$definition_group = null;
foreach ($blocks as $block) {
    if (($block['blockName'] ?? '') !== 'core/group') {
        continue;
    }
    foreach ($block['innerBlocks'] ?? [] as $inner_block) {
        if (($inner_block['attrs']['className'] ?? '') === 'card-meta') {
            $definition_group = $inner_block;
            break 2;
        }
    }
}
$assert(null !== $definition_group, 'visual-definition-list-produces-group');
$assert(!\in_array('core/html', $names, \true), 'visual-definition-list-has-no-core-html', \implode(', ', $names));
$assert(!\in_array('core/list', $names, \true), 'visual-definition-list-is-not-bulleted-list', \implode(', ', $names));
$assert(\count($definition_group['innerBlocks'] ?? []) === 2, 'visual-definition-list-keeps-pair-count');
$assert(($definition_group['innerBlocks'][0]['attrs']['className'] ?? '') === 'meta-row', 'visual-definition-list-preserves-row-class');
$assert(($definition_group['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? '') === 'core/paragraph', 'visual-definition-list-term-is-paragraph');
$assert(($definition_group['innerBlocks'][0]['innerBlocks'][0]['attrs']['className'] ?? '') === 'meta-label', 'visual-definition-list-preserves-term-class');
$assert(($definition_group['innerBlocks'][0]['innerBlocks'][0]['attrs']['content'] ?? '') === 'Best seller', 'visual-definition-list-preserves-term-content');
$assert(($definition_group['innerBlocks'][0]['innerBlocks'][1]['attrs']['className'] ?? '') === 'meta-value', 'visual-definition-list-preserves-description-class');
$assert(($definition_group['innerBlocks'][0]['innerBlocks'][1]['attrs']['content'] ?? '') === 'Brownie Depth Set', 'visual-definition-list-preserves-description-content');
$direct_blocks = html_to_blocks_raw_handler(['HTML' => '<dl><dt>Origin</dt><dd>Charleston</dd></dl>']);
$assert(($direct_blocks[0]['blockName'] ?? '') === 'core/list', 'direct-definition-list-becomes-list');
$assert(($direct_blocks[0]['innerBlocks'][0]['attrs']['content'] ?? '') === 'Origin: Charleston', 'direct-definition-list-content');
$wrapper_stat_blocks = html_to_blocks_raw_handler(['HTML' => '<dl class="hero-stats" aria-label="Store highlights"><div><dt>5</dt><dd>workflow categories</dd></div><div><dt>18+</dt><dd>bench-ready tools</dd></div><div><dt>0</dt><dd>guesswork mornings</dd></div></dl>']);
$assert(\count($wrapper_stat_blocks) === 1, 'wrapped-stat-definition-list-produces-single-block');
$assert(($wrapper_stat_blocks[0]['blockName'] ?? '') === 'core/group', 'wrapped-stat-definition-list-becomes-group');
$assert(($wrapper_stat_blocks[0]['attrs']['className'] ?? '') === 'hero-stats', 'wrapped-stat-definition-list-preserves-class');
$assert(\count($wrapper_stat_blocks[0]['innerBlocks'] ?? []) === 3, 'wrapped-stat-definition-list-keeps-pair-count');
$assert(($wrapper_stat_blocks[0]['attrs']['ariaLabel'] ?? '') === 'Store highlights', 'wrapped-stat-definition-list-preserves-aria-label');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['content'] ?? '') === '5', 'wrapped-stat-definition-list-first-term');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][0]['innerBlocks'][1]['attrs']['content'] ?? '') === 'workflow categories', 'wrapped-stat-definition-list-first-description');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['content'] ?? '') === '18+', 'wrapped-stat-definition-list-second-term');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][1]['innerBlocks'][1]['attrs']['content'] ?? '') === 'bench-ready tools', 'wrapped-stat-definition-list-second-description');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][2]['innerBlocks'][0]['attrs']['content'] ?? '') === '0', 'wrapped-stat-definition-list-third-term');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][2]['innerBlocks'][1]['attrs']['content'] ?? '') === 'guesswork mornings', 'wrapped-stat-definition-list-third-description');
$complex_blocks = html_to_blocks_raw_handler(['HTML' => '<dl><div><dt>Term</dt><dd>Description</dd><p>Extra</p></div></dl>']);
$complex_names = $flatten_block_names($complex_blocks);
$assert(\in_array('core/html', $complex_names, \true), 'complex-definition-list-still-falls-back', \implode(', ', $complex_names));
if ($failures) {
    \fwrite(\STDERR, \implode("\n", $failures) . "\n");
    exit(1);
}
\fwrite(\STDOUT, "PASS: {$assertions} definition list assertions\n");
