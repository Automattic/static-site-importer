<?php
/**
 * Runtime presentation projection for materialized provider forms.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps source form presentation attached to provider-rendered controls. */
final class Static_Site_Importer_Provider_Form_Runtime_V1 {
	/** Whether hooks have already been registered in this request. */
	private static bool $registered = false;
	/** @var array<string,array<string,mixed>> Complete empty-country groups keyed by generated field ID. */
	private static array $visual_states = array();

	/** Admit only the portable subset also enforced by Blocks Engine's SourceDom. */
	public static function valid_inline_svg( string $markup ): bool {
		if ( '' === trim( $markup ) || str_contains( $markup, '<?' ) || preg_match( '/<!\s*(?:doctype|entity)\b/i', $markup ) || preg_match( '/&(?!(?:amp|lt|gt|quot|apos);)/i', $markup ) ) {
			return false;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadXML( $markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $document->documentElement instanceof \DOMElement || 'svg' !== strtolower( $document->documentElement->tagName ) ) {
			return false;
		}
		$allowed = array_flip( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'tspan', 'title', 'desc', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'marker', 'filter', 'feblend', 'fecolormatrix', 'fecomposite', 'fegaussianblur', 'femerge', 'femergenode', 'feoffset', 'feflood', 'feturbulence' ) );
		$blocked = array_flip( array( 'href', 'xlink:href', 'src', 'style' ) );
		$nodes   = array( $document->documentElement );
		while ( ! empty( $nodes ) ) {
			$element = array_pop( $nodes );
			if ( ! isset( $allowed[ strtolower( $element->tagName ) ] ) ) {
				return false;
			}
			foreach ( $element->attributes as $attribute ) {
				$name  = strtolower( $attribute->name );
				$value = trim( $attribute->value );
				if ( str_starts_with( $name, 'on' ) || isset( $blocked[ $name ] ) || ( str_contains( $name, ':' ) && ! in_array( $name, array( 'xmlns', 'xml:lang', 'xml:space' ), true ) ) || preg_match( '/(?:^|[^a-z])url\s*\(/i', $value ) ) {
					return false;
				}
			}
			foreach ( $element->childNodes as $child ) {
				if ( $child instanceof \DOMElement ) {
					$nodes[] = $child;
				}
			}
		}
		return true;
	}

	/** Configure complete, source-captured empty-country groups for this companion. */
	public static function configure_visual_states( array $states ): void {
		self::$visual_states = array();
		foreach ( $states as $state ) {
			if ( self::valid_visual_state( $state ) ) {
				self::$visual_states[ $state['field_id'] ] = $state;
			}
		}
	}

	/** Validate the portable configuration before it is persisted in a companion. */
	public static function valid_visual_state( mixed $state ): bool {
		if ( ! is_array( $state ) || array_keys( $state ) !== array( 'schema', 'field_id', 'trigger_class', 'group', 'parts', 'css' ) || 'static-site-importer/form-visual-state/v1' !== ( $state['schema'] ?? null ) || ! is_string( $state['field_id'] ?? null ) || ! preg_match( '/^ssi-form-[a-f0-9]{12}-field-[0-9]{1,3}$/D', $state['field_id'] ) || ! is_string( $state['trigger_class'] ?? null ) || ! preg_match( '/^ssi-node-[a-f0-9]{12}-destination-country-trigger$/D', $state['trigger_class'] ) || ! is_array( $state['group'] ?? null ) || array_keys( $state['group'] ) !== array( 'id', 'class' ) || ! is_string( $state['group']['id'] ?? null ) || ! preg_match( '/^visual-group-[a-f0-9]{16}$/D', $state['group']['id'] ) || ! is_string( $state['group']['class'] ?? null ) || ! preg_match( '/^ssi-fvg-[a-f0-9]{12}$/D', $state['group']['class'] ) || ! is_array( $state['parts'] ?? null ) || ! array_is_list( $state['parts'] ) || count( $state['parts'] ) < 1 || count( $state['parts'] ) > 32 || ! is_string( $state['css'] ?? null ) || strlen( $state['css'] ) > 16384 ) {
			return false;
		}
		$seen = array();
		foreach ( $state['parts'] as $part ) {
			if ( ! is_array( $part ) || array_keys( $part ) !== array( 'id', 'class', 'markup' ) || ! is_string( $part['id'] ?? null ) || isset( $seen[ $part['id'] ] ) || ! preg_match( '/^control-[0-9]+-svg-[0-9]+$/D', $part['id'] ) || ! is_string( $part['class'] ?? null ) || ! preg_match( '/^ssi-fvs-[a-f0-9]{12}$/D', $part['class'] ) || ! is_string( $part['markup'] ?? null ) || strlen( $part['markup'] ) > 16384 || ! self::valid_inline_svg( $part['markup'] ) ) {
				return false;
			}
			$seen[ $part['id'] ] = true;
		}
		return true;
	}

	/** Register inert-unless-marked provider projection hooks. */
	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_wrapper_classes' ) );
		add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_empty_country_visual_state' ), 20 );
		add_filter( 'render_block_jetpack/contact-form', array( __CLASS__, 'project_plain_root_fieldset' ), 10, 2 );
		add_filter( 'render_block_core/button', array( __CLASS__, 'project_submit_presentation' ), 10, 2 );
	}

	/** Restore a source plain-root fieldset around provider field content, never the form itself. */
	public static function project_plain_root_fieldset( string $html, array $block = array() ): string {
		$class_name = isset( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ? $block['attrs']['className'] : '';
		if ( 262144 < strlen( $html ) || ! preg_match( '/(?:^|\s)ssi-source-root-fieldset(?:\s|$)/', $class_name ) ) {
			return $html;
		}
		$source_classes = array();
		$classes        = preg_split( '/\s+/', $class_name );
		foreach ( false === $classes ? array() : $classes as $class ) {
			if ( preg_match( '/^ssi-source-root-fieldset--([A-Za-z_][A-Za-z0-9_-]{0,79})$/D', $class, $marker ) ) {
				$source_classes[] = $marker[1];
			}
		}
		$source_classes = array_slice( array_values( array_unique( $source_classes ) ), 0, 8 );
		$document       = new \DOMDocument();
		$previous       = libxml_use_internal_errors( true );
		$loaded         = $document->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $html;
		}
		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return $html;
		}
		$field_list = null;
		foreach ( $body->getElementsByTagName( '*' ) as $element ) {
			$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
			$classes = false === $classes ? array() : $classes;
			if ( in_array( 'ssi-source-root-fieldset', $classes, true ) ) {
				if ( $field_list instanceof \DOMElement || 'div' !== strtolower( $element->tagName ) || ! in_array( 'wp-block-jetpack-contact-form', $classes, true ) ) {
					return $html;
				}
				$field_list = $element;
			}
		}
		if ( ! $field_list instanceof \DOMElement || ! $field_list->parentNode instanceof \DOMElement ) {
			return $html;
		}
		$form         = $field_list->parentNode;
		$form_classes = preg_split( '/\s+/', trim( $form->getAttribute( 'class' ) ) );
		if ( 'form' !== strtolower( $form->tagName ) || ! in_array( 'jetpack-contact-form__form', false === $form_classes ? array() : $form_classes, true ) ) {
			return $html;
		}
		$fieldset = $document->createElement( 'fieldset' );
		if ( ! empty( $source_classes ) ) {
			$fieldset->setAttribute( 'class', implode( ' ', $source_classes ) );
		}
		$form->insertBefore( $fieldset, $field_list );
		$fieldset->appendChild( $field_list );
		$field_classes = preg_split( '/\s+/', trim( $field_list->getAttribute( 'class' ) ) );
		$field_classes = array_values( array_filter( false === $field_classes ? array() : $field_classes, static fn( string $class_name ): bool => 'ssi-source-root-fieldset' !== $class_name && 1 !== preg_match( '/^ssi-source-root-fieldset--/', $class_name ) ) );
		$field_list->setAttribute( 'class', implode( ' ', $field_classes ) );
		$output = '';
		foreach ( $body->childNodes as $child ) {
			$output .= $document->saveHTML( $child );
		}
		return $output;
	}

	/** Insert the complete captured group into Jetpack's existing trigger; never replace its flag or arrow after selection. */
	public static function project_empty_country_visual_state( string $html ): string {
		if ( empty( self::$visual_states ) || ! preg_match( '/\bid=(?:"|\')((?:ssi-form-[a-f0-9]{12}-field-[0-9]{1,3}))(?:"|\')/', $html, $id ) || ! isset( self::$visual_states[ $id[1] ] ) ) {
			return $html;
		}
		if ( ! preg_match( '/<button\b(?=[^>]*\bclass=("|\')[^"\']*\bjetpack-combobox-trigger\b[^"\']*\1)[^>]*>/i', $html, $button, PREG_OFFSET_CAPTURE ) || ! preg_match( '/<[^>]*\bclass=("|\')[^"\']*\bjetpack-combobox-trigger-arrow\b[^"\']*\1[^>]*>/i', $html, $arrow, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$state   = self::$visual_states[ $id[1] ];
		$trigger = preg_replace( '/\bclass=("|\')(.*?)\1/is', 'class=$1$2 ' . $state['trigger_class'] . '$1', $button[0][0], 1 );
		if ( ! is_string( $trigger ) ) {
			return $html;
		}
		$html           = substr_replace( $html, $trigger, $button[0][1], strlen( $button[0][0] ) );
		$parts          = array_map( static fn( array $part ): string => preg_replace( '/^<svg\b/i', '<svg class="' . $part['class'] . '"', $part['markup'], 1 ) ?? $part['markup'], $state['parts'] );
		$visibility_css = '.' . $state['trigger_class'] . ' [hidden]{display:none!important}.' . $state['trigger_class'] . ':has(>.ssi-form-visual-state:not([hidden])){gap:0}';
		$group          = '<style>' . $visibility_css . $state['css'] . '</style><span class="ssi-form-visual-state ' . $state['group']['class'] . '" data-wp-bind--hidden="context.selectedCountry.value">' . implode( '', $parts ) . '</span>';
		$html           = substr_replace( $html, $group, $button[0][1] + strlen( $trigger ), 0 );
		$arrow_offset   = $arrow[0][1] + ( $arrow[0][1] > $button[0][1] ? strlen( $trigger ) - strlen( $button[0][0] ) + strlen( $group ) : 0 );
		$arrow_tag      = $arrow[0][0];
		$arrow_tag      = preg_replace( '/\sdata-wp-bind--hidden=("|\')[^"\']*\1/i', '', $arrow_tag ) ?? $arrow_tag;
		return substr_replace( $html, rtrim( substr( $arrow_tag, 0, -1 ) ) . ' data-wp-bind--hidden="!context.selectedCountry.value">', $arrow_offset, strlen( $arrow[0][0] ) );
	}

	/** Move source submit presentation from Core's wrapper onto its button control. */
	public static function project_submit_presentation( string $html, array $block = array() ): string {
		$class_name = isset( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ? $block['attrs']['className'] : '';
		if ( ! str_contains( $class_name, 'ssi-source-submit--' ) && ! str_contains( $class_name, 'ssi-source-semantic-wrapper-' ) ) {
			return $html;
		}
		$source_classes = array();
		$projected      = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$source_classes ): string {
				$classes = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes = false === $classes ? array() : $classes;
				$output  = array();
				foreach ( $classes as $candidate ) {
					if ( preg_match( '/^ssi-source-submit--([A-Za-z_][A-Za-z0-9_-]{0,79})$/D', $candidate, $marker ) ) {
						$source_classes[] = $marker[1];
						continue;
					}
					$output[] = $candidate;
				}
				return 'class=' . $matches[1] . implode( ' ', $output ) . $matches[1];
			},
			$html,
			1
		);
		if ( ! is_string( $projected ) ) {
			return $html;
		}
		if ( ! empty( $source_classes ) ) {
			$source_classes = array_values( array_unique( $source_classes ) );
			// The provider sizes its submit wrapper to its own field height. The source
			// sized that row from its own content, so the provider default is released
			// on the wrapper exactly as it already is on the button it contains.
			$projected = preg_replace_callback(
				'/<div\b([^>]*\bclass=(["\'])[^"\']*\bwp-block-button\b[^"\']*\2[^>]*)>/is',
				static function ( array $matches ): string {
					$attributes = $matches[1];
					if ( preg_match( '/\bstyle=(["\'])(.*?)\1/is', $attributes ) ) {
						return '<div' . ( preg_replace( '/\bstyle=(["\'])(.*?)\1/is', 'style=$1$2;min-height:0$1', $attributes, 1 ) ?? $attributes ) . '>';
					}

					return '<div' . $attributes . ' style="min-height:0">';
				},
				$projected,
				1
			);
			if ( ! is_string( $projected ) ) {
				return $html;
			}
			$projected = preg_replace_callback(
			'/<button\b([^>]*)>/is',
			static function ( array $matches ) use ( $source_classes ): string {
				$attributes = $matches[1];
				if ( preg_match( '/\bclass=(["\'])(.*?)\1/is', $attributes ) ) {
					$attributes = preg_replace( '/\bclass=(["\'])(.*?)\1/is', 'class=$1$2 ' . implode( ' ', $source_classes ) . '$1', $attributes, 1 ) ?? $attributes;
				} else {
					$attributes .= ' class="' . implode( ' ', $source_classes ) . '"';
				}
				if ( preg_match( '/\bstyle=(["\'])(.*?)\1/is', $attributes ) ) {
					$attributes = preg_replace( '/\bstyle=(["\'])(.*?)\1/is', 'style=$1$2;min-height:0$1', $attributes, 1 ) ?? $attributes;
				} else {
					$attributes .= ' style="min-height:0"';
				}
				return '<button' . $attributes . '>';
			},
			$projected,
			1
			);
		}
		return is_string( $projected ) ? self::project_semantic_wrappers( $projected ) : $html;
	}

	/**
	 * Rebuild explicitly projected wrapper layers inside a provider field shell.
	 *
	 * The seeder's `ssi-source-wrapper-N--CLASS-wrap` token is a bounded transport
	 * contract. It never makes CLASS part of saved provider markup: this filter
	 * recognizes it only on the provider field shell, then restores the layer
	 * immediately around its native control. Older depth-qualified tokens remain
	 * readable because they have already been persisted in imported content.
	 */
	public static function project_wrapper_classes( string $html ): string {
		$wrapper_layers            = array();
		$composite_layers          = array();
		$provider_layout_classes   = array();
		$fullspan_child_classes    = array();
		$phone_destination_classes = array();
		$projected                 = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$wrapper_layers, &$composite_layers, &$provider_layout_classes, &$fullspan_child_classes, &$phone_destination_classes ): string {
				$classes        = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes        = false === $classes ? array() : $classes;
				$is_wrapper     = (bool) array_filter( $classes, static fn ( string $class_name ): bool => 1 === preg_match( '/^grunion-field-[A-Za-z0-9_-]+-wrap$/D', $class_name ) );
				$is_phone_shell = in_array( 'jetpack-field__input-phone-wrapper', $classes, true );
				$output         = array();
				foreach ( $classes as $class_name ) {
					if ( preg_match( '/^ssi-source-fullspan-child--(ssi-node-[a-f0-9]{12})-wrap$/D', $class_name, $marker ) ) {
						if ( $is_wrapper ) {
							$fullspan_child_classes[] = $marker[1] . '-wrap';
						}
						continue;
					}
					if ( preg_match( '/^ssi-source-wrapper-(prefix|shell)-([0-9]{1,2})--([A-Za-z_][A-Za-z0-9_-]{0,79})-wrap$/D', $class_name, $marker ) ) {
						if ( $is_wrapper ) {
							$composite_layers[ $marker[1] ][ (int) $marker[2] ][] = $marker[3];
						}
						continue;
					}
					if ( preg_match( '/^ssi-source-wrapper-(?:prefix|shell)-[0-9]{1,2}--[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ) {
						continue;
					}
					if ( $is_phone_shell && 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-destination-(?:primary|carrier)$/D', $class_name ) ) {
						$phone_destination_classes[] = $class_name;
						continue;
					}
					if ( $is_wrapper && 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-wrap$/D', $class_name ) ) {
						$provider_layout_classes[] = $class_name;
						continue;
					}
					if ( preg_match( '/^ssi-source-wrapper-([0-9]{1,2})--([A-Za-z_][A-Za-z0-9_-]{0,79})-wrap$/D', $class_name, $marker ) ) {
						if ( $is_wrapper ) {
							$wrapper_layers[ (int) $marker[1] ][] = $marker[2];
						}
						continue;
					}
					if ( preg_match( '/^ssi-source-wrapper-([0-9]{1,2})--([A-Za-z_][A-Za-z0-9_-]{0,79})$/D', $class_name, $marker ) ) {
						if ( $is_wrapper ) {
							$wrapper_layers[ (int) $marker[1] ][] = $marker[2];
						}
						continue;
					}
					if ( str_starts_with( $class_name, 'ssi-source-wrapper--' ) ) {
						if ( $is_wrapper && str_ends_with( $class_name, '-wrap' ) ) {
							$source_class = substr( $class_name, strlen( 'ssi-source-wrapper--' ), -strlen( '-wrap' ) );
							if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $source_class ) ) {
								$wrapper_layers[0][] = $source_class;
							}
						}
						continue;
					}
					$output[] = $class_name;
				}
				return 'class=' . $matches[1] . implode( ' ', array_values( array_unique( $output ) ) ) . $matches[1];
			},
			$html
		);
		if ( ! is_string( $projected ) || ( empty( $wrapper_layers ) && empty( $composite_layers ) && empty( $fullspan_child_classes ) && empty( $phone_destination_classes ) ) ) {
			return is_string( $projected ) ? self::project_semantic_wrappers( $projected ) : $html;
		}

		ksort( $wrapper_layers );
		$open  = '';
		$close = '';
		foreach ( $wrapper_layers as $depth => $classes ) {
			$classes = array_values( array_unique( $classes ) );
			if ( array_key_first( $wrapper_layers ) === $depth ) {
				$classes = array_values( array_unique( array_merge( $classes, $provider_layout_classes ) ) );
			}
			$open .= '<div class="' . implode( ' ', $classes ) . '">';
			$close = '</div>' . $close;
		}
		if ( ! empty( $fullspan_child_classes ) ) {
			$open .= '<div class="' . implode( ' ', array_values( array_unique( $fullspan_child_classes ) ) ) . '">';
			$close = '</div>' . $close;
		}
		// A phone field's country search precedes its value input in Jetpack's HTML.
		// Target Jetpack's actual telephone control, leaving auxiliary and hidden inputs intact.
		$is_phone = (bool) preg_match( '/\bclass=(["\'])[^"\']*\bgrunion-field-(?:phone|telephone)-wrap\b[^"\']*\1/i', $projected );
		$pattern  = $is_phone
			? '/<input\b(?=[^>]*\btype\s*=\s*(["\'])tel\1)[^>]*>/is'
			: '/<input\b[^>]*>|<textarea\b[^>]*>.*?<\/textarea>|<select\b[^>]*>.*?<\/select>/is';
		$wrapped  = preg_replace_callback(
			$pattern,
			static function ( array $control_match ) use ( $open, $close, $is_phone, $phone_destination_classes ): string {
				if ( ! $is_phone || empty( $phone_destination_classes ) ) {
					return $open . $control_match[0] . $close;
				}
				$value_classes   = implode( ' ', array_filter( $phone_destination_classes, static fn( string $class_name ): bool => str_ends_with( $class_name, '-destination-primary' ) ) );
				$carrier_classes = implode( ' ', array_filter( $phone_destination_classes, static fn( string $class_name ): bool => str_ends_with( $class_name, '-destination-carrier' ) ) );
				if ( '' === $value_classes ) {
					return $open . $control_match[0] . $close;
				}
				$input = preg_replace( '/\bclass=(["\'])(.*?)\1/is', 'class=$1$2 ' . $value_classes . '$1', $control_match[0], 1 ) ?? $control_match[0];
				if ( '' !== $carrier_classes && '' !== $open ) {
					$carrier_open = preg_replace( '/\bclass=(["\'])(.*?)\1/is', 'class=$1$2 ' . $carrier_classes . '$1', $open, 1 ) ?? $open;
					return $carrier_open . $input . $close;
				}
				if ( '' !== $carrier_classes ) {
					return '<div class="' . $carrier_classes . '">' . $input . '</div>';
				}
				return $open . $input . $close;
			},
			$projected,
			1
		);
		$wrapped  = is_string( $wrapped ) ? $wrapped : $projected;
		if ( ! empty( $composite_layers ) ) {
			$document        = new \DOMDocument();
			$previous_errors = libxml_use_internal_errors( true );
			$loaded          = $document->loadHTML( '<?xml encoding="utf-8" ?><body>' . $wrapped . '</body>', LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
			if ( $loaded ) {
				$xpath = new \DOMXPath( $document );
				foreach ( array(
					'shell'  => 'jetpack-field__input-phone-wrapper',
					'prefix' => 'jetpack-field__input-prefix',
				) as $role => $class ) {
					$targets = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' );
					$target  = false === $targets ? null : $targets->item( 0 );
					$layers  = $composite_layers[ $role ] ?? array();
					if ( ! $target instanceof \DOMElement || null === $target->parentNode || empty( $layers ) ) {
						continue;
					}
					ksort( $layers );
					foreach ( $layers as $classes ) {
						$layer = $document->createElement( 'div' );
						$layer->setAttribute( 'class', implode( ' ', array_unique( $classes ) ) );
						$target->parentNode->insertBefore( $layer, $target );
						$layer->appendChild( $target );
					}
				}
				$body    = $document->getElementsByTagName( 'body' )->item( 0 );
				$wrapped = '';
				foreach ( $body->childNodes as $child ) {
					$wrapped .= $document->saveHTML( $child );
				}
			}
		}
		return self::project_semantic_wrappers( $wrapped );
	}

	/** Restore a bounded source paragraph around a provider-owned field or button. */
	private static function project_semantic_wrappers( string $html ): string {
		$wrappers  = array();
		$projected = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$wrappers ): string {
				$classes = preg_split( '/\s+/', trim( $matches[2] ) );
				$output  = array();
				foreach ( false === $classes ? array() : $classes as $class ) {
					if ( preg_match( '/^ssi-source-semantic-wrapper-([0-9]{1,2})--p(?:--([A-Za-z_][A-Za-z0-9_-]{0,79}))?$/D', $class, $marker ) ) {
						$wrappers[ (int) $marker[1] ][] = $marker[2] ?? '';
						continue;
					}
					$output[] = $class;
				}
				return 'class=' . $matches[1] . implode( ' ', $output ) . $matches[1];
			},
			$html,
			1
		);
		if ( ! is_string( $projected ) || empty( $wrappers ) ) {
			return is_string( $projected ) ? $projected : $html;
		}
		ksort( $wrappers );
		foreach ( array_reverse( $wrappers, true ) as $classes ) {
			$classes   = array_values( array_filter( array_unique( $classes ) ) );
			$attribute = empty( $classes ) ? '' : ' class="' . implode( ' ', $classes ) . '"';
			$projected = '<p' . $attribute . '>' . $projected . '</p>';
		}
		return $projected;
	}
}
