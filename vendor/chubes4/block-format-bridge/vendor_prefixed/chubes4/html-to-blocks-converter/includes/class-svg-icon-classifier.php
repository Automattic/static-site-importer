<?php

namespace BlockFormatBridge\Vendor;

/**
 * Safe inline SVG icon classification and sanitization.
 *
 * @package HTML_To_Blocks_Converter
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_SVG_Icon_Classifier
{
    private const MAX_BYTES = 5120;
    private const MAX_NODES = 50;
    private const MAX_DEPTH = 5;
    private const MAX_GRAPHIC_SIZE = 512;
    private const ALLOWED_TAGS = array('svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'g', 'defs', 'pattern', 'title', 'desc');
    private const ALLOWED_ATTRIBUTES = array('aria-hidden', 'aria-label', 'aria-labelledby', 'class', 'cx', 'cy', 'd', 'fill', 'fill-opacity', 'height', 'id', 'patternunits', 'points', 'r', 'role', 'rx', 'ry', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-width', 'viewbox', 'width', 'x', 'x1', 'x2', 'y', 'y1', 'y2');
    /**
     * Classifies a source fragment as a safe inline SVG icon or a rejected SVG.
     *
     * @param string $svg Source SVG fragment.
     * @return array Classification result with is_safe, svg, metadata, and reason keys.
     */
    public static function classify(string $svg): array
    {
        $svg = \trim($svg);
        $result = array('is_safe' => \false, 'svg' => '', 'metadata' => array(), 'reason' => '');
        if ('' === $svg || \strlen($svg) > self::MAX_BYTES) {
            $result['reason'] = 'size_limit';
            return $result;
        }
        if (!\class_exists('DOMDocument', \false)) {
            $result['reason'] = 'dom_unavailable';
            return $result;
        }
        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(\true);
        $loaded = $document->loadXML($svg, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (!$loaded || !$document->documentElement || \strtolower($document->documentElement->tagName) !== 'svg') {
            $result['reason'] = 'invalid_svg';
            return $result;
        }
        $state = array('nodes' => 0, 'max_depth' => 0, 'tags' => array(), 'reason' => '', 'local_refs' => self::collect_local_reference_ids($document->documentElement));
        $sanitized = self::sanitize_element($document->documentElement, $document, 1, $state);
        if (!$sanitized) {
            $result['reason'] = $state['reason'] ? $state['reason'] : 'unsafe_svg';
            return $result;
        }
        $view_box = $sanitized->getAttribute('viewBox');
        $width = $sanitized->getAttribute('width');
        $height = $sanitized->getAttribute('height');
        if (!self::has_bounded_graphic_dimensions($view_box, $width, $height)) {
            $result['reason'] = 'dimension_limit';
            return $result;
        }
        $sanitized_svg = $document->saveXML($sanitized);
        if (!\is_string($sanitized_svg) || '' === $sanitized_svg) {
            $result['reason'] = 'serialization_failed';
            return $result;
        }
        $is_icon_sized = self::is_icon_sized_graphic($view_box, $width, $height);
        $result['is_safe'] = \true;
        $result['svg'] = $sanitized_svg;
        $result['reason'] = $is_icon_sized ? 'safe_svg_icon' : 'safe_inline_svg_illustration';
        $result['metadata'] = array('kind' => $is_icon_sized ? 'inline-svg-icon' : 'inline-svg-illustration', 'viewBox' => $view_box, 'width' => $width, 'height' => $height, 'className' => $sanitized->getAttribute('class'), 'ariaLabel' => $sanitized->getAttribute('aria-label'), 'nodeCount' => $state['nodes'], 'maxDepth' => $state['max_depth'], 'tags' => \array_values(\array_unique($state['tags'])));
        return $result;
    }
    /**
     * Recursively validates and copies an allowed SVG element into the sanitizer document.
     *
     * @param DOMElement  $source Source element.
     * @param DOMDocument $document Sanitizer document.
     * @param int         $depth Current element depth.
     * @param array       $state Mutable traversal state.
     * @return DOMElement|null Sanitized element or null when unsafe.
     */
    private static function sanitize_element(\DOMElement $source, \DOMDocument $document, int $depth, array &$state): ?\DOMElement
    {
        $tag = \strtolower($source->tagName);
        if (!\in_array($tag, self::ALLOWED_TAGS, \true) || \preg_match('/^animate/i', $tag) === 1) {
            $state['reason'] = 'disallowed_tag';
            return null;
        }
        ++$state['nodes'];
        $state['max_depth'] = \max($state['max_depth'], $depth);
        $state['tags'][] = $tag;
        if ($state['nodes'] > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            $state['reason'] = 'complexity_limit';
            return null;
        }
        $target = $document->createElement($tag);
        foreach (\iterator_to_array($source->attributes) as $attribute) {
            $name = \strtolower($attribute->name);
            $value = \trim($attribute->value);
            if (!self::is_allowed_attribute($name, $value, $state)) {
                $state['reason'] = 'disallowed_attribute';
                return null;
            }
            $target->setAttribute('viewbox' === $name ? 'viewBox' : $name, $value);
        }
        foreach (\iterator_to_array($source->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                $text = \trim($child->textContent);
                if ('' !== $text) {
                    $target->appendChild($document->createTextNode($text));
                }
                continue;
            }
            if ($child instanceof \DOMElement) {
                $child_element = self::sanitize_element($child, $document, $depth + 1, $state);
                if (!$child_element) {
                    return null;
                }
                $target->appendChild($child_element);
                continue;
            }
            if (\in_array($child->nodeType, array(\XML_COMMENT_NODE, \XML_CDATA_SECTION_NODE, \XML_PI_NODE), \true)) {
                $state['reason'] = 'disallowed_node';
                return null;
            }
        }
        return $target;
    }
    /**
     * Checks whether an SVG attribute is allowed and reference-free.
     *
     * @param string $name Attribute name.
     * @param string $value Attribute value.
     * @return bool True when safe.
     */
    private static function is_allowed_attribute(string $name, string $value, array $state): bool
    {
        if (\strpos($name, 'on') === 0 || \in_array($name, array('href', 'xlink:href', 'src', 'style'), \true)) {
            return \false;
        }
        if ('id' === $name) {
            return \preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $value) === 1;
        }
        if ('xmlns' === $name && 'http://www.w3.org/2000/svg' === $value) {
            return \true;
        }
        if (!\in_array($name, self::ALLOWED_ATTRIBUTES, \true)) {
            return \false;
        }
        if (\preg_match('/url\s*\(/i', $value) === 1) {
            if (\preg_match('/^url\(#([A-Za-z][A-Za-z0-9_-]*)\)$/', $value, $matches) !== 1) {
                return \false;
            }
            return \in_array($matches[1], $state['local_refs'] ?? array(), \true);
        }
        return \preg_match('/(?:https?:)?\/\/|data:/i', $value) !== 1;
    }
    /**
     * Collects local paint server IDs that are safe to reference via url(#id).
     *
     * @param DOMElement $root Root SVG element.
     * @return string[] Local reference IDs.
     */
    private static function collect_local_reference_ids(\DOMElement $root): array
    {
        $ids = array();
        foreach ($root->getElementsByTagName('pattern') as $pattern) {
            if ($pattern instanceof \DOMElement && $pattern->hasAttribute('id')) {
                $id = \trim($pattern->getAttribute('id'));
                if (\preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) === 1) {
                    $ids[] = $id;
                }
            }
        }
        return \array_values(\array_unique($ids));
    }
    /**
     * Applies bounded inline graphic dimension limits.
     *
     * @param string $view_box SVG viewBox attribute.
     * @param string $width SVG width attribute.
     * @param string $height SVG height attribute.
     * @return bool True when dimensions look bounded.
     */
    private static function has_bounded_graphic_dimensions(string $view_box, string $width, string $height): bool
    {
        if ('' !== $view_box) {
            $parts = \preg_split('/[\s,]+/', \trim($view_box));
            if (!\is_array($parts)) {
                return \false;
            }
            if (\count($parts) !== 4 || !\is_numeric($parts[2]) || !\is_numeric($parts[3])) {
                return \false;
            }
            if ((float) $parts[2] <= 0 || (float) $parts[3] <= 0 || (float) $parts[2] > self::MAX_GRAPHIC_SIZE || (float) $parts[3] > self::MAX_GRAPHIC_SIZE) {
                return \false;
            }
        }
        foreach (array($width, $height) as $dimension) {
            if ('' === $dimension) {
                continue;
            }
            if (\preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/', $dimension, $matches) !== 1 || (float) $matches[1] > self::MAX_GRAPHIC_SIZE) {
                return \false;
            }
        }
        return '' !== $view_box || '' !== $width || '' !== $height;
    }
    /**
     * Checks whether a bounded SVG is small enough to keep icon metadata.
     *
     * @param string $view_box SVG viewBox attribute.
     * @param string $width    SVG width attribute.
     * @param string $height   SVG height attribute.
     * @return bool True when dimensions look icon-sized.
     */
    private static function is_icon_sized_graphic(string $view_box, string $width, string $height): bool
    {
        if ('' !== $view_box) {
            $parts = \preg_split('/[\s,]+/', \trim($view_box));
            return \is_array($parts) && \count($parts) === 4 && \is_numeric($parts[2]) && \is_numeric($parts[3]) && (float) $parts[2] <= 256 && (float) $parts[3] <= 256;
        }
        foreach (array($width, $height) as $dimension) {
            if ('' === $dimension) {
                continue;
            }
            if (\preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/', $dimension, $matches) !== 1 || (float) $matches[1] > 256) {
                return \false;
            }
        }
        return '' !== $view_box || '' !== $width || '' !== $height;
    }
}
