<?php
/**
 * Emit the column-flex grid-span form the rendered layout regression measures.
 *
 * @package StaticSiteImporter
 */

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
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;
	$html = '<style>.stack{display:flex;flex-direction:column;gap:24px;width:100%}</style><form class="stack">'
		. '<div style="display:grid;width:100%;grid-template-columns:repeat(12, 1fr);column-gap:24px">'
		. '<div style="grid-column:1 / span 6"><label>First name</label><input type="text" name="first"></div>'
		. '<div style="grid-column:7 / span 6"><label>Last name</label><input type="text" name="last"></div>'
		. '<div style="grid-column:1 / span 12"><label>Message</label><textarea name="message"></textarea></div>'
		. '<div style="grid-column:1 / span 3"><button type="submit">Send</button></div>'
		. '</div></form>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup = (string) ( $row['block_markup'] ?? '' );
	$comment_json = static function ( string $markup, string $name ): array {
		$found = array();
		$offset = 0;
		$needle = '<!-- wp:' . $name . ' ';
		while ( false !== ( $start = strpos( $markup, $needle, $offset ) ) ) {
			$json_start = $start + strlen( $needle );
			if ( '{' !== ( $markup[ $json_start ] ?? '' ) ) {
				$offset = $json_start;
				continue;
			}
			$depth = 0;
			$end   = strlen( $markup );
			for ( $index = $json_start; $index < $end; ++$index ) {
				$depth += '{' === $markup[ $index ] ? 1 : ( '}' === $markup[ $index ] ? -1 : 0 );
				if ( 0 === $depth ) {
					$decoded = json_decode( substr( $markup, $json_start, $index - $json_start + 1 ), true );
					if ( is_array( $decoded ) ) {
						$found[] = $decoded;
					}
					$offset = $index + 1;
					continue 2;
				}
			}
			break;
		}
		return $found;
	};
	$fields = array();
	$labels = $comment_json( $markup, 'jetpack/label' );
	foreach ( array_merge( $comment_json( $markup, 'jetpack/field-text' ), $comment_json( $markup, 'jetpack/field-textarea' ) ) as $index => $attrs ) {
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		$classes = false === $classes ? array() : array_map( static fn ( string $class_name ): string => $class_name . '-wrap', array_filter( $classes ) );
		$fields[] = array(
			'label'   => (string) ( $labels[ $index ]['label'] ?? '' ),
			'classes' => implode( ' ', $classes ),
		);
	}
	$submit = array( 'classes' => '', 'text' => 'Send' );
	$buttons = array_merge( $comment_json( $markup, 'button' ), $comment_json( $markup, 'core/button' ) );
	if ( isset( $buttons[0] ) ) {
		$submit['classes'] = (string) ( $buttons[0]['className'] ?? '' );
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$attrs      = json_decode( $form[1], true );
		$form_class = (string) ( $attrs['className'] ?? '' );
	}
	echo (string) wp_json_encode(
		array(
			'status'    => (string) ( $row['status'] ?? '' ),
			'css'       => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'className' => $form_class,
			'fields'    => $fields,
			'submit'    => $submit,
		)
	);
}
