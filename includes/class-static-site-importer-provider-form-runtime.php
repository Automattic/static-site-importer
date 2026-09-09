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

	/** Register inert-unless-marked provider projection hooks. */
	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_wrapper_classes' ) );
		add_filter( 'render_block_core/button', array( __CLASS__, 'project_submit_presentation' ), 10, 2 );
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
			$projected      = preg_replace_callback(
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
		$phone_destination_classes = array();
		$projected                 = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$wrapper_layers, &$composite_layers, &$provider_layout_classes, &$phone_destination_classes ): string {
				$classes        = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes        = false === $classes ? array() : $classes;
				$is_wrapper     = (bool) array_filter( $classes, static fn ( string $class_name ): bool => 1 === preg_match( '/^grunion-field-[A-Za-z0-9_-]+-wrap$/D', $class_name ) );
				$is_phone_shell = in_array( 'jetpack-field__input-phone-wrapper', $classes, true );
				$output         = array();
				foreach ( $classes as $class_name ) {
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
		if ( ! is_string( $projected ) || ( empty( $wrapper_layers ) && empty( $composite_layers ) && empty( $phone_destination_classes ) ) ) {
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
