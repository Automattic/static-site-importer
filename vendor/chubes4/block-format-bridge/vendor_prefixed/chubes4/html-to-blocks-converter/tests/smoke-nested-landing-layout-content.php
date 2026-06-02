<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: nested AI landing-page layout wrappers preserve descendants.
 *
 * Run: php tests/smoke-nested-landing-layout-content.php
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
            return \in_array($name, ['core/group', 'core/columns', 'core/column', 'core/heading', 'core/html', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
$fixtures = ['problem-compare' => ['html' => <<<'HTML'
<section class="section" style="background:var(--bg-2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
  <div class="container">
    <p class="section-label reveal">The problem</p>
    <h2 class="section-title reveal" data-delay="100">WordPress is powerful.<br>Gutenberg is a <span style="color:var(--accent-2)">detour.</span></h2>
    <p class="section-body reveal" data-delay="200">You just want to ship a beautiful website. Instead you're googling "how to add a custom CSS class to a core/group block" at 11pm. Sound familiar?</p>

    <div class="compare reveal" data-delay="300">
      <div class="compare-col bad">
        <h3>Old Way: Block Editor Hell</h3>
        <ul>
          <li>Drag-and-drop interfaces that fight you</li>
          <li>JSON attributes buried inside HTML comments</li>
          <li>Custom designs need custom blocks need build tools</li>
          <li>AI-generated code breaks on save because block validation fails</li>
          <li>Global styles vs. inline styles vs. theme.json — pick your poison</li>
        </ul>
      </div>
      <div class="compare-col good">
        <h3>Studio Code Way: Pure HTML</h3>
        <ul>
          <li>Write semantic HTML like it's 2005 (but make it slap)</li>
          <li>CSS in one stylesheet, everything just works</li>
          <li>AI agents can generate real content — no block schema to validate</li>
          <li>The site editor still works — content becomes blocks automatically</li>
          <li>Ship in minutes, not hours</li>
        </ul>
      </div>
    </div>
  </div>
</section>
HTML
, 'expected' => ['The problem', 'WordPress is powerful.', 'Gutenberg is a <span style="color:var(--accent-2)">detour.</span>', 'Old Way: Block Editor Hell', 'Drag-and-drop interfaces that fight you', 'Global styles vs. inline styles vs. theme.json', 'Studio Code Way: Pure HTML', 'AI agents can generate real content', 'Ship in minutes, not hours']], 'features-grid' => ['html' => <<<'HTML'
<section class="section">
  <div class="container">
    <p class="section-label reveal">Why it's sick</p>
    <h2 class="section-title reveal" data-delay="100">The freedom you want.<br>The platform you need.</h2>

    <div class="features-grid" style="margin-top:3rem;">
      <div class="feature-card reveal" data-delay="0">
        <div class="feature-icon">&lt;/&gt;</div>
        <h3>Write HTML, Ship WordPress</h3>
        <p>Studio Code converts your raw HTML fragments into WordPress block content automatically. You get the CMS, the editor, the hosting compatibility — without touching a block schema.</p>
      </div>
      <div class="feature-card reveal" data-delay="100">
        <div class="feature-icon">~~</div>
        <h3>Vibe Code With AI</h3>
        <p>Ask an AI agent to build your site. Pure HTML is something every LLM understands perfectly. No more "block validation failed" errors. The AI writes HTML, Studio makes it WordPress.</p>
      </div>
    </div>
  </div>
</section>
HTML
, 'expected' => ["Why it's sick", 'The freedom you want.', 'The platform you need.', '&lt;/&gt;', 'Write HTML, Ship WordPress', 'without touching a block schema', '~~', 'Vibe Code With AI', 'No more "block validation failed" errors']]];
foreach ($fixtures as $name => $fixture) {
    $blocks = html_to_blocks_raw_handler(['HTML' => $fixture['html']]);
    $serialized = serialize_blocks($blocks);
    $names = $flatten_block_names($blocks);
    $assert(\count($blocks) === 1, $name . '-single-top-level-wrapper');
    $assert(\count($names) > 8, $name . '-has-descendant-block-tree', 'Block count: ' . \count($names));
    $assert(!\in_array('core/html', $names, \true), $name . '-does-not-fallback-to-core-html');
    $assert(\in_array('core/group', $names, \true), $name . '-uses-groups-for-wrappers');
    $assert(html_to_blocks_measure_block_content_length($blocks) >= \strlen($fixture['html']) * 0.1, $name . '-content-loss-metric-counts-descendants');
    foreach ($fixture['expected'] as $expected) {
        $assert(\strpos($serialized, $expected) !== \false, $name . '-preserves-' . \substr(\md5($expected), 0, 8), 'Missing: ' . $expected);
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
