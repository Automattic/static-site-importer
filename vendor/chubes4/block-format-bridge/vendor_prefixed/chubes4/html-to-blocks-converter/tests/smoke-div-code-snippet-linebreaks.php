<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: div code snippets with spans and br line breaks convert natively.
 *
 * Run: php tests/smoke-div-code-snippet-linebreaks.php
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
            return \in_array($name, ['core/group', 'core/heading', 'core/html', 'core/paragraph', 'core/preformatted'], \true);
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
$html = <<<'HTML'
<section class="workflow-steps">
  <div class="step-card">
    <h3>Map HTML to blocks</h3>
    <p>Styled code snippets should remain editable native blocks.</p>
    <div class="step-code">
      <span class="hl-green">&lt;section</span> <span class="hl">class=&quot;hero&quot;</span><span class="hl-green">&gt;</span><br>
      &nbsp;&nbsp;<span class="hl-green">&lt;h1&gt;</span>Welcome<span class="hl-green">&lt;/h1&gt;</span><br>
      <span class="hl-green">&lt;/section&gt;</span>
    </div>
  </div>
  <div class="step-card">
    <h3>Compare output</h3>
    <p>Comments, arrows, and spacing should survive.</p>
    <div class="step-code">
      <span class="hl-purple">wp_html_to_blocks</span>(<span class="hl">$html</span>)<br>
      <span class="hl-green">// Maps:</span><br>
      &nbsp;&nbsp;<span class="hl">section.hero</span> &rarr; <span class="hl-purple">cover</span><br>
    </div>
  </div>
  <div class="step-card">
    <h3>Describe the site</h3>
    <p>Plain multiline prompt snippets should remain native blocks.</p>
    <div class="step-code">
      "Build a SaaS landing page<br>
      with a hero, pricing,<br>
      and testimonials."
    </div>
  </div>
  <div class="step-card">
    <h3>Run the import</h3>
    <p>CLI snippets should preserve indentation entities.</p>
    <div class="step-code">
      wp static-site-importer<br>
      &nbsp;&nbsp;import-theme index.html<br>
      &nbsp;&nbsp;--activate --overwrite
    </div>
  </div>
  <div class="step-card">
    <h3>Review the result</h3>
    <p>Output snippets can contain checkmarks and plain words.</p>
    <div class="step-code">
      ✓ Block theme activated<br>
      ✓ Site Editor ready<br>
      ✓ All blocks editable
    </div>
  </div>
  <div class="step-card">
    <h3>Inspect workflow markup</h3>
    <p>Workflow code panels can use display-block spans as visual lines.</p>
    <div class="workflow-code">
      <span style="display:block"><span class="code-comment">&lt;!-- Clean semantic HTML --&gt;</span></span>
      <span style="display:block"><span class="code-tag">&lt;section</span> <span class="code-attr">class</span>=<span class="code-string">&quot;hero&quot;</span><span class="code-tag">&gt;</span></span>
      <span style="display:block">&nbsp;&nbsp;<span class="code-tag">&lt;h1&gt;</span>Native blocks<span class="code-tag">&lt;/h1&gt;</span></span>
      <span style="display:block"><span class="code-comment">&lt;!-- wp:group {&quot;layout&quot;:{&quot;type&quot;:&quot;constrained&quot;}} --&gt;</span></span>
    </div>
  </div>
</section>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$serialized = serialize_blocks($blocks);
$fallbacks = $collect_blocks($blocks, 'core/html');
$groups = $collect_blocks($blocks, 'core/group');
$headings = $collect_blocks($blocks, 'core/heading');
$paragraphs = $collect_blocks($blocks, 'core/paragraph');
$preformatted = $collect_blocks($blocks, 'core/preformatted');
$assert(\count($blocks) === 1 && ($blocks[0]['blockName'] ?? '') === 'core/group', 'workflow-section-becomes-group', $serialized);
$assert(\count($groups) >= 3, 'step-cards-become-groups', $serialized);
$assert(\count($headings) === 6, 'step-titles-become-headings', $serialized);
$assert(\count($paragraphs) === 6, 'step-body-copy-becomes-paragraphs', $serialized);
$assert(\count($preformatted) === 6, 'step-code-becomes-preformatted', $serialized);
$assert(\count($fallbacks) === 0, 'step-code-does-not-fallback-to-html', $serialized);
$assert(\str_contains($serialized, 'workflow-steps') && \str_contains($serialized, 'step-card'), 'wrapper-classes-survive', $serialized);
$assert(\str_contains($serialized, 'wp-block-preformatted step-code'), 'step-code-class-survives', $serialized);
$assert(\str_contains($serialized, 'wp-block-preformatted workflow-code'), 'workflow-code-class-survives', $serialized);
$assert(\str_contains($serialized, '&lt;section') && \str_contains($serialized, '&lt;/section&gt;'), 'escaped-html-code-survives', $serialized);
$assert(\str_contains($serialized, 'Welcome') && \str_contains($serialized, 'wp_html_to_blocks'), 'span-text-survives', $serialized);
$assert(\str_contains($serialized, '// Maps:') && \str_contains($serialized, '&rarr;'), 'comments-and-arrow-survive', $serialized);
$assert(\str_contains($serialized, 'Build a SaaS landing page') && \str_contains($serialized, 'and testimonials'), 'plain-prompt-step-code-survives', $serialized);
$assert(\str_contains($serialized, 'wp static-site-importer') && \str_contains($serialized, '&nbsp;&nbsp;--activate --overwrite'), 'cli-step-code-survives', $serialized);
$assert(\str_contains($serialized, '✓ Block theme activated') && \str_contains($serialized, '✓ All blocks editable'), 'output-step-code-survives', $serialized);
$assert(\str_contains($serialized, 'Clean semantic HTML') && \str_contains($serialized, 'wp:group'), 'workflow-code-display-block-lines-survive', $serialized);
$assert(\str_contains($serialized, "--&gt;</span>\n<span class=\"code-tag\""), 'workflow-code-display-block-spans-become-linebreaks', $serialized);
$assert(\str_contains($serialized, "&gt;</span>\n") && \str_contains($serialized, "// Maps:</span>\n"), 'br-tags-become-linebreaks', $serialized);
$assert(!\str_contains($serialized, '<br>') && !\str_contains($serialized, '<br/>'), 'step-code-br-tags-removed', $serialized);
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
