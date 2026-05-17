<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: repeated production-style card grids become editable blocks.
 *
 * Run: php tests/smoke-repeated-card-grid-transforms.php
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
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $unsupported_fallback_events;
        if ('html_to_blocks_unsupported_html_fallback' === $hook_name) {
            $unsupported_fallback_events[] = $args;
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
$home_3x3_grid = <<<'HTML'
<div class="home-3x3-grid">
  <div class="home-3x3-col">
    <a class="home-3x3-card" href="https://extrachill.com/2026/05/shovels-and-rope-review">
      <img class="home-3x3-thumb" src="https://cdn.example.com/shovels.jpg" srcset="https://cdn.example.com/shovels-300.jpg 300w, https://cdn.example.com/shovels.jpg 900w" sizes="(max-width: 900px) 100vw, 33vw" width="900" height="600" alt="Shovels and Rope on stage" />
      <span class="home-3x3-badge">Review</span>
      <h3 class="home-3x3-title">Shovels &amp; Rope Keep Charleston Weird</h3>
      <span class="home-3x3-meta">May 3, 2026</span>
    </a>
  </div>
  <div class="home-3x3-col">
    <a class="home-3x3-card" href="https://extrachill.com/2026/05/charleston-show-calendar">
      <img class="home-3x3-thumb" src="https://cdn.example.com/calendar.jpg" width="900" height="600" alt="Crowd at a Charleston show" />
      <span class="home-3x3-badge">Calendar</span>
      <h3 class="home-3x3-title">Ten Charleston Shows to Catch This Week</h3>
      <span class="home-3x3-meta">May 2, 2026</span>
    </a>
  </div>
  <div class="home-3x3-col">
    <a class="home-3x3-card" href="https://extrachill.com/2026/05/new-music-friday">
      <img class="home-3x3-thumb" src="https://cdn.example.com/new-music.jpg" width="900" height="600" alt="Record store bins" />
      <span class="home-3x3-badge">New Music</span>
      <h3 class="home-3x3-title">New Music Friday: Lowcountry Edition</h3>
      <span class="home-3x3-meta">May 1, 2026</span>
    </a>
  </div>
</div>
HTML;
$home_network_grid = <<<'HTML'
<div class="home-network-grid">
  <div class="home-network-card">
    <img class="home-network-logo" src="https://cdn.example.com/events.png" width="640" height="360" alt="Extra Chill Events" />
    <span class="home-network-badge">Events</span>
    <h3 class="home-network-title">Extra Chill Events</h3>
    <p class="home-network-description">Concert listings and community calendars for Charleston music fans.</p>
    <a class="home-network-cta" href="https://events.extrachill.com">Browse events</a>
  </div>
  <div class="home-network-card">
    <img class="home-network-logo" src="https://cdn.example.com/wire.png" width="640" height="360" alt="Extra Chill Wire" />
    <span class="home-network-badge">Wire</span>
    <h3 class="home-network-title">Extra Chill Wire</h3>
    <p class="home-network-description">Local music dispatches, interviews, and scene notes from the Carolinas.</p>
    <a class="home-network-cta" href="https://wire.extrachill.com">Read the wire</a>
  </div>
</div>
HTML;
foreach (['home-3x3-grid' => $home_3x3_grid, 'home-network-grid' => $home_network_grid] as $fixture_name => $html) {
    $unsupported_fallback_events = [];
    $blocks = html_to_blocks_raw_handler(['HTML' => $html]);
    $serialized = serialize_blocks($blocks);
    $names = $flatten_block_names($blocks);
    $assert(\count($blocks) === 1, $fixture_name . '-single-wrapper');
    $assert(($blocks[0]['blockName'] ?? '') === 'core/group', $fixture_name . '-wrapper-is-group');
    $assert(!\in_array('core/html', $names, \true), $fixture_name . '-does-not-fallback-to-core-html', 'Blocks: ' . \implode(', ', $names));
    $assert(\count($unsupported_fallback_events) === 0, $fixture_name . '-emits-no-unsupported-fallback-events');
    $assert(\substr_count(\implode(',', $names), 'core/group') >= 3, $fixture_name . '-creates-nested-card-groups', 'Blocks: ' . \implode(', ', $names));
    $assert(\in_array('core/image', $names, \true), $fixture_name . '-creates-image-blocks');
    $assert(\in_array('core/heading', $names, \true), $fixture_name . '-creates-heading-blocks');
    $assert(\in_array('core/paragraph', $names, \true), $fixture_name . '-creates-paragraph-blocks');
    $assert(\strpos($serialized, 'home-') !== \false, $fixture_name . '-preserves-source-classes');
    $assert(\strpos($serialized, 'https://') !== \false, $fixture_name . '-preserves-links-and-images');
}
$blocks_3x3 = html_to_blocks_raw_handler(['HTML' => $home_3x3_grid]);
$serialized_3x3 = serialize_blocks($blocks_3x3);
$assert(\strpos($serialized_3x3, 'srcset=') !== \false, 'home-3x3-preserves-srcset');
$assert(\strpos($serialized_3x3, 'width="900"') !== \false, 'home-3x3-preserves-width');
$assert(\strpos($serialized_3x3, 'height="600"') !== \false, 'home-3x3-preserves-height');
$assert(\strpos($serialized_3x3, 'Shovels &amp; Rope Keep Charleston Weird') !== \false, 'home-3x3-preserves-title');
$assert(\strpos($serialized_3x3, 'May 3, 2026') !== \false, 'home-3x3-preserves-meta');
$assert(\strpos($serialized_3x3, 'Review') !== \false, 'home-3x3-preserves-badge');
$assert(\strpos($serialized_3x3, 'https://extrachill.com/2026/05/shovels-and-rope-review') !== \false, 'home-3x3-preserves-card-link');
$blocks_network = html_to_blocks_raw_handler(['HTML' => $home_network_grid]);
$serialized_network = serialize_blocks($blocks_network);
$assert(\strpos($serialized_network, 'Extra Chill Events') !== \false, 'network-preserves-title');
$assert(\strpos($serialized_network, 'Concert listings and community calendars') !== \false, 'network-preserves-description');
$assert(\strpos($serialized_network, 'Events') !== \false, 'network-preserves-badge');
$assert(\strpos($serialized_network, 'https://events.extrachill.com') !== \false, 'network-preserves-cta-link');
$generic_wrapped_grid = '<div class="full-width-breakout edge-shell"><div class="article-grid"><article class="story-card"><h3>First story</h3><p>One.</p></article><article class="story-card"><h3>Second story</h3><p>Two.</p></article></div></div>';
$unsupported_fallback_events = [];
$wrapped_blocks = html_to_blocks_raw_handler(['HTML' => $generic_wrapped_grid]);
$wrapped_serialized = serialize_blocks($wrapped_blocks);
$wrapped_names = $flatten_block_names($wrapped_blocks);
$assert(\count($wrapped_blocks) === 1, 'generic-wrapper-single-block');
$assert(($wrapped_blocks[0]['blockName'] ?? '') === 'core/group', 'generic-wrapper-becomes-group');
$assert(\strpos($wrapped_serialized, 'full-width-breakout edge-shell') !== \false, 'generic-wrapper-preserves-shell-classes', $wrapped_serialized);
$assert(\strpos($wrapped_serialized, 'article-grid') !== \false, 'generic-wrapper-preserves-child-grid-class', $wrapped_serialized);
$assert(\strpos($wrapped_serialized, 'First story') !== \false && \strpos($wrapped_serialized, 'Second story') !== \false, 'generic-wrapper-preserves-card-content', $wrapped_serialized);
$assert(!\in_array('core/html', $wrapped_names, \true), 'generic-wrapper-does-not-fallback-to-core-html', 'Blocks: ' . \implode(', ', $wrapped_names));
$assert(\count($unsupported_fallback_events) === 0, 'generic-wrapper-emits-no-unsupported-fallback-events', (string) \count($unsupported_fallback_events));
$service_card_grid = <<<'HTML'
<div class="service-grid">
	<article class="service-card"><span class="service-number">01</span><span class="tag">window seats</span><h3>Heels &amp; soles</h3><p>Heel lifts and sole patching.</p></article>
	<article class="service-card"><span class="service-number">02</span><h3>Boot work</h3><p>Waterproofing and zipper repair.</p></article>
</div>
HTML;
$service_card_blocks = html_to_blocks_raw_handler(['HTML' => $service_card_grid]);
$service_card_serialized = serialize_blocks($service_card_blocks);
$service_card_names = $flatten_block_names($service_card_blocks);
$assert(!\in_array('core/html', $service_card_names, \true), 'service-card-label-spans-avoid-core-html', 'Blocks: ' . \implode(', ', $service_card_names));
$assert(\strpos($service_card_serialized, '<span class="service-number">01</span>') !== \false, 'service-card-classed-span-preserved', $service_card_serialized);
$assert(\strpos($service_card_serialized, '<span class="tag">window seats</span>') !== \false, 'service-card-tag-span-preserved', $service_card_serialized);
$assert(\strpos($service_card_serialized, '<p class="service-number">01</p>') === \false, 'service-card-classed-span-not-rewritten-to-paragraph', $service_card_serialized);
$assert(\strpos($service_card_serialized, '<h3 class="wp-block-heading">Heels &amp; soles</h3>') !== \false, 'service-card-heading-remains-editable', $service_card_serialized);
$assert(\strpos($service_card_serialized, '<p>Heel lifts and sole patching.</p>') !== \false, 'service-card-copy-remains-editable', $service_card_serialized);
$extrachill_shell_grid = <<<'HTML'
<div class="full-width-breakout ec-edge-shell">
  <div class="home-3x3-grid">
    <!-- Interviews Column -->
    <div class="home-3x3-col">
      <a class="home-3x3-card" href="https://extrachill.com/interviews/artist-profile">
        <h3 class="home-3x3-title">Inside the Lowcountry Scene</h3>
        <p class="home-3x3-excerpt">Interviews with independent artists building something real.</p>
      </a>
    </div>
    <div class="home-3x3-col">
      <a class="home-3x3-card" href="https://extrachill.com/events/weekend-guide">
        <h3 class="home-3x3-title">Weekend Guide</h3>
        <p class="home-3x3-excerpt">Shows worth leaving the house for.</p>
      </a>
    </div>
  </div>
</div>
HTML;
$unsupported_fallback_events = [];
$shell_grid_blocks = html_to_blocks_raw_handler(['HTML' => $extrachill_shell_grid]);
$shell_grid_serialized = serialize_blocks($shell_grid_blocks);
$shell_grid_names = $flatten_block_names($shell_grid_blocks);
$assert(\count($shell_grid_blocks) === 1, 'extrachill-shell-grid-single-wrapper');
$assert(($shell_grid_blocks[0]['blockName'] ?? '') === 'core/group', 'extrachill-shell-grid-wrapper-is-group');
$assert(\strpos($shell_grid_serialized, 'full-width-breakout ec-edge-shell') !== \false, 'extrachill-shell-grid-preserves-shell-classes', $shell_grid_serialized);
$assert(\strpos($shell_grid_serialized, 'home-3x3-grid') !== \false, 'extrachill-shell-grid-preserves-grid-class', $shell_grid_serialized);
$assert(\strpos($shell_grid_serialized, 'Inside the Lowcountry Scene') !== \false, 'extrachill-shell-grid-preserves-first-card', $shell_grid_serialized);
$assert(\strpos($shell_grid_serialized, 'Weekend Guide') !== \false, 'extrachill-shell-grid-preserves-second-card', $shell_grid_serialized);
$assert(!\in_array('core/html', $shell_grid_names, \true), 'extrachill-shell-grid-does-not-fallback-to-core-html', 'Blocks: ' . \implode(', ', $shell_grid_names));
$assert(\count($unsupported_fallback_events) === 0, 'extrachill-shell-grid-emits-no-unsupported-fallback-events', (string) \count($unsupported_fallback_events));
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
