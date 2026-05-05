<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: core/buttons preserves source horizontal alignment.
 *
 * Run: php tests/smoke-buttons-justify-content.php
 *
 * Exits 0 on pass, 1 on failure. No WordPress required.
 *
 * Regression coverage for
 * https://github.com/chubes4/html-to-blocks-converter/issues/247 — when a
 * button row carries inline `justify-content` the wrapper must round-trip
 * through Gutenberg's flex layout attributes instead of dropping the value.
 */
// phpcs:disable
\define('ABSPATH', __DIR__);
if (!\function_exists('BlockFormatBridge\Vendor\esc_attr')) {
    function esc_attr($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\esc_url')) {
    function esc_url($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($value)
    {
        return wp_strip_all_tags((string) $value);
    }
}
class WP_Block_Type_Registry
{
    private static $instance;
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function is_registered($name)
    {
        return \true;
    }
    public function get_registered($name)
    {
        $attributes = \array_fill_keys(['anchor', 'className', 'content', 'layout', 'linkTarget', 'rel', 'text', 'url'], ['type' => 'string']);
        // core/buttons stores layout as an object.
        $attributes['layout'] = ['type' => 'object'];
        return (object) ['attributes' => $attributes];
    }
}
\class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
require_once \dirname(__DIR__) . '/includes/class-html-element.php';
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
$failures = [];
$assertions = 0;
$smoke_assert = function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$find_transform = function ($element) {
    foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
        try {
            $is_match = \call_user_func($transform['isMatch'], $element);
        } catch (\Throwable $e) {
            $is_match = \false;
        }
        if ($is_match) {
            return $transform;
        }
    }
    return null;
};
$handler = function ($args) {
    return [HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => \trim($args['HTML'] ?? '')])];
};
// -------------------------------------------------------------------------
// Issue #247: inline justify-content on a button-anchor row must survive
// as Gutenberg-compatible flex layout attributes on the core/buttons block.
// -------------------------------------------------------------------------
$centered_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'actions', 'style' => 'justify-content:center'], '<div class="actions" style="justify-content:center"><a class="button" href="#top">Launch Studio Code</a><a class="button secondary" href="#workflow">Review the pipeline</a></div>', '<a class="button" href="#top">Launch Studio Code</a><a class="button secondary" href="#workflow">Review the pipeline</a>');
$centered_transform = $find_transform($centered_actions);
$centered_block = \call_user_func($centered_transform['transform'], $centered_actions, $handler);
$smoke_assert(null !== $centered_transform, 'centered-actions-transform-found');
$smoke_assert('core/buttons' === $centered_transform['blockName'], 'centered-actions-becomes-buttons');
$smoke_assert('core/buttons' === $centered_block['blockName'], 'centered-actions-block-name');
$smoke_assert('actions' === $centered_block['attrs']['className'], 'centered-actions-class-preserved');
$smoke_assert(isset($centered_block['attrs']['layout']), 'centered-actions-layout-attribute-set');
$smoke_assert(($centered_block['attrs']['layout']['type'] ?? '') === 'flex', 'centered-actions-layout-type-flex');
$smoke_assert(($centered_block['attrs']['layout']['justifyContent'] ?? '') === 'center', 'centered-actions-justify-center');
$smoke_assert(\count($centered_block['innerBlocks']) === 2, 'centered-actions-child-count');
// -------------------------------------------------------------------------
// Other inline horizontal alignments map to Gutenberg-supported values.
// -------------------------------------------------------------------------
$right_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions', 'style' => 'display:flex;justify-content:flex-end'], '<div class="cta-actions" style="display:flex;justify-content:flex-end"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$right_transform = $find_transform($right_actions);
$right_block = \call_user_func($right_transform['transform'], $right_actions, $handler);
$smoke_assert('core/buttons' === $right_transform['blockName'], 'right-actions-becomes-buttons');
$smoke_assert(($right_block['attrs']['layout']['justifyContent'] ?? '') === 'right', 'right-actions-justify-flex-end-mapped-to-right');
$left_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions', 'style' => 'justify-content:flex-start'], '<div class="cta-actions" style="justify-content:flex-start"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$left_transform = $find_transform($left_actions);
$left_block = \call_user_func($left_transform['transform'], $left_actions, $handler);
$smoke_assert(($left_block['attrs']['layout']['justifyContent'] ?? '') === 'left', 'left-actions-flex-start-mapped-to-left');
$between_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions', 'style' => 'justify-content:space-between'], '<div class="cta-actions" style="justify-content:space-between"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$between_transform = $find_transform($between_actions);
$between_block = \call_user_func($between_transform['transform'], $between_actions, $handler);
$smoke_assert(($between_block['attrs']['layout']['justifyContent'] ?? '') === 'space-between', 'between-actions-justify-preserved');
// -------------------------------------------------------------------------
// is-content-justification-* utility classes also drive the layout attribute.
// -------------------------------------------------------------------------
$class_centered_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'actions is-content-justification-center'], '<div class="actions is-content-justification-center"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$class_centered_transform = $find_transform($class_centered_actions);
$class_centered_block = \call_user_func($class_centered_transform['transform'], $class_centered_actions, $handler);
$smoke_assert(($class_centered_block['attrs']['layout']['justifyContent'] ?? '') === 'center', 'class-utility-centered-justify-preserved');
// -------------------------------------------------------------------------
// No alignment hint -> no layout attribute (preserve previous default).
// -------------------------------------------------------------------------
$plain_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'actions'], '<div class="actions"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$plain_transform = $find_transform($plain_actions);
$plain_block = \call_user_func($plain_transform['transform'], $plain_actions, $handler);
$smoke_assert('core/buttons' === $plain_transform['blockName'], 'plain-actions-still-buttons');
$smoke_assert(!isset($plain_block['attrs']['layout']), 'plain-actions-no-layout-attribute');
// -------------------------------------------------------------------------
// Unsupported justify-content values fall through silently.
// -------------------------------------------------------------------------
$weird_actions = new HTML_To_Blocks_HTML_Element('div', ['class' => 'actions', 'style' => 'justify-content:space-evenly'], '<div class="actions" style="justify-content:space-evenly"><a class="btn" href="/a">A</a><a class="btn" href="/b">B</a></div>', '<a class="btn" href="/a">A</a><a class="btn" href="/b">B</a>');
$weird_transform = $find_transform($weird_actions);
$weird_block = \call_user_func($weird_transform['transform'], $weird_actions, $handler);
$smoke_assert(!isset($weird_block['attrs']['layout']), 'unsupported-justify-value-skipped');
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
