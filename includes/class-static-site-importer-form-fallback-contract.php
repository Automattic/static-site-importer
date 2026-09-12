<?php
/**
 * Provider-neutral form fallback contract operations.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns operational form normalization, presentation, and reconciliation facts.
 */
class Static_Site_Importer_Form_Fallback_Contract {

	/**
	 * Extract bounded presentation facts while the exact fallback form is in scope.
	 * Raw fallback HTML never enters a diagnostic or materialization manifest.
	 *
	 * @return array<string,mixed>
	 */
	public static function presentation_from_html( string $html, string $selector = '', int $occurrence = 0 ): array {
		$manifest = self::manifest_from_html( $html );
		$form     = self::preserved_presentation( $html, $manifest['form'], $manifest['controls'] );
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$form_node      = $doc->getElementsByTagName( 'form' )->item( 0 );
		$before         = array();
		$after          = array();
		$interleaved    = false;
		$seen_controls  = 0;
		$total_controls = count( $manifest['controls'] );
		if ( $form_node instanceof DOMElement ) {
			foreach ( $form_node->getElementsByTagName( '*' ) as $node ) {
				$tag = strtolower( $node->nodeName );
				if ( in_array( $tag, array( 'input', 'select', 'textarea', 'button' ), true ) ) {
					++$seen_controls;
					continue;
				}
				$text = self::presentation_text( $node->textContent );
				$item = preg_match( '/^h[1-6]$/', $tag ) && '' !== $text ? array(
					'type'  => 'heading',
					'level' => (int) substr( $tag, 1 ),
					'text'  => $text,
				) : ( in_array( $tag, array( 'label', 'p' ), true ) && preg_match( '/(?:required|note|instruction|help)/i', $node->getAttribute( 'class' ) ) && '' !== $text ? array(
					'type' => 'paragraph',
					'text' => $text,
				) : null );
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( 0 === $seen_controls ) {
					$before[] = $item;
				} elseif ( $seen_controls >= $total_controls ) {
					$after[] = $item;
				} else {
					$interleaved = true;
				}
			}
		}
		$heights = array();
		foreach ( $manifest['controls'] as $index => $control ) {
			if ( isset( $control['height'] ) ) {
				$heights[ $index ] = $control['height'];
			}
		}
		$fingerprint    = array(
			'class'               => $manifest['form']['class'] ?? '',
			'action'              => $manifest['form']['action'] ?? '',
			'method'              => $manifest['form']['method'] ?? '',
			'controls'            => array_map( static fn ( array $control ): array => array_intersect_key( $control, array_flip( array( 'tag', 'type', 'name', 'id', 'label' ) ) ), $manifest['controls'] ),
			'submit_text'         => $form['submit_presentation']['text'] ?? '',
			'context_before_hash' => hash( 'sha256', (string) wp_json_encode( $before ) ),
			'context_after_hash'  => hash( 'sha256', (string) wp_json_encode( $after ) ),
		);
		$stored_heights = array_slice( $heights, 0, 16, true );
		return array_filter(
			array(
				'schema'                        => 'generic/form-presentation/v1',
				'selector'                      => $selector,
				// The transformer supplies the form's document-wide position. A selector's
				// nth-of-type position is scoped to siblings and cannot safely identify it.
				'document_ordinal'              => $occurrence > 0 ? $occurrence : null,
				'fingerprint'                   => hash( 'sha256', (string) wp_json_encode( $fingerprint ) ),
				'context_before'                => array_slice( $before, 0, 8 ),
				'context_after'                 => array_slice( $after, 0, 8 ),
				'interleaved_context'           => $interleaved,
				'submit_presentation'           => $form['submit_presentation'] ?? null,
				'textarea_heights'              => $stored_heights,
				'textarea_height_omitted_count' => max( 0, count( $heights ) - count( $stored_heights ) ),
			)
		);
	}

	/**
	 * Extract a provider-neutral form manifest from complete HTML.
	 *
	 * @param string $html Form HTML.
	 * @return array{form:array<string,string>,controls:array<int,array<string,mixed>>}
	 */
	public static function manifest_from_html( string $html ): array {
		if ( '' === $html || ! str_contains( strtolower( $html ), '<form' ) ) {
			return array(
				'form'     => array(),
				'controls' => array(),
			);
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$form_node = $doc->getElementsByTagName( 'form' )->item( 0 );
		if ( null === $form_node ) {
			return array(
				'form'     => array(),
				'controls' => array(),
			);
		}

		$form = array();
		foreach ( array( 'class', 'action', 'method' ) as $attribute ) {
			$value = trim( $form_node->getAttribute( $attribute ) );
			if ( '' !== $value ) {
				$form[ $attribute ] = $value;
			}
		}

		$controls = array();
		foreach ( $form_node->getElementsByTagName( '*' ) as $control_node ) {
			if ( ! in_array( strtolower( $control_node->tagName ), array( 'input', 'textarea', 'select', 'button' ), true ) ) {
				continue;
			}
			$control = array(
				'tag'  => strtolower( $control_node->tagName ),
				'type' => strtolower( trim( $control_node->getAttribute( 'type' ) ) ),
			);
			if ( 'button' === $control['tag'] && '' === $control['type'] ) {
				$control['type'] = 'submit';
			}
			if ( 'input' === $control['tag'] && '' === $control['type'] ) {
				$control['type'] = 'text';
			}

			foreach ( array( 'id', 'name', 'placeholder' ) as $attribute ) {
				$value = trim( $control_node->getAttribute( $attribute ) );
				if ( '' !== $value ) {
					$control[ $attribute ] = $value;
				}
			}

			$label = trim( $control_node->getAttribute( 'aria-label' ) );
			if ( '' === $label ) {
				$label = trim( $control_node->textContent );
			}
			if ( '' !== $label ) {
				$control['label'] = $label;
			}

			if ( $control_node->hasAttribute( 'required' ) ) {
				$control['required'] = true;
			}
			if ( $control_node->hasAttribute( 'aria-required' ) ) {
				$control['aria-required'] = $control_node->getAttribute( 'aria-required' );
			}

			$controls[] = $control;
		}

		return array(
			'form'     => $form,
			'controls' => $controls,
		);
	}

	/** @param array<string,mixed> $fallback */
	public static function reconciliation_hash( array $fallback ): string {
		$source = isset( $fallback['form'] ) || isset( $fallback['controls'] )
			? wp_json_encode(
				self::canonical_value(
					array(
						'form'     => $fallback['form'] ?? array(),
						'controls' => $fallback['controls'] ?? array(),
					)
				)
			)
			: self::first_scalar( $fallback, array( 'source_html_preview', 'html_excerpt', 'excerpt' ) );
		return hash( 'sha256', (string) $source );
	}

	/** Canonicalize associative metadata while retaining authored list order. */
	private static function canonical_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonical_value( $child );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/** @param array<string,mixed> $fallback */
	public static function reconciliation_identity( array $fallback ): string {
		// Blocks Engine assigns this identity at fallback detection, before an
		// importer-specific provider projection can alter its representation.
		foreach ( array( 'source_fallback_identity', 'fallback_reconciliation_identity', 'fallback_identity' ) as $field ) {
			$identity = $fallback[ $field ] ?? null;
			if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
				return $identity;
			}
		}

		return hash( 'sha256', "static-site-importer/fallback-reconciliation/v1\n" . self::first_scalar( $fallback, array( 'source_path', 'source' ) ) . "\n" . self::first_scalar( $fallback, array( 'selector' ) ) . "\n" . self::reconciliation_hash( $fallback ) );
	}

	/**
	 * Carry bounded authored form context into the provider adapter.
	 *
	 * The fallback HTML is the only complete representation when a form island is
	 * replaced, so retain headings, standalone notes, and a visible submit treatment
	 * before the provider block is serialized.
	 *
	 * @param string                         $html     Complete internal fallback HTML.
	 * @param array<string,mixed>            $form     Extracted form metadata.
	 * @param array<int,array<string,mixed>> $controls Extracted controls, enriched in place.
	 * @return array<string,mixed>
	 */
	private static function preserved_presentation( string $html, array $form, array &$controls ): array {
		if ( '' === $html ) {
			return $form;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$form_node = $doc->getElementsByTagName( 'form' )->item( 0 );
		if ( null === $form_node ) {
			return $form;
		}

		$context = array();
		$nodes   = $form_node->getElementsByTagName( '*' );
		foreach ( $nodes as $node ) {
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, array( 'input', 'select', 'textarea', 'button' ), true ) ) {
				break;
			}
			$text = self::presentation_text( $node->textContent );
			if ( preg_match( '/^h[1-6]$/', $tag ) && '' !== $text ) {
				$context[] = array(
					'type'  => 'heading',
					'level' => (int) substr( $tag, 1 ),
					'text'  => $text,
				);
			} elseif ( 'label' === $tag && preg_match( '/(?:required|note|instruction|help)/i', $node->getAttribute( 'class' ) ) && '' !== $text ) {
				$context[] = array(
					'type' => 'paragraph',
					'text' => $text,
				);
			}
		}
		if ( ! empty( $context ) ) {
			$form['context_before'] = $context;
		}

		$textarea_index = 0;
		foreach ( $nodes as $node ) {
			if ( 'textarea' !== strtolower( $node->nodeName ) ) {
				continue;
			}
			while ( isset( $controls[ $textarea_index ] ) && 'textarea' !== strtolower( (string) ( $controls[ $textarea_index ]['tag'] ?? '' ) ) ) {
				++$textarea_index;
			}
			if ( isset( $controls[ $textarea_index ] ) && preg_match( '/(?:^|;)\s*height\s*:\s*([0-9]{1,4}(?:\.[0-9]+)?(?:px|em|rem|vh|vw|%))\s*(?:;|$)/i', $node->getAttribute( 'style' ), $height ) ) {
				$controls[ $textarea_index ]['height'] = $height[1];
			}
			++$textarea_index;
		}

		foreach ( $nodes as $node ) {
			if ( ! self::is_submit_control( $node ) ) {
				continue;
			}
			$presentation = self::submit_presentation( $node );
			$visible_node = self::next_element_sibling( $node );
			if ( self::submit_is_visually_hidden( $node ) && $visible_node instanceof DOMElement && in_array( strtolower( $visible_node->nodeName ), array( 'a', 'button' ), true ) ) {
				$visible = self::submit_presentation( $visible_node );
				if ( '' !== $visible['text'] && $visible['text'] === $presentation['text'] ) {
					$presentation = $visible;
				}
			}
			if ( '' !== $presentation['text'] ) {
				$form['submit_presentation'] = $presentation;
			}
			break;
		}

		return $form;
	}

	private static function is_submit_control( DOMElement $node ): bool {
		$tag  = strtolower( $node->nodeName );
		$type = strtolower( trim( $node->getAttribute( 'type' ) ) );
		return ( 'input' === $tag && in_array( $type, array( 'submit', 'image' ), true ) ) || ( 'button' === $tag && ( '' === $type || 'submit' === $type ) );
	}

	/** @return array{text:string,classes:array<int,string>} */
	private static function submit_presentation( DOMElement $node ): array {
		$text          = 'input' === strtolower( $node->nodeName ) ? trim( $node->getAttribute( 'value' ) ) : self::presentation_text( $node->textContent );
		$presentation  = array(
			'text'    => $text,
			'classes' => self::presentation_classes( 'class="' . $node->getAttribute( 'class' ) . '"' ),
		);
		$label_classes = self::submit_label_classes( $node, $text );
		if ( array() !== $label_classes ) {
			$presentation['label_classes'] = $label_classes;
		}
		return $presentation;
	}

	/**
	 * A submit label can live in its own element that carries the typography
	 * governing the rendered line box. Report that element's classes so the
	 * materialized button can keep it, instead of resolving the text against the
	 * button's own typography and changing the control's height.
	 *
	 * @return array<int,string>
	 */
	private static function submit_label_classes( DOMElement $node, string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$only_child = null;
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( null !== $only_child ) {
					return array();
				}
				$only_child = $child;
				continue;
			}
			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				return array();
			}
		}
		if ( ! $only_child instanceof DOMElement || 'span' !== strtolower( $only_child->nodeName ) || self::presentation_text( $only_child->textContent ) !== $text ) {
			return array();
		}
		return self::presentation_classes( 'class="' . $only_child->getAttribute( 'class' ) . '"' );
	}

	private static function submit_is_visually_hidden( DOMElement $node ): bool {
		return (bool) preg_match( '/(?:display\s*:\s*none|visibility\s*:\s*hidden|left\s*:\s*-\s*[0-9]+px)/i', $node->getAttribute( 'style' ) );
	}

	private static function next_element_sibling( DOMElement $node ): ?DOMElement {
		for ( $sibling = $node->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
			if ( $sibling instanceof DOMElement ) {
				return $sibling;
			}
		}
		return null;
	}

	/** @return array<int,string> */
	private static function presentation_classes( string $attributes ): array {
		if ( ! preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $attributes, $match ) ) {
			return array();
		}
		$classes = preg_split( '/\s+/', trim( $match[2] ) );
		return array_slice( array_filter( is_array( $classes ) ? $classes : array(), static fn ( string $class_name ): bool => (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ), 0, 8 );
	}

	private static function presentation_text( string $html ): string {
		$plain      = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for runtime-free smoke tests.
		$normalized = preg_replace( '/\s+/', ' ', $plain );
		return substr( trim( is_string( $normalized ) ? $normalized : '' ), 0, 200 );
	}

	/** @param array<string,mixed> $row @param array<int,string> $keys */
	private static function first_scalar( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
		}
		return '';
	}
}
