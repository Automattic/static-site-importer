<?php
/**
 * Runtime presentation projection for materialized provider forms.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Static_Site_Importer_Provider_Form_Runtime', false ) ) {
	return;
}

/** Keeps source form presentation attached to provider-rendered controls. */
final class Static_Site_Importer_Provider_Form_Runtime {
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
		if ( ! str_contains( $class_name, 'ssi-source-submit--' ) ) {
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
		if ( ! is_string( $projected ) || empty( $source_classes ) ) {
			return $html;
		}
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
		return is_string( $projected ) ? $projected : $html;
	}

	/** Rebuild source input-only wrapper layers inside Jetpack's field shell. */
	public static function project_wrapper_classes( string $html ): string {
		$wrapper_layers = array();
		$projected      = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ) use ( &$wrapper_layers ): string {
				$classes    = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes    = false === $classes ? array() : $classes;
				$is_wrapper = (bool) array_filter( $classes, static fn ( string $class_name ): bool => 1 === preg_match( '/^grunion-field-[A-Za-z0-9_-]+-wrap$/D', $class_name ) );
				$output     = array();
				foreach ( $classes as $class_name ) {
					if ( preg_match( '/^ssi-source-wrapper-([0-9]{1,2})--([A-Za-z_][A-Za-z0-9_-]{0,79})-wrap$/D', $class_name, $marker ) ) {
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
		if ( ! is_string( $projected ) || empty( $wrapper_layers ) ) {
			return is_string( $projected ) ? $projected : $html;
		}

		ksort( $wrapper_layers );
		$open  = '';
		$close = '';
		foreach ( $wrapper_layers as $classes ) {
			$classes = array_values( array_unique( $classes ) );
			$open   .= '<div class="' . implode( ' ', $classes ) . '">';
			$close   = '</div>' . $close;
		}
		$wrapped = preg_replace_callback(
			'/<input\b[^>]*>|<textarea\b[^>]*>.*?<\/textarea>|<select\b[^>]*>.*?<\/select>/is',
			static fn ( array $control_match ): string => $open . $control_match[0] . $close,
			$projected,
			1
		);
		return is_string( $wrapped ) ? $wrapped : $projected;
	}
}
