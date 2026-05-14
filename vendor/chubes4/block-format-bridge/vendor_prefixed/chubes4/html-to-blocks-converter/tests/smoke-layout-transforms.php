<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: high-confidence layout raw transforms.
 *
 * Run: php tests/smoke-layout-transforms.php
 *
 * Exits 0 on pass, 1 on failure. Uses small WordPress stubs so it can run
 * deterministically outside a site that may already have another h2bc copy
 * loaded by a drop-in or composer autoloader.
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
            return \in_array($name, ['core/group', 'core/columns', 'core/column', 'core/cover', 'core/spacer', 'core/heading', 'core/paragraph', 'core/html'], \true);
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
        return wp_strip_all_tags($text);
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-transform-registry.php';
class Layout_Smoke_Element
{
    private string $tag_name;
    private array $attributes;
    private string $inner_html;
    private array $children;
    public function __construct(string $tag_name, array $attributes = [], string $inner_html = '', array $children = [])
    {
        $this->tag_name = \strtoupper($tag_name);
        $this->attributes = \array_change_key_case($attributes, \CASE_LOWER);
        $this->inner_html = $inner_html;
        $this->children = $children;
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
        return $this->children;
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
$smoke_assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
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
    $html = $args['HTML'] ?? '';
    if (\strpos($html, '<h1') !== \false || \strpos($html, '<h2') !== \false) {
        return [HTML_To_Blocks_Block_Factory::create_block('core/heading', ['level' => 2, 'content' => 'Heading'])];
    }
    return [HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => 'Paragraph'])];
};
// --------------------------------------------------------------------------
// Group: section is a high-confidence semantic wrapper, and inner content must
// recurse through the normal raw handler.
// --------------------------------------------------------------------------
$section = new Layout_Smoke_Element('section', ['id' => 'intro', 'class' => 'intro-section alignwide', 'aria-label' => 'Introduction'], '<h2>Intro</h2><p>Hello</p>');
$group_transform = $find_transform($section, 'core/group');
$group = $group_transform ? \call_user_func($group_transform['transform'], $section, $handler) : null;
$smoke_assert($group && 'core/group' === $group['blockName'], 'section-to-group');
$smoke_assert(($group['attrs']['anchor'] ?? '') === 'intro', 'group-preserves-anchor');
$smoke_assert(\strpos($group['attrs']['className'] ?? '', 'intro-section') !== \false, 'group-preserves-class');
$smoke_assert(($group['attrs']['align'] ?? '') === 'wide', 'group-preserves-align');
$smoke_assert(($group['attrs']['tagName'] ?? '') === 'section', 'group-preserves-tag-name');
$smoke_assert(($group['attrs']['ariaLabel'] ?? '') === 'Introduction', 'group-preserves-aria-label');
$smoke_assert(\count($group['innerBlocks'] ?? []) === 1, 'group-preserves-inner-blocks');
$smoke_assert(($group['innerBlocks'][0]['blockName'] ?? '') === 'core/heading', 'group-inner-heading');
$inline_flex_hero = new Layout_Smoke_Element('section', ['class' => 'hero', 'style' => 'min-height: 100svh; display: flex; flex-direction: column; justify-content: center; padding: 9rem 2rem 5rem;'], '<div class="container"><h1>Launch</h1></div><div class="hero-code-window"></div><div class="hero-scroll-hint"><span class="scroll-line"></span><p>scroll</p></div>');
$inline_flex_transform = $find_transform($inline_flex_hero, 'core/group');
$inline_flex_hero_group = $inline_flex_transform ? \call_user_func($inline_flex_transform['transform'], $inline_flex_hero, $handler) : null;
$smoke_assert($inline_flex_hero_group && 'core/group' === $inline_flex_hero_group['blockName'], 'inline-flex-hero-to-group');
$smoke_assert(($inline_flex_hero_group['attrs']['layout']['type'] ?? '') === 'flex', 'inline-flex-hero-preserves-layout-type');
$smoke_assert(($inline_flex_hero_group['attrs']['layout']['orientation'] ?? '') === 'vertical', 'inline-flex-hero-preserves-layout-orientation');
$smoke_assert(($inline_flex_hero_group['attrs']['layout']['justifyContent'] ?? '') === 'center', 'inline-flex-hero-preserves-justify-content');
$smoke_assert(($inline_flex_hero_group['attrs']['style']['dimensions']['minHeight'] ?? '') === '100svh', 'inline-flex-hero-preserves-min-height');
$smoke_assert(($inline_flex_hero_group['attrs']['style']['spacing']['padding'] ?? '') === '9rem 2rem 5rem', 'inline-flex-hero-preserves-padding');
$class_only_hero = new Layout_Smoke_Element('section', ['class' => 'hero'], '<div class="container"><h1>Launch</h1></div><div class="hero-code-window"></div><div class="hero-scroll-hint"><span class="scroll-line"></span><p>scroll</p></div>');
$class_only_transform = $find_transform($class_only_hero, 'core/group');
$class_only_hero_group = $class_only_transform ? \call_user_func($class_only_transform['transform'], $class_only_hero, $handler) : null;
$smoke_assert($class_only_hero_group && 'core/group' === $class_only_hero_group['blockName'], 'class-only-hero-to-group');
$smoke_assert(!isset($class_only_hero_group['attrs']['layout']), 'class-only-hero-does-not-invent-flex-layout');
$stack_group_element = new Layout_Smoke_Element('div', ['class' => 'wp-block-group is-layout-flex is-vertical is-nowrap is-content-justification-space-between custom-stack'], '<p>Stacked copy</p>');
$stack_group_transform = $find_transform($stack_group_element, 'core/group');
$stack_group = $stack_group_transform ? \call_user_func($stack_group_transform['transform'], $stack_group_element, $handler) : null;
$smoke_assert($stack_group && 'core/group' === $stack_group['blockName'], 'explicit-wp-layout-group-to-group');
$smoke_assert(($stack_group['attrs']['layout']['type'] ?? '') === 'flex', 'group-preserves-layout-type');
$smoke_assert(($stack_group['attrs']['layout']['orientation'] ?? '') === 'vertical', 'group-preserves-layout-orientation');
$smoke_assert(($stack_group['attrs']['layout']['flexWrap'] ?? '') === 'nowrap', 'group-preserves-layout-nowrap');
$smoke_assert(($stack_group['attrs']['layout']['justifyContent'] ?? '') === 'space-between', 'group-preserves-layout-justification');
$smoke_assert(($stack_group['attrs']['className'] ?? '') === 'custom-stack', 'group-filters-generated-layout-classes');
$main = new Layout_Smoke_Element('main', ['class' => 'site-shell'], '<section class="hero"><h1>Site Editor Template Smoke</h1><p>Template raw HTML should become blocks.</p></section>');
$main_transform = $find_transform($main, 'core/group');
$main_group = $main_transform ? \call_user_func($main_transform['transform'], $main, $handler) : null;
$landmark_tags = ['header', 'footer', 'article', 'aside'];
$smoke_assert($main_group && 'core/group' === $main_group['blockName'], 'main-landmark-to-group');
$smoke_assert(\strpos($main_group['attrs']['className'] ?? '', 'site-shell') !== \false, 'main-landmark-preserves-class');
$smoke_assert(($main_group['innerBlocks'][0]['blockName'] ?? '') === 'core/heading', 'main-landmark-recurses-children');
$wrap_group_element = new Layout_Smoke_Element('div', ['class' => 'wrap'], '<h2>Wrapped static-site copy</h2>');
$wrap_group_transform = $find_transform($wrap_group_element, 'core/group');
$wrap_group = $wrap_group_transform ? \call_user_func($wrap_group_transform['transform'], $wrap_group_element, $handler) : null;
$smoke_assert($wrap_group && 'core/group' === $wrap_group['blockName'], 'wrap-wrapper-to-group');
$smoke_assert(($wrap_group['attrs']['className'] ?? '') === 'wrap', 'wrap-wrapper-preserves-class');
$smoke_assert(($wrap_group['innerBlocks'][0]['blockName'] ?? '') === 'core/heading', 'wrap-wrapper-recurses-children');
$grid_group_element = new Layout_Smoke_Element('div', ['class' => 'grid cols-3'], '<article class="card"><h3>Card</h3><p>Copy</p></article>');
$grid_group_transform = $find_transform($grid_group_element, 'core/group');
$grid_group = $grid_group_transform ? \call_user_func($grid_group_transform['transform'], $grid_group_element, $handler) : null;
$smoke_assert($grid_group && 'core/group' === $grid_group['blockName'], 'grid-wrapper-to-group');
$smoke_assert(($grid_group['attrs']['className'] ?? '') === 'grid cols-3', 'grid-wrapper-preserves-class');
$smoke_assert(($grid_group['innerBlocks'][0]['blockName'] ?? '') === 'core/paragraph', 'grid-wrapper-recurses-children');
foreach ($landmark_tags as $tag) {
    $landmark = new Layout_Smoke_Element($tag, [], '<p>Landmark copy</p>');
    $landmark_transform = $find_transform($landmark, 'core/group');
    $landmark_group = $landmark_transform ? \call_user_func($landmark_transform['transform'], $landmark, $handler) : null;
    $smoke_assert($landmark_group && 'core/group' === $landmark_group['blockName'], $tag . '-landmark-to-group');
    $smoke_assert(($landmark_group['innerBlocks'][0]['blockName'] ?? '') === 'core/paragraph', $tag . '-landmark-recurses-children');
}
// --------------------------------------------------------------------------
// Columns: require an explicit row/grid signal plus direct column-like children.
// --------------------------------------------------------------------------
$left = new Layout_Smoke_Element('div', ['class' => 'col-md-6'], '<p>Left</p>');
$right = new Layout_Smoke_Element('div', ['class' => 'col-md-6'], '<p>Right</p>');
$row = new Layout_Smoke_Element('div', ['class' => 'row'], '', [$left, $right]);
$columns_transform = $find_transform($row, 'core/columns');
$columns = $columns_transform ? \call_user_func($columns_transform['transform'], $row, $handler) : null;
$smoke_assert($columns && 'core/columns' === $columns['blockName'], 'row-to-columns');
$smoke_assert(\count($columns['innerBlocks'] ?? []) === 2, 'columns-has-two-columns');
$smoke_assert(($columns['innerBlocks'][0]['blockName'] ?? '') === 'core/column', 'first-child-column');
$smoke_assert(($columns['innerBlocks'][1]['blockName'] ?? '') === 'core/column', 'second-child-column');
$smoke_assert(\strpos($columns['innerBlocks'][0]['attrs']['className'] ?? '', 'col-md-6') !== \false, 'column-preserves-class');
$smoke_assert(($columns['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? '') === 'core/paragraph', 'column-preserves-inner-paragraph');
// --------------------------------------------------------------------------
// Cover: require a hero/cover signal with an explicit background style.
// --------------------------------------------------------------------------
$hero = new Layout_Smoke_Element('section', ['id' => 'hero', 'class' => 'hero', 'style' => 'background-image: url(/hero.jpg); background-color: #123456;'], '<h1>Launch</h1>');
$cover_transform = $find_transform($hero, 'core/cover');
$cover = $cover_transform ? \call_user_func($cover_transform['transform'], $hero, $handler) : null;
$smoke_assert($cover && 'core/cover' === $cover['blockName'], 'hero-to-cover');
$smoke_assert(($cover['attrs']['anchor'] ?? '') === 'hero', 'cover-preserves-anchor');
$smoke_assert(($cover['attrs']['tagName'] ?? '') === 'section', 'cover-preserves-tag-name');
$smoke_assert(($cover['attrs']['url'] ?? '') === '/hero.jpg', 'cover-preserves-background-image');
$smoke_assert(($cover['attrs']['customOverlayColor'] ?? '') === '#123456', 'cover-preserves-background-color');
$smoke_assert(($cover['innerBlocks'][0]['blockName'] ?? '') === 'core/heading', 'cover-preserves-inner-heading');
// --------------------------------------------------------------------------
// Spacer: only empty explicit spacer elements with an explicit height qualify.
// --------------------------------------------------------------------------
$spacer_element = new Layout_Smoke_Element('div', ['class' => 'spacer', 'style' => 'height: 48px'], '');
$spacer_transform = $find_transform($spacer_element, 'core/spacer');
$spacer = $spacer_transform ? \call_user_func($spacer_transform['transform'], $spacer_element, $handler) : null;
$smoke_assert($spacer && 'core/spacer' === $spacer['blockName'], 'explicit-spacer-to-spacer');
$smoke_assert(($spacer['attrs']['height'] ?? '') === '48px', 'spacer-preserves-height');
// --------------------------------------------------------------------------
// Fallback safety: arbitrary divs must not become layout blocks.
// --------------------------------------------------------------------------
$ambiguous = new Layout_Smoke_Element('div', [], '<p>Ambiguous</p>');
$ambiguous_flex = new Layout_Smoke_Element('div', ['style' => 'display:flex; gap: 2rem;'], '<p>Ambiguous flex</p>');
$smoke_assert($find_transform($ambiguous, 'core/group') === null, 'ambiguous-div-not-group');
$smoke_assert($find_transform($ambiguous, 'core/columns') === null, 'ambiguous-div-not-columns');
$smoke_assert($find_transform($ambiguous, 'core/cover') === null, 'ambiguous-div-not-cover');
$smoke_assert($find_transform($ambiguous, 'core/spacer') === null, 'ambiguous-div-not-spacer');
$smoke_assert($find_transform($ambiguous_flex, 'core/group') === null, 'display-flex-div-not-group');
$smoke_assert($find_transform($ambiguous_flex, 'core/columns') === null, 'display-flex-div-not-columns');
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
