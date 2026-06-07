<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: Loom & Larder SVG and patch chrome avoid unsupported HTML fallback.
 *
 * Run: php tests/smoke-loom-larder-fallbacks.php
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
            return \in_array($name, ['core/group', 'core/html', 'html-to-blocks/svg-icon'], \true);
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
            $attrs = \array_diff_key($block['attrs'] ?? [], ['content' => \true, 'svg' => \true, 'metadata' => \true]);
            $attrs_json = empty($attrs) ? '' : ' ' . \json_encode($attrs, \JSON_UNESCAPED_SLASHES);
            if ('core/html' === $name) {
                $output .= '<!-- wp:html -->' . ($block['attrs']['content'] ?? $block['innerHTML'] ?? '') . '<!-- /wp:html -->';
                continue;
            }
            $output .= '<!-- wp:' . (\str_starts_with($name, 'core/') ? \substr($name, 5) : $name) . $attrs_json . ' -->';
            $output .= $block['innerContent'][0] ?? $block['innerHTML'] ?? '';
            $output .= serialize_blocks($block['innerBlocks'] ?? []);
            $inner_content = $block['innerContent'] ?? [];
            $output .= \end($inner_content) ? \end($inner_content) : '';
            $output .= '<!-- /wp:' . (\str_starts_with($name, 'core/') ? \substr($name, 5) : $name) . ' -->';
        }
        return $output;
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-svg-icon-classifier.php';
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
$flatten_class_names = static function (array $blocks) use (&$flatten_class_names): array {
    $class_names = [];
    foreach ($blocks as $block) {
        if (!empty($block['attrs']['className'])) {
            $class_names[] = $block['attrs']['className'];
        }
        $class_names = \array_merge($class_names, $flatten_class_names($block['innerBlocks'] ?? []));
    }
    return $class_names;
};
$svg_html = <<<'HTML'
<svg class="cloth-study cloth-one" viewBox="0 0 320 240" role="img" aria-labelledby="cloth-one-title">
  <title id="cloth-one-title">Folded handwoven bread cloth with visible selvedge</title>
  <defs>
    <pattern id="plainWeave" width="18" height="18" patternUnits="userSpaceOnUse">
      <path d="M0 4h18M0 13h18M4 0v18M13 0v18" />
    </pattern>
  </defs>
  <path class="shadow" d="M44 60h198c18 0 34 16 34 34v87c0 16-14 29-30 29H56c-14 0-25-11-25-25V73c0-7 6-13 13-13z"></path>
  <path class="cloth-fill" d="M40 50h198c18 0 34 16 34 34v87c0 16-14 29-30 29H52c-14 0-25-11-25-25V63c0-7 6-13 13-13z"></path>
  <path class="cloth-pattern" d="M40 50h198c18 0 34 16 34 34v87c0 16-14 29-30 29H52c-14 0-25-11-25-25V63c0-7 6-13 13-13z" fill="url(#plainWeave)"></path>
  <path class="fold" d="M63 50c36 42 30 96 6 150M170 51c17 28 22 75 10 149"></path>
  <path class="selvedge" d="M252 82v86"></path>
  <path class="mending" d="M102 141l11-10 11 10 11-10 11 10"></path>
</svg>
HTML;
$patch_html = <<<'HTML'
<div class="repair-visual" aria-hidden="true">
  <div class="patch patch-a"></div>
  <div class="patch patch-b"></div>
</div>
HTML;
$svg_blocks = html_to_blocks_raw_handler(['HTML' => $svg_html]);
$patch_blocks = html_to_blocks_raw_handler(['HTML' => $patch_html]);
$serialized = serialize_blocks(\array_merge($svg_blocks, $patch_blocks));
$fallbacks = \array_merge($collect_blocks($svg_blocks, 'core/html'), $collect_blocks($patch_blocks, 'core/html'));
$svg_placeholds = $collect_blocks($svg_blocks, 'html-to-blocks/svg-icon');
$class_names = $flatten_class_names($patch_blocks);
$assert(\count($fallbacks) === 0, 'loom-larder-fragments-do-not-use-core-html', $serialized);
$assert(\count($fallback_events) === 0, 'loom-larder-fragments-emit-no-fallback-events', (string) \count($fallback_events));
$assert(\count($svg_placeholds) === 1, 'cloth-svg-becomes-placeholder', $serialized);
$assert(($svg_placeholds[0]['attrs']['metadata']['kind'] ?? '') === 'inline-svg-illustration', 'cloth-svg-is-classified-as-illustration', \var_export($svg_placeholds[0]['attrs']['metadata'] ?? [], \true));
$assert(\str_contains($svg_placeholds[0]['attrs']['svg'] ?? '', '<pattern id="plainWeave"'), 'cloth-svg-pattern-is-preserved', $svg_placeholds[0]['attrs']['svg'] ?? '');
$assert(\str_contains($svg_placeholds[0]['attrs']['svg'] ?? '', 'fill="url(#plainWeave)"'), 'cloth-svg-local-fill-reference-is-preserved', $svg_placeholds[0]['attrs']['svg'] ?? '');
$assert(\in_array('repair-visual', $class_names, \true), 'repair-wrapper-class-survives', \implode(', ', $class_names));
$assert(\in_array('patch patch-a', $class_names, \true), 'patch-a-class-survives', \implode(', ', $class_names));
$assert(\in_array('patch patch-b', $class_names, \true), 'patch-b-class-survives', \implode(', ', $class_names));
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
