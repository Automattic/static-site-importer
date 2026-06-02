<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: common static-site chrome uses native blocks only where valid.
 *
 * Run: php tests/smoke-static-site-chrome.php
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
            return \in_array($name, ['core/group', 'core/html', 'core/list', 'core/list-item', 'core/paragraph', 'core/preformatted', 'core/quote'], \true);
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
            if ('core/html' === $name) {
                $output .= '<!-- wp:html -->' . ($block['attrs']['content'] ?? $block['innerHTML'] ?? '') . '<!-- /wp:html -->';
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
$html = <<<HTML
<header class="site">
  <div class="site-inner">
    <nav class="primary"><ul><li><a href="/">Home</a></li><li><a href="/manifesto/">Manifesto</a></li></ul></nav>
  </div>
</header>
<footer class="site">
  <div class="container">
    <div class="row">
      <div>© 2026 The Prompt Liberation Front. Hand-served by your filesystem.</div>
      <ul class="links"><li><a href="/manifesto/">Manifesto</a></li><li><a href="/proof/">Proof</a></li></ul>
    </div>
  </div>
</footer>
<pre class="prompt"><span class="label">Prompt</span>Generate a static HTML site.</pre>
HTML;
$serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $html]));
$assert(\str_contains($serialized, 'wp:group'), 'static-chrome-uses-group-blocks');
$assert(!\str_contains($serialized, 'wp:navigation'), 'static-nav-avoids-invalid-navigation-blocks', $serialized);
$assert(!\str_contains($serialized, '<!-- wp:html --><nav class="primary">'), 'static-nav-avoids-core-html-fallback', $serialized);
$assert(\str_contains($serialized, '<nav class="wp-block-group primary">'), 'static-nav-uses-group-nav-tag', $serialized);
$assert(\str_contains($serialized, 'wp:list'), 'footer-links-use-list-block');
$assert(\str_contains($serialized, 'The Prompt Liberation Front'), 'text-only-div-preserves-footer-copy');
$assert(\str_contains($serialized, 'class="wp-block-preformatted prompt"'), 'preformatted-rendered-html-preserves-source-class', $serialized);
$salt_star_html = <<<HTML
<nav class="site-nav" aria-label="Main navigation">
  <a href="#" class="nav-logo">Salt &amp; Star</a>
  <ul class="nav-links">
    <li><a href="#our-bakes">Our Bakes</a></li>
    <li><a href="#visit">Visit Us</a></li>
    <li><a href="#order">Order</a></li>
  </ul>
</nav>
HTML;
$salt_star_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $salt_star_html]));
$assert(!\str_contains($salt_star_serialized, 'wp:html'), 'salt-star-nav-avoids-core-html-fallback', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, '<nav class="wp-block-group site-nav" aria-label="Main navigation">'), 'salt-star-nav-preserves-wrapper', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, '<a href="#" class="nav-logo">Salt &amp; Star</a>'), 'salt-star-nav-preserves-logo-link', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, 'class="wp-block-list nav-links"'), 'salt-star-nav-preserves-list-class', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, 'href="#our-bakes"'), 'salt-star-nav-preserves-our-bakes-href', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, 'href="#visit"'), 'salt-star-nav-preserves-visit-href', $salt_star_serialized);
$assert(\str_contains($salt_star_serialized, 'href="#order"'), 'salt-star-nav-preserves-order-href', $salt_star_serialized);
$studio_code_nav_serialized = serialize_blocks(html_to_blocks_raw_handler(array('HTML' => '<nav><div class="nav-logo"><div class="dot"></div>Studio Code</div></nav>')));
$assert(!\str_contains($studio_code_nav_serialized, '<!-- wp:html -->'), 'studio-code-nav-logo-dot-avoids-core-html', $studio_code_nav_serialized);
$assert(\str_contains($studio_code_nav_serialized, '<nav class="wp-block-group">'), 'studio-code-nav-wrapper-survives', $studio_code_nav_serialized);
$assert(\str_contains($studio_code_nav_serialized, 'class="wp-block-group nav-logo"'), 'studio-code-nav-logo-wrapper-survives', $studio_code_nav_serialized);
$assert(\str_contains($studio_code_nav_serialized, 'Studio Code'), 'studio-code-nav-logo-text-survives', $studio_code_nav_serialized);
$assert(!\str_contains($studio_code_nav_serialized, 'class="wp-block-group dot"'), 'studio-code-nav-logo-dot-is-dropped', $studio_code_nav_serialized);
$inline_footer_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<footer>Hand-Coded · No Block Editor Was Harmed · Made With <span class="heart">🔥</span> And Spite</footer>']));
$assert(\str_contains($inline_footer_serialized, 'Hand-Coded'), 'inline-footer-preserves-leading-text', $inline_footer_serialized);
$assert(\str_contains($inline_footer_serialized, 'No Block Editor Was Harmed'), 'inline-footer-preserves-middle-text', $inline_footer_serialized);
$assert(\str_contains($inline_footer_serialized, 'Made With <span class="heart">🔥</span> And Spite'), 'inline-footer-preserves-mixed-inline-content', $inline_footer_serialized);
$text_div_footer_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<footer class="footer"><div class="footer-brand">Studio Code by Automattic</div><div class="footer-copy">Copyright 2026 Automattic Inc. All rights reserved.</div></footer>']));
$assert(\str_contains($text_div_footer_serialized, 'Studio Code by Automattic'), 'footer-brand-div-text-survives', $text_div_footer_serialized);
$assert(\str_contains($text_div_footer_serialized, 'Copyright 2026 Automattic Inc. All rights reserved.'), 'footer-copy-div-text-survives', $text_div_footer_serialized);
$assert(\str_contains($text_div_footer_serialized, 'wp:paragraph'), 'footer-text-divs-become-paragraphs', $text_div_footer_serialized);
$assert(!\str_contains($text_div_footer_serialized, '<div class="wp-block-group footer-brand">'), 'footer-brand-div-does-not-become-empty-wrapper', $text_div_footer_serialized);
$inspector_field_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div class="inspector-field"><label>Type</label><div class="inspector-field-value">core/cover</div></div>']));
$assert(!\str_contains($inspector_field_serialized, '<!-- wp:html -->'), 'inspector-field-label-avoids-core-html-fallback', $inspector_field_serialized);
$assert(\str_contains($inspector_field_serialized, '<p>Type</p>'), 'inspector-field-label-becomes-editable-text', $inspector_field_serialized);
$assert(\str_contains($inspector_field_serialized, 'core/cover'), 'inspector-field-value-survives', $inspector_field_serialized);
$form_label_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<label for="field-type">Type</label>']));
$assert(\str_contains($form_label_serialized, '<!-- wp:html -->'), 'form-label-for-stays-semantic-fallback', $form_label_serialized);
$badge_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div class="hero-badge"><span class="hero-badge-dot"></span>Now in Beta - Studio by Automattic</div>']));
$assert(!\str_contains($badge_serialized, '<!-- wp:html -->'), 'badge-cluster-avoids-core-html-fallback', $badge_serialized);
$assert(\str_contains($badge_serialized, 'hero-badge-dot'), 'badge-dot-class-survives', $badge_serialized);
$assert(\str_contains($badge_serialized, 'Now in Beta'), 'badge-text-survives', $badge_serialized);
$dot_cluster_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div class="diagram-dots"><div class="diagram-dot"></div><div class="diagram-dot"></div><div class="diagram-dot"></div></div>']));
$assert(!\str_contains($dot_cluster_serialized, '<!-- wp:html -->'), 'empty-dot-cluster-avoids-core-html-fallback', $dot_cluster_serialized);
$assert(\substr_count($dot_cluster_serialized, '<!-- wp:group') >= 4, 'empty-dot-cluster-uses-native-groups', $dot_cluster_serialized);
$quote_accent_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<blockquote class="quote-card"><div class="quote-accent-bar"></div><p>Blocks over fallbacks.</p></blockquote>']));
$assert(!\str_contains($quote_accent_serialized, '<!-- wp:html -->'), 'quote-accent-bar-avoids-core-html-fallback', $quote_accent_serialized);
$assert(!\str_contains($quote_accent_serialized, 'quote-accent-bar'), 'quote-accent-bar-is-dropped', $quote_accent_serialized);
$assert(\str_contains($quote_accent_serialized, 'Blocks over fallbacks.'), 'quote-accent-neighbor-text-survives', $quote_accent_serialized);
$decorative_inline_html = '<span class="topbar-logo-dot"></span><span style="width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;"></span>';
$decorative_inline_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $decorative_inline_html]));
$assert(!\str_contains($decorative_inline_serialized, '<!-- wp:html -->'), 'decorative-inline-spans-avoid-core-html-fallback', $decorative_inline_serialized);
$assert(\str_contains($decorative_inline_serialized, '<!-- wp:paragraph -->'), 'decorative-inline-spans-use-editable-paragraph', $decorative_inline_serialized);
$assert(\str_contains($decorative_inline_serialized, '<span class="topbar-logo-dot"></span>'), 'decorative-inline-class-dot-survives', $decorative_inline_serialized);
$assert(\str_contains($decorative_inline_serialized, 'width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;'), 'decorative-inline-style-dot-survives', $decorative_inline_serialized);
$parsed_decorative_inline_serialized = serialize_blocks(html_to_blocks_normalize_parsed_image_html_blocks([HTML_To_Blocks_Block_Factory::create_block('core/html', ['content' => '<span class="topbar-logo-dot"></span>']), HTML_To_Blocks_Block_Factory::create_block('core/html', ['content' => '<span style="width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;"></span>'])]));
$assert(!\str_contains($parsed_decorative_inline_serialized, '<!-- wp:html -->'), 'parsed-decorative-inline-spans-avoid-core-html-fallback', $parsed_decorative_inline_serialized);
$assert(\str_contains($parsed_decorative_inline_serialized, '<span class="topbar-logo-dot"></span>'), 'parsed-decorative-inline-class-dot-survives', $parsed_decorative_inline_serialized);
$assert(\str_contains($parsed_decorative_inline_serialized, 'width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;'), 'parsed-decorative-inline-style-dot-survives', $parsed_decorative_inline_serialized);
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
