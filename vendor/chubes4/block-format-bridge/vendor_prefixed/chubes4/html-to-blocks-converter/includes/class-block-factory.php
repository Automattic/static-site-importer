<?php

namespace BlockFormatBridge\Vendor;

/**
 * Block Factory - Creates block arrays compatible with serialize_blocks()
 *
 * Creates Gutenberg block structures from parsed HTML elements.
 * Works with HTML_To_Blocks_HTML_Element adapter for DOM-like access.
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_Block_Factory
{
    /**
     * Creates a block array structure compatible with WordPress block parser format
     *
     * @param string $name         Block name (e.g., 'core/paragraph')
     * @param array  $attributes   Block attributes
     * @param array  $inner_blocks Nested block arrays
     * @return array Block array structure
     */
    public static function create_block($name, $attributes = array(), $inner_blocks = array())
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        if (!$registry->is_registered($name)) {
            return self::create_block('core/html', array('content' => ''));
        }
        $block_type = $registry->get_registered($name);
        $inner_html = '';
        $inner_content = array();
        $html_parts = self::generate_wrapper_html($name, $attributes);
        if ('' !== $html_parts['opening'] || '' !== $html_parts['closing']) {
            $inner_html = $html_parts['opening'] . $html_parts['closing'];
            if (!empty($inner_blocks)) {
                $inner_content[] = $html_parts['opening'];
                foreach ($inner_blocks as $index => $inner_block) {
                    $inner_content[] = null;
                }
                $inner_content[] = $html_parts['closing'];
            } else {
                $inner_content[] = $inner_html;
            }
        } else {
            $block_html = self::generate_block_html($name, $attributes);
            if (!empty($block_html)) {
                $inner_html = $block_html;
                $inner_content[] = $block_html;
            }
        }
        $sanitized_attributes = self::sanitize_attributes($block_type, $attributes);
        return array('blockName' => $name, 'attrs' => $sanitized_attributes, 'innerBlocks' => $inner_blocks, 'innerHTML' => $inner_html, 'innerContent' => $inner_content);
    }
    /**
     * Merges a block's base class with the stored custom className attribute.
     *
     * @param string $base       Base class list required by the core block.
     * @param array  $attributes Block attributes.
     * @return string Merged class list.
     */
    private static function merge_block_class(string $base, array $attributes): string
    {
        $classes = \preg_split('/\s+/', \trim($base . ' ' . ($attributes['className'] ?? '')));
        $classes = \is_array($classes) ? \array_filter($classes) : array();
        $style = $attributes['style'] ?? array();
        if (\is_array($style)) {
            if (!empty($style['color']['text'])) {
                $classes[] = 'has-text-color';
            }
            if (!empty($style['color']['background']) || !empty($style['color']['gradient'])) {
                $classes[] = 'has-background';
            }
        }
        return \implode(' ', \array_values(\array_unique($classes)));
    }
    /**
     * Resolves an allowed HTML tagName attribute with a safe default.
     *
     * @param string $default_value Default tag name.
     * @param array  $attributes Block attributes.
     * @param array  $allowed    Allowed lowercase tag names.
     * @return string Safe tag name.
     */
    private static function tag_name(string $default_value, array $attributes, array $allowed): string
    {
        $tag_name = \strtolower((string) ($attributes['tagName'] ?? $default_value));
        return \in_array($tag_name, $allowed, \true) ? $tag_name : $default_value;
    }
    /**
     * Converts an associative attribute map to escaped HTML attributes.
     *
     * @param array $attrs HTML attribute map.
     * @return string Leading-space-prefixed HTML attributes.
     */
    private static function html_attrs(array $attrs, array $include_empty = array()): string
    {
        $html = '';
        foreach ($attrs as $name => $value) {
            if (null === $value || '' === $value && !\in_array($name, $include_empty, \true) || \false === $value) {
                continue;
            }
            if (\true === $value) {
                $html .= ' ' . $name;
                continue;
            }
            $html .= ' ' . $name . '="' . esc_attr($value) . '"';
        }
        return $html;
    }
    /**
     * Serializes core block support style attributes into inline CSS.
     *
     * @param array $attributes Block attributes.
     * @return string Inline CSS declaration list.
     */
    private static function style_attr(array $attributes): string
    {
        $style = $attributes['style'] ?? array();
        $declarations = array();
        if (!\is_array($style)) {
            return '';
        }
        $border = $style['border'] ?? array();
        if (\is_array($border)) {
            foreach (array('color', 'style', 'width', 'radius') as $part) {
                $value = $border[$part] ?? null;
                if (\is_string($value) && '' !== $value) {
                    $declarations[] = 'border-' . $part . ':' . $value;
                }
            }
        }
        $text_color = $style['color']['text'] ?? null;
        if (\is_string($text_color) && '' !== $text_color) {
            $declarations[] = 'color:' . $text_color;
        }
        $background_color = $style['color']['background'] ?? null;
        if (\is_string($background_color) && '' !== $background_color) {
            $declarations[] = 'background-color:' . $background_color;
        }
        $background_gradient = $style['color']['gradient'] ?? null;
        if (\is_string($background_gradient) && '' !== $background_gradient) {
            $declarations[] = 'background:' . $background_gradient;
        }
        $spacing = $style['spacing'] ?? array();
        if (\is_array($spacing)) {
            foreach (array('margin', 'padding') as $property) {
                $value = $spacing[$property] ?? null;
                if (\is_string($value) && '' !== $value) {
                    $declarations[] = $property . ':' . $value;
                    continue;
                }
                if (!\is_array($value)) {
                    continue;
                }
                foreach (array('top', 'right', 'bottom', 'left') as $side) {
                    $side_value = $value[$side] ?? null;
                    if (\is_string($side_value) && '' !== $side_value) {
                        $declarations[] = $property . '-' . $side . ':' . $side_value;
                    }
                }
            }
        }
        $min_height = $style['dimensions']['minHeight'] ?? null;
        if (\is_string($min_height) && '' !== $min_height) {
            $declarations[] = 'min-height:' . $min_height;
        }
        $width = $style['dimensions']['width'] ?? null;
        if (\is_string($width) && '' !== $width) {
            $declarations[] = 'width:' . $width;
        }
        return \implode(';', $declarations);
    }
    /**
     * Generates the complete HTML for a block without inner blocks
     *
     * @param string $name       Block name
     * @param array  $attributes Block attributes
     * @return string Block HTML
     */
    private static function generate_block_html($name, $attributes)
    {
        switch ($name) {
            case 'core/paragraph':
                $content = $attributes['content'] ?? '';
                $html_attrs = array('class' => self::merge_block_class('', $attributes));
                $style = self::style_attr($attributes);
                if (!empty($attributes['align'])) {
                    $style = \trim($style . ';text-align:' . $attributes['align'], ';');
                }
                if ('' !== $style) {
                    $html_attrs['style'] = $style;
                }
                return '<p' . self::html_attrs($html_attrs) . '>' . $content . '</p>';
            case 'core/heading':
                $level = $attributes['level'] ?? 2;
                $content = $attributes['content'] ?? '';
                return '<h' . (int) $level . self::html_attrs(array('class' => self::merge_block_class('wp-block-heading', $attributes))) . '>' . $content . '</h' . (int) $level . '>';
            case 'core/list-item':
                $content = $attributes['content'] ?? '';
                return '<li>' . $content . '</li>';
            case 'core/button':
                return self::generate_button_html($attributes);
            case 'core/pullquote':
                return self::generate_pullquote_html($attributes);
            case 'core/verse':
                $content = $attributes['content'] ?? '';
                return '<pre' . self::html_attrs(array('class' => self::merge_block_class('wp-block-verse', $attributes))) . '>' . $content . '</pre>';
            case 'core/image':
                return self::generate_image_html($attributes);
            case 'core/code':
                $content = esc_html($attributes['content'] ?? '');
                return '<pre' . self::html_attrs(array('class' => self::merge_block_class('wp-block-code', $attributes))) . '><code>' . $content . '</code></pre>';
            case 'core/preformatted':
                $content = $attributes['content'] ?? '';
                return '<pre' . self::html_attrs(array('class' => self::merge_block_class('wp-block-preformatted', $attributes))) . '>' . $content . '</pre>';
            case 'core/separator':
                return '<hr' . self::html_attrs(array('class' => self::merge_block_class('wp-block-separator has-css-opacity', $attributes))) . '/>';
            case 'core/table':
                return self::generate_table_html($attributes);
            case 'core/video':
                return self::generate_video_html($attributes);
            case 'core/audio':
                return self::generate_audio_html($attributes);
            case 'core/file':
                return self::generate_file_html($attributes);
            case 'core/embed':
                return self::generate_embed_html($attributes);
            case 'core/shortcode':
                return $attributes['text'] ?? '';
            case 'core/html':
                return $attributes['content'] ?? '';
            default:
                return '';
        }
    }
    /**
     * Generates HTML for button block.
     *
     * @param array $attributes Block attributes.
     * @return string Button HTML.
     */
    private static function generate_button_html($attributes)
    {
        $text = $attributes['text'] ?? '';
        $url = $attributes['url'] ?? '';
        $rel = !empty($attributes['rel']) ? ' rel="' . esc_attr($attributes['rel']) . '"' : '';
        $target = !empty($attributes['linkTarget']) ? ' target="' . esc_attr($attributes['linkTarget']) . '"' : '';
        $class_name = self::merge_block_class('wp-block-button', $attributes);
        return '<div class="' . esc_attr($class_name) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url($url) . '"' . $target . $rel . '>' . $text . '</a></div>';
    }
    /**
     * Generates HTML for pullquote block.
     *
     * @param array $attributes Block attributes.
     * @return string Pullquote HTML.
     */
    private static function generate_pullquote_html($attributes)
    {
        $value = $attributes['value'] ?? '';
        $citation = !empty($attributes['citation']) ? '<cite>' . $attributes['citation'] . '</cite>' : '';
        return '<figure' . self::html_attrs(array('class' => self::merge_block_class('wp-block-pullquote', $attributes))) . '><blockquote>' . $value . $citation . '</blockquote></figure>';
    }
    /**
     * Generates HTML for image block
     *
     * @param array $attributes Block attributes
     * @return string Image HTML
     */
    private static function generate_image_html($attributes)
    {
        $url = $attributes['url'] ?? '';
        if (empty($url)) {
            return '';
        }
        $img_attrs = array('src' => esc_url($url), 'alt' => $attributes['alt'] ?? '', 'title' => $attributes['title'] ?? null, 'srcset' => $attributes['srcset'] ?? null, 'sizes' => $attributes['sizes'] ?? null, 'width' => $attributes['width'] ?? null, 'height' => $attributes['height'] ?? null);
        $is_svg_image = \preg_match('/\.svg(?:$|[?#])/i', (string) $url) === 1;
        $is_resized = $is_svg_image && !empty($attributes['width']) && !empty($attributes['height']);
        if ($is_resized) {
            $width = self::image_style_dimension($attributes['width']);
            $height = self::image_style_dimension($attributes['height']);
            $img_attrs['style'] = 'width:' . $width . ';height:' . $height;
            $img_attrs['width'] = null;
            $img_attrs['height'] = null;
        }
        $img = '<img' . self::html_attrs($img_attrs, array('alt')) . '/>';
        if (!empty($attributes['href'])) {
            $rel = !empty($attributes['rel']) ? ' rel="' . esc_attr($attributes['rel']) . '"' : '';
            $img = '<a href="' . esc_url($attributes['href']) . '"' . $rel . '>' . $img . '</a>';
        }
        $figcaption = '';
        if (!empty($attributes['caption'])) {
            $figcaption = '<figcaption class="wp-element-caption">' . $attributes['caption'] . '</figcaption>';
        }
        $class = self::merge_block_class($is_resized ? 'wp-block-image is-resized' : 'wp-block-image', $attributes);
        if (!empty($attributes['align'])) {
            $class .= ' align' . $attributes['align'];
        }
        return '<figure class="' . esc_attr($class) . '">' . $img . $figcaption . '</figure>';
    }
    /**
     * Normalizes image dimension attributes for CSS style declarations.
     *
     * @param mixed $value Dimension attribute value.
     * @return string CSS dimension value.
     */
    private static function image_style_dimension($value): string
    {
        $value = \trim((string) $value);
        if (\preg_match('/^-?(?:\d+|\d*\.\d+)$/', $value)) {
            return $value . 'px';
        }
        return $value;
    }
    /**
     * Generates HTML for table block
     *
     * @param array $attributes Block attributes
     * @return string Table HTML
     */
    private static function generate_table_html($attributes)
    {
        $html = '<figure' . self::html_attrs(array('class' => self::merge_block_class('wp-block-table', $attributes))) . '><table>';
        if (!empty($attributes['head'])) {
            $html .= '<thead>';
            foreach ($attributes['head'] as $row) {
                $html .= '<tr>';
                foreach ($row['cells'] ?? array() as $cell) {
                    $tag = $cell['tag'] ?? 'th';
                    $html .= "<{$tag}>" . ($cell['content'] ?? '') . "</{$tag}>";
                }
                $html .= '</tr>';
            }
            $html .= '</thead>';
        }
        if (!empty($attributes['body'])) {
            $html .= '<tbody>';
            foreach ($attributes['body'] as $row) {
                $html .= '<tr>';
                foreach ($row['cells'] ?? array() as $cell) {
                    $tag = $cell['tag'] ?? 'td';
                    $html .= "<{$tag}>" . ($cell['content'] ?? '') . "</{$tag}>";
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }
        if (!empty($attributes['foot'])) {
            $html .= '<tfoot>';
            foreach ($attributes['foot'] as $row) {
                $html .= '<tr>';
                foreach ($row['cells'] ?? array() as $cell) {
                    $tag = $cell['tag'] ?? 'td';
                    $html .= "<{$tag}>" . ($cell['content'] ?? '') . "</{$tag}>";
                }
                $html .= '</tr>';
            }
            $html .= '</tfoot>';
        }
        $html .= '</table>';
        if (!empty($attributes['caption'])) {
            $html .= '<figcaption class="wp-element-caption">' . $attributes['caption'] . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
    /**
     * Generates HTML for a video block.
     *
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    private static function generate_video_html($attributes)
    {
        $src = $attributes['src'] ?? '';
        if ('' === $src) {
            return '';
        }
        $attrs = ' controls';
        foreach (array('autoplay', 'loop', 'muted', 'playsInline') as $flag) {
            if (!empty($attributes[$flag])) {
                $attrs .= ' ' . \strtolower('playsInline' === $flag ? 'playsinline' : $flag);
            }
        }
        foreach (array('poster', 'preload') as $key) {
            if (!empty($attributes[$key])) {
                $attrs .= ' ' . $key . '="' . esc_attr($attributes[$key]) . '"';
            }
        }
        $html = '<figure' . self::html_attrs(array('class' => self::merge_block_class('wp-block-video', $attributes))) . '><video src="' . esc_url($src) . '"' . $attrs . '></video>';
        if (!empty($attributes['caption'])) {
            $html .= '<figcaption class="wp-element-caption">' . $attributes['caption'] . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
    /**
     * Generates HTML for an audio block.
     *
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    private static function generate_audio_html($attributes)
    {
        $src = $attributes['src'] ?? '';
        if ('' === $src) {
            return '';
        }
        $attrs = ' controls';
        foreach (array('autoplay', 'loop') as $flag) {
            if (!empty($attributes[$flag])) {
                $attrs .= ' ' . $flag;
            }
        }
        if (!empty($attributes['preload'])) {
            $attrs .= ' preload="' . esc_attr($attributes['preload']) . '"';
        }
        $html = '<figure' . self::html_attrs(array('class' => self::merge_block_class('wp-block-audio', $attributes))) . '><audio src="' . esc_url($src) . '"' . $attrs . '></audio>';
        if (!empty($attributes['caption'])) {
            $html .= '<figcaption class="wp-element-caption">' . $attributes['caption'] . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
    /**
     * Generates HTML for a file block.
     *
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    private static function generate_file_html($attributes)
    {
        $href = $attributes['href'] ?? $attributes['textLinkHref'] ?? '';
        if ('' === $href) {
            return '';
        }
        $name = $attributes['fileName'] ?? \basename(\strtok($href, '?#'));
        $target = !empty($attributes['textLinkTarget']) ? ' target="' . esc_attr($attributes['textLinkTarget']) . '"' : '';
        $html = '<div' . self::html_attrs(array('class' => self::merge_block_class('wp-block-file', $attributes))) . '><a href="' . esc_url($href) . '"' . $target . '>' . $name . '</a>';
        if (!isset($attributes['showDownloadButton']) || $attributes['showDownloadButton']) {
            $html .= '<a href="' . esc_url($href) . '" class="wp-block-file__button wp-element-button" download>Download</a>';
        }
        $html .= '</div>';
        return $html;
    }
    /**
     * Generates HTML for an embed block.
     *
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    private static function generate_embed_html($attributes)
    {
        $url = $attributes['url'] ?? '';
        if ('' === $url) {
            return '';
        }
        $provider = $attributes['providerNameSlug'] ?? '';
        $class = self::merge_block_class('wp-block-embed', $attributes);
        if ('' !== $provider) {
            $class .= ' is-provider-' . sanitize_html_class($provider) . ' wp-block-embed-' . sanitize_html_class($provider);
        }
        return '<figure class="' . esc_attr($class) . '"><div class="wp-block-embed__wrapper">' . esc_url($url) . '</div></figure>';
    }
    /**
     * Generates wrapper HTML for blocks with inner blocks
     *
     * @param string $name       Block name
     * @param array  $attributes Block attributes
     * @return array Opening and closing HTML tags
     */
    private static function generate_wrapper_html($name, $attributes)
    {
        switch ($name) {
            case 'core/list':
                $tag = !empty($attributes['ordered']) ? 'ol' : 'ul';
                return array('opening' => '<' . $tag . self::html_attrs(array('class' => self::merge_block_class('wp-block-list', $attributes))) . '>', 'closing' => "</{$tag}>");
            case 'core/list-item':
                $content = $attributes['content'] ?? '';
                return array('opening' => '<li>' . $content, 'closing' => '</li>');
            case 'core/quote':
                return array('opening' => '<blockquote' . self::html_attrs(array('class' => self::merge_block_class('wp-block-quote', $attributes))) . '>', 'closing' => '</blockquote>');
            case 'core/buttons':
                return array('opening' => '<div' . self::html_attrs(array('class' => self::merge_block_class('wp-block-buttons', $attributes))) . '>', 'closing' => '</div>');
            case 'core/details':
                $summary = $attributes['summary'] ?? '';
                return array('opening' => '<details' . self::html_attrs(array('class' => self::merge_block_class('wp-block-details', $attributes))) . '><summary>' . $summary . '</summary>', 'closing' => '</details>');
            case 'core/group':
                $tag = self::tag_name('div', $attributes, array('div', 'section', 'main', 'article', 'aside', 'header', 'footer', 'nav'));
                return array('opening' => '<' . $tag . self::html_attrs(array('id' => $attributes['anchor'] ?? null, 'class' => self::merge_block_class('wp-block-group', $attributes), 'style' => self::style_attr($attributes), 'aria-label' => $attributes['ariaLabel'] ?? null)) . '>', 'closing' => '</' . $tag . '>');
            case 'core/column':
                return array('opening' => '<div' . self::html_attrs(array('class' => self::merge_block_class('wp-block-column', $attributes))) . '>', 'closing' => '</div>');
            case 'core/columns':
                return array('opening' => '<div' . self::html_attrs(array('class' => self::merge_block_class('wp-block-columns', $attributes))) . '>', 'closing' => '</div>');
            case 'core/gallery':
                $class = self::merge_block_class('wp-block-gallery has-nested-images columns-default is-cropped', $attributes);
                if (!empty($attributes['columns'])) {
                    $class = self::merge_block_class('wp-block-gallery has-nested-images columns-' . (int) $attributes['columns'] . ' is-cropped', $attributes);
                }
                return array('opening' => '<figure class="' . esc_attr($class) . '">', 'closing' => '</figure>');
            case 'core/media-text':
                $media_url = $attributes['mediaUrl'] ?? '';
                $media_type = $attributes['mediaType'] ?? 'image';
                $media_alt = esc_attr($attributes['mediaAlt'] ?? '');
                $media_html = 'video' === $media_type ? '<video src="' . esc_url($media_url) . '" controls></video>' : '<img src="' . esc_url($media_url) . '" alt="' . $media_alt . '"/>';
                $class = self::merge_block_class('wp-block-media-text is-stacked-on-mobile', $attributes);
                if (($attributes['mediaPosition'] ?? 'left') === 'right') {
                    $class .= ' has-media-on-the-right';
                }
                return array('opening' => '<div class="' . esc_attr($class) . '"><figure class="wp-block-media-text__media">' . $media_html . '</figure><div class="wp-block-media-text__content">', 'closing' => '</div></div>');
            default:
                return array('opening' => '', 'closing' => '');
        }
    }
    /**
     * Sanitizes block attributes against the block type schema
     * Excludes attributes with source types (rich-text, html, text) as those are derived from HTML
     *
     * @param WP_Block_Type $block_type Block type object
     * @param array         $attributes Raw attributes
     * @return array Sanitized attributes for JSON serialization
     */
    private static function sanitize_attributes($block_type, $attributes)
    {
        if (empty($block_type->attributes)) {
            return $attributes;
        }
        $sanitized = array();
        foreach ($attributes as $key => $value) {
            if (!isset($block_type->attributes[$key])) {
                if ('anchor' === $key) {
                    $sanitized[$key] = $value;
                }
                continue;
            }
            $schema = $block_type->attributes[$key];
            if (isset($schema['source'])) {
                continue;
            }
            $type = $schema['type'] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            if ('rich-text' === $type) {
                continue;
            }
            switch ($type) {
                case 'string':
                    $sanitized[$key] = (string) $value;
                    break;
                case 'number':
                case 'integer':
                    $sanitized[$key] = \is_numeric($value) ? (int) $value : null;
                    break;
                case 'boolean':
                    $sanitized[$key] = (bool) $value;
                    break;
                case 'array':
                    $sanitized[$key] = \is_array($value) ? $value : array($value);
                    break;
                case 'object':
                    $sanitized[$key] = \is_array($value) ? $value : array();
                    break;
                default:
                    $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
    /**
     * Gets the inner HTML content from an element
     *
     * @param mixed $element Element or HTML string
     * @return string Inner HTML
     */
    public static function get_inner_html($element)
    {
        if ($element instanceof HTML_To_Blocks_HTML_Element) {
            return $element->get_inner_html();
        }
        if (\is_string($element)) {
            $parsed = HTML_To_Blocks_HTML_Element::from_html($element);
            return $parsed ? $parsed->get_inner_html() : '';
        }
        return '';
    }
    /**
     * Gets text content from an element
     *
     * @param mixed $element Element or HTML string
     * @return string Text content
     */
    public static function get_text_content($element)
    {
        if ($element instanceof HTML_To_Blocks_HTML_Element) {
            return $element->get_text_content();
        }
        if (\is_string($element)) {
            $parsed = HTML_To_Blocks_HTML_Element::from_html($element);
            return $parsed ? $parsed->get_text_content() : '';
        }
        return '';
    }
}
