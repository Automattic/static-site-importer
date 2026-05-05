<?php

namespace BlockFormatBridge\Vendor;

/**
 * HTML Element Adapter - Provides DOM-like interface over WP_HTML_Processor
 *
 * Wraps WordPress HTML API to provide familiar DOM traversal methods
 * for transform callbacks and HTML parsing operations.
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_HTML_Element
{
    private string $tag_name;
    private array $attributes;
    private string $outer_html;
    private string $inner_html;
    public function __construct(string $tag_name, array $attributes, string $outer_html, string $inner_html)
    {
        $this->tag_name = $tag_name;
        $this->attributes = $attributes;
        $this->outer_html = $outer_html;
        $this->inner_html = $inner_html;
    }
    /**
     * Creates an HTML_Element from raw HTML string representing a single element
     *
     * @param string $html HTML string containing a single root element
     * @return self|null Element instance or null if parsing fails
     */
    public static function from_html(string $html): ?self
    {
        $html = \trim($html);
        if (empty($html)) {
            return null;
        }
        $processor = \WP_HTML_Processor::create_fragment($html);
        if (!$processor) {
            return null;
        }
        if (!$processor->next_token()) {
            return self::from_table_scoped_html($html);
        }
        $tag_name = $processor->get_tag();
        if (!$tag_name) {
            return self::from_table_scoped_html($html);
        }
        $attributes = self::extract_attributes($processor);
        $inner_html = self::extract_inner_html($html, $tag_name);
        return new self($tag_name, $attributes, $html, $inner_html);
    }
    /**
     * Creates an HTML_Element for table-scoped tags that cannot be parsed standalone
     *
     * @param string $html Raw HTML
     * @return self|null
     */
    private static function from_table_scoped_html(string $html): ?self
    {
        if (!\preg_match('/^<\s*([a-z0-9:-]+)/i', $html, $matches)) {
            return null;
        }
        $tag = \strtolower($matches[1]);
        $wrappers = array('thead' => '<table>%s</table>', 'tbody' => '<table>%s</table>', 'tfoot' => '<table>%s</table>', 'caption' => '<table>%s</table>', 'colgroup' => '<table>%s</table>', 'col' => '<table><colgroup>%s</colgroup></table>', 'tr' => '<table><tbody>%s</tbody></table>', 'td' => '<table><tbody><tr>%s</tr></tbody></table>', 'th' => '<table><tbody><tr>%s</tr></tbody></table>');
        if (!isset($wrappers[$tag])) {
            return null;
        }
        $wrapped_html = \sprintf($wrappers[$tag], $html);
        $processor = \WP_HTML_Processor::create_fragment($wrapped_html);
        if (!$processor) {
            return null;
        }
        while ($processor->next_tag()) {
            if ($processor->is_tag_closer()) {
                continue;
            }
            if (\strtolower($processor->get_tag()) !== $tag) {
                continue;
            }
            $attributes = self::extract_attributes($processor);
            $inner_html = self::extract_inner_html($html, $processor->get_tag());
            return new self($processor->get_tag(), $attributes, $html, $inner_html);
        }
        return null;
    }
    /**
     * Extracts all attributes from the current processor position
     *
     * @param WP_HTML_Processor $processor HTML processor at an element
     * @return array Associative array of attribute name => value
     */
    private static function extract_attributes(\WP_HTML_Processor $processor): array
    {
        $attributes = array();
        $names = $processor->get_attribute_names_with_prefix('');
        if ($names) {
            foreach ($names as $name) {
                $attributes[$name] = $processor->get_attribute($name);
            }
        }
        return $attributes;
    }
    /**
     * Extracts inner HTML from an element string
     *
     * @param string $html     Full element HTML
     * @param string $tag_name Tag name to find closing tag
     * @return string Inner HTML content
     */
    private static function extract_inner_html(string $html, string $tag_name): string
    {
        $tag_lower = \strtolower($tag_name);
        $void_elements = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
        if (\in_array($tag_lower, $void_elements, \true)) {
            return '';
        }
        $pattern = '/^<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?>(.*)$/is';
        if (!\preg_match($pattern, $html, $matches)) {
            return '';
        }
        $content = $matches[1];
        $close_pattern = '/<\/' . \preg_quote($tag_name, '/') . '>\s*$/i';
        $content = \preg_replace($close_pattern, '', $content);
        return \trim($content);
    }
    /**
     * Gets the tag name (uppercase)
     *
     * @return string Tag name
     */
    public function get_tag_name(): string
    {
        return \strtoupper($this->tag_name);
    }
    /**
     * Alias for get_tag_name() to match DOMNode interface
     *
     * @return string Tag name
     */
    public function get_node_name(): string
    {
        return $this->get_tag_name();
    }
    /**
     * Gets an attribute value
     *
     * @param string $name Attribute name
     * @return string|null Attribute value or null if not present
     */
    public function get_attribute(string $name): ?string
    {
        $value = $this->attributes[\strtolower($name)] ?? null;
        if (\true === $value) {
            return '';
        }
        return $value;
    }
    /**
     * Checks if an attribute exists
     *
     * @param string $name Attribute name
     * @return bool True if attribute exists
     */
    public function has_attribute(string $name): bool
    {
        return isset($this->attributes[\strtolower($name)]);
    }
    /**
     * Gets all attributes
     *
     * @return array Associative array of attributes
     */
    public function get_attributes(): array
    {
        return $this->attributes;
    }
    /**
     * Gets the inner HTML content
     *
     * @return string Inner HTML
     */
    public function get_inner_html(): string
    {
        return $this->inner_html;
    }
    /**
     * Gets the outer HTML (full element including tags)
     *
     * @return string Outer HTML
     */
    public function get_outer_html(): string
    {
        return $this->outer_html;
    }
    /**
     * Gets the text content (strips all HTML tags)
     *
     * @return string Text content
     */
    public function get_text_content(): string
    {
        return \trim(wp_strip_all_tags($this->inner_html));
    }
    /**
     * Queries for a descendant element matching a simple selector
     *
     * @param string $selector Simple CSS selector (tag, .class, #id)
     * @return self|null Matching element or null
     */
    public function query_selector(string $selector): ?self
    {
        $results = $this->query_selector_all($selector);
        return $results[0] ?? null;
    }
    /**
     * Queries for all descendant elements matching a simple selector
     *
     * @param string $selector Simple CSS selector (tag, .class, #id)
     * @return array Array of matching elements
     */
    public function query_selector_all(string $selector): array
    {
        $processor = \WP_HTML_Processor::create_fragment($this->outer_html);
        if (!$processor) {
            return array();
        }
        $selector = \trim($selector);
        $results = array();
        $tag_match = null;
        $class_match = null;
        $id_match = null;
        $occurrence_counters = array();
        if (\preg_match('/^([a-z0-9]+)?(?:\.([a-z0-9_-]+))?(?:#([a-z0-9_-]+))?$/i', $selector, $matches)) {
            $tag_match = !empty($matches[1]) ? \strtoupper($matches[1]) : null;
            $class_match = $matches[2] ?? null;
            $id_match = $matches[3] ?? null;
        }
        $root_depth = null;
        while ($processor->next_tag()) {
            if ($processor->is_tag_closer()) {
                continue;
            }
            $tag = $processor->get_tag();
            $tag_lower = \strtolower($tag);
            $occurrence_counters[$tag_lower] = ($occurrence_counters[$tag_lower] ?? 0) + 1;
            $occurrence = $occurrence_counters[$tag_lower] - 1;
            if (null === $root_depth) {
                $root_depth = $processor->get_current_depth();
                continue;
            }
            if ($processor->get_current_depth() <= $root_depth) {
                continue;
            }
            if ($tag_match && \strtoupper($tag) !== $tag_match) {
                continue;
            }
            if ($class_match) {
                $class_attr = $processor->get_attribute('class');
                if (!\is_string($class_attr) || !\preg_match('/(?:^|\s)' . \preg_quote($class_match, '/') . '(?:$|\s)/', $class_attr)) {
                    continue;
                }
            }
            if ($id_match) {
                $id_attr = $processor->get_attribute('id');
                if ($id_attr !== $id_match) {
                    continue;
                }
            }
            $element_html = self::extract_element_html_at_occurrence($this->outer_html, $tag_lower, $occurrence);
            if ($element_html) {
                $element = self::from_html($element_html);
                if ($element) {
                    $results[] = $element;
                }
            }
        }
        return $results;
    }
    /**
     * Gets child elements (direct descendants only)
     *
     * @return array Array of child elements
     */
    public function get_child_elements(): array
    {
        $processor = \WP_HTML_Processor::create_fragment($this->outer_html);
        if (!$processor) {
            return array();
        }
        $children = array();
        $root_depth = null;
        $occurrence_counters = array();
        $tag_positions = array();
        while ($processor->next_tag()) {
            if ($processor->is_tag_closer()) {
                continue;
            }
            $tag_name = $processor->get_tag();
            $tag_lower = \strtolower($tag_name);
            $occurrence_counters[$tag_lower] = ($occurrence_counters[$tag_lower] ?? 0) + 1;
            $occurrence = $occurrence_counters[$tag_lower] - 1;
            if (null === $root_depth) {
                $root_depth = $processor->get_current_depth();
                continue;
            }
            $depth = $processor->get_current_depth();
            if ($depth !== $root_depth + 1) {
                continue;
            }
            if (!isset($tag_positions[$tag_lower])) {
                $tag_positions[$tag_lower] = self::find_tag_positions($this->outer_html, $tag_lower);
            }
            $element_html = self::extract_element_html_at_occurrence($this->outer_html, $tag_lower, $occurrence, $tag_positions[$tag_lower]);
            if ($element_html) {
                $element = self::from_html($element_html);
                if ($element) {
                    $children[] = $element;
                }
            }
        }
        return $children;
    }
    /**
     * Extracts element HTML at a specific occurrence in source HTML
     *
     * @param string $html       Source HTML
     * @param string $tag_name   Tag name (lowercase)
     * @param int    $occurrence Which occurrence (0-based)
     * @return string|null
     */
    private static function extract_element_html_at_occurrence(string $html, string $tag_name, int $occurrence, ?array $positions = null): ?string
    {
        $positions = $positions ?? self::find_tag_positions($html, $tag_name);
        if (!isset($positions[$occurrence])) {
            return null;
        }
        $start_pos = $positions[$occurrence];
        $html_from_start = \substr($html, $start_pos);
        $void_elements = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
        if (\in_array(\strtolower($tag_name), $void_elements, \true)) {
            $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?\/?>/i';
            if (\preg_match($pattern, $html_from_start, $matches)) {
                return $matches[0];
            }
            return null;
        }
        return self::extract_balanced_element($html_from_start, $tag_name);
    }
    /**
     * Finds all positions of a tag's opening tags in HTML
     *
     * @param string $html     Source HTML
     * @param string $tag_name Tag name
     * @return array
     */
    private static function find_tag_positions(string $html, string $tag_name): array
    {
        $positions = array();
        $offset = 0;
        $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s|>)/i';
        while (\preg_match($pattern, $html, $matches, \PREG_OFFSET_CAPTURE, $offset)) {
            $positions[] = $matches[0][1];
            $offset = $matches[0][1] + 1;
        }
        return $positions;
    }
    /**
     * Extracts a balanced element including nested elements of same type
     *
     * @param string $html     HTML starting with opening tag
     * @param string $tag_name Tag name
     * @return string|null
     */
    private static function extract_balanced_element(string $html, string $tag_name): ?string
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
}
