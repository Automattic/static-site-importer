<?php
/**
 * Smoke coverage for the public conversion API.
 *
 * @package BlockFormatBridge
 */

/**
 * Exercises the conversion paths BFB exposes through bfb_convert().
 */
class BFBConversionUnitTest extends WP_UnitTestCase {

	/**
	 * HTML input should route through html-to-blocks-converter for core block transforms.
	 */
	public function test_html_to_blocks_covers_core_transforms(): void {
		foreach ( range( 1, 6 ) as $level ) {
			$blocks = $this->blocks_from( "<h{$level}>Heading {$level}</h{$level}>", 'html' );

			$this->assertSame( 'core/heading', $blocks[0]['blockName'] ?? null, "h{$level} converts to heading block." );
			$this->assertSame( $level, $blocks[0]['attrs']['level'] ?? null, "h{$level} preserves heading level." );
		}

		$paragraph = $this->blocks_from( '<p>Text with <strong>bold</strong>, <em>emphasis</em>, and <a href="https://example.com">a link</a>.</p>', 'html' );
		$this->assertSame( 'core/paragraph', $paragraph[0]['blockName'] ?? null );
		$this->assertStringContainsString( '<strong>bold</strong>', $paragraph[0]['innerHTML'] ?? '' );
		$this->assertStringContainsString( '<em>emphasis</em>', $paragraph[0]['innerHTML'] ?? '' );
		$this->assertStringContainsString( '<a href="https://example.com">a link</a>', $paragraph[0]['innerHTML'] ?? '' );

		$unordered = $this->blocks_from( '<ul><li>One</li><li>Two</li></ul>', 'html' );
		$this->assertSame( 'core/list', $unordered[0]['blockName'] ?? null );
		$this->assertFalse( $unordered[0]['attrs']['ordered'] ?? true );
		$this->assertSame( 'core/list-item', $unordered[0]['innerBlocks'][0]['blockName'] ?? null );

		$ordered = $this->blocks_from( '<ol><li>First</li><li>Second</li></ol>', 'html' );
		$this->assertSame( 'core/list', $ordered[0]['blockName'] ?? null );
		$this->assertTrue( $ordered[0]['attrs']['ordered'] ?? false );
		$this->assertSame( 'core/list-item', $ordered[0]['innerBlocks'][0]['blockName'] ?? null );

		$quote = $this->blocks_from( '<blockquote><p>Quoted text</p></blockquote>', 'html' );
		$this->assertSame( 'core/quote', $quote[0]['blockName'] ?? null );
		$this->assertSame( 'core/paragraph', $quote[0]['innerBlocks'][0]['blockName'] ?? null );
		$this->assertStringContainsString( 'Quoted text', $quote[0]['innerBlocks'][0]['innerHTML'] ?? '' );

		$nested_quote = $this->blocks_from( '<blockquote><blockquote><p>Deep quote</p></blockquote></blockquote>', 'html' );
		$this->assertSame( 'core/quote', $nested_quote[0]['blockName'] ?? null );
		$this->assertSame( 'core/quote', $nested_quote[0]['innerBlocks'][0]['blockName'] ?? null );
		$this->assertSame( 'core/paragraph', $nested_quote[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? null );

		$code = $this->blocks_from( '<pre><code class="language-php">echo "hi";</code></pre>', 'html' );
		$this->assertSame( 'core/code', $code[0]['blockName'] ?? null );
		$this->assertSame( 'language-php', $code[0]['attrs']['className'] ?? null );
		$this->assertStringContainsString( 'echo "hi";', html_entity_decode( $code[0]['innerHTML'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$table = $this->blocks_from( '<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>BFB</td></tr></tbody></table>', 'html' );
		$this->assertSame( 'core/table', $table[0]['blockName'] ?? null );
		$this->assertStringContainsString( '<th>Name</th>', $table[0]['innerHTML'] ?? '' );
		$this->assertStringContainsString( '<td>BFB</td>', $table[0]['innerHTML'] ?? '' );
	}

	/**
	 * HTML input should delegate to BFB's bundled, vendor-prefixed h2bc copy.
	 */
	public function test_html_to_blocks_delegates_to_bundled_h2bc_for_representative_fixtures(): void {
		$this->assertTrue(
			function_exists( '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler' ),
			'BFB package mode should expose the vendor-prefixed h2bc raw handler.'
		);

		$fixtures = array(
			'heading paragraph baseline' => array(
				'html'   => '<h2>Delegated Heading</h2><p>Delegated paragraph.</p>',
				'blocks' => array( 'core/heading', 'core/paragraph' ),
			),
			'recursive nested transforms' => array(
				'html'   => '<ul><li>One<ul><li>Nested</li></ul></li></ul>'
					. '<blockquote><p>Quoted</p></blockquote>'
					. '<table><tbody><tr><td>Cell</td></tr></tbody></table>',
				'blocks' => array( 'core/list', 'core/list-item', 'core/quote', 'core/paragraph', 'core/table' ),
			),
			'image code separator transforms' => array(
				'html'   => '<figure><img src="https://example.com/image.jpg" alt="Example"><figcaption>Caption</figcaption></figure>'
					. '<pre><code class="language-php">echo "hi";</code></pre>'
					. '<hr class="is-style-wide">',
				'blocks' => array( 'core/image', 'core/code', 'core/separator' ),
			),
			'unsupported safe fallback' => array(
				'html'   => '<iframe src="https://example.com/embed"></iframe>',
				'blocks' => array( 'core/html' ),
			),
		);

		foreach ( $fixtures as $label => $fixture ) {
			$blocks = $this->blocks_from( $fixture['html'], 'html' );
			$flat   = $this->flatten_blocks( $blocks );

			foreach ( $fixture['blocks'] as $block_name ) {
				$this->assertContains( $block_name, $flat, "{$label} should include {$block_name}." );
			}
		}
	}

	/**
	 * Conversion options should flow from the public API into adapters and h2bc args.
	 */
	public function test_conversion_options_flow_to_adapters_and_h2bc_args(): void {
		$html = '<h2>Options Heading</h2><p>Options paragraph.</p>';

		$default = bfb_convert( $html, 'html', 'blocks' );
		$this->assertNotSame( '', $default, 'Default 3-argument conversion should remain supported.' );

		$default_args = null;
		$default_listener = static function ( array $args ) use ( &$default_args ): array {
			$default_args = $args;
			return $args;
		};

		add_filter( 'bfb_html_to_blocks_args', $default_listener, 10, 1 );
		try {
			bfb_convert( $html, 'html', 'blocks' );
		} finally {
			remove_filter( 'bfb_html_to_blocks_args', $default_listener, 10 );
		}

		$this->assertIsArray( $default_args, 'Default conversion should still expose h2bc raw-handler arguments.' );
		$this->assertArrayNotHasKey( 'context', $default_args, 'Default conversion should not inject conversion context.' );

		$seen_args = null;
		$listener  = static function ( array $args, string $content, array $options ) use ( &$seen_args ): array {
			$seen_args = array(
				'args'    => $args,
				'content' => $content,
				'options' => $options,
			);
			return $args;
		};

		add_filter( 'bfb_html_to_blocks_args', $listener, 10, 3 );
		try {
			$with_options = bfb_convert(
				$html,
				'html',
				'blocks',
				array(
					'context' => array(
						'source' => 'static-site-importer',
						'mode'   => 'import',
					),
					'mode'    => 'fidelity',
				)
			);
		} finally {
			remove_filter( 'bfb_html_to_blocks_args', $listener, 10 );
		}

		$this->assertNotSame( '', $with_options, '4-argument conversion should produce serialized blocks.' );
		$this->assertIsArray( $seen_args, 'HTML adapter should expose h2bc raw-handler arguments.' );
		$this->assertSame( 'fidelity', $seen_args['args']['mode'] ?? null, 'Mode option should be forwarded to h2bc args.' );
		$this->assertSame(
			array(
				'source' => 'static-site-importer',
				'mode'   => 'import',
			),
			$seen_args['args']['context'] ?? null,
			'Generic conversion context should be forwarded to h2bc args.'
		);
		$this->assertSame( $html, $seen_args['args']['HTML'] ?? null, 'BFB should preserve the reserved HTML raw-handler arg.' );
		$this->assertSame(
			array(
				'context' => array(
					'source' => 'static-site-importer',
					'mode'   => 'import',
				),
				'mode'    => 'fidelity',
			),
			$seen_args['options'] ?? null,
			'HTML adapter should receive public conversion options.'
		);

		$probe = new class() implements BFB_Format_Adapter {
			/**
			 * @var array<string, mixed>
			 */
			public $received_options = array();

			public function slug(): string {
				return 'probe-options';
			}

			public function to_blocks( string $content, array $options = array() ): array {
				unset( $content );
				$this->received_options = $options;
				return parse_blocks( '<!-- wp:paragraph --><p>Probe</p><!-- /wp:paragraph -->' );
			}

			public function from_blocks( array $blocks, array $options = array() ): string {
				unset( $blocks );
				$this->received_options = $options;
				return 'probe';
			}

			public function detect( string $content ): bool {
				unset( $content );
				return false;
			}
		};

		$adapter_filter = static function ( $adapter, string $slug ) use ( $probe ) {
			return 'probe-options' === $slug ? $probe : $adapter;
		};

		add_filter( 'bfb_register_format_adapter', $adapter_filter, 10, 2 );
		try {
			bfb_convert( 'probe source', 'probe-options', 'blocks', array( 'mode' => 'fidelity' ) );
		} finally {
			remove_filter( 'bfb_register_format_adapter', $adapter_filter, 10 );
		}

		$this->assertSame( array( 'mode' => 'fidelity' ), $probe->received_options, 'Generic adapters should receive public conversion options.' );
	}

	/**
	 * BFB should expose h2bc's expanded layout transforms through bfb_convert().
	 */
	public function test_html_to_blocks_covers_expanded_layout_transforms(): void {
		$fixtures = array(
			'group'   => array( '<section id="intro" class="intro-section"><h2>Intro</h2><p>Hello</p></section>', array( 'core/group' ) ),
			'columns' => array( '<div class="row"><div class="col-md-6"><p>Left</p></div><div class="col-md-6"><p>Right</p></div></div>', array( 'core/columns', 'core/column' ) ),
			'cover'   => array( '<section id="hero" class="hero" style="background-image: url(/hero.jpg); background-color: #123456;"><h1>Launch</h1></section>', array( 'core/cover' ) ),
			'spacer'  => array( '<div class="spacer" style="height: 48px"></div>', array( 'core/spacer' ) ),
		);

		foreach ( $fixtures as $label => $fixture ) {
			$flat = $this->flatten_blocks( $this->blocks_from( $fixture[0], 'html' ) );
			foreach ( $fixture[1] as $block_name ) {
				$this->assertContains( $block_name, $flat, "{$label} should include {$block_name}." );
			}
		}
	}

	/**
	 * BFB should expose h2bc's expanded action and text transforms through bfb_convert().
	 */
	public function test_html_to_blocks_covers_expanded_action_text_transforms(): void {
		$fixtures = array(
			'button'    => array( '<a class="wp-block-button__link" href="/signup">Sign up</a>', array( 'core/buttons', 'core/button' ) ),
			'details'   => array( '<details><summary>More <strong>info</strong></summary><p>Nested copy</p></details>', array( 'core/details' ) ),
			'pullquote' => array( '<blockquote class="wp-block-pullquote"><p>Big line</p><cite>Author</cite></blockquote>', array( 'core/pullquote' ) ),
			'verse'     => array( "<pre class=\"wp-block-verse\">Line 1\nLine 2<br>Line 3</pre>", array( 'core/verse' ) ),
		);

		foreach ( $fixtures as $label => $fixture ) {
			$flat = $this->flatten_blocks( $this->blocks_from( $fixture[0], 'html' ) );
			foreach ( $fixture[1] as $block_name ) {
				$this->assertContains( $block_name, $flat, "{$label} should include {$block_name}." );
			}
		}
	}

	/**
	 * BFB should expose h2bc's expanded media and embed transforms through bfb_convert().
	 */
	public function test_html_to_blocks_covers_expanded_media_embed_transforms(): void {
		$fixtures = array(
			'video'     => array( '<video src="movie.mp4" poster="poster.jpg" controls></video>', array( 'core/video' ) ),
			'audio'     => array( '<audio><source src="clip.mp3"></audio>', array( 'core/audio' ) ),
			'gallery'   => array( '<div class="gallery columns-2"><figure><img src="a.jpg" alt="A" class="wp-image-10"><figcaption>Caption A</figcaption></figure><figure><img src="b.jpg" alt="B"><figcaption>Caption B</figcaption></figure></div>', array( 'core/gallery' ) ),
			'mediaText' => array( '<div class="wp-block-media-text"><figure><img src="hero.jpg" alt="Hero"></figure><div class="wp-block-media-text__content"><p>Copy</p></div></div>', array( 'core/media-text' ) ),
			'file'      => array( '<a href="https://example.com/report.pdf">Download report</a>', array( 'core/file' ) ),
			'embed'     => array( '<iframe src="https://www.youtube.com/embed/abc123"></iframe>', array( 'core/embed' ) ),
		);

		foreach ( $fixtures as $label => $fixture ) {
			$flat = $this->flatten_blocks( $this->blocks_from( $fixture[0], 'html' ) );
			foreach ( $fixture[1] as $block_name ) {
				$this->assertContains( $block_name, $flat, "{$label} should include {$block_name}." );
			}
		}
	}

	/**
	 * Site Editor primitives must never be inferred from lookalike HTML.
	 */
	public function test_html_to_blocks_does_not_infer_site_editor_primitives(): void {
		$fixtures = array(
			'unmarked header'           => '<header><h1>Site title</h1><nav><a href="/">Home</a></nav></header>',
			'unmarked footer'           => '<footer><p>Copyright</p></footer>',
			'unmarked sidebar'          => '<aside><h2>Related</h2><p>Links</p></aside>',
			'unmarked pattern-lookalike' => '<section class="pricing-table"><h2>Pricing</h2><p>$10</p></section>',
		);

		foreach ( $fixtures as $label => $html ) {
			$serialized = bfb_convert( $html, 'html', 'blocks' );
			$blocks     = parse_blocks( $serialized );
			$flat       = $this->flatten_blocks( $blocks );

			$this->assertNotContains( 'core/template-part', $flat, "{$label} must not infer a template part." );
			$this->assertNotContains( 'core/pattern', $flat, "{$label} must not infer a pattern." );
			$this->assertStringNotContainsString( '<!-- wp:template-part', $serialized, "{$label} must not serialize a template part." );
			$this->assertStringNotContainsString( '<!-- wp:pattern', $serialized, "{$label} must not serialize a pattern." );
		}
	}

	/**
	 * Unsupported embeds should stay observable and preserve their HTML fallback.
	 */
	public function test_html_to_blocks_emits_unsupported_fallback_hook(): void {
		$this->ensure_block_registered( 'core/html' );

		$fallbacks = array();
		$listener  = static function ( string $html, array $context, array $block ) use ( &$fallbacks ): void {
			$fallbacks[] = array(
				'html'    => $html,
				'context' => $context,
				'block'   => $block,
			);
		};

		add_action( 'html_to_blocks_unsupported_html_fallback', $listener, 10, 3 );
		try {
			$blocks = $this->blocks_from( '<iframe src="https://example.com/widget"></iframe>', 'html' );
		} finally {
			remove_action( 'html_to_blocks_unsupported_html_fallback', $listener, 10 );
		}

		$this->assertSame( 'core/html', $blocks[0]['blockName'] ?? null );
		$this->assertNotEmpty( $fallbacks, 'Unsupported fallback hook should fire.' );
		$this->assertSame( 'core/html', $fallbacks[0]['block']['blockName'] ?? null );
		$this->assertStringContainsString( 'https://example.com/widget', $fallbacks[0]['html'] ?? '' );
	}

	/**
	 * The bundled artifact should include h2bc's file-link transform.
	 */
	public function test_bundled_h2bc_artifact_includes_file_transform(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture reads the bundled artifact from disk.
		$registry_source = file_get_contents( BFB_PATH . 'vendor_prefixed/chubes4/html-to-blocks-converter/includes/class-transform-registry.php' );

		$this->assertIsString( $registry_source );
		$this->assertStringContainsString( "'blockName' => 'core/file'", $registry_source );
		$this->assertStringContainsString( 'is_file_link', $registry_source );
	}

	/**
	 * Markdown input should use CommonMark/GFM, then the same HTML adapter path.
	 */
	public function test_markdown_to_blocks_covers_commonmark_and_gfm_paths(): void {
		$markdown = <<<MARKDOWN
# Markdown Heading

Paragraph with **bold**, *emphasis*, [a link](https://example.com), ~~strike~~, and <https://example.com/auto>.

- One
- Two

1. First
2. Second

> Quote text
>
> > Nested quote

```php
echo "hi";
```

| Name | Value |
| ---- | ----- |
| BFB  | Works |
MARKDOWN;

		$blocks = $this->blocks_from( $markdown, 'markdown' );
		$flat   = $this->flatten_blocks( $blocks );

		$this->assertContains( 'core/heading', $flat );
		$this->assertContains( 'core/paragraph', $flat );
		$this->assertContains( 'core/list', $flat );
		$this->assertContains( 'core/list-item', $flat );
		$this->assertContains( 'core/quote', $flat );
		$this->assertContains( 'core/code', $flat );
		$this->assertContains( 'core/table', $flat );

		$serialized = bfb_convert( $markdown, 'markdown', 'blocks' );
		$this->assertStringContainsString( '<strong>bold</strong>', $serialized );
		$this->assertStringContainsString( '<em>emphasis</em>', $serialized );
		$this->assertStringContainsString( '<del>strike</del>', $serialized );
		$this->assertStringContainsString( 'https://example.com/auto', $serialized );
		$this->assertStringContainsString( 'language-php', $serialized );
	}

	/**
	 * Import-grade Markdown bodies should become native blocks without SSI-specific APIs.
	 */
	public function test_markdown_to_blocks_covers_import_grade_body_fixtures(): void {
		$fixtures = array(
			'docs'    => array(
				'file'     => 'import-grade-docs.md',
				'blocks'   => array( 'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/table', 'core/code', 'core/quote' ),
				'snippets' => array(
					'<!-- wp:heading {"level":1} -->',
					'<h2 class="wp-block-heading">Preflight</h2>',
					'<td>Markdown</td><td>Native blocks</td>',
					'const format = &#039;markdown&#039;;',
				),
			),
			'article' => array(
				'file'     => 'import-grade-article.md',
				'blocks'   => array( 'core/heading', 'core/paragraph', 'core/quote', 'core/list', 'core/list-item', 'core/code' ),
				'snippets' => array(
					'<h1 class="wp-block-heading">The Import-Grade Article</h1>',
					'<img src="https://example.com/migration-diagram.png" alt="Migration diagram"',
					'<strong>bold decisions</strong>',
					'bfb_convert( $markdown, &#039;markdown&#039;, &#039;blocks&#039; );',
				),
			),
			'landing' => array(
				'file'     => 'import-grade-landing.md',
				'blocks'   => array( 'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/separator', 'core/table', 'core/quote' ),
				'snippets' => array(
					'<h1 class="wp-block-heading">Ship Cleaner Imports</h1>',
					'<a href="https://example.com/start">Start the import</a>',
					'<!-- wp:separator -->',
					'<td>Landing pages</td><td>Content blocks</td>',
				),
			),
		);

		foreach ( $fixtures as $label => $fixture ) {
			$markdown   = $this->markdown_fixture( $fixture['file'] );
			$serialized = bfb_convert( $markdown, 'markdown', 'blocks' );
			$blocks     = parse_blocks( $serialized );
			$flat       = $this->flatten_blocks( $blocks );

			$this->assertNotSame( '', $serialized, "{$label} fixture should produce serialized blocks." );
			$this->assertNotContains( 'core/html', $flat, "{$label} fixture should not fall back to raw HTML blocks." );

			foreach ( $fixture['blocks'] as $block_name ) {
				$this->assertContains( $block_name, $flat, "{$label} fixture should include {$block_name}." );
			}

			foreach ( $fixture['snippets'] as $snippet ) {
				$this->assertStringContainsString( $snippet, $serialized, "{$label} fixture should serialize {$snippet}." );
			}
		}
	}

	/**
	 * Compiler consumers should get block arrays without reaching into adapters.
	 */
	public function test_bfb_to_blocks_exposes_compiler_facing_block_array_helper(): void {
		$block_markup = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Array Heading</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Array paragraph.</p><!-- /wp:paragraph -->';

		$parsed = bfb_to_blocks( $block_markup, 'blocks' );
		$this->assertSame( 'core/heading', $parsed[0]['blockName'] ?? null );
		$this->assertSame( 2, $parsed[0]['attrs']['level'] ?? null );
		$this->assertSame( 'core/paragraph', $parsed[1]['blockName'] ?? null );

		$html = bfb_to_blocks( '<h3>HTML Array</h3><p>HTML copy.</p>', 'html' );
		$this->assertSame( 'core/heading', $html[0]['blockName'] ?? null );
		$this->assertSame( 3, $html[0]['attrs']['level'] ?? null );
		$this->assertSame( 'core/paragraph', $html[1]['blockName'] ?? null );

		$markdown = bfb_to_blocks( "# Markdown Array\n\n- One\n- Two", 'markdown' );
		$flat     = $this->flatten_blocks( $markdown );
		$this->assertContains( 'core/heading', $flat );
		$this->assertContains( 'core/list', $flat );
		$this->assertContains( 'core/list-item', $flat );

		$this->assertSame( array(), bfb_to_blocks( 'content', 'asciidoc' ) );
		$this->assertSame( '', bfb_convert( 'content', 'asciidoc', 'blocks' ) );
	}

	/**
	 * Source-fragment conversion should produce scoped block output for targeted updates.
	 */
	public function test_source_fragment_conversion_returns_scoped_hero_block_output(): void {
		$fragment = '<section id="hero" class="hero hero-dark">'
			. '<div class="container"><p class="eyebrow">New season</p><h1>Launch faster</h1>'
			. '<p>Use a targeted update instead of importing the whole page.</p>'
			. '<a class="wp-block-button__link" href="/start">Start now</a></div></section>';
		$seen_args = null;
		$listener  = static function ( array $args ) use ( &$seen_args ): array {
			$seen_args = $args;
			return $args;
		};

		add_filter( 'bfb_html_to_blocks_args', $listener, 10, 1 );
		try {
			$result = bfb_convert_fragment(
				$fragment,
				array(
					'source_id'       => 'homepage-v2',
					'source_selector' => '#hero',
					'region_id'       => 'hero',
				)
			);
		} finally {
			remove_filter( 'bfb_html_to_blocks_args', $listener, 10 );
		}

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'html', $result['from'] );
		$this->assertSame( 'blocks', $result['to'] );
		$this->assertSame( 'fragment', $result['scope']['type'] ?? null );
		$this->assertSame( '#hero', $result['scope']['source_selector'] ?? null );
		$this->assertSame( 'hero', $result['scope']['region_id'] ?? null );
		$this->assertSame( $result['scope'], $result['provenance']['scope'] ?? null );
		$this->assertSame( 'fragment', $seen_args['context']['conversion_scope'] ?? null );
		$this->assertSame( '#hero', $seen_args['context']['source_fragment']['source_selector'] ?? null );
		$this->assertNotSame( '', $result['content'] );
		$this->assertStringContainsString( '<!-- wp:', $result['content'] );

		$flat = $this->flatten_blocks( $result['blocks'] );
		$this->assertContains( 'core/group', $flat, 'Hero section should become native group blocks.' );
		$this->assertNotContains( 'core/html', $flat, 'Hero fragment should not require raw HTML fallback.' );
		$this->assertStringContainsString( 'Launch faster', $result['content'] );
		$this->assertStringContainsString( 'Use a targeted update instead of importing the whole page.', $result['content'] );

		$this->assertSame( $result['scope'], $result['report']['scope'] ?? null );
		$this->assertSame( 0, $result['report']['fallback_event_count'] ?? null );
	}

	/**
	 * Fragment fallback diagnostics should stay attached to the fragment scope.
	 */
	public function test_source_fragment_conversion_scopes_fallback_diagnostics(): void {
		$result = bfb_convert_fragment(
			'<section id="widget"><iframe src="https://example.com/widget"></iframe></section>',
			array( 'source_selector' => '#widget' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'success_with_fallbacks', $result['status'] );
		$this->assertSame( '#widget', $result['diagnostics'][0]['scope']['source_selector'] ?? null );
		$this->assertSame( '#widget', $result['diagnostics'][0]['details']['scope']['source_selector'] ?? null );
		$this->assertSame( '#widget', $result['report']['diagnostics'][0]['scope']['source_selector'] ?? null );
		$this->assertSame( 1, $result['report']['fallback_event_count'] ?? null );
	}

	/**
	 * Block analysis should expose fallback details without every consumer reimplementing metrics.
	 */
	public function test_block_analysis_reports_core_html_fallback_details(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName' => 'core/html',
						'attrs'     => array(
							'content' => '<div class="widget"> Unsupported widget </div>',
						),
					),
				),
			),
		);

		$report = bfb_analyze_blocks( $blocks );

		$this->assertSame( 2, $report['total_blocks'] );
		$this->assertSame( 1, $report['core_html_blocks'] );
		$this->assertSame( 1, $report['block_counts']['core/html'] ?? null );
		$this->assertSame( '0.0', $report['fallbacks'][0]['path'] ?? null );
		$this->assertStringContainsString( 'Unsupported widget', $report['fallbacks'][0]['preview'] ?? '' );
	}

	/**
	 * Conversion reports should include h2bc fallback reasons captured during conversion.
	 */
	public function test_conversion_report_captures_h2bc_fallback_events(): void {
		$report = bfb_conversion_report( '<iframe src="https://example.com/widget"></iframe>', 'html' );

		$this->assertSame( 'html', $report['from'] );
		$this->assertSame( 1, $report['total_blocks'] );
		$this->assertSame( 1, $report['core_html_blocks'] );
		$this->assertSame( 1, $report['fallback_event_count'] );
		$this->assertSame( 'success_with_fallbacks', $report['status'] );
		$this->assertSame( 'core_html_fallback', $report['diagnostics'][0]['code'] ?? null );
		$this->assertStringContainsString( 'fallback_events', $report['agent_guidance'] ?? '' );
		$this->assertSame( 'no_transform', $report['fallback_events'][0]['reason'] ?? null );
		$this->assertSame( 'IFRAME', $report['fallback_events'][0]['tag_name'] ?? null );
		$this->assertStringContainsString( '<!-- wp:html', $report['serialized_blocks'] );
	}

	/**
	 * Conversion reports should surface generic downstream materialization requests.
	 */
	public function test_conversion_report_surfaces_safe_svg_materialization_metadata(): void {
		$safe_svg = '<svg viewBox="0 0 24 24" aria-label="Check"><path d="M20 6 9 17l-5-5" fill="none" stroke="currentColor" stroke-width="2"/></svg>';

		$pre_result = static function ( $pre_result, string $content ): array {
			unset( $pre_result );

			do_action(
				'html_to_blocks_materialization_request',
				array(
					'id'             => 'svg-icon-check',
					'kind'           => 'asset',
					'source'         => 'inline',
					'classification' => 'safe_svg_icon',
					'media_type'     => 'image/svg+xml',
					'filename'       => 'svg-icon-check.svg',
					'placeholder'    => 'bfb-materialization://svg-icon-check',
					'payload'        => $content,
					'alt'            => 'Check',
					'replacement'    => array(
						'block_name' => 'core/image',
						'attrs'      => array(
							'url' => 'bfb-materialization://svg-icon-check',
							'alt' => 'Check',
						),
					),
				)
			);

			return array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'url' => 'bfb-materialization://svg-icon-check',
						'alt' => 'Check',
					),
					'innerBlocks'  => array(),
					'innerHTML'    => '<figure class="wp-block-image"><img src="bfb-materialization://svg-icon-check" alt="Check"/></figure>',
					'innerContent' => array( '<figure class="wp-block-image"><img src="bfb-materialization://svg-icon-check" alt="Check"/></figure>' ),
				),
			);
		};

		add_filter( 'bfb_html_to_blocks_pre_result', $pre_result, 10, 2 );
		try {
			$report = bfb_conversion_report( $safe_svg, 'html' );
		} finally {
			remove_filter( 'bfb_html_to_blocks_pre_result', $pre_result, 10 );
		}

		$this->assertSame( 1, $report['total_blocks'] );
		$this->assertSame( 0, $report['core_html_blocks'] );
		$this->assertSame( 0, $report['fallback_event_count'] );
		$this->assertSame( 1, $report['materialization_request_count'] );
		$this->assertSame( 'success_with_materialization_requests', $report['status'] );
		$this->assertSame( 'materialization_requested', $report['diagnostics'][0]['code'] ?? null );
		$this->assertSame( 'safe_svg_icon', $report['materialization_requests'][0]['classification'] ?? null );
		$this->assertSame( 'image/svg+xml', $report['materialization_requests'][0]['media_type'] ?? null );
		$this->assertStringContainsString( '<svg viewBox=', $report['materialization_requests'][0]['payload'] ?? '' );
		$this->assertStringNotContainsString( '<!-- wp:html', $report['serialized_blocks'] );
	}

	/**
	 * Unsafe SVG should stay on the explicit unsupported fallback path.
	 */
	public function test_conversion_report_keeps_unsafe_svg_as_fallback_diagnostic(): void {
		$report = bfb_conversion_report( '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg>', 'html' );

		$this->assertSame( 1, $report['core_html_blocks'] );
		$this->assertSame( 1, $report['fallback_event_count'] );
		$this->assertSame( 0, $report['materialization_request_count'] );
		$this->assertSame( 'success_with_fallbacks', $report['status'] );
		$this->assertSame( 'core_html_fallback', $report['diagnostics'][0]['code'] ?? null );
		$this->assertSame( 'SVG', $report['fallback_events'][0]['tag_name'] ?? null );
		$this->assertStringContainsString( '<!-- wp:html', $report['serialized_blocks'] );
	}

	/**
	 * Conversion diagnostics should separate warning-only suspicion from explicit fallback evidence.
	 */
	public function test_conversion_diagnostics_classify_warning_only_suspicion(): void {
		$diagnostics = bfb_build_conversion_diagnostics(
			array(
				'total_blocks'          => 1,
				'core_html_blocks'      => 0,
				'fallback_event_count'  => 0,
				'source_bytes'          => 1400,
				'source_text_bytes'     => 900,
				'converted_text_bytes'  => 44,
				'text_retention_ratio'  => 0.0489,
			)
		);

		$this->assertSame( 'warning_only_suspicion', $diagnostics['status'] );
		$this->assertSame( 'possible_text_loss', $diagnostics['diagnostics'][0]['code'] ?? null );
		$this->assertSame( 'warning', $diagnostics['diagnostics'][0]['severity'] ?? null );
		$this->assertStringContainsString( 'do not work around it by manually authoring wp:html blocks', $diagnostics['agent_guidance'] );
	}

	/**
	 * Empty block output should remain an error, not a softened warning.
	 */
	public function test_conversion_diagnostics_classify_failed_conversion(): void {
		$diagnostics = bfb_build_conversion_diagnostics(
			array(
				'total_blocks'          => 0,
				'core_html_blocks'      => 0,
				'fallback_event_count'  => 0,
				'source_bytes'          => 20,
				'source_text_bytes'     => 12,
				'converted_text_bytes'  => 0,
				'text_retention_ratio'  => 0.0,
			)
		);

		$this->assertSame( 'failed', $diagnostics['status'] );
		$this->assertSame( 'conversion_failed', $diagnostics['diagnostics'][0]['code'] ?? null );
		$this->assertSame( 'error', $diagnostics['diagnostics'][0]['severity'] ?? null );
	}

	/**
	 * Blocks should render to HTML through WordPress' real render_block() path.
	 */
	public function test_blocks_to_html_renders_static_and_dynamic_blocks(): void {
		$static_blocks = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Rendered Heading</h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p class="wp-block-paragraph">Rendered paragraph.</p><!-- /wp:paragraph -->';

		$html = bfb_convert( $static_blocks, 'blocks', 'html' );
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Rendered Heading</h1>', $html );
		$this->assertStringContainsString( '<p class="wp-block-paragraph">Rendered paragraph.</p>', $html );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Dynamic BFB Post',
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $post_id );

		$dynamic = bfb_convert( '<!-- wp:latest-posts {"postsToShow":1} /-->', 'blocks', 'html' );
		$this->assertStringContainsString( 'wp-block-latest-posts', $dynamic );
		$this->assertStringContainsString( 'Dynamic BFB Post', $dynamic );
	}

	/**
	 * Blocks should render to markdown through the read-side markdown adapter.
	 */
	public function test_blocks_to_markdown_covers_structural_elements_and_lossy_expectations(): void {
		$blocks = ''
			. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Markdown Heading</h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Paragraph with <strong>bold</strong>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
			. '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quote text</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->'
			. '<!-- wp:code --><pre class="wp-block-code language-php"><code>echo &quot;hi&quot;;</code></pre><!-- /wp:code -->'
			. '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>Name</td><td>BFB</td></tr></tbody></table></figure><!-- /wp:table -->';

		$markdown = bfb_convert( $blocks, 'blocks', 'markdown' );

		$this->assertStringContainsString( '# Markdown Heading', $markdown );
		$this->assertStringContainsString( 'Paragraph with **bold**.', $markdown );
		$this->assertStringContainsString( '- One', $markdown );
		$this->assertStringContainsString( '> Quote text', $markdown );
		$this->assertStringContainsString( '```php', $markdown );
		$this->assertStringContainsString( 'echo "hi";', $markdown );
		$this->assertStringContainsString( '| Name | BFB |', $markdown );
	}

	/**
	 * Custom blocks convert to markdown from rendered front-end HTML, not from hidden attrs.
	 */
	public function test_custom_blocks_to_markdown_uses_rendered_html_contract(): void {
		$semantic_block = 'bfb/semantic-markdown-fixture';
		$empty_block    = 'bfb/empty-markdown-fixture';

		register_block_type(
			$semantic_block,
			array(
				'render_callback' => static function (): string {
					return '<article><h2>Custom block heading</h2><p>Front-end copy.</p><ul><li>Rendered item</li></ul></article>';
				},
			)
		);
		register_block_type(
			$empty_block,
			array(
				'render_callback' => static function (): string {
					return '';
				},
			)
		);

		try {
			$semantic_markdown = bfb_convert( '<!-- wp:bfb/semantic-markdown-fixture /-->', 'blocks', 'markdown' );
			$this->assertStringContainsString( '## Custom block heading', $semantic_markdown );
			$this->assertStringContainsString( 'Front-end copy.', $semantic_markdown );
			$this->assertStringContainsString( '- Rendered item', $semantic_markdown );

			$empty_markdown = bfb_convert( '<!-- wp:bfb/empty-markdown-fixture {"title":"Hidden attr title"} /-->', 'blocks', 'markdown' );
			$this->assertSame( '', $empty_markdown );
			$this->assertStringNotContainsString( 'Hidden attr title', $empty_markdown );
		} finally {
			unregister_block_type( $semantic_block );
			unregister_block_type( $empty_block );
		}
	}

	/**
	 * Non-block formats should compose through the block pivot in both directions.
	 */
	public function test_composition_paths_route_through_blocks_pivot(): void {
		$markdown_to_html = bfb_convert( "# Composed Heading\n\n> Composed quote", 'markdown', 'html' );
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Composed Heading</h1>', $markdown_to_html );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote', $markdown_to_html );
		$this->assertStringContainsString( 'Composed quote', $markdown_to_html );

		$html_to_markdown = bfb_convert( '<h1>HTML Heading</h1><blockquote><p>HTML quote</p></blockquote>', 'html', 'markdown' );
		$this->assertStringContainsString( '# HTML Heading', $html_to_markdown );
		$this->assertStringContainsString( '> HTML quote', $html_to_markdown );
	}

	/**
	 * Public API conversion matrix should cover every supported direction.
	 */
	public function test_public_conversion_matrix_covers_supported_format_directions(): void {
		$html = <<<HTML
<h2>Matrix Heading</h2>
<p>Paragraph with <strong>bold</strong>, <em>emphasis</em>, and <a href="https://example.com">example link</a>.</p>
<ul><li>First item</li><li>Second item</li></ul>
<blockquote><p>Quoted HTML</p></blockquote>
<pre><code class="language-php">echo "matrix";</code></pre>
<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>BFB</td><td>Matrix</td></tr></tbody></table>
<a class="download" href="https://example.com/report.pdf">Download report</a>
<figure><img src="https://example.com/image.jpg" alt="Example"><figcaption>Media caption</figcaption></figure>
HTML;

		$markdown = <<<MARKDOWN
# Markdown Matrix

Paragraph with **bold**, *emphasis*, and [example link](https://example.com).

- First item
- Second item

> Quoted markdown

```php
echo "matrix";
```

| Name | Value |
| ---- | ----- |
| BFB  | Matrix |
MARKDOWN;

		$blocks = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Block Matrix</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Block paragraph with <strong>bold</strong>, <em>emphasis</em>, and <a href="https://example.com">example link</a>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>First block item</li><!-- /wp:list-item --><li>Second block item</li></ul><!-- /wp:list -->'
			. '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted blocks</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->'
			. '<!-- wp:code --><pre class="wp-block-code language-php"><code>echo &quot;matrix&quot;;</code></pre><!-- /wp:code -->'
			. '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>Name</td><td>Value</td></tr><tr><td>BFB</td><td>Matrix</td></tr></tbody></table></figure><!-- /wp:table -->';

		$matrix = array(
			'html -> blocks'    => array(
				'from'     => 'html',
				'to'       => 'blocks',
				'content'  => $html,
				'contains' => array( '<!-- wp:heading', '<!-- wp:paragraph', '<!-- wp:list', '<!-- wp:quote', '<!-- wp:code', '<!-- wp:table', '<!-- wp:image' ),
			),
			'markdown -> blocks' => array(
				'from'     => 'markdown',
				'to'       => 'blocks',
				'content'  => $markdown,
				'contains' => array( '<!-- wp:heading', '<!-- wp:paragraph', '<!-- wp:list', '<!-- wp:quote', '<!-- wp:code', '<!-- wp:table' ),
			),
			'blocks -> html'    => array(
				'from'     => 'blocks',
				'to'       => 'html',
				'content'  => $blocks,
				'contains' => array( '<h2 class="wp-block-heading">Block Matrix</h2>', '<strong>bold</strong>', '<blockquote class="wp-block-quote', 'Quoted blocks', '<table>' ),
			),
			'blocks -> markdown' => array(
				'from'     => 'blocks',
				'to'       => 'markdown',
				'content'  => $blocks,
				'contains' => array( '## Block Matrix', '**bold**', '*emphasis*', '[example link](https://example.com)', '> Quoted blocks', '```php', 'echo "matrix";', '| Name | Value |' ),
			),
			'html -> markdown' => array(
				'from'     => 'html',
				'to'       => 'markdown',
				'content'  => $html,
				'contains' => array( '## Matrix Heading', '**bold**', '*emphasis*', '[example link](https://example.com)', '> Quoted HTML', 'echo "matrix";', '| Name | Value |' ),
			),
			'markdown -> html' => array(
				'from'     => 'markdown',
				'to'       => 'html',
				'content'  => $markdown,
				'contains' => array( '<h1 class="wp-block-heading">Markdown Matrix</h1>', '<strong>bold</strong>', '<em>emphasis</em>', '<a href="https://example.com">example link</a>', '<blockquote class="wp-block-quote', '<code>echo &quot;matrix&quot;;', '<table>' ),
			),
		);

		foreach ( $matrix as $label => $case ) {
			$output = bfb_convert( $case['content'], $case['from'], $case['to'] );

			$this->assertNotSame( '', $output, "{$label} should produce output." );
			$this->assert_output_contains_all( $label, $output, $case['contains'] );
		}

		$file_blocks = bfb_convert( '<a href="https://example.com/report.pdf">Download report</a>', 'html', 'blocks' );
		$this->assertStringContainsString( '<!-- wp:file', $file_blocks, 'HTML -> blocks should cover link-like file download transforms.' );

		$malformed_mixed_blocks = '<!-- wp:heading --><h2>AI Heading</h2><!-- /wp:heading -->'
			. "\n# Markdown outside serialized blocks\n"
			. '<!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->';
		$normalized             = bfb_normalize( $malformed_mixed_blocks, 'blocks' );

		$this->assertTrue( is_wp_error( $normalized ), 'Malformed mixed AI-authored block-ish input should fail declared block normalization.' );
		$this->assertInstanceOf( WP_Error::class, $normalized );
		$this->assertSame( 'bfb_blocks_mixed_content', $normalized->get_error_code() );
	}

	/**
	 * Convert content into parsed blocks through BFB's public API.
	 *
	 * @param string $content Source content.
	 * @param string $from    Source format.
	 * @return array<int, array<string, mixed>> Parsed block list.
	 */
	private function blocks_from( string $content, string $from ): array {
		$serialized = bfb_convert( $content, $from, 'blocks' );
		$this->assertNotSame( '', $serialized, "{$from} conversion should produce serialized blocks." );

		return parse_blocks( $serialized );
	}

	/**
	 * Load a Markdown fixture body.
	 *
	 * @param string $file Fixture filename.
	 * @return string Fixture contents.
	 */
	private function markdown_fixture( string $file ): string {
		$path = __DIR__ . '/fixtures/markdown/' . $file;
		$this->assertFileExists( $path );

		$contents = file_get_contents( $path );
		$this->assertIsString( $contents );

		return $contents;
	}

	/**
	 * Return block names from a parsed block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, string> Block names.
	 */
	private function flatten_blocks( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = $block['blockName'];
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Assert every expected substring appears in a conversion result.
	 *
	 * @param string        $label    Conversion label.
	 * @param string        $output   Conversion output.
	 * @param array<string> $expected Expected substrings.
	 */
	private function assert_output_contains_all( string $label, string $output, array $expected ): void {
		foreach ( $expected as $needle ) {
			$this->assertStringContainsString(
				$needle,
				$output,
				"{$label} should contain {$needle}. Output preview: " . substr( $output, 0, 500 )
			);
		}
	}

	/**
	 * Ensure optional core blocks exist in the minimal Playground test registry.
	 *
	 * @param string $name Block name.
	 */
	private function ensure_block_registered( string $name ): void {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( $name ) ) {
			return;
		}

		register_block_type( $name, array() );
	}
}
