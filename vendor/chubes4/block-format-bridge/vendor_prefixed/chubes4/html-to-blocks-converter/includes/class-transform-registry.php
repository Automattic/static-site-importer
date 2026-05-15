<?php

namespace BlockFormatBridge\Vendor;

/**
 * Transform Registry - PHP raw transforms mirroring Gutenberg JS transforms
 *
 * Uses HTML_To_Blocks_HTML_Element adapter for DOM-like access via WordPress HTML API.
 * Only type raw transforms for server-side HTML-to-blocks conversion.
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_Transform_Registry
{
    private static ?array $transforms = null;
    /**
     * Repeated-card children validated during transform matching.
     *
     * @var array<int,array<int,HTML_To_Blocks_HTML_Element>>
     */
    private static array $repeated_card_grid_children = array();
    /**
     * Gets all raw transforms for core blocks
     * Sorted by priority (lower = higher priority)
     *
     * @return array Array of transform definitions
     */
    public static function get_raw_transforms()
    {
        if (null !== self::$transforms) {
            return self::$transforms;
        }
        self::$transforms = \array_merge(self::get_site_editor_marker_transforms(), self::get_svg_icon_transforms(), self::get_heading_transforms(), self::get_list_transforms(), self::get_button_transforms(), self::get_media_transforms(), self::get_image_transforms(), self::get_details_transforms(), self::get_pullquote_transforms(), self::get_quote_transforms(), self::get_code_transforms(), self::get_code_window_transforms(), self::get_verse_transforms(), self::get_preformatted_transforms(), self::get_separator_transforms(), self::get_table_transforms(), self::get_form_transforms(), self::get_layout_transforms(), self::get_paragraph_transforms());
        \usort(self::$transforms, function ($a, $b) {
            return ($a['priority'] ?? 10) - ($b['priority'] ?? 10);
        });
        return self::$transforms;
    }
    /**
     * Safe inline SVG icon transform.
     *
     * h2bc exposes these as explicit placeholders rather than final core/html so
     * stricter downstream pipelines can replace them with native assets.
     *
     * @return array Transform definitions
     */
    private static function get_svg_icon_transforms()
    {
        return array(array('blockName' => 'html-to-blocks/svg-icon', 'priority' => 2, 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'SVG' || !\class_exists('BlockFormatBridge\Vendor\HTML_To_Blocks_SVG_Icon_Classifier', \false)) {
                return \false;
            }
            $classification = HTML_To_Blocks_SVG_Icon_Classifier::classify($element->get_outer_html());
            return !empty($classification['is_safe']);
        }, 'transform' => function ($element) {
            $classification = HTML_To_Blocks_SVG_Icon_Classifier::classify($element->get_outer_html());
            $svg = $classification['svg'] ?? '';
            $metadata = $classification['metadata'] ?? array();
            if (\function_exists('do_action')) {
                \do_action('html_to_blocks_safe_inline_svg_icon', $svg, $metadata, $classification);
            }
            return array('blockName' => 'html-to-blocks/svg-icon', 'attrs' => array('svg' => $svg, 'metadata' => $metadata), 'innerBlocks' => array(), 'innerHTML' => $svg, 'innerContent' => array($svg));
        }));
    }
    /**
     * Explicit Site Editor primitive marker transforms.
     *
     * @return array Transform definitions
     */
    private static function get_site_editor_marker_transforms()
    {
        return array(array('blockName' => 'core/pattern', 'priority' => 1, 'isMatch' => function ($element) {
            return self::get_pattern_marker_slug($element) !== '';
        }, 'transform' => function ($element) {
            return HTML_To_Blocks_Block_Factory::create_block('core/pattern', array('slug' => self::get_pattern_marker_slug($element)));
        }), array('blockName' => 'core/template-part', 'priority' => 1, 'isMatch' => function ($element) {
            return self::get_template_part_marker_slug($element) !== '';
        }, 'transform' => function ($element) {
            $slug = self::get_template_part_marker_slug($element);
            $attributes = array('slug' => $slug);
            if (\in_array($slug, array('header', 'footer', 'sidebar'), \true)) {
                $attributes['area'] = $slug;
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/template-part', $attributes);
        }));
    }
    /**
     * Gets a valid explicit pattern marker slug.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return string Pattern slug or empty string.
     */
    private static function get_pattern_marker_slug($element): string
    {
        if (!$element->has_attribute('data-bfb-pattern')) {
            return '';
        }
        $slug = \trim((string) $element->get_attribute('data-bfb-pattern'));
        return \preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.\/-]+$/i', $slug) === 1 ? $slug : '';
    }
    /**
     * Gets a valid explicit template-part marker slug.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return string Template-part slug or empty string.
     */
    private static function get_template_part_marker_slug($element): string
    {
        if (!$element->has_attribute('data-bfb-template-part')) {
            return '';
        }
        $slug = \trim((string) $element->get_attribute('data-bfb-template-part'));
        return \preg_match('/^[a-z0-9_.-]+$/i', $slug) === 1 ? $slug : '';
    }
    /**
     * Media and embed transforms for high-confidence static HTML patterns.
     *
     * @return array Transform definitions
     */
    private static function get_media_transforms()
    {
        return array(array('blockName' => 'core/gallery', 'priority' => 8, 'isMatch' => function ($element) {
            return self::is_gallery_element($element);
        }, 'transform' => function ($element) {
            return self::create_gallery_block($element);
        }), array('blockName' => 'core/media-text', 'priority' => 8, 'isMatch' => function ($element) {
            $class = $element->has_attribute('class') ? $element->get_attribute('class') : '';
            return \preg_match('/(?:^|\s)(?:wp-block-media-text|media-text)(?:$|\s)/i', $class) === 1 && ($element->query_selector('img') || $element->query_selector('video'));
        }, 'transform' => function ($element, $handler) {
            return self::create_media_text_block($element, $handler);
        }), array('blockName' => 'core/video', 'priority' => 9, 'isMatch' => function ($element) {
            $video = $element->get_tag_name() === 'VIDEO' ? $element : $element->query_selector('video');
            return $video && self::get_media_src($video) !== '';
        }, 'transform' => function ($element) {
            $video = $element->get_tag_name() === 'VIDEO' ? $element : $element->query_selector('video');
            $attributes = self::get_media_attributes($video, array('src', 'poster', 'preload', 'autoplay', 'controls', 'loop', 'muted', 'playsInline'));
            if ($element->get_tag_name() === 'FIGURE') {
                $caption = $element->query_selector('figcaption');
                if ($caption) {
                    $attributes['caption'] = $caption->get_inner_html();
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/video', $attributes);
        }), array('blockName' => 'core/audio', 'priority' => 9, 'isMatch' => function ($element) {
            $audio = $element->get_tag_name() === 'AUDIO' ? $element : $element->query_selector('audio');
            return $audio && self::get_media_src($audio) !== '';
        }, 'transform' => function ($element) {
            $audio = $element->get_tag_name() === 'AUDIO' ? $element : $element->query_selector('audio');
            $attributes = self::get_media_attributes($audio, array('src', 'preload', 'autoplay', 'loop'));
            if ($element->get_tag_name() === 'FIGURE') {
                $caption = $element->query_selector('figcaption');
                if ($caption) {
                    $attributes['caption'] = $caption->get_inner_html();
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/audio', $attributes);
        }), array('blockName' => 'core/file', 'priority' => 9, 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'A' && $element->has_attribute('href') && self::is_file_link($element);
        }, 'transform' => function ($element) {
            return self::create_file_block_from_anchor($element);
        }), array('blockName' => 'core/file', 'priority' => 9, 'isMatch' => function ($element) {
            $anchor = $element->get_tag_name() === 'P' ? $element->query_selector('a') : null;
            return $anchor && self::is_file_link($anchor) && \trim($element->get_inner_html()) === \trim($anchor->get_outer_html());
        }, 'transform' => function ($element) {
            return self::create_file_block_from_anchor($element->query_selector('a'));
        }), array('blockName' => 'core/embed', 'priority' => 9, 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'IFRAME' && $element->has_attribute('src') && self::get_embed_provider_slug($element->get_attribute('src')) !== '';
        }, 'transform' => function ($element) {
            $src = $element->get_attribute('src');
            $attributes = array('url' => self::normalise_embed_url($src), 'type' => 'rich', 'providerNameSlug' => self::get_embed_provider_slug($src), 'responsive' => \true);
            return HTML_To_Blocks_Block_Factory::create_block('core/embed', $attributes);
        }));
    }
    /**
     * Checks whether an element is a high-confidence gallery wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool
     */
    private static function is_gallery_element($element): bool
    {
        $class = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        if (\preg_match('/(?:^|\s)(?:wp-block-gallery|blocks-gallery-grid|gallery|image-grid)(?:$|\s)/i', $class) !== 1) {
            return \false;
        }
        return \count($element->query_selector_all('img')) > 1;
    }
    /**
     * Creates a gallery block containing image inner blocks.
     *
     * @param HTML_To_Blocks_HTML_Element $element Gallery wrapper.
     * @return array Block array.
     */
    private static function create_gallery_block($element): array
    {
        $images = $element->query_selector_all('img');
        $captions = $element->query_selector_all('figcaption');
        $inner_blocks = array();
        $ids = array();
        foreach ($images as $index => $img) {
            $caption = isset($captions[$index]) ? $captions[$index]->get_inner_html() : '';
            $image_block = self::create_image_block_from_img($img, $caption);
            $inner_blocks[] = $image_block;
            if (isset($image_block['attrs']['id'])) {
                $ids[] = $image_block['attrs']['id'];
            }
        }
        $attributes = array();
        if (!empty($ids)) {
            $attributes['ids'] = $ids;
        }
        if (\preg_match('/(?:^|\s)columns-(\d+)(?:$|\s)/', $element->get_attribute('class') ?? '', $matches)) {
            $attributes['columns'] = \min(8, \max(1, (int) $matches[1]));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/gallery', $attributes, $inner_blocks);
    }
    /**
     * Creates an image block from an img element.
     *
     * @param HTML_To_Blocks_HTML_Element $img     Image element.
     * @param string                      $caption Optional caption HTML.
     * @return array Block array.
     */
    private static function create_image_block_from_img($img, string $caption = ''): array
    {
        $attributes = array('url' => $img->get_attribute('src') ?? '');
        self::apply_image_element_attributes($attributes, $img);
        if ('' !== $caption) {
            $attributes['caption'] = $caption;
        }
        if ($img->has_attribute('class') && \preg_match('/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $img->get_attribute('class'), $matches)) {
            $attributes['id'] = (int) $matches[1];
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/image', $attributes);
    }
    /**
     * Preserves direct img attributes that are safe and useful in static snapshots.
     *
     * @param array                       $attributes Block attributes.
     * @param HTML_To_Blocks_HTML_Element $img        Source img element.
     */
    private static function apply_image_element_attributes(array &$attributes, $img): void
    {
        foreach (array('alt', 'title', 'srcset', 'sizes', 'width', 'height') as $attribute) {
            if ($img->has_attribute($attribute)) {
                $attributes[$attribute] = $img->get_attribute($attribute);
            }
        }
    }
    /**
     * Creates a media-text block from a recognized two-column wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element Media-text wrapper.
     * @param callable                    $handler Recursive raw handler.
     * @return array Block array.
     */
    private static function create_media_text_block($element, $handler): array
    {
        $media = $element->query_selector('img') ? $element->query_selector('img') : $element->query_selector('video');
        $content = $element->query_selector('.wp-block-media-text__content');
        $media_type = $media && $media->get_tag_name() === 'VIDEO' ? 'video' : 'image';
        $attributes = array('mediaUrl' => self::get_media_src($media), 'mediaType' => $media_type, 'mediaPosition' => 'left', 'mediaWidth' => 50, 'isStackedOnMobile' => \true);
        if ($media && $media->has_attribute('alt')) {
            $attributes['mediaAlt'] = $media->get_attribute('alt');
        }
        if ($media && $media->has_attribute('class') && \preg_match('/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $media->get_attribute('class'), $matches)) {
            $attributes['mediaId'] = (int) $matches[1];
        }
        if (\preg_match('/(?:^|\s)has-media-on-the-right(?:$|\s)/', $element->get_attribute('class') ?? '')) {
            $attributes['mediaPosition'] = 'right';
        }
        if (!$content) {
            $children = $element->get_child_elements();
            foreach ($children as $child) {
                if (!$child->query_selector('img') && !$child->query_selector('video')) {
                    $content = $child;
                    break;
                }
            }
        }
        $inner_blocks = $content ? $handler(array('HTML' => $content->get_inner_html())) : array();
        return HTML_To_Blocks_Block_Factory::create_block('core/media-text', $attributes, $inner_blocks);
    }
    /**
     * Extracts the best media source from src or nested source tags.
     *
     * @param HTML_To_Blocks_HTML_Element|null $element Media element.
     * @return string
     */
    private static function get_media_src($element): string
    {
        if (!$element) {
            return '';
        }
        if ($element->has_attribute('src') && $element->get_attribute('src') !== '') {
            return $element->get_attribute('src');
        }
        $source = $element->query_selector('source');
        if ($source && $source->has_attribute('src')) {
            return $source->get_attribute('src');
        }
        return '';
    }
    /**
     * Extracts media attributes from a video/audio element.
     *
     * @param HTML_To_Blocks_HTML_Element $element Media element.
     * @param array                       $keys    Allowed attributes.
     * @return array
     */
    private static function get_media_attributes($element, array $keys): array
    {
        $attributes = array('src' => self::get_media_src($element));
        foreach ($keys as $key) {
            $html_key = 'playsInline' === $key ? 'playsinline' : $key;
            if ('src' === $key || !$element->has_attribute($html_key)) {
                continue;
            }
            $value = $element->get_attribute($html_key);
            if (\in_array($key, array('autoplay', 'controls', 'loop', 'muted', 'playsInline'), \true)) {
                $attributes[$key] = \true;
            } else {
                $attributes[$key] = $value;
            }
        }
        return $attributes;
    }
    /**
     * Checks whether a link points to a document/archive download.
     *
     * @param HTML_To_Blocks_HTML_Element $element Link element.
     * @return bool
     */
    private static function is_file_link($element): bool
    {
        $href = (string) $element->get_attribute('href');
        $href_path = \strtok($href, '?#');
        $href_path = \false === $href_path ? '' : \strtolower($href_path);
        if (\preg_match('/\.(?:pdf|docx?|pptx?|xlsx?|zip|rar|7z|txt|csv|ics|epub)$/', $href_path)) {
            return \true;
        }
        $class = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        return $element->has_attribute('download') && \preg_match('/(?:^|\s)(?:download|file)(?:$|\s)/i', $class) === 1;
    }
    /**
     * Creates a file block from a downloadable anchor.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor Link element.
     * @return array Block array.
     */
    private static function create_file_block_from_anchor($anchor): array
    {
        $attributes = array('href' => $anchor->get_attribute('href'), 'textLinkHref' => $anchor->get_attribute('href'), 'fileName' => $anchor->get_inner_html(), 'showDownloadButton' => \true);
        if ($anchor->has_attribute('target')) {
            $attributes['textLinkTarget'] = $anchor->get_attribute('target');
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/file', $attributes);
    }
    /**
     * Infers a conservative core/embed provider slug from a URL.
     *
     * @param string $url Embed URL.
     * @return string
     */
    private static function get_embed_provider_slug(string $url): string
    {
        $host = wp_parse_url($url, \PHP_URL_HOST);
        $host = $host ? \strtolower(\preg_replace('/^www\./', '', $host)) : '';
        $providers = array('youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'vimeo.com' => 'vimeo', 'soundcloud.com' => 'soundcloud', 'spotify.com' => 'spotify', 'twitter.com' => 'twitter', 'x.com' => 'twitter', 'instagram.com' => 'instagram', 'tiktok.com' => 'tiktok');
        foreach ($providers as $needle => $slug) {
            if ($host === $needle || \substr($host, -\strlen('.' . $needle)) === '.' . $needle) {
                return $slug;
            }
        }
        return '';
    }
    /**
     * Converts common iframe embed URLs back to their public oEmbed URL.
     *
     * @param string $url Iframe URL.
     * @return string
     */
    private static function normalise_embed_url(string $url): string
    {
        if (\preg_match('#youtube\.com/embed/([^?&/]+)#i', $url, $matches)) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }
        if (\preg_match('#player\.vimeo\.com/video/(\d+)#i', $url, $matches)) {
            return 'https://vimeo.com/' . $matches[1];
        }
        return $url;
    }
    /**
     * core/heading transforms - h1-h6 elements
     *
     * @return array Transform definitions
     */
    private static function get_heading_transforms()
    {
        return array(array('blockName' => 'core/heading', 'priority' => 10, 'selector' => 'h1,h2,h3,h4,h5,h6', 'isMatch' => function ($element) {
            return \preg_match('/^H[1-6]$/i', $element->get_tag_name());
        }, 'transform' => function ($element) {
            $level = (int) \substr($element->get_tag_name(), 1);
            $content = $element->get_inner_html();
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'align' => \true, 'text_align' => \true, 'colors' => \true, 'typography' => \true, 'spacing' => \true, 'border' => \true, 'class_name' => \true));
            $attributes = \array_merge($attributes, array('level' => $level, 'content' => $content));
            return HTML_To_Blocks_Block_Factory::create_block('core/heading', $attributes);
        }));
    }
    /**
     * core/list transforms - ol and ul elements
     *
     * @return array Transform definitions
     */
    private static function get_list_transforms()
    {
        return array(array('blockName' => 'core/list', 'priority' => 9, 'selector' => 'dl', 'isMatch' => function ($element) {
            return 'DL' === $element->get_tag_name() && !empty(self::get_definition_list_pairs($element));
        }, 'transform' => function ($element) {
            return self::create_definition_list_block_from_element($element);
        }), array('blockName' => 'core/group', 'priority' => 9, 'selector' => 'ol,ul', 'isMatch' => function ($element) {
            return \in_array($element->get_tag_name(), array('OL', 'UL'), \true) && self::is_visual_list_element($element);
        }, 'transform' => function ($element, $handler) {
            return self::create_visual_list_group_from_element($element, $handler);
        }), array('blockName' => 'core/list', 'priority' => 10, 'selector' => 'ol,ul', 'isMatch' => function ($element) {
            return \in_array($element->get_tag_name(), array('OL', 'UL'), \true);
        }, 'transform' => function ($element) {
            return self::create_list_block_from_element($element);
        }));
    }
    /**
     * Creates an unordered list block from a simple definition list.
     *
     * @param HTML_To_Blocks_HTML_Element $definition_list The dl element.
     * @return array Block array.
     */
    private static function create_definition_list_block_from_element($definition_list): array
    {
        $list_attributes = self::get_block_support_attributes($definition_list, array('anchor' => \true, 'class_name' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
        $list_attributes = \array_merge($list_attributes, array('ordered' => \false));
        $inner_blocks = array();
        foreach (self::get_definition_list_pairs($definition_list) as $pair) {
            $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/list-item', array('content' => $pair['term'] . ': ' . $pair['description']));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/list', $list_attributes, $inner_blocks);
    }
    /**
     * Gets simple term/description pairs from a dl element.
     *
     * Supports generated fragments shaped as dl > div > dt + dd and plain
     * dl > dt + dd. More complex definition lists intentionally keep falling
     * through so unsupported structure remains visible to diagnostics.
     *
     * @param HTML_To_Blocks_HTML_Element $definition_list The dl element.
     * @return array<int,array{term:string,description:string}> Definition pairs.
     */
    private static function get_definition_list_pairs($definition_list): array
    {
        $children = $definition_list->get_child_elements();
        if (empty($children)) {
            return array();
        }
        if (self::definition_list_has_only_wrapper_pairs($children)) {
            $pairs = array();
            foreach ($children as $child) {
                $pairs[] = self::get_definition_pair_from_wrapper($child);
            }
            return \array_values(\array_filter($pairs));
        }
        return self::get_direct_definition_list_pairs($children);
    }
    /**
     * Checks whether all direct dl children are div wrappers around dt/dd pairs.
     *
     * @param array<int,HTML_To_Blocks_HTML_Element> $children Direct dl children.
     * @return bool True when every direct child is a simple pair wrapper.
     */
    private static function definition_list_has_only_wrapper_pairs(array $children): bool
    {
        foreach ($children as $child) {
            if ('DIV' !== $child->get_tag_name() || !self::get_definition_pair_from_wrapper($child)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Gets a term/description pair from a simple wrapper element.
     *
     * @param HTML_To_Blocks_HTML_Element $wrapper Candidate wrapper element.
     * @return array{term:string,description:string}|null Definition pair or null.
     */
    private static function get_definition_pair_from_wrapper($wrapper): ?array
    {
        $children = $wrapper->get_child_elements();
        if (\count($children) !== 2 || 'DT' !== $children[0]->get_tag_name() || 'DD' !== $children[1]->get_tag_name()) {
            return null;
        }
        return self::create_definition_pair($children[0], $children[1]);
    }
    /**
     * Gets term/description pairs from a dl with direct dt/dd children.
     *
     * @param array<int,HTML_To_Blocks_HTML_Element> $children Direct dl children.
     * @return array<int,array{term:string,description:string}> Definition pairs.
     */
    private static function get_direct_definition_list_pairs(array $children): array
    {
        $pairs = array();
        $count = \count($children);
        for ($index = 0; $index < $count; $index += 2) {
            $term = $children[$index] ?? null;
            $description = $children[$index + 1] ?? null;
            if (!$term || !$description || 'DT' !== $term->get_tag_name() || 'DD' !== $description->get_tag_name()) {
                return array();
            }
            $pair = self::create_definition_pair($term, $description);
            if (!$pair) {
                return array();
            }
            $pairs[] = $pair;
        }
        return $pairs;
    }
    /**
     * Creates a sanitized definition pair from dt and dd elements.
     *
     * @param HTML_To_Blocks_HTML_Element $term        The dt element.
     * @param HTML_To_Blocks_HTML_Element $description The dd element.
     * @return array{term:string,description:string}|null Definition pair or null.
     */
    private static function create_definition_pair($term, $description): ?array
    {
        $pair = array('term' => \trim($term->get_inner_html()), 'description' => \trim($description->get_inner_html()));
        return '' !== wp_strip_all_tags($pair['term']) && '' !== wp_strip_all_tags($pair['description']) ? $pair : null;
    }
    /**
     * Creates a list block from an HTML element (recursive for nested lists)
     *
     * @param HTML_To_Blocks_HTML_Element $list_element The ol/ul element
     * @return array Block array
     */
    private static function create_list_block_from_element($list_element)
    {
        $ordered = $list_element->get_tag_name() === 'OL';
        $list_attributes = self::get_block_support_attributes($list_element, array('anchor' => \true, 'class_name' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
        $list_attributes = \array_merge($list_attributes, array('ordered' => $ordered));
        if ($list_element->has_attribute('start')) {
            $list_attributes['start'] = (int) $list_element->get_attribute('start');
        }
        if ($list_element->has_attribute('reversed')) {
            $list_attributes['reversed'] = \true;
        }
        if ($list_element->has_attribute('type')) {
            $type = $list_element->get_attribute('type');
            $type_map = array('A' => 'upper-alpha', 'a' => 'lower-alpha', 'I' => 'upper-roman', 'i' => 'lower-roman');
            if (isset($type_map[$type])) {
                $list_attributes['type'] = $type_map[$type];
            }
        }
        $inner_blocks = array();
        $li_elements = self::get_direct_li_children($list_element->get_inner_html());
        foreach ($li_elements as $li_html) {
            $li = HTML_To_Blocks_HTML_Element::from_html($li_html);
            if ($li) {
                $list_item_block = self::create_list_item_block($li);
                if ($list_item_block) {
                    $inner_blocks[] = $list_item_block;
                }
            }
        }
        $block = HTML_To_Blocks_Block_Factory::create_block('core/list', $list_attributes, $inner_blocks);
        // The source class is already preserved in the static list wrapper markup.
        unset($block['attrs']['className']);
        return $block;
    }
    /**
     * Checks whether a list is being used as card/timeline layout scaffolding.
     *
     * @param HTML_To_Blocks_HTML_Element $list_element The ol/ul element.
     * @return bool True when the list should become editable group blocks.
     */
    private static function is_visual_list_element($list_element): bool
    {
        foreach (self::get_direct_li_children($list_element->get_inner_html()) as $li_html) {
            $li = HTML_To_Blocks_HTML_Element::from_html($li_html);
            if (!$li) {
                continue;
            }
            foreach ($li->get_child_elements() as $child) {
                if (self::is_visual_list_item_child($child)) {
                    return \true;
                }
            }
        }
        return \false;
    }
    /**
     * Checks whether a direct li child indicates layout content instead of prose.
     *
     * @param HTML_To_Blocks_HTML_Element $child Direct child element of an li.
     * @return bool True when the child should be editable outside core/list-item content.
     */
    private static function is_visual_list_item_child($child): bool
    {
        return \in_array($child->get_tag_name(), array('DIV', 'SECTION', 'ARTICLE', 'MAIN', 'ASIDE', 'HEADER', 'FOOTER', 'NAV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'FIGURE', 'TABLE', 'PRE', 'BLOCKQUOTE', 'DETAILS'), \true);
    }
    /**
     * Creates editable group blocks for visual ol/ul card or timeline layouts.
     *
     * @param HTML_To_Blocks_HTML_Element $list_element The ol/ul element.
     * @param callable                    $handler      Raw handler callback.
     * @return array Block array.
     */
    private static function create_visual_list_group_from_element($list_element, callable $handler): array
    {
        $inner_blocks = array();
        foreach (self::get_direct_li_children($list_element->get_inner_html()) as $li_html) {
            $li = HTML_To_Blocks_HTML_Element::from_html($li_html);
            if (!$li) {
                continue;
            }
            $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_visual_list_group_attributes($li), $handler(array('HTML' => $li->get_inner_html())));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_visual_list_group_attributes($list_element), $inner_blocks);
    }
    /**
     * Gets wrapper-safe attributes for visual list groups.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source ol/ul/li element.
     * @return array Block attributes.
     */
    private static function get_visual_list_group_attributes($element): array
    {
        return self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
    }
    /**
     * Creates a list-item block from an li element
     *
     * @param HTML_To_Blocks_HTML_Element $li_element The li element
     * @return array Block array
     */
    private static function create_list_item_block($li_element)
    {
        $inner_html = $li_element->get_inner_html();
        $nested_list = null;
        $nested_ol = $li_element->query_selector('ol');
        $nested_ul = $li_element->query_selector('ul');
        if ($nested_ol) {
            $nested_list = $nested_ol;
            $inner_html = \preg_replace('/<ol[^>]*>.*<\/ol>/is', '', $inner_html);
        } elseif ($nested_ul) {
            $nested_list = $nested_ul;
            $inner_html = \preg_replace('/<ul[^>]*>.*<\/ul>/is', '', $inner_html);
        }
        $content = \trim($inner_html);
        $inner_blocks = array();
        if ($nested_list) {
            $inner_blocks[] = self::create_list_block_from_element($nested_list);
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/list-item', array('content' => $content), $inner_blocks);
    }
    /**
     * Gets direct <li> children from list inner HTML
     *
     * @param string $inner_html The inner HTML of an ol/ul element
     * @return array Array of li element HTML strings
     */
    private static function get_direct_li_children(string $inner_html): array
    {
        $results = array();
        $len = \strlen($inner_html);
        $i = 0;
        $list_depth = 0;
        while ($i < $len) {
            $remaining = \substr($inner_html, $i);
            if (\preg_match('/^<(ul|ol)(?:\s|>)/i', $remaining)) {
                ++$list_depth;
                ++$i;
                continue;
            }
            if (\preg_match('/^<\/(ul|ol)\s*>/i', $remaining)) {
                --$list_depth;
                ++$i;
                continue;
            }
            if (0 === $list_depth && \preg_match('/^<li(?:\s[^>]*)?>/i', $remaining)) {
                $li_html = self::extract_balanced_li($remaining);
                if ($li_html) {
                    $results[] = $li_html;
                    $i += \strlen($li_html);
                    continue;
                }
            }
            ++$i;
        }
        return $results;
    }
    /**
     * Extracts a balanced <li> element including nested lists
     *
     * @param string $html HTML starting with <li
     * @return string|null Complete li element or null
     */
    private static function extract_balanced_li(string $html): ?string
    {
        $li_depth = 0;
        $len = \strlen($html);
        $i = 0;
        while ($i < $len) {
            $remaining = \substr($html, $i);
            if (\preg_match('/^<li(?:\s|>)/i', $remaining)) {
                ++$li_depth;
            } elseif (\preg_match('/^<\/li\s*>/i', $remaining, $close_match)) {
                --$li_depth;
                if (0 === $li_depth) {
                    return \substr($html, 0, $i + \strlen($close_match[0]));
                }
            }
            ++$i;
        }
        return null;
    }
    /**
     * core/buttons and core/button transforms - native WordPress button anchors.
     *
     * @return array Transform definitions
     */
    private static function get_button_transforms()
    {
        return array(array('blockName' => 'core/group', 'priority' => 8, 'selector' => 'div,p', 'isMatch' => function ($element) {
            return self::is_static_visual_button_container($element);
        }, 'transform' => function ($element) {
            return self::create_static_visual_button_group($element);
        }), array('blockName' => 'core/paragraph', 'priority' => 8, 'selector' => 'button', 'isMatch' => function ($element) {
            return self::is_static_visual_button($element);
        }, 'transform' => function ($element) {
            return self::create_static_visual_button_paragraph($element);
        }), array('blockName' => 'core/buttons', 'priority' => 8, 'selector' => 'div,p', 'isMatch' => function ($element) {
            return self::is_button_anchor_container($element) || self::is_single_button_anchor_wrapper($element);
        }, 'transform' => function ($element) {
            return self::create_buttons_block_from_container($element);
        }), array('blockName' => 'core/buttons', 'priority' => 9, 'selector' => 'a', 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'A' && self::is_button_like_anchor($element);
        }, 'transform' => function ($element) {
            return self::create_buttons_block_from_anchor($element);
        }), array('blockName' => 'core/buttons', 'priority' => 9, 'selector' => 'p', 'isMatch' => function ($element) {
            $anchor = self::get_single_anchor_from_html($element->get_inner_html());
            return $element->get_tag_name() === 'P' && $anchor && self::is_button_like_anchor($anchor);
        }, 'transform' => function ($element) {
            $anchor = self::get_single_anchor_from_html($element->get_inner_html());
            return self::create_buttons_block_from_anchor($anchor);
        }));
    }
    /**
     * Checks whether an element is a simple row/container of button-like anchors.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when the container can safely become core/buttons.
     */
    private static function is_button_anchor_container($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'P'), \true)) {
            return \false;
        }
        $children = self::get_direct_anchor_children_from_html($element->get_inner_html());
        if (\count($children) < 2) {
            return \false;
        }
        if (self::is_action_link_container($element)) {
            foreach ($children as $child) {
                if (self::is_class_sensitive_cta_anchor($child)) {
                    return \false;
                }
            }
            return \true;
        }
        foreach ($children as $child) {
            if (!self::is_button_like_anchor($child)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Checks whether an alignment/action wrapper contains one button-like CTA anchor.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when the wrapper can safely become core/buttons.
     */
    private static function is_single_button_anchor_wrapper($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'P'), \true)) {
            return \false;
        }
        $children = self::get_direct_anchor_children_from_html($element->get_inner_html());
        if (\count($children) !== 1 || !self::is_button_like_anchor($children[0])) {
            return \false;
        }
        return self::is_action_link_container($element) || self::is_alignment_button_wrapper($element);
    }
    /**
     * Checks for explicit alignment wrapper classes commonly used around CTAs.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when the wrapper class signals visual alignment.
     */
    private static function is_alignment_button_wrapper($element): bool
    {
        return self::class_matches($element, '/(?:^|[-_\s])(?:center|centered|text[-_]?center|aligncenter|align[-_]?center)(?:$|[-_\s])/i');
    }
    /**
     * Checks whether a wrapper contains only static visual button controls.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when direct button children can become native text blocks.
     */
    private static function is_static_visual_button_container($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'P'), \true)) {
            return \false;
        }
        $buttons = self::get_direct_static_visual_button_children_from_html($element->get_inner_html());
        return \count($buttons) >= 2;
    }
    /**
     * Gets direct static visual button children when no other content is present.
     *
     * @param string $html Inner HTML to inspect.
     * @return array Static visual button elements.
     */
    private static function get_direct_static_visual_button_children_from_html(string $html): array
    {
        $remaining = $html;
        $buttons = array();
        if (!\preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $html, $matches, \PREG_SET_ORDER)) {
            return array();
        }
        foreach ($matches as $match) {
            $outer = $match[0];
            $attributes = self::parse_attribute_string($match[1]);
            $button = new HTML_To_Blocks_HTML_Element('button', $attributes, $outer, \trim($match[2]));
            if (!self::is_static_visual_button($button)) {
                return array();
            }
            $buttons[] = $button;
            $remaining = \str_replace($outer, '', $remaining);
        }
        return \trim($remaining) === '' ? $buttons : array();
    }
    /**
     * Checks whether a button is static visual UI text rather than a form control.
     *
     * Inline `on*` event handlers (e.g. `onclick`) do not disqualify the button:
     * the block factory only carries class-based block support attributes, so the
     * handler is dropped from the output instead of being preserved as raw HTML.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when the button can safely become editable paragraph text.
     */
    private static function is_static_visual_button($element): bool
    {
        if ('BUTTON' !== $element->get_tag_name()) {
            return \false;
        }
        if ($element->has_attribute('form') || $element->has_attribute('name') || $element->has_attribute('value')) {
            return \false;
        }
        $type = \strtolower(\trim((string) ($element->get_attribute('type') ?? '')));
        if (\in_array($type, array('submit', 'reset'), \true)) {
            return \false;
        }
        if (\preg_match('/<\s*[a-z][^>]*>/i', $element->get_inner_html()) === 1 || \trim($element->get_text_content()) === '') {
            return \false;
        }
        $class_name = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        return \preg_match('/(?:^|[-_\s])(?:tabs?|chips?|filters?|pills?|segmented|selector|use[-_]?case)(?:$|[-_\s])/i', $class_name) === 1;
    }
    /**
     * Creates a native group from a static visual button row.
     *
     * @param HTML_To_Blocks_HTML_Element $element Button row wrapper.
     * @return array Block array.
     */
    private static function create_static_visual_button_group($element): array
    {
        $buttons = \array_map(array(__CLASS__, 'create_static_visual_button_paragraph'), self::get_direct_static_visual_button_children_from_html($element->get_inner_html()));
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $buttons);
    }
    /**
     * Creates an editable paragraph block from a static visual button.
     *
     * @param HTML_To_Blocks_HTML_Element $element Button element.
     * @return array Block array.
     */
    private static function create_static_visual_button_paragraph($element): array
    {
        $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'colors' => \true, 'typography' => \true, 'spacing' => \true, 'border' => \true));
        $attributes['content'] = $element->get_inner_html();
        return HTML_To_Blocks_Block_Factory::create_block('core/paragraph', $attributes);
    }
    /**
     * Checks whether a container is explicitly an action/link row.
     *
     * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
     * @return bool True when direct anchors should remain separate action blocks.
     */
    private static function is_action_link_container($element): bool
    {
        return self::class_matches($element, '/(?:^|[-_\s])(?:actions?|buttons?|cta)(?:$|[-_\s])/i');
    }
    /**
     * Gets direct anchor children when the HTML contains only sibling anchors and whitespace.
     *
     * @param string $html Inner HTML to inspect.
     * @return array Anchor elements.
     */
    private static function get_direct_anchor_children_from_html(string $html): array
    {
        $remaining = $html;
        $anchors = array();
        if (!\preg_match_all('/<a\s([^>]*)>(.*?)<\/a>/is', $html, $matches, \PREG_SET_ORDER)) {
            return array();
        }
        foreach ($matches as $match) {
            $outer = $match[0];
            $attributes = self::parse_attribute_string($match[1]);
            $anchors[] = new HTML_To_Blocks_HTML_Element('a', $attributes, $outer, \trim($match[2]));
            $remaining = \str_replace($outer, '', $remaining);
        }
        return \trim($remaining) === '' ? $anchors : array();
    }
    /**
     * Gets a single anchor element when the HTML is only one anchor.
     *
     * @param string $html Inner HTML to inspect.
     * @return HTML_To_Blocks_HTML_Element|null Anchor element or null.
     */
    private static function get_single_anchor_from_html(string $html): ?HTML_To_Blocks_HTML_Element
    {
        $html = \trim($html);
        if (!\preg_match('/^<a\s([^>]*)>(.*)<\/a>$/is', $html, $matches)) {
            return null;
        }
        $attributes = self::parse_attribute_string($matches[1]);
        return new HTML_To_Blocks_HTML_Element('a', $attributes, $html, \trim($matches[2]));
    }
    /**
     * Parses an HTML attribute string into an associative array.
     *
     * @param string $attribute_string Raw attribute string.
     * @return array Parsed attributes.
     */
    private static function parse_attribute_string(string $attribute_string): array
    {
        $attributes = array();
        if (\preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $attribute_string, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = '';
                if (isset($match[3]) && '' !== $match[3]) {
                    $value = $match[3];
                } elseif (isset($match[4]) && '' !== $match[4]) {
                    $value = $match[4];
                } elseif (isset($match[5])) {
                    $value = $match[5];
                }
                $attributes[\strtolower($match[1])] = \html_entity_decode($value, \ENT_QUOTES, 'UTF-8');
            }
        }
        return $attributes;
    }
    /**
     * Checks if an anchor already carries native WordPress button markup.
     *
     * @param HTML_To_Blocks_HTML_Element $element Anchor element.
     * @return bool True when the anchor is already WordPress-button-shaped.
     */
    private static function is_button_like_anchor($element): bool
    {
        // @phpstan-ignore-next-line booleanNot.alwaysFalse -- Defensive public API guard for untyped external callers.
        if (!$element || $element->get_tag_name() !== 'A') {
            return \false;
        }
        if (self::is_class_sensitive_cta_anchor($element)) {
            return \false;
        }
        $class_name = $element->get_attribute('class') ?? '';
        if (\preg_match('/(?:^|\s)(?:wp-block-button__link|wp-element-button)(?:$|\s)/i', $class_name) === 1) {
            return \true;
        }
        if (\preg_match('/(?:^|\s)btn(?:$|\s)/i', $class_name) === 1) {
            return \true;
        }
        if (\preg_match('/(?:^|\s)btn-(?!cta(?:$|\s))[A-Za-z0-9_-]+(?:$|\s)/i', $class_name) === 1) {
            return \true;
        }
        return \preg_match('/(?:^|\s)[A-Za-z0-9]+-btn(?:-[A-Za-z0-9_-]+)?(?:$|\s)/i', $class_name) === 1;
    }
    /**
     * Checks for CTA link classes whose selectors must remain anchor-owned.
     *
     * Gutenberg core/button serializes custom className on the wrapper div, not
     * the inner link. Keep these anchors as inline paragraph content instead of
     * silently changing selectors like `.cta-btn:hover` or `a.cta-link`.
     *
     * @param HTML_To_Blocks_HTML_Element $element Anchor element.
     * @return bool True when class ownership is more important than button shape.
     */
    private static function is_class_sensitive_cta_anchor($element): bool
    {
        // @phpstan-ignore-next-line booleanNot.alwaysFalse -- Defensive public API guard for untyped external callers.
        if (!$element || $element->get_tag_name() !== 'A') {
            return \false;
        }
        $class_name = $element->get_attribute('class') ?? '';
        if (\preg_match('/(?:^|\s)(?:wp-block-button__link|wp-element-button)(?:$|\s)/i', $class_name) === 1) {
            return \false;
        }
        return \preg_match('/(?:^|\s)(?:cta-(?:btn|link)|(?:btn|link)-cta)(?:$|\s)/i', $class_name) === 1;
    }
    /**
     * Creates a buttons wrapper with one button child from an anchor.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor Anchor element.
     * @return array Block array.
     */
    private static function create_buttons_block_from_anchor($anchor): array
    {
        return self::create_buttons_block_from_anchors(array($anchor));
    }
    /**
     * Creates a buttons wrapper with one button child per direct anchor.
     *
     * @param HTML_To_Blocks_HTML_Element $element Container element.
     * @return array Block array.
     */
    private static function create_buttons_block_from_container($element): array
    {
        $attributes = array();
        if ($element->has_attribute('class')) {
            $attributes['className'] = $element->get_attribute('class');
        }
        $layout = self::extract_buttons_layout_attributes($element);
        if (!empty($layout)) {
            $attributes['layout'] = $layout;
        }
        return self::create_buttons_block_from_anchors(self::get_direct_anchor_children_from_html($element->get_inner_html()), $attributes);
    }
    /**
     * Extracts core/buttons layout attributes from a source container.
     *
     * Maps inline horizontal alignment to Gutenberg-compatible flex layout
     * attributes so the buttons block round-trips through Gutenberg with the
     * source horizontal alignment intact. core/buttons defaults to a flex
     * layout, so the layout type is fixed to flex whenever any horizontal
     * alignment intent is detected.
     *
     * @param HTML_To_Blocks_HTML_Element $element Container element.
     * @return array Layout attribute map (possibly empty).
     */
    private static function extract_buttons_layout_attributes($element): array
    {
        $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
        $classes = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        $justify = '';
        if ('' !== $style) {
            $justify = \strtolower(\trim(self::extract_css_property($style, 'justify-content')));
        }
        // Map common CSS justify-content values to Gutenberg's supported values.
        $mapped_justify = '';
        switch ($justify) {
            case 'center':
                $mapped_justify = 'center';
                break;
            case 'flex-start':
            case 'start':
            case 'left':
                $mapped_justify = 'left';
                break;
            case 'flex-end':
            case 'end':
            case 'right':
                $mapped_justify = 'right';
                break;
            case 'space-between':
                $mapped_justify = 'space-between';
                break;
        }
        if ('' === $mapped_justify && '' !== $classes) {
            if (\preg_match('/(?:^|\s)is-content-justification-(left|right|center|space-between)(?:\s|$)/i', $classes, $matches)) {
                $mapped_justify = \strtolower($matches[1]);
            }
        }
        if ('' === $mapped_justify) {
            return array();
        }
        return array('type' => 'flex', 'justifyContent' => $mapped_justify);
    }
    /**
     * Creates a buttons wrapper with button children from anchors.
     *
     * @param array $anchors            Anchor elements.
     * @param array $wrapper_attributes Wrapper block attributes.
     * @return array Block array.
     */
    private static function create_buttons_block_from_anchors(array $anchors, array $wrapper_attributes = array()): array
    {
        $buttons = array();
        foreach ($anchors as $anchor) {
            $buttons[] = self::create_button_block_from_anchor($anchor);
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/buttons', $wrapper_attributes, $buttons);
    }
    /**
     * Creates one button block from an anchor.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor Anchor element.
     * @return array Block array.
     */
    private static function create_button_block_from_anchor($anchor): array
    {
        $attributes = array('text' => $anchor->get_inner_html());
        if ($anchor->has_attribute('href')) {
            $attributes['url'] = $anchor->get_attribute('href');
        }
        if ($anchor->has_attribute('target')) {
            $attributes['linkTarget'] = $anchor->get_attribute('target');
        }
        if ($anchor->has_attribute('rel')) {
            $attributes['rel'] = $anchor->get_attribute('rel');
        }
        if ($anchor->has_attribute('class')) {
            $attributes['className'] = self::button_block_class_name($anchor->get_attribute('class'));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/button', $attributes);
    }
    /**
     * Converts anchor classes to button block classes.
     *
     * @param string $class_name Anchor class attribute.
     * @return string Button block class name.
     */
    private static function button_block_class_name(string $class_name): string
    {
        $classes = \preg_split('/\s+/', \trim($class_name));
        if (\false === $classes) {
            return '';
        }
        $classes = \array_filter($classes, function ($class_name) {
            return !\in_array($class_name, array('wp-block-button__link', 'wp-element-button'), \true);
        });
        return \trim(\implode(' ', $classes));
    }
    /**
     * core/image transforms - figure with img
     *
     * @return array Transform definitions
     */
    private static function get_image_transforms()
    {
        return array(array('blockName' => 'core/image', 'priority' => 10, 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'FIGURE') {
                return \false;
            }
            $img = $element->query_selector('img');
            return null !== $img;
        }, 'transform' => function ($element) {
            $img = $element->query_selector('img');
            $figcaption = $element->query_selector('figcaption');
            $attributes = array('url' => $img->get_attribute('src') ?? '');
            self::apply_image_element_attributes($attributes, $img);
            if ($figcaption) {
                $attributes['caption'] = $figcaption->get_inner_html();
            }
            $class_name = '';
            if ($element->has_attribute('class')) {
                $class_name .= $element->get_attribute('class') . ' ';
            }
            if ($img->has_attribute('class')) {
                $class_name .= $img->get_attribute('class');
            }
            $class_name = \trim($class_name);
            if (\preg_match('/(?:^|\s)align(left|center|right)(?:$|\s)/', $class_name, $matches)) {
                $attributes['align'] = $matches[1];
            }
            if (\preg_match('/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $class_name, $matches)) {
                $attributes['id'] = (int) $matches[1];
            }
            if ($element->has_attribute('id') && $element->get_attribute('id') !== '') {
                $attributes['anchor'] = $element->get_attribute('id');
            }
            $anchor_element = $element->query_selector('a');
            if ($anchor_element && $anchor_element->has_attribute('href')) {
                $attributes['href'] = $anchor_element->get_attribute('href');
                $attributes['linkDestination'] = 'custom';
                if ($anchor_element->has_attribute('rel')) {
                    $attributes['rel'] = $anchor_element->get_attribute('rel');
                }
                if ($anchor_element->has_attribute('class')) {
                    $attributes['linkClass'] = $anchor_element->get_attribute('class');
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/image', $attributes);
        }), array('blockName' => 'core/image', 'priority' => 15, 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'IMG';
        }, 'transform' => function ($element) {
            $attributes = array('url' => $element->get_attribute('src') ?? '');
            self::apply_image_element_attributes($attributes, $element);
            $class_name = $element->has_attribute('class') ? $element->get_attribute('class') : '';
            if (\preg_match('/(?:^|\s)align(left|center|right)(?:$|\s)/', $class_name, $matches)) {
                $attributes['align'] = $matches[1];
            }
            if (\preg_match('/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $class_name, $matches)) {
                $attributes['id'] = (int) $matches[1];
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/image', $attributes);
        }));
    }
    /**
     * core/details transforms - details elements with a summary.
     *
     * @return array Transform definitions
     */
    private static function get_details_transforms()
    {
        return array(array('blockName' => 'core/details', 'priority' => 10, 'selector' => 'details', 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'DETAILS' && \preg_match('/<summary(?:\s[^>]*)?>.*?<\/summary>/is', $element->get_inner_html()) === 1;
        }, 'transform' => function ($element, $handler) {
            $inner_html = $element->get_inner_html();
            \preg_match('/<summary(?:\s[^>]*)?>(.*?)<\/summary>/is', $inner_html, $summary_matches);
            $summary = \trim($summary_matches[1] ?? '');
            $content_html = \trim(\preg_replace('/<summary(?:\s[^>]*)?>.*?<\/summary>/is', '', $inner_html, 1));
            $inner_blocks = '' !== $content_html ? $handler(array('HTML' => $content_html)) : array();
            $attributes = array('summary' => $summary);
            return HTML_To_Blocks_Block_Factory::create_block('core/details', $attributes, $inner_blocks);
        }));
    }
    /**
     * core/pullquote transforms - explicit pullquote blockquotes.
     *
     * @return array Transform definitions
     */
    private static function get_pullquote_transforms()
    {
        return array(array('blockName' => 'core/pullquote', 'priority' => 9, 'selector' => 'blockquote', 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'BLOCKQUOTE' || !$element->has_attribute('class')) {
                return \false;
            }
            $class_name = $element->get_attribute('class');
            return \preg_match('/(?:^|\s)(?:wp-block-pullquote|pullquote|is-style-pullquote)(?:$|\s)/i', $class_name) === 1;
        }, 'transform' => function ($element) {
            $value = \trim($element->get_inner_html());
            $citation = '';
            if (\preg_match('/<cite(?:\s[^>]*)?>(.*?)<\/cite>/is', $value, $matches)) {
                $citation = \trim($matches[1]);
                $value = \trim(\preg_replace('/<cite(?:\s[^>]*)?>.*?<\/cite>/is', '', $value, 1));
            }
            $attributes = array('value' => $value);
            if ('' !== $citation) {
                $attributes['citation'] = $citation;
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/pullquote', $attributes);
        }));
    }
    /**
     * core/quote transforms - blockquote elements
     *
     * @return array Transform definitions
     */
    private static function get_quote_transforms()
    {
        return array(array('blockName' => 'core/group', 'priority' => 9, 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'FIGURE') {
                return \false;
            }
            $children = $element->get_child_elements();
            return \count($children) === 2 && $children[0]->get_tag_name() === 'BLOCKQUOTE' && $children[1]->get_tag_name() === 'FIGCAPTION';
        }, 'transform' => function ($element, $handler) {
            $children = $element->get_child_elements();
            $blockquote = $children[0];
            $figcaption = $children[1];
            $inner_blocks = $handler(array('HTML' => $blockquote->get_outer_html()));
            $caption_attrs = self::get_block_support_attributes($figcaption, array('class_name' => \true));
            $caption_markup = \trim($figcaption->get_inner_html());
            if ('' !== $caption_markup) {
                $caption_attrs['content'] = $caption_markup;
                $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', $caption_attrs);
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
        }), array('blockName' => 'core/quote', 'priority' => 10, 'selector' => 'blockquote', 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'BLOCKQUOTE';
        }, 'transform' => function ($element, $handler) {
            $inner_html = $element->get_inner_html();
            $inner_blocks = $handler(array('HTML' => $inner_html));
            $attributes = array();
            if ($element->has_attribute('id') && $element->get_attribute('id') !== '') {
                $attributes['anchor'] = $element->get_attribute('id');
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/quote', $attributes, $inner_blocks);
        }));
    }
    /**
     * core/code transforms - pre > code elements
     *
     * @return array Transform definitions
     */
    private static function get_code_transforms()
    {
        return array(array('blockName' => 'core/code', 'priority' => 10, 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'PRE') {
                return \false;
            }
            $code = $element->query_selector('code');
            if (!$code) {
                return \false;
            }
            $inner_html = $element->get_inner_html();
            $stripped = \preg_replace('/<code[^>]*>.*<\/code>/is', '', $inner_html);
            $has_only_code = empty(\trim(wp_strip_all_tags($stripped)));
            return $has_only_code;
        }, 'transform' => function ($element) {
            $code = $element->query_selector('code');
            if ($code && array() !== $code->get_child_elements()) {
                $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true));
                $attributes['content'] = $code->get_inner_html();
                if ($code->has_attribute('class')) {
                    $attributes['className'] = self::merge_class_names($attributes['className'] ?? '', $code->get_attribute('class'));
                }
                return HTML_To_Blocks_Block_Factory::create_block('core/preformatted', $attributes);
            }
            $content = $code ? $code->get_text_content() : $element->get_text_content();
            $attributes = array('content' => $content);
            // Preserve language class for syntax highlighting
            if ($code && $code->has_attribute('class')) {
                $class = $code->get_attribute('class');
                if (\preg_match('/language-(\S+)/', $class, $matches)) {
                    $attributes['className'] = 'language-' . $matches[1];
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/code', $attributes);
        }));
    }
    /**
     * Visual code-window/demo transforms.
     *
     * @return array Transform definitions
     */
    private static function get_code_window_transforms()
    {
        return array(array('blockName' => 'core/group', 'priority' => 8, 'isMatch' => function ($element) {
            return self::is_decorative_code_chrome_element($element);
        }, 'transform' => function ($element, $handler) {
            $inner_blocks = array() !== $element->get_child_elements() ? $handler(array('HTML' => $element->get_inner_html())) : array();
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
        }), array('blockName' => 'core/group', 'priority' => 9, 'isMatch' => function ($element) {
            return self::is_code_window_text_chrome_element($element);
        }, 'transform' => function ($element) {
            return self::create_code_window_text_group($element);
        }), array('blockName' => 'core/preformatted', 'priority' => 9, 'isMatch' => function ($element) {
            return self::is_div_line_code_panel($element);
        }, 'transform' => function ($element) {
            return self::create_code_window_body_block($element);
        }), array('blockName' => 'core/group', 'priority' => 9, 'isMatch' => function ($element) {
            if ('DIV' !== $element->get_tag_name()) {
                return \false;
            }
            if (self::has_unsafe_code_display_markup($element)) {
                return \false;
            }
            if (self::class_matches($element, '/(?:^|\s)(?:[A-Za-z0-9_-]*code[-_]?(?:window|preview|panel|comparison)[A-Za-z0-9_-]*|code[-_]?pane)(?:$|\s)/i')) {
                return \true;
            }
            if (self::is_code_window_part_container($element)) {
                return \true;
            }
            return self::class_matches($element, '/(?:^|[-_\s])(?:code|workflow[-_\s]?code)(?:$|[-_\s])/i') && 1 === \preg_match('/class=["\'][^"\']*[A-Za-z0-9_-]*code[-_]?window[A-Za-z0-9_-]*[^"\']*["\']/i', $element->get_inner_html());
        }, 'transform' => function ($element, $handler) {
            return self::create_code_window_block($element, $handler);
        }));
    }
    /**
     * Creates a native group for a visual code-window wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element Code-window wrapper.
     * @param callable                    $handler Recursive raw handler.
     * @return array Block array.
     */
    private static function create_code_window_block($element, $handler): array
    {
        $inner_blocks = array();
        foreach ($element->get_child_elements() as $child) {
            $class_name = $child->has_attribute('class') ? $child->get_attribute('class') : '';
            if ('PRE' === $child->get_tag_name()) {
                $inner_blocks[] = self::create_pre_code_preformatted_block($child);
                continue;
            }
            if (self::class_matches($child, '/(?:^|[-_\s])code[-_]?block(?:$|[-_\s])/i') && \preg_match('/<pre\b/i', $child->get_inner_html()) === 1) {
                $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($child), $handler(array('HTML' => $child->get_inner_html())));
                continue;
            }
            if (\preg_match('/(?:^|\s)[A-Za-z0-9_-]*code[-_]?(?:body|block)[A-Za-z0-9_-]*(?:$|\s)/i', $class_name) === 1) {
                $inner_blocks[] = self::create_code_window_body_block($child);
                continue;
            }
            if (\preg_match('/(?:^|\s)[A-Za-z0-9_-]*(?:code[-_]?(?:bar|titlebar|header|pane[-_]?header|panel[-_]?label|badge)|code[-_]?preview[-_]?(?:header|tab[-_]?bar)|window[-_]?(?:bar|title|header)|arrow[-_]?row)[A-Za-z0-9_-]*(?:$|\s)/i', $class_name) === 1) {
                $inner_blocks[] = self::create_code_window_text_group($child);
                continue;
            }
            $inner_blocks = \array_merge($inner_blocks, $handler(array('HTML' => $child->get_outer_html())));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
    }
    /**
     * Creates a preformatted block for code panel pre/code bodies.
     *
     * @param HTML_To_Blocks_HTML_Element $element Pre element.
     * @return array Block array.
     */
    private static function create_pre_code_preformatted_block($element): array
    {
        $code = $element->query_selector('code');
        $content = $code ? $code->get_inner_html() : $element->get_inner_html();
        $attrs = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true));
        $attrs['content'] = $content;
        if ($code && $code->has_attribute('class')) {
            $attrs['className'] = self::merge_class_names($attrs['className'] ?? '', $code->get_attribute('class'));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/preformatted', $attrs);
    }
    /**
     * Creates a preformatted block from code-window line wrappers.
     *
     * @param HTML_To_Blocks_HTML_Element $element Code body element.
     * @return array Block array.
     */
    private static function create_code_window_body_block($element): array
    {
        $lines = array();
        foreach ($element->get_child_elements() as $child) {
            if ($child->get_tag_name() === 'DIV') {
                $line_html = $child->get_inner_html();
                if ($child->has_attribute('class')) {
                    $line_class = self::safe_block_class_name($child->get_attribute('class'));
                    if ('' !== $line_class) {
                        $line_html = '<span class="' . esc_attr($line_class) . '">' . $line_html . '</span>';
                    }
                }
                $lines[] = $line_html;
            }
        }
        $content = !empty($lines) ? \implode("\n", $lines) : $element->get_inner_html();
        $attrs = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true));
        $attrs['content'] = $content;
        return HTML_To_Blocks_Block_Factory::create_block('core/preformatted', $attrs);
    }
    /**
     * Creates a native group for non-code chrome around a code-window.
     *
     * @param HTML_To_Blocks_HTML_Element $element Chrome element.
     * @return array Block array.
     */
    private static function create_code_window_text_group($element): array
    {
        $content = self::get_code_window_chrome_content($element);
        $attrs = self::get_common_layout_attributes($element);
        if ('' === $content) {
            return HTML_To_Blocks_Block_Factory::create_block('core/group', $attrs);
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', $attrs, array(HTML_To_Blocks_Block_Factory::create_block('core/paragraph', array('className' => $attrs['className'] ?? '', 'content' => $content))));
    }
    /**
     * Extracts meaningful visible text from code-window chrome.
     *
     * @param HTML_To_Blocks_HTML_Element $element Chrome element.
     * @return string Editable chrome text, excluding decorative dots.
     */
    private static function get_code_window_chrome_content($element): string
    {
        $content = $element->get_inner_html();
        foreach ($element->get_child_elements() as $child) {
            if (self::class_matches($child, '/(?:^|\s)[A-Za-z0-9_-]*code[-_]?dot[A-Za-z0-9_-]*(?:$|\s)/i') || self::is_empty_decorative_element($child)) {
                $content = \str_replace($child->get_outer_html(), '', $content);
            }
        }
        return \trim($content);
    }
    /**
     * Checks whether an element is empty/decorative code-window chrome.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when the element can safely become native non-content chrome.
     */
    private static function is_decorative_code_chrome_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        if (self::has_unsafe_code_display_markup($element) || !self::has_decorative_code_chrome_class($element)) {
            return \false;
        }
        $text = \trim(wp_strip_all_tags($element->get_inner_html()));
        if (!self::is_decorative_text_content($text)) {
            return \false;
        }
        foreach ($element->get_child_elements() as $child) {
            if (!self::is_decorative_code_chrome_element($child)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Checks whether an element is textual code-window chrome such as title bars.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when chrome can safely become a native group.
     */
    private static function is_code_window_text_chrome_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        if (self::has_unsafe_code_display_markup($element)) {
            return \false;
        }
        return self::class_matches($element, '/(?:^|\s)[A-Za-z0-9_-]*(?:code[-_]?(?:bar|titlebar|header|pane[-_]?header|panel[-_]?label|badge)|code[-_]?preview[-_]?(?:header|tab[-_]?bar)|window[-_]?(?:bar|title|header)|arrow[-_]?row)[A-Za-z0-9_-]*(?:$|\s)/i');
    }
    /**
     * Checks whether a generic code-labeled wrapper contains code-window parts.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when a wrapper can be decomposed into native code-window blocks.
     */
    private static function is_code_window_part_container($element): bool
    {
        if ('DIV' !== $element->get_tag_name() || !self::class_matches($element, '/(?:^|[-_\s])code(?:$|[-_\s])/i')) {
            return \false;
        }
        $has_header = \false;
        $has_body = \false;
        foreach ($element->get_child_elements() as $child) {
            $class_name = $child->has_attribute('class') ? $child->get_attribute('class') : '';
            if (\preg_match('/(?:^|\s)[A-Za-z0-9_-]*(?:code[-_]?(?:bar|titlebar|header|pane[-_]?header|panel[-_]?label)|code[-_]?preview[-_]?header)[A-Za-z0-9_-]*(?:$|\s)/i', $class_name) === 1) {
                $has_header = \true;
            }
            if ('PRE' === $child->get_tag_name() || \preg_match('/(?:^|\s)[A-Za-z0-9_-]*(?:code[-_]?(?:body|block|output|panel)|code[-_]?preview[-_]?body)[A-Za-z0-9_-]*(?:$|\s)/i', $class_name) === 1) {
                $has_body = \true;
            }
        }
        $inner_html = $element->get_inner_html();
        if (!$has_header && \preg_match('/class=["\'][^"\']*[A-Za-z0-9_-]*code[-_]?(?:bar|titlebar|header|pane[-_]?header|panel[-_]?label)[A-Za-z0-9_-]*[^"\']*["\']/i', $inner_html) === 1) {
            $has_header = \true;
        }
        if (!$has_body && \preg_match('/<pre\b/i', $inner_html) === 1) {
            $has_body = \true;
        }
        return $has_header && $has_body;
    }
    /**
     * Checks whether a classed code panel is composed of direct div line wrappers.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when the wrapper can become a preformatted native block.
     */
    private static function is_div_line_code_panel($element): bool
    {
        if ('DIV' !== $element->get_tag_name() || !self::class_matches($element, '/(?:^|[-_\s])(?:code|terminal|snippet)(?:$|[-_\s])/i')) {
            return \false;
        }
        if (self::class_matches($element, '/(?:^|\s)[A-Za-z0-9_-]*code[-_]?window[A-Za-z0-9_-]*(?:$|\s)/i')) {
            return \false;
        }
        if (self::has_unsafe_code_display_markup($element)) {
            return \false;
        }
        $children = $element->get_child_elements();
        if (\count($children) < 2) {
            return \false;
        }
        $has_text = \false;
        foreach ($children as $child) {
            if ('DIV' !== $child->get_tag_name()) {
                return \false;
            }
            foreach ($child->get_child_elements() as $inline_child) {
                if (!\in_array($inline_child->get_tag_name(), array('SPAN', 'BR', 'CODE', 'STRONG', 'B', 'EM', 'I'), \true)) {
                    return \false;
                }
            }
            $text = \str_replace(" ", ' ', \html_entity_decode(\trim($child->get_text_content()), \ENT_QUOTES, 'UTF-8'));
            if (\trim($text) !== '') {
                $has_text = \true;
            }
        }
        return $has_text;
    }
    /**
     * Checks for generic code-window chrome class names.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when class names describe decorative code chrome.
     */
    private static function has_decorative_code_chrome_class($element): bool
    {
        $class_name = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        if ('' === $class_name) {
            return \false;
        }
        $chrome_part = '(?:dot|line|divider|separator|rule|arrow|chrome|bar|titlebar)';
        return \preg_match('/(?:^|[\s_-])code[\w-]*[\s_-]?' . $chrome_part . '(?:$|[\s_-]|\d)/i', $class_name) === 1 || \preg_match('/(?:^|[\s_-])' . $chrome_part . '[\w-]*[\s_-]?code(?:$|[\s_-]|\d)/i', $class_name) === 1;
    }
    /**
     * Checks whether text is empty or purely visual punctuation/chrome.
     *
     * @param string $text Text content to inspect.
     * @return bool True when text carries no semantic content.
     */
    private static function is_decorative_text_content(string $text): bool
    {
        $text = \trim(\html_entity_decode($text, \ENT_QUOTES, 'UTF-8'));
        if ('' === $text) {
            return \true;
        }
        return \preg_match('/^[\s\-_=|:;.,•·*+<>›»«‹→←↑↓↔⇒⇐⇑⇓➜➔➝⟶⟵⟷]+$/u', $text) === 1;
    }
    /**
     * core/verse transforms - explicit verse/preformatted poetry classes.
     *
     * @return array Transform definitions
     */
    private static function get_verse_transforms()
    {
        return array(array('blockName' => 'core/verse', 'priority' => 10, 'selector' => 'pre', 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'PRE' || !$element->has_attribute('class')) {
                return \false;
            }
            $class_name = $element->get_attribute('class');
            return \preg_match('/(?:^|\s)(?:wp-block-verse|verse)(?:$|\s)/i', $class_name) === 1;
        }, 'transform' => function ($element) {
            return HTML_To_Blocks_Block_Factory::create_block('core/verse', array('content' => $element->get_inner_html()));
        }));
    }
    /**
     * core/preformatted transforms - pre elements (not containing code)
     *
     * @return array Transform definitions
     */
    private static function get_preformatted_transforms()
    {
        return array(array('blockName' => 'core/preformatted', 'priority' => 9, 'isMatch' => function ($element) {
            return self::is_div_code_snippet_element($element);
        }, 'transform' => function ($element) {
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true));
            $attributes['content'] = self::normalise_code_snippet_content($element);
            return HTML_To_Blocks_Block_Factory::create_block('core/preformatted', $attributes);
        }), array('blockName' => 'core/preformatted', 'priority' => 11, 'isMatch' => function ($element) {
            if ($element->get_tag_name() !== 'PRE') {
                return \false;
            }
            $code = $element->query_selector('code');
            if (!$code) {
                return \true;
            }
            $inner_html = $element->get_inner_html();
            $stripped = \preg_replace('/<code[^>]*>.*<\/code>/is', '', $inner_html);
            $has_only_code = empty(\trim(wp_strip_all_tags($stripped)));
            return !$has_only_code;
        }, 'transform' => function ($element) {
            $content = $element->get_inner_html();
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true));
            $attributes['content'] = $content;
            return HTML_To_Blocks_Block_Factory::create_block('core/preformatted', $attributes);
        }));
    }
    /**
     * Checks whether a div is a styled code snippet using inline syntax spans and br line breaks.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when the element should become core/preformatted.
     */
    private static function is_div_code_snippet_element($element): bool
    {
        if ($element->get_tag_name() !== 'DIV') {
            return \false;
        }
        if (self::class_matches($element, '/(?:^|\s)[A-Za-z0-9_-]*code[-_]?window[A-Za-z0-9_-]*(?:$|\s)/i')) {
            return \false;
        }
        if (self::has_unsafe_code_display_markup($element)) {
            return \false;
        }
        if (self::class_matches($element, '/(?:^|[-_\s])ws[-_]?code(?:$|[-_\s])/i')) {
            return \trim($element->get_text_content()) !== '';
        }
        if (!self::class_matches($element, '/(?:^|[-_\s])(?:step[-_\s]?code|workflow[-_\s]?code|code[-_\s]?(?:snippet|block|body|output))(?:$|[-_\s])/i')) {
            return \false;
        }
        $inner_html = $element->get_inner_html();
        $has_br_lines = \preg_match('/<br\s*\/?\s*>/i', $inner_html) === 1;
        $has_display_block_lines = self::has_display_block_code_lines($element);
        if (!$has_br_lines && !$has_display_block_lines) {
            return \false;
        }
        if (self::class_matches($element, '/(?:^|[-_\s])(?:step[-_\s]?code|workflow[-_\s]?code|code[-_\s]?(?:body|output))(?:$|[-_\s])/i')) {
            return \trim($element->get_text_content()) !== '';
        }
        return \preg_match('/(?:&lt;|&gt;|\/\/|\x{2192}|&rarr;|=&quot;|=\")/iu', $inner_html) === 1;
    }
    /**
     * Converts snippet div line-break markup into preformatted content.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source snippet element.
     * @return string Preformatted block content.
     */
    private static function normalise_code_snippet_content($element): string
    {
        $display_block_lines = self::get_display_block_code_lines($element);
        if (\count($display_block_lines) >= 2) {
            return \trim(\implode("\n", $display_block_lines));
        }
        $inner_html = $element->get_inner_html();
        $content = \preg_replace('/<br\s*\/?\s*>/i', "\n", $inner_html);
        return \trim($content);
    }
    /**
     * Checks whether a code snippet uses direct display:block spans as line wrappers.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source snippet element.
     * @return bool True when the snippet has display:block line spans.
     */
    private static function has_display_block_code_lines($element): bool
    {
        return \count(self::get_display_block_code_lines($element)) >= 2;
    }
    /**
     * Extracts line contents from direct display:block span wrappers.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source snippet element.
     * @return array Line HTML fragments.
     */
    private static function get_display_block_code_lines($element): array
    {
        $lines = array();
        foreach ($element->get_child_elements() as $child) {
            if ('SPAN' !== $child->get_tag_name()) {
                continue;
            }
            $style = $child->has_attribute('style') ? $child->get_attribute('style') : '';
            if (\preg_match('/(?:^|;)\s*display\s*:\s*block\b/i', (string) $style) !== 1) {
                continue;
            }
            $lines[] = $child->get_inner_html();
        }
        return $lines;
    }
    /**
     * Checks whether display-code markup contains executable/style content.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when this generic transform should not claim it.
     */
    private static function has_unsafe_code_display_markup($element): bool
    {
        return \preg_match('/<\/?(?:script|style)\b/i', $element->get_inner_html()) === 1;
    }
    /**
     * core/separator transforms - hr elements
     *
     * @return array Transform definitions
     */
    private static function get_separator_transforms()
    {
        return array(array('blockName' => 'core/separator', 'priority' => 10, 'selector' => 'hr', 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'HR';
        }, 'transform' => function ($element) {
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
            if ($element->has_attribute('class')) {
                $class = $element->get_attribute('class');
                if (\strpos($class, 'is-style-wide') !== \false) {
                    $attributes['className'] = self::merge_class_names($attributes['className'] ?? '', 'is-style-wide');
                } elseif (\strpos($class, 'is-style-dots') !== \false) {
                    $attributes['className'] = self::merge_class_names($attributes['className'] ?? '', 'is-style-dots');
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/separator', $attributes);
        }), array('blockName' => 'core/separator', 'priority' => 11, 'selector' => 'div,span', 'isMatch' => function ($element) {
            return \in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true) && self::is_empty_element($element) && self::class_matches($element, '/(?:^|[-_\s])(divider|separator|rule|ruler)(?:$|[-_\s]|\d)/i');
        }, 'transform' => function ($element) {
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
            return HTML_To_Blocks_Block_Factory::create_block('core/separator', $attributes);
        }));
    }
    /**
     * core/table transforms - table elements
     *
     * @return array Transform definitions
     */
    private static function get_table_transforms()
    {
        return array(array('blockName' => 'core/table', 'priority' => 10, 'selector' => 'table', 'isMatch' => function ($element) {
            return $element->get_tag_name() === 'TABLE';
        }, 'transform' => function ($element) {
            return self::create_table_block_from_element($element);
        }));
    }
    /**
     * Creates a table block from an HTML element
     *
     * @param HTML_To_Blocks_HTML_Element $table_element The table element
     * @return array Block array
     */
    private static function create_table_block_from_element($table_element)
    {
        $table_html = $table_element->get_outer_html();
        $processor = \WP_HTML_Processor::create_fragment($table_html);
        if (!$processor) {
            return HTML_To_Blocks_Block_Factory::create_block('core/table', array());
        }
        $current_section = 'body';
        $current_row = array();
        $rows_head = array();
        $rows_body = array();
        $rows_foot = array();
        $caption_text = '';
        $html_offset = 0;
        while ($processor->next_tag(array('tag_closers' => 'visit'))) {
            $tag = $processor->get_tag();
            $is_closer = $processor->is_tag_closer();
            if ('THEAD' === $tag && !$is_closer) {
                $current_section = 'head';
            } elseif ('TBODY' === $tag && !$is_closer) {
                $current_section = 'body';
            } elseif ('TFOOT' === $tag && !$is_closer) {
                $current_section = 'foot';
            } elseif ('TR' === $tag && !$is_closer) {
                $current_row = array();
            } elseif ('TR' === $tag) {
                if (!empty($current_row)) {
                    $row_data = array('cells' => $current_row);
                    if ('head' === $current_section) {
                        $rows_head[] = $row_data;
                    } elseif ('foot' === $current_section) {
                        $rows_foot[] = $row_data;
                    } else {
                        $rows_body[] = $row_data;
                    }
                }
                $current_row = array();
            } elseif (('TD' === $tag || 'TH' === $tag) && !$is_closer) {
                $cell_data = array('content' => '', 'tag' => \strtolower($tag));
                if ($processor->get_attribute('colspan')) {
                    $cell_data['colspan'] = (int) $processor->get_attribute('colspan');
                }
                if ($processor->get_attribute('rowspan')) {
                    $cell_data['rowspan'] = (int) $processor->get_attribute('rowspan');
                }
                $inner_html = self::extract_cell_content_at_offset($table_html, $html_offset, $tag);
                $cell_data['content'] = $inner_html;
                $current_row[] = $cell_data;
            } elseif ('CAPTION' === $tag && !$is_closer) {
                $caption_text = self::extract_cell_content_at_offset($table_html, $html_offset, 'CAPTION');
            }
        }
        $attributes = self::get_block_support_attributes($table_element, array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'spacing' => \true, 'border' => \true));
        $attributes = \array_merge($attributes, array('head' => $rows_head, 'body' => $rows_body, 'foot' => $rows_foot));
        if (!empty($caption_text)) {
            $attributes['caption'] = $caption_text;
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/table', $attributes);
    }
    /**
     * Extracts cell content from table HTML using regex
     *
     * @param string $html   Full table HTML
     * @param int    $offset Current offset position (passed by reference)
     * @param string $tag    Tag name (TD, TH, CAPTION)
     * @return string Cell inner HTML
     */
    private static function extract_cell_content_at_offset(string $html, int &$offset, string $tag): string
    {
        $search_html = \substr($html, $offset);
        $tag_lower = \strtolower($tag);
        $pattern = '/<' . \preg_quote($tag_lower, '/') . '(?:\s[^>]*)?>(.*)$/is';
        if (!\preg_match($pattern, $search_html, $matches, \PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $content_start = $matches[1][1];
        $content = $matches[1][0];
        $close_tag = '</' . $tag_lower . '>';
        $close_pos = \stripos($content, $close_tag);
        if (\false !== $close_pos) {
            $inner_html = \substr($content, 0, $close_pos);
            $offset = (int) ($offset + $matches[0][1] + \strlen($matches[0][0]) - \strlen($content) + $close_pos + \strlen($close_tag));
            return \trim($inner_html);
        }
        return '';
    }
    /**
     * Layout transforms - conservative wrappers only.
     *
     * Static placeholder form transforms.
     *
     * Core does not provide a generic form block. When a source form has no real
     * submission target, preserve the visible intake copy as editable native blocks
     * rather than falling back to core/html for inert markup.
     *
     * @return array Transform definitions
     */
    private static function get_form_transforms()
    {
        return array(array('blockName' => 'core/group', 'priority' => 10, 'selector' => 'form', 'isMatch' => function ($element) {
            return self::is_static_placeholder_form($element);
        }, 'transform' => function ($element) {
            return self::create_static_placeholder_form_group($element);
        }));
    }
    /**
     * Determines whether a form is an inert static placeholder.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when the form can safely become editable copy blocks.
     */
    private static function is_static_placeholder_form($element): bool
    {
        if ('FORM' !== $element->get_tag_name()) {
            return \false;
        }
        if (!$element->has_attribute('action')) {
            return \false;
        }
        $action = \trim((string) $element->get_attribute('action'));
        if ('#' !== $action) {
            return \false;
        }
        foreach ($element->get_child_elements() as $child) {
            if (\in_array($child->get_tag_name(), array('LABEL', 'INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'), \true)) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Creates a native group from a static placeholder form.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source form element.
     * @return array Block array.
     */
    private static function create_static_placeholder_form_group($element): array
    {
        $inner_blocks = array();
        foreach ($element->get_child_elements() as $child) {
            $tag = $child->get_tag_name();
            if ('LABEL' === $tag) {
                $content = self::get_static_form_label_text($child);
                if ('' !== $content) {
                    $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', array('content' => esc_html($content)));
                }
                continue;
            }
            if ('BUTTON' === $tag) {
                $content = \trim(wp_strip_all_tags($child->get_inner_html()));
                if ('' !== $content) {
                    $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/buttons', array(), array(HTML_To_Blocks_Block_Factory::create_block('core/button', array('text' => esc_html($content), 'url' => '#'))));
                }
                continue;
            }
            if (\in_array($tag, array('INPUT', 'TEXTAREA', 'SELECT'), \true)) {
                $content = self::get_static_form_control_label($child);
                if ('' !== $content) {
                    $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', array('content' => esc_html($content)));
                }
            }
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
    }
    /**
     * Gets visible text for a static form label.
     *
     * @param HTML_To_Blocks_HTML_Element $label Label element.
     * @return string Label text.
     */
    private static function get_static_form_label_text($label): string
    {
        $text = \trim(wp_strip_all_tags($label->get_inner_html()));
        if ('' !== $text) {
            return \preg_replace('/\s+/', ' ', $text);
        }
        foreach ($label->get_child_elements() as $child) {
            $text = self::get_static_form_control_label($child);
            if ('' !== $text) {
                return $text;
            }
        }
        return '';
    }
    /**
     * Gets the best editable text for an unlabeled static form control.
     *
     * @param HTML_To_Blocks_HTML_Element $control Form control element.
     * @return string Control label text.
     */
    private static function get_static_form_control_label($control): string
    {
        foreach (array('placeholder', 'aria-label', 'name') as $attribute) {
            if ($control->has_attribute($attribute)) {
                $value = \trim((string) $control->get_attribute($attribute));
                if ('' !== $value) {
                    return \preg_replace('/\s+/', ' ', $value);
                }
            }
        }
        return '';
    }
    /**
     * @return array Transform definitions
     */
    private static function get_layout_transforms()
    {
        return array(array('blockName' => 'core/spacer', 'priority' => 11, 'isMatch' => function ($element) {
            return self::is_spacer_element($element);
        }, 'transform' => function ($element) {
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'spacing' => \true));
            $height = self::extract_height_value($element);
            if ('' !== $height) {
                $attributes['height'] = $height;
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/spacer', $attributes);
        }), array('blockName' => 'core/cover', 'priority' => 12, 'isMatch' => function ($element) {
            return self::is_cover_element($element);
        }, 'transform' => function ($element, $handler) {
            $attributes = self::get_common_layout_attributes($element);
            $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
            $inner_blocks = $handler(array('HTML' => $element->get_inner_html()));
            if (\preg_match('/background-image:\s*url\((["\']?)([^)"\']+)\1\)/i', $style, $matches)) {
                $attributes['url'] = \trim($matches[2]);
            }
            $background_color = self::extract_background_color($style);
            if ('' !== $background_color) {
                $attributes['customOverlayColor'] = $background_color;
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/cover', $attributes, $inner_blocks);
        }), array('blockName' => 'core/columns', 'priority' => 13, 'isMatch' => function ($element) {
            return self::is_columns_element($element);
        }, 'transform' => function ($element, $handler) {
            $inner_blocks = array();
            foreach ($element->get_child_elements() as $child) {
                if (!self::is_column_element($child)) {
                    continue;
                }
                $column_attributes = self::get_common_layout_attributes($child);
                $column_blocks = $handler(array('HTML' => $child->get_inner_html()));
                $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/column', $column_attributes, $column_blocks);
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/columns', self::get_common_layout_attributes($element), $inner_blocks);
        }), array('blockName' => 'core/group', 'priority' => 3, 'isMatch' => function ($element) {
            return self::is_repeated_card_grid_element($element);
        }, 'transform' => function ($element, $handler, $args = array()) {
            return self::create_repeated_card_grid_group($element, $handler, $args);
        }), array('blockName' => 'core/column', 'priority' => 14, 'isMatch' => function ($element) {
            return self::is_column_element($element);
        }, 'transform' => function ($element, $handler) {
            return HTML_To_Blocks_Block_Factory::create_block('core/column', self::get_common_layout_attributes($element), $handler(array('HTML' => $element->get_inner_html())));
        }), array('blockName' => 'core/group', 'priority' => 14, 'isMatch' => function ($element) {
            return self::is_image_wrapper_element($element);
        }, 'transform' => function ($element) {
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), array(self::create_image_block_from_img($element->query_selector('img'))));
        }), array('blockName' => 'core/group', 'priority' => 14, 'isMatch' => function ($element) {
            return self::is_nav_logo_chrome_element($element);
        }, 'transform' => function ($element, $handler) {
            $inner_html = $element->get_inner_html();
            foreach ($element->get_child_elements() as $child) {
                if (self::is_empty_decorative_element($child)) {
                    $inner_html = \str_replace($child->get_outer_html(), '', $inner_html);
                }
            }
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $handler(array('HTML' => $inner_html)));
        }), array('blockName' => 'core/group', 'priority' => 14, 'isMatch' => function ($element) {
            return self::is_inline_scroller_element($element);
        }, 'transform' => function ($element, $handler) {
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), self::create_inline_scroller_child_blocks($element, $handler));
        }), array('blockName' => 'core/group', 'priority' => 14, 'isMatch' => function ($element) {
            return self::is_decorative_figure_with_caption($element);
        }, 'transform' => function ($element) {
            $children = $element->get_child_elements();
            $caption = \end($children);
            $caption_attrs = self::get_block_support_attributes($caption, array('class_name' => \true));
            $inner_blocks = array();
            if (\count($children) === 2) {
                $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_empty_decorative_group_attributes($children[0]));
            }
            $caption_attrs['content'] = \trim($caption->get_inner_html());
            $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', $caption_attrs);
            return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
        }), array('blockName' => 'core/group', 'priority' => 15, 'isMatch' => function ($element) {
            return self::is_group_element($element);
        }, 'transform' => function ($element, $handler) {
            $attributes = self::is_empty_decorative_element($element) ? self::get_empty_decorative_group_attributes($element) : self::get_common_layout_attributes($element);
            return HTML_To_Blocks_Block_Factory::create_block('core/group', $attributes, $handler(array('HTML' => $element->get_inner_html())));
        }));
    }
    /**
     * Gets safe attributes for empty decorative group chrome.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return array Block attributes.
     */
    private static function get_empty_decorative_group_attributes($element): array
    {
        return self::get_block_support_attributes($element, array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'dimensions' => \true, 'spacing' => \true, 'border' => \true));
    }
    /**
     * Gets attributes shared by layout blocks.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return array Block attributes.
     */
    private static function get_common_layout_attributes($element): array
    {
        $options = array('anchor' => \true, 'class_name' => \true, 'align' => \true, 'colors' => \true, 'dimensions' => \true, 'typography' => \true, 'spacing' => \true, 'border' => \true, 'layout' => \true, 'tag_name' => $element->get_tag_name() !== 'DIV', 'aria_label' => \true);
        return self::get_block_support_attributes($element, $options);
    }
    /**
     * Extracts direct, mechanical block-support attributes from HTML.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @param array                       $options Enabled support keys.
     * @return array Block attributes.
     */
    private static function get_block_support_attributes($element, array $options = array()): array
    {
        $attributes = array();
        $classes = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
        if (!empty($options['anchor']) && $element->has_attribute('id') && $element->get_attribute('id') !== '') {
            $attributes['anchor'] = $element->get_attribute('id');
        }
        if (!empty($options['align']) && \preg_match('/(?:^|\s)align(wide|full|left|center|right)(?:$|\s)/i', $classes, $matches)) {
            $attributes['align'] = \strtolower($matches[1]);
        }
        if (!empty($options['class_name'])) {
            $class_name = self::safe_block_class_name($classes);
            if ('' !== $class_name) {
                $attributes['className'] = $class_name;
            }
        }
        if (!empty($options['tag_name'])) {
            $tag_name = \strtolower($element->get_tag_name());
            if (\in_array($tag_name, array('section', 'main', 'article', 'aside', 'header', 'footer', 'nav'), \true)) {
                $attributes['tagName'] = $tag_name;
            }
        }
        if (!empty($options['aria_label']) && $element->has_attribute('aria-label') && $element->get_attribute('aria-label') !== '') {
            $attributes['ariaLabel'] = $element->get_attribute('aria-label');
        }
        if (!empty($options['colors'])) {
            self::apply_color_support_attributes($attributes, $style, $classes);
        }
        if (!empty($options['typography'])) {
            self::apply_typography_support_attributes($attributes, $style, $classes);
        }
        if ('' !== $style) {
            if (!empty($options['text_align'])) {
                $text_align = self::extract_css_property($style, 'text-align');
                if (\in_array(\strtolower($text_align), array('left', 'center', 'right'), \true)) {
                    $attributes['textAlign'] = \strtolower($text_align);
                }
            }
            if (!empty($options['spacing'])) {
                self::apply_spacing_support_attributes($attributes, $style);
            }
            if (!empty($options['border'])) {
                self::apply_border_support_attributes($attributes, $style);
            }
            if (!empty($options['dimensions'])) {
                self::apply_dimension_support_attributes($attributes, $style);
            }
        }
        if (!empty($options['layout'])) {
            self::apply_layout_support_attributes($attributes, $classes, $style, $element);
        }
        return $attributes;
    }
    /**
     * Preserves source classes that are safe as block custom classes.
     *
     * @param string $class_name Source class attribute.
     * @return string Safe custom classes.
     */
    private static function safe_block_class_name(string $class_name): string
    {
        $classes = \preg_split('/\s+/', \trim($class_name));
        if (\false === $classes) {
            return '';
        }
        $classes = \array_filter($classes, function ($class_name) {
            return '' !== $class_name && \preg_match('/^[A-Za-z0-9_-]+$/', $class_name) === 1 && \preg_match('/^align(?:wide|full|left|center|right)$/i', $class_name) !== 1 && \preg_match('/^has-(?:[A-Za-z0-9_-]+-(?:color|background-color|font-size)|text-color|background|custom-font-size)$/i', $class_name) !== 1 && \preg_match('/^is-(?:layout-(?:flow|constrained|flex)|vertical|horizontal|nowrap|content-justification-[A-Za-z0-9_-]+)$/i', $class_name) !== 1 && \stripos($class_name, 'wp-block-') !== 0;
        });
        return \implode(' ', \array_values(\array_unique($classes)));
    }
    /**
     * Merges two class-name strings without duplicates.
     *
     * @param string $base Base classes.
     * @param string $extra Extra classes.
     * @return string Merged classes.
     */
    private static function merge_class_names(string $base, string $extra): string
    {
        return self::safe_block_class_name(\trim($base . ' ' . $extra));
    }
    /**
     * Applies direct color declarations and explicit WordPress color preset classes.
     *
     * @param array  $attributes Block attributes.
     * @param string $style Source style attribute.
     * @param string $classes Source class attribute.
     */
    private static function apply_color_support_attributes(array &$attributes, string $style, string $classes = ''): void
    {
        $text_color = self::extract_preset_class_slug($classes, 'color');
        if ('' !== $text_color) {
            $attributes['textColor'] = $text_color;
        }
        $background_color = self::extract_preset_class_slug($classes, 'background-color');
        if ('' !== $background_color) {
            $attributes['backgroundColor'] = $background_color;
        }
        $color = self::extract_css_property($style, 'color');
        if ('' !== $color) {
            $attributes['style']['color']['text'] = $color;
        }
        $background = self::extract_background_color($style);
        if ('' !== $background) {
            if (\stripos($background, 'gradient(') !== \false) {
                $attributes['style']['color']['gradient'] = $background;
            } else {
                $attributes['style']['color']['background'] = $background;
            }
        }
    }
    /**
     * Applies explicit WordPress typography preset classes/vars.
     *
     * @param array  $attributes Block attributes.
     * @param string $style Source style attribute.
     * @param string $classes Source class attribute.
     */
    private static function apply_typography_support_attributes(array &$attributes, string $style, string $classes = ''): void
    {
        $font_size = self::extract_preset_class_slug($classes, 'font-size');
        if ('' !== $font_size) {
            $attributes['fontSize'] = $font_size;
            return;
        }
        $font_size_value = self::extract_css_property($style, 'font-size');
        $font_size_token = self::normalise_wp_preset_var($font_size_value, 'font-size');
        if ('' !== $font_size_token) {
            $attributes['fontSize'] = $font_size_token;
        }
    }
    /**
     * Applies direct margin/padding declarations to block support attributes.
     *
     * @param array  $attributes Block attributes.
     * @param string $style Source style attribute.
     */
    private static function apply_spacing_support_attributes(array &$attributes, string $style): void
    {
        foreach (array('margin', 'padding') as $kind) {
            $value = self::extract_css_property($style, $kind);
            $side_values = array();
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                $side_value = self::extract_css_property($style, $kind . '-' . $side);
                if ('' !== $side_value) {
                    $side_values[$side] = $side_value;
                }
            }
            if (!empty($side_values)) {
                foreach ($side_values as $side => $side_value) {
                    $side_values[$side] = self::normalise_wp_preset_var($side_value, 'spacing') ? self::normalise_wp_preset_var($side_value, 'spacing') : $side_value;
                }
                $attributes['style']['spacing'][$kind] = $side_values;
                continue;
            }
            if ('' !== $value) {
                $attributes['style']['spacing'][$kind] = self::normalise_wp_preset_var($value, 'spacing') ? self::normalise_wp_preset_var($value, 'spacing') : $value;
            }
        }
    }
    /**
     * Applies explicit WordPress layout classes emitted by block supports.
     *
     * @param array  $attributes Block attributes.
     * @param string $classes Source class attribute.
     */
    private static function apply_layout_support_attributes(array &$attributes, string $classes, string $style = '', $element = null): void
    {
        if (\preg_match('/(?:^|\s)is-layout-(flow|constrained|flex)(?:\s|$)/i', $classes, $matches)) {
            $type = \strtolower($matches[1]);
            $attributes['layout']['type'] = 'flow' === $type ? 'default' : $type;
        }
        if (\strtolower(self::extract_css_property($style, 'display')) === 'flex') {
            $attributes['layout']['type'] = 'flex';
        }
        if (\preg_match('/(?:^|\s)is-(vertical|horizontal)(?:\s|$)/i', $classes, $matches)) {
            $attributes['layout']['orientation'] = \strtolower($matches[1]);
        }
        $flex_direction = \strtolower(self::extract_css_property($style, 'flex-direction'));
        if (\in_array($flex_direction, array('column', 'column-reverse'), \true)) {
            $attributes['layout']['orientation'] = 'vertical';
        } elseif (\in_array($flex_direction, array('row', 'row-reverse'), \true)) {
            $attributes['layout']['orientation'] = 'horizontal';
        }
        if (\preg_match('/(?:^|\s)is-content-justification-(left|right|center|space-between)(?:\s|$)/i', $classes, $matches)) {
            $attributes['layout']['justifyContent'] = \strtolower($matches[1]);
        }
        $justify_content = \strtolower(self::extract_css_property($style, 'justify-content'));
        if (\in_array($justify_content, array('left', 'right', 'center', 'space-between'), \true)) {
            $attributes['layout']['justifyContent'] = $justify_content;
        }
        $align_items = \strtolower(self::extract_css_property($style, 'align-items'));
        if (\in_array($align_items, array('left', 'right', 'center'), \true)) {
            $attributes['layout']['verticalAlignment'] = $align_items;
        }
        if (\preg_match('/(?:^|\s)is-nowrap(?:\s|$)/i', $classes) || \strtolower(self::extract_css_property($style, 'flex-wrap')) === 'nowrap') {
            $attributes['layout']['flexWrap'] = 'nowrap';
        }
    }
    /**
     * Applies direct dimension declarations to block support attributes.
     *
     * @param array  $attributes Block attributes.
     * @param string $style Source style attribute.
     */
    private static function apply_dimension_support_attributes(array &$attributes, string $style): void
    {
        $min_height = self::extract_css_property($style, 'min-height');
        if ('' !== $min_height) {
            $attributes['style']['dimensions']['minHeight'] = $min_height;
        }
        $width = self::extract_css_property($style, 'width');
        if ('' !== $width && self::is_safe_dimension_value($width)) {
            $attributes['style']['dimensions']['width'] = $width;
        }
    }
    /**
     * Checks whether a CSS dimension value is safe to preserve mechanically.
     *
     * @param string $value CSS dimension value.
     * @return bool True when the value contains no executable or external payload.
     */
    private static function is_safe_dimension_value(string $value): bool
    {
        $value = \trim($value);
        if ('' === $value || \strlen($value) > 80) {
            return \false;
        }
        if (\preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:)/i', $value)) {
            return \false;
        }
        return \preg_match('/^[0-9.]+(?:px|em|rem|%|vw|vh|vmin|vmax|ch|ex)?$/i', $value) === 1 || \preg_match('/^calc\(\s*[0-9.]+(?:px|em|rem|%|vw|vh|vmin|vmax|ch|ex)?\s*[-+]\s*[0-9.]+(?:px|em|rem|%|vw|vh|vmin|vmax|ch|ex)?\s*\)$/i', $value) === 1;
    }
    /**
     * Checks whether a section is a high-confidence full-bleed hero wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when a default flow group would lose hero centering intent.
     */
    private static function is_hero_like_section($element): bool
    {
        if ($element->get_tag_name() !== 'SECTION' || !$element->has_attribute('class')) {
            return \false;
        }
        return self::class_matches($element, '/(?:^|[-_\s])(?:hero|cover|banner|masthead)(?:$|[-_\s])/i');
    }
    /**
     * Applies direct border declarations to block support attributes.
     *
     * @param array  $attributes Block attributes.
     * @param string $style Source style attribute.
     */
    private static function apply_border_support_attributes(array &$attributes, string $style): void
    {
        $border_parts = array();
        $border = self::extract_css_property($style, 'border');
        if ('' !== $border) {
            $border_parts = self::parse_border_shorthand($border);
        }
        $color = self::extract_css_property($style, 'border-color');
        if ('' !== $color && self::is_safe_border_color($color)) {
            $border_parts['color'] = $color;
        }
        $border_style = self::extract_css_property($style, 'border-style');
        if ('' !== $border_style && self::is_safe_border_style($border_style)) {
            $border_parts['style'] = \strtolower($border_style);
        }
        $width = self::extract_css_property($style, 'border-width');
        if ('' !== $width && self::is_safe_border_width($width)) {
            $border_parts['width'] = $width;
        }
        $radius = self::extract_css_property($style, 'border-radius');
        if ('' !== $radius && self::is_safe_border_radius($radius)) {
            $border_parts['radius'] = $radius;
        }
        if (!empty($border_parts)) {
            $attributes['style']['border'] = $border_parts;
        }
    }
    /**
     * Parses an editor-safe `border` shorthand into block support parts.
     *
     * @param string $value CSS border shorthand value.
     * @return array Border support parts.
     */
    private static function parse_border_shorthand(string $value): array
    {
        $parts = array();
        foreach (self::split_css_value_tokens($value) as $token) {
            $lower_token = \strtolower($token);
            if (empty($parts['width']) && self::is_safe_border_width($token)) {
                $parts['width'] = $token;
                continue;
            }
            if (empty($parts['style']) && self::is_safe_border_style($lower_token)) {
                $parts['style'] = $lower_token;
                continue;
            }
            if (empty($parts['color']) && self::is_safe_border_color($token)) {
                $parts['color'] = $token;
            }
        }
        return $parts;
    }
    /**
     * Splits CSS value tokens without breaking function arguments.
     *
     * @param string $value CSS value.
     * @return array Tokens.
     */
    private static function split_css_value_tokens(string $value): array
    {
        $tokens = array();
        $token = '';
        $depth = 0;
        $length = \strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char && $depth > 0) {
                --$depth;
            }
            if (\ctype_space($char) && 0 === $depth) {
                if ('' !== $token) {
                    $tokens[] = $token;
                    $token = '';
                }
                continue;
            }
            $token .= $char;
        }
        if ('' !== $token) {
            $tokens[] = $token;
        }
        return $tokens;
    }
    /**
     * Checks whether a border width is safe and editor-valid.
     *
     * @param string $value CSS border width.
     * @return bool True when safe.
     */
    private static function is_safe_border_width(string $value): bool
    {
        $value = \trim($value);
        if ('' === $value || \preg_match('/\s/', $value)) {
            return \false;
        }
        return \in_array(\strtolower($value), array('thin', 'medium', 'thick'), \true) || \preg_match('/^(?:0|[0-9.]+(?:px|em|rem|%|vw|vh|vmin|vmax))$/i', $value) === 1;
    }
    /**
     * Checks whether a border style is safe and editor-valid.
     *
     * @param string $value CSS border style.
     * @return bool True when safe.
     */
    private static function is_safe_border_style(string $value): bool
    {
        return \in_array(\strtolower(\trim($value)), array('none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'), \true);
    }
    /**
     * Checks whether a border color is safe to preserve mechanically.
     *
     * @param string $value CSS border color.
     * @return bool True when safe.
     */
    private static function is_safe_border_color(string $value): bool
    {
        $value = \trim($value);
        if ('' === $value || \strlen($value) > 100 || \preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:)/i', $value)) {
            return \false;
        }
        return \preg_match('/^(?:#[0-9a-f]{3,8}|[a-z]+|rgba?\([^()]+\)|hsla?\([^()]+\)|var\(\s*--[A-Za-z0-9_-]+\s*\))$/i', $value) === 1;
    }
    /**
     * Checks whether a border radius is safe to preserve mechanically.
     *
     * @param string $value CSS border radius.
     * @return bool True when safe.
     */
    private static function is_safe_border_radius(string $value): bool
    {
        $tokens = self::split_css_value_tokens(\trim($value));
        if (empty($tokens) || \count($tokens) > 4) {
            return \false;
        }
        foreach ($tokens as $token) {
            if (!self::is_safe_border_width($token)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Extracts one CSS declaration value from a style attribute.
     *
     * @param string $style CSS style attribute.
     * @param string $name CSS property name.
     * @return string CSS value or empty string.
     */
    private static function extract_css_property(string $style, string $name): string
    {
        $pattern = '/(?:^|;)\s*' . \preg_quote($name, '/') . '\s*:\s*([^;]+)/i';
        return \preg_match($pattern, $style, $matches) ? \trim($matches[1]) : '';
    }
    /**
     * Extracts a WordPress preset slug from generated block-support classes.
     *
     * @param string $classes Source class attribute.
     * @param string $kind Preset class kind: color, background-color, or font-size.
     * @return string Preset slug or empty string.
     */
    private static function extract_preset_class_slug(string $classes, string $kind): string
    {
        $pattern = 'background-color' === $kind ? '/(?:^|\s)has-([A-Za-z0-9_-]+)-background-color(?:\s|$)/i' : '/(?:^|\s)has-([A-Za-z0-9_-]+)-' . \preg_quote($kind, '/') . '(?:\s|$)/i';
        if (!\preg_match($pattern, $classes, $matches)) {
            return '';
        }
        $slug = \strtolower($matches[1]);
        return \in_array($slug, array('text', 'background', 'custom'), \true) ? '' : $slug;
    }
    /**
     * Converts explicit WordPress preset CSS vars to block attribute token syntax.
     *
     * @param string $value CSS value.
     * @param string $kind Preset kind, such as spacing or font-size.
     * @return string Block preset token or empty string.
     */
    private static function normalise_wp_preset_var(string $value, string $kind): string
    {
        $pattern = '/^var\(\s*--wp--preset--' . \preg_quote($kind, '/') . '--([A-Za-z0-9_-]+)\s*\)$/i';
        return \preg_match($pattern, \trim($value), $matches) ? 'var:preset|' . $kind . '|' . \strtolower($matches[1]) : '';
    }
    /**
     * Checks whether an element is a safe group wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element should become core/group.
     */
    private static function is_group_element($element): bool
    {
        $tag = $element->get_tag_name();
        if ('SECTION' === $tag) {
            return \true;
        }
        if (\in_array($tag, array('MAIN', 'ARTICLE', 'ASIDE', 'HEADER', 'FOOTER', 'NAV'), \true)) {
            return \true;
        }
        if ('SPAN' === $tag) {
            return self::is_empty_decorative_element($element);
        }
        if ('DIV' !== $tag) {
            return \false;
        }
        if (self::is_project_card_status_element($element) && !self::is_empty_element($element)) {
            return \false;
        }
        if (\trim($element->get_text_content()) !== '' && \trim($element->get_inner_html()) === \trim(wp_strip_all_tags($element->get_inner_html()))) {
            return \false;
        }
        if (self::class_matches($element, '/(?:^|[-_\s])quote[-_\s]+(?:attr|attribution)(?:$|[-_\s])/i')) {
            return \true;
        }
        if (self::class_matches($element, '/(?:^|[-_\s])(actions?|buttons?|cta|group|section|container|wrapper|wrap|content|main|page|article|aside|header|footer|inner|row|grid|card|product|compare|feature|visual|text|pin|location|address|detail|chrome|scroll|thumb|stars?|rating|info|demo|panel|arrow)(?:$|[-_\s])/i')) {
            return \true;
        }
        if (self::class_matches($element, '/(?:^|[-_\s])(code|code[-_\s]?window)(?:$|[-_\s])/i')) {
            return \false;
        }
        if ($element->has_attribute('data-widget')) {
            return \false;
        }
        if (array() !== $element->get_child_elements()) {
            return \true;
        }
        return self::is_empty_decorative_element($element);
    }
    /**
     * Checks whether a wrapper is a repeated static card grid.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when direct children should become editable card groups.
     */
    private static function is_repeated_card_grid_element($element): bool
    {
        $cache_key = \spl_object_id($element);
        unset(self::$repeated_card_grid_children[$cache_key]);
        if (!\in_array($element->get_tag_name(), array('DIV', 'SECTION'), \true)) {
            return \false;
        }
        if (!self::class_matches($element, '/(?:^|[-_\s])(?:grid|cards?|network|list)(?:$|[-_\s]|\d)/i')) {
            return \false;
        }
        $cards = array();
        foreach ($element->get_child_elements() as $child) {
            if (self::is_empty_decorative_element($child)) {
                continue;
            }
            if (!self::is_card_grid_item_element($child)) {
                return \false;
            }
            $cards[] = $child;
        }
        if (\count($cards) < 2) {
            return \false;
        }
        self::$repeated_card_grid_children[$cache_key] = $cards;
        return \true;
    }
    /**
     * Checks whether an element is one repeated card/grid item.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element is safe to convert as a card group.
     */
    private static function is_card_grid_item_element($element): bool
    {
        if (\in_array($element->get_tag_name(), array('ARTICLE', 'ASIDE'), \true)) {
            return \true;
        }
        if ('A' === $element->get_tag_name() && array() !== $element->get_child_elements()) {
            return \true;
        }
        if (!\in_array($element->get_tag_name(), array('DIV', 'SECTION'), \true)) {
            return \false;
        }
        return self::class_matches($element, '/(?:^|[-_\s])(?:cards?|card|col|column|item|tile|entry|post|network|site|feature)(?:$|[-_\s]|\d)/i');
    }
    /**
     * Creates an editable group hierarchy for repeated static card grids.
     *
     * @param HTML_To_Blocks_HTML_Element $element The grid wrapper.
     * @param callable                    $handler Recursive raw handler.
     * @return array Block array.
     */
    private static function create_repeated_card_grid_group($element, callable $handler, array $args = array()): array
    {
        $inner_blocks = array();
        $cache_key = \spl_object_id($element);
        $children = self::$repeated_card_grid_children[$cache_key] ?? $element->get_child_elements();
        unset(self::$repeated_card_grid_children[$cache_key]);
        $diagnostic = self::get_commerce_product_grid_diagnostic($element, $args);
        if (null !== $diagnostic && \function_exists('do_action')) {
            \do_action('html_to_blocks_commerce_product_grid_detected', $diagnostic, $element, $args);
        }
        foreach ($children as $child) {
            $inner_blocks[] = self::create_card_grid_item_group($child, $handler);
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
    }
    /**
     * Builds a diagnostic payload for explicit commerce-context product grids.
     *
     * @param HTML_To_Blocks_HTML_Element $element The grid wrapper.
     * @param array                       $args Raw handler arguments.
     * @return array|null Diagnostic payload, or null when the commerce gate is closed.
     */
    private static function get_commerce_product_grid_diagnostic($element, array $args): ?array
    {
        if (!self::has_explicit_commerce_context($args) || !self::is_product_grid_element($element)) {
            return null;
        }
        $products = array();
        foreach ($element->get_child_elements() as $child) {
            if (self::is_empty_decorative_element($child)) {
                continue;
            }
            $products[] = \array_filter(array('slug' => $child->get_attribute('data-product-slug') ?? '', 'name' => self::get_first_text_for_selectors($child, array('.product-card__name', '.ss-product-name', 'h1', 'h2', 'h3', 'h4')), 'price' => self::get_first_text_for_selectors($child, array('.product-card__price', '.ss-product-price')), 'category' => self::get_first_text_for_selectors($child, array('.product-card__category', '.ss-product-category')), 'badge' => self::get_first_text_for_selectors($child, array('.product-card__badge', '.ss-product-badge')), 'cta' => self::get_first_text_for_selectors($child, array('.product-card__cta', '.ss-product-cta', 'a')), 'has_image' => null !== $child->query_selector('img'), 'placeholder' => null !== $child->query_selector('.product-card__image-placeholder')), function ($value) {
                return !\is_string($value) || '' !== $value;
            });
        }
        return array('type' => 'commerce_product_grid', 'status' => 'static_editable_placeholder', 'product_count' => \count($products), 'products' => $products, 'message' => 'Explicit commerce context detected; h2bc preserved editor-valid static product cards for downstream materialization.');
    }
    /**
     * Checks whether raw-handler args explicitly opt into commerce-aware handling.
     *
     * @param array $args Raw handler arguments.
     * @return bool True when explicit commerce context is present.
     */
    private static function has_explicit_commerce_context(array $args): bool
    {
        $context = $args['context'] ?? array();
        if (!\is_array($context)) {
            return \false;
        }
        return !empty($context['commerce']) || !empty($context['products']) || !empty($context['product_context']);
    }
    /**
     * Checks whether a repeated card grid carries high-confidence product-grid markers.
     *
     * @param HTML_To_Blocks_HTML_Element $element The grid wrapper.
     * @return bool True when the grid is product-like.
     */
    private static function is_product_grid_element($element): bool
    {
        return self::class_matches($element, '/(?:^|[-_\s])products?(?:$|[-_\s])/i') || self::class_matches($element, '/(?:^|[-_\s])shop(?:$|[-_\s])/i') || 'product-grid' === ($element->get_attribute('data-commerce-region') ?? '');
    }
    /**
     * Gets the first non-empty text from a simple selector list.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @param array                       $selectors Simple selectors to inspect.
     * @return string Text content or empty string.
     */
    private static function get_first_text_for_selectors($element, array $selectors): string
    {
        foreach ($selectors as $selector) {
            $match = $element->query_selector($selector);
            if ($match && \trim($match->get_text_content()) !== '') {
                return \trim($match->get_text_content());
            }
        }
        return '';
    }
    /**
     * Creates one editable card group, preserving whole-card links as CTA buttons.
     *
     * @param HTML_To_Blocks_HTML_Element $element The card item.
     * @param callable                    $handler Recursive raw handler.
     * @return array Block array.
     */
    private static function create_card_grid_item_group($element, callable $handler): array
    {
        $children = $element->get_child_elements();
        $link_element = 'A' === $element->get_tag_name() ? $element : self::get_single_complex_card_anchor_child($element, $children);
        $card_element = $link_element ? $link_element : $element;
        $card_children = $link_element ? null : $children;
        $inner_blocks = self::create_card_grid_item_inner_blocks($card_element, $card_children);
        if (null === $inner_blocks) {
            $content_html = $link_element ? $link_element->get_inner_html() : $element->get_inner_html();
            $inner_blocks = $handler(array('HTML' => $content_html));
        }
        if ($link_element && $link_element->has_attribute('href')) {
            $inner_blocks[] = self::create_button_block_from_anchor(self::create_card_grid_cta_anchor($link_element));
        }
        return HTML_To_Blocks_Block_Factory::create_block('core/group', self::get_common_layout_attributes($element), $inner_blocks);
    }
    /**
     * Convert common repeated-card children without re-entering the full raw handler.
     *
     * This keeps repeated card grids on the WordPress HTML API path while avoiding a
     * fresh full transform pass for every card in large static exports.
     *
     * @param HTML_To_Blocks_HTML_Element $element Card content element.
     * @return array<int,array<string,mixed>>|null Blocks, or null when the shape needs the generic handler.
     */
    private static function create_card_grid_item_inner_blocks($element, ?array $children = null): ?array
    {
        $blocks = array();
        foreach ($children ?? $element->get_child_elements() as $child) {
            $block = self::create_card_grid_child_block($child);
            if (null === $block) {
                return null;
            }
            if (!empty($block)) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }
    /**
     * Convert one common card child to a native block.
     *
     * @param HTML_To_Blocks_HTML_Element $child Card child element.
     * @return array<string,mixed>|array{}|null Block, empty array for ignored decorative children, or null for fallback.
     */
    private static function create_card_grid_child_block($child): ?array
    {
        if (self::is_empty_decorative_element($child)) {
            return array();
        }
        $tag = $child->get_tag_name();
        if (\preg_match('/^H[1-6]$/', $tag)) {
            return HTML_To_Blocks_Block_Factory::create_block('core/heading', \array_merge(self::get_block_support_attributes($child, array('anchor' => \true, 'class_name' => \true)), array('level' => (int) \substr($tag, 1), 'content' => $child->get_inner_html())));
        }
        if ('IMG' === $tag) {
            return self::create_image_block_from_img($child);
        }
        if (\in_array($tag, array('OL', 'UL'), \true)) {
            return self::create_list_block_from_element($child);
        }
        if ('P' === $tag || 'SPAN' === $tag) {
            return HTML_To_Blocks_Block_Factory::create_block('core/paragraph', \array_merge(self::get_block_support_attributes($child, array('anchor' => \true, 'class_name' => \true)), array('content' => $child->get_inner_html())));
        }
        if ('A' === $tag && self::is_button_like_anchor($child)) {
            return HTML_To_Blocks_Block_Factory::create_block('core/buttons', array(), array(self::create_button_block_from_anchor($child)));
        }
        return null;
    }
    /**
     * Gets a single whole-card anchor child when it is the card's only content.
     *
     * @param HTML_To_Blocks_HTML_Element $element The card item.
     * @return HTML_To_Blocks_HTML_Element|null Anchor child or null.
     */
    private static function get_single_complex_card_anchor_child($element, ?array $children = null): ?HTML_To_Blocks_HTML_Element
    {
        $children = \array_values(\array_filter($children ?? $element->get_child_elements(), function ($child) {
            return !self::is_empty_decorative_element($child);
        }));
        if (\count($children) !== 1 || 'A' !== $children[0]->get_tag_name() || array() === $children[0]->get_child_elements()) {
            return null;
        }
        $remaining = \str_replace($children[0]->get_outer_html(), '', $element->get_inner_html());
        return \trim(wp_strip_all_tags($remaining)) === '' ? $children[0] : null;
    }
    /**
     * Creates a compact CTA anchor from a linked card wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor The source card anchor.
     * @return HTML_To_Blocks_HTML_Element Synthetic CTA anchor.
     */
    private static function create_card_grid_cta_anchor($anchor): HTML_To_Blocks_HTML_Element
    {
        $text = self::get_card_grid_link_label($anchor);
        $attrs = array('href' => $anchor->get_attribute('href') ?? '', 'class' => self::merge_class_names($anchor->get_attribute('class') ?? '', 'card-grid-link'));
        foreach (array('target', 'rel') as $attribute) {
            if ($anchor->has_attribute($attribute)) {
                $attrs[$attribute] = $anchor->get_attribute($attribute);
            }
        }
        return new HTML_To_Blocks_HTML_Element('a', $attrs, '', esc_html($text));
    }
    /**
     * Gets a stable link label for a whole-card CTA.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor The source card anchor.
     * @return string Link label.
     */
    private static function get_card_grid_link_label($anchor): string
    {
        foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $selector) {
            $heading = $anchor->query_selector($selector);
            if ($heading && \trim($heading->get_text_content()) !== '') {
                return \trim($heading->get_text_content());
            }
        }
        $text = \trim($anchor->get_text_content());
        return '' !== $text ? $text : 'Read more';
    }
    /**
     * Checks whether an element is an image-only wrapper that should stay grouped.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the wrapper should become core/group with core/image.
     */
    private static function is_image_wrapper_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        $images = $element->query_selector_all('img');
        if (\count($images) !== 1 || self::get_media_src($images[0]) === '') {
            return \false;
        }
        $remaining = \str_replace($images[0]->get_outer_html(), '', $element->get_inner_html());
        return \trim(wp_strip_all_tags($remaining)) === '';
    }
    /**
     * Checks whether an element is nav/logo chrome with removable visual-only children.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when decorative child placeholders should be ignored.
     */
    private static function is_nav_logo_chrome_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        if (!self::class_matches($element, '/(?:^|[-_\s])(?:nav[-_\s]?logo|logo|brand)(?:$|[-_\s])/i')) {
            return \false;
        }
        $has_decorative_child = \false;
        foreach ($element->get_child_elements() as $child) {
            if (self::is_empty_decorative_element($child)) {
                $has_decorative_child = \true;
                continue;
            }
            if (!\in_array($child->get_tag_name(), array('A', 'P', 'SPAN', 'STRONG', 'B', 'EM', 'I'), \true)) {
                return \false;
            }
        }
        return $has_decorative_child && '' !== \trim(wp_strip_all_tags($element->get_inner_html()));
    }
    /**
     * Checks whether a wrapper is a horizontal inline scroller/marquee track.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when direct inline children should stay separate blocks.
     */
    private static function is_inline_scroller_element($element): bool
    {
        if ('DIV' !== $element->get_tag_name() || !self::class_matches($element, '/(?:^|[-_\s])(?:marquee|scroller|scroll|ticker|track)(?:$|[-_\s])/i')) {
            return \false;
        }
        $children = $element->get_child_elements();
        if (\count($children) < 2) {
            return \false;
        }
        foreach ($children as $child) {
            if ('SPAN' !== $child->get_tag_name() || !$child->has_attribute('class') || \trim($child->get_text_content()) === '') {
                return \false;
            }
            foreach ($child->get_child_elements() as $grandchild) {
                if (!self::is_empty_decorative_element($grandchild)) {
                    return \false;
                }
            }
        }
        return \true;
    }
    /**
     * Creates child blocks for direct inline marquee/scroller items.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source scroller wrapper.
     * @param callable                    $handler Recursive conversion handler.
     * @return array Converted child blocks.
     */
    private static function create_inline_scroller_child_blocks($element, callable $handler): array
    {
        $inner_blocks = array();
        foreach ($element->get_child_elements() as $child) {
            if (array() !== $child->get_child_elements()) {
                $inner_blocks = \array_merge($inner_blocks, $handler(array('HTML' => $child->get_outer_html())));
                continue;
            }
            $attributes = self::get_block_support_attributes($child, array('anchor' => \true, 'class_name' => \true));
            $attributes['content'] = $child->get_inner_html();
            $inner_blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', $attributes);
        }
        return $inner_blocks;
    }
    /**
     * Checks whether an empty element is safe to preserve as native visual chrome.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element is empty decorative layout chrome.
     */
    private static function is_empty_decorative_element($element): bool
    {
        return self::is_empty_element($element) && (self::is_project_card_status_element($element) || self::class_matches($element, '/(?:^|[-_\s])(background|bg|pattern|texture|divider|separator|connector|rule|ruler|line|blank|overlay|grain|noise|glow|gradient|scan|dot|mark|bullet|icon|orb|blob|fill|img|image|media|photo|picture|thumb|progress|meter|gauge|today|traffic[-_]?light|tl[-_]?(?:red|yellow|green)|task[-_\s]?check)(?:$|[-_\s]|\d)/i') || self::has_visual_placeholder_background($element));
    }
    /**
     * Checks whether a figure wraps a decorative visual placeholder and caption.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when the figure can become an editable group with caption.
     */
    private static function is_decorative_figure_with_caption($element): bool
    {
        if ('FIGURE' !== $element->get_tag_name()) {
            return \false;
        }
        $children = $element->get_child_elements();
        if (\count($children) < 1 || \count($children) > 2) {
            return \false;
        }
        $caption = \end($children);
        if ('FIGCAPTION' !== $caption->get_tag_name() || \trim(wp_strip_all_tags($caption->get_inner_html())) === '') {
            return \false;
        }
        return \count($children) === 1 || self::is_empty_decorative_element($children[0]);
    }
    /**
     * Checks whether an empty element carries visual background styling.
     *
     * @param HTML_To_Blocks_HTML_Element $element Source element.
     * @return bool True when source style provides a native block background.
     */
    private static function has_visual_placeholder_background($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
        if ('' === $style || \preg_match('/url\s*\(/i', $style) === 1) {
            return \false;
        }
        return self::extract_background_color($style) !== '';
    }
    /**
     * Checks whether an element is a project-card status chip/dot.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the classes match the project-card status pattern.
     */
    private static function is_project_card_status_element($element): bool
    {
        return 'DIV' === $element->get_tag_name() && self::class_matches($element, '/(?:^|\s)pcard-status(?:\s|$)/i') && self::class_matches($element, '/(?:^|\s)status-(?:done|active|warn|idle)(?:\s|$)/i');
    }
    /**
     * Checks whether an element has no meaningful text or child elements.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element is empty layout chrome.
     */
    private static function is_empty_element($element): bool
    {
        return \trim(wp_strip_all_tags($element->get_inner_html())) === '' && array() === $element->get_child_elements();
    }
    /**
     * Checks whether an element is a cover/hero wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element should become core/cover.
     */
    private static function is_cover_element($element): bool
    {
        $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
        if ('' === $style) {
            return \false;
        }
        $has_background_image = \preg_match('/background-image:\s*url\(/i', $style) === 1;
        $has_background_color = self::extract_background_color($style) !== '';
        $is_hero_like = self::class_matches($element, '/(?:^|[-_\s])(hero|cover|banner|masthead)(?:$|[-_\s])/i');
        if (!$has_background_image && !$has_background_color) {
            return \false;
        }
        return $is_hero_like || $has_background_image && $element->get_tag_name() === 'SECTION';
    }
    /**
     * Checks whether an element is a columns wrapper.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element should become core/columns.
     */
    private static function is_columns_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SECTION'), \true)) {
            return \false;
        }
        if (!self::class_matches($element, '/(?:^|[-_\s])(columns|row|grid|flex)(?:$|[-_\s])/i')) {
            return \false;
        }
        $column_count = 0;
        foreach ($element->get_child_elements() as $child) {
            if (!self::is_column_element($child)) {
                return \false;
            }
            ++$column_count;
        }
        return $column_count >= 2;
    }
    /**
     * Checks whether an element is an individual column.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element should become core/column.
     */
    private static function is_column_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SECTION', 'ARTICLE', 'ASIDE'), \true)) {
            return \false;
        }
        return self::class_matches($element, '/(?:^|[-_\s])(column|col|cell)(?:$|[-_\s]|\d)/i');
    }
    /**
     * Checks whether an element is an empty explicit spacer.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the element should become core/spacer.
     */
    private static function is_spacer_element($element): bool
    {
        if (!\in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true)) {
            return \false;
        }
        if (\trim(wp_strip_all_tags($element->get_inner_html())) !== '') {
            return \false;
        }
        return self::class_matches($element, '/(?:^|[-_\s])(spacer|gap|separator-space)(?:$|[-_\s])/i') && self::extract_height_value($element) !== '';
    }
    /**
     * Checks a source element class attribute against a pattern.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @param string                      $pattern Regex pattern.
     * @return bool True when the class matches.
     */
    private static function class_matches($element, string $pattern): bool
    {
        $class_name = $element->has_attribute('class') ? $element->get_attribute('class') : '';
        return '' !== $class_name && \preg_match($pattern, $class_name) === 1;
    }
    /**
     * Extracts an explicit height CSS value.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return string CSS height value or empty string.
     */
    private static function extract_height_value($element): string
    {
        $style = $element->has_attribute('style') ? $element->get_attribute('style') : '';
        if (\preg_match('/(?:^|;)\s*height:\s*([^;]+)/i', $style, $matches)) {
            return \trim($matches[1]);
        }
        return '';
    }
    /**
     * Extracts a background-color CSS value.
     *
     * @param string $style CSS style attribute.
     * @return string CSS color value or empty string.
     */
    private static function extract_background_color(string $style): string
    {
        if (\preg_match('/(?:^|;)\s*background(?:-color)?:\s*(?![^;]*url\()([^;]+)/i', $style, $matches)) {
            return \trim($matches[1]);
        }
        return '';
    }
    /**
     * core/paragraph transforms - p/address/a elements, visual labels, and text-only wrappers (lowest priority, fallback)
     *
     * @return array Transform definitions
     */
    private static function get_paragraph_transforms()
    {
        return array(array('blockName' => 'core/paragraph', 'priority' => 20, 'selector' => 'p,address,a,label,div,span', 'isMatch' => function ($element) {
            if (\in_array($element->get_tag_name(), array('P', 'ADDRESS', 'A'), \true)) {
                return \true;
            }
            if (self::is_static_visual_label($element)) {
                return \true;
            }
            return \in_array($element->get_tag_name(), array('DIV', 'SPAN'), \true) && array() === $element->get_child_elements() && \trim($element->get_text_content()) !== '';
        }, 'transform' => function ($element) {
            $content = $element->get_tag_name() === 'A' ? self::get_paragraph_anchor_content($element) : $element->get_inner_html();
            if (self::is_static_checkbox_label($element)) {
                $content = \trim(\preg_replace('/<\s*input\b[^>]*>/i', '', $content));
            }
            $attributes = self::get_block_support_attributes($element, array('anchor' => \true, 'align' => \true, 'text_align' => \true, 'colors' => \true, 'typography' => \true, 'spacing' => \true, 'border' => \true, 'class_name' => \true));
            if ($element->get_tag_name() === 'A') {
                unset($attributes['className']);
            }
            $attributes['content'] = $content;
            return HTML_To_Blocks_Block_Factory::create_block('core/paragraph', $attributes);
        }));
    }
    /**
     * Gets paragraph content for a standalone anchor element.
     *
     * @param HTML_To_Blocks_HTML_Element $anchor Anchor element.
     * @return string Paragraph content with link-owned attributes preserved.
     */
    private static function get_paragraph_anchor_content($anchor): string
    {
        $html = $anchor->get_outer_html();
        $class = $anchor->has_attribute('class') ? (string) $anchor->get_attribute('class') : '';
        if (\preg_match('/(^|[-_\s])(brand|logo)([-_\s]|$)/i', $class)) {
            $html = \preg_replace('/<\s*div\b([^>]*)>/i', '<span$1>', $html) ?? $html;
            $html = \preg_replace('/<\s*\/\s*div\s*>/i', '</span>', $html) ?? $html;
        }
        return $html;
    }
    /**
     * Checks whether a label is static visual UI text rather than a form label.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when the label can safely become editable paragraph text.
     */
    private static function is_static_visual_label($element): bool
    {
        if ('LABEL' !== $element->get_tag_name()) {
            return \false;
        }
        if ($element->has_attribute('for') || $element->has_attribute('form')) {
            return \false;
        }
        $inner_html = $element->get_inner_html();
        if (\trim(wp_strip_all_tags($inner_html)) === '') {
            return \false;
        }
        if (\preg_match('/<\s*(?:select|textarea|button|output|meter|progress)\b/i', $inner_html) === 1) {
            return \false;
        }
        if (\preg_match('/<\s*input\b/i', $inner_html) !== 1) {
            return \true;
        }
        return self::is_static_checkbox_label($element);
    }
    /**
     * Checks whether a label contains only decorative checkbox inputs plus visible text.
     *
     * @param HTML_To_Blocks_HTML_Element $element The source element.
     * @return bool True when checkbox inputs can be dropped and text preserved.
     */
    private static function is_static_checkbox_label($element): bool
    {
        if ('LABEL' !== $element->get_tag_name() || $element->has_attribute('for') || $element->has_attribute('form')) {
            return \false;
        }
        $inner_html = $element->get_inner_html();
        if (\trim(wp_strip_all_tags($inner_html)) === '') {
            return \false;
        }
        if (\preg_match_all('/<\s*input\b[^>]*>/i', $inner_html, $matches) < 1) {
            return \false;
        }
        foreach ($matches[0] as $input_html) {
            $type = '';
            if (\preg_match('/\stype\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $input_html, $type_match) === 1) {
                $type = \strtolower($type_match[2]);
            }
            if ('checkbox' !== $type) {
                return \false;
            }
            if (\preg_match('/\s(?:id|name|value|form|required|on[a-z0-9_-]*)\b/i', $input_html) === 1) {
                return \false;
            }
        }
        return \true;
    }
}
