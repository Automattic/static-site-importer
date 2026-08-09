<?php
/**
 * SVG artwork sanitizer and trusted renderer.
 *
 * Single source of truth for sanitizing inline SVG artwork that cannot be
 * represented by core/image because it depends on inline defs, gradients,
 * filters, masks, symbols, clip paths, ID references, or document-context
 * styling. The companion plugin scaffolder emits a render.php that delegates
 * to sanitize() so the runtime boundary is the only place an SVG can be
 * trusted.
 *
 * The sanitizer is conservative on purpose: every element and attribute
 * passes an explicit allow-list. The pass strips <script>, <style>,
 * <foreignObject>, animation elements, on* event handlers, javascript:
 * URLs, external href/xlink:href, and unsafe url(...) values from inline
 * style. Local #id references, gradients, masks, clip paths, filters, and
 * symbols are preserved when their ID survives the sanitization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Svg_Artwork' ) ) {

	/**
	 * SVG artwork helpers used by generated companion-block render output.
	 */
	final class Static_Site_Importer_Svg_Artwork {

		/**
		 * Maximum root-svg byte size accepted for sanitization. Anything
		 * larger is rejected outright so resource exhaustion is bounded.
		 */
		public const MAX_INPUT_BYTES = 524288;

		/**
		 * Maximum absolute value accepted in a single viewBox component.
		 * Caps render-side numeric memory.
		 */
		public const MAX_VIEWBOX_COMPONENT = 1.0e6;

		/**
		 * Maximum recursion depth for prune_subtree. Bounded so a
		 * pathological deeply-nested SVG cannot blow the stack.
		 */
		public const MAX_PRUNE_DEPTH = 64;

		/**
		 * Allowed SVG element local names.
		 *
		 * @var string[]
		 */
		private static $allowed_elements = array(
			'svg',
			'defs',
			'symbol',
			'use',
			'g',
			'title',
			'desc',
			'metadata',
			'path',
			'circle',
			'ellipse',
			'rect',
			'line',
			'polyline',
			'polygon',
			'clippath',
			'mask',
			'pattern',
			'lineargradient',
			'radialgradient',
			'stop',
			'filter',
			'fecolormatrix',
			'fecomponenttransfer',
			'fecomposite',
			'feconvolvematrix',
			'fediffuselighting',
			'fedisplacementmap',
			'fedropshadow',
			'fedistantlight',
			'feflood',
			'fefunca',
			'fefuncr',
			'fefuncb',
			'fefuncg',
			'fegaussianblur',
			'feimage',
			'femerge',
			'femergenode',
			'femorphology',
			'feoffset',
			'fepointlight',
			'fespecularlighting',
			'fespotlight',
			'feturbulence',
			'marker',
			'text',
			'tspan',
			'textpath',
			'image',
		);

		/**
		 * Attributes that are always safe regardless of element. Event
		 * handlers, javascript: URLs, and any href that does not point to
		 * a local fragment are stripped separately.
		 *
		 * @var string[]
		 */
		private static $allowed_attributes = array(
			'id',
			'class',
			'lang',
			'role',
			'aria-hidden',
			'aria-label',
			'aria-labelledby',
			'aria-describedby',
			'xml:lang',
			'xml:space',
			'xmlns',
			'style',
			'transform',
			'color',
			'fill',
			'fill-opacity',
			'fill-rule',
			'stroke',
			'stroke-width',
			'stroke-linecap',
			'stroke-linejoin',
			'stroke-miterlimit',
			'stroke-dasharray',
			'stroke-dashoffset',
			'stroke-opacity',
			'marker-start',
			'marker-mid',
			'marker-end',
			'opacity',
			'clip-path',
			'clip-rule',
			'mask',
			'filter',
			'font-family',
			'font-size',
			'font-style',
			'font-weight',
			'letter-spacing',
			'word-spacing',
			'text-anchor',
			'dominant-baseline',
			'alignment-baseline',
			'baseline-shift',
			'display',
			'visibility',
			'pointer-events',
			'paint-order',
			'vector-effect',
			'shape-rendering',
			'color-interpolation',
			'color-interpolation-filters',
			'width',
			'height',
			'x',
			'y',
			'x1',
			'y1',
			'x2',
			'y2',
			'cx',
			'cy',
			'r',
			'rx',
			'ry',
			'd',
			'points',
			'pathlength',
			'offset',
			'stop-color',
			'stop-opacity',
			'spreadmethod',
			'gradienttransform',
			'gradientunits',
			'patterntransform',
			'patternunits',
			'patterncontentunits',
			'clippathunits',
			'maskunits',
			'maskcontentunits',
			'filterunits',
			'primitiveunits',
			'in',
			'in2',
			'result',
			'stddeviation',
			'flood-color',
			'flood-opacity',
			'lighting-color',
			'k1',
			'k2',
			'k3',
			'k4',
			'kernelmatrix',
			'kernelunitlength',
			'preservealpha',
			'operator',
			'order',
			'divisor',
			'bias',
			'radius',
			'edgemode',
			'preserveaspectratio',
			'viewbox',
			'dx',
			'dy',
			'rotate',
			'startoffset',
			'method',
			'spacing',
			'lengthadjust',
			'textlength',
			'markerwidth',
			'markerheight',
			'markerunits',
			'orient',
			'refx',
			'refy',
			'href',
			'xlink:href',
			'pathlength',
			'crossorigin',
			'decoding',
		);

		/**
		 * Attributes whose values must be a local fragment (#id).
		 *
		 * @var string[]
		 */
		private static $fragment_only_attributes = array(
			'href',
			'xlink:href',
		);

		/**
		 * Sanitize an inline SVG string.
		 *
		 * Returns an empty string when the input is too large, malformed,
		 * not a root <svg> element, or stripped of all drawable content.
		 *
		 * @param string $svg Raw inline SVG markup.
		 * @return string Sanitized SVG or empty string on rejection.
		 */
		public static function sanitize( string $svg ): string {
			$svg = trim( $svg );
			if ( '' === $svg ) {
				return '';
			}
			if ( strlen( $svg ) > self::MAX_INPUT_BYTES ) {
				return '';
			}

			$document = self::load_document( $svg );
			if ( null === $document ) {
				return '';
			}

			$root = $document->documentElement;
			if ( null === $root || strtolower( $root->localName ) !== 'svg' ) {
				return '';
			}

			$known_ids = self::prune_subtree( $root, array() );
			if ( ! self::root_has_drawable_content( $root ) ) {
				return '';
			}

			$serialized = self::serialize_root( $document, $root );
			if ( '' === $serialized ) {
				return '';
			}

			$reloaded = self::load_document( $serialized );
			if ( null === $reloaded || null === $reloaded->documentElement ) {
				return '';
			}

			return self::serialize_root( $reloaded, $reloaded->documentElement );
		}

		/**
		 * Resolve the viewBox attribute on a sanitized SVG.
		 *
		 * Prefers the typed attribute when present; otherwise reads the
		 * root <svg> viewBox attribute. Returns an empty string when
		 * neither is set.
		 *
		 * @param string $svg        Sanitized inline SVG.
		 * @param string $attribute  Optional typed attribute value.
		 * @return string
		 */
		public static function view_box( string $svg, string $attribute = '' ): string {
			$attribute = trim( $attribute );
			if ( '' !== $attribute ) {
				return self::normalize_view_box( $attribute );
			}
			$document = self::load_document( $svg );
			if ( null === $document || null === $document->documentElement ) {
				return '';
			}
			$value = $document->documentElement->getAttribute( 'viewBox' );
			if ( '' === $value ) {
				$value = $document->documentElement->getAttribute( 'viewbox' );
			}
			return self::normalize_view_box( $value );
		}

		/**
		 * Resolve preserveAspectRatio on a sanitized SVG.
		 *
		 * @param string $svg        Sanitized inline SVG.
		 * @param string $attribute  Optional typed attribute value.
		 * @return string
		 */
		public static function preserve_aspect_ratio( string $svg, string $attribute = '' ): string {
			$attribute = trim( $attribute );
			if ( '' !== $attribute ) {
				$normalized = self::normalize_preserve_aspect_ratio( $attribute );
				return '' === $normalized ? 'xMidYMid meet' : $normalized;
			}
			$document = self::load_document( $svg );
			if ( null === $document || null === $document->documentElement ) {
				return 'xMidYMid meet';
			}
			$value = $document->documentElement->getAttribute( 'preserveAspectRatio' );
			if ( '' === $value ) {
				$value = $document->documentElement->getAttribute( 'preserveaspectratio' );
			}
			$normalized = self::normalize_preserve_aspect_ratio( $value );
			return '' === $normalized ? 'xMidYMid meet' : $normalized;
		}

		/**
		 * Build accessible role/aria-* attribute pairs for the outer wrapper.
		 *
		 * When both title and description are provided, the wrapper uses
		 * aria-labelledby and aria-describedby so the inner <title> and
		 * <desc> elements carry the accessible name and description, and
		 * the generated IDs are returned so the renderer can splice them
		 * onto the matching <title>/<desc> elements.
		 *
		 * @param string $title       Optional accessible title.
		 * @param string $description Optional accessible description.
		 * @return array{attrs: array<string,string>, ids: array<string,string>}
		 */
		public static function accessibility_attributes( string $title, string $description ): array {
			$title       = trim( $title );
			$description = trim( $description );
			$ids         = array();
			if ( '' === $title && '' === $description ) {
				return array(
					'attrs' => array( 'role' => 'img' ),
					'ids'   => $ids,
				);
			}
			$attrs = array();
			if ( '' !== $title && '' !== $description ) {
				$title_id       = 'ssi-svg-title-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
				$description_id = 'ssi-svg-desc-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
				$ids            = array(
					'title'       => $title_id,
					'description' => $description_id,
				);
				$attrs['aria-labelledby']  = $title_id;
				$attrs['aria-describedby'] = $description_id;
			} elseif ( '' !== $title ) {
				$attrs['aria-label'] = $title;
			} else {
				$attrs['aria-label'] = $description;
			}
			return array(
				'attrs' => $attrs,
				'ids'   => $ids,
			);
		}

		/**
		 * Load HTML into a DOMDocument with external entities disabled.
		 *
		 * @param string $markup Raw markup.
		 * @return DOMDocument|null
		 */
		private static function load_document( string $markup ) {
			if ( ! class_exists( 'DOMDocument' ) ) {
				return null;
			}
			$previous = libxml_use_internal_errors( true );
			$document = new DOMDocument( '1.0', 'UTF-8' );
			$document->resolveExternals = false;
			$document->validateOnParse  = false;
			$loaded = $document->loadXML( '<?xml version="1.0" encoding="UTF-8"?><root>' . $markup . '</root>', LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			if ( ! $loaded || ! isset( $document->documentElement ) ) {
				return null;
			}
			$root = $document->documentElement;
			$svg  = null;
			foreach ( $root->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'svg' === strtolower( $child->localName ) ) {
					$svg = $child;
					break;
				}
			}
			if ( null === $svg ) {
				return null;
			}
			$adopted = new DOMDocument( '1.0', 'UTF-8' );
			$adopted->resolveExternals = false;
			$adopted->validateOnParse  = false;
			$imported = $adopted->importNode( $svg, true );
			$adopted->appendChild( $imported );
			return $adopted;
		}

		/**
		 * Walk a subtree, stripping disallowed elements, attributes, and
		 * unsafe URL/CSS values. Returns the set of local IDs discovered
		 * so a final pass can drop any unreferenced defs.
		 *
		 * Recursion is bounded by MAX_PRUNE_DEPTH. Once the cap is hit,
		 * deeper nodes are removed unconditionally so a pathological SVG
		 * cannot blow the call stack.
		 *
		 * @param DOMNode $node   Current node.
		 * @param array   $ids    Discovered ID map (lowercase id => true).
		 * @param int     $depth  Current recursion depth.
		 * @return array<string,bool>
		 */
		private static function prune_subtree( DOMNode $node, array $ids, int $depth = 0 ): array {
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				return $ids;
			}

			if ( $depth >= self::MAX_PRUNE_DEPTH ) {
				if ( null !== $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
				return $ids;
			}

			$local = strtolower( $node->localName );
			if ( ! in_array( $local, self::$allowed_elements, true ) ) {
				if ( null !== $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
				return $ids;
			}

			$id = $node->getAttribute( 'id' );
			if ( '' !== $id ) {
				$ids[ strtolower( $id ) ] = true;
			}

			self::prune_attributes( $node );

			$children = array();
			foreach ( $node->childNodes as $child ) {
				$children[] = $child;
			}
			foreach ( $children as $child ) {
				$ids = self::prune_subtree( $child, $ids, $depth + 1 );
			}

			return $ids;
		}

		/**
		 * Strip disallowed and unsafe attributes from an element.
		 *
		 * @param DOMElement $element Element to mutate in place.
		 */
		private static function prune_attributes( DOMElement $element ): void {
			$attributes = array();
			foreach ( $element->attributes as $attribute ) {
				$attributes[] = $attribute;
			}
			foreach ( $attributes as $attribute ) {
				$name  = strtolower( $attribute->nodeName );
				$value = (string) $attribute->nodeValue;

				if ( str_starts_with( $name, 'on' ) ) {
					$element->removeAttribute( $attribute->nodeName );
					continue;
				}
				if ( ! in_array( $name, self::$allowed_attributes, true ) ) {
					$element->removeAttribute( $attribute->nodeName );
					continue;
				}
				if ( in_array( $name, self::$fragment_only_attributes, true ) ) {
					$value = ltrim( $value );
					if ( '' === $value || '#' !== $value[0] || ! self::is_safe_fragment( substr( $value, 1 ) ) ) {
						if ( 'image' === strtolower( $element->localName ) && self::is_safe_data_image( $value ) ) {
							continue;
						}
						$element->removeAttribute( $attribute->nodeName );
					}
					continue;
				}
				if ( 'style' === $name ) {
					$cleaned = self::sanitize_style( $value );
					if ( '' === $cleaned ) {
						$element->removeAttribute( $attribute->nodeName );
					} else {
						$element->setAttribute( $attribute->nodeName, $cleaned );
					}
					continue;
				}
				if ( self::is_unsafe_url_value( $value ) ) {
					$element->removeAttribute( $attribute->nodeName );
				}
			}
		}

		/**
		 * Validate a local fragment identifier after the leading "#".
		 *
		 * @param string $fragment Fragment text without the leading "#".
		 * @return bool
		 */
		private static function is_safe_fragment( string $fragment ): bool {
			return (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_:\-\.]*$/', $fragment );
		}

		/**
		 * Allow data: URLs that point to image MIME types only.
		 *
		 * Reject every other data: scheme so HTML/SVG/JS payloads cannot
		 * be smuggled in via <image href="data:...">. The image element
		 * is the only context where data: URIs survive.
		 *
		 * @param string $value Raw href value, leading whitespace stripped.
		 * @return bool
		 */
		private static function is_safe_data_image( string $value ): bool {
			$lower = strtolower( $value );
			if ( ! str_starts_with( $lower, 'data:image/' ) ) {
				return false;
			}
			return (bool) preg_match( '/^data:image\/(png|jpe?g|gif|webp|svg\+xml)(;base64)?,/', $lower );
		}

		/**
		 * Reject attribute values that look like executable URLs.
		 *
		 * @param string $value Raw attribute value.
		 * @return bool True when the value should be discarded.
		 */
		private static function is_unsafe_url_value( string $value ): bool {
			$trimmed = ltrim( strtolower( $value ) );
			if ( '' === $trimmed ) {
				return false;
			}
			$unsafe_prefixes = array( 'javascript:', 'vbscript:', 'data:text/html', 'data:application/xhtml' );
			foreach ( $unsafe_prefixes as $prefix ) {
				if ( str_starts_with( $trimmed, $prefix ) ) {
					return true;
				}
			}
			if ( str_contains( $trimmed, 'expression(' ) || str_contains( $trimmed, 'behavior:' ) || str_contains( $trimmed, 'javascript:' ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Sanitize a style attribute value.
		 *
		 * Drops declarations that reference unsafe URL schemes, CSS custom
		 * properties, var(), or @import. Only fragment-only url() values
		 * survive. Returns the cleaned declaration list or an empty string
		 * when no declaration survives.
		 *
		 * @param string $style Raw style attribute value.
		 * @return string
		 */
		private static function sanitize_style( string $style ): string {
			$declarations = explode( ';', $style );
			$kept         = array();
			foreach ( $declarations as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$colon = strpos( $declaration, ':' );
				if ( false === $colon ) {
					continue;
				}
				$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value    = trim( substr( $declaration, $colon + 1 ) );
				if ( '' === $property || '' === $value ) {
					continue;
				}
				if ( str_starts_with( $property, '--' ) ) {
					continue;
				}
				if ( str_contains( $value, '@import' ) ) {
					continue;
				}
				if ( self::is_unsafe_url_value( $value ) ) {
					continue;
				}
				if ( preg_match_all( '/url\(\s*([\'"]?)([^)]*)\1\s*\)/i', $value, $url_matches ) ) {
					$all_safe = true;
					foreach ( $url_matches[2] as $reference ) {
						$reference = trim( (string) $reference );
						$first     = isset( $reference[0] ) ? $reference[0] : '';
						if ( '#' !== $first || ! self::is_safe_fragment( substr( $reference, 1 ) ) ) {
							$all_safe = false;
							break;
						}
					}
					if ( ! $all_safe ) {
						continue;
					}
				}
				$value_lower = strtolower( $value );
				if ( str_contains( $value_lower, 'var(' )
					|| str_contains( $value_lower, 'expression' )
					|| str_contains( $value_lower, 'behavior' )
				) {
					continue;
				}
				$kept[] = $property . ':' . $value;
			}
			return implode( ';', $kept );
		}

		/**
		 * Normalize a viewBox value.
		 *
		 * Accepts a four-number comma- or space-separated tuple only. Each
		 * component is bounded to MAX_VIEWBOX_COMPONENT so absurd values
		 * cannot exhaust renderer memory.
		 *
		 * @param string $value Raw value.
		 * @return string
		 */
		private static function normalize_view_box( string $value ): string {
			$value = trim( $value );
			if ( '' === $value || ! preg_match( '/^[-+]?[\d\.]+(\s+|,)\s*[-+]?[\d\.]+(\s+|,)\s*[-+]?[\d\.]+(\s+|,)\s*[-+]?[\d\.]+\s*$/', $value ) ) {
				return '';
			}
			$parts = preg_split( '/[\s,]+/', $value );
			if ( ! is_array( $parts ) || 4 !== count( $parts ) ) {
				return '';
			}
			foreach ( $parts as $component ) {
				if ( ! is_numeric( $component ) ) {
					return '';
				}
				$numeric = (float) $component;
				if ( ! is_finite( $numeric ) || abs( $numeric ) > self::MAX_VIEWBOX_COMPONENT ) {
					return '';
				}
			}
			return implode( ' ', $parts );
		}

		/**
		 * Normalize a preserveAspectRatio value.
		 *
		 * Allows only the canonical alignment+meet-or-slice pair, or a
		 * "none" value. Anything else falls back to the default.
		 *
		 * @param string $value Raw value.
		 * @return string
		 */
		private static function normalize_preserve_aspect_ratio( string $value ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}
			$valid_alignments = array( 'none', 'xMinYMin', 'xMidYMin', 'xMaxYMin', 'xMinYMid', 'xMidYMid', 'xMaxYMid', 'xMinYMax', 'xMidYMax', 'xMaxYMax' );
			$valid_meet       = array( 'meet', 'slice' );
			$parts            = preg_split( '/\s+/', $value );
			if ( ! is_array( $parts ) || 1 === count( $parts ) ) {
				if ( in_array( $parts[0] ?? '', $valid_alignments, true ) ) {
					return $parts[0];
				}
				return '';
			}
			if ( 2 !== count( $parts ) ) {
				return '';
			}
			if ( ! in_array( $parts[0], $valid_alignments, true ) || ! in_array( $parts[1], $valid_meet, true ) ) {
				return '';
			}
			return $parts[0] . ' ' . $parts[1];
		}

		/**
		 * Check whether the root <svg> still has any drawable descendant.
		 *
		 * @param DOMElement $root Root <svg> element.
		 * @return bool
		 */
		private static function root_has_drawable_content( DOMElement $root ): bool {
			$drawable = array( 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'use', 'g', 'image' );
			$stack    = array( $root );
			while ( $stack ) {
				$node   = array_pop( $stack );
				$local  = strtolower( $node->localName );
				if ( in_array( $local, $drawable, true ) ) {
					if ( 'g' === $local ) {
						foreach ( $node->childNodes as $child ) {
							$stack[] = $child;
						}
						continue;
					}
					return true;
				}
				if ( 'svg' === $local ) {
					foreach ( $node->childNodes as $child ) {
						$stack[] = $child;
					}
				}
			}
			return false;
		}

		/**
		 * Serialize the sanitized document to a clean SVG string.
		 *
		 * @param DOMDocument $document Document.
		 * @param DOMElement  $root     Root <svg> element.
		 * @return string
		 */
		private static function serialize_root( DOMDocument $document, DOMElement $root ): string {
			$root->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
			$xml = $document->saveXML( $root );
			if ( false === $xml ) {
				return '';
			}
			return $xml;
		}
	}
}
