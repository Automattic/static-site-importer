<?php
/**
 * Static HTML document parser.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts usable fragments from a static HTML document.
 */
class Static_Site_Importer_Document {

	/**
	 * DOM document.
	 *
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * Raw source HTML.
	 *
	 * @var string
	 */
	private string $raw_html;

	/**
	 * Constructor.
	 *
	 * @param string $html Source HTML.
	 */
	public function __construct( string $html ) {
		$this->dom      = new DOMDocument();
		$this->raw_html = $html;

		$previous = libxml_use_internal_errors( true );
		$this->dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	/**
	 * Create a document object from a file.
	 *
	 * @param string $path HTML file path.
	 * @return self|WP_Error
	 */
	public static function from_file( string $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_file', sprintf( 'HTML file is not readable: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local static-site HTML file selected for import.
		$html = file_get_contents( $path );
		if ( false === $html || '' === trim( $html ) ) {
			return new WP_Error( 'static_site_importer_empty_file', sprintf( 'HTML file is empty: %s', $path ) );
		}

		return new self( $html );
	}

	/**
	 * Get the document title.
	 *
	 * @return string
	 */
	public function title(): string {
		$nodes = $this->dom->getElementsByTagName( 'title' );
		if ( $nodes->length > 0 ) {
			return trim( (string) $nodes->item( 0 )->textContent );
		}

		$nodes = $this->dom->getElementsByTagName( 'h1' );
		if ( $nodes->length > 0 ) {
			return trim( (string) $nodes->item( 0 )->textContent );
		}

		return 'Imported Static Site';
	}

	/**
	 * Get CSS from inline style tags.
	 *
	 * @return string
	 */
	public function inline_css(): string {
		$css = array();
		foreach ( $this->dom->getElementsByTagName( 'style' ) as $style ) {
			$css[] = trim( (string) $style->textContent );
		}

		return trim( implode( "\n\n", array_filter( $css ) ) );
	}

	/**
	 * Get linked stylesheet hrefs.
	 *
	 * @return array<int, string>
	 */
	public function stylesheet_hrefs(): array {
		$hrefs = array();
		foreach ( $this->dom->getElementsByTagName( 'link' ) as $link ) {
			$rel  = strtolower( trim( $link->getAttribute( 'rel' ) ) );
			$href = trim( $link->getAttribute( 'href' ) );
			if ( '' === $href || ! str_contains( ' ' . $rel . ' ', ' stylesheet ' ) ) {
				continue;
			}

			$hrefs[] = $href;
		}

		return array_values( array_unique( $hrefs ) );
	}

	/**
	 * Get inline JavaScript from script tags without src attributes.
	 *
	 * @return string
	 */
	public function inline_js(): string {
		$js = array();
		foreach ( $this->dom->getElementsByTagName( 'script' ) as $script ) {
			if ( $script->hasAttribute( 'src' ) ) {
				continue;
			}

			$js[] = trim( (string) $script->textContent );
		}

		return trim( implode( "\n\n", array_filter( $js ) ) );
	}

	/**
	 * Extract site fragments for theme generation.
	 *
	 * @return array{background:string,header:string,main:string,footer:string}
	 */
	public function fragments(): array {
		$selection = $this->compute_selection();

		return array(
			'background' => $selection['fragments']['background'],
			'header'     => $selection['fragments']['header'],
			'main'       => $selection['fragments']['main'],
			'footer'     => $selection['fragments']['footer'],
		);
	}

	/**
	 * Build a structured report describing source-region extraction decisions.
	 *
	 * Reports the selected page body, header, footer, and any meaningful source
	 * regions that were not assigned to header/main/footer/background. Includes
	 * selector paths, source line ranges (when available), and short excerpts so
	 * agents can pinpoint dropped regions without diffing source against output.
	 *
	 * Reporting only — does not affect conversion behavior.
	 *
	 * @return array<string,mixed>
	 */
	public function selection_report(): array {
		$selection = $this->compute_selection();
		$body      = $this->first_element( 'body' );
		$root      = $body instanceof DOMElement ? $body : $this->dom->documentElement;

		$header_node = $selection['header_node'];
		$nav_node    = $selection['nav_node'];
		$footer_node = $selection['footer_node'];
		$main_node   = $selection['main_node'];

		$page_body = $this->describe_page_body( $main_node, $root );

		$extracted_header = null;
		if ( $header_node instanceof DOMElement || $nav_node instanceof DOMElement ) {
			$extracted_header = $this->describe_extracted_header( $header_node, $nav_node, $selection['effective_children'] );
		}

		$extracted_footer = null;
		if ( $footer_node instanceof DOMElement ) {
			$extracted_footer = $this->describe_region( $footer_node, 'footer_element' );
		}

		$unassigned = $this->collect_unassigned_regions( $selection );

		$counts = array(
			'source_landmarks'   => array(
				'main'   => $main_node instanceof DOMElement ? 1 : 0,
				'header' => $this->dom->getElementsByTagName( 'header' )->length,
				'nav'    => $this->dom->getElementsByTagName( 'nav' )->length,
				'footer' => $this->dom->getElementsByTagName( 'footer' )->length,
			),
			'unassigned_regions' => count( $unassigned ),
		);

		return array(
			'page_body'          => $page_body,
			'extracted_header'   => $extracted_header,
			'extracted_footer'   => $extracted_footer,
			'unassigned_regions' => $unassigned,
			'counts'             => $counts,
		);
	}

	/**
	 * Build the shared selection model used by fragments() and selection_report().
	 *
	 * @return array{
	 *     fragments: array{background:string,header:string,main:string,footer:string},
	 *     header_node: ?DOMElement,
	 *     nav_node: ?DOMElement,
	 *     footer_node: ?DOMElement,
	 *     main_node: ?DOMElement,
	 *     effective_children: DOMElement[],
	 *     body_headers: DOMElement[],
	 *     unassigned_children: DOMElement[],
	 *     skipped_children: DOMElement[]
	 * }
	 */
	private function compute_selection(): array {
		$body = $this->first_element( 'body' );
		$root = $body instanceof DOMElement ? $body : $this->dom->documentElement;

		$footer             = $this->first_element( 'footer' );
		$main               = $this->first_element( 'main' );
		$effective_children = $this->effective_root_children( $root, $main );

		$header       = $this->first_plausible_global_header( $effective_children );
		$nav          = $this->first_plausible_global_nav( $effective_children, $header );
		$body_headers = $this->body_content_headers( $effective_children, $header, $nav );

		if ( $header instanceof DOMElement && $this->contains_same_node( $body_headers, $header ) ) {
			$header = null;
		}

		$header_parts = array();
		if ( $nav instanceof DOMElement && $header instanceof DOMElement && $this->is_leading_sibling_in( $effective_children, $nav, $header ) ) {
			$header_parts[] = $this->outer_html( $nav );
		}

		if ( $header instanceof DOMElement ) {
			$header_parts[] = $this->outer_html( $header );
		} elseif ( $nav instanceof DOMElement ) {
			$header_parts[] = $this->outer_html( $nav );
		}

		$header_html = implode( "\n", $header_parts );
		$footer_html = $footer instanceof DOMElement ? $this->outer_html( $footer ) : '';

		$main_parts          = array();
		$background          = array();
		$main_html           = $main instanceof DOMElement ? $this->inner_html( $main ) : '';
		$unassigned_children = array();
		$skipped_children    = array();

		foreach ( $effective_children as $child ) {
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, array( 'style', 'script' ), true ) ) {
				$skipped_children[] = $child;
				continue;
			}

			if ( $this->same_node( $child, $main ) ) {
				$main_parts[] = $main_html;
				continue;
			}

			if ( $this->same_node( $child, $nav ) || $this->same_node( $child, $header ) || $this->same_node( $child, $footer ) ) {
				continue;
			}

			if ( $this->looks_like_background_chrome( $child ) ) {
				$background[] = $this->outer_html( $child );
				continue;
			}

			if ( ! $main instanceof DOMElement || $this->contains_same_node( $body_headers, $child ) ) {
				$main_parts[] = $this->outer_html( $child );
				continue;
			}

			// Direct child of body that is not main, not header/nav/footer chrome,
			// not a body content header, and not background — and a <main> exists
			// elsewhere. Today this child is silently dropped from conversion.
			$unassigned_children[] = $child;
		}

		if ( $main instanceof DOMElement && empty( $main_parts ) ) {
			$main_parts[] = $main_html;
		}

		return array(
			'fragments'           => array(
				'background' => trim( implode( "\n", $background ) ),
				'header'     => trim( $header_html ),
				'main'       => trim( implode( "\n", $main_parts ) ),
				'footer'     => trim( $footer_html ),
			),
			'header_node'         => $header,
			'nav_node'            => $nav,
			'footer_node'         => $footer,
			'main_node'           => $main,
			'effective_children'  => $effective_children,
			'body_headers'        => $body_headers,
			'unassigned_children' => $unassigned_children,
			'skipped_children'    => $skipped_children,
		);
	}

	/**
	 * Resolve the effective list of body-level direct children, unwrapping a
	 * single generic page wrapper that holds pre-main chrome.
	 *
	 * Some static sites wrap pre-main chrome (nav + hero header) inside a generic
	 * `<div class="page">` sibling of `<main>`. Treating that wrapper opaquely
	 * drops the chrome from fragment decomposition. Unwrapping it surfaces the
	 * inner nav/header as effective body-level siblings while leaving any
	 * direct-child main/footer alone.
	 *
	 * @param DOMElement      $root Page root element (typically <body>).
	 * @param DOMElement|null $main Main landmark element if present.
	 * @return DOMElement[]
	 */
	private function effective_root_children( DOMElement $root, ?DOMElement $main ): array {
		$effective = array();

		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( $this->is_unwrappable_page_wrapper( $child, $main ) ) {
				foreach ( iterator_to_array( $child->childNodes ) as $grandchild ) {
					if ( $grandchild instanceof DOMElement ) {
						$effective[] = $grandchild;
					}
				}

				continue;
			}

			$effective[] = $child;
		}

		return $effective;
	}

	/**
	 * Whether a body-level element is a generic page wrapper that should be
	 * unwrapped for fragment decomposition.
	 *
	 * The wrapper must be a plain `<div>` (no semantic landmark of its own),
	 * must not contain `<main>` (so we never flatten main's container), must
	 * not look like background chrome, and must contain at least one
	 * meaningful pre-main landmark (`<nav>` or `<header>`). This is intentionally
	 * narrow: it only unwraps wrappers that demonstrably hold chrome the
	 * decomposition would otherwise drop.
	 *
	 * @param DOMElement      $element Candidate wrapper.
	 * @param DOMElement|null $main    Main landmark element if present.
	 * @return bool
	 */
	private function is_unwrappable_page_wrapper( DOMElement $element, ?DOMElement $main ): bool {
		if ( 'div' !== strtolower( $element->tagName ) ) {
			return false;
		}

		if ( $main instanceof DOMElement && $this->is_descendant_of( $main, $element ) ) {
			return false;
		}

		if ( $this->looks_like_background_chrome( $element ) ) {
			return false;
		}

		$has_landmark = $element->getElementsByTagName( 'nav' )->length > 0
			|| $element->getElementsByTagName( 'header' )->length > 0;

		return $has_landmark;
	}

	/**
	 * Find the first tag in the document.
	 *
	 * @param string $tag Tag name.
	 * @return DOMElement|null
	 */
	private function first_element( string $tag ): ?DOMElement {
		$nodes = $this->dom->getElementsByTagName( $tag );
		$node  = $nodes->length > 0 ? $nodes->item( 0 ) : null;

		return $node instanceof DOMElement ? $node : null;
	}

	/**
	 * Find a header that is plausible reusable site chrome.
	 *
	 * @param DOMElement[] $effective_children Effective body-level direct children.
	 * @return DOMElement|null
	 */
	private function first_plausible_global_header( array $effective_children ): ?DOMElement {
		foreach ( $this->dom->getElementsByTagName( 'header' ) as $header ) {
			if ( $this->is_plausible_global_header( $effective_children, $header ) ) {
				return $header;
			}
		}

		return null;
	}

	/**
	 * Find a nav that is plausible reusable site chrome.
	 *
	 * @param DOMElement[]    $effective_children Effective body-level direct children.
	 * @param DOMElement|null $header             Selected header element.
	 * @return DOMElement|null
	 */
	private function first_plausible_global_nav( array $effective_children, ?DOMElement $header ): ?DOMElement {
		foreach ( $this->dom->getElementsByTagName( 'nav' ) as $nav ) {
			if ( $header instanceof DOMElement && $this->is_descendant_of( $nav, $header ) ) {
				return $nav;
			}

			if ( $this->contains_same_node( $effective_children, $nav ) || $this->has_global_chrome_signal( $nav ) ) {
				return $nav;
			}
		}

		return null;
	}

	/**
	 * Check whether a header looks like global site chrome rather than section content.
	 *
	 * @param DOMElement[] $effective_children Effective body-level direct children.
	 * @param DOMElement   $header             Header element.
	 * @return bool
	 */
	private function is_plausible_global_header( array $effective_children, DOMElement $header ): bool {
		if ( $this->has_global_chrome_signal( $header ) ) {
			return true;
		}

		return $this->contains_same_node( $effective_children, $header );
	}

	/**
	 * Collect effective body-level headers that should remain in page content.
	 *
	 * @param DOMElement[]    $effective_children Effective body-level direct children.
	 * @param DOMElement|null $header             Selected header element.
	 * @param DOMElement|null $nav                Selected nav element.
	 * @return DOMElement[]
	 */
	private function body_content_headers( array $effective_children, ?DOMElement $header, ?DOMElement $nav ): array {
		$body_headers = array();
		$seen_header  = false;

		foreach ( $effective_children as $child ) {
			if ( 'header' !== strtolower( $child->tagName ) ) {
				continue;
			}

			if ( $header instanceof DOMElement && $this->same_node( $child, $header ) ) {
				$seen_header = true;

				if ( $nav instanceof DOMElement && ! $this->has_global_chrome_signal( $child ) && $this->is_leading_sibling_in( $effective_children, $nav, $child ) ) {
					$body_headers[] = $child;
				}

				continue;
			}

			if ( $seen_header ) {
				$body_headers[] = $child;
			}
		}

		return $body_headers;
	}

	/**
	 * Check whether an array contains a DOM node.
	 *
	 * @param DOMElement[] $nodes     Nodes to search.
	 * @param DOMElement   $candidate Candidate element.
	 * @return bool
	 */
	private function contains_same_node( array $nodes, DOMElement $candidate ): bool {
		foreach ( $nodes as $node ) {
			if ( $this->same_node( $node, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an element carries explicit global-chrome signals.
	 *
	 * @param DOMElement $element Element.
	 * @return bool
	 */
	private function has_global_chrome_signal( DOMElement $element ): bool {
		$role = strtolower( trim( $element->getAttribute( 'role' ) ) );
		if ( 'banner' === $role || 'navigation' === $role ) {
			return true;
		}

		$classes = strtolower( $element->getAttribute( 'class' ) );
		if ( preg_match( '/(^|[\s_-])(site-header|site-nav|navbar|navigation|masthead|brand|branding)([\s_-]|$)/', $classes ) ) {
			return true;
		}

		return $element->getElementsByTagName( 'nav' )->length > 0;
	}

	/**
	 * Check whether a candidate is contained by another element.
	 *
	 * @param DOMElement $candidate Candidate element.
	 * @param DOMElement $ancestor  Ancestor element.
	 * @return bool
	 */
	private function is_descendant_of( DOMElement $candidate, DOMElement $ancestor ): bool {
		$node = $candidate->parentNode;
		while ( $node instanceof DOMNode ) {
			if ( $node->isSameNode( $ancestor ) ) {
				return true;
			}

			$node = $node->parentNode;
		}

		return false;
	}

	/**
	 * Serialize a DOM node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function outer_html( DOMNode $node ): string {
		$html = $this->dom->saveHTML( $node );
		return false === $html ? '' : $html;
	}

	/**
	 * Serialize a DOM element's child nodes.
	 *
	 * @param DOMElement $element Element.
	 * @return string
	 */
	private function inner_html( DOMElement $element ): string {
		$html = array();
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			$html[] = $this->outer_html( $child );
		}

		return implode( '', $html );
	}

	/**
	 * Compare two DOM elements by identity.
	 *
	 * @param DOMElement      $left  Left node.
	 * @param DOMElement|null $right Right node.
	 * @return bool
	 */
	private function same_node( DOMElement $left, ?DOMElement $right ): bool {
		return $right instanceof DOMElement && $left->isSameNode( $right );
	}

	/**
	 * Check whether one element appears before another in an ordered effective sibling list.
	 *
	 * @param DOMElement[] $effective_children Ordered list of effective siblings.
	 * @param DOMElement   $before             Candidate leading element.
	 * @param DOMElement   $after              Candidate following element.
	 * @return bool
	 */
	private function is_leading_sibling_in( array $effective_children, DOMElement $before, DOMElement $after ): bool {
		foreach ( $effective_children as $child ) {
			if ( $child->isSameNode( $before ) ) {
				return true;
			}

			if ( $child->isSameNode( $after ) ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Detect decorative wrappers that should stay outside main content.
	 *
	 * @param DOMElement $node Node.
	 * @return bool
	 */
	private function looks_like_background_chrome( DOMElement $node ): bool {
		$class = ' ' . $node->getAttribute( 'class' ) . ' ';

		return str_contains( $class, ' bg-' ) || str_contains( $class, ' orb ' ) || str_contains( $class, ' grid-' );
	}

	/**
	 * Describe the page body selection for the import report.
	 *
	 * @param ?DOMElement $main Selected <main> element, if any.
	 * @param DOMElement  $root Page root element.
	 * @return array<string,mixed>
	 */
	private function describe_page_body( ?DOMElement $main, DOMElement $root ): array {
		if ( $main instanceof DOMElement ) {
			return array(
				'mode'       => 'semantic_main',
				'selector'   => $this->selector_path( $main ),
				'tag'        => strtolower( $main->tagName ),
				'line_range' => $this->element_line_range( $main ),
				'excerpt'    => $this->excerpt( $main ),
			);
		}

		return array(
			'mode'       => 'body_root_fallback',
			'selector'   => $this->selector_path( $root ),
			'tag'        => strtolower( $root->tagName ),
			'line_range' => $this->element_line_range( $root ),
			'excerpt'    => $this->excerpt( $root ),
		);
	}

	/**
	 * Describe the extracted header selection for the import report.
	 *
	 * @param ?DOMElement $header Selected header element.
	 * @param ?DOMElement $nav    Selected nav element.
	 * @param DOMElement[] $effective_children Ordered list of effective siblings.
	 * @return array<string,mixed>
	 */
	private function describe_extracted_header( ?DOMElement $header, ?DOMElement $nav, array $effective_children ): array {
		$mode = 'none';
		if ( $header instanceof DOMElement && $nav instanceof DOMElement && $this->is_leading_sibling_in( $effective_children, $nav, $header ) ) {
			$mode = 'leading_nav_plus_header';
		} elseif ( $header instanceof DOMElement ) {
			$mode = 'header_element';
		} elseif ( $nav instanceof DOMElement ) {
			$mode = 'nav_element_only';
		}

		$parts = array();
		if ( $nav instanceof DOMElement ) {
			$parts[] = $this->describe_region( $nav, 'nav' );
		}
		if ( $header instanceof DOMElement ) {
			$parts[] = $this->describe_region( $header, 'header' );
		}

		return array(
			'mode'  => $mode,
			'parts' => $parts,
		);
	}

	/**
	 * Describe a single region with selector path, line range, and excerpt.
	 *
	 * @param DOMElement $node   Node.
	 * @param string     $reason Reason or role label.
	 * @return array<string,mixed>
	 */
	private function describe_region( DOMElement $node, string $reason ): array {
		return array(
			'role'       => $reason,
			'tag'        => strtolower( $node->tagName ),
			'selector'   => $this->selector_path( $node ),
			'line_range' => $this->element_line_range( $node ),
			'excerpt'    => $this->excerpt( $node ),
		);
	}

	/**
	 * Collect meaningful unassigned source regions for the import report.
	 *
	 * Looks at direct body children that the extractor dropped (because <main>
	 * exists and they are not chrome) and reports each one. When a dropped
	 * wrapper element contains nested landmarks (nav, header, hero, main, etc.)
	 * those nested regions are reported as well so agents can see exactly which
	 * source regions were excluded.
	 *
	 * @param array<string,mixed> $selection Shared selection model.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_unassigned_regions( array $selection ): array {
		$regions   = array();
		$main_node = $selection['main_node'];
		$effective_children = $selection['effective_children'];
		foreach ( $selection['unassigned_children'] as $child ) {
			$position  = ( $main_node instanceof DOMElement && $this->is_leading_sibling_in( $effective_children, $child, $main_node ) ) ? 'before_main' : 'after_main';
			$regions[] = array(
				'role'       => 'unassigned_body_child',
				'reason'     => 'pre_main_or_post_main_sibling_not_assigned',
				'position'   => $position,
				'tag'        => strtolower( $child->tagName ),
				'selector'   => $this->selector_path( $child ),
				'line_range' => $this->element_line_range( $child ),
				'excerpt'    => $this->excerpt( $child ),
			);

			foreach ( $this->find_meaningful_descendants( $child ) as $nested ) {
				$regions[] = array(
					'role'       => 'unassigned_nested_landmark',
					'reason'     => 'inside_unassigned_body_child',
					'position'   => $position,
					'tag'        => strtolower( $nested->tagName ),
					'selector'   => $this->selector_path( $nested ),
					'line_range' => $this->element_line_range( $nested ),
					'excerpt'    => $this->excerpt( $nested ),
				);
			}
		}

		return $regions;
	}

	/**
	 * Find meaningful descendant landmarks inside an unassigned body child.
	 *
	 * Returns the first matching nav, header, main, footer, and hero-like
	 * descendants so the report can flag each dropped landmark.
	 *
	 * @param DOMElement $node Wrapper element.
	 * @return DOMElement[]
	 */
	private function find_meaningful_descendants( DOMElement $node ): array {
		$found = array();
		$seen  = array();

		$tags = array( 'nav', 'header', 'main', 'footer', 'aside', 'section' );
		foreach ( $tags as $tag ) {
			$nodes = $node->getElementsByTagName( $tag );
			if ( 0 === $nodes->length ) {
				continue;
			}
			foreach ( $nodes as $candidate ) {
				if ( 'section' === $tag && ! $this->looks_like_hero_or_landmark( $candidate ) ) {
					continue;
				}

				$key = spl_object_hash( $candidate );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$found[]      = $candidate;

				if ( in_array( $tag, array( 'nav', 'header', 'main', 'footer' ), true ) ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Check whether a section looks like a hero-style landmark worth reporting.
	 *
	 * @param DOMElement $node Section element.
	 * @return bool
	 */
	private function looks_like_hero_or_landmark( DOMElement $node ): bool {
		$class = strtolower( ' ' . $node->getAttribute( 'class' ) . ' ' );
		$id    = strtolower( $node->getAttribute( 'id' ) );

		if ( '' !== $id && in_array( $id, array( 'top', 'hero', 'masthead' ), true ) ) {
			return true;
		}

		return preg_match( '/(^|[\s_-])(hero|masthead|jumbotron|banner|intro|cta)([\s_-]|$)/', $class ) === 1;
	}

	/**
	 * Build a CSS-like selector path from an element to its document root.
	 *
	 * Used in the import report so dropped regions can be matched against the
	 * raw source HTML.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function selector_path( DOMElement $node ): string {
		$parts   = array();
		$current = $node;
		while ( $current instanceof DOMElement ) {
			$tag = strtolower( $current->tagName );
			$id  = trim( $current->getAttribute( 'id' ) );
			if ( '' !== $id ) {
				$tag .= '#' . $id;
			} else {
				$class = trim( $current->getAttribute( 'class' ) );
				if ( '' !== $class ) {
					$first = preg_split( '/\s+/', $class )[0] ?? '';
					if ( '' !== $first ) {
						$tag .= '.' . $first;
					}
				}
			}

			array_unshift( $parts, $tag );
			$parent  = $current->parentNode;
			$current = $parent instanceof DOMElement ? $parent : null;
		}

		return implode( ' > ', $parts );
	}

	/**
	 * Compute a 1-indexed line range for an element based on the raw source HTML.
	 *
	 * Returns null when line numbers are not available. The end line is best-effort:
	 * it locates the matching close tag in the raw HTML starting from the open
	 * line, falling back to the open line when the close tag cannot be found.
	 *
	 * @param DOMElement $node Element.
	 * @return array{0:int,1:int}|null
	 */
	private function element_line_range( DOMElement $node ): ?array {
		$start = $node->getLineNo();
		if ( $start <= 0 ) {
			return null;
		}

		$end = $this->locate_close_line( $node, $start );
		if ( $end < $start ) {
			$end = $start;
		}

		return array( $start, $end );
	}

	/**
	 * Best-effort lookup of the line number where an element's open tag closes.
	 *
	 * Self-closing or void elements return the start line. Otherwise scans the
	 * raw HTML for the matching close tag, accounting for nested same-tag elements.
	 *
	 * @param DOMElement $node  Element.
	 * @param int        $start Start line.
	 * @return int
	 */
	private function locate_close_line( DOMElement $node, int $start ): int {
		$tag   = strtolower( $node->tagName );
		$voids = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
		if ( in_array( $tag, $voids, true ) ) {
			return $start;
		}

		$lines = preg_split( "/\r\n|\r|\n/", $this->raw_html );
		if ( ! is_array( $lines ) ) {
			return $start;
		}

		$total = count( $lines );
		if ( $start - 1 >= $total ) {
			return $start;
		}

		$open_re  = '/<' . preg_quote( $tag, '/' ) . '(?=[\s>\/])/i';
		$close_re = '/<\/' . preg_quote( $tag, '/' ) . '\s*>/i';

		$depth = 0;
		for ( $i = $start - 1; $i < $total; $i++ ) {
			$line   = $lines[ $i ];
			$opens  = preg_match_all( $open_re, $line );
			$closes = preg_match_all( $close_re, $line );

			if ( false !== $opens ) {
				$depth += (int) $opens;
			}
			if ( false !== $closes ) {
				$depth -= (int) $closes;
			}

			if ( $depth <= 0 ) {
				return $i + 1;
			}
		}

		return $start;
	}

	/**
	 * Build a short text excerpt for an element to aid manual review.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function excerpt( DOMElement $node ): string {
		$text = preg_replace( '/\s+/', ' ', (string) $node->textContent ) ?? '';
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$limit = 160;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
			return rtrim( mb_substr( $text, 0, $limit ) ) . '…';
		}

		if ( strlen( $text ) > $limit ) {
			return rtrim( substr( $text, 0, $limit ) ) . '…';
		}

		return $text;
	}
}
