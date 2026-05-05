<?php

namespace BlockFormatBridge\Vendor;

/**
 * Production-shape raw-handler fixture coverage.
 *
 * @package HtmlToBlocksConverter
 */
/**
 * Exercises literal HTML fragments through the plugin's raw handler.
 */
class RawHandlerFixturesUnitTest extends WP_UnitTestCase
{
    /**
     * Supported static HTML fixtures should become their native core blocks.
     */
    public function test_supported_fixtures_convert_to_expected_blocks(): void
    {
        foreach ($this->supported_fixtures() as $label => $fixture) {
            $fallbacks = array();
            $listener = static function (string $html, array $context, array $block) use (&$fallbacks): void {
                $fallbacks[] = array('html' => $html, 'context' => $context, 'block' => $block);
            };
            \add_action('html_to_blocks_unsupported_html_fallback', $listener, 10, 3);
            try {
                $blocks = html_to_blocks_raw_handler(array('HTML' => $fixture['html']));
            } finally {
                remove_action('html_to_blocks_unsupported_html_fallback', $listener, 10);
            }
            $this->assertSame($fixture['expected_names'], $this->flatten_block_names($blocks), "{$label} should produce expected block names.");
            $this->assertSame(array(), $fallbacks, "{$label} should not emit unsupported HTML fallbacks.");
            $combined_html = $this->combined_block_html($blocks);
            foreach ($fixture['snippets'] ?? array() as $snippet) {
                $this->assertStringContainsString($snippet, $combined_html, "{$label} should preserve {$snippet}.");
            }
        }
    }
    /**
     * Unsupported HTML should remain observable and preserve the original fragment.
     */
    public function test_unsupported_fixtures_emit_fallback_context(): void
    {
        foreach ($this->unsupported_fixtures() as $label => $fixture) {
            $fallbacks = array();
            $listener = static function (string $html, array $context, array $block) use (&$fallbacks): void {
                $fallbacks[] = array('html' => $html, 'context' => $context, 'block' => $block);
            };
            \add_action('html_to_blocks_unsupported_html_fallback', $listener, 10, 3);
            try {
                $blocks = html_to_blocks_raw_handler(array('HTML' => $fixture['html']));
            } finally {
                remove_action('html_to_blocks_unsupported_html_fallback', $listener, 10);
            }
            $this->assertSame($fixture['expected_names'], $this->flatten_block_names($blocks), "{$label} should preserve unsupported HTML as core/html.");
            $this->assertCount(1, $fallbacks, "{$label} should emit one fallback action.");
            $this->assertSame('no_transform', $fallbacks[0]['context']['reason'] ?? null, "{$label} should expose fallback reason.");
            $this->assertSame($fixture['fallback_tag'], $fallbacks[0]['context']['tag_name'] ?? null, "{$label} should expose fallback tag.");
            $this->assertSame('core/html', $fallbacks[0]['block']['blockName'] ?? null, "{$label} fallback should be core/html.");
            $this->assertStringContainsString($fixture['snippet'], $fallbacks[0]['html'] ?? '', "{$label} fallback should include source HTML.");
        }
    }
    /**
     * Balanced element extraction should preserve nested same-tag content.
     */
    public function test_balanced_element_extraction_preserves_nested_same_tag_content(): void
    {
        $html = '<div class="outer"><div class="inner">Nested</div><p>After</p></div><div>Sibling</div>';
        $this->assertSame('<div class="outer"><div class="inner">Nested</div><p>After</p></div>', html_to_blocks_extract_balanced_element($html, 'DIV'));
    }
    /**
     * Child extraction should preserve direct children with nested same-tag content.
     */
    public function test_child_extraction_preserves_nested_same_tag_content(): void
    {
        $element = HTML_To_Blocks_HTML_Element::from_html('<section><article class="card"><div class="media"><div class="dot"></div></div><p>Card</p></article><article><p>Second</p></article></section>');
        $children = $element ? $element->get_child_elements() : array();
        $this->assertCount(2, $children);
        $this->assertStringContainsString('<div class="dot"></div>', $children[0]->get_outer_html());
        $this->assertSame('ARTICLE', $children[0]->get_tag_name());
    }
    /**
     * Already wrapped block-like fragments should skip normalization.
     */
    public function test_single_block_root_can_skip_normalization(): void
    {
        $this->assertTrue(html_to_blocks_can_skip_normalise_blocks('<main><section><p>Wrapped</p></section></main>'));
        $this->assertFalse(html_to_blocks_can_skip_normalise_blocks('<span>Inline</span>'));
        $this->assertFalse(html_to_blocks_can_skip_normalise_blocks('<section>One</section><section>Two</section>'));
    }
    /**
     * Supported fixture matrix.
     *
     * @return array<string,array{html:string,expected_names:string[],snippets?:string[]}>
     */
    private function supported_fixtures(): array
    {
        return array('heading' => array('html' => '<h2>Fixture Heading</h2>', 'expected_names' => array('core/heading'), 'snippets' => array('Fixture Heading')), 'paragraph' => array('html' => '<p>Fixture <strong>paragraph</strong>.</p>', 'expected_names' => array('core/paragraph'), 'snippets' => array('<strong>paragraph</strong>')), 'nested-list' => array('html' => '<ul><li>One<ul><li>Child</li></ul></li><li>Two</li></ul>', 'expected_names' => array('core/list', 'core/list-item', 'core/list', 'core/list-item', 'core/list-item'), 'snippets' => array('Child')), 'quote' => array('html' => '<blockquote><p>Quote text</p><cite>Source</cite></blockquote>', 'expected_names' => array('core/quote', 'core/paragraph', 'core/paragraph'), 'snippets' => array('Source')), 'code' => array('html' => '<pre><code>const answer = 42;</code></pre>', 'expected_names' => array('core/code'), 'snippets' => array('const answer = 42;')), 'preformatted' => array('html' => '<pre>Plain preformatted text</pre>', 'expected_names' => array('core/preformatted'), 'snippets' => array('Plain preformatted text')), 'code-window' => array('html' => '<div class="workflow-code reveal hidden"><div class="code-window"><div class="code-titlebar"><div class="code-dot code-dot-r"></div><div class="code-dot code-dot-y"></div><div class="code-dot code-dot-g"></div><span class="code-filename">import-command.sh</span></div><div class="code-block"><div><span class="prompt">$</span> studio import ./site</div><div><span class="comment"># Converts static snippets to blocks</span></div></div></div></div>', 'expected_names' => array('core/group', 'core/group', 'core/group', 'core/paragraph', 'core/preformatted'), 'snippets' => array('workflow-code reveal hidden', 'code-window', 'code-titlebar', 'import-command.sh', 'studio import ./site', 'Converts static snippets to blocks')), 'hero-code-window' => array('html' => '<div class="hero-code-block reveal"><div class="hero-code-header"><span class="hero-code-dot"></span><span class="code-filename">agent-output.html &rarr; WordPress blocks</span></div><div class="hero-code-body"><div><span class="token-tag">&lt;section</span> <span class="token-attr">class</span>=<span class="token-val">"hero"</span><span class="token-tag">&gt;</span></div></div></div>', 'expected_names' => array('core/group', 'core/group', 'core/paragraph', 'core/preformatted'), 'snippets' => array('hero-code-block reveal', 'hero-code-header', 'agent-output.html &rarr; WordPress blocks', 'token-tag')), 'hero-code-window-panel' => array('html' => '<div class="hero-code-window"><div class="window-bar"><span class="dot dot-r"></span><span class="dot dot-y"></span><span class="dot dot-g"></span><span class="window-title">studio-code &mdash; agent output</span></div><div class="code-body"><span class="code-line c-comment">// Agent writes normal HTML/CSS</span><span class="code-line"><span class="token-tag">&lt;section</span> <span class="token-attr">class</span>=<span class="token-val">"hero"</span><span class="token-tag">&gt;</span></span></div><div class="code-badge">Site Editor ready</div></div>', 'expected_names' => array('core/group', 'core/group', 'core/paragraph', 'core/preformatted', 'core/group', 'core/paragraph'), 'snippets' => array('hero-code-window', 'window-bar', 'studio-code &mdash; agent output', 'code-body', 'Agent writes normal HTML/CSS', 'token-tag', 'code-badge', 'Site Editor ready')), 'div-line-code-panel' => array('html' => '<div class="case-code"><div class="t-comment"># customer brief &rarr; editable WordPress site</div><div>&nbsp;</div><div><span class="t-fn">generate</span>(<span class="t-string">"luxury spa, warm earth tones,</span></div><div><span class="t-string">  hero with booking CTA,</span></div><div><span class="t-string">  services grid, testimonials"</span>)</div><div>&nbsp;</div><div class="t-comment"># agent writes index.html + style.css</div><div>&nbsp;</div><div><span class="t-keyword">import-theme</span> /tmp/static-site/</div><div><span class="t-keyword">  --slug</span>=<span class="t-string">luxe-spa</span></div><div><span class="t-keyword">  --activate</span> <span class="t-keyword">--overwrite</span></div><div>&nbsp;</div><div class="t-comment"># OK Block theme active in Site Editor</div></div>', 'expected_names' => array('core/preformatted'), 'snippets' => array('case-code', 't-comment', 'customer brief', 't-fn', 'generate', 't-string', 'luxe-spa', 't-keyword', '--overwrite')), 'empty-quote-accent-bar' => array('html' => '<blockquote class="quote-card"><div class="quote-accent-bar"></div><p>Blocks over fallbacks.</p></blockquote>', 'expected_names' => array('core/quote', 'core/paragraph'), 'snippets' => array('Blocks over fallbacks.')), 'empty-step-connector' => array('html' => '<div class="workflow-steps"><div class="workflow-step"><p>Plan</p></div><div class="step-connector"></div><div class="workflow-step"><p>Build</p></div></div>', 'expected_names' => array('core/group', 'core/group', 'core/paragraph', 'core/group', 'core/group', 'core/paragraph'), 'snippets' => array('workflow-steps', 'workflow-step', 'step-connector', 'Plan', 'Build')), 'separator' => array('html' => '<hr class="is-style-wide">', 'expected_names' => array('core/separator'), 'snippets' => array('wp-block-separator')), 'table' => array('html' => '<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Ada</td></tr></tbody></table>', 'expected_names' => array('core/table')), 'group' => array('html' => '<div class="wp-block-group"><p>Grouped copy</p></div>', 'expected_names' => array('core/group', 'core/paragraph'), 'snippets' => array('Grouped copy')), 'image-wrapper' => array('html' => '<div class="nav-logo-mark"><img src="http://localhost:9159/wp-content/themes/studio-code/assets/icons/theme-part-header-1-b57d7c946b676f82.svg" alt="" decoding="async"></div>', 'expected_names' => array('core/group', 'core/image'), 'snippets' => array('nav-logo-mark', 'theme-part-header-1-b57d7c946b676f82.svg')), 'parsed-image-html-wrapper' => array('html' => '<!-- wp:group {"className":"nav-logo-mark"} --><div class="wp-block-group nav-logo-mark"><!-- wp:html --><img src="http://localhost:9159/wp-content/themes/studio-code/assets/icons/theme-part-header-1-b57d7c946b676f82.svg" alt="" decoding="async"><!-- /wp:html --></div><!-- /wp:group -->', 'expected_names' => array('core/group', 'core/image'), 'snippets' => array('nav-logo-mark', 'theme-part-header-1-b57d7c946b676f82.svg')), 'site-editor-landmark' => array('html' => '<main class="site-shell"><section class="hero"><h1>Site Editor Template Smoke</h1><p>Template raw HTML should become blocks.</p></section></main>', 'expected_names' => array('core/group', 'core/group', 'core/heading', 'core/paragraph'), 'snippets' => array('site-shell', 'hero', 'Site Editor Template Smoke', 'Template raw HTML should become blocks.')), 'columns' => array('html' => '<div class="wp-block-columns"><div class="wp-block-column"><p>Left</p></div><div class="wp-block-column"><p>Right</p></div></div>', 'expected_names' => array('core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph'), 'snippets' => array('Left', 'Right')), 'cover' => array('html' => '<section class="hero cover" style="background-image: url(cover.jpg)"><p>Cover text</p></section>', 'expected_names' => array('core/cover', 'core/paragraph'), 'snippets' => array('Cover text')), 'spacer' => array('html' => '<div style="height: 48px" aria-hidden="true" class="wp-block-spacer"></div>', 'expected_names' => array('core/spacer')), 'buttons' => array('html' => '<a class="wp-block-button__link wp-element-button" href="https://example.com">Click</a>', 'expected_names' => array('core/buttons', 'core/button'), 'snippets' => array('https://example.com', 'Click')), 'hero-actions' => array('html' => '<div class="hero-actions"><a href="/early-access/" class="btn btn-primary">Get Early Access</a><a href="/proof/" class="btn btn-ghost">See the Proof</a></div>', 'expected_names' => array('core/buttons', 'core/button', 'core/button'), 'snippets' => array('hero-actions', '/early-access/', 'btn btn-primary', 'Get Early Access', '/proof/', 'btn btn-ghost', 'See the Proof')), 'cta-actions' => array('html' => '<div class="cta-actions"><a href="/signup/" class="cta-primary">Start Now <img src="/assets/arrow.svg" alt="" class="materialized-icon"></a><a href="/contact/" class="cta-secondary">Contact Us</a></div>', 'expected_names' => array('core/buttons', 'core/button', 'core/button'), 'snippets' => array('cta-actions', '/signup/', 'cta-primary', 'Start Now', '/assets/arrow.svg', 'materialized-icon', '/contact/', 'cta-secondary', 'Contact Us')), 'details' => array('html' => '<details><summary>Question</summary><p>Answer</p></details>', 'expected_names' => array('core/details', 'core/paragraph'), 'snippets' => array('Question', 'Answer')), 'pullquote' => array('html' => '<blockquote class="wp-block-pullquote"><p>Pull this</p><cite>Citation</cite></blockquote>', 'expected_names' => array('core/pullquote'), 'snippets' => array('Pull this', 'Citation')), 'verse' => array('html' => '<pre class="wp-block-verse">Line one\nLine two</pre>', 'expected_names' => array('core/verse'), 'snippets' => array('Line one', 'Line two')), 'video' => array('html' => '<video controls src="movie.mp4" poster="poster.jpg"></video>', 'expected_names' => array('core/video'), 'snippets' => array('movie.mp4', 'poster.jpg')), 'audio' => array('html' => '<audio controls><source src="clip.mp3" type="audio/mpeg"></audio>', 'expected_names' => array('core/audio'), 'snippets' => array('clip.mp3')), 'gallery' => array('html' => '<div class="gallery columns-2"><figure><img src="a.jpg" alt="A" class="wp-image-10"><figcaption>Caption A</figcaption></figure><figure><img src="b.jpg" alt="B"><figcaption>Caption B</figcaption></figure></div>', 'expected_names' => array('core/gallery', 'core/image', 'core/image'), 'snippets' => array('Caption A', 'b.jpg')), 'media-text' => array('html' => '<div class="wp-block-media-text"><figure><img src="hero.jpg" alt="Hero"></figure><div class="wp-block-media-text__content"><p>Media copy</p></div></div>', 'expected_names' => array('core/media-text', 'core/paragraph'), 'snippets' => array('hero.jpg', 'Media copy')), 'file' => array('html' => '<a href="https://example.com/report.pdf">Download report</a>', 'expected_names' => array('core/file'), 'snippets' => array('report.pdf', 'Download report')), 'recognized-embed' => array('html' => '<iframe src="https://www.youtube.com/embed/abc123"></iframe>', 'expected_names' => array('core/embed'), 'snippets' => array('youtube.com/watch?v=abc123')));
    }
    /**
     * Unsupported fixture matrix.
     *
     * @return array<string,array{html:string,expected_names:string[],fallback_tag:string,snippet:string}>
     */
    private function unsupported_fixtures(): array
    {
        return array('unknown-iframe-provider' => array('html' => '<iframe src="https://example.com/widget"></iframe>', 'expected_names' => array('core/html'), 'fallback_tag' => 'IFRAME', 'snippet' => 'example.com/widget'), 'custom-element' => array('html' => '<x-card data-kind="promo">Custom payload</x-card>', 'expected_names' => array('core/html'), 'fallback_tag' => 'X-CARD', 'snippet' => 'Custom payload'), 'app-widget' => array('html' => '<div data-widget="stock-ticker"><span>AAPL</span></div>', 'expected_names' => array('core/html'), 'fallback_tag' => 'DIV', 'snippet' => 'stock-ticker'));
    }
    /**
     * Flatten block names recursively.
     *
     * @param array<int,array<string,mixed>> $blocks Blocks.
     * @return string[] Block names.
     */
    private function flatten_block_names(array $blocks): array
    {
        $names = array();
        foreach ($blocks as $block) {
            $names[] = $block['blockName'] ?? null;
            if (!empty($block['innerBlocks'])) {
                $names = \array_merge($names, $this->flatten_block_names($block['innerBlocks']));
            }
        }
        return $names;
    }
    /**
     * Collect block HTML/content recursively for fixture assertions.
     *
     * @param array<int,array<string,mixed>> $blocks Blocks.
     * @return string Combined HTML/content.
     */
    private function combined_block_html(array $blocks): string
    {
        $combined = '';
        foreach ($blocks as $block) {
            foreach (array('innerHTML', 'content') as $key) {
                if (isset($block[$key]) && \is_string($block[$key])) {
                    $combined .= "\n" . $block[$key];
                }
            }
            foreach ($block['attrs'] ?? array() as $value) {
                if (\is_string($value)) {
                    $combined .= "\n" . $value;
                }
            }
            if (!empty($block['innerBlocks'])) {
                $combined .= $this->combined_block_html($block['innerBlocks']);
            }
        }
        return $combined;
    }
}
