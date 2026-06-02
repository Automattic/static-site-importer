<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: top-level raw conversion must not re-emit descendants as siblings.
 *
 * Run: php tests/smoke-no-duplicate-descendants.php
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
            return \in_array($name, ['core/column', 'core/columns', 'core/group', 'core/html', 'core/heading', 'core/list', 'core/list-item', 'core/paragraph'], \true);
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
$assert_occurs_once = static function (string $haystack, string $needle, string $label) use ($assert) {
    $count = \substr_count($haystack, $needle);
    $assert(1 === $count, $label, 'Expected one occurrence of ' . $needle . ', found ' . $count);
};
$assert_contains = static function (string $haystack, string $needle, string $label) use ($assert) {
    $assert(\strpos($haystack, $needle) !== \false, $label, 'Missing ' . $needle);
};
$html = <<<HTML
<div class="compare">
  <div class="col-wp">
    <h3>WordPress <span class="tag">2003-2026</span></h3>
    <ul>
      <li>Buy domain, buy hosting, install LAMP stack</li>
      <li>Run 5-minute install</li>
    </ul>
  </div>
  <div class="col-claude">
    <h3>The Prompt <span class="tag">2026-</span></h3>
    <ul>
      <li>Open Claude Code in a folder</li>
      <li>Type one sentence describing the site</li>
    </ul>
  </div>
</div>
<div class="eulogy-frame">
  <div class="dates">May 27, 2003 - April 29, 2026</div>
  <h2>For WordPress, with love.</h2>
  <p>It is rare that a piece of software earns the right to be eulogized.</p>
  <p class="signoff">- Generated, with feeling, in one prompt.</p>
</div>
<ol class="manifesto-list">
  <li>
    <div>
      <h3>The CMS was a workaround for not being able to write HTML.</h3>
      <p>That excuse no longer holds.</p>
    </div>
  </li>
  <li>
    <div>
      <h3>The plugin economy was a tax on not knowing JavaScript.</h3>
      <p>You paid \$49/year so a contact form could send an email.</p>
    </div>
  </li>
</ol>
HTML;
$serialized = serialize_blocks(html_to_blocks_raw_handler(['HTML' => $html]));
$assert_contains($serialized, 'compare', 'compare-wrapper-preserved');
$assert_contains($serialized, 'col-wp', 'col-wp-preserved');
$assert_contains($serialized, 'col-claude', 'col-claude-preserved');
$assert_occurs_once($serialized, '2003-2026', 'compare-heading-once');
$assert_occurs_once($serialized, 'May 27, 2003', 'dates-once');
$assert_occurs_once($serialized, 'It is rare that a piece of software earns the right to be eulogized.', 'eulogy-copy-once');
$assert_occurs_once($serialized, 'The CMS was a workaround for not being able to write HTML.', 'manifesto-copy-once');
$assert_contains($serialized, 'manifesto-list', 'classed-visual-list-wrapper-preserved');
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
