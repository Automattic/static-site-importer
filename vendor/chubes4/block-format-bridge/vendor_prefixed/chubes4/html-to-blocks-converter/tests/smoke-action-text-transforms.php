<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: action/text raw transforms.
 *
 * Run: php tests/smoke-action-text-transforms.php
 *
 * Exits 0 on pass, 1 on failure. No WordPress required.
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
        return \strip_tags((string) $value);
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
        $attributes = \array_fill_keys(['anchor', 'citation', 'className', 'content', 'level', 'linkTarget', 'rel', 'summary', 'text', 'url', 'value'], ['type' => 'string']);
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
// Buttons: native WordPress button anchors become core/buttons > core/button.
// -------------------------------------------------------------------------
$button_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link wp-element-button">Buy <strong>Now</strong></a></p>', '<a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link wp-element-button">Buy <strong>Now</strong></a>');
$button_transform = $find_transform($button_paragraph);
$button_block = \call_user_func($button_transform['transform'], $button_paragraph, $handler);
$smoke_assert('core/buttons' === $button_transform['blockName'], 'button-transform-selected');
$smoke_assert('core/buttons' === $button_block['blockName'], 'button-wrapper-block-name');
$smoke_assert(\count($button_block['innerBlocks']) === 1, 'button-wrapper-has-one-child');
$smoke_assert('core/button' === $button_block['innerBlocks'][0]['blockName'], 'button-child-block-name');
$smoke_assert('/buy' === $button_block['innerBlocks'][0]['attrs']['url'], 'button-url-preserved');
$smoke_assert('_blank' === $button_block['innerBlocks'][0]['attrs']['linkTarget'], 'button-target-preserved');
$smoke_assert('nofollow' === $button_block['innerBlocks'][0]['attrs']['rel'], 'button-rel-preserved');
$smoke_assert(\strpos($button_block['innerBlocks'][0]['attrs']['className'], 'btn-primary') !== \false, 'button-class-preserved');
$smoke_assert(\strpos($button_block['innerBlocks'][0]['innerHTML'], 'Buy <strong>Now</strong>') !== \false, 'button-rich-text-preserved');
$button_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions'], '<div class="hero-actions"><a class="wp-block-button__link" href="/manifesto/">Read the Manifesto &rarr;</a><a class="wp-block-button__link" href="/proof/">See The Proof</a></div>', '<a class="wp-block-button__link" href="/manifesto/">Read the Manifesto &rarr;</a><a class="wp-block-button__link" href="/proof/">See The Proof</a>');
$button_row_transform = $find_transform($button_row);
$button_row_block = \call_user_func($button_row_transform['transform'], $button_row, $handler);
$smoke_assert('core/buttons' === $button_row_transform['blockName'], 'button-row-transform-selected');
$smoke_assert('core/buttons' === $button_row_block['blockName'], 'button-row-wrapper-block-name');
$smoke_assert(\count($button_row_block['innerBlocks']) === 2, 'button-row-has-two-children');
$smoke_assert('core/button' === $button_row_block['innerBlocks'][0]['blockName'], 'button-row-first-child-block-name');
$smoke_assert('core/button' === $button_row_block['innerBlocks'][1]['blockName'], 'button-row-second-child-block-name');
$smoke_assert('/manifesto/' === $button_row_block['innerBlocks'][0]['attrs']['url'], 'button-row-first-url-preserved');
$smoke_assert('/proof/' === $button_row_block['innerBlocks'][1]['attrs']['url'], 'button-row-second-url-preserved');
$smoke_assert(\strpos($button_row_block['innerBlocks'][0]['innerHTML'], 'href="/proof/"') === \false, 'button-row-first-child-does-not-contain-second-anchor');
$custom_button_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="#order" class="btn btn-primary">Order Online</a></p>', '<a href="#order" class="btn btn-primary">Order Online</a>');
$custom_button_transform = $find_transform($custom_button_paragraph);
$custom_button_block = \call_user_func($custom_button_transform['transform'], $custom_button_paragraph, $handler);
$smoke_assert('core/buttons' === $custom_button_transform['blockName'], 'custom-button-anchor-becomes-buttons');
$smoke_assert(\count($custom_button_block['innerBlocks']) === 1, 'custom-button-anchor-has-one-button');
$smoke_assert('core/button' === $custom_button_block['innerBlocks'][0]['blockName'], 'custom-button-anchor-child-block-name');
$smoke_assert('#order' === $custom_button_block['innerBlocks'][0]['attrs']['url'], 'custom-button-anchor-url-preserved');
$smoke_assert('btn btn-primary' === $custom_button_block['innerBlocks'][0]['attrs']['className'], 'custom-button-anchor-class-preserved');
$nav_cta_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="#try" class="btn nav-cta">Request Access</a></p>', '<a href="#try" class="btn nav-cta">Request Access</a>');
$nav_cta_transform = $find_transform($nav_cta_paragraph);
$nav_cta_block = \call_user_func($nav_cta_transform['transform'], $nav_cta_paragraph, $handler);
$smoke_assert('core/buttons' === $nav_cta_transform['blockName'], 'nav-cta-button-anchor-becomes-buttons');
$smoke_assert(\count($nav_cta_block['innerBlocks']) === 1, 'nav-cta-button-anchor-has-one-button');
$smoke_assert('#try' === $nav_cta_block['innerBlocks'][0]['attrs']['url'], 'nav-cta-button-anchor-url-preserved');
$smoke_assert('btn nav-cta' === $nav_cta_block['innerBlocks'][0]['attrs']['className'], 'nav-cta-button-anchor-class-preserved');
$custom_button_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions'], '<div class="hero-actions"><a href="#order" class="btn btn-primary">Order Online</a><a href="#our-bakes" class="btn btn-ghost">See Our Bakes</a></div>', '<a href="#order" class="btn btn-primary">Order Online</a><a href="#our-bakes" class="btn btn-ghost">See Our Bakes</a>');
$custom_button_row_transform = $find_transform($custom_button_row);
$custom_button_row_block = \call_user_func($custom_button_row_transform['transform'], $custom_button_row, $handler);
$smoke_assert('core/buttons' === $custom_button_row_transform['blockName'], 'custom-button-row-becomes-buttons');
$smoke_assert('core/buttons' === $custom_button_row_block['blockName'], 'custom-button-row-block-name');
$smoke_assert(\count($custom_button_row_block['innerBlocks']) === 2, 'custom-button-row-has-two-buttons');
$smoke_assert('hero-actions' === $custom_button_row_block['attrs']['className'], 'custom-button-row-wrapper-class-preserved');
$smoke_assert('#order' === $custom_button_row_block['innerBlocks'][0]['attrs']['url'], 'custom-button-row-first-url-preserved');
$smoke_assert('#our-bakes' === $custom_button_row_block['innerBlocks'][1]['attrs']['url'], 'custom-button-row-second-url-preserved');
$smoke_assert('btn btn-primary' === $custom_button_row_block['innerBlocks'][0]['attrs']['className'], 'custom-button-row-first-class-preserved');
$smoke_assert('btn btn-ghost' === $custom_button_row_block['innerBlocks'][1]['attrs']['className'], 'custom-button-row-second-class-preserved');
$custom_cta_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions'], '<div class="cta-actions"><a href="/early-access/" class="cta-primary">Get Early Access <img src="/assets/arrow.svg" alt="" class="materialized-icon"></a><a href="/demo/" class="cta-secondary">View Demo</a></div>', '<a href="/early-access/" class="cta-primary">Get Early Access <img src="/assets/arrow.svg" alt="" class="materialized-icon"></a><a href="/demo/" class="cta-secondary">View Demo</a>');
$custom_cta_row_transform = $find_transform($custom_cta_row);
$custom_cta_row_block = \call_user_func($custom_cta_row_transform['transform'], $custom_cta_row, $handler);
$smoke_assert('core/buttons' === $custom_cta_row_transform['blockName'], 'custom-cta-row-becomes-buttons');
$smoke_assert(\count($custom_cta_row_block['innerBlocks']) === 2, 'custom-cta-row-has-two-buttons');
$smoke_assert('cta-actions' === $custom_cta_row_block['attrs']['className'], 'custom-cta-row-wrapper-class-preserved');
$smoke_assert('/early-access/' === $custom_cta_row_block['innerBlocks'][0]['attrs']['url'], 'custom-cta-row-first-url-preserved');
$smoke_assert('cta-primary' === $custom_cta_row_block['innerBlocks'][0]['attrs']['className'], 'custom-cta-row-first-class-preserved');
$smoke_assert(\strpos($custom_cta_row_block['innerBlocks'][0]['attrs']['text'], 'Get Early Access') !== \false, 'custom-cta-row-text-preserved');
$smoke_assert(\strpos($custom_cta_row_block['innerBlocks'][0]['attrs']['text'], '<img src="/assets/arrow.svg" alt="" class="materialized-icon">') !== \false, 'custom-cta-row-icon-image-preserved');
$custom_cta_anchor = new HTML_To_Blocks_HTML_Element('a', ['href' => 'mailto:hello@saltandstar.com', 'class' => 'btn-cta'], '<a href="mailto:hello@saltandstar.com" class="btn-cta">Place an Order</a>', 'Place an Order');
$custom_cta_transform = $find_transform($custom_cta_anchor);
$custom_cta_block = \call_user_func($custom_cta_transform['transform'], $custom_cta_anchor, $handler);
$smoke_assert('core/paragraph' === $custom_cta_transform['blockName'], 'custom-cta-anchor-stays-paragraph');
$smoke_assert(\strpos($custom_cta_block['attrs']['content'], '<a href="mailto:hello@saltandstar.com" class="btn-cta">Place an Order</a>') !== \false, 'custom-cta-anchor-preserved');
$smoke_assert(\strpos($custom_cta_block['attrs']['content'], 'wp-element-button') === \false, 'custom-cta-anchor-avoids-wp-button-class');
$class_sensitive_cta_anchor = new HTML_To_Blocks_HTML_Element('a', ['class' => 'cta-btn', 'href' => '#install'], '<a class="cta-btn" href="#install">Install Now</a>', 'Install Now');
$class_sensitive_cta_transform = $find_transform($class_sensitive_cta_anchor);
$class_sensitive_cta_block = \call_user_func($class_sensitive_cta_transform['transform'], $class_sensitive_cta_anchor, $handler);
$smoke_assert('core/paragraph' === $class_sensitive_cta_transform['blockName'], 'class-sensitive-cta-anchor-stays-paragraph');
$smoke_assert(\strpos($class_sensitive_cta_block['attrs']['content'], '<a class="cta-btn" href="#install">Install Now</a>') !== \false, 'class-sensitive-cta-anchor-class-remains-on-link');
$smoke_assert(\strpos($class_sensitive_cta_block['attrs']['content'], 'wp-block-button') === \false, 'class-sensitive-cta-anchor-avoids-button-wrapper');
$class_sensitive_cta_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions'], '<div class="cta-actions"><a class="cta-link" href="#commands">Browse the docs</a></div>', '<a class="cta-link" href="#commands">Browse the docs</a>');
$class_sensitive_cta_row_transform = $find_transform($class_sensitive_cta_row);
$smoke_assert('core/buttons' !== $class_sensitive_cta_row_transform['blockName'], 'class-sensitive-cta-row-avoids-buttons');
$button_variant_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions cta'], '<div class="hero-actions cta"><a class="button primary" href="#classes">Find a class</a><a class="button secondary" href="#first-visit">Plan your first visit</a></div>', '<a class="button primary" href="#classes">Find a class</a><a class="button secondary" href="#first-visit">Plan your first visit</a>');
$button_variant_row_transform = $find_transform($button_variant_row);
$smoke_assert('core/buttons' !== $button_variant_row_transform['blockName'], 'class-sensitive-button-variant-row-avoids-buttons');
$ordinary_link = new HTML_To_Blocks_HTML_Element('p', [], '<p>Read <a href="/more">more</a>.</p>', 'Read <a href="/more">more</a>.');
$ordinary_link_transform = $find_transform($ordinary_link);
$smoke_assert('core/paragraph' === $ordinary_link_transform['blockName'], 'ordinary-link-stays-paragraph');
$standalone_brand_anchor = new HTML_To_Blocks_HTML_Element('a', ['class' => 'brand', 'href' => '#top', 'aria-label' => 'Studio home'], '<a class="brand" href="#top" aria-label="Studio home"><span>Studio</span> <small>Tattoo</small></a>', '<span>Studio</span> <small>Tattoo</small>');
$standalone_brand_anchor_transform = $find_transform($standalone_brand_anchor);
$standalone_brand_anchor_block = \call_user_func($standalone_brand_anchor_transform['transform'], $standalone_brand_anchor, $handler);
$smoke_assert('core/paragraph' === $standalone_brand_anchor_transform['blockName'], 'standalone-brand-anchor-becomes-paragraph');
$smoke_assert(\strpos($standalone_brand_anchor_block['attrs']['content'], '<a class="brand" href="#top" aria-label="Studio home">') !== \false, 'standalone-brand-anchor-preserves-link-attributes');
$smoke_assert(!isset($standalone_brand_anchor_block['attrs']['className']), 'standalone-brand-anchor-keeps-class-on-link-only');
$smoke_assert(\strpos($standalone_brand_anchor_block['innerHTML'], '<!-- wp:html -->') === \false, 'standalone-brand-anchor-avoids-freeform');
$static_button_tabs = new HTML_To_Blocks_HTML_Element('div', ['class' => 'use-case-tabs'], '<div class="use-case-tabs"><button class="use-case-tab active">Product Managers</button><button class="use-case-tab">Engineering Leads</button><button class="use-case-tab">GTM Teams</button><button class="use-case-tab">Executives</button></div>', '<button class="use-case-tab active">Product Managers</button><button class="use-case-tab">Engineering Leads</button><button class="use-case-tab">GTM Teams</button><button class="use-case-tab">Executives</button>');
$static_button_tabs_transform = $find_transform($static_button_tabs);
$static_button_tabs_block = \call_user_func($static_button_tabs_transform['transform'], $static_button_tabs, $handler);
$smoke_assert('core/group' === $static_button_tabs_transform['blockName'], 'static-button-tabs-become-group');
$smoke_assert('use-case-tabs' === $static_button_tabs_block['attrs']['className'], 'static-button-tab-wrapper-class-preserved');
$smoke_assert(\count($static_button_tabs_block['innerBlocks']) === 4, 'static-button-tabs-children-preserved');
$smoke_assert('core/paragraph' === $static_button_tabs_block['innerBlocks'][0]['blockName'], 'static-button-tab-child-becomes-paragraph');
$smoke_assert('Product Managers' === $static_button_tabs_block['innerBlocks'][0]['attrs']['content'], 'static-button-tab-label-preserved');
$smoke_assert('use-case-tab active' === $static_button_tabs_block['innerBlocks'][0]['attrs']['className'], 'static-button-tab-class-preserved');
$smoke_assert(\strpos($static_button_tabs_block['innerHTML'], '<!-- wp:html -->') === \false, 'static-button-tabs-avoid-wp-html');
$static_chip_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'filter-chip active', 'type' => 'button'], '<button class="filter-chip active" type="button">Analytics</button>', 'Analytics');
$static_chip_button_transform = $find_transform($static_chip_button);
$static_chip_button_block = \call_user_func($static_chip_button_transform['transform'], $static_chip_button);
$smoke_assert('core/paragraph' === $static_chip_button_transform['blockName'], 'static-chip-button-becomes-paragraph');
$smoke_assert('Analytics' === $static_chip_button_block['attrs']['content'], 'static-chip-button-label-preserved');
$smoke_assert('filter-chip active' === $static_chip_button_block['attrs']['className'], 'static-chip-button-class-preserved');
$submit_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'use-case-tab', 'type' => 'submit'], '<button class="use-case-tab" type="submit">Submit</button>', 'Submit');
$smoke_assert($find_transform($submit_button) === null, 'submit-button-falls-through');
$form_owned_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'filter-chip', 'form' => 'filters'], '<button class="filter-chip" form="filters">Apply</button>', 'Apply');
$smoke_assert($find_transform($form_owned_button) === null, 'form-owned-button-falls-through');
// -------------------------------------------------------------------------
// Static visual buttons with inline JS handlers: handlers are dropped, label
// and class semantics survive as a native paragraph (no core/html fallback).
// Issue: https://github.com/chubes4/html-to-blocks-converter/issues/234
// -------------------------------------------------------------------------
$onclick_tab_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'tab-btn active', 'onclick' => "showDay('day1', this)"], '<button class="tab-btn active" onclick="showDay(\'day1\', this)">Day 1 — Friday, Sept 18</button>', 'Day 1 — Friday, Sept 18');
$onclick_tab_button_transform = $find_transform($onclick_tab_button);
$onclick_tab_button_block = \call_user_func($onclick_tab_button_transform['transform'], $onclick_tab_button);
$smoke_assert('core/paragraph' === $onclick_tab_button_transform['blockName'], 'onclick-tab-button-becomes-paragraph');
$smoke_assert('Day 1 — Friday, Sept 18' === $onclick_tab_button_block['attrs']['content'], 'onclick-tab-button-label-preserved');
$smoke_assert('tab-btn active' === $onclick_tab_button_block['attrs']['className'], 'onclick-tab-button-class-preserved');
$smoke_assert(\strpos($onclick_tab_button_block['innerHTML'], 'onclick') === \false, 'onclick-tab-button-strips-inline-handler');
$smoke_assert(\strpos($onclick_tab_button_block['innerHTML'], 'showDay') === \false, 'onclick-tab-button-strips-handler-payload');
$onclick_tab_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'tab-bar'], '<div class="tab-bar"><button class="tab-btn active" onclick="showDay(\'day1\', this)">Day 1 — Friday, Sept 18</button><button class="tab-btn" onclick="showDay(\'day2\', this)">Day 2 — Saturday, Sept 19</button></div>', '<button class="tab-btn active" onclick="showDay(\'day1\', this)">Day 1 — Friday, Sept 18</button><button class="tab-btn" onclick="showDay(\'day2\', this)">Day 2 — Saturday, Sept 19</button>');
$onclick_tab_row_transform = $find_transform($onclick_tab_row);
$onclick_tab_row_block = \call_user_func($onclick_tab_row_transform['transform'], $onclick_tab_row, $handler);
$smoke_assert('core/group' === $onclick_tab_row_transform['blockName'], 'onclick-tab-row-becomes-group');
$smoke_assert('tab-bar' === $onclick_tab_row_block['attrs']['className'], 'onclick-tab-row-wrapper-class-preserved');
$smoke_assert(\count($onclick_tab_row_block['innerBlocks']) === 2, 'onclick-tab-row-children-preserved');
$smoke_assert('core/paragraph' === $onclick_tab_row_block['innerBlocks'][0]['blockName'], 'onclick-tab-row-first-child-becomes-paragraph');
$smoke_assert('core/paragraph' === $onclick_tab_row_block['innerBlocks'][1]['blockName'], 'onclick-tab-row-second-child-becomes-paragraph');
$smoke_assert('Day 1 — Friday, Sept 18' === $onclick_tab_row_block['innerBlocks'][0]['attrs']['content'], 'onclick-tab-row-first-label-preserved');
$smoke_assert('Day 2 — Saturday, Sept 19' === $onclick_tab_row_block['innerBlocks'][1]['attrs']['content'], 'onclick-tab-row-second-label-preserved');
$smoke_assert('tab-btn active' === $onclick_tab_row_block['innerBlocks'][0]['attrs']['className'], 'onclick-tab-row-first-class-preserved');
$smoke_assert('tab-btn' === $onclick_tab_row_block['innerBlocks'][1]['attrs']['className'], 'onclick-tab-row-second-class-preserved');
$smoke_assert(\strpos($onclick_tab_row_block['innerHTML'], 'onclick') === \false, 'onclick-tab-row-wrapper-strips-handler');
foreach ($onclick_tab_row_block['innerBlocks'] as $index => $child_block) {
    $smoke_assert(\strpos($child_block['innerHTML'], 'onclick') === \false, 'onclick-tab-row-child-' . $index . '-strips-handler');
    $smoke_assert(\strpos($child_block['innerHTML'], 'showDay') === \false, 'onclick-tab-row-child-' . $index . '-strips-handler-payload');
}
$smoke_assert(\strpos($onclick_tab_row_block['innerHTML'], '<!-- wp:html -->') === \false, 'onclick-tab-row-avoids-wp-html');
// Other on* handlers (onmouseover, onfocus, etc.) should also be dropped without falling back.
$onmouseover_chip = new HTML_To_Blocks_HTML_Element('button', ['class' => 'filter-chip', 'onmouseover' => 'highlight(this)'], '<button class="filter-chip" onmouseover="highlight(this)">Hover me</button>', 'Hover me');
$onmouseover_chip_transform = $find_transform($onmouseover_chip);
$onmouseover_chip_block = \call_user_func($onmouseover_chip_transform['transform'], $onmouseover_chip);
$smoke_assert('core/paragraph' === $onmouseover_chip_transform['blockName'], 'onmouseover-chip-becomes-paragraph');
$smoke_assert('Hover me' === $onmouseover_chip_block['attrs']['content'], 'onmouseover-chip-label-preserved');
$smoke_assert(\strpos($onmouseover_chip_block['innerHTML'], 'onmouseover') === \false, 'onmouseover-chip-strips-handler');
// Form-control buttons with on* handlers must still fall through to a fallback,
// because dropping the handler would silently change behavior for real controls.
$onclick_submit_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'tab-btn', 'type' => 'submit', 'onclick' => 'submitForm()'], '<button class="tab-btn" type="submit" onclick="submitForm()">Submit</button>', 'Submit');
$smoke_assert($find_transform($onclick_submit_button) === null, 'onclick-submit-button-falls-through');
// -------------------------------------------------------------------------
// Spans: numeric classed leaf spans become editable paragraph markup.
// -------------------------------------------------------------------------
$classed_leaf_span = new HTML_To_Blocks_HTML_Element('span', ['class' => 'service-number'], '<span class="service-number">01</span>', '01');
$classed_leaf_span_transform = $find_transform($classed_leaf_span);
$smoke_assert($classed_leaf_span_transform !== null, 'numeric-classed-leaf-span-has-transform');
$classed_leaf_span_block = \call_user_func($classed_leaf_span_transform['transform'], $classed_leaf_span);
$smoke_assert(($classed_leaf_span_block['blockName'] ?? '') === 'core/paragraph', 'numeric-classed-leaf-span-becomes-paragraph');
$smoke_assert(($classed_leaf_span_block['attrs']['content'] ?? '') === '<span class="service-number">01</span>', 'numeric-classed-leaf-span-preserves-markup');
$classed_text_span = new HTML_To_Blocks_HTML_Element('span', ['class' => 'service-label'], '<span class="service-label">Repair</span>', 'Repair');
$smoke_assert($find_transform($classed_text_span) === null, 'classed-text-leaf-span-falls-through');
// -------------------------------------------------------------------------
// Labels: static visual UI labels become text, real form labels fall through.
// -------------------------------------------------------------------------
$visual_label = new HTML_To_Blocks_HTML_Element('label', ['class' => 'inspector-label'], '<label class="inspector-label">Type</label>', 'Type');
$visual_label_transform = $find_transform($visual_label);
$visual_label_block = \call_user_func($visual_label_transform['transform'], $visual_label, $handler);
$smoke_assert('core/paragraph' === $visual_label_transform['blockName'], 'visual-label-becomes-paragraph');
$smoke_assert('Type' === $visual_label_block['attrs']['content'], 'visual-label-content-preserved');
$smoke_assert('inspector-label' === $visual_label_block['attrs']['className'], 'visual-label-class-preserved');
$smoke_assert(\strpos($visual_label_block['innerHTML'], '<p class="inspector-label">Type</p>') !== \false, 'visual-label-renders-native-paragraph');
$rich_visual_label = new HTML_To_Blocks_HTML_Element('label', [], '<label>Overlay <strong>Color</strong></label>', 'Overlay <strong>Color</strong>');
$rich_visual_label_transform = $find_transform($rich_visual_label);
$rich_visual_label_block = \call_user_func($rich_visual_label_transform['transform'], $rich_visual_label, $handler);
$smoke_assert('core/paragraph' === $rich_visual_label_transform['blockName'], 'rich-visual-label-becomes-paragraph');
$smoke_assert('Overlay <strong>Color</strong>' === $rich_visual_label_block['attrs']['content'], 'rich-visual-label-inline-markup-preserved');
$form_label_for = new HTML_To_Blocks_HTML_Element('label', ['for' => 'field-type'], '<label for="field-type">Type</label>', 'Type');
$smoke_assert($find_transform($form_label_for) === null, 'form-label-for-falls-through');
$form_label_wrapping_input = new HTML_To_Blocks_HTML_Element('label', [], '<label>Type <input name="type"></label>', 'Type <input name="type">');
$smoke_assert($find_transform($form_label_wrapping_input) === null, 'form-label-wrapping-input-falls-through');
// -------------------------------------------------------------------------
// Details: summary becomes an attribute and nested content becomes blocks.
// -------------------------------------------------------------------------
$details = new HTML_To_Blocks_HTML_Element('details', [], '<details><summary>More <strong>info</strong></summary><p>Nested copy</p><ul><li>One</li></ul></details>', '<summary>More <strong>info</strong></summary><p>Nested copy</p><ul><li>One</li></ul>');
$details_transform = $find_transform($details);
$details_block = \call_user_func($details_transform['transform'], $details, $handler);
$smoke_assert('core/details' === $details_transform['blockName'], 'details-transform-selected');
$smoke_assert('More <strong>info</strong>' === $details_block['attrs']['summary'], 'details-summary-preserved');
$smoke_assert(\count($details_block['innerBlocks']) === 1, 'details-content-routed-through-handler');
$smoke_assert(\strpos($details_block['innerBlocks'][0]['attrs']['content'], '<p>Nested copy</p>') !== \false, 'details-nested-content-preserved');
// -------------------------------------------------------------------------
// Pullquote: explicit pullquote class wins, ordinary blockquote stays quote.
// -------------------------------------------------------------------------
$pullquote = new HTML_To_Blocks_HTML_Element('blockquote', ['class' => 'wp-block-pullquote'], '<blockquote class="wp-block-pullquote"><p>Big line</p><cite>Author</cite></blockquote>', '<p>Big line</p><cite>Author</cite>');
$pullquote_transform = $find_transform($pullquote);
$pullquote_block = \call_user_func($pullquote_transform['transform'], $pullquote, $handler);
$smoke_assert('core/pullquote' === $pullquote_transform['blockName'], 'pullquote-transform-selected');
$smoke_assert('<p>Big line</p>' === $pullquote_block['attrs']['value'], 'pullquote-value-preserved');
$smoke_assert('Author' === $pullquote_block['attrs']['citation'], 'pullquote-citation-preserved');
$quote = new HTML_To_Blocks_HTML_Element('blockquote', [], '<blockquote><p>Regular quote</p></blockquote>', '<p>Regular quote</p>');
$quote_transform = $find_transform($quote);
$smoke_assert('core/quote' === $quote_transform['blockName'], 'ordinary-blockquote-stays-quote');
// -------------------------------------------------------------------------
// Verse: explicit verse pre blocks preserve line breaks.
// -------------------------------------------------------------------------
$verse = new HTML_To_Blocks_HTML_Element('pre', ['class' => 'wp-block-verse'], "<pre class=\"wp-block-verse\">Line 1\nLine 2<br>Line 3</pre>", "Line 1\nLine 2<br>Line 3");
$verse_transform = $find_transform($verse);
$verse_block = \call_user_func($verse_transform['transform'], $verse, $handler);
$smoke_assert('core/verse' === $verse_transform['blockName'], 'verse-transform-selected');
$smoke_assert("Line 1\nLine 2<br>Line 3" === $verse_block['attrs']['content'], 'verse-content-preserves-line-breaks');
$smoke_assert(\strpos($verse_block['innerHTML'], "Line 1\nLine 2<br>Line 3") !== \false, 'verse-inner-html-preserves-line-breaks');
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
