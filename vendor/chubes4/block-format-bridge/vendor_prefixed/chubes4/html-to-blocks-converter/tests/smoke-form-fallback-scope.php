<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: form controls localize core/html fallback to the control island.
 *
 * Run: php tests/smoke-form-fallback-scope.php
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
            return \in_array($name, ['core/button', 'core/buttons', 'core/group', 'core/heading', 'core/html', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
$search_section = <<<HTML
<section class="home-network-search">
  <div class="home-network-search__copy">
    <h2>Find your scene</h2>
    <p>Search venues, shows, and neighborhood networks.</p>
  </div>
  <form class="search-form" action="/" method="get">
    <label for="network-search">Search</label>
    <input id="network-search" type="search" name="s" placeholder="City, venue, or band" />
    <button type="submit"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M1 1h4" /></svg><span>Search</span></button>
  </form>
  <p><a href="/network/">Browse the full network</a></p>
</section>
HTML;
$fallback_events = [];
$search_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $search_section]));
$assert(!\str_contains($search_serialized, '<!-- wp:html --><section class="home-network-search"'), 'search-section-wrapper-is-not-core-html', $search_serialized);
$assert(\str_contains($search_serialized, '<section class="wp-block-group home-network-search">'), 'search-section-wrapper-becomes-group', $search_serialized);
$assert(\str_contains($search_serialized, 'Find your scene'), 'search-heading-remains-editable', $search_serialized);
$assert(\str_contains($search_serialized, 'Search venues, shows, and neighborhood networks.'), 'search-paragraph-remains-editable', $search_serialized);
$assert(\str_contains($search_serialized, 'Browse the full network'), 'search-link-text-survives', $search_serialized);
$assert(\str_contains($search_serialized, '<!-- wp:html --><form class="search-form"'), 'search-form-is-local-core-html-island', $search_serialized);
$assert(\count($fallback_events) === 1, 'search-section-emits-one-local-form-fallback', (string) \count($fallback_events));
$assert(($fallback_events[0][1]['tag_name'] ?? '') === 'FORM', 'search-fallback-context-is-form', \print_r($fallback_events, \true));
$newsletter_grid = <<<HTML
<div class="home-3x3-grid">
  <article class="feature-card"><h3>Latest stories</h3><p>Fresh dispatches from the underground.</p></article>
  <article class="feature-card newsletter-card">
    <h3>Get the newsletter</h3>
    <p>One email when the week gets loud.</p>
    <form class="newsletter-form" action="/subscribe" method="post"><input type="email" name="email" /><button type="submit">Sign up</button></form>
  </article>
  <article class="feature-card"><h3>Local calendars</h3><p><a href="/events/">Find events</a></p></article>
</div>
HTML;
$fallback_events = [];
$newsletter_serialized = serialize_blocks(html_to_blocks_normalize_parsed_image_html_blocks([HTML_To_Blocks_Block_Factory::create_block('core/html', ['content' => $newsletter_grid])]));
$assert(!\str_contains($newsletter_serialized, '<!-- wp:html --><div class="home-3x3-grid"'), 'parsed-newsletter-grid-wrapper-is-not-core-html', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, '<div class="wp-block-group home-3x3-grid">'), 'parsed-newsletter-grid-becomes-group', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, 'Latest stories'), 'parsed-newsletter-sibling-heading-survives', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, 'Get the newsletter'), 'parsed-newsletter-heading-remains-editable', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, 'One email when the week gets loud.'), 'parsed-newsletter-copy-remains-editable', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, 'Find events'), 'parsed-newsletter-link-text-survives', $newsletter_serialized);
$assert(\str_contains($newsletter_serialized, '<!-- wp:html --><form class="newsletter-form"'), 'parsed-newsletter-form-is-local-core-html-island', $newsletter_serialized);
$assert(\count($fallback_events) === 1, 'parsed-newsletter-grid-emits-one-local-form-fallback', (string) \count($fallback_events));
$assert(($fallback_events[0][1]['tag_name'] ?? '') === 'FORM', 'parsed-newsletter-fallback-context-is-form', \print_r($fallback_events, \true));
$generic_wrapped_form = '<div class="content-shell edge-wrapper"><section class="search-panel"><div class="search-copy"><h2>Find anything</h2><p>Search the archive.</p></div><form class="search-form" action="/" method="get"><input type="search" name="s" /><button type="submit">Search</button></form><p><a href="/archive/">Browse archive</a></p></section></div>';
$fallback_events = [];
$wrapped_form_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $generic_wrapped_form]));
$assert(!\str_contains($wrapped_form_serialized, '<!-- wp:html --><div class="content-shell edge-wrapper"'), 'generic-form-wrapper-is-not-core-html', $wrapped_form_serialized);
$assert(\str_contains($wrapped_form_serialized, '<div class="wp-block-group content-shell edge-wrapper">'), 'generic-form-wrapper-becomes-group', $wrapped_form_serialized);
$assert(\str_contains($wrapped_form_serialized, '<section class="wp-block-group search-panel">'), 'generic-form-section-becomes-group', $wrapped_form_serialized);
$assert(\str_contains($wrapped_form_serialized, 'Find anything'), 'generic-form-heading-remains-editable', $wrapped_form_serialized);
$assert(\str_contains($wrapped_form_serialized, 'Browse archive'), 'generic-form-link-text-survives', $wrapped_form_serialized);
$assert(\str_contains($wrapped_form_serialized, '<!-- wp:html --><form class="search-form"'), 'generic-form-is-local-core-html-island', $wrapped_form_serialized);
$assert(\count($fallback_events) === 1, 'generic-form-wrapper-emits-one-local-form-fallback', (string) \count($fallback_events));
$assert(($fallback_events[0][1]['tag_name'] ?? '') === 'FORM', 'generic-form-fallback-context-is-form', \print_r($fallback_events, \true));
$extrachill_network_search = <<<'HTML'
<div class="home-network-search">
  <h2 class="home-network-search-header">Search the Network</h2>
  <p class="home-network-search-description">Find artists, events, discussions, and more across Extra Chill.</p>
  <form action="https://extrachill.com/" class="search-form searchform" method="get">
    <label for="home-network-search-input">Search for:</label>
    <input id="home-network-search-input" name="s" type="search" value="" />
    <button type="submit">Search</button>
  </form>
</div>
HTML;
$fallback_events = [];
$network_search_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $extrachill_network_search]));
$assert(!\str_contains($network_search_serialized, '<!-- wp:html --><div class="home-network-search"'), 'extrachill-network-search-wrapper-is-not-core-html', $network_search_serialized);
$assert(\str_contains($network_search_serialized, '<div class="wp-block-group home-network-search">'), 'extrachill-network-search-wrapper-becomes-group', $network_search_serialized);
$assert(\str_contains($network_search_serialized, 'Search the Network'), 'extrachill-network-search-heading-remains-editable', $network_search_serialized);
$assert(\str_contains($network_search_serialized, 'Find artists, events, discussions, and more across Extra Chill.'), 'extrachill-network-search-description-remains-editable', $network_search_serialized);
$assert(\str_contains($network_search_serialized, '<!-- wp:html --><form action="https://extrachill.com/" class="search-form searchform" method="get">'), 'extrachill-network-search-form-is-local-core-html-island', $network_search_serialized);
$assert(\count($fallback_events) === 1, 'extrachill-network-search-emits-one-local-form-fallback', (string) \count($fallback_events));
$assert(($fallback_events[0][1]['tag_name'] ?? '') === 'FORM', 'extrachill-network-search-fallback-context-is-form', \print_r($fallback_events, \true));
$eastbank_static_preview_form = <<<'HTML'
<form class="static-form" aria-label="Repair intake preview form">
  <label for="item">Item</label>
  <input id="item" name="item" type="text" placeholder="Example: desk lamp, toaster, backpack zipper">
  <label for="problem">What changed?</label>
  <textarea id="problem" name="problem" rows="4" placeholder="Tell us what stopped working, what you tried, and whether parts are loose."></textarea>
  <label for="visit">Preferred visit</label>
  <select id="visit" name="visit">
    <option>Thursday afternoon</option>
    <option>Saturday walk-in counter</option>
    <option>Next clinic or tool night</option>
  </select>
  <button type="button">Prepare my bench note</button>
  <p class="form-note">Static preview only - bring this information with you or call the shop before visiting.</p>
</form>
HTML;
$fallback_events = [];
$eastbank_form_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $eastbank_static_preview_form]));
$assert(!\str_contains($eastbank_form_serialized, '<!-- wp:html -->'), 'eastbank-static-preview-form-avoids-core-html-fallback', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, '<div class="wp-block-group static-form" aria-label="Repair intake preview form">'), 'eastbank-static-preview-form-becomes-group', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, 'Item'), 'eastbank-static-preview-label-survives', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, 'Example: desk lamp, toaster, backpack zipper'), 'eastbank-static-preview-placeholder-survives', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, '<!-- wp:list -->'), 'eastbank-static-preview-select-becomes-list', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, 'Thursday afternoon'), 'eastbank-static-preview-option-survives', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, 'Prepare my bench note'), 'eastbank-static-preview-button-survives', $eastbank_form_serialized);
$assert(\str_contains($eastbank_form_serialized, 'Static preview only'), 'eastbank-static-preview-note-survives', $eastbank_form_serialized);
$assert(\count($fallback_events) === 0, 'eastbank-static-preview-emits-no-fallback-event', (string) \count($fallback_events));
$ember_form_card = <<<'HTML'
<form class="form-card reveal" aria-label="Reservation request form"><h2>Request a reservation</h2><label>Name<input type="text" name="name" placeholder="Your name"></label><label>Email<input type="email" name="email" placeholder="you@example.com"></label><div class="form-row"><label>Date<input type="date" placeholder="Preferred date"></label><label>Time<select><option>5:00 PM</option><option>7:30 PM</option></select></label></div><button class="btn" type="submit">Request Table</button></form>
HTML;
$fallback_events = [];
$ember_form_card_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $ember_form_card]));
$assert(!\str_contains($ember_form_card_serialized, '<!-- wp:html -->'), 'ember-form-card-avoids-core-html-fallback', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, '<div class="wp-block-group form-card reveal" aria-label="Reservation request form">'), 'ember-form-card-becomes-group', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'Request a reservation'), 'ember-form-card-title-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'Name'), 'ember-form-card-name-label-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'Your name'), 'ember-form-card-name-placeholder-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'you@example.com'), 'ember-form-card-email-placeholder-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'Preferred date'), 'ember-form-card-date-placeholder-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, '<!-- wp:list -->'), 'ember-form-card-select-becomes-list', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, '5:00 PM'), 'ember-form-card-first-option-survives', $ember_form_card_serialized);
$assert(\str_contains($ember_form_card_serialized, 'Request Table'), 'ember-form-card-submit-text-survives', $ember_form_card_serialized);
$assert(\count($fallback_events) === 0, 'ember-form-card-emits-no-fallback-event', (string) \count($fallback_events));
$untargeted_real_form = '<form aria-label="Contact form"><label for="email">Email</label><input id="email" name="email" type="email"></form>';
$fallback_events = [];
$untargeted_real_form_serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $untargeted_real_form]));
$assert(\str_contains($untargeted_real_form_serialized, '<!-- wp:html --><form aria-label="Contact form">'), 'untargeted-real-form-still-falls-back', $untargeted_real_form_serialized);
$assert(\count($fallback_events) === 1, 'untargeted-real-form-emits-fallback-event', (string) \count($fallback_events));
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
