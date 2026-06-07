<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: decorative visual clusters avoid broad core/html fallback.
 *
 * Run: php tests/smoke-decorative-visual-clusters.php
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
$flatten_block_names = static function (array $blocks) use (&$flatten_block_names): array {
    $names = [];
    foreach ($blocks as $block) {
        $names[] = $block['blockName'] ?? '';
        $names = \array_merge($names, $flatten_block_names($block['innerBlocks'] ?? []));
    }
    return $names;
};
$scroll_html = <<<'HTML'
<div class="ss-hero-scroll" aria-hidden="true">
  <div class="ss-hero-scroll-line"></div>
  <span>Scroll</span>
</div>
HTML;
$scroll_blocks = html_to_blocks_raw_handler(['HTML' => $scroll_html]);
$scroll_serialized = serialize_blocks($scroll_blocks);
$scroll_names = $flatten_block_names($scroll_blocks);
$assert(\str_contains($scroll_serialized, 'Scroll'), 'scroll-text-survives', $scroll_serialized);
$assert(\str_contains($scroll_serialized, 'ss-hero-scroll'), 'scroll-wrapper-class-survives', $scroll_serialized);
$assert(\in_array('core/group', $scroll_names, \true), 'scroll-wrapper-becomes-group', $scroll_serialized);
$assert(\in_array('core/paragraph', $scroll_names, \true), 'scroll-text-becomes-paragraph', $scroll_serialized);
$assert(!\in_array('core/html', $scroll_names, \true), 'scroll-has-no-html-fallback', $scroll_serialized);
$product_html = <<<'HTML'
<div class="ss-product-thumb ss-product-thumb-sourdough" aria-hidden="true">
  <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
    <ellipse cx="40" cy="48" rx="32" ry="22" fill="rgba(255,255,255,0.15)"/>
    <ellipse cx="40" cy="38" rx="24" ry="20" fill="rgba(255,255,255,0.2)"/>
    <path d="M26 42 Q40 22 54 42" stroke="rgba(255,255,255,0.4)" stroke-width="2" fill="none"/>
  </svg>
</div>
HTML;
$product_blocks = html_to_blocks_raw_handler(['HTML' => $product_html]);
$product_serialized = serialize_blocks($product_blocks);
$product_names = $flatten_block_names($product_blocks);
$product_fallbacks = $collect_blocks($product_blocks, 'core/html');
$assert(\in_array('core/group', $product_names, \true), 'product-wrapper-becomes-group', $product_serialized);
$assert(\str_contains($product_serialized, 'ss-product-thumb'), 'product-wrapper-class-survives', $product_serialized);
$assert(\count($product_fallbacks) === 1, 'product-only-svg-falls-back', $product_serialized);
$product_fallback_content = $product_fallbacks[0]['attrs']['content'] ?? '';
$assert(\str_starts_with(\trim($product_fallback_content), '<svg'), 'product-fallback-starts-at-svg', $product_fallback_content);
$assert(!\str_contains($product_fallback_content, 'ss-product-thumb'), 'product-fallback-does-not-wrap-thumb', $product_fallback_content);
$stars_html = <<<'HTML'
<div class="ss-quote-stars" aria-label="5 out of 5 stars">
  <span aria-hidden="true">&#9733;</span>
  <span aria-hidden="true">&#9733;</span>
  <span aria-hidden="true">&#9733;</span>
  <span aria-hidden="true">&#9733;</span>
  <span aria-hidden="true">&#9733;</span>
</div>
HTML;
$stars_blocks = html_to_blocks_raw_handler(['HTML' => $stars_html]);
$stars_serialized = serialize_blocks($stars_blocks);
$stars_names = $flatten_block_names($stars_blocks);
$star_paragraphs = $collect_blocks($stars_blocks, 'core/paragraph');
$star_content = $star_paragraphs[0]['attrs']['content'] ?? '';
$assert(\in_array('core/group', $stars_names, \true), 'stars-wrapper-becomes-group', $stars_serialized);
$assert(\str_contains($stars_serialized, 'ss-quote-stars'), 'stars-wrapper-class-survives', $stars_serialized);
$assert(\str_contains($stars_serialized, '5 out of 5 stars'), 'stars-aria-label-survives', $stars_serialized);
$assert(\substr_count(\html_entity_decode($star_content, \ENT_QUOTES, 'UTF-8'), '★') === 5, 'five-stars-survive', $star_content);
$assert(!\in_array('core/html', $stars_names, \true), 'stars-have-no-html-fallback', $stars_serialized);
$caption_only_figure_html = <<<'HTML'
<figure class="gallery-tile tile-script"><figcaption>Marked scripts at the table</figcaption></figure>
HTML;
$caption_only_figure_blocks = html_to_blocks_raw_handler(['HTML' => $caption_only_figure_html]);
$caption_only_figure_serialized = serialize_blocks($caption_only_figure_blocks);
$caption_only_figure_names = $flatten_block_names($caption_only_figure_blocks);
$assert(\in_array('core/group', $caption_only_figure_names, \true), 'caption-only-figure-becomes-group', $caption_only_figure_serialized);
$assert(\in_array('core/paragraph', $caption_only_figure_names, \true), 'caption-only-figure-caption-becomes-paragraph', $caption_only_figure_serialized);
$assert(\str_contains($caption_only_figure_serialized, 'gallery-tile tile-script'), 'caption-only-figure-class-survives', $caption_only_figure_serialized);
$assert(\str_contains($caption_only_figure_serialized, 'Marked scripts at the table'), 'caption-only-figure-caption-survives', $caption_only_figure_serialized);
$assert(!\in_array('core/html', $caption_only_figure_names, \true), 'caption-only-figure-has-no-html-fallback', $caption_only_figure_serialized);
$hearthline_gallery_html = <<<'HTML'
<div class="gallery-grid" aria-label="Illustrated views of the café">
  <figure class="photo-card photo-one"><figcaption>Corner windows and amber evening light</figcaption></figure>
  <figure class="photo-card photo-two"><figcaption>Hands learning a tile-laying game</figcaption></figure>
  <figure class="photo-card photo-three"><figcaption>Staff shelf tags by mood and group size</figcaption></figure>
</div>
HTML;
$hearthline_gallery_blocks = html_to_blocks_raw_handler(['HTML' => $hearthline_gallery_html]);
$hearthline_gallery_serialized = serialize_blocks($hearthline_gallery_blocks);
$hearthline_gallery_names = $flatten_block_names($hearthline_gallery_blocks);
$assert(!\in_array('core/html', $hearthline_gallery_names, \true), 'hearthline-gallery-has-no-html-fallback', $hearthline_gallery_serialized);
$assert(\substr_count($hearthline_gallery_serialized, 'photo-card') >= 3, 'hearthline-photo-card-classes-survive', $hearthline_gallery_serialized);
$assert(\str_contains($hearthline_gallery_serialized, 'Corner windows and amber evening light'), 'hearthline-first-caption-survives', $hearthline_gallery_serialized);
$assert(\str_contains($hearthline_gallery_serialized, 'Hands learning a tile-laying game'), 'hearthline-second-caption-survives', $hearthline_gallery_serialized);
$assert(\str_contains($hearthline_gallery_serialized, 'Staff shelf tags by mood and group size'), 'hearthline-third-caption-survives', $hearthline_gallery_serialized);
$nested_decorative_figure_html = <<<'HTML'
<figure role="listitem" class="gallery-card"><div class="paper-illustration" aria-hidden="true"><span></span><span></span><span></span></div><figcaption>Deep fiction shelves with handwritten shelf talkers.</figcaption></figure>
HTML;
$nested_decorative_figure_blocks = html_to_blocks_raw_handler(['HTML' => $nested_decorative_figure_html]);
$nested_decorative_figure_serialized = serialize_blocks($nested_decorative_figure_blocks);
$nested_decorative_figure_names = $flatten_block_names($nested_decorative_figure_blocks);
$assert(\in_array('core/group', $nested_decorative_figure_names, \true), 'nested-decorative-figure-becomes-group', $nested_decorative_figure_serialized);
$assert(\in_array('core/paragraph', $nested_decorative_figure_names, \true), 'nested-decorative-figure-caption-becomes-paragraph', $nested_decorative_figure_serialized);
$assert(\str_contains($nested_decorative_figure_serialized, 'gallery-card'), 'nested-decorative-figure-class-survives', $nested_decorative_figure_serialized);
$assert(\str_contains($nested_decorative_figure_serialized, 'paper-illustration'), 'nested-decorative-placeholder-class-survives', $nested_decorative_figure_serialized);
$assert(\str_contains($nested_decorative_figure_serialized, 'Deep fiction shelves with handwritten shelf talkers.'), 'nested-decorative-caption-survives', $nested_decorative_figure_serialized);
$assert(!\in_array('core/html', $nested_decorative_figure_names, \true), 'nested-decorative-figure-has-no-html-fallback', $nested_decorative_figure_serialized);
$linked_figure_html = <<<'HTML'
<figure class="product-card"><a href="/menu">View menu</a><figcaption>Menu tile</figcaption></figure>
HTML;
$linked_figure_blocks = html_to_blocks_raw_handler(['HTML' => $linked_figure_html]);
$linked_figure_names = $flatten_block_names($linked_figure_blocks);
$linked_figure_serialized = serialize_blocks($linked_figure_blocks);
$assert(!\in_array('core/group', $linked_figure_names, \true), 'functional-figure-child-does-not-use-decorative-group-transform', $linked_figure_serialized);
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
