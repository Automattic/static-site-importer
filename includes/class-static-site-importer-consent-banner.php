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
		$button_attrs = array( 'style' => array( 'color' => $button ) );
		$button_html  = '<div class="wp-block-button"><a class="wp-block-button__link' . self::color_classes( $button ) . ' wp-element-button"' . self::style_attribute( $button ) . '>' . esc_html( $fact['accept_label'] ) . '</a></div>';
		$inner        = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button ' . wp_json_encode( $button_attrs ) . ' -->' . "\n" . $button_html . "\n" . '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->';
		$attrs        = array( 'style' => array( 'color' => $banner ) );
		return '<!-- wp:jetpack/cookie-consent ' . wp_json_encode( $attrs ) . ' -->' . "\n" . '<div class="wp-block-jetpack-cookie-consent alignwide' . self::color_classes( $banner ) . '"' . self::style_attribute( $banner ) . ' role="dialog" aria-modal="true"><p>' . $text . '</p>' . $inner . '<span>365</span></div>' . "\n" . '<!-- /wp:jetpack/cookie-consent -->';
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

	private static function style_attribute( array $colors ): string {
		$css = '';
		foreach ( $colors as $key => $value ) {
			$css .= ( 'text' === $key ? 'color' : 'background-color' ) . ':' . $value . ';';
		}
		return '' === $css ? '' : ' style="' . esc_attr( rtrim( $css, ';' ) ) . '"';
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
