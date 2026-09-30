<?php
/**
 * WordPress and Jetpack symbols the standalone form seeder CLI fixtures need.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols across namespaces outside a WordPress runtime.

namespace Automattic\Jetpack\Forms\ContactForm {
	class Contact_Form {}
}

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
			return json_encode( $value, $flags, max( 1, $depth ) );
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			return strip_tags( $text );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	$GLOBALS['ssi_test_hooks'] = array();
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback ): void {
			$GLOBALS['ssi_test_hooks'][ $hook ][] = $callback;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			foreach ( $GLOBALS['ssi_test_hooks'][ $hook ] ?? array() as $callback ) {
				$value = $callback( $value, ...$args );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return $GLOBALS['ssi_test_options'][ $name ] ?? $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value, $autoload = null ): bool {
			unset( $autoload );
			$GLOBALS['ssi_test_options'][ $name ] = $value;
			return true;
		}
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message = '', private $data = null ) {}
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}
	if ( ! function_exists( 'serialize_block' ) ) {
		function serialize_block( array $block ): string {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) && ! empty( $block['attrs'] ) ? ' ' . (string) wp_json_encode( $block['attrs'] ) : '';
			$inner = '';
			$index = 0;
			foreach ( $block['innerContent'] ?? array() as $piece ) {
				if ( null === $piece ) {
					$child  = $block['innerBlocks'][ $index ] ?? null;
					$inner .= is_array( $child ) ? serialize_block( $child ) : '';
					++$index;
					continue;
				}
				$inner .= (string) $piece;
			}
			if ( '' === $name ) {
				return $inner;
			}
			return '<!-- wp:' . $name . $attrs . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
		}
	}
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	}
	$GLOBALS['ssi_jetpack_form_blocks_available']  = true;
	$GLOBALS['ssi_jetpack_registered_form_blocks'] = array(
		'jetpack/contact-form',
		'jetpack/field-checkbox',
		'jetpack/field-checkbox-multiple',
		'jetpack/field-date',
		'jetpack/field-email',
		'jetpack/field-number',
		'jetpack/field-radio',
		'jetpack/field-select',
		'jetpack/field-telephone',
		'jetpack/field-text',
		'jetpack/field-textarea',
		'jetpack/field-url',
		'jetpack/input',
		'jetpack/label',
		'jetpack/option',
		'jetpack/options',
		'jetpack/phone-input',
	);
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			public static function get_instance(): self {
				return new self();
			}
			public function is_registered( string $name ): bool {
				return ! empty( $GLOBALS['ssi_jetpack_form_blocks_available'] ) && in_array( $name, $GLOBALS['ssi_jetpack_registered_form_blocks'] ?? array(), true );
			}
		}
	}
	if ( ! class_exists( 'Grunion_Contact_Form' ) ) {
		class Grunion_Contact_Form {}
	}
	require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
}
