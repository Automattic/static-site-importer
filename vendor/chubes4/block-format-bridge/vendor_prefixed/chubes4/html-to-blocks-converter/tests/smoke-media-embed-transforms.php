<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: media/embed raw transforms.
 *
 * Runs without WordPress by stubbing the tiny surface needed by the transform
 * registry and block factory. This keeps the transform contract deterministic:
 * high-confidence media patterns become semantic blocks, while unsafe patterns
 * remain unmatched for the raw handler's core/html fallback.
 *
 * Run: php tests/smoke-media-embed-transforms.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\function_exists('BlockFormatBridge\Vendor\esc_attr')) {
    function esc_attr($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\esc_url')) {
    function esc_url($value)
    {
        return (string) $value;
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\sanitize_html_class')) {
    function sanitize_html_class($value)
    {
        return \preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return \parse_url($url, $component);
    }
}
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        private static $instance;
        private $blocks = [];
        public static function get_instance()
        {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        public function __construct()
        {
            $source_string = ['type' => 'string', 'source' => 'attribute'];
            $source_rich = ['type' => 'rich-text', 'source' => 'rich-text'];
            foreach (['core/video', 'core/audio', 'core/image'] as $name) {
                $this->blocks[$name] = (object) ['attributes' => ['src' => $source_string, 'url' => $source_string, 'alt' => $source_string, 'caption' => $source_rich, 'poster' => $source_string, 'preload' => $source_string, 'autoplay' => ['type' => 'boolean', 'source' => 'attribute'], 'controls' => ['type' => 'boolean', 'source' => 'attribute'], 'loop' => ['type' => 'boolean', 'source' => 'attribute'], 'muted' => ['type' => 'boolean', 'source' => 'attribute'], 'id' => ['type' => 'number'], 'className' => ['type' => 'string']]];
            }
            $this->blocks['core/gallery'] = (object) ['attributes' => ['ids' => ['type' => 'array'], 'columns' => ['type' => 'number']]];
            $this->blocks['core/media-text'] = (object) ['attributes' => ['mediaUrl' => ['type' => 'string'], 'mediaId' => ['type' => 'number'], 'mediaAlt' => $source_string, 'mediaType' => ['type' => 'string'], 'mediaPosition' => ['type' => 'string'], 'mediaWidth' => ['type' => 'number'], 'isStackedOnMobile' => ['type' => 'boolean']]];
            $this->blocks['core/file'] = (object) ['attributes' => ['href' => ['type' => 'string'], 'textLinkHref' => $source_string, 'fileName' => $source_rich, 'showDownloadButton' => ['type' => 'boolean']]];
            $this->blocks['core/embed'] = (object) ['attributes' => ['url' => ['type' => 'string'], 'type' => ['type' => 'string'], 'providerNameSlug' => ['type' => 'string'], 'responsive' => ['type' => 'boolean']]];
            foreach (['core/paragraph', 'core/html'] as $name) {
                $this->blocks[$name] = (object) ['attributes' => ['content' => ['type' => 'string']]];
            }
        }
        public function is_registered($name)
        {
            return isset($this->blocks[$name]);
        }
        public function get_registered($name)
        {
            return $this->blocks[$name] ?? null;
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
class H2BC_Fake_Element
{
    private $tag;
    private $attrs;
    private $inner;
    private $children;
    public function __construct($tag, $attrs = [], $inner = '', $children = [])
    {
        $this->tag = \strtoupper($tag);
        $this->attrs = \array_change_key_case($attrs, \CASE_LOWER);
        $this->inner = $inner;
        $this->children = $children;
    }
    public function get_tag_name()
    {
        return $this->tag;
    }
    public function has_attribute(string $name): bool
    {
        return \array_key_exists(\strtolower($name), $this->attrs);
    }
    public function get_attribute(string $name): ?string
    {
        return $this->attrs[\strtolower($name)] ?? null;
    }
    public function get_inner_html(): string
    {
        return $this->inner;
    }
    public function get_text_content(): string
    {
        return \trim(wp_strip_all_tags($this->inner));
    }
    public function get_outer_html(): string
    {
        return '<' . \strtolower($this->tag) . '>' . $this->inner . '</' . \strtolower($this->tag) . '>';
    }
    public function get_child_elements(): array
    {
        return $this->children;
    }
    public function query_selector(string $selector)
    {
        $all = $this->query_selector_all($selector);
        return $all[0] ?? null;
    }
    public function query_selector_all(string $selector): array
    {
        $results = [];
        foreach ($this->children as $child) {
            if ($child->matches($selector)) {
                $results[] = $child;
            }
            $results = \array_merge($results, $child->query_selector_all($selector));
        }
        return $results;
    }
    private function matches(string $selector): bool
    {
        if ('.' === $selector[0]) {
            $class = $this->attrs['class'] ?? '';
            return \preg_match('/(?:^|\s)' . \preg_quote(\substr($selector, 1), '/') . '(?:$|\s)/', $class) === 1;
        }
        return \strtoupper($selector) === $this->tag;
    }
}
$failures = [];
$assertions = 0;
$assert = function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$find_transform = function ($element) {
    foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
        if (\call_user_func($transform['isMatch'], $element)) {
            return $transform;
        }
    }
    return null;
};
$convert = function ($element, array $args = []) use ($find_transform) {
    $transform = $find_transform($element);
    if (!$transform) {
        return null;
    }
    $handler = function () {
        return [HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => 'Nested text'])];
    };
    return \call_user_func($transform['transform'], $element, $handler, $args);
};
$video = $convert(new H2BC_Fake_Element('video', ['src' => 'movie.mp4', 'poster' => 'poster.jpg', 'controls' => '']));
$assert('core/video' === $video['blockName'], 'video-direct-block');
$assert(\strpos($video['innerHTML'], 'movie.mp4') !== \false && \strpos($video['innerHTML'], 'poster.jpg') !== \false, 'video-direct-html');
$video_source = $convert(new H2BC_Fake_Element('video', [], '', [new H2BC_Fake_Element('source', ['src' => 'nested.mp4'])]));
$assert('core/video' === $video_source['blockName'], 'video-source-block');
$assert(\strpos($video_source['innerHTML'], 'nested.mp4') !== \false, 'video-source-html');
$audio = $convert(new H2BC_Fake_Element('audio', [], '', [new H2BC_Fake_Element('source', ['src' => 'clip.mp3'])]));
$assert('core/audio' === $audio['blockName'], 'audio-source-block');
$assert(\strpos($audio['innerHTML'], 'clip.mp3') !== \false, 'audio-source-html');
$gallery = $convert(new H2BC_Fake_Element('div', ['class' => 'gallery columns-2'], '', [new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'a.jpg', 'alt' => 'A', 'class' => 'wp-image-10']), new H2BC_Fake_Element('figcaption', [], 'Caption A')]), new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'b.jpg', 'alt' => 'B']), new H2BC_Fake_Element('figcaption', [], 'Caption B')])]));
$assert('core/gallery' === $gallery['blockName'], 'gallery-block');
$assert(\count($gallery['innerBlocks']) === 2, 'gallery-inner-image-count');
$assert(\strpos($gallery['innerHTML'], 'wp-block-gallery') !== \false, 'gallery-wrapper-html');
$assert(\strpos($gallery['innerBlocks'][0]['innerHTML'], 'Caption A') !== \false, 'gallery-caption-preserved');
$resolved_gallery = $convert(new H2BC_Fake_Element('div', ['class' => 'gallery columns-2'], '', [new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'a.jpg', 'alt' => 'A'])]), new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'b.jpg', 'alt' => 'B'])])]), ['context' => ['asset_metadata' => ['a.jpg' => ['id' => 101, 'url' => 'https://example.test/uploads/a.jpg'], 'b.jpg' => ['id' => 102, 'url' => 'https://example.test/uploads/b.jpg']]]]);
$assert([101, 102] === ($resolved_gallery['attrs']['ids'] ?? []), 'gallery-resolved-ids');
$assert(\strpos($resolved_gallery['innerBlocks'][0]['innerHTML'], 'https://example.test/uploads/a.jpg') !== \false, 'gallery-resolved-image-url');
$media_text = $convert(new H2BC_Fake_Element('div', ['class' => 'wp-block-media-text'], '', [new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'hero.jpg', 'alt' => 'Hero'])]), new H2BC_Fake_Element('div', ['class' => 'wp-block-media-text__content'], '<p>Copy</p>')]));
$assert('core/media-text' === $media_text['blockName'], 'media-text-block');
$assert(($media_text['attrs']['mediaUrl'] ?? '') === 'hero.jpg', 'media-text-media-url');
$assert(\count($media_text['innerBlocks']) === 1, 'media-text-inner-blocks');
$resolved_media_text = $convert(new H2BC_Fake_Element('div', ['class' => 'wp-block-media-text'], '', [new H2BC_Fake_Element('figure', [], '', [new H2BC_Fake_Element('img', ['src' => 'hero.jpg', 'alt' => 'Hero'])]), new H2BC_Fake_Element('div', ['class' => 'wp-block-media-text__content'], '<p>Copy</p>')]), ['context' => ['asset_metadata' => ['hero.jpg' => ['id' => 201, 'url' => 'https://example.test/uploads/hero.jpg', 'alt' => 'Resolved alt']]]]);
$assert(($resolved_media_text['attrs']['mediaUrl'] ?? '') === 'https://example.test/uploads/hero.jpg', 'media-text-resolved-url');
$assert(($resolved_media_text['attrs']['mediaId'] ?? null) === 201, 'media-text-resolved-id');
$assert(\strpos($resolved_media_text['innerHTML'], 'alt="Hero"') !== \false, 'media-text-preserves-source-alt');
$resolved_image = $convert(new H2BC_Fake_Element('img', ['src' => 'product.jpg', 'alt' => 'Product', 'width' => '640', 'height' => '480']), ['context' => ['asset_metadata' => ['product.jpg' => ['id' => 301, 'url' => 'https://example.test/uploads/product.jpg', 'width' => '1280']]]]);
$assert(($resolved_image['attrs']['id'] ?? null) === 301, 'image-resolved-id');
$assert(\strpos($resolved_image['innerHTML'], 'https://example.test/uploads/product.jpg') !== \false, 'image-resolved-url');
$assert(\strpos($resolved_image['innerHTML'], 'width="640"') !== \false, 'image-preserves-source-width');
$assert(\strpos($resolved_image['innerHTML'], 'https://example.test/uploads/product.jpg') !== \false, 'image-resolved-inner-html');
$file = $convert(new H2BC_Fake_Element('a', ['href' => 'https://example.com/report.pdf'], 'Download report'));
$assert('core/file' === $file['blockName'], 'file-link-block');
$assert(($file['attrs']['href'] ?? '') === 'https://example.com/report.pdf', 'file-link-href');
$cta = $find_transform(new H2BC_Fake_Element('a', ['href' => 'https://example.com/signup'], 'Sign up'));
$assert(!$cta || ($cta['blockName'] ?? '') !== 'core/file', 'normal-cta-link-not-file');
$embed = $convert(new H2BC_Fake_Element('iframe', ['src' => 'https://www.youtube.com/embed/abc123']));
$assert('core/embed' === $embed['blockName'], 'youtube-iframe-block');
$assert(($embed['attrs']['providerNameSlug'] ?? '') === 'youtube', 'youtube-provider');
$assert(($embed['attrs']['url'] ?? '') === 'https://www.youtube.com/watch?v=abc123', 'youtube-url-normalised');
$unknown_iframe = $find_transform(new H2BC_Fake_Element('iframe', ['src' => 'https://example.com/widget']));
$assert(null === $unknown_iframe, 'unknown-iframe-safe-fallback');
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
