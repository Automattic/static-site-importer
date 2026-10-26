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

	/**
	 * Admit a bounded list of captured inline SVG parts for a submit button.
	 *
	 * Every part must pass the portable inline-SVG admission on its own; the
	 * list is capped so one captured control cannot grow the saved markup
	 * without bound.
	 *
	 * @param mixed $parts Candidate list of SVG markup strings.
	 * @return array<int,string>
	 */
	public static function valid_icon_parts( mixed $parts ): array {
		if ( ! is_array( $parts ) || ! array_is_list( $parts ) ) {
			return array();
		}
		$valid = array();
		foreach ( $parts as $part ) {
			if ( ! is_string( $part ) || '' === trim( $part ) || strlen( $part ) > 12288 || ! self::valid_inline_svg( $part ) ) {
				continue;
			}
			$valid[] = $part;
			if ( 4 === count( $valid ) ) {
				break;
			}
		}
		return $valid;
	}



	/** Register inert-unless-marked provider projection hooks. */
	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_wrapper_classes' ) );
		add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_choice_values' ), 30 );
		add_filter( 'render_block_core/button', array( __CLASS__, 'project_submit_presentation' ), 10, 2 );
		add_filter( 'shortcode_atts_contact-field', array( __CLASS__, 'project_help_text_attribute' ), 10, 3 );
	}

	/** Encode source label/value pairs in the editable field's persisted class attribute. */
	public static function choice_token( mixed $options ): string {
		if ( ! is_array( $options ) || ! array_is_list( $options ) || count( $options ) < 1 || count( $options ) > 32 ) {
			return '';
		}
		$values         = array();
		$selected_count = 0;
		$labels         = array();
		foreach ( $options as $option ) {
			if ( is_array( $option ) && ! empty( $option['placeholder'] ) ) {
				continue;
			}
			if ( ! is_array( $option ) || ! is_string( $option['label'] ?? null ) || ! is_string( $option['value'] ?? null ) || '' === trim( $option['label'] ) || strlen( $option['label'] ) > 200 || strlen( $option['value'] ) > 200 || isset( $labels[ $option['label'] ] ) ) {
				return '';
			}
			$labels[ $option['label'] ] = true;
			$selected_count            += ! empty( $option['selected'] ) ? 1 : 0;
			if ( $selected_count > 1 ) {
				return '';
			}
			$values[] = array( $option['label'], $option['value'], ! empty( $option['selected'] ) );
		}
		if ( array() === $values ) {
			return '';
		}
		$encoded = base64_encode( (string) wp_json_encode( $values ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes bounded choice data for a persisted CSS-class transport token, not obfuscation.
		return strlen( $encoded ) <= 8192 ? 'ssi-choice-' . rtrim( strtr( $encoded, '+/', '-_' ), '=' ) : '';
	}

	/** Apply the captured native option values and selected state to Jetpack's select. */
	public static function project_choice_values( string $html ): string {
		if ( strlen( $html ) > 262144 || ! preg_match( '/\bssi-choice-([A-Za-z0-9_-]{1,8192})\b/', $html, $matches ) ) {
			return $html;
		}
		$json = base64_decode( strtr( $matches[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the persisted choice-data transport token before validating its shape.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) && str_ends_with( $matches[1], '-wrap' ) ) {
			$json = base64_decode( strtr( substr( $matches[1], 0, -5 ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the same token after removing Jetpack's appended wrapper suffix.
			$data = is_string( $json ) ? json_decode( $json, true ) : null;
		}
		if ( ! is_array( $data ) || count( $data ) > 32 || ! str_contains( $html, '<select' ) ) {
			return $html;
		}
		$ordinal = 0;
		$found   = false;
		$updated = preg_replace_callback( '/<option\b([^>]*)>(.*?)<\/option>/si', static function ( array $matches ) use ( $data, &$ordinal, &$found ): string {
			if ( ! isset( $data[ $ordinal ] ) || ! is_array( $data[ $ordinal ] ) || count( $data[ $ordinal ] ) !== 3 ) {
				return $matches[0];
			}
			[ $label, $value, $selected ] = $data[ $ordinal ];
			if ( ! is_string( $label ) || ! is_string( $value ) || ! is_bool( $selected ) || html_entity_decode( wp_strip_all_tags( $matches[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $label ) {
				return $matches[0];
			}
			++$ordinal;
			$found = true;
			$attrs = preg_replace( '/\s+(?:value=(?:"[^"]*"|\x27[^\x27]*\x27)|selected(?:=(?:"[^"]*"|\x27[^\x27]*\x27))?)/i', '', $matches[1] );
			return '<option' . $attrs . ' value="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . $matches[2] . '</option>';
		}, $html );
		return $found && count( $data ) === $ordinal && is_string( $updated ) ? $updated : $html;
	}

	/**
	 * Jetpack's field block stores help as `helpText`; the shortcode renderer
	 * reads `helptext`. Copy the block attribute onto the shortcode key so a
	 * source-authored description actually renders.
	 *
	 * @param array<string,mixed> $out   Shortcode attributes after defaults.
	 * @param array<string,mixed> $pairs Unused default pairs.
	 * @param array<string,mixed> $atts  Original block/shortcode attributes.
	 * @return array<string,mixed>
	 */
	public static function project_help_text_attribute( array $out, array $pairs, array $atts ): array {
		unset( $pairs );
		$help_text = $out['helptext'] ?? null;
		if ( is_string( $help_text ) && '' !== trim( $help_text ) ) {
			return $out;
		}
		$block_help = $atts['helpText'] ?? null;
		if ( is_string( $block_help ) && '' !== trim( $block_help ) ) {
			$out['helptext'] = $block_help;
		}
		return $out;
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
					if ( preg_match( '/^ssi-source-submit--([A-Za-z_][A-Za-z0-9_:-]{0,79})$/D', $candidate, $marker ) ) {
						if ( 1 === preg_match( '/^m[trblxyse]?-/', $marker[1] ) ) {
							$output[] = $marker[1];
							continue;
						}
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
			$projected      = preg_replace_callback(
			'/<button\b([^>]*)>/is',
			static function ( array $matches ) use ( $source_classes ): string {
				$attributes = $matches[1];
				if ( preg_match( '/\bclass=(["\'])(.*?)\1/is', $attributes ) ) {
					$attributes = preg_replace( '/\bclass=(["\'])(.*?)\1/is', 'class=$1$2 ' . implode( ' ', $source_classes ) . '$1', $attributes, 1 ) ?? $attributes;
				} else {
					$attributes .= ' class="' . implode( ' ', $source_classes ) . '"';
				}
				return '<button' . $attributes . '>';
			},
			$projected,
			1
			);
		}
		return is_string( $projected ) ? $projected : $html;
	}

	/**
	 * Move help presentation classes from the field shell to its description.
	 *
	 * @param string $html Provider field HTML.
	 * @return string
	 */
	private static function project_help_classes( string $html ): string {
		$classes = array();
		$html    = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$classes ): string {
				if ( ! preg_match( '/\bgrunion-field-[A-Za-z0-9_-]+-wrap\b/', $matches[2] ) ) {
					return $matches[0];
				}
				$output = array();
				$tokens = preg_split( '/\s+/', trim( $matches[2] ) );
				foreach ( false === $tokens ? array() : $tokens as $class_name ) {
					if ( preg_match( '/^ssi-source-help--([A-Za-z_][A-Za-z0-9_-]{0,79})-wrap$/D', $class_name, $marker ) ) {
						$classes[] = $marker[1];
					} else {
						$output[] = $class_name;
					}
				}
				return 'class=' . $matches[1] . implode( ' ', $output ) . $matches[1];
			},
			$html
		) ?? $html;
		if ( array() === $classes ) {
			return $html;
		}
		return preg_replace_callback(
			'/\bclass=(["\'])([^"\']*\bcontact-form__field-help\b[^"\']*)\1/',
			static fn ( array $matches ): string => 'class=' . $matches[1] . $matches[2] . ' ' . implode( ' ', array_unique( $classes ) ) . $matches[1],
			$html
		) ?? $html;
	}

	/** Apply field help, choice label, phone destination and textarea row hooks. */
	public static function project_wrapper_classes( string $html ): string {
		// A telephone field without the country selector renders as a plain tel
		// input ($is_plain_tel). It has no shell, carrier, or prefix box for those
		// hooks to style.
		$html                      = self::project_help_classes( $html );
		$choice_label              = array();
		$phone_destination_classes = array();
		$textarea_rows             = null;
		$is_phone                  = (bool) preg_match( '/\bclass=(["\'])[^"\']*\bgrunion-field-(?:phone|telephone)-wrap\b[^"\']*\1/i', $html );
		$is_plain_tel              = $is_phone && ! str_contains( $html, 'jetpack-field__input-phone-wrapper' );
		$projected                 = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( $is_plain_tel, &$choice_label, &$phone_destination_classes, &$textarea_rows ): string {
				$classes        = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes        = false === $classes ? array() : $classes;
				$is_wrapper     = (bool) array_filter( $classes, static fn ( string $class_name ): bool => 1 === preg_match( '/^grunion-field-[A-Za-z0-9_-]+-wrap$/D', $class_name ) );
				$is_phone_shell = in_array( 'jetpack-field__input-phone-wrapper', $classes, true );
				$output         = array();
				foreach ( $classes as $class_name ) {
					$transport = $is_wrapper && str_ends_with( $class_name, '-wrap' ) ? substr( $class_name, 0, -5 ) : $class_name;
					if ( preg_match( '/^ssi-source-choice-label--([A-Za-z_][A-Za-z0-9_-]{0,79})$/D', $transport, $marker ) ) {
						if ( $is_wrapper ) {
							$choice_label[] = $marker[1];
						}
						continue;
					}
					if ( preg_match( '/^ssi-textarea-rows-([1-9][0-9]{0,1})$/D', $class_name, $marker ) ) {
						$textarea_rows = $marker[1];
						continue;
					}
					if ( $is_plain_tel && 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-destination-(?:shell|carrier|prefix)$/D', $class_name ) ) {
						// Their resets (`height:100%`, zero padding and border) would
						// land on the input itself and override its captured box.
						continue;
					}
					if ( $is_phone_shell && 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-destination-(?:primary|carrier|prefix)$/D', $class_name ) ) {
						$phone_destination_classes[] = $class_name;
						continue;
					}
					if ( $is_wrapper && 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-wrap$/D', $class_name ) ) {
						$output[] = $class_name;
						continue;
					}
					$output[] = $class_name;
				}
				return 'class=' . $matches[1] . implode( ' ', array_values( array_unique( $output ) ) ) . $matches[1];
			},
			$html
		);
		if ( ! is_string( $projected ) ) {
			return $html;
		}
		$pattern                    = $is_phone
			? '/<input\b(?=[^>]*\btype\s*=\s*(["\'])tel\1)[^>]*>/is'
			: '/<input\b[^>]*>|<textarea\b[^>]*>.*?<\/textarea>|<select\b[^>]*>.*?<\/select>/is';
		$prefix_destination_classes = array_values( array_filter( $phone_destination_classes, static fn( string $class_name ): bool => str_ends_with( $class_name, '-destination-prefix' ) ) );
		$phone_destination_classes  = array_values( array_filter( $phone_destination_classes, static fn( string $class_name ): bool => ! str_ends_with( $class_name, '-destination-prefix' ) ) );
		$wrapped                    = preg_replace_callback(
			$pattern,
			static function ( array $control_match ) use ( $is_phone, $phone_destination_classes ): string {
				if ( ! $is_phone || empty( $phone_destination_classes ) ) {
					return $control_match[0];
				}
				$value_classes   = implode( ' ', array_filter( $phone_destination_classes, static fn( string $class_name ): bool => str_ends_with( $class_name, '-destination-primary' ) ) );
				$carrier_classes = implode( ' ', array_filter( $phone_destination_classes, static fn( string $class_name ): bool => str_ends_with( $class_name, '-destination-carrier' ) ) );
				if ( '' === $value_classes ) {
					return $control_match[0];
				}
				$input = preg_replace( '/\bclass=(["\'])(.*?)\1/is', 'class=$1$2 ' . $value_classes . '$1', $control_match[0], 1 ) ?? $control_match[0];
				if ( '' !== $carrier_classes ) {
					return '<div class="' . $carrier_classes . '">' . $input . '</div>';
				}
				return $input;
			},
			$projected,
			1
		);
		$wrapped                    = is_string( $wrapped ) ? $wrapped : $projected;
		if ( ! empty( $prefix_destination_classes ) ) {
			$prefix_classes = implode( ' ', array_unique( $prefix_destination_classes ) );
			$prefixed       = preg_replace_callback(
				'/<div\b([^>]*\bclass=("|\')([^"\']*\b(?:jetpack-field__input-prefix|jetpack-custom-combobox)\b[^"\']*)\2[^>]*)>/i',
				static function ( array $matches ) use ( $prefix_destination_classes, $prefix_classes ): string {
					$existing = preg_split( '/\s+/', trim( $matches[3] ) );
					$existing = false === $existing ? array() : $existing;
					if ( array() === array_diff( $prefix_destination_classes, $existing ) ) {
						return $matches[0];
					}
					return preg_replace( '/\bclass=("|\')(.*?)\1/is', 'class=$1$2 ' . $prefix_classes . '$1', $matches[0], 1 ) ?? $matches[0];
				},
				$wrapped
			);
			$wrapped        = is_string( $prefixed ) ? $prefixed : $wrapped;
		}
		return self::with_textarea_rows( self::project_choice_label( $wrapped, $choice_label ), $textarea_rows );
	}

	/** Carry the captured wrapping choice caption onto the native choice box. */
	private static function project_choice_label( string $html, array $choice_label ): string {
		if ( empty( $choice_label ) || strlen( $html ) > 262144 ) {
			return $html;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $html;
		}
		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return $html;
		}
		$field  = null;
		$choice = null;
		foreach ( $body->getElementsByTagName( 'div' ) as $element ) {
			$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
			$classes = false === $classes ? array() : $classes;
			if ( in_array( 'grunion-field-wrap', $classes, true ) ) {
				$field ??= $element;
			}
			if ( in_array( 'contact-form__checkbox-wrap', $classes, true ) ) {
				$choice ??= $element;
			}
		}
		if ( ! $field instanceof \DOMElement || ! $choice instanceof \DOMElement ) {
			return $html;
		}
		$existing_classes = preg_split( '/\s+/', $choice->getAttribute( 'class' ) );
		$existing_classes = false === $existing_classes ? array() : $existing_classes;
		$choice->setAttribute( 'class', implode( ' ', array_unique( array_merge( $existing_classes, $choice_label ) ) ) );
		$output = '';
		foreach ( $body->childNodes as $child ) {
			$output .= $document->saveHTML( $child );
		}
		return $output;
	}


	/** Apply a captured row count to the first textarea in a provider field. */
	private static function with_textarea_rows( string $html, ?string $rows ): string {
		if ( null === $rows || 1 !== preg_match( '/^[1-9][0-9]{0,1}$/D', $rows ) ) {
			return $html;
		}
		$replaced = preg_replace( '/(<textarea\b[^>]*\brows=)(["\'])[^"\']*\2/is', '${1}${2}' . $rows . '${2}', $html, 1 );
		if ( is_string( $replaced ) && $replaced !== $html ) {
			return $replaced;
		}
		$added = preg_replace( '/<textarea\b/i', '<textarea rows="' . $rows . '"', $html, 1 );
		return is_string( $added ) ? $added : $html;
	}
}
