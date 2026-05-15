<?php

namespace BlockFormatBridge\Vendor;

/**
 * Raw handler pipeline ported from Gutenberg JavaScript to PHP
 *
 * Uses WordPress HTML API (WP_HTML_Processor) for spec-compliant HTML5 parsing.
 * Converts HTML to Gutenberg blocks using registered transforms.
 */
if (!\defined('ABSPATH')) {
    exit;
}
/**
 * Main raw handler function - converts HTML to blocks
 *
 * @param array $args Arguments array with 'HTML' key and optional conversion context.
 * @return array Array of block arrays
 */
function html_to_blocks_raw_handler($args)
{
    $html = $args['HTML'] ?? '';
    if (empty($html)) {
        return array();
    }
    if (\strpos($html, '<!-- wp:') !== \false) {
        $blocks = parse_blocks($html);
        $is_single_freeform = \count($blocks) === 1 && isset($blocks[0]['blockName']) && 'core/freeform' === $blocks[0]['blockName'];
        if (!$is_single_freeform) {
            return html_to_blocks_normalize_parsed_image_html_blocks($blocks);
        }
        $freeform_html = html_to_blocks_get_parsed_block_html($blocks[0]);
        if ('' !== \trim($freeform_html)) {
            $html = $freeform_html;
        }
    }
    $pieces = html_to_blocks_shortcode_converter($html);
    $result = array();
    foreach ($pieces as $piece) {
        if (!\is_string($piece)) {
            $result[] = $piece;
            continue;
        }
        if (!html_to_blocks_can_skip_normalise_blocks($piece)) {
            $piece = html_to_blocks_normalise_blocks($piece);
        }
        $blocks = html_to_blocks_convert($piece, \array_merge($args, array('HTML' => $piece)));
        $result = \array_merge($result, $blocks);
    }
    return \array_filter($result);
}
/**
 * Gets the HTML payload from a parsed block.
 *
 * @param array $block Parsed block array.
 * @return string Block HTML.
 */
function html_to_blocks_get_parsed_block_html(array $block): string
{
    if (isset($block['innerHTML']) && \is_string($block['innerHTML'])) {
        return $block['innerHTML'];
    }
    if (empty($block['innerContent']) || !\is_array($block['innerContent'])) {
        return '';
    }
    $html = '';
    foreach ($block['innerContent'] as $content) {
        if (\is_string($content)) {
            $html .= $content;
        }
    }
    return $html;
}
/**
 * Determine whether block normalization can be skipped for an already wrapped fragment.
 *
 * Normalization only needs to repair top-level phrasing content. A fragment that is
 * already one complete block-like root can go straight to raw conversion, avoiding
 * an extra full scan of large generated HTML pages.
 *
 * @param string $html HTML fragment.
 * @return bool True when normalization can be skipped.
 */
function html_to_blocks_can_skip_normalise_blocks(string $html): bool
{
    $html = \trim($html);
    if ('' === $html || '<' !== $html[0] || !\preg_match('/^<\s*([a-z0-9:-]+)/i', $html, $matches)) {
        return \false;
    }
    $tag_name = \strtoupper($matches[1]);
    if (\in_array($tag_name, html_to_blocks_phrasing_tag_names(), \true)) {
        return \false;
    }
    return \trim((string) html_to_blocks_extract_balanced_element($html, $tag_name)) === $html;
}
/**
 * Gets tag names treated as phrasing content by block normalization.
 *
 * @return string[] Uppercase tag names.
 */
function html_to_blocks_phrasing_tag_names(): array
{
    return array('A', 'ABBR', 'B', 'BDI', 'BDO', 'BR', 'CITE', 'CODE', 'DATA', 'DFN', 'EM', 'I', 'KBD', 'MARK', 'Q', 'RP', 'RT', 'RUBY', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR', 'WBR');
}
/**
 * Calculate elapsed wall time in milliseconds.
 *
 * @param float $started Started timestamp from microtime(true).
 * @return float Elapsed milliseconds.
 */
function html_to_blocks_elapsed_ms(float $started): float
{
    return (\microtime(\true) - $started) * 1000;
}
/**
 * Accumulate per-transform trace metrics.
 *
 * @param array  $metrics Metrics accumulator.
 * @param string $name    Transform metric key.
 * @param string $field   Metric field.
 * @param float  $value   Value to add.
 * @return void
 */
function html_to_blocks_record_transform_metric(array &$metrics, string $name, string $field, float $value): void
{
    if (!isset($metrics['transforms'][$name])) {
        $metrics['transforms'][$name] = array('count' => 0, 'execute_ms' => 0.0);
    }
    $metrics['transforms'][$name][$field] = ($metrics['transforms'][$name][$field] ?? 0) + $value;
}
/**
 * Converts HTML directly to blocks using registered transforms
 *
 * @param string $html HTML to convert
 * @param array  $args Raw handler arguments for transform context.
 * @return array Array of blocks
 */
function html_to_blocks_convert($html, $args = array())
{
    if (empty(\trim($html))) {
        return array();
    }
    $collect_metrics = \function_exists('has_action') && \has_action('html_to_blocks_convert_metrics');
    $metrics = null;
    $convert_started = 0.0;
    if ($collect_metrics) {
        $metrics = array('html_bytes' => \strlen($html), 'token_count' => 0, 'top_level_element_count' => 0, 'extract_ms' => 0.0, 'element_parse_ms' => 0.0, 'transform_match_ms' => 0.0, 'transform_execute_ms' => 0.0, 'content_measure_ms' => 0.0, 'total_ms' => 0.0, 'transforms' => array());
        $convert_started = \microtime(\true);
    }
    $processor = \WP_HTML_Processor::create_fragment($html);
    if (!$processor) {
        if (\defined('WP_DEBUG') && \WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
            \error_log(\sprintf('[HTML to Blocks] create_fragment() failed | HTML length: %d | Preview: %s', \strlen($html), \substr($html, 0, 300)));
        }
        return array();
    }
    $original_html_length = \strlen($html);
    $blocks = array();
    $transforms = HTML_To_Blocks_Transform_Registry::get_raw_transforms();
    $body_depth = 2;
    $top_level_depth = $body_depth + 1;
    $tag_occurrences = array();
    $tag_positions = array();
    $ignored_decorative_html_length = 0;
    while ($processor->next_token()) {
        if ($collect_metrics) {
            ++$metrics['token_count'];
        }
        $token_type = $processor->get_token_type();
        $depth = $processor->get_current_depth();
        if ('#text' === $token_type && $depth === $top_level_depth) {
            $text = \trim($processor->get_modifiable_text());
            if (!empty($text)) {
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', array('content' => \htmlspecialchars($text, \ENT_QUOTES, 'UTF-8')));
            }
            continue;
        }
        if ('#tag' !== $token_type) {
            continue;
        }
        if ($processor->is_tag_closer()) {
            continue;
        }
        $tag_name = $processor->get_tag();
        if (!isset($tag_occurrences[$tag_name])) {
            $tag_occurrences[$tag_name] = 0;
            $tag_positions[$tag_name] = html_to_blocks_find_all_tag_positions($html, $tag_name);
        }
        $occurrence = $tag_occurrences[$tag_name]++;
        if ($depth !== $top_level_depth) {
            continue;
        }
        if ($collect_metrics) {
            ++$metrics['top_level_element_count'];
        }
        $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
        $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
        if ($collect_metrics) {
            $metrics['extract_ms'] += html_to_blocks_elapsed_ms($phase_started);
        }
        if (!$element_html) {
            if (\defined('WP_DEBUG') && \WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
                \error_log(\sprintf('[HTML to Blocks] Element extraction failed | Tag: %s | Occurrence: %d | HTML preview: %s', $tag_name, $occurrence, \substr($html, 0, 300)));
            }
            continue;
        }
        $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
        $element = HTML_To_Blocks_HTML_Element::from_html($element_html);
        if ($collect_metrics) {
            $metrics['element_parse_ms'] += html_to_blocks_elapsed_ms($phase_started);
        }
        if (!$element) {
            $blocks[] = html_to_blocks_create_unsupported_html_fallback_block($element_html, array('reason' => 'element_parse_failed', 'tag_name' => $tag_name, 'occurrence' => $occurrence));
            continue;
        }
        if ('BR' === $element->get_tag_name()) {
            $ignored_decorative_html_length += \strlen($element_html);
            continue;
        }
        if (html_to_blocks_should_ignore_empty_decorative_placeholder($element)) {
            $ignored_decorative_html_length += \strlen($element_html);
            continue;
        }
        $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
        $raw_transform = html_to_blocks_find_transform($element, $transforms);
        if ($collect_metrics) {
            $metrics['transform_match_ms'] += html_to_blocks_elapsed_ms($phase_started);
        }
        if (!$raw_transform) {
            if ($collect_metrics) {
                html_to_blocks_record_transform_metric($metrics, 'fallback:no_transform', 'count', 1);
            }
            $blocks[] = html_to_blocks_create_unsupported_html_fallback_block($element_html, array('reason' => 'no_transform', 'tag_name' => $element->get_tag_name(), 'occurrence' => $occurrence));
        } else {
            $transform_fn = $raw_transform['transform'] ?? null;
            $metric_name = (string) ($raw_transform['blockName'] ?? 'unknown') . ':p' . (string) ($raw_transform['priority'] ?? 'default');
            if ($collect_metrics) {
                html_to_blocks_record_transform_metric($metrics, $metric_name, 'count', 1);
            }
            if ($transform_fn && \is_callable($transform_fn)) {
                $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
                $raw_handler_fn = 'BlockFormatBridge\Vendor\html_to_blocks_raw_handler';
                $raw_handler_callback = function ($nested_args) use ($args, $raw_handler_fn) {
                    $nested_args = \is_array($nested_args) ? $nested_args : array();
                    return \call_user_func($raw_handler_fn, \array_merge($args, $nested_args));
                };
                $block = \call_user_func($transform_fn, $element, $raw_handler_callback, $args);
                if ($collect_metrics) {
                    $elapsed = html_to_blocks_elapsed_ms($phase_started);
                    $metrics['transform_execute_ms'] += $elapsed;
                    html_to_blocks_record_transform_metric($metrics, $metric_name, 'execute_ms', $elapsed);
                }
                if ($element->has_attribute('class')) {
                    $existing_class = $block['attrs']['className'] ?? '';
                    $node_class = $element->get_attribute('class');
                    $inner_html = $block['innerHTML'] ?? '';
                    if (!empty($node_class) && \strpos($existing_class, $node_class) === \false && \strpos($inner_html, $node_class) === \false) {
                        $block['attrs']['className'] = \trim($existing_class . ' ' . $node_class);
                    }
                }
                $blocks[] = $block;
            } else {
                $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
                $block_name = $raw_transform['blockName'];
                $attributes = HTML_To_Blocks_Attribute_Parser::get_block_attributes($block_name, $element_html);
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block($block_name, $attributes);
                if ($collect_metrics) {
                    $elapsed = html_to_blocks_elapsed_ms($phase_started);
                    $metrics['transform_execute_ms'] += $elapsed;
                    html_to_blocks_record_transform_metric($metrics, $metric_name, 'execute_ms', $elapsed);
                }
            }
        }
    }
    // Check if processor bailed due to unsupported HTML
    $last_error = $processor->get_last_error();
    if (null !== $last_error) {
        if (\defined('WP_DEBUG') && \WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
            \error_log(\sprintf('[HTML to Blocks] WP_HTML_Processor bailed | Error: %s | Blocks created: %d | HTML length: %d | Preview: %s', $last_error, \count($blocks), $original_html_length, \substr($html, 0, 500)));
        }
    }
    if (empty($blocks) && \trim(wp_strip_all_tags($html)) !== '' && \trim($html) === \trim(wp_strip_all_tags($html))) {
        $blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', array('content' => \trim($html)));
    }
    // Check for significant content loss (input had content but output is empty/minimal)
    $phase_started = $collect_metrics ? \microtime(\true) : 0.0;
    $output_content_length = html_to_blocks_measure_block_content_length($blocks);
    if ($collect_metrics) {
        $metrics['content_measure_ms'] += html_to_blocks_elapsed_ms($phase_started);
        $metrics['total_ms'] = html_to_blocks_elapsed_ms($convert_started);
        \do_action('html_to_blocks_convert_metrics', $metrics, $args);
    }
    $diagnostic_html_length = \max(0, $original_html_length - $ignored_decorative_html_length);
    if ($diagnostic_html_length > 100 && $output_content_length < $diagnostic_html_length * 0.1) {
        if (\defined('WP_DEBUG') && \WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
            \error_log(\sprintf('[HTML to Blocks] Significant content loss detected | Input: %d chars | Output: %d chars | Blocks: %d | Processor error: %s | Preview: %s', $diagnostic_html_length, $output_content_length, \count($blocks), $last_error ?? 'none', \substr($html, 0, 500)));
        }
    }
    return $blocks;
}
/**
 * Checks whether an empty div/span is a safe visual-only icon placeholder.
 *
 * @param HTML_To_Blocks_HTML_Element $element The source element.
 * @return bool True when the placeholder should be ignored.
 */
function html_to_blocks_should_ignore_empty_decorative_placeholder($element): bool
{
    if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
        return \false;
    }
    if (\trim(wp_strip_all_tags($element->get_inner_html())) !== '' || array() !== $element->get_child_elements()) {
        return \false;
    }
    $attributes = $element->get_attributes();
    if ('DIV' === $element->get_tag_name() && array() === $attributes) {
        return \true;
    }
    $class_name = isset($attributes['class']) ? (string) $attributes['class'] : '';
    $style = isset($attributes['style']) ? (string) $attributes['style'] : '';
    $role = isset($attributes['role']) ? \strtolower(\trim((string) $attributes['role'])) : '';
    $decorative_class_pattern = '/(?:^|[-_\s])(icon|ico|glyph|symbol|accent|bar|divider|separator|sep|rule|line|blank|orb|blob|dot|glow)(?:$|[-_\s]|\d)/i';
    if (\preg_match($decorative_class_pattern, $class_name) !== 1) {
        return \false;
    }
    foreach ($attributes as $name => $value) {
        $name = \strtolower((string) $name);
        if (\preg_match('/^on/i', $name)) {
            return \false;
        }
        if (!\in_array($name, array('class', 'style', 'id', 'aria-hidden', 'role'), \true)) {
            return \false;
        }
    }
    if ('' !== $role && !\in_array($role, array('none', 'presentation'), \true)) {
        return \false;
    }
    if (\preg_match('/url\s*\(/i', $style)) {
        return \false;
    }
    if (\preg_match('/(?:^|\s)code[-_]?dot(?:$|\s)/i', $class_name) === 1) {
        return \true;
    }
    if (\preg_match('/(?:^|[-_\s])(?:accent|sep)(?:$|[-_\s]|\d)/i', $class_name) === 1) {
        return \true;
    }
    return \preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)\b/i', $style) === 1 || \preg_match('/(?:^|;)\s*opacity\s*:\s*0(?:\.0+)?\b/i', $style) === 1 || \preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|pointer-events\s*:\s*none)\b/i', $style) === 1 || \strtolower((string) ($attributes['aria-hidden'] ?? '')) === 'true';
}
/**
 * Checks whether a span contains block-level markup that cannot live in a paragraph.
 *
 * @param HTML_To_Blocks_HTML_Element $element The source element.
 * @return bool True when the span should be promoted to a block wrapper.
 */
function html_to_blocks_is_blocky_span($element): bool
{
    if ('SPAN' !== $element->get_tag_name()) {
        return \false;
    }
    return \preg_match('/<(?:address|article|aside|blockquote|details|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul)\b/i', $element->get_inner_html()) === 1;
}
/**
 * Promotes an invalid span wrapper to a div while preserving safe attributes.
 *
 * @param HTML_To_Blocks_HTML_Element $element The span element.
 * @return string A valid block-level wrapper with the original contents.
 */
function html_to_blocks_promote_span_to_div_markup($element): string
{
    $attributes = '';
    foreach ($element->get_attributes() as $name => $value) {
        $name = \strtolower((string) $name);
        if (\preg_match('/^[a-z][a-z0-9:-]*$/', $name) !== 1) {
            continue;
        }
        if (\true === $value) {
            $attributes .= ' ' . $name;
            continue;
        }
        $attributes .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
    }
    return '<div' . $attributes . '>' . $element->get_inner_html() . '</div>';
}
/**
 * Measures converted block content, including nested layout descendants.
 *
 * @param array $blocks Converted block arrays.
 * @return int Approximate HTML content length.
 */
function html_to_blocks_measure_block_content_length(array $blocks): int
{
    $length = 0;
    foreach ($blocks as $block) {
        if (!\is_array($block)) {
            continue;
        }
        $length += \strlen((string) ($block['innerHTML'] ?? ''));
        if (isset($block['attrs']['content']) && \is_string($block['attrs']['content'])) {
            $length += \strlen($block['attrs']['content']);
        }
        if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
            $length += html_to_blocks_measure_block_content_length($block['innerBlocks']);
        }
    }
    return $length;
}
/**
 * Creates the core/html fallback block and emits an observability hook.
 *
 * @param string $element_html Unsupported HTML fragment.
 * @param array  $context      Fallback context such as reason, tag_name, and occurrence.
 * @return array Block array.
 */
function html_to_blocks_create_unsupported_html_fallback_block(string $element_html, array $context = array()): array
{
    $block = HTML_To_Blocks_Block_Factory::create_block('core/html', array('content' => $element_html));
    if (\function_exists('do_action')) {
        /**
         * Fires when h2bc falls back to core/html because no supported transform exists.
         *
         * @param string $element_html Unsupported HTML fragment.
         * @param array  $context      Context including reason, tag_name, and occurrence when available.
         * @param array  $block        The generated core/html fallback block.
         */
        \do_action('html_to_blocks_unsupported_html_fallback', $element_html, $context, $block);
    }
    return $block;
}
/**
 * Recursively converts parsed core/html image fragments back to native image blocks.
 *
 * Some upstream callers pass already-serialized block markup through h2bc. In that
 * path parse_blocks() would otherwise preserve harmless image-only core/html
 * fragments instead of applying the raw image transforms.
 *
 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
 * @return array<int|string,array<string,mixed>> Normalized blocks.
 */
function html_to_blocks_normalize_parsed_image_html_blocks(array $blocks): array
{
    $normalized = array();
    foreach ($blocks as $block) {
        if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
            $block['innerBlocks'] = html_to_blocks_normalize_parsed_image_html_blocks($block['innerBlocks']);
        }
        if (($block['blockName'] ?? null) !== 'core/html') {
            $normalized[] = $block;
            continue;
        }
        $html = '';
        if (isset($block['attrs']['content']) && \is_string($block['attrs']['content'])) {
            $html = $block['attrs']['content'];
        } elseif (isset($block['innerHTML']) && \is_string($block['innerHTML'])) {
            $html = $block['innerHTML'];
        }
        $convertible_html = $html;
        $is_decorative_inline_span = \false;
        $is_image_only_fragment = \false;
        $is_form_container = \false;
        if (html_to_blocks_is_decorative_inline_span_fragment($html)) {
            $is_decorative_inline_span = \true;
            $convertible_html = html_to_blocks_normalise_blocks($html);
        } elseif (html_to_blocks_is_image_only_html_fragment($html)) {
            $is_image_only_fragment = \true;
        } elseif (html_to_blocks_is_form_containing_container_fragment($html)) {
            $is_form_container = \true;
        } else {
            $normalized[] = $block;
            continue;
        }
        $converted = html_to_blocks_convert($convertible_html);
        if (empty($converted)) {
            $normalized[] = $block;
            continue;
        }
        if (($is_decorative_inline_span || $is_image_only_fragment) && html_to_blocks_contains_block_name($converted, 'core/html')) {
            $normalized[] = $block;
            continue;
        }
        if ($is_form_container && html_to_blocks_is_single_html_fallback_for_fragment($converted, $html)) {
            $normalized[] = $block;
            continue;
        }
        $normalized = \array_merge($normalized, $converted);
    }
    return $normalized;
}
/**
 * Checks whether a raw HTML fallback wraps a larger static container with form controls.
 *
 * @param string $html HTML fragment.
 * @return bool True when reconversion may shrink fallback to a form/control island.
 */
function html_to_blocks_is_form_containing_container_fragment(string $html): bool
{
    $element = HTML_To_Blocks_HTML_Element::from_html($html);
    if (!$element) {
        return \false;
    }
    if (!\in_array($element->get_tag_name(), array('SECTION', 'DIV', 'ARTICLE', 'ASIDE', 'HEADER', 'FOOTER', 'MAIN', 'NAV'), \true)) {
        return \false;
    }
    foreach (array('form', 'input', 'textarea', 'select', 'button') as $selector) {
        if ($element->query_selector($selector)) {
            return \true;
        }
    }
    return \false;
}
/**
 * Checks whether conversion still produced the original opaque core/html fragment.
 *
 * @param array  $blocks Blocks produced by reconversion.
 * @param string $html   Original HTML fragment.
 * @return bool True when fallback scope did not shrink.
 */
function html_to_blocks_is_single_html_fallback_for_fragment(array $blocks, string $html): bool
{
    if (\count($blocks) !== 1 || ($blocks[0]['blockName'] ?? null) !== 'core/html') {
        return \false;
    }
    $fallback_html = $blocks[0]['attrs']['content'] ?? $blocks[0]['innerHTML'] ?? '';
    return \is_string($fallback_html) && \trim($fallback_html) === \trim($html);
}
/**
 * Checks whether an HTML fragment is one safe, empty decorative inline span.
 *
 * @param string $html HTML fragment.
 * @return bool True when the fragment can be materialized as editable inline content.
 */
function html_to_blocks_is_decorative_inline_span_fragment(string $html): bool
{
    $element = HTML_To_Blocks_HTML_Element::from_html($html);
    if (!$element || $element->get_tag_name() !== 'SPAN') {
        return \false;
    }
    if (\trim(wp_strip_all_tags($element->get_inner_html())) !== '' || array() !== $element->get_child_elements()) {
        return \false;
    }
    $attributes = $element->get_attributes();
    $class_name = isset($attributes['class']) ? (string) $attributes['class'] : '';
    $style = isset($attributes['style']) ? (string) $attributes['style'] : '';
    $role = isset($attributes['role']) ? \strtolower(\trim((string) $attributes['role'])) : '';
    foreach ($attributes as $name => $value) {
        $name = \strtolower((string) $name);
        if (\preg_match('/^on/i', $name)) {
            return \false;
        }
        if (!\in_array($name, array('class', 'style', 'id', 'aria-hidden', 'role'), \true)) {
            return \false;
        }
    }
    if ('' !== $role && !\in_array($role, array('none', 'presentation'), \true)) {
        return \false;
    }
    if (\preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:)/i', $style)) {
        return \false;
    }
    $decorative_class_pattern = '/(?:^|[-_\s])(icon|ico|glyph|symbol|accent|bar|divider|separator|sep|rule|line|blank|orb|blob|dot|glow)(?:$|[-_\s]|\d)/i';
    if (\preg_match($decorative_class_pattern, $class_name) === 1) {
        return \true;
    }
    return '' !== $style && \preg_match('/(?:^|;)\s*display\s*:\s*inline-block\b/i', $style) === 1 && \preg_match('/(?:^|;)\s*width\s*:\s*[^;]+/i', $style) === 1 && \preg_match('/(?:^|;)\s*height\s*:\s*[^;]+/i', $style) === 1 && \preg_match('/(?:^|;)\s*(?:background|background-color)\s*:\s*[^;]+/i', $style) === 1;
}
/**
 * Checks whether an HTML fragment is only an image, optionally inside one wrapper.
 *
 * @param string $html HTML fragment.
 * @return bool True when the fragment can safely be re-run through image transforms.
 */
function html_to_blocks_is_image_only_html_fragment(string $html): bool
{
    $element = HTML_To_Blocks_HTML_Element::from_html($html);
    if (!$element) {
        return \false;
    }
    if ($element->get_tag_name() === 'IMG') {
        $src = $element->get_attribute('src');
        return \is_string($src) && '' !== $src;
    }
    if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN', 'FIGURE'), \true)) {
        return \false;
    }
    $images = $element->query_selector_all('img');
    $src = \count($images) === 1 ? $images[0]->get_attribute('src') : null;
    if (\count($images) !== 1 || !\is_string($src) || '' === $src) {
        return \false;
    }
    $remaining = \str_replace($images[0]->get_outer_html(), '', $element->get_inner_html());
    return \trim(wp_strip_all_tags($remaining)) === '';
}
/**
 * Checks whether a block tree contains a block name.
 *
 * @param array<int|string,array<string,mixed>> $blocks Blocks to inspect.
 * @param string                                $name   Block name.
 * @return bool True when the block tree contains the name.
 */
function html_to_blocks_contains_block_name(array $blocks, string $name): bool
{
    foreach ($blocks as $block) {
        if (($block['blockName'] ?? null) === $name) {
            return \true;
        }
        if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks']) && html_to_blocks_contains_block_name($block['innerBlocks'], $name)) {
            return \true;
        }
    }
    return \false;
}
/**
 * Finds all positions of a tag's opening tags in HTML
 *
 * @param string $html     Source HTML
 * @param string $tag_name Tag name to find
 * @return array Array of start positions
 */
function html_to_blocks_find_all_tag_positions($html, $tag_name)
{
    $positions = array();
    $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?>/i';
    if (\preg_match_all($pattern, $html, $matches, \PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $positions[] = $match[1];
        }
    }
    return $positions;
}
/**
 * Extracts element HTML at a specific occurrence
 *
 * @param string $html       Source HTML
 * @param string $tag_name   Tag name
 * @param array  $positions  Array of tag start positions
 * @param int    $occurrence Which occurrence (0-based)
 * @return string|null Element HTML or null
 */
function html_to_blocks_extract_element_at_occurrence($html, $tag_name, $positions, $occurrence)
{
    if (!isset($positions[$occurrence])) {
        return null;
    }
    $start_pos = $positions[$occurrence];
    $html_from_start = \substr($html, $start_pos);
    $void_elements = array('AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR');
    if (\in_array(\strtoupper($tag_name), $void_elements, \true)) {
        $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?\/?>/i';
        if (\preg_match($pattern, $html_from_start, $matches)) {
            return $matches[0];
        }
        return null;
    }
    return html_to_blocks_extract_balanced_element($html_from_start, $tag_name);
}
/**
 * Extracts a balanced element including nested elements of the same type
 *
 * @param string $html     HTML starting with the opening tag
 * @param string $tag_name Tag name to balance
 * @return string|null Balanced element HTML or null
 */
function html_to_blocks_extract_balanced_element($html, $tag_name)
{
    $depth = 0;
    $tag_pattern = '/<\/?' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?>/i';
    $matched_count = \preg_match_all($tag_pattern, $html, $matches, \PREG_OFFSET_CAPTURE);
    if (\false === $matched_count || 0 === $matched_count) {
        return null;
    }
    foreach ($matches[0] as $match) {
        $tag_markup = $match[0];
        $offset = $match[1];
        if (0 === \strpos($tag_markup, '</')) {
            --$depth;
            if (0 === $depth) {
                return \substr($html, 0, $offset + \strlen($tag_markup));
            }
            continue;
        }
        ++$depth;
    }
    return null;
}
/**
 * Finds a matching raw transform for an element
 *
 * @param HTML_To_Blocks_HTML_Element $element    The element to match
 * @param array                       $transforms Array of transforms
 * @return array|null The transform data or null
 */
function html_to_blocks_find_transform($element, $transforms)
{
    foreach ($transforms as $transform) {
        $is_match = $transform['isMatch'] ?? null;
        if ($is_match && \is_callable($is_match) && \call_user_func($is_match, $element)) {
            return $transform;
        }
    }
    return null;
}
/**
 * Converts shortcodes in HTML to blocks
 *
 * @param string $html The HTML containing shortcodes
 * @return array Array of pieces (strings or blocks)
 */
function html_to_blocks_shortcode_converter($html)
{
    $pieces = array();
    $last_index = 0;
    \preg_match_all('/' . get_shortcode_regex() . '/', $html, $matches, \PREG_OFFSET_CAPTURE);
    if (empty($matches[0])) {
        return array($html);
    }
    foreach ($matches[0] as $match) {
        $shortcode = $match[0];
        $index = $match[1];
        if ($index > $last_index) {
            $pieces[] = \substr($html, $last_index, $index - $last_index);
        }
        $parsed = html_to_blocks_parse_shortcode($shortcode);
        $pieces[] = null !== $parsed ? $parsed : $shortcode;
        $last_index = $index + \strlen($shortcode);
    }
    if ($last_index < \strlen($html)) {
        $pieces[] = \substr($html, $last_index);
    }
    return $pieces;
}
/**
 * Parses a shortcode and returns a block if possible
 *
 * @param string $shortcode The shortcode string
 * @return array|null The block array or null
 */
function html_to_blocks_parse_shortcode($shortcode)
{
    $pattern = get_shortcode_regex();
    if (!\preg_match("/{$pattern}/", $shortcode, $match)) {
        return null;
    }
    return HTML_To_Blocks_Block_Factory::create_block('core/shortcode', array('text' => $shortcode));
}
/**
 * Normalises blocks in HTML - wraps inline content in paragraphs
 *
 * @param string $html The HTML
 * @return string The normalized HTML
 */
function html_to_blocks_normalise_blocks($html)
{
    $processor = \WP_HTML_Processor::create_fragment($html);
    if (!$processor) {
        return $html;
    }
    $phrasing_tags = html_to_blocks_phrasing_tag_names();
    $body_depth = 2;
    $top_level_depth = $body_depth + 1;
    $output = '';
    $paragraph_buffer = '';
    $in_paragraph = \false;
    $last_was_br = \false;
    $tag_occurrences = array();
    $tag_positions = array();
    while ($processor->next_token()) {
        $token_type = $processor->get_token_type();
        $depth = $processor->get_current_depth();
        if ('#text' === $token_type && $depth === $top_level_depth) {
            $text = $processor->get_modifiable_text();
            if (\trim($text) === '') {
                if ($in_paragraph && '' !== $paragraph_buffer) {
                    $paragraph_buffer .= \htmlspecialchars($text, \ENT_QUOTES, 'UTF-8');
                }
                continue;
            }
            if (!$in_paragraph) {
                $in_paragraph = \true;
            }
            $paragraph_buffer .= \htmlspecialchars($text, \ENT_QUOTES, 'UTF-8');
            $last_was_br = \false;
            continue;
        }
        if ('#tag' !== $token_type) {
            continue;
        }
        if ($depth < $top_level_depth && !$processor->is_tag_closer()) {
            continue;
        }
        $tag_name = $processor->get_tag();
        $tag_upper = \strtoupper($tag_name);
        $is_closer = $processor->is_tag_closer();
        $occurrence = null;
        if (!$is_closer) {
            if (!isset($tag_occurrences[$tag_name])) {
                $tag_occurrences[$tag_name] = 0;
                $tag_positions[$tag_name] = html_to_blocks_find_all_tag_positions($html, $tag_name);
            }
            $occurrence = $tag_occurrences[$tag_name]++;
        }
        if ($depth !== $top_level_depth && $depth !== $body_depth) {
            continue;
        }
        if ('BR' === $tag_upper && !$is_closer) {
            if ($last_was_br) {
                if (!empty(\trim($paragraph_buffer))) {
                    $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
                }
                $paragraph_buffer = '';
                $in_paragraph = \false;
                $last_was_br = \false;
            } else {
                if ($in_paragraph && !empty($paragraph_buffer)) {
                    $paragraph_buffer .= '<br>';
                }
                $last_was_br = \true;
            }
            continue;
        }
        $last_was_br = \false;
        if (\in_array($tag_upper, $phrasing_tags, \true) && !$is_closer) {
            $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
            if ($element_html) {
                $element = HTML_To_Blocks_HTML_Element::from_html($element_html);
                if ($element && html_to_blocks_is_blocky_span($element)) {
                    if ($in_paragraph && !empty(\trim($paragraph_buffer))) {
                        $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
                    }
                    $paragraph_buffer = '';
                    $in_paragraph = \false;
                    $output .= html_to_blocks_promote_span_to_div_markup($element);
                    continue;
                }
                if (!$in_paragraph) {
                    $in_paragraph = \true;
                }
                $paragraph_buffer .= html_to_blocks_normalise_phrasing_fragment($element_html);
            }
            continue;
        }
        if (!$is_closer && !\in_array($tag_upper, $phrasing_tags, \true)) {
            if ($in_paragraph && !empty(\trim($paragraph_buffer))) {
                $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
            }
            $paragraph_buffer = '';
            $in_paragraph = \false;
            $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
            if ($element_html) {
                $output .= $element_html;
            }
        }
    }
    if ($in_paragraph && !empty(\trim($paragraph_buffer))) {
        $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
    }
    return !empty($output) ? $output : $html;
}
/**
 * Normalize standalone phrasing fragments before wrapping them in paragraphs.
 *
 * @param string $html HTML fragment.
 * @return string Normalized fragment.
 */
function html_to_blocks_normalise_phrasing_fragment(string $html): string
{
    if (\preg_match('/^\s*<\s*a\b[^>]*\sclass=("|\')([^"\']*)\1/i', $html, $matches) && \preg_match('/(^|[-_\s])(brand|logo)([-_\s]|$)/i', $matches[2])) {
        $html = \preg_replace('/<\s*div\b([^>]*)>/i', '<span$1>', $html) ?? $html;
        $html = \preg_replace('/<\s*\/\s*div\s*>/i', '</span>', $html) ?? $html;
    }
    return $html;
}
