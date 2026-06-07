<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: Ember & Rye decorative media and photo collage wrappers.
 *
 * Run: php tests/smoke-ember-rye-media-collage.php
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
    require_once \dirname($wp_html_api_path) . '/class-wp-token-map.php';
    require_once $wp_html_api_path . '/html5-named-character-references.php';
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
            return \in_array($name, ['core/group', 'core/html', 'core/image'], \true);
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
$flatten_blocks = static function (array $blocks) use (&$flatten_blocks): array {
    $flat = [];
    foreach ($blocks as $block) {
        $flat[] = $block;
        $flat = \array_merge($flat, $flatten_blocks($block['innerBlocks'] ?? []));
    }
    return $flat;
};
$html = <<<HTML
<div class="hero-media" role="img" aria-label="A wood-fired pizza coming out of a glowing oven"></div>
<div class="photo-collage reveal" aria-label="Restaurant food and dining photography">
  <img src="https://images.unsplash.com/photo-1574071318508-1cdbab80d002?auto=format&amp;fit=crop&amp;w=900&amp;q=80" alt="Wood-fired pizza with basil and melted mozzarella">
  <img src="https://images.unsplash.com/photo-1541745537411-b8046dc6d66c?auto=format&amp;fit=crop&amp;w=700&amp;q=80" alt="Friends sharing food at a warm restaurant table">
  <img src="https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&amp;fit=crop&amp;w=700&amp;q=80" alt="Fresh pizza topped with herbs">
</div>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$flat = $flatten_blocks($blocks);
$serialized = serialize_blocks($blocks);
$names = \array_map(static function ($block) {
    return $block['blockName'] ?? '';
}, $flat);
$groups = \array_values(\array_filter($flat, static function ($block) {
    return 'core/group' === ($block['blockName'] ?? '');
}));
$images = \array_values(\array_filter($flat, static function ($block) {
    return 'core/image' === ($block['blockName'] ?? '');
}));
$class_names = \array_map(static function ($block) {
    return $block['attrs']['className'] ?? '';
}, $flat);
$assert(!\str_contains($serialized, '<!-- wp:html -->'), 'ember-rye-fragment-avoids-core-html', $serialized);
$assert(\count($blocks) === 2, 'ember-rye-top-level-block-count', (string) \count($blocks));
$assert(\in_array('hero-media', $class_names, \true), 'hero-media-class-survives', \implode(', ', $class_names));
$assert(\in_array('photo-collage reveal', $class_names, \true), 'photo-collage-classes-survive', \implode(', ', $class_names));
$assert(\count($groups) === 2, 'hero-and-collage-use-group-blocks', \implode(', ', $names));
$assert(($blocks[0]['attrs']['ariaLabel'] ?? '') === 'A wood-fired pizza coming out of a glowing oven', 'hero-media-aria-label-survives', $serialized);
$assert(($blocks[1]['attrs']['ariaLabel'] ?? '') === 'Restaurant food and dining photography', 'photo-collage-aria-label-survives', $serialized);
$assert(\count($images) === 3, 'photo-collage-has-three-image-blocks', \implode(', ', $names));
$expected_alts = ['Wood-fired pizza with basil and melted mozzarella', 'Friends sharing food at a warm restaurant table', 'Fresh pizza topped with herbs'];
foreach ($expected_alts as $index => $alt) {
    $assert(($images[$index]['attrs']['alt'] ?? '') === $alt, 'photo-collage-alt-' . ($index + 1) . '-survives', $serialized);
    $assert(\str_contains($images[$index]['attrs']['url'] ?? '', 'images.unsplash.com/photo-'), 'photo-collage-url-' . ($index + 1) . '-survives', $serialized);
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
