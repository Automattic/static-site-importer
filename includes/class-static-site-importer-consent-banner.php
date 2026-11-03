<?php
/**
 * Producer-neutral consent intent and Jetpack's site-wide option owner.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Static_Site_Importer_Consent_Banner {
	public const PATH = 'website/consent-banner.json';

	/** Extract evidence before compile, including referenced artifact payloads. */
	public static function extract( array &$artifact, array &$args, ?object $reader = null ): void {
		foreach ( $artifact['files'] ?? array() as $index => $file ) {
			if ( self::PATH !== ( $file['path'] ?? '' ) ) {
				continue;
			}
			unset( $artifact['files'][ $index ] );
			try {
				$bytes = $file['content'] ?? null;
				if ( isset( $file['content_base64'] ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Artifact transport.
					$bytes = base64_decode( $file['content_base64'], true );
				} elseif ( isset( $file['payload_reference'] ) && null !== $reader && method_exists( $reader, 'read' ) ) {
					$bytes = $reader->read( $file['payload_reference'] );
				}
				$fact  = is_string( $bytes ) && strlen( $bytes ) <= 1048576 ? json_decode( $bytes, true, 16 ) : null;
				$valid = is_array( $fact ) && 'static-site-importer/consent-banner/v1' === ( $fact['schema'] ?? '' );
				foreach ( array( 'text', 'accept_label' ) as $key ) {
					$valid = $valid && is_string( $fact[ $key ] ?? null ) && '' !== trim( $fact[ $key ] );
				}
				if ( $valid ) {
					$args['consent_banner'] = $fact;
				} else {
					$args = Static_Site_Importer_Content_Policy::with_intake_findings( $args, array( self::finding( 'consent_banner_unreadable', 'Invalid schema or missing text or accept_label.' ) ) );
				}
			} catch ( Throwable $error ) {
				$args = Static_Site_Importer_Content_Policy::with_intake_findings( $args, array( self::finding( 'consent_banner_unreadable', $error->getMessage() ) ) );
			}
		}
		$artifact['files'] = array_values( $artifact['files'] ?? array() );
	}

	/** Declare the same plugin dependency lifecycle used by native form adapters. */
	public static function adapter(): array {
		return array(
			'id'                     => 'jetpack_cookie_consent',
			'capability'             => 'consent_banner',
			'rollback_contract_id'   => 'static-site-importer/consent-option-journal/v1',
			'intent_callback'        => array( self::class, 'has_intent' ),
			'provider'               => 'jetpack',
			'best_effort_dependency' => true,
			'waiver_arg'             => 'allow_missing_jetpack',
			'dependencies'           => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'jetpack',
					'plugin_file'           => 'jetpack/jetpack.php',
					'availability_callback' => array( self::class, 'available' ),
					'preparation_callback'  => array( self::class, 'prepare_runtime' ),
					'provider_readiness'    => array( 'required_block_types' => array( 'jetpack/cookie-consent' ) ),
				),
			),
		);
	}

	/** Missing optional runtime still degrades cleanly when provisioning is disabled. */
	public static function has_intent( array $plan, array $args ): bool {
		return ! empty( $args['consent_banner'] ) && ( ! empty( $args['materialize_dependencies'] ) || 'plan' === ( $args['runtime_lifecycle_phase'] ?? '' ) );
	}

	public static function available(): bool {
		return class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'jetpack/cookie-consent' );
	}

	/** Boot-time registration is completed on the lifecycle's fresh request. */
	public static function prepare_runtime() {
		return Static_Site_Importer_Jetpack_Runtime::prepare_modules( array( 'blocks' ) );
	}

	/** Materialize only the native runtime; the persistence journal owns rollback. */
	public static function materialize( array &$state ): array {
		$fact = $state['args']['consent_banner'] ?? null;
		if ( ! is_array( $fact ) ) {
			return array();
		}
		if ( ! self::available() ) {
			return array( 'findings' => array( self::finding( 'consent_banner_not_materialized', 'The jetpack/cookie-consent block runtime is unavailable.' ) ) );
		}
		Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, 'cookie_consent_template' );
		if ( ! Static_Site_Importer_Site_Plan_Persistence::write_option( 'cookie_consent_template', self::markup( $fact ) ) ) {
			return array( 'findings' => array( self::finding( 'consent_banner_not_materialized', 'The cookie_consent_template option could not be written.' ) ) );
		}
		return array(
			'status' => 'materialized',
			'block'  => 'jetpack/cookie-consent',
			'option' => 'cookie_consent_template',
		);
	}

	/** Match Jetpack save.jsx, block.json and its serialized fixture, not builder HTML. */
	public static function markup( array $fact ): string {
		$text = esc_html( $fact['text'] );
		$link = $fact['policy_link'] ?? null;
		if ( is_array( $link ) && is_string( $link['label'] ?? null ) && is_string( $link['href'] ?? null ) && preg_match( '#^https?://#i', $link['href'] ) ) {
			$text .= ' <a href="' . esc_url( $link['href'] ) . '">' . esc_html( $link['label'] ) . '</a>';
		}
		$colors       = is_array( $fact['colors'] ?? null ) ? $fact['colors'] : array();
		$banner       = self::color_style( $colors, 'text', 'background' );
		$button       = self::color_style( $colors, 'button_text', 'button_background' );
		$presentation = self::presentation( $fact );
		$button_style = array( 'color' => $button );
		$button_css   = '';
		foreach ( $presentation['button'] ?? array() as $key => $value ) {
			$mapping                                 = array(
				'border_radius' => array( 'border', 'radius', 'border-radius' ),
				'border_width'  => array( 'border', 'width', 'border-width' ),
				'border_style'  => array( 'border', 'style', 'border-style' ),
				'border_color'  => array( 'border', 'color', 'border-color' ),
				'padding'       => array( 'spacing', 'padding', 'padding' ),
				'font_size'     => array( 'typography', 'fontSize', 'font-size' ),
				'font_family'   => array( 'typography', 'fontFamily', 'font-family' ),
				'line_height'   => array( 'typography', 'lineHeight', 'line-height' ),
			);
			list( $group, $property, $css_property ) = $mapping[ $key ];
			$button_style[ $group ][ $property ]     = $value;
			$button_css                             .= $css_property . ':' . $value . ';';
		}
		$button_attrs = array( 'style' => $button_style );
		$button_html  = '<div class="wp-block-button"><a class="wp-block-button__link' . self::color_classes( $button ) . ( isset( $button_style['border']['color'] ) ? ' has-border-color' : '' ) . ( isset( $button_style['typography']['fontSize'] ) ? ' has-custom-font-size' : '' ) . ' wp-element-button"' . self::style_attribute( $button, $button_css ) . '>' . esc_html( $fact['accept_label'] ) . '</a></div>';
		$inner        = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button ' . wp_json_encode( $button_attrs ) . ' -->' . "\n" . $button_html . "\n" . '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->';
		$attrs        = array( 'style' => array( 'color' => $banner ) );
		$align        = 'wide';
		$banner_css   = '';
		$custom_class = '';
		if ( array() !== $presentation ) {
			$align              = 'full' === ( $presentation['span'] ?? '' ) ? 'full' : 'wide';
			$attrs['align']     = $align;
			$attrs['className'] = 'ssi-consent-banner';
			$custom_class       = ' ssi-consent-banner';
			if ( isset( $presentation['padding'] ) ) {
				$attrs['style']['spacing']['padding'] = $presentation['padding'];
				$banner_css                           = 'padding:' . $presentation['padding'] . ';';
			}
		}
		return '<!-- wp:jetpack/cookie-consent ' . wp_json_encode( $attrs ) . ' -->' . "\n" . '<div class="wp-block-jetpack-cookie-consent align' . $align . self::color_classes( $banner ) . $custom_class . '"' . self::style_attribute( $banner, $banner_css ) . ' role="dialog" aria-modal="true"><p>' . $text . '</p>' . $inner . '<span>365</span></div>' . "\n" . '<!-- /wp:jetpack/cookie-consent -->';
	}

	/** Accept CSS colors only, never arbitrary source styles. */
	private static function color_style( array $colors, string $text, string $background ): array {
		$result = array();
		foreach ( array(
			'text'       => $text,
			'background' => $background,
		) as $key => $source ) {
			$value = $colors[ $source ] ?? null;
			if ( is_string( $value ) && preg_match( '/^(#[a-f0-9]{3,8}|rgba?\([0-9.,%\s]+\)|[a-z]+)$/i', $value ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	private static function color_classes( array $colors ): string {
		return ( isset( $colors['text'] ) ? ' has-text-color' : '' ) . ( isset( $colors['background'] ) ? ' has-background' : '' );
	}

	private static function style_attribute( array $colors, string $extra = '' ): string {
		$css = $extra;
		foreach ( $colors as $key => $value ) {
			$css .= ( 'text' === $key ? 'color' : 'background-color' ) . ':' . $value . ';';
		}
		return '' === $css ? '' : ' style="' . esc_attr( rtrim( $css, ';' ) ) . '"';
	}

	/** Admit only typed presentation values, never source declarations or functions. */
	private static function presentation( array $fact ): array {
		$input  = $fact['presentation'] ?? null;
		$result = array();
		if ( ! is_array( $input ) ) {
			return $result;
		}
		foreach ( $input as $key => $value ) {
			if ( 'span' === $key && in_array( $value, array( 'full', 'inset' ), true ) ) {
				$result[ $key ] = $value;
			} elseif ( 'button' === $key && is_array( $value ) ) {
				$result['button'] = array();
				foreach ( array( 'border_radius', 'border_width', 'border_style', 'border_color', 'padding', 'font_size', 'font_family', 'line_height' ) as $property ) {
					if ( self::presentation_value( $property, $value[ $property ] ?? null ) ) {
						$result['button'][ $property ] = trim( $value[ $property ] );
					}
				}
			} elseif ( 'layout' === $key && is_array( $value ) && array_is_list( $value ) ) {
				$result['layout'] = array();
				foreach ( $value as $entry ) {
					if ( self::valid_layout( $entry ) ) {
						$result['layout'][] = $entry;
					}
				}
			} elseif ( in_array( $key, array( 'edge_offset', 'padding', 'font_family', 'font_size', 'line_height', 'text_margin' ), true ) && self::presentation_value( $key, $value ) ) {
				$result[ $key ] = trim( $value );
			}
		}
		return $result;
	}

	private static function presentation_value( string $key, $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( 'border_style' === $key ) {
			return in_array( $value, array( 'solid', 'dashed', 'dotted', 'double', 'none' ), true );
		}
		if ( 'border_color' === $key ) {
			return array() !== self::color_style( array( 'border' => $value ), 'border', 'unused' );
		}
		if ( 'font_family' === $key ) {
			$family = '(?:[a-zA-Z][a-zA-Z0-9 -]*|"[a-zA-Z0-9 -]+"|\'[a-zA-Z0-9 -]+\')';
			return 1 === preg_match( '/^' . $family . '(?:\\s*,\\s*' . $family . ')*$/D', $value );
		}
		$length = '(?:0|(?:[0-9]+(?:\\.[0-9]+)?|\\.[0-9]+)(?:px|em|rem|%|vh|vw|vmin|vmax|pt|pc|in|cm|mm))';
		$count  = in_array( $key, array( 'padding', 'border_radius', 'text_margin' ), true ) ? '{0,3}' : '{0}';
		return 1 === preg_match( '/^' . $length . '(?:\\s+' . $length . ')' . $count . '$/D', $value );
	}

	/** Viewport bounds are finite, nonnegative pixel numbers, not CSS strings. */
	private static function valid_layout( $entry ): bool {
		if ( ! is_array( $entry ) || ! in_array( $entry['direction'] ?? null, array( 'row', 'column' ), true ) ) {
			return false;
		}
		foreach ( array( 'min_width', 'max_width' ) as $key ) {
			if ( array_key_exists( $key, $entry ) && ( ( ! is_int( $entry[ $key ] ) && ! is_float( $entry[ $key ] ) ) || ! is_finite( (float) $entry[ $key ] ) || $entry[ $key ] < 0 ) ) {
				return false;
			}
		}
		if ( isset( $entry['min_width'], $entry['max_width'] ) && $entry['min_width'] > $entry['max_width'] ) {
			return false;
		}
		foreach ( array(
			'align_items'  => array( 'center', 'flex-start', 'flex-end', 'stretch' ),
			'button_align' => array( 'start', 'end' ),
		) as $key => $allowed ) {
			if ( array_key_exists( $key, $entry ) && ! in_array( $entry[ $key ], $allowed, true ) ) {
				return false;
			}
		}
		return ! array_key_exists( 'text_margin', $entry ) || self::presentation_value( 'text_margin', $entry['text_margin'] );
	}

	/** Generated theme rules outrank Jetpack's two-class-specificity edge rule. */
	public static function stylesheet( array $fact ): string {
		$presentation = self::presentation( $fact );
		$css          = '';
		foreach ( array(
			'edge_offset' => 'top' === ( $fact['position'] ?? '' ) ? 'top' : 'bottom',
			'font_family' => 'font-family',
			'font_size'   => 'font-size',
			'line_height' => 'line-height',
		) as $key => $property ) {
			if ( isset( $presentation[ $key ] ) ) {
				$css .= $property . ':' . $presentation[ $key ] . ';';
			}
		}
		$selector = '.wp-block-jetpack-cookie-consent.ssi-consent-banner:not([role="document"])';
		$flex     = isset( $presentation['text_margin'] ) || ! empty( $presentation['layout'] );
		if ( $flex ) {
			$css .= 'flex-wrap:nowrap;';
		}
		$output = '' === $css ? '' : "\n" . $selector . '{' . $css . "}\n";
		if ( $flex ) {
			$output .= $selector . ' > p{flex:1 1 auto;' . ( isset( $presentation['text_margin'] ) ? 'margin:' . $presentation['text_margin'] . ';' : '' ) . "}\n";
		}
		foreach ( $presentation['layout'] ?? array() as $entry ) {
			$conditions = array();
			foreach ( array(
				'min_width' => 'min-width',
				'max_width' => 'max-width',
			) as $key => $property ) {
				if ( isset( $entry[ $key ] ) ) {
					$conditions[] = '(' . $property . ':' . $entry[ $key ] . 'px)';
				}
			}
			$rules = $selector . '{flex-direction:' . $entry['direction'] . ';' . ( isset( $entry['align_items'] ) ? 'align-items:' . $entry['align_items'] . ';' : '' ) . '}';
			if ( isset( $entry['text_margin'] ) ) {
				$rules .= $selector . ' > p{margin:' . trim( $entry['text_margin'] ) . ';}';
			}
			if ( 'column' === $entry['direction'] && isset( $entry['button_align'] ) ) {
				$end    = 'end' === $entry['button_align'];
				$rules .= $selector . ' > .wp-block-buttons{align-self:flex-' . ( $end ? 'end' : 'start' ) . ';margin-left:' . ( $end ? 'auto' : '0' ) . ';margin-right:' . ( $end ? '0' : 'auto' ) . ';}';
			}
			$output .= ( $conditions ? '@media ' . implode( ' and ', $conditions ) . '{' . $rules . '}' : $rules ) . "\n";
		}
		return $output;
	}

	private static function finding( string $code, string $message ): array {
		return array(
			'code'         => $code,
			'severity'     => 'warning',
			'message'      => $message,
			'source_path'  => self::PATH,
			'stage'        => 'materialization',
			'owning_layer' => 'static-site-importer',
			'loss_class'   => 'degraded_unit',
			'unit'         => 'item',
		);
	}
}
