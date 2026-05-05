<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: product-grid fixtures stay static until commerce context exists.
 *
 * Run: php tests/smoke-product-grid-context-gate.php
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
            return \in_array($name, ['core/button', 'core/buttons', 'core/group', 'core/heading', 'core/html', 'core/image', 'core/paragraph'], \true);
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
$unsupported_fallback_events = [];
$commerce_product_grid_events = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $unsupported_fallback_events, $commerce_product_grid_events;
        if ('html_to_blocks_unsupported_html_fallback' === $hook_name) {
            $unsupported_fallback_events[] = $args;
        }
        if ('html_to_blocks_commerce_product_grid_detected' === $hook_name) {
            $commerce_product_grid_events[] = $args;
        }
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = \array_diff_key($block['attrs'] ?? [], ['content' => \true, 'text' => \true]);
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
$product_grid_html = <<<'HTML'
<section class="shop-featured" aria-labelledby="shop-featured-title">
  <h2 id="shop-featured-title">Farm Stand Favorites</h2>
  <div class="product-grid" data-commerce-region="product-grid">
    <article class="product-card" data-product-slug="country-sourdough">
      <img class="product-card__image" src="https://cdn.example.com/products/country-sourdough.jpg" width="900" height="900" alt="Country sourdough loaf on a linen towel" />
      <span class="product-card__category">Bread</span>
      <span class="product-card__badge">Best Seller</span>
      <h3 class="product-card__name">Country Sourdough</h3>
      <p class="product-card__description">A 48-hour cold-fermented loaf milled from South Carolina red fife wheat.</p>
      <span class="product-card__price">$14 per loaf</span>
      <a class="product-card__cta" href="/shop/country-sourdough/">View loaf</a>
    </article>
    <article class="product-card" data-product-slug="sea-salt-butter">
      <img class="product-card__image" src="https://cdn.example.com/products/sea-salt-butter.jpg" width="900" height="900" alt="Cultured butter with flaky sea salt" />
      <span class="product-card__category">Dairy</span>
      <span class="product-card__badge">New Batch</span>
      <h3 class="product-card__name">Sea Salt Cultured Butter</h3>
      <p class="product-card__description">Slow-cultured cream churned small-batch and finished with flaky Atlantic salt.</p>
      <span class="product-card__price">$9 per roll</span>
      <a class="product-card__cta" href="/shop/sea-salt-butter/">View butter</a>
    </article>
    <article class="product-card product-card--seasonal" data-product-slug="strawberry-jam">
      <div class="product-card__image-placeholder" aria-label="Strawberry jam jar placeholder"><span>Seasonal</span></div>
      <span class="product-card__category">Pantry</span>
      <span class="product-card__badge">Limited</span>
      <h3 class="product-card__name">Strawberry Jam</h3>
      <p class="product-card__description">Peak-season berries simmered with lemon and cane sugar for bright spoonable fruit.</p>
      <span class="product-card__price">$11 per jar</span>
      <a class="product-card__cta" href="/shop/strawberry-jam/">View jam</a>
    </article>
  </div>
</section>
HTML;
// First context-enabled primitive stays editor-valid and materializable without creating Woo products.
$expected_context_enabled_shape = ['gate' => 'explicit commerce/product context only', 'block_names' => ['core/group', 'core/heading', 'core/group', 'core/group', 'core/image', 'core/paragraph', 'core/paragraph', 'core/heading', 'core/paragraph', 'core/paragraph', 'core/buttons', 'core/button'], 'notes' => 'Preserve static editable placeholders until SSI or another importer materializes product state.'];
$blocks = html_to_blocks_raw_handler(['HTML' => $product_grid_html]);
$serialized = serialize_blocks($blocks);
$names = $flatten_block_names($blocks);
$name_list = \implode(', ', $names);
$assert(\is_array($expected_context_enabled_shape) && !empty($expected_context_enabled_shape['block_names']), 'documents-context-enabled-shape');
$assert(\count($blocks) === 1, 'product-grid-single-wrapper');
$assert(($blocks[0]['blockName'] ?? '') === 'core/group', 'product-grid-wrapper-is-static-group');
$assert(!\in_array('core/html', $names, \true), 'product-grid-does-not-fallback-to-core-html', $name_list);
$assert(\count($unsupported_fallback_events) === 0, 'product-grid-emits-no-unsupported-fallback-events', (string) \count($unsupported_fallback_events));
$assert(!\preg_match('/(?:^|, )woocommerce\//', $name_list), 'product-grid-does-not-create-commerce-blocks', $name_list);
$assert(\strpos($serialized, '<!-- wp:woocommerce/') === \false, 'product-grid-serialization-has-no-woocommerce-blocks', $serialized);
$assert(\count($commerce_product_grid_events) === 0, 'no-context-emits-no-commerce-diagnostic', (string) \count($commerce_product_grid_events));
foreach (['product-grid', 'product-card', 'product-card__category', 'product-card__badge', 'product-card__price', 'product-card__cta', 'product-card__image-placeholder'] as $class_name) {
    $assert(\strpos($serialized, $class_name) !== \false, 'product-grid-preserves-' . $class_name, $serialized);
}
foreach (['Country Sourdough', '$14 per loaf', 'Bread', 'Best Seller', '/shop/country-sourdough/', 'Sea Salt Cultured Butter', '$9 per roll', 'Dairy', 'New Batch', '/shop/sea-salt-butter/', 'Strawberry Jam', '$11 per jar', 'Pantry', 'Limited', '/shop/strawberry-jam/'] as $snippet) {
    $assert(\strpos($serialized, $snippet) !== \false, 'product-grid-preserves-' . \preg_replace('/[^a-z0-9]+/i', '-', \strtolower($snippet)), $serialized);
}
$assert(\substr_count($serialized, '<!-- wp:image') === 2, 'product-grid-preserves-two-images', $serialized);
$assert(\substr_count($serialized, 'product-card__image-placeholder') >= 1, 'product-grid-preserves-one-placeholder', $serialized);
$assert(\substr_count($serialized, '<h3 class="wp-block-heading product-card__name">') === 3, 'product-grid-preserves-three-product-name-headings', $serialized);
$assert(\substr_count($serialized, 'product-card__price') === 3, 'product-grid-preserves-three-prices', $serialized);
$assert(\substr_count($serialized, 'product-card__cta') === 3, 'product-grid-preserves-three-ctas', $serialized);
$commerce_product_grid_events = [];
$context_blocks = html_to_blocks_raw_handler(['HTML' => $product_grid_html, 'context' => ['commerce' => ['source' => 'static-site-importer']]]);
$context_serialized = serialize_blocks($context_blocks);
$context_names = $flatten_block_names($context_blocks);
$context_name_list = \implode(', ', $context_names);
$commerce_diagnostic = $commerce_product_grid_events[0][0] ?? [];
$assert(\count($commerce_product_grid_events) === 1, 'context-emits-one-commerce-diagnostic', (string) \count($commerce_product_grid_events));
$assert(($commerce_diagnostic['type'] ?? '') === 'commerce_product_grid', 'context-diagnostic-type', \json_encode($commerce_diagnostic));
$assert(($commerce_diagnostic['status'] ?? '') === 'static_editable_placeholder', 'context-diagnostic-status', \json_encode($commerce_diagnostic));
$assert(($commerce_diagnostic['product_count'] ?? 0) === 3, 'context-diagnostic-product-count', \json_encode($commerce_diagnostic));
$assert(($commerce_diagnostic['products'][0]['slug'] ?? '') === 'country-sourdough', 'context-diagnostic-first-product-slug', \json_encode($commerce_diagnostic));
$assert(($commerce_diagnostic['products'][1]['name'] ?? '') === 'Sea Salt Cultured Butter', 'context-diagnostic-second-product-name', \json_encode($commerce_diagnostic));
$assert(($commerce_diagnostic['products'][2]['placeholder'] ?? \false) === \true, 'context-diagnostic-third-product-placeholder', \json_encode($commerce_diagnostic));
$assert(!\in_array('core/html', $context_names, \true), 'context-product-grid-does-not-fallback-to-core-html', $context_name_list);
$assert(!\preg_match('/(?:^|, )woocommerce\//', $context_name_list), 'context-product-grid-does-not-create-commerce-blocks', $context_name_list);
$assert(\strpos($context_serialized, '<!-- wp:woocommerce/') === \false, 'context-product-grid-serialization-has-no-woocommerce-blocks', $context_serialized);
$assert(\strpos($context_serialized, 'Strawberry Jam') !== \false, 'context-product-grid-remains-editor-valid-static-output', $context_serialized);
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
