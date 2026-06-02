<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: mechanical block-support mapping from HTML attributes.
 *
 * Run: php tests/smoke-block-supports.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
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
            return \in_array($name, ['core/heading', 'core/paragraph', 'core/group', 'core/separator', 'core/html'], \true);
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
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-transform-registry.php';
class Block_Supports_Smoke_Element
{
    private string $tag_name;
    private array $attributes;
    private string $inner_html;
    public function __construct(string $tag_name, array $attributes = [], string $inner_html = '')
    {
        $this->tag_name = \strtoupper($tag_name);
        $this->attributes = \array_change_key_case($attributes, \CASE_LOWER);
        $this->inner_html = $inner_html;
    }
    public function get_tag_name(): string
    {
        return $this->tag_name;
    }
    public function has_attribute(string $name): bool
    {
        return \array_key_exists(\strtolower($name), $this->attributes);
    }
    public function get_attribute(string $name): ?string
    {
        return $this->attributes[\strtolower($name)] ?? null;
    }
    public function get_inner_html(): string
    {
        return $this->inner_html;
    }
    public function get_child_elements(): array
    {
        return [];
    }
    public function query_selector(string $selector)
    {
        return null;
    }
    public function query_selector_all(string $selector): array
    {
        return [];
    }
    public function get_text_content(): string
    {
        return \trim(wp_strip_all_tags($this->inner_html));
    }
}
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$find_transform = static function ($element, string $block_name) {
    foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
        if (($transform['blockName'] ?? '') !== $block_name) {
            continue;
        }
        if (\is_callable($transform['isMatch'] ?? null) && \call_user_func($transform['isMatch'], $element)) {
            return $transform;
        }
    }
    return null;
};
$handler = static function ($args) {
    return [HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => 'Inner'])];
};
$heading = new Block_Supports_Smoke_Element('h2', ['id' => 'intro', 'class' => 'wp-block-heading alignwide custom-heading unsafe@class has-primary-color has-secondary-background-color has-large-font-size has-text-color has-background', 'style' => 'text-align: center; color: #123456; background-color: rgba(1, 2, 3, .4); margin-top: var(--wp--preset--spacing--40); padding: 2rem; border-color: red; border-style: solid; border-width: 2px; border-radius: 4px; transform: rotate(1deg); display: grid; position: absolute;'], 'Heading');
$heading_transform = $find_transform($heading, 'core/heading');
$heading_block = $heading_transform ? \call_user_func($heading_transform['transform'], $heading) : null;
$assert($heading_block && 'core/heading' === $heading_block['blockName'], 'heading-transform-found');
$assert(($heading_block['attrs']['anchor'] ?? '') === 'intro', 'heading-anchor');
$assert(($heading_block['attrs']['align'] ?? '') === 'wide', 'heading-align-wide');
$assert(($heading_block['attrs']['textAlign'] ?? '') === 'center', 'heading-text-align');
$assert(($heading_block['attrs']['className'] ?? '') === 'custom-heading', 'heading-safe-class-filter');
$assert(($heading_block['attrs']['textColor'] ?? '') === 'primary', 'heading-preset-text-color');
$assert(($heading_block['attrs']['backgroundColor'] ?? '') === 'secondary', 'heading-preset-background-color');
$assert(($heading_block['attrs']['fontSize'] ?? '') === 'large', 'heading-preset-font-size');
$assert(($heading_block['attrs']['style']['color']['text'] ?? '') === '#123456', 'heading-text-color');
$assert(($heading_block['attrs']['style']['color']['background'] ?? '') === 'rgba(1, 2, 3, .4)', 'heading-background-color');
$assert(($heading_block['attrs']['style']['spacing']['margin']['top'] ?? '') === 'var:preset|spacing|40', 'heading-margin-top-preset-var');
$assert(($heading_block['attrs']['style']['spacing']['padding'] ?? '') === '2rem', 'heading-padding');
$assert(($heading_block['attrs']['style']['border']['color'] ?? '') === 'red', 'heading-border-color');
$assert(($heading_block['attrs']['style']['border']['style'] ?? '') === 'solid', 'heading-border-style');
$assert(($heading_block['attrs']['style']['border']['width'] ?? '') === '2px', 'heading-border-width');
$assert(($heading_block['attrs']['style']['border']['radius'] ?? '') === '4px', 'heading-border-radius');
$assert(!isset($heading_block['attrs']['style']['transform']), 'heading-ignores-noisy-style');
$assert(!isset($heading_block['attrs']['style']['display']), 'heading-ignores-display-style');
$assert(!isset($heading_block['attrs']['style']['position']), 'heading-ignores-position-style');
$paragraph = new Block_Supports_Smoke_Element('p', ['class' => 'has-heading-2-font-size readable-copy', 'style' => 'font-size: var(--wp--preset--font-size--heading-2);'], 'Paragraph');
$paragraph_transform = $find_transform($paragraph, 'core/paragraph');
$paragraph_block = $paragraph_transform ? \call_user_func($paragraph_transform['transform'], $paragraph) : null;
$assert($paragraph_block && 'core/paragraph' === $paragraph_block['blockName'], 'paragraph-transform-found');
$assert(($paragraph_block['attrs']['fontSize'] ?? '') === 'heading-2', 'paragraph-preset-font-size-class-wins');
$assert(($paragraph_block['attrs']['className'] ?? '') === 'readable-copy', 'paragraph-filters-preset-font-class');
$group = new Block_Supports_Smoke_Element('section', ['id' => 'shell', 'class' => 'wp-block-group alignfull site-shell', 'aria-label' => 'Primary content', 'style' => 'background: #fff; padding-left: 3rem;'], '<p>Inner</p>');
$group_transform = $find_transform($group, 'core/group');
$group_block = $group_transform ? \call_user_func($group_transform['transform'], $group, $handler) : null;
$assert($group_block && 'core/group' === $group_block['blockName'], 'group-transform-found');
$assert(($group_block['attrs']['anchor'] ?? '') === 'shell', 'group-anchor');
$assert(($group_block['attrs']['align'] ?? '') === 'full', 'group-align-full');
$assert(($group_block['attrs']['className'] ?? '') === 'site-shell', 'group-safe-class-filter');
$assert(($group_block['attrs']['tagName'] ?? '') === 'section', 'group-tag-name');
$assert(($group_block['attrs']['ariaLabel'] ?? '') === 'Primary content', 'group-aria-label');
$assert(($group_block['attrs']['style']['color']['background'] ?? '') === '#fff', 'group-background');
$assert(($group_block['attrs']['style']['spacing']['padding']['left'] ?? '') === '3rem', 'group-padding-left');
$border_group = new Block_Supports_Smoke_Element('div', ['class' => 'wp-block-group benchmark-card', 'style' => 'border: 1px solid var(--border); background-color: var(--surface-2); margin-top: 2px; padding: 24px;'], '<p>Inner</p>');
$border_group_transform = $find_transform($border_group, 'core/group');
$border_group_block = $border_group_transform ? \call_user_func($border_group_transform['transform'], $border_group, $handler) : null;
$assert($border_group_block && 'core/group' === $border_group_block['blockName'], 'border-group-transform-found');
$assert(($border_group_block['attrs']['style']['border']['width'] ?? '') === '1px', 'group-border-shorthand-width');
$assert(($border_group_block['attrs']['style']['border']['style'] ?? '') === 'solid', 'group-border-shorthand-style');
$assert(($border_group_block['attrs']['style']['border']['color'] ?? '') === 'var(--border)', 'group-border-shorthand-color');
$assert(\str_contains($border_group_block['innerHTML'] ?? '', 'border-width:1px'), 'group-border-serialized-width', $border_group_block['innerHTML'] ?? '');
$assert(\str_contains($border_group_block['innerHTML'] ?? '', 'border-style:solid'), 'group-border-serialized-style', $border_group_block['innerHTML'] ?? '');
$assert(\str_contains($border_group_block['innerHTML'] ?? '', 'border-color:var(--border)'), 'group-border-serialized-color', $border_group_block['innerHTML'] ?? '');
$misparsed_border_group = new Block_Supports_Smoke_Element('div', ['class' => 'wp-block-group has-background benchmark-card', 'style' => 'border-width: 1px solid var(--border); background-color: var(--surface-2); margin-top: 2px; padding: 24px;'], '<p>Inner</p>');
$misparsed_border_group_transform = $find_transform($misparsed_border_group, 'core/group');
$misparsed_border_group_block = $misparsed_border_group_transform ? \call_user_func($misparsed_border_group_transform['transform'], $misparsed_border_group, $handler) : null;
$assert($misparsed_border_group_block && 'core/group' === $misparsed_border_group_block['blockName'], 'misparsed-border-group-transform-found');
$assert(!isset($misparsed_border_group_block['attrs']['style']['border']['width']), 'group-drops-invalid-border-width');
$assert(!\str_contains($misparsed_border_group_block['innerHTML'] ?? '', 'border-width:1px solid var(--border)'), 'group-does-not-serialize-invalid-border-width', $misparsed_border_group_block['innerHTML'] ?? '');
$separator = new Block_Supports_Smoke_Element('hr', ['class' => 'wp-block-separator alignwide is-style-dots custom-separator']);
$separator_transform = $find_transform($separator, 'core/separator');
$separator_block = $separator_transform ? \call_user_func($separator_transform['transform'], $separator) : null;
$assert($separator_block && 'core/separator' === $separator_block['blockName'], 'separator-transform-found');
$assert(($separator_block['attrs']['align'] ?? '') === 'wide', 'separator-align-wide');
$assert(($separator_block['attrs']['className'] ?? '') === 'is-style-dots custom-separator', 'separator-preserves-safe-classes');
$custom_separator = new Block_Supports_Smoke_Element('hr', ['class' => 'ep-divider']);
$custom_separator_transform = $find_transform($custom_separator, 'core/separator');
$custom_separator_block = $custom_separator_transform ? \call_user_func($custom_separator_transform['transform'], $custom_separator) : null;
$assert($custom_separator_block && 'core/separator' === $custom_separator_block['blockName'], 'custom-separator-transform-found');
$assert(($custom_separator_block['attrs']['className'] ?? '') === 'ep-divider', 'custom-separator-preserves-class');
$assert(($custom_separator_block['innerHTML'] ?? '') === '<hr class="wp-block-separator has-css-opacity ep-divider" />', 'custom-separator-serializes-gutenberg-default-opacity-class', $custom_separator_block['innerHTML'] ?? '');
$ruler_separator = new Block_Supports_Smoke_Element('div', ['class' => 'ruler', 'aria-hidden' => 'true']);
$ruler_separator_transform = $find_transform($ruler_separator, 'core/separator');
$ruler_separator_block = $ruler_separator_transform ? \call_user_func($ruler_separator_transform['transform'], $ruler_separator) : null;
$assert($ruler_separator_block && 'core/separator' === $ruler_separator_block['blockName'], 'ruler-separator-transform-found');
$assert(($ruler_separator_block['attrs']['className'] ?? '') === 'ruler', 'ruler-separator-preserves-class');
$assert(($ruler_separator_block['innerHTML'] ?? '') === '<hr class="wp-block-separator has-css-opacity ruler" />', 'ruler-separator-serializes-as-native-separator', $ruler_separator_block['innerHTML'] ?? '');
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
