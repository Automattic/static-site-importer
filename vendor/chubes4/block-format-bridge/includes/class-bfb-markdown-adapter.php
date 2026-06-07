<?php
/**
 * Markdown format adapter.
 *
 * Write side (`to_blocks()`):
 *   Runs CommonMark + GFM via league/commonmark to convert the markdown
 *   source to HTML, then routes the HTML through the registered HTML
 *   adapter to land in block form.
 *
 * Read side (`from_blocks()`):
 *   Renders blocks → HTML via `do_blocks()`, then converts HTML → markdown
 *   via league/html-to-markdown. Both libraries are vendor-prefixed under
 *   the `BlockFormatBridge\Vendor` namespace by the build pipeline; the
 *   adapter prefers the prefixed namespace and falls back to unprefixed
 *   (dev-mode `composer install` without the build step).
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markdown ↔ Blocks adapter.
 */
class BFB_Markdown_Adapter implements BFB_Format_Adapter {

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'markdown';
	}

	/**
	 * @inheritDoc
	 */
	public function to_blocks( string $content, array $options = array() ): array {
		if ( '' === $content ) {
			return array();
		}

		/**
		 * Pre-process raw markdown before it reaches CommonMark.
		 *
		 * Useful for opinionated transformations that the bridge should
		 * not encode itself (e.g. linkifying bare domain URLs to
		 * `https://`, normalising smart quotes, etc.).
		 *
		 * @since 0.3.0
		 *
		 * @param string $markdown Markdown source.
		 */
		$content = (string) apply_filters( 'bfb_markdown_input', $content );

		$html = $this->markdown_to_html( $content );
		if ( '' === $html ) {
			return array();
		}

		$html_adapter = BFB_Adapter_Registry::get( 'html' );
		if ( ! $html_adapter ) {
			return array();
		}

		return $html_adapter->to_blocks( $html, $options );
	}

	/**
	 * @inheritDoc
	 *
	 * Renders blocks → HTML (via `do_blocks()` + `serialize_blocks()`)
	 * and converts the resulting HTML to markdown via
	 * league/html-to-markdown.
	 *
	 * Dynamic blocks render through their PHP callback, so server-side
	 * blocks (latest-posts, navigation, query loop, etc.) appear in the
	 * markdown as their rendered HTML output rather than block-comment
	 * markup.
	 *
	 * @param array $blocks Block array (parse_blocks() shape).
	 * @return string Markdown representation. Empty string on failure.
	 */
	public function from_blocks( array $blocks, array $options = array() ): string {
		unset( $options );

		if ( empty( $blocks ) ) {
			return '';
		}

		// Render dynamic blocks through their server-side callbacks, then
		// pass the rendered HTML to the html-to-md converter.
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		// Flatten <pre> blocks so syntax-highlighter wrapper markup
		// (Prism, highlight.js, etc.) doesn't leak into the code fence.
		// Mirrors the approach in roots/post-content-to-markdown.
		$html = (string) preg_replace_callback(
			'#<pre\b[^>]*>(.*?)</pre>#is',
			static function ( array $pre_match ): string {
				$language_class = '';
				if ( preg_match( '/\blanguage-([A-Za-z0-9_-]+)/', $pre_match[0], $language_match ) ) {
					$language_class = ' class="language-' . esc_attr( $language_match[1] ) . '"';
				}

				$inner = html_entity_decode( wp_strip_all_tags( $pre_match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return '<pre><code' . $language_class . '>' . htmlspecialchars( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</code></pre>';
			},
			$html
		);

		$markdown = $this->html_to_markdown( $html );

		// Collapse runs of 3+ newlines (league emits them on nested lists).
		$markdown = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $markdown ) );

		/**
		 * Filters the markdown output produced by the markdown adapter.
		 *
		 * Mirrors `roots/post-content-to-markdown`'s
		 * `post_content_to_markdown/markdown_output` filter so consumers
		 * can swap in a richer implementation without changing the
		 * bridge contract.
		 *
		 * @since 0.2.0
		 *
		 * @param string $markdown Markdown produced by the converter.
		 * @param string $html     Source HTML that was converted.
		 * @param array  $blocks   Original block array.
		 */
		return (string) apply_filters( 'bfb_markdown_output', $markdown, $html, $blocks );
	}

	/**
	 * Render markdown to HTML using league/commonmark with GFM extensions.
	 *
	 * Picks the prefixed namespace from the build distribution when
	 * available, falling back to the unprefixed namespace for dev mode.
	 *
	 * @param string $markdown Raw markdown source.
	 * @return string HTML. Empty string on failure.
	 */
	protected function markdown_to_html( string $markdown ): string {
		$prefixed_converter = '\\BlockFormatBridge\\Vendor\\League\\CommonMark\\GithubFlavoredMarkdownConverter';
		$unprefixed         = '\\League\\CommonMark\\GithubFlavoredMarkdownConverter';

		$class = null;
		if ( class_exists( $prefixed_converter ) ) {
			$class = $prefixed_converter;
		} elseif ( class_exists( $unprefixed ) ) {
			$class = $unprefixed;
		}

		if ( null === $class ) {
			do_action(
				'bfb_diagnostic',
				'commonmark_unavailable',
				'league/commonmark is not loaded; markdown conversion unavailable.',
				array( 'adapter' => 'markdown' )
			);
			return '';
		}

		try {
			$converter = new $class();
			$result    = $converter->convert( $markdown );
			return (string) $result;
		} catch ( \Throwable $e ) {
			do_action(
				'bfb_diagnostic',
				'commonmark_conversion_failed',
				'CommonMark conversion failed.',
				array( 'error' => $e->getMessage() )
			);
			return '';
		}
	}

	/**
	 * Convert HTML to markdown using league/html-to-markdown.
	 *
	 * Picks the prefixed namespace from the build distribution when
	 * available, falling back to the unprefixed namespace for dev mode.
	 *
	 * Default converter options:
	 *   - header_style: 'atx'    (`#` prefix instead of underline)
	 *   - strip_tags:   true     drop unsupported HTML
	 *   - remove_nodes: 'script style'
	 *   - hard_break:   true     `<br>` → newline
	 *
	 * Filterable via `bfb_html_to_markdown_options`.
	 *
	 * @param string $html Source HTML.
	 * @return string Markdown. Empty string on failure.
	 */
	protected function html_to_markdown( string $html ): string {
		$prefixed   = '\\BlockFormatBridge\\Vendor\\League\\HTMLToMarkdown\\HtmlConverter';
		$unprefixed = '\\League\\HTMLToMarkdown\\HtmlConverter';

		$class = null;
		if ( class_exists( $prefixed ) ) {
			$class = $prefixed;
		} elseif ( class_exists( $unprefixed ) ) {
			$class = $unprefixed;
		}

		if ( null === $class ) {
			do_action(
				'bfb_diagnostic',
				'html_to_markdown_unavailable',
				'league/html-to-markdown is not loaded; HTML to markdown conversion unavailable.',
				array( 'adapter' => 'markdown' )
			);
			return '';
		}

		$defaults = array(
			'header_style' => 'atx',
			'strip_tags'   => true,
			'remove_nodes' => 'script style',
			'hard_break'   => true,
		);

		/**
		 * Filters the option array passed to league/html-to-markdown.
		 *
		 * Mirrors `roots/post-content-to-markdown`'s
		 * `post_content_to_markdown/converter_options`.
		 *
		 * @since 0.2.0
		 *
		 * @param array  $options Converter options.
		 * @param string $html    Source HTML.
		 */
		$options = (array) apply_filters( 'bfb_html_to_markdown_options', $defaults, $html );

		try {
			$converter = new $class( $options );

			// Register the league/html-to-markdown TableConverter — it
			// ships with the library but isn't enabled by default. Without
			// it, <table> blocks collapse to inline text.
			$this->register_table_converter( $converter );

			/**
			 * Fires after the html-to-markdown converter has been built but
			 * before it runs. Allows consumers to register additional
			 * league/html-to-markdown Converter implementations on the
			 * converter's environment.
			 *
			 * The converter is the prefixed `HtmlConverter` instance;
			 * consumers should pull `getEnvironment()` and call
			 * `addConverter()` with prefixed Converter classes if they
			 * ship under the same namespace.
			 *
			 * @since 0.3.0
			 *
			 * @param object $converter HtmlConverter instance.
			 */
			do_action( 'bfb_html_to_markdown_converter', $converter );

			return (string) $converter->convert( $html );
		} catch ( \Throwable $e ) {
			do_action(
				'bfb_diagnostic',
				'html_to_markdown_conversion_failed',
				'HTML to markdown conversion failed.',
				array( 'error' => $e->getMessage() )
			);
			return '';
		}
	}

	/**
	 * Register league/html-to-markdown's TableConverter on the converter's
	 * environment.
	 *
	 * Picks the prefixed class first, then unprefixed dev-mode fallback.
	 * Silent no-op if neither class is present (older library versions).
	 *
	 * @param object $converter HtmlConverter instance.
	 * @return void
	 */
	protected function register_table_converter( $converter ): void {
		$prefixed   = '\\BlockFormatBridge\\Vendor\\League\\HTMLToMarkdown\\Converter\\TableConverter';
		$unprefixed = '\\League\\HTMLToMarkdown\\Converter\\TableConverter';

		$class = null;
		if ( class_exists( $prefixed ) ) {
			$class = $prefixed;
		} elseif ( class_exists( $unprefixed ) ) {
			$class = $unprefixed;
		}

		if ( null === $class ) {
			return;
		}

		if ( ! method_exists( $converter, 'getEnvironment' ) ) {
			return;
		}

		try {
			$env = $converter->getEnvironment();
			$env->addConverter( new $class() );
		} catch ( \Throwable $e ) {
			do_action(
				'bfb_diagnostic',
				'table_converter_registration_failed',
				'TableConverter registration failed.',
				array( 'error' => $e->getMessage() )
			);
		}
	}
}
