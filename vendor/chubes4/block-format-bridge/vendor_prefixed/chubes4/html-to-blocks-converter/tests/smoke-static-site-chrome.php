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
            return \in_array($name, ['core/button', 'core/buttons', 'core/details', 'core/heading', 'core/group', 'core/heading', 'core/html', 'core/image', 'core/list', 'core/list-item', 'core/paragraph', 'core/preformatted', 'core/quote'], \true);
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
            $inner_blocks = $block['innerBlocks'] ?? [];
            $inner_content = $block['innerContent'] ?? [];
            $inner_index = 0;
            if ([] === $inner_content) {
                $output .= $block['innerHTML'] ?? '';
            } else {
                foreach ($inner_content as $content) {
                    if (null === $content) {
                        $output .= serialize_blocks([$inner_blocks[$inner_index] ?? []]);
                        $inner_index++;
                        continue;
                    }
                    $output .= $content;
                }
            }
            $output .= '<!-- /wp:' . \substr($name, 5) . ' -->';
        }
        return $output;
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_smoke_block_names')) {
    function html_to_blocks_smoke_block_names(array $blocks): array
    {
        $names = [];
        foreach ($blocks as $block) {
            $names[] = $block['blockName'] ?? '';
            $names = \array_merge($names, html_to_blocks_smoke_block_names($block['innerBlocks'] ?? []));
        }
        return $names;
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
$restaurant_nav_html = <<<HTML
<nav class="nav container" aria-label="Primary navigation">
  <a class="brand" href="index.html"><img src="assets/img/ember-rye-mark.svg" alt="" width="46" height="46"><span><strong>Ember &amp; Rye</strong><small>Wood-Fired Pizza</small></span></a>
  <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-menu"><span class="sr-only">Toggle navigation</span><span></span><span></span><span></span></button>
  <ul class="nav-links"><li><a href="menu.html">Menu</a></li><li><a href="reservations.html">Reservations</a></li></ul>
</nav>
HTML;
$restaurant_nav_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $restaurant_nav_html]));
$assert(!\str_contains($restaurant_nav_serialized, '<!-- wp:html -->'), 'restaurant-nav-toggle-avoids-core-html-fallback', $restaurant_nav_serialized);
$assert(\str_contains($restaurant_nav_serialized, '<nav class="wp-block-group nav container" aria-label="Primary navigation">'), 'restaurant-nav-wrapper-survives', $restaurant_nav_serialized);
$assert(\str_contains($restaurant_nav_serialized, 'class="wp-block-group nav-toggle"'), 'restaurant-nav-toggle-becomes-native-chrome', $restaurant_nav_serialized);
$assert(\str_contains($restaurant_nav_serialized, '<a class="brand" href="index.html">'), 'restaurant-nav-brand-link-survives', $restaurant_nav_serialized);
$assert(\str_contains($restaurant_nav_serialized, 'class="wp-block-list nav-links"'), 'restaurant-nav-links-survive', $restaurant_nav_serialized);
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
$ember_nav_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<nav class="nav container" aria-label="Primary navigation"><a class="brand" href="index.html" aria-label="Ember &amp; Rye home"><img src="assets/img/ember-rye-mark.svg" alt="" width="46" height="46"><span><strong>Ember &amp; Rye</strong><small>Wood-Fired Pizza</small></span></a><button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-menu"><span class="sr-only">Toggle navigation</span><span></span><span></span><span></span></button><div class="nav-menu" id="primary-menu"><a href="index.html" data-nav="home">Home</a><a href="menu.html" data-nav="menu">Menu</a><a href="reservations.html" data-nav="reservations">Reservations</a><a href="private-events.html" data-nav="events">Private Events</a><a href="contact.html" data-nav="contact">Contact</a><a class="btn btn-small" href="reservations.html">Reserve</a></div></nav>']));
$assert(!\str_contains($ember_nav_serialized, '<!-- wp:html -->'), 'ember-nav-avoids-core-html-fallback', $ember_nav_serialized);
$assert(\str_contains($ember_nav_serialized, '<nav class="wp-block-group nav container" aria-label="Primary navigation">'), 'ember-nav-preserves-wrapper', $ember_nav_serialized);
$assert(\str_contains($ember_nav_serialized, 'Ember &amp; Rye'), 'ember-nav-brand-text-survives', $ember_nav_serialized);
$assert(\str_contains($ember_nav_serialized, 'href="reservations.html"'), 'ember-nav-links-survive', $ember_nav_serialized);
$ember_footer_column_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div><h2>Visit</h2><p>1247 Hearthside Ave<br>Maplewood, OR 97205</p><a href="contact.html">Directions</a></div>']));
$assert(!\str_contains($ember_footer_column_serialized, '<!-- wp:html -->'), 'ember-footer-column-avoids-core-html-fallback', $ember_footer_column_serialized);
$assert(\str_contains($ember_footer_column_serialized, '1247 Hearthside Ave'), 'ember-footer-column-copy-survives', $ember_footer_column_serialized);
$assert(\str_contains($ember_footer_column_serialized, 'href="contact.html"'), 'ember-footer-column-link-survives', $ember_footer_column_serialized);
$ember_hero_copy_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div class="hero-copy reveal"><p class="eyebrow">Neighborhood hearth • sourdough crust • seasonal toppings</p><h1>Wood-fired pizza with a warm seat at the table.</h1><p>Ember &amp; Rye brings blistered, naturally leavened pies, market-driven small plates, and easy hospitality to the heart of the neighborhood.</p><div class="hero-actions"><a class="btn" href="reservations.html">Book a Table</a><a class="btn btn-ghost" href="menu.html">View Menu</a></div></div>']));
$assert(!\str_contains($ember_hero_copy_serialized, '<!-- wp:html -->'), 'ember-hero-copy-avoids-core-html-fallback', $ember_hero_copy_serialized);
$assert(\str_contains($ember_hero_copy_serialized, 'Wood-fired pizza with a warm seat at the table.'), 'ember-hero-heading-survives', $ember_hero_copy_serialized);
$assert(\str_contains($ember_hero_copy_serialized, 'Book a Table'), 'ember-hero-cta-survives', $ember_hero_copy_serialized);
$ember_rye_footer_html = <<<HTML
<footer class="site-footer">
  <div><a class="brand footer-brand" href="index.html"><img src="assets/img/ember-rye-mark.svg" alt="" width="42" height="42"><span><strong>Ember &amp; Rye</strong><small>Wood-Fired Pizza</small></span></a><p>Warm hospitality, blistered crust, and neighborhood energy nightly.</p></div>
  <div><h2>Visit</h2><p>1247 Hearthside Ave<br>Maplewood, OR 97205</p><a href="contact.html">Directions</a></div>
  <div><h2>Hours</h2><p>Tue–Thu 4–10pm<br>Fri–Sat 4–11pm<br>Sun 3–9pm</p></div>
  <div><h2>Connect</h2><p><a href="tel:+15035550184">(503) 555-0184</a><br><a href="mailto:hello@emberandrye.example">hello@emberandrye.example</a></p></div>
</footer>
HTML;
$ember_rye_footer_blocks = html_to_blocks_raw_handler(['HTML' => $ember_rye_footer_html]);
$ember_rye_footer_names = html_to_blocks_smoke_block_names($ember_rye_footer_blocks);
$ember_rye_footer_serialized = serialize_blocks($ember_rye_footer_blocks);
$assert(!\in_array('core/html', $ember_rye_footer_names, \true), 'ember-rye-footer-avoids-core-html-blocks', \implode(', ', $ember_rye_footer_names));
$assert(!\str_contains($ember_rye_footer_serialized, '<!-- wp:html -->'), 'ember-rye-footer-serialized-has-no-wp-html', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'class="brand footer-brand"'), 'ember-rye-brand-class-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'href="index.html"'), 'ember-rye-brand-link-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'assets/img/ember-rye-mark.svg'), 'ember-rye-logo-url-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, '<strong>Ember &amp; Rye</strong><small>Wood-Fired Pizza</small>'), 'ember-rye-brand-text-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, '1247 Hearthside Ave<br>Maplewood, OR 97205'), 'ember-rye-visit-text-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'Tue–Thu 4–10pm<br>Fri–Sat 4–11pm<br>Sun 3–9pm'), 'ember-rye-hours-text-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'href="contact.html"'), 'ember-rye-directions-link-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'href="tel:+15035550184"'), 'ember-rye-tel-link-survives', $ember_rye_footer_serialized);
$assert(\str_contains($ember_rye_footer_serialized, 'href="mailto:hello@emberandrye.example"'), 'ember-rye-mailto-link-survives', $ember_rye_footer_serialized);
$assert(\substr_count($ember_rye_footer_serialized, 'Ember &amp; Rye') === 1, 'ember-rye-brand-text-serializes-once', $ember_rye_footer_serialized);
$assert(\substr_count($ember_rye_footer_serialized, 'Warm hospitality, blistered crust, and neighborhood energy nightly.') === 1, 'ember-rye-footer-copy-serializes-once', $ember_rye_footer_serialized);
$ember_menu_category_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => '<div class="menu-sections"><section class="menu-category reveal"><div class="category-heading"><h2>Wood-Fired Pizza</h2><span>12&quot; pies</span></div><div class="menu-item"><div><h3>Ember Margherita</h3><p>San Marzano tomato, fior di latte, basil, olive oil</p></div><strong>$18</strong></div><div class="menu-item"><div><h3>Spicy Soppressata</h3><p>Calabrian chile, honey, oregano</p></div><strong>$22</strong></div></section></div>']));
$assert(!\str_contains($ember_menu_category_serialized, '<!-- wp:html -->'), 'ember-menu-category-avoids-core-html-fallback', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, 'menu-sections'), 'ember-menu-section-class-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, 'menu-category reveal'), 'ember-menu-category-class-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, 'Wood-Fired Pizza'), 'ember-menu-category-title-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, '12&quot; pies'), 'ember-menu-category-label-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, 'Ember Margherita'), 'ember-menu-item-title-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, 'San Marzano tomato'), 'ember-menu-item-description-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, '<strong>$18</strong>'), 'ember-menu-price-survives', $ember_menu_category_serialized);
$assert(\str_contains($ember_menu_category_serialized, '<strong>$22</strong>'), 'ember-menu-second-price-survives', $ember_menu_category_serialized);
$ember_faq_html = <<<HTML
<div class="faq-list reveal">
  <details open><summary>Do you take walk-ins?</summary><p>Yes. We keep a portion of bar and patio seating open for walk-ins every night.</p></details>
  <details><summary>Do you offer takeout?</summary><p>Takeout is available Tuesday through Thursday and Sunday, depending on oven volume.</p></details>
</div>
HTML;
$ember_faq_blocks = html_to_blocks_raw_handler(['HTML' => $ember_faq_html]);
$ember_faq_names = html_to_blocks_smoke_block_names($ember_faq_blocks);
$ember_faq_serialized = serialize_blocks($ember_faq_blocks);
$ember_faq_first = $ember_faq_blocks[0]['innerBlocks'][0] ?? [];
$assert(!\in_array('core/html', $ember_faq_names, \true), 'ember-faq-avoids-core-html-blocks', \implode(', ', $ember_faq_names));
$assert(!\str_contains($ember_faq_serialized, '<!-- wp:html -->'), 'ember-faq-serialized-has-no-wp-html', $ember_faq_serialized);
$assert(\str_contains($ember_faq_serialized, 'faq-list reveal'), 'ember-faq-wrapper-class-survives', $ember_faq_serialized);
$assert(\true === ($ember_faq_first['attrs']['showContent'] ?? \false), 'ember-faq-open-state-survives', \var_export($ember_faq_first['attrs'] ?? [], \true));
$assert(\str_contains($ember_faq_serialized, 'Do you take walk-ins?'), 'ember-faq-first-summary-survives', $ember_faq_serialized);
$assert(\str_contains($ember_faq_serialized, 'Do you offer takeout?'), 'ember-faq-second-summary-survives', $ember_faq_serialized);
$assert(\str_contains($ember_faq_serialized, 'bar and patio seating'), 'ember-faq-first-answer-survives', $ember_faq_serialized);
$assert(\str_contains($ember_faq_serialized, 'depending on oven volume'), 'ember-faq-second-answer-survives', $ember_faq_serialized);
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
