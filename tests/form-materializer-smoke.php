<?php
/**
 * Smoke coverage for the configurable form provider layer and Jetpack form adapter.
 *
 * Run from the repository root:
 * php tests/form-materializer-smoke.php
 *
 * @package StaticSiteImporter
 */

namespace Automattic\Jetpack\Forms\ContactForm {
	class Contact_Form {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

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
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return class_exists( 'WP_Error' ) && $value instanceof WP_Error;
		}
	}

	$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
	$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
	$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
	if ( is_readable( $parser ) && is_readable( $blocks ) ) {
		require_once $parser;
		require_once $blocks;
	}
	if ( ! function_exists( 'serialize_blocks' ) ) {
		fwrite( STDERR, "SKIP: WordPress block serialization is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
		exit( 0 );
	}

	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;
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
	$GLOBALS['ssi_test_required_jetpack_form_blocks'] = $GLOBALS['ssi_jetpack_registered_form_blocks'];
	if ( ! class_exists( 'Grunion_Contact_Form' ) ) {
		class Grunion_Contact_Form {}
	}
	if ( ! class_exists( 'Jetpack' ) ) {
		class Jetpack {
			public static bool $connection_ready = false;

			public static function is_connection_ready(): bool {
				return self::$connection_ready;
			}

			public static function activate_module( string $module, bool $exit = true, bool $redirect = true ): bool {
				unset( $exit, $redirect );
				$GLOBALS['ssi_test_jetpack_active_modules'][] = $module;
				return true;
			}
		}
	}
	if ( ! class_exists( 'SSI_Test_Jetpack_Modules' ) ) {
		class SSI_Test_Jetpack_Modules {
			public function is_active( string $module ): bool {
				return in_array( $module, $GLOBALS['ssi_test_jetpack_active_modules'] ?? array(), true );
			}
		}
		class_alias( 'SSI_Test_Jetpack_Modules', 'Automattic\\Jetpack\\Modules' );
	}
	if ( ! class_exists( 'SSI_Test_Jetpack_Status' ) ) {
		class SSI_Test_Jetpack_Status {
			public function is_offline_mode(): bool {
				return (bool) get_option( 'jetpack_offline_mode', false );
			}
		}
		class_alias( 'SSI_Test_Jetpack_Status', 'Automattic\\Jetpack\\Status' );
	}
	if ( ! class_exists( 'SSI_Test_Jetpack_Status_Cache' ) ) {
		class SSI_Test_Jetpack_Status_Cache {
			public static function clear(): void {}
		}
		class_alias( 'SSI_Test_Jetpack_Status_Cache', 'Automattic\\Jetpack\\Status\\Cache' );
	}
	if ( ! class_exists( 'SSI_Test_Contact_Form_Block' ) ) {
		class SSI_Test_Contact_Form_Block {
			public static function register_block(): void {
				$GLOBALS['ssi_jetpack_registered_form_blocks'][] = 'jetpack/contact-form';
			}

			public static function register_child_blocks(): void {
				$GLOBALS['ssi_jetpack_registered_form_blocks'] = $GLOBALS['ssi_test_required_jetpack_form_blocks'];
			}
		}
		class_alias( 'SSI_Test_Contact_Form_Block', 'Automattic\\Jetpack\\Extensions\\Contact_Form\\Contact_Form_Block' );
	}

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

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-computed-layout-strategy.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-fallback-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

	$transformer_root      = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer_bootstrap = rtrim( $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( is_readable( $transformer_bootstrap ) ) {
		require_once $transformer_bootstrap;
	}

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};
	$artifact_compiler = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
	$layout_graph = static function ( array $nodes ): array {
		return array( 'schema' => 'generic/computed-layout-graph/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'nodes' => 128, 'depth' => 8, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(), 'nodes' => $nodes );
	};
	$v2_layout_graph = static function ( array $nodes ): array {
		return array( 'schema' => 'generic/computed-layout-graph/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'nodes' => 128, 'depth' => 16, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(), 'nodes' => $nodes );
	};
	$layout_node = static function ( string $id, array $layout, string $tag = 'div' ): array {
		return array( 'id' => $id, 'kind' => 'control' === substr( $id, 0, 7 ) ? 'control' : 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => $tag, 'classes' => array() ), 'layout' => $layout, 'provenance' => array() );
	};
	$proven_layout_node = static function ( string $id, ?string $parent, string $class, array $layout, array $properties ): array {
		return array( 'id' => $id, 'kind' => 'container', 'parent' => $parent, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( $class ) ), 'layout' => $layout, 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '.' . $class, 'condition' => null, 'properties' => $properties ) ) );
	};
	$proven_layout_variant = static function ( string $node, string $class, array $condition, array $patch, array $properties ): array {
		$precedence = array();
		foreach ( $properties as $property ) {
			$precedence[ $property ] = array( 'source_order' => 2, 'specificity' => 10, 'important' => false );
		}
		return array( 'node' => $node, 'condition' => $condition, 'layout_patch' => $patch, 'precedence' => $precedence, 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '.' . $class, 'condition' => $condition, 'properties' => $properties ) ) );
	};

	// --- Default provider selection -----------------------------------------
	$assert( 'jetpack' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-default-provider-jetpack' );
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-default-provider-woocommerce' );

	$form_adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'jetpack_contact_form' === ( $form_adapter['id'] ?? '' ), 'form-adapter-resolves-jetpack' );
	$assert( 'form' === ( $form_adapter['capability'] ?? '' ), 'form-adapter-capability' );
	$assert( 'allow_missing_jetpack' === ( $form_adapter['waiver_arg'] ?? '' ), 'form-adapter-waiver' );
	$assert( is_callable( $form_adapter['dependencies'][0]['preparation_callback'] ?? null ), 'form-adapter-prepares-provider-runtime' );
	$all_jetpack_blocks = $GLOBALS['ssi_jetpack_registered_form_blocks'];
	$GLOBALS['ssi_jetpack_registered_form_blocks'] = array( 'jetpack/contact-form', 'jetpack/field-text' );
	$assert( ! Static_Site_Importer_Form_Seeder::jetpack_forms_available(), 'partial-provider-block-registration-is-unavailable' );
	$GLOBALS['ssi_jetpack_registered_form_blocks'] = $all_jetpack_blocks;
	$jetpack_dependency = $form_adapter['dependencies'][0] ?? array();
	$assert( in_array( 'jetpack/option', $jetpack_dependency['missing_apis'] ?? array(), true ), 'form-adapter-declares-field-children' );
	$assert( Static_Site_Importer_Form_Seeder::required_block_types() === ( $jetpack_dependency['provider_readiness']['required_block_types'] ?? array() ), 'form-adapter-declares-every-emitted-block' );

	// --- Woo path unaffected -------------------------------------------------
	$product_adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$assert( 'woocommerce_simple_product' === ( $product_adapter['id'] ?? '' ), 'product-adapter-unchanged' );
	$assert( 'shop' === ( $product_adapter['capability'] ?? '' ), 'product-adapter-capability-shop' );
	$assert( 'allow_missing_woocommerce' === ( $product_adapter['waiver_arg'] ?? '' ), 'product-adapter-waiver-unchanged' );

	// --- Forms manifest validation rejects submit-only forms ----------------
	$submit_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array( array( 'selector' => 'form#x', 'controls' => array( array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) )
	);
	$assert( array() === $submit_only['forms'], 'submit-only-form-rejected' );
	$assert( ! empty( $submit_only['errors'] ), 'submit-only-form-error-recorded' );
	$responsive_identity_forms = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array(
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'a', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ) ),
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'b', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ) ),
		) )
	);
	$assert( empty( $responsive_identity_forms['errors'] ) && 2 === count( $responsive_identity_forms['forms'] ), 'responsive-form-identities-remain-distinct-during-validation' );

	// Truncated graphs remain producer fallback evidence, never runtime input.
	$truncated_css   = str_repeat( '@media (min-width:1px){', 9 ) . '.form{display:grid}' . str_repeat( '}', 9 );
	$truncated_forms = str_repeat( '<form class="form"><input name="email"><button type="submit">Send</button></form>', 8 );
	$truncated_result = ( new $artifact_compiler() )->compile(
		array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<style>' . $truncated_css . '</style>' . $truncated_forms ) )
	)->toArray();
	$truncated_fallbacks = array_values( array_filter( $truncated_result['fallbacks'] ?? array(), static fn( mixed $fallback ): bool => true === ( $fallback['layout_graph']['truncated'] ?? false ) ) );
	$truncated_forms_declaration = array_values( array_filter( $truncated_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( mixed $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) )[0] ?? array();
	$truncated_runtime_forms = $truncated_forms_declaration['payload']['entities'] ?? array();
	$truncated_validation    = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $truncated_runtime_forms ) );
	$assert( 8 === count( $truncated_fallbacks ) && 8 === count( $truncated_runtime_forms ) && array() === array_filter( $truncated_runtime_forms, static fn( mixed $form ): bool => array_key_exists( 'layout_graph', $form ) ) && empty( $truncated_validation['errors'] ), 'truncated-layout-graphs-are-omitted-before-strict-runtime-validation' );

	// --- Jetpack form seeder maps controls to contact-form blocks -----------
	$forms_manifest = array(
		'forms' => array(
			array(
				'selector' => 'form.contact',
				'form'     => array( 'action' => 'mailto:hello@example.com', 'method' => 'post', 'class' => 'form contact' ),
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'text', 'id' => 'contact-name', 'class' => 'source-field', 'label_class' => 'source-label', 'name' => 'name', 'label' => 'Your name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'input', 'type' => 'number', 'name' => 'attendees', 'label' => 'Attendees' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'Sales' ), array( 'label' => 'Support' ) ) ),
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'format', 'label' => 'In person', 'options' => array( 'In person', 'Online' ) ),
					array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates' ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'class' => 'source-submit', 'label' => 'Send message', 'presentation' => array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '11px', 'bottom' => '11px' ) ) ) ) ),
				),
			),
		),
	);
	$seed = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$assert( 'completed' === ( $seed['status'] ?? '' ), 'seed-status-completed' );
	$assert( 1 === ( $seed['counts']['mapped'] ?? 0 ), 'seed-one-form-mapped' );
	$row    = $seed['forms'][0] ?? array();
	$markup = (string) ( $row['block_markup'] ?? '' );
	$assert( true === ( $row['runtime_mapped'] ?? false ), 'seed-form-runtime-mapped' );
	$assert( 8 === ( $row['field_count'] ?? 0 ), 'seed-eight-fields-mapped' );
	$assert( str_contains( $markup, 'wp:jetpack/contact-form' ), 'markup-contact-form' );
	$assert( str_contains( $markup, 'wp:jetpack/field-text' ), 'markup-field-text' );
	$assert( str_contains( $markup, 'wp:jetpack/field-email' ), 'markup-field-email' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone' ), 'markup-preserves-telephone-field-semantics' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone {"showCountrySelector":false' ) && str_contains( $markup, 'wp:jetpack/phone-input' ) && ! str_contains( $markup, '"type":"tel"' ), 'markup-telephone-uses-canonical-phone-input' );
	$assert( str_contains( $markup, 'wp:jetpack/field-number' ), 'markup-field-number' );
	$assert( str_contains( $markup, 'wp:jetpack/field-select' ), 'markup-field-select' );
	$assert( str_contains( $markup, 'wp:jetpack/field-radio' ), 'markup-field-radio' );
	$assert( str_contains( $markup, 'wp:jetpack/field-checkbox' ), 'markup-field-checkbox' );
	$assert( str_contains( $markup, 'wp:jetpack/field-textarea' ), 'markup-field-textarea' );
	$assert( str_contains( $markup, 'wp:button' ) && ! str_contains( $markup, 'wp:jetpack/button' ), 'markup-canonical-core-submit-button' );
	$assert( 1 === substr_count( $markup, '<!-- wp:button ' ) && str_contains( $markup, '<button type="submit" class="wp-block-button__link wp-element-button">Send message</button>' ), 'source-submit-control-emits-one-canonical-button' );
	$labelled_submit_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/subscribe', 'method' => 'post' ),
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
						array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send', 'class' => 'cta', 'label_classes' => 'cta-label typography-small' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $labelled_submit_markup, '<span class="cta-label typography-small">Send</span>' ) && ! str_contains( $labelled_submit_markup, '&lt;span' ), 'source-submit-label-element-is-saved-as-markup-rather-than-escaped-text', $labelled_submit_markup );
	$unsafe_label_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/subscribe', 'method' => 'post' ),
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
						array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send', 'class' => 'cta', 'label_classes' => 'ok "><script>alert(1)</script>' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $unsafe_label_markup, '<span class="ok">Send</span>' ) && ! str_contains( $unsafe_label_markup, '<script' ), 'submit-label-classes-that-are-not-plain-tokens-are-refused', $unsafe_label_markup );
	$assert( str_contains( $markup, 'form-button-submit is-submit ssi-source-submit--source-submit ssi-provider-submit-presentation' ), 'source-submit-control-presentation-projects-onto-core-button' );
	// The source stylesheet governs this button, so the block claims no style
	// attribute it would then have to reproduce in saved markup. That agreement
	// with core's save() output is what keeps imported forms clean in the editor.
	$submit_attrs = array();
	foreach ( parse_blocks( $markup ) as $parsed_form ) {
		$collect = static function ( array $blocks, callable $collect ) use ( &$submit_attrs ): void {
			foreach ( $blocks as $parsed ) {
				if ( 'core/button' === ( $parsed['blockName'] ?? '' ) ) {
					$submit_attrs[] = $parsed['attrs'] ?? array();
				}
				$collect( $parsed['innerBlocks'] ?? array(), $collect );
			}
		};
		$collect( array( $parsed_form ), $collect );
	}
	$assert( 1 === count( $submit_attrs ) && ! array_key_exists( 'style', $submit_attrs[0] ), 'source-submit-block-claims-no-unrenderable-style-attribute', wp_json_encode( $submit_attrs ) );
	$assert( str_contains( $markup, 'hello@example.com' ), 'markup-mailto-recipient' );
	$assert( str_contains( $markup, '"options":["Sales","Support"]' ), 'markup-select-options' );
	$assert( 1 === preg_match( '/<div class="wp-block-jetpack-contact-form form contact ssi-form-[a-f0-9]{12}">/', $markup ), 'markup-contact-form-wrapper-and-source-classes' );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-text \{"required":true,"id":"ssi-form-[a-f0-9]{12}-field-0","className":"ssi-node-[a-f0-9]{12}"\} -->/', $markup ), 'markup-field-wrapper-keeps-provider-layout-class-and-instance-identity' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/label {"label":"Your name","className":"source-label"} /-->' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"className":"source-field"} /-->' ), 'markup-field-canonical-label-and-input-children-carry-source-classes' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-select {"options":["Sales","Support"]' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"type":"dropdown"} /-->' ), 'markup-select-options-and-dropdown-input' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-radio {"options":["In person","Online"]' ) && str_contains( $markup, '<!-- wp:jetpack/options {"type":"radio"} -->' ), 'markup-radio-options-on-field-and-child-list' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-checkbox ' ) && str_contains( $markup, '<!-- wp:jetpack/option {"label":"Send me updates","isStandalone":true} /-->' ), 'markup-checkbox-uses-standalone-option-child' );
	$responsive_seed = Static_Site_Importer_Form_Seeder::seed(
		array( 'forms' => array(
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'a', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'repeated-source-id', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ),
			array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'fallback_identity' => str_repeat( 'b', 64 ), 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'repeated-source-id', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ),
		) )
	);
	$responsive_rows = $responsive_seed['forms'] ?? array();
	$responsive_ids = array();
	foreach ( $responsive_rows as $responsive_row ) {
		preg_match( '/"id":"([^"]+)"/', $responsive_row['block_markup'], $field_id );
		$responsive_ids[] = $field_id[1] ?? '';
	}
	$assert( 2 === count( array_unique( $responsive_ids ) ) && ! in_array( '', $responsive_ids, true ), 'responsive-form-instances-have-distinct-provider-field-state-identities' );
	$marker_form = $responsive_identity_forms['forms'][0];
	$marker_form['controls'][0]['required'] = true;
	$marker_form['controls'][0]['label'] = 'Email';
	$marker_form['controls'][0]['required_text'] = '*';
	$marker_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $marker_form ) ) )['forms'][0];
	$assert( str_contains( $marker_row['block_markup'], '"requiredText":"*"' ), 'captured-required-marker-uses-existing-provider-label-api' );
	$assert( 2 === ( $responsive_seed['counts']['mapped'] ?? 0 ) && array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) === array_column( $responsive_rows, 'fallback_identity' ) && 2 === count( array_unique( array_map( static fn( array $row ): string => (string) preg_replace( '/.*\b(ssi-form-[a-f0-9]{12})\b.*/s', '$1', (string) ( $row['block_markup'] ?? '' ) ), $responsive_rows ) ) ), 'responsive-form-identities-produce-distinct-provider-blocks-and-receipts' );
	$responsive_entities = $responsive_identity_forms['forms'];
	foreach ( $responsive_entities as $index => &$responsive_entity ) {
		$responsive_entity['bindings'] = array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'contact.html', 'search_block_markup' => '<!-- wp:html --><form class="contact"></form><!-- /wp:html -->', 'occurrence' => $index + 1, 'role' => 'form' ) );
	}
	unset( $responsive_entity );
	$responsive_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
		array( 'entities' => array( 'responsive' => array( 'adapter' => Static_Site_Importer_Entity_Materializer_Registry::form_adapter(), 'manifest' => array( 'forms' => $responsive_entities ) ) ) ),
		array( 'responsive' => $responsive_seed )
	);
	$assert( is_array( $responsive_bindings ) && 2 === count( $responsive_bindings ) && array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) === array_column( $responsive_bindings, 'fallback_reconciliation_identity' ), 'responsive-form-identities-match-provider-results-to-every-binding' );
	$checkbox_group = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.preferences', 'controls' => array( array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'topics', 'label' => 'Topics', 'options' => array( 'Art', 'Events' ) ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ) ) ) ) ) );
	$checkbox_group_markup = (string) ( $checkbox_group['forms'][0]['block_markup'] ?? '' );
	$assert( str_contains( $checkbox_group_markup, '<!-- wp:jetpack/field-checkbox-multiple {"options":["Art","Events"]' ) && str_contains( $checkbox_group_markup, '<!-- wp:jetpack/options {"type":"checkbox"} -->' ), 'checkbox-group-uses-provider-multiple-field' );
	$sensitive_label = 'C:\\forms\\"quoted" --> < & support';
	$escaped_label = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.escaped', 'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'unsafe', 'label' => $sensitive_label ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) ) );
	$escaped_label_markup = (string) ( $escaped_label['forms'][0]['block_markup'] ?? '' );
	$expected_sensitive_attrs = serialize_block_attributes( array( 'label' => $sensitive_label ) );
	$assert( str_contains( $escaped_label_markup, '<!-- wp:jetpack/label ' . $expected_sensitive_attrs . ' /-->' ), 'field-attributes-byte-match-core-escaping', $escaped_label_markup );
	$assert( str_contains( $expected_sensitive_attrs, '\\u005c' ) && str_contains( $expected_sensitive_attrs, '\\u0022' ) && str_contains( $expected_sensitive_attrs, '\\u002d\\u002d' ) && str_contains( $expected_sensitive_attrs, '\\u003c' ) && str_contains( $expected_sensitive_attrs, '\\u003e' ) && str_contains( $expected_sensitive_attrs, '\\u0026' ), 'field-attributes-core-escapes-every-comment-sensitive-character', $expected_sensitive_attrs );
	$assert( $escaped_label_markup === serialize_blocks( parse_blocks( $escaped_label_markup ) ), 'field-attributes-round-trip-through-wordpress-block-parser', $escaped_label_markup );

	// --- Composed route forms materialize directly without caller seeding ----
	$route_form = '<main><form class="contact"><label>Email <input type="email" name="email" required></label><button type="submit">Contact me</button></form></main>';
	$composed_result = ( new $artifact_compiler() )->compile(
		array( 'entrypoint' => 'about.html', 'files' => array( 'about.html' => $route_form, 'contact.html' => $route_form ) )
	)->toArray();
	$composed_plan = $composed_result['source_reports']['wordpress_site_plan'] ?? array();
	$composed_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $composed_plan, array() );
	$composed_entities = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $composed_lifecycle, array( 'seed_entities' => false ) );
	$composed_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $composed_lifecycle, $composed_entities['reports'] ?? array() );
	$composed_form_receipt = reset( $composed_entities['reports'] );
	$assert( 2 === ( $composed_plan['quality']['metrics']['fallback_count'] ?? -1 ) && 2 === ( $composed_form_receipt['counts']['mapped'] ?? 0 ), 'composed-route-forms-produce-one-provider-receipt' );
	$assert( is_array( $composed_bindings ) && 2 === count( $composed_bindings ) && array() === array_filter( $composed_bindings, static fn( array $binding ): bool => 'jetpack' !== ( $binding['provider'] ?? '' ) || '' === ( $binding['fallback_reconciliation_identity'] ?? '' ) ), 'composed-route-forms-produce-identity-bound-provider-bindings', (string) wp_json_encode( array( 'receipt' => $composed_form_receipt, 'bindings' => $composed_bindings ) ) );

	// --- Generic topology preserves nested rows and source presentation hooks --
	$topology_form = array(
		'forms' => array(
			array(
				'selector' => 'form.contact',
				'form' => array( 'class' => 'form contact' ),
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First name' ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
				),
				'control_topology' => array(
					'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
					'nodes' => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'row-2', 'source_id' => 'contact-row' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'class' => 'field' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'class' => 'field' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'class' => 'field standalone' ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 1, 'control' => 2 ),
						array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 3 ),
					),
				),
				'layout_graph' => array(
					'schema' => 'generic/computed-layout-graph/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'nodes' => 128, 'depth' => 8, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(),
					'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'div', 'id' => 'contact-row', 'classes' => array( 'row-2' ) ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)', 'gap' => '1rem' ), 'provenance' => array() ) ),
				),
			),
		),
	);
	$validated_topology = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $topology_form );
	$assert( empty( $validated_topology['errors'] ), 'topology-manifest-validates' );
	$topology_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$topology_markup = (string) ( $topology_seed['forms'][0]['block_markup'] ?? '' );
	$topology_receipt = $topology_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$assert( ! str_contains( $topology_markup, 'wp:group' ), 'topology-avoids-unsupported-provider-wrapper-blocks' );
	$assert( 2 === substr_count( $topology_markup, '"width":50' ), 'topology-maps-proven-equal-grid-to-field-widths' );
	$assert( str_contains( $topology_markup, 'First name' ) && str_contains( $topology_markup, 'Email' ) && str_contains( $topology_markup, 'Message' ), 'topology-preserves-labels' );
	$assert( 1 === substr_count( $topology_markup, '<!-- wp:button ' ), 'topology-submit-control-emits-one-core-button-in-source-position' );
	$assert( 'applied' === ( $topology_receipt['status'] ?? '' ) && 5 === ( $topology_receipt['operation_count'] ?? 0 ) && 'provider_equal_width_fields' === ( $topology_receipt['operations'][3]['strategy'] ?? '' ) && 'provider_interaction_carrier' === ( $topology_receipt['operations'][4]['strategy'] ?? '' ), 'computed-layout-equal-grid-applies-with-bounded-receipt' );
	$native_row_form = array(
		'forms' => array( array(
			'selector' => 'form.subscribe',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Subscribe' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'email-submit-row' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'email-submit-row' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.email-submit-row', 'condition' => null, 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) ) ) ),
		) ),
	);
	$native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_row_form );
	$native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$native_row_markup     = (string) ( $native_row_result['block_markup'] ?? '' );
	$native_row_losses     = array_column( $native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$native_row_targets    = array_filter( $native_row_result['provider_layout_target_map']['targets'] ?? array(), static fn( array $target ): bool => 'wrapper-0' === ( $target['node'] ?? '' ) );
	$native_row_blocks     = parse_blocks( $native_row_markup );
	$native_row_block      = $native_row_blocks[0]['innerBlocks'][0] ?? array();
	$assert( empty( $native_row_validation['errors'] ) && 'core/group' === ( $native_row_block['blockName'] ?? '' ) && array( 'jetpack/field-email', 'core/button' ) === array_column( $native_row_block['innerBlocks'] ?? array(), 'blockName' ) && str_contains( (string) ( $native_row_block['attrs']['className'] ?? '' ), 'email-submit-row' ) && preg_match( '/ssi-node-[a-f0-9]{12}/', (string) ( $native_row_block['attrs']['className'] ?? '' ) ) && 1 === count( $native_row_targets ) && in_array( 'direct_child_layout', reset( $native_row_targets )['capabilities'] ?? array(), true ) && 1 === substr_count( $native_row_markup, '<div class="wp-block-group' ) && ! array_intersect( array( 'provider_wrapper_layout_unrepresentable', 'direct_child_relationship_unrepresentable' ), $native_row_losses ) && $native_row_markup === serialize_blocks( parse_blocks( $native_row_markup ) ), 'proven-horizontal-direct-control-row-preserves-native-group-and-direct-child-layout-target', wp_json_encode( $native_row_result ) );
	$nested_native_row_form = $native_row_form;
	$nested_native_row_form['forms'][0]['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'newsletter-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div', 'class' => 'email-submit-row' ),
		array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'email-box' ),
		array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'tag' => 'div', 'class' => 'email-control-shell' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 4, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'control' => 1 ),
	);
	$nested_native_row_form['forms'][0]['layout_graph']['nodes'] = array(
		$layout_node( 'form', array(), 'form' ),
		$proven_layout_node( 'wrapper-0', 'form', 'newsletter-shell', array( 'display' => 'flex', 'direction' => 'column', 'gap' => '20px', 'width' => '100%', 'flex_shrink' => '0', 'align_items' => 'center' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items' ) ),
		$proven_layout_node( 'wrapper-1', 'wrapper-0', 'email-submit-row', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '16px', 'width' => '100%', 'flex_shrink' => '0', 'align_items' => 'center', 'justify_content' => 'center' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items', 'justify-content' ) ),
		$proven_layout_node( 'wrapper-2', 'wrapper-1', 'email-box', array( 'display' => 'flex', 'direction' => 'column', 'gap' => '8px', 'width' => '244px', 'flex_shrink' => '0' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink' ) ),
		$proven_layout_node( 'wrapper-3', 'wrapper-2', 'email-control-shell', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '8px', 'width' => '244px', 'flex_shrink' => '0', 'align_items' => 'center', 'align_self' => 'stretch' ), array( 'display', 'flex-direction', 'gap', 'width', 'flex-shrink', 'align-items', 'align-self' ) ),
	);
	$nested_condition = array( 'kind' => 'media', 'query' => '(max-width:915px)' );
	$nested_native_row_form['forms'][0]['layout_graph']['variants'] = array(
		$proven_layout_variant( 'wrapper-0', 'newsletter-shell', $nested_condition, array( 'gap' => '10px', 'width' => '100%' ), array( 'gap', 'width' ) ),
		$proven_layout_variant( 'wrapper-1', 'email-submit-row', $nested_condition, array( 'direction' => 'column', 'gap' => '13px', 'align_self' => 'stretch' ), array( 'flex-direction', 'gap', 'align-self' ) ),
		$proven_layout_variant( 'wrapper-1', 'email-submit-row', array( 'kind' => 'media', 'query' => '(max-width:390px)' ), array( 'direction' => 'column', 'wrap' => 'nowrap', 'align_items' => 'stretch' ), array( 'flex-direction', 'flex-wrap', 'align-items' ) ),
		$proven_layout_variant( 'wrapper-2', 'email-box', $nested_condition, array( 'width' => '100%' ), array( 'width' ) ),
		$proven_layout_variant( 'wrapper-3', 'email-control-shell', $nested_condition, array( 'width' => '100%' ), array( 'width' ) ),
	);
	$nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_native_row_form );
	$nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_native_row_markup     = (string) ( $nested_native_row_result['block_markup'] ?? '' );
	$nested_native_row_block      = parse_blocks( $nested_native_row_markup )[0]['innerBlocks'][0] ?? array();
	$nested_native_row_child      = $nested_native_row_block['innerBlocks'][0] ?? array();
	$nested_native_row_grandchild = $nested_native_row_child['innerBlocks'][0] ?? array();
	$nested_native_row_field_box  = $nested_native_row_grandchild['innerBlocks'][0] ?? array();
	$nested_native_row_losses     = array_column( $nested_native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$nested_native_row_targets    = array_column( $nested_native_row_result['provider_layout_target_map']['targets'] ?? array(), null, 'node' );
	$nested_native_row_css        = (string) ( $nested_native_row_result['provider_layout_overlay_css']['css'] ?? '' );
	$nested_native_row_strategies = array_column( $nested_native_row_result['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $nested_native_row_validation['errors'] ) && true === ( $nested_native_row_result['runtime_mapped'] ?? false ) && 'core/group' === ( $nested_native_row_block['blockName'] ?? '' ) && 'vertical' === ( $nested_native_row_block['attrs']['layout']['orientation'] ?? '' ) && 'core/group' === ( $nested_native_row_child['blockName'] ?? '' ) && 'core/group' === ( $nested_native_row_grandchild['blockName'] ?? '' ) && 'core/group' === ( $nested_native_row_field_box['blockName'] ?? '' ) && array( 'jetpack/field-email' ) === array_column( $nested_native_row_field_box['innerBlocks'] ?? array(), 'blockName' ) && array( 'core/group', 'core/button' ) === array_column( $nested_native_row_child['innerBlocks'] ?? array(), 'blockName' ) && isset( $nested_native_row_targets['wrapper-0'], $nested_native_row_targets['wrapper-1'], $nested_native_row_targets['wrapper-2'], $nested_native_row_targets['wrapper-3'] ) && in_array( 'direct_child_layout', $nested_native_row_targets['wrapper-1']['capabilities'] ?? array(), true ) && ! str_contains( $nested_native_row_markup, 'ssi-source-wrapper-' ) && 4 === substr_count( $nested_native_row_markup, '<!-- wp:group ' ) && 4 === substr_count( $nested_native_row_markup, '<div class="wp-block-group' ) && str_contains( $nested_native_row_css, 'width:100%;flex-shrink:0' ) && str_contains( $nested_native_row_css, '@media (max-width:915px)' ) && str_contains( $nested_native_row_css, '@media (max-width:390px)' ) && ! array_intersect( array( 'provider_wrapper_layout_unrepresentable', 'direct_child_relationship_unrepresentable', 'responsive_layout_ownership' ), $nested_native_row_losses ) && $nested_native_row_markup === serialize_blocks( parse_blocks( $nested_native_row_markup ) ), 'proven-responsive-nested-div-containers-preserve-exact-native-groups-and-overlay', wp_json_encode( $nested_native_row_result ) );
	$unsafe_nested_native_row_form = $nested_native_row_form;
	$unsafe_nested_native_row_form['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 1, 'depth' => 3, 'tag' => 'div', 'class' => 'ambiguous-extra-child' );
	$unsafe_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_nested_native_row_form );
	$unsafe_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$unsafe_nested_native_row_markup     = (string) ( $unsafe_nested_native_row_result['block_markup'] ?? '' );
	$assert( empty( $unsafe_nested_native_row_validation['errors'] ) && ! str_contains( $unsafe_nested_native_row_markup, 'newsletter-shell ssi-node-' ) && ! str_contains( $unsafe_nested_native_row_markup, 'email-submit-row ssi-node-' ), 'nested-div-control-row-with-ambiguous-extra-child-remains-declined', wp_json_encode( $unsafe_nested_native_row_result ) );
	$semantic_nested_native_row_form = $nested_native_row_form;
	$semantic_nested_native_row_form['forms'][0]['control_topology']['nodes'][0]['tag'] = 'section';
	$semantic_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $semantic_nested_native_row_form );
	$semantic_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $semantic_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$semantic_nested_native_row_losses     = array_column( $semantic_nested_native_row_result['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( empty( $semantic_nested_native_row_validation['errors'] ) && ! str_contains( (string) ( $semantic_nested_native_row_result['block_markup'] ?? '' ), 'newsletter-shell ssi-node-' ) && in_array( 'unsupported_semantic_wrapper', $semantic_nested_native_row_losses, true ), 'nested-semantic-container-remains-declined', wp_json_encode( $semantic_nested_native_row_result ) );
	$unsafe_property_nested_native_row_form = $nested_native_row_form;
	$unsafe_property_nested_native_row_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = 'url(https://example.test/unsafe)';
	$unsafe_property_nested_native_row_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_property_nested_native_row_form );
	$unsafe_property_nested_native_row_result     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_property_nested_native_row_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $unsafe_property_nested_native_row_validation['errors'] ) && ! str_contains( (string) ( $unsafe_property_nested_native_row_result['block_markup'] ?? '' ), 'newsletter-shell ssi-node-' ), 'nested-container-with-unsafe-property-remains-declined', wp_json_encode( array( 'validation' => $unsafe_property_nested_native_row_validation, 'row' => $unsafe_property_nested_native_row_result ) ) );
	// A stylesheet may be attached as media="all". It is unconditional, so a
	// two-field grid can be mapped while source paragraph field wrappers remain
	// represented by the provider runtime instead of being silently flattened.
	$aetna_topology_form = $topology_form;
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][1]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][3]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['control_topology']['nodes'][5]['tag'] = 'p';
	$aetna_topology_form['forms'][0]['layout_graph']['nodes'][0]['layout'] = array();
	$aetna_all_condition = array( 'kind' => 'media', 'query' => 'all' );
	$aetna_topology_form['forms'][0]['layout_graph']['variants'][] = array(
		'node' => 'wrapper-0', 'condition' => $aetna_all_condition, 'layout_patch' => array( 'display' => 'grid', 'columns' => 'repeat(2, 1fr)', 'gap' => '1rem' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'gap' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.row-2', 'condition' => $aetna_all_condition, 'properties' => array( 'display', 'grid-template-columns', 'gap' ) ) ),
	);
	$aetna_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $aetna_topology_form );
	$aetna_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $aetna_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$aetna_markup     = (string) ( $aetna_row['block_markup'] ?? '' );
	$aetna_losses     = array_column( $aetna_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( empty( $aetna_validation['errors'] ) && 'mapped' === ( $aetna_row['status'] ?? '' ) && true === ( $aetna_row['runtime_mapped'] ?? false ) && 2 === substr_count( $aetna_markup, '"width":50' ) && ! in_array( 'unsupported_semantic_wrapper', $aetna_losses, true ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $aetna_losses, true ) && $aetna_markup === serialize_blocks( parse_blocks( $aetna_markup ) ), 'unconditional-media-grid-and-paragraph-field-wrappers-materialize-as-editable-valid-blocks', wp_json_encode( $aetna_row ) );
	$aetna_field_runtime = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-semantic-wrapper-1--p--field"><label>Name</label><input></div>' );
	$aetna_submit_runtime = Static_Site_Importer_Form_Seeder::project_provider_submit_presentation( '<div class="wp-block-button ssi-source-semantic-wrapper-1--p"><button>Send</button></div>', array( 'attrs' => array( 'className' => 'ssi-source-semantic-wrapper-1--p' ) ) );
	$assert( '<p class="field"><div class="grunion-field-text-wrap"><label>Name</label><input></div></p>' === $aetna_field_runtime && '<p><div class="wp-block-button"><button>Send</button></div></p>' === $aetna_submit_runtime, 'provider-runtime-restores-safe-paragraph-wrapper-semantics-around-editable-fields-and-submits', $aetna_field_runtime . "\n" . $aetna_submit_runtime );
	$aetna_subscription_form = array(
		'forms' => array( array(
			'selector' => 'form.subscribe',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'token' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Subscribe' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'subscription-row' ), array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'p' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ), array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'p' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 1, 'depth' => 2, 'control' => 2 ) ) ),
			'layout_graph' => $v2_layout_graph( array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'subscribe' ) ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'subscription-row' ) ), 'layout' => array(), 'provenance' => array() ) ) ),
		) ),
	);
	$aetna_subscription_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $aetna_all_condition, 'layout_patch' => array( 'display' => 'flex', 'direction' => 'row', 'align_items' => 'flex-start' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'flex-direction' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'align-items' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/subscription.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.subscription-row', 'condition' => $aetna_all_condition, 'properties' => array( 'display', 'flex-direction', 'align-items' ) ) ) );
	$aetna_subscription_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $aetna_subscription_form );
	$aetna_subscription_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $aetna_subscription_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $aetna_subscription_validation['errors'] ) && 'mapped' === ( $aetna_subscription_row['status'] ?? '' ) && true === ( $aetna_subscription_row['runtime_mapped'] ?? false ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), array_column( $aetna_subscription_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ) ), 'unconditional-subscription-row-with-hidden-bookkeeping-materializes-without-source-form-runtime', wp_json_encode( $aetna_subscription_row ) );
	$popup_form = array(
		'selector' => 'form.picker',
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'appointment', 'label' => 'Appointment', 'label_id' => 'appointment-label', 'readonly' => true ),
			array( 'tag' => 'button', 'type' => 'button', 'label' => 'Open picker', 'aria_haspopup' => 'dialog', 'aria_describedby' => 'appointment-label' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
		'control_topology' => array(
			'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
			'nodes' => array(
				array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'picker-field' ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
				array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
			),
		),
	);
	$popup_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $popup_form ) ) );
	$popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $popup_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$popup_strategies = array_column( $popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( true === ( $popup_row['runtime_mapped'] ?? false ) && 1 === ( $popup_row['field_count'] ?? 0 ) && in_array( 'provider_auxiliary_popup_control', $popup_strategies, true ) && ! str_contains( (string) ( $popup_row['block_markup'] ?? '' ), 'Open picker' ), 'related-popup-button-is-superseded-by-editable-provider-field', wp_json_encode( $popup_row ) );
	$unrelated_popup_form = $popup_form;
	$unrelated_popup_form['control_topology']['nodes'][2]['parent'] = null;
	$unrelated_popup_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unrelated_popup_form ) ) );
	$unrelated_popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unrelated_popup_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_auxiliary_popup_control', array_column( $unrelated_popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'popup-button-without-shared-field-topology-is-not-superseded', wp_json_encode( $unrelated_popup_row ) );
	$phone_popup_form = $popup_form;
	$phone_popup_form['controls'][0] = array( 'tag' => 'button', 'type' => 'button', 'label' => 'Phone. Select a country code', 'aria_haspopup' => 'listbox' );
	$phone_popup_form['controls'][1] = array( 'tag' => 'input', 'type' => 'phone', 'name' => 'phone', 'label' => 'Phone' );
	$phone_popup_form['controls'][2] = array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' );
	$phone_popup_form['control_topology']['nodes'] = array(
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'phone-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span', 'class' => 'country-picker' ),
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
	);
	$phone_popup_form['layout_graph'] = $v2_layout_graph( array(
		$layout_node( 'form', array(), 'form' ),
		array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'phone-shell' ) ), 'layout' => array( 'display' => 'flex', 'align_items' => 'center' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.phone-shell', 'condition' => null, 'properties' => array( 'display', 'align-items' ) ) ) ),
		array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'country-picker' ) ), 'layout' => array( 'display' => 'block' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.country-picker', 'condition' => null, 'properties' => array( 'display' ) ) ) ),
	) );
	$phone_popup_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $phone_popup_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_popup_losses = array_column( $phone_popup_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert( str_contains( (string) $phone_popup_row['block_markup'], 'ssi-source-wrapper-shell-0\u002d\u002dphone-shell' ), 'common-source-ancestor-targets-composite-shell-rather-than-only-value' );
	$assert( 'mapped' === ( $phone_popup_row['status'] ?? '' ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $phone_popup_losses, true ) && in_array( 'provider_auxiliary_popup_control', array_column( $phone_popup_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! str_contains( (string) ( $phone_popup_row['block_markup'] ?? '' ), 'ssi-source-wrapper-1\u002d\u002dcountry-picker' ), 'owned-phone-country-popup-does-not-transfer-auxiliary-wrappers-to-value-input', wp_json_encode( $phone_popup_row ) );
	$unrelated_adjacent_phone_popup = $phone_popup_form;
	$unrelated_adjacent_phone_popup['controls'][0]['label'] = 'Open service menu';
	$unrelated_adjacent_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unrelated_adjacent_phone_popup ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unrelated_adjacent_phone_row['status'] ?? '' ) && in_array( 'unsupported_control_unrepresentable', array_column( $unrelated_adjacent_phone_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ) && ! in_array( 'provider_auxiliary_popup_control', array_column( $unrelated_adjacent_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'adjacent-non-country-popup-before-phone-remains-unrepresented-and-preserved', wp_json_encode( $unrelated_adjacent_phone_row ) );
	$mobile_phone_form = $phone_popup_form;
	$mobile_phone_form['fallback_identity'] = str_repeat( 'c', 64 );
	$mobile_phone_form['controls'] = array(
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ),
		array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'company', 'label' => 'Company' ),
		array( 'tag' => 'button', 'type' => 'button', 'label' => 'Phone. Phone. Select a country code' ),
		array( 'tag' => 'input', 'type' => 'phone', 'name' => 'phone', 'label' => 'Phone' ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	);
	$mobile_phone_form['control_topology']['nodes'] = array(
		array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
		array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
		array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ),
		array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 3, 'depth' => 0, 'tag' => 'div', 'class' => 'phone-shell' ),
		array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span', 'class' => 'country-picker' ),
		array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 3 ),
		array( 'id' => 'control-4', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 4 ),
		array( 'id' => 'control-5', 'kind' => 'control', 'parent' => null, 'order' => 4, 'depth' => 0, 'control' => 5 ),
	);
	$mobile_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $mobile_phone_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $mobile_phone_row['status'] ?? '' ) && true === ( $mobile_phone_row['runtime_mapped'] ?? false ) && str_repeat( 'c', 64 ) === ( $mobile_phone_row['fallback_identity'] ?? '' ) && empty( $mobile_phone_row['form_receipt_unaccepted_losses'] ?? array() ) && in_array( 'provider_auxiliary_popup_control', array_column( $mobile_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'mobile-country-selector-without-popup-metadata-materializes-and-retains-fallback-receipt-identity', wp_json_encode( $mobile_phone_row ) );
	$incompatible_mobile_phone_form = $mobile_phone_form;
	$incompatible_mobile_phone_form['controls'][3]['aria_haspopup'] = 'tooltip';
	$incompatible_mobile_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $incompatible_mobile_phone_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $incompatible_mobile_phone_row['status'] ?? '' ) && in_array( 'unsupported_control_unrepresentable', array_column( $incompatible_mobile_phone_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'explicitly-incompatible-country-popup-before-phone-remains-unrepresented', wp_json_encode( $incompatible_mobile_phone_row ) );
	$presentation_form = $topology_form;
	$presentation_role = static function ( array $styles, array $properties, string $selector ): array {
		return array(
			'styles'     => $styles,
			'provenance' => array( array( 'source_path' => 'assets/forms.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => $selector, 'condition' => null, 'properties' => $properties ) ),
		);
	};
	$presentation_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(
			array(
				'index'   => 0,
				'control' => $presentation_role( array( 'background_color' => 'transparent', 'border' => '0', 'padding' => '8px 0', 'font_size' => '16px', 'line_height' => '24px' ), array( 'background-color', 'border', 'padding', 'font-size', 'line-height' ), 'input' ),
				'label'   => $presentation_role( array( 'font_size' => '14px', 'font_weight' => '400', 'line_height' => '1.4', 'margin_bottom' => '8px' ), array( 'font-size', 'font-weight', 'line-height', 'margin-bottom' ), 'label' ),
			),
			array( 'index' => 3, 'control' => $presentation_role( array( 'background_color' => 'rgb(254,126,3)', 'color' => '#fff', 'border' => '0', 'border_radius' => '100px', 'padding' => '11px 15px', 'font_size' => '16px' ), array( 'background-color', 'color', 'border', 'border-radius', 'padding', 'font-size' ), 'button' ) ),
		),
	);
	$validated_presentation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $presentation_form );
	$presentation_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_presentation['forms'] ) )['forms'][0] ?? array();
	$presentation_markup    = (string) ( $presentation_row['block_markup'] ?? '' );
	$presentation_css       = (string) ( $presentation_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $validated_presentation['errors'] ) && str_contains( $presentation_css, 'background-color:transparent;border:0;padding:8px 0;font-size:16px;line-height:24px' ) && str_contains( $presentation_css, 'font-size:14px;font-weight:400;line-height:1.4;margin-bottom:8px' ) && str_contains( $presentation_css, 'background-color:rgb(254,126,3);color:#fff;border:0;border-radius:100px;padding:11px 15px;font-size:16px' ), 'bounded-form-presentation-transposes-control-label-and-submit-styles', $presentation_css );
	$assert( preg_match( '/wp:jetpack\/label .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/wp:jetpack\/input .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/wp:button .*ssi-node-[a-f0-9]{12}/', $presentation_markup ) && preg_match( '/\.ssi-form-([a-f0-9]{12})\.ssi-form-\1 \.ssi-node-[a-f0-9]{12}/', $presentation_css ) && str_contains( $presentation_css, '> .wp-block-button__link{' ), 'form-presentation-targets-use-deterministic-provider-subparts-with-authoritative-scope-specificity', $presentation_markup );
	$variant_only_presentation = $presentation_form;
	$variant_condition         = array( 'kind' => 'media', 'query' => '(min-width:769px)' );
	$variant_role              = $presentation_role( array( 'height' => '40px' ), array( 'height' ), 'input' );
	$variant_role['provenance'][0]['condition'] = $variant_condition;
	$variant_only_presentation['forms'][0]['presentation_graph']['controls'] = array();
	$variant_only_presentation['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 0, 'role' => 'control', 'condition' => $variant_condition, 'style_patch' => $variant_role['styles'], 'precedence' => array( 'height' => array( 'source_order' => 1, 'specificity' => 1, 'important' => false ) ), 'provenance' => $variant_role['provenance'] ) );
	$validated_variant_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_only_presentation );
	$variant_only_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_variant_only['forms'] ) )['forms'][0] ?? array();
	$assert( empty( $validated_variant_only['errors'] ) && str_contains( (string) ( $variant_only_row['block_markup'] ?? '' ), 'ssi-node-' ) && str_contains( (string) ( $variant_only_row['provider_layout_overlay_css']['css'] ?? '' ), '@media (min-width:769px){' ) && str_contains( (string) ( $variant_only_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:40px' ), 'variant-only-form-presentation-creates-provider-targets', wp_json_encode( $variant_only_row ) );
	$all_controls_presentation = $presentation_form;
	$all_controls_presentation['forms'][0]['controls'][1] = array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'One' ) ) );
	$all_controls_presentation['forms'][0]['presentation_graph']['controls'] = array(
		array( 'index' => 0, 'control' => $presentation_role( array( 'border' => '1px solid #111', 'padding' => '7px' ), array( 'border', 'padding' ), 'input' ) ),
		array( 'index' => 1, 'control' => $presentation_role( array( 'border' => '2px solid #222', 'padding' => '8px' ), array( 'border', 'padding' ), 'select' ) ),
		array( 'index' => 2, 'control' => $presentation_role( array( 'border' => '3px solid #333', 'min_height' => '9rem' ), array( 'border', 'min-height' ), 'textarea' ) ),
		array( 'index' => 3, 'control' => $presentation_role( array( 'background_color' => '#444', 'padding' => '9px 12px' ), array( 'background-color', 'padding' ), 'button' ) ),
	);
	$all_controls_presentation['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 2, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:48rem)' ), 'style_patch' => array( 'min_height' => '6rem' ), 'precedence' => array( 'min-height' => array( 'source_order' => 2, 'specificity' => 1, 'important' => false ) ), 'provenance' => array() ) );
	$validated_all_controls = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $all_controls_presentation );
	$all_controls_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_all_controls['forms'] ?? array() ) )['forms'][0] ?? array();
	$all_controls_markup    = (string) ( $all_controls_row['block_markup'] ?? '' );
	$all_controls_css       = (string) ( $all_controls_row['provider_layout_overlay_css']['css'] ?? '' );
	$all_controls_targets   = $all_controls_row['provider_layout_target_map']['presentation_targets'] ?? array();
	$all_controls_hooks     = array();
	foreach ( $all_controls_targets as $target ) {
		$selector = $target['destinations'][0]['selector'] ?? '';
		if ( is_array( $target ) && preg_match( '/\.ssi-node-[a-f0-9]{12}/', (string) $selector, $hook ) ) {
			$all_controls_hooks[] = substr( $hook[0], 1 );
		}
	}
	$assert( empty( $validated_all_controls['errors'] ) && 4 === count( $all_controls_hooks ) && empty( array_filter( $all_controls_hooks, static fn( string $hook ): bool => ! str_contains( $all_controls_markup, $hook ) || ! str_contains( $all_controls_css, '.' . $hook ) ) ) && str_contains( $all_controls_css, 'border:1px solid #111;padding:7px' ) && str_contains( $all_controls_css, 'border:2px solid #222;padding:8px' ) && str_contains( $all_controls_css, 'border:3px solid #333;min-height:9rem' ) && str_contains( $all_controls_css, 'background-color:#444;padding:9px 12px' ) && str_contains( $all_controls_css, '@media (max-width:48rem){' ) && str_contains( $all_controls_css, '> .wp-block-button__link{background-color:#444;padding:9px 12px}' ) && ! str_contains( $all_controls_css, 'control-shell' ) && ! str_contains( $all_controls_css, 'control-hook' ), 'presentation-overlay-persists-scoped-input-textarea-select-and-submit-relationships-without-raw-class-leakage', wp_json_encode( array( 'markup' => $all_controls_markup, 'css' => $all_controls_css, 'targets' => $all_controls_targets ) ) );
	$submit_width_form = $presentation_form;
	$submit_width_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'width' => '100%' ), array( 'width' ), 'button' ) ) );
	$submit_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_width_form );
	$submit_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $submit_width_validation['errors'] ) && preg_match( '/\.ssi-node-[a-f0-9]{12}\{width:100%\}/', (string) ( $submit_width_row['provider_layout_overlay_css']['css'] ?? '' ) ), 'source-submit-width-targets-the-wrapper-instead-of-its-shrink-wrapped-inner-button' );
	$phone_presentation = $presentation_form;
	$phone_presentation['forms'][0]['controls'][0]['type'] = 'phone';
	$phone_presentation['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'background_color' => '#fff', 'border_color' => '#1e4b6e', 'border_radius' => '0', 'font_size' => '16px', 'line_height' => '24px', 'padding_block_start' => '8px', 'padding_block_end' => '8px', 'padding_inline_start' => '8px', 'padding_inline_end' => '8px', 'height' => '40px' ), array( 'background-color', 'border-color', 'border-radius', 'font-size', 'line-height', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end', 'height' ), 'input' ) ) );
	$phone_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['styles']['text_indent'] = '4px';
	$validated_phone_presentation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_presentation );
	$phone_presentation_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_phone_presentation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_presentation_css       = (string) ( $phone_presentation_row['provider_layout_overlay_css']['css'] ?? '' );
	$phone_presentation_target    = $phone_presentation_row['provider_layout_target_map']['presentation_targets'][0] ?? array();
	$phone_destinations = $phone_presentation_target['destinations'] ?? array();
	$assert( '0' === ( $phone_destinations[0]['resets']['text-indent'] ?? null ) && str_contains( $phone_presentation_css, 'text-indent:0!important' ) && str_contains( $phone_presentation_css, 'text-indent:4px!important' ), 'phone-text-indentation-belongs-to-value-not-structural-prefix-container' );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $phone_presentation_row['provider_layout_overlay_css'] ?? array() ), 'composite-provider-destination-overlay-survives-stylesheet-admission' );
	$assert( empty( $validated_phone_presentation['errors'] ) && 3 === count( $phone_destinations ) && empty( $phone_destinations[0]['properties'] ) && str_contains( (string) ( $phone_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && str_contains( $phone_presentation_css, 'background-color:#fff!important' ) && str_contains( $phone_presentation_css, 'border-color:#1e4b6e!important' ) && str_contains( $phone_presentation_css, 'padding-block-start:8px!important' ) && str_contains( $phone_presentation_css, 'padding-inline-end:8px!important' ) && str_contains( $phone_presentation_css, 'padding:0!important;border:0!important;background:transparent!important' ), 'phone-presentation-keeps-input-styles-on-value-and-neutralizes-provider-added-shell', wp_json_encode( array( 'css' => $phone_presentation_css, 'target' => $phone_presentation_target ) ) );
	$editor_chrome_graph = array(
		'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'visual_parts' => array(), 'visual_groups' => array(), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array( array( 'index' => 0, 'control' => $presentation_role( array( 'border' => '0' ), array( 'border' ), 'input' ) ) ),
		'control_containers' => array( array( 'index' => 0, 'source_selector' => '.field', 'styles' => array( 'border' => '1px solid rgba(30,75,110,.6)', 'background' => 'rgb(247,249,251)', 'border_radius' => '0' ), 'provenance' => array( array( 'source_path' => 'assets/forms.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field', 'condition' => null, 'properties' => array( 'border', 'background', 'border-radius' ) ) ) ) ),
	);
	$editor_chrome_form = $presentation_form;
	$editor_chrome_form['forms'][0]['presentation_graph'] = $editor_chrome_graph;
	$editor_chrome_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $editor_chrome_form );
	$editor_chrome_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $editor_chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$editor_chrome_overlay = $editor_chrome_row['provider_layout_overlay_css'] ?? array();
	$editor_chrome_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/editor-chrome', 'Editor Chrome', '', array(), array(), array( $editor_chrome_overlay ) );
	$editor_chrome_frontend = (string) ( $editor_chrome_writes['/tmp/editor-chrome/style.css'] ?? '' );
	$editor_chrome_editor = (string) ( $editor_chrome_writes['/tmp/editor-chrome/assets/css/editor-style.css'] ?? '' );
	$assert( empty( $editor_chrome_validation['errors'] ) && str_contains( (string) ( $editor_chrome_overlay['css'] ?? '' ), 'border:0' ) && ! str_contains( (string) ( $editor_chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ) && str_contains( (string) ( $editor_chrome_overlay['editor_css'] ?? '' ), '.editor-styles-wrapper ' ) && str_contains( $editor_chrome_editor, 'border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0' ) && ! str_contains( $editor_chrome_frontend, '1px solid rgba(30,75,110,.6)' ), 'editor-only-control-container-chrome-preserves-a-single-owned-source-wrapper-on-the-editable-control-without-changing-frontend-input-resets', wp_json_encode( $editor_chrome_overlay ) );
	$phone_editor_chrome_form                              = $editor_chrome_form;
	$phone_editor_chrome_form['forms'][0]['controls'][0]['type'] = 'phone';
	$phone_editor_chrome_form['forms'][0]['presentation_graph']['controls'][0]['control'] = $presentation_role( array( 'padding_inline_end' => '2px' ), array( 'padding-inline-end' ), 'input' );
	$phone_editor_chrome_validation                        = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_editor_chrome_form );
	$phone_editor_chrome_row                               = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $phone_editor_chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_editor_chrome_overlay                           = $phone_editor_chrome_row['provider_layout_overlay_css'] ?? array();
	$phone_editor_chrome_destinations                      = $phone_editor_chrome_row['provider_layout_target_map']['presentation_targets'][0]['destinations'] ?? array();
	$assert( empty( $phone_editor_chrome_validation['errors'] ) && 4 === count( $phone_editor_chrome_destinations ) && str_contains( (string) ( $phone_editor_chrome_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && '0' === ( $phone_editor_chrome_destinations[0]['resets']['padding'] ?? null ) && str_contains( (string) ( $phone_editor_chrome_destinations[2]['selector'] ?? '' ), '-destination-carrier' ) && str_contains( (string) ( $phone_editor_chrome_destinations[3]['selector'] ?? '' ), '-destination-shell' ) && str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), 'padding-inline-end:2px!important' ) && str_contains( (string) ( $phone_editor_chrome_overlay['editor_css'] ?? '' ), '-destination-shell{border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0}' ) && ! str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ), 'phone-control-container-chrome-targets-the-editor-shell-while-primary-and-carrier-destinations-retain-their-separate-ownership', wp_json_encode( $phone_editor_chrome_row ) );
	$unsafe_presentation = $presentation_form;
	$unsafe_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['styles']['background_image'] = 'url(https://example.test/tracker)';
	$oversized_presentation = $presentation_form;
	$oversized_presentation['forms'][0]['presentation_graph']['controls'] = array_fill( 0, 129, array() );
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_presentation )['errors'] ) && ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $oversized_presentation )['errors'] ), 'form-presentation-contract-rejects-unsafe-or-unbounded-input' );
	$candidate_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' );
	$candidate_root        = is_string( $candidate_root ) && '' !== $candidate_root ? rtrim( $candidate_root, '/\\' ) : dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer';
	$candidate_transformer = $candidate_root . '/php-transformer.php';
	if ( ! is_readable( $candidate_transformer ) && is_readable( $candidate_root . '/php-transformer/php-transformer.php' ) ) {
		$candidate_transformer = $candidate_root . '/php-transformer/php-transformer.php';
	}
	if ( ! is_readable( $candidate_transformer ) ) {
		throw new RuntimeException( 'The required Blocks Engine transformer is unavailable.' );
	}
	$candidate_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<link rel="stylesheet" href="style.css"><main><form><div><button type="button" aria-label="Phone country selector"><span class="sourcegroup"><svg class="globe" width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><svg class="chevron" width="16" height="16" viewBox="0 0 16 16"><path d="M4 7l4 4 4-4"/></svg></span></button><input type="tel" name="phone"></div></form></main>', 'style.css' => '.sourcegroup{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:8px}.globe{width:24px;color:rgb(30,75,110)}.chevron{width:16px}@media (min-width:769px){.sourcegroup{gap:4px}.chevron{width:12px}}' ) );
	$candidate_artifact['files']['style.css'] .= 'button{position:relative;flex-shrink:0;transform:translateX(0)}';
	$candidate_code = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $candidate_artifact, true ) . '));';
	$candidate_json = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $candidate_code ) );
	$candidate_result = is_string( $candidate_json ) ? json_decode( $candidate_json, true ) : null;
	if ( ! is_array( $candidate_result ) ) {
		throw new RuntimeException( 'The required Blocks Engine candidate compilation failed.' );
	}
	$candidate_plan = $candidate_result['source_reports']['wordpress_site_plan'] ?? array();
	$chrome_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<link rel="stylesheet" href="style.css"><form><div class="field"><input name="email"></div><div class="shared"><input name="first"><input name="last"></div><div class="plain"><input name="plain"></div></form>', 'style.css' => 'input{border:0}div{background:0 0}@media (min-width:769px){.field{border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0}}.shared{border:2px solid #111}' ) );
	$chrome_code = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $chrome_artifact, true ) . '));';
	$chrome_json = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $chrome_code ) );
	$chrome_result = is_string( $chrome_json ) ? json_decode( $chrome_json, true ) : null;
	$chrome_declaration = is_array( $chrome_result ) ? current( array_filter( $chrome_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) ) : array();
	$chrome_entity = $chrome_declaration['payload']['entities'][0] ?? array();
	$chrome_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $chrome_entity ) ) );
	$chrome_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $chrome_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$chrome_overlay = $chrome_row['provider_layout_overlay_css'] ?? array();
	$chrome_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/real-be-chrome', 'Real BE Chrome', '', array(), array(), array( $chrome_overlay ) );
	$assert( is_array( $chrome_result ) && array() === ( $chrome_entity['presentation_graph']['control_containers'][0]['styles'] ?? null ) && 1 === count( $chrome_entity['presentation_graph']['control_containers'] ?? array() ) && str_contains( (string) ( $chrome_overlay['css'] ?? '' ), 'border:0' ) && ! str_contains( (string) ( $chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ) && str_contains( (string) ( $chrome_writes['/tmp/real-be-chrome/assets/css/editor-style.css'] ?? '' ), 'border:1px solid rgba(30,75,110,.6)' ) && ! str_contains( (string) ( $chrome_writes['/tmp/real-be-chrome/style.css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ), 'real-blocks-engine-artifact-preserves-responsive-owned-wrapper-paint-without-assigning-shared-or-neutral-wrappers', wp_json_encode( $chrome_row ) );
	$candidate_declaration = current( array_filter( $candidate_plan['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) );
	$visual_state_form = array( 'forms' => $candidate_declaration['payload']['entities'] ?? array() );
	$validated_visual_state = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $visual_state_form );
	$visual_state_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_visual_state['forms'] ?? array() ) )['forms'][0] ?? array();
	$visual_state = $visual_state_row['form_visual_state'] ?? array();
	Static_Site_Importer_Provider_Form_Runtime_V1::configure_visual_states( array( $visual_state ) );
	$visual_provider_html = Static_Site_Importer_Provider_Form_Runtime_V1::project_empty_country_visual_state( '<div class="jetpack-field__input-phone-wrapper"><button class="jetpack-combobox-trigger"><span class="jetpack-combobox-trigger-arrow"><svg></svg></span><span data-wp-text="context.selectedCountry.value"></span></button><input type="hidden" id="' . ( $visual_state['field_id'] ?? '' ) . '"></div>' );
	$auxiliary_target = $visual_state_row['provider_layout_target_map']['presentation_targets'][0]['destinations'][0] ?? array();
	$auxiliary_overlay = (string) ( $visual_state_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $validated_visual_state['errors'] ) && 2 === count( $visual_state['parts'] ?? array() ) && 'visual-group-' === substr( (string) ( $visual_state['group']['id'] ?? '' ), 0, 13 ) && str_contains( $visual_provider_html, 'data-wp-bind--hidden="context.selectedCountry.value"' ) && str_contains( $visual_provider_html, 'data-wp-bind--hidden="!context.selectedCountry.value"' ) && str_contains( $visual_provider_html, ':not([hidden])' ) && str_contains( $visual_provider_html, 'display:flex!important' ) && str_contains( $visual_provider_html, 'gap:8px!important' ) && str_contains( $visual_provider_html, 'gap:4px!important' ) && str_contains( $visual_provider_html, 'width:24px!important' ) && str_contains( $visual_provider_html, 'width:12px!important' ) && str_contains( $visual_provider_html, '@media (min-width:769px)' ) && 1 === substr_count( $visual_provider_html, 'jetpack-combobox-trigger-arrow' ) && str_contains( $visual_provider_html, (string) ( $visual_state['trigger_class'] ?? '' ) ) && str_contains( (string) ( $auxiliary_target['selector'] ?? '' ), '-destination-country-trigger' ) && str_contains( $auxiliary_overlay, 'position:relative!important' ) && str_contains( $auxiliary_overlay, 'flex-shrink:0!important' ) && str_contains( $auxiliary_overlay, 'transform:translateX(0)!important' ) && empty( $visual_state_row['form_receipt_unaccepted_losses'] ?? array() ) && ! str_contains( $visual_provider_html, 'base64' ), 'candidate-v2-auxiliary-visual-group-retains-source-flex-and-conditional-gap-with-hidden-state-winning', wp_json_encode( array( 'validation' => $validated_visual_state, 'row' => $visual_state_row, 'html' => $visual_provider_html ) ) );
	$marker_artifact = array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<link rel="stylesheet" href="style.css"><main><form><label class="source-label" for="inherited">Inherited <span aria-hidden="true">*</span></label><input id="inherited" name="inherited" required></form><form><label class="small-label" for="small">Small <span class="required-marker" aria-hidden="true">*</span></label><input id="small" name="small" required></form><form><label class="conditional-label" for="conditional">Conditional <span class="required-marker" aria-hidden="true">*</span></label><input id="conditional" name="conditional" required></form><form><label class="plain-label" for="plain">Plain</label><input id="plain" name="plain" required></form></main>',
			'style.css'  => '.source-label{font-size:14px;line-height:19.6px}.small-label{font-size:14px;line-height:19.6px}.small-label .required-marker{font-size:12px;line-height:16px}.conditional-label{--marker-size:14px;font-size:14px;line-height:19.6px}.conditional-label .required-marker{font-size:var(--marker-size);line-height:19.6px}@media (max-width:768px){.conditional-label .required-marker{font-size:12px;margin-left:2px}}.plain-label{font-size:14px;line-height:19.6px}',
		),
	);
	$marker_code   = 'require ' . var_export( $candidate_transformer, true ) . '; echo json_encode(blocks_engine_php_transformer_compile_artifact(' . var_export( $marker_artifact, true ) . '));';
	$marker_json   = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $marker_code ) );
	$marker_result = is_string( $marker_json ) ? json_decode( $marker_json, true ) : null;
	$marker_declaration = is_array( $marker_result ) ? current( array_filter( $marker_result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn( array $declaration ): bool => 'forms' === ( $declaration['type'] ?? null ) ) ) : array();
	$marker_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $marker_declaration['payload']['entities'] ?? array() ) );
	$marker_rows       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $marker_validation['forms'] ?? array() ) )['forms'] ?? array();
	$marker_css        = array_map( static fn( array $row ): string => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ), $marker_rows );
	$assert( empty( $marker_validation['errors'] ) && 4 === count( $marker_rows ) && preg_match( '/\.ssi-node-[a-f0-9]{12} > \.grunion-label-required\{font-size:inherit!important\}/', $marker_css[0] ?? '' ) && str_contains( $marker_css[1] ?? '', ' > .grunion-label-required{font-size:inherit!important;font-size:12px!important;line-height:16px!important}' ) && str_contains( $marker_css[2] ?? '', ' > .grunion-label-required{font-size:inherit!important;font-size:14px!important;line-height:19.6px!important}' ) && str_contains( $marker_css[2] ?? '', '@media (max-width:768px){' ) && str_contains( $marker_css[2] ?? '', 'font-size:12px!important;margin-left:2px!important' ) && ! str_contains( $marker_css[3] ?? '', 'grunion-label-required' ), 'paired-BE-to-SSI-required-marker-projection-is-field-scoped-and-keeps-authored-base-and-conditional-linebox-facts', wp_json_encode( array( 'validation' => $marker_validation, 'css' => $marker_css ) ) );
	$malformed_visual_state = $visual_state_form;
	$malformed_visual_state['forms'][0]['presentation_graph']['visual_parts'][0]['markup'] = '<svg><script>alert(1)</script></svg>';
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $malformed_visual_state )['errors'] ), 'v2-visual-parts-reject-malformed-svg-at-intake' );
	$topology_seed_repeat = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$assert( $topology_markup === (string) ( $topology_seed_repeat['forms'][0]['block_markup'] ?? '' ), 'provider-layout-classes-are-stable-for-identical-source-form' );
	$field_list_form = $topology_form['forms'][0];
	foreach ( $field_list_form['control_topology']['nodes'] as &$field_list_node ) {
		++$field_list_node['depth'];
		if ( null === ( $field_list_node['parent'] ?? null ) ) {
			$field_list_node['parent'] = 'wrapper-4';
		}
	}
	unset( $field_list_node );
	array_unshift( $field_list_form['control_topology']['nodes'], array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ) );
	$field_list_form['layout_graph']['nodes'][0]['parent'] = 'wrapper-4';
	array_unshift( $field_list_form['layout_graph']['nodes'], array( 'id' => 'wrapper-4', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'field-list' ) ), 'layout' => array(), 'provenance' => array() ) );
	$field_list_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-4', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => '2rem' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'flex-direction' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ), 'gap' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'f', 64 ), 'selector' => '.field-list', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) );
	$field_list_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $field_list_form ) ) );
	$field_list_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $field_list_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( str_contains( (string) ( $field_list_row['block_markup'] ?? '' ), 'form contact field-list ssi-form-' ) && in_array( 'provider_field_list_class_projection', array_column( $field_list_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! in_array( 'responsive_layout_ownership', array_column( $field_list_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'class-owned-field-list-wrapper-projects-onto-provider-container', wp_json_encode( array( 'validation' => $field_list_validation, 'row' => $field_list_row ) ) );

	$grid_submit_form = array(
		'selector' => 'form.grid-submit',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array(
			'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
			'nodes' => array(
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
				array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div' ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 1 ),
			),
		),
		'layout_graph' => $v2_layout_graph( array(
			array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'grid-submit' ) ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.grid-submit', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns' ) ) ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array( 'submit-cell' ) ), 'layout' => array( 'column' => 'span 4' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.submit-cell', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) ),
		) ),
	);
	$grid_submit_condition = array( 'kind' => 'media', 'query' => '(max-width: 767px)' );
	$grid_submit_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $grid_submit_condition, 'layout_patch' => array( 'column' => 'span 12' ), 'precedence' => array( 'grid-column' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.submit-cell', 'condition' => $grid_submit_condition, 'properties' => array( 'grid-column' ) ) ) );
	$grid_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_submit_form ) ) );
	$grid_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_submit_css = (string) ( $grid_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $grid_submit_validation['errors'] ) && 'mapped' === ( $grid_submit_row['status'] ?? '' ) && in_array( 'provider_grid_span_submit', array_column( $grid_submit_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $grid_submit_css, 'width:33.333%' ) && str_contains( $grid_submit_css, '@media (max-width: 767px)' ) && str_contains( $grid_submit_css, 'width:100%' ), 'proven-grid-span-submit-transposes-to-responsive-provider-width', wp_json_encode( array( 'validation' => $grid_submit_validation, 'row' => $grid_submit_row ) ) );
	$grid_area_submit_form = $grid_submit_form;
	$grid_area_submit_form['layout_graph']['nodes'][1]['layout'] = array( 'area' => '2 / 1 / span 1 / span 4' );
	$grid_area_submit_form['layout_graph']['nodes'][1]['provenance'][0]['properties'] = array( 'grid-area' );
	$grid_area_submit_form['layout_graph']['variants'] = array();
	$grid_area_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_area_submit_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $grid_area_submit_row['status'] ?? '' ) && str_contains( (string) ( $grid_area_submit_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:33.333%' ) && ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $grid_area_submit_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'proven-grid-area-submit-transposes-to-provider-width-without-claiming-row-placement', wp_json_encode( $grid_area_submit_row ) );
	$full_width_grid_field_form = array(
		'forms' => array( array(
			'selector' => 'form.full-width-grid-field',
			'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-shell' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array(
				$layout_node( 'form', array(), 'form' ),
				array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'display' => 'grid', 'columns' => 'repeat(12, 1fr)', 'gap' => '1rem' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'grid-template-columns', 'gap' ) ) ) ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'column' => 'span 12' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell input', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) ),
			) ),
		) ),
	);
	$full_width_grid_field_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = '100%';
	$full_width_grid_field_form['forms'][0]['layout_graph']['nodes'][1]['provenance'][0]['properties'][] = 'width';
	$full_width_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $full_width_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$full_width_grid_field_css = (string) ( $full_width_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( 'mapped' === ( $full_width_grid_field_row['status'] ?? '' ) && in_array( 'provider_fullspan_grid_child', array_column( $full_width_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $full_width_grid_field_css, 'display:grid;grid-template-columns:repeat(12, 1fr);gap:1rem;width:100%' ) && str_contains( $full_width_grid_field_css, 'grid-column:span 12' ), 'full-span-single-field-grid-retains-proven-tracks-and-native-child-placement', wp_json_encode( $full_width_grid_field_row ) );
	$fullspan_child_runtime = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-email-wrap ssi-node-a1b2c3d4e5f6-wrap ssi-source-wrapper-0--source-grid-wrap ssi-source-fullspan-child--ssi-node-0f1e2d3c4b5a-wrap"><label>Email</label><input type="email"></div>' );
	$assert( '<div class="grunion-field-email-wrap"><label>Email</label><div class="source-grid ssi-node-a1b2c3d4e5f6-wrap"><div class="ssi-node-0f1e2d3c4b5a-wrap"><input type="email"></div></div></div>' === $fullspan_child_runtime, 'full-span-grid-rebuilds-a-real-value-child-inside-the-source-grid-container', $fullspan_child_runtime );
	$partial_grid_field_form = $full_width_grid_field_form;
	$partial_grid_field_form['forms'][0]['layout_graph']['nodes'][2]['layout']['column'] = 'span 6';
	$partial_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $partial_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $partial_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'partial-span-field-grid-does-not-claim-full-width-provider-ownership', wp_json_encode( $partial_grid_field_row ) );
	$explicit_start_grid = $full_width_grid_field_form;
	$explicit_start_grid['forms'][0]['layout_graph']['nodes'][2]['layout']['column'] = '1 / span 12';
	$explicit_start_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $explicit_start_grid )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( in_array( 'provider_fullspan_grid_child', array_column( $explicit_start_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $explicit_start_row['provider_layout_overlay_css']['css'] ?? '', 'grid-column:1 / span 12' ), 'explicit-grid-start-and-full-span-preserve-native-child-placement' );
	$nested_grid_field_form = $full_width_grid_field_form;
	$nested_grid_field_form['forms'][0]['control_topology']['nodes'][1]['parent'] = 'wrapper-1';
	$nested_grid_field_form['forms'][0]['control_topology']['nodes'][1]['depth'] = 2;
	array_splice( $nested_grid_field_form['forms'][0]['control_topology']['nodes'], 1, 0, array( array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div' ) ) );
	$nested_grid_field_form['forms'][0]['layout_graph']['nodes'][2]['parent'] = 'wrapper-1';
	array_splice( $nested_grid_field_form['forms'][0]['layout_graph']['nodes'], 2, 0, array( array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'area' => '2 / 1 / span 1 / span 12' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'grid-area' ) ) ) ) ) );
	$nested_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_grid_field_css = (string) ( $nested_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$nested_grid_field_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'column_gap' => '1.5rem' ), 'precedence' => array( 'column-gap' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'column-gap' ) ) ) );
	$nested_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_grid_field_css = (string) ( $nested_grid_field_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( in_array( 'provider_fullspan_grid_branch', array_column( $nested_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && str_contains( $nested_grid_field_css, 'repeat(12, 1fr)' ) && str_contains( $nested_grid_field_css, 'grid-area:2 / 1 / span 1 / span 12' ) && str_contains( $nested_grid_field_css, '@media (max-width: 48rem)' ) && str_contains( $nested_grid_field_css, 'column-gap:1.5rem' ), 'nested-single-field-grid-keeps-proven-wrapper-grid-placement-and-responsive-gap', wp_json_encode( $nested_grid_field_row ) );
	$multi_control_grid_field_form = $full_width_grid_field_form;
	$multi_control_grid_field_form['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' );
	array_splice( $multi_control_grid_field_form['forms'][0]['control_topology']['nodes'], 2, 0, array( array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 2 ) ) );
	$multi_control_grid_field_form['forms'][0]['layout_graph']['nodes'][] = array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'column' => 'span 12' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell input', 'condition' => null, 'properties' => array( 'grid-column' ) ) ) );
	$multi_control_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $multi_control_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $multi_control_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'multi-control-full-span-grid-does-not-claim-single-field-provider-ownership', wp_json_encode( $multi_control_grid_field_row ) );
	$variant_grid_field_form = $full_width_grid_field_form;
	$variant_grid_field_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'columns' => 'repeat(6, 1fr)' ), 'precedence' => array( 'grid-template-columns' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.field-shell', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'grid-template-columns' ) ) ) );
	$variant_grid_field_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_grid_field_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! in_array( 'provider_fullspan_grid_child', array_column( $variant_grid_field_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'responsive-grid-variant-does-not-claim-static-full-width-provider-ownership', wp_json_encode( $variant_grid_field_row ) );

	$grid_track_form = array(
		'selector' => 'form.source-grid',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ), array( 'tag' => 'input', 'type' => 'text', 'name' => 'cancel_note', 'label' => 'Cancel note' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
		'layout_graph' => $v2_layout_graph( array(
			array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'source-grid' ) ), 'layout' => array( 'display' => 'grid', 'columns' => '1fr 155.4px' ), 'provenance' => array() ),
			array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'button', 'classes' => array() ), 'layout' => array( 'column' => '2' ), 'provenance' => array(), 'sizing' => array( 'kind' => 'grid_track', 'axis' => 'inline', 'container' => 'form', 'grid_column' => '2' ) ),
			array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'form', 'order' => 2, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'justify_self' => 'start' ), 'provenance' => array() ),
		) ),
		'presentation_graph' => array( 'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(), 'controls' => array( array( 'index' => 1, 'control' => array( 'styles' => array( 'padding' => '5%' ), 'provenance' => array() ) ) ) ),
	);
	$grid_track_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $grid_track_form ) ) );
	$grid_track_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_track_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_track_css        = (string) ( $grid_track_row['provider_layout_overlay_css']['css'] ?? '' );
	$source_control_width  = 155.4;
	$source_padding        = $source_control_width * 0.05;
	$content_control_hook  = 'ssi-node-' . substr( hash( 'sha256', 'ssi-form-' . substr( hash( 'sha256', "\nform.source-grid" ), 0, 12 ) . "\ncontrol-2" ), 0, 12 );
	preg_match( '/width:([0-9.]+)px/', $grid_track_css, $provider_width );
	$provider_padding = isset( $provider_width[1] ) ? (float) $provider_width[1] * 0.05 : 0.0;
	$assert( empty( $grid_track_validation['errors'] ) && 'mapped' === ( $grid_track_row['status'] ?? '' ) && in_array( 'provider_grid_track_control_width', array_column( $grid_track_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && 1 === substr_count( $grid_track_css, 'width:155.4px;flex:0 1 auto' ) && str_contains( $grid_track_css, 'padding:5%' ) && ! str_contains( $grid_track_css, '.' . $content_control_hook . '{width:' ) && abs( $source_padding - $provider_padding ) < 0.001, 'grid-track-submit-keeps-source-width-and-percentage-padding-when-provider-layout-differs', wp_json_encode( array( 'css' => $grid_track_css, 'source_width' => $source_control_width, 'source_padding' => $source_padding, 'provider_padding' => $provider_padding ) ) );
	$fixed_control_form = array(
		'forms' => array( array(
			'selector' => 'form.fixed-control',
			'controls' => array( array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ), array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
			'layout_graph' => $v2_layout_graph( array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'fixed-control' ) ), 'layout' => array(), 'provenance' => array() ),
				array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'textarea', 'classes' => array() ), 'layout' => array( 'width' => '611px' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ) ),
				array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array( 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ) ),
				array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'form', 'order' => 2, 'source' => array( 'tag' => 'button', 'classes' => array() ), 'layout' => array( 'width' => '280px', 'flex_grow' => 1 ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width', 'flex-grow' ) ) ) ),
			) ),
		) ),
	);
	$fixed_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $fixed_control_form );
	$fixed_control_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $fixed_control_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$fixed_control_css        = (string) ( $fixed_control_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $fixed_control_validation['errors'] ) && str_contains( $fixed_control_css, 'width:611px;flex:0 1 auto' ) && str_contains( $fixed_control_css, 'width:100%' ) && ! str_contains( $fixed_control_css, 'width:100%;flex:0 1 auto' ) && str_contains( $fixed_control_css, 'width:280px;flex-grow:1' ) && ! str_contains( $fixed_control_css, 'width:280px;flex-grow:1;flex:0 1 auto' ), 'fixed-provenance-width-neutralizes-jetpack-flex-without-changing-fluid-or-authored-flex-fields', $fixed_control_css );
	$responsive_fixed_control_form = $fixed_control_form;
	$responsive_fixed_control_form['forms'][0]['layout_graph']['nodes'][1]['layout']['width'] = '100%';
	$responsive_fixed_control_condition = array( 'kind' => 'media', 'query' => '(min-width: 769px)' );
	$responsive_fixed_control_form['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'control-0', 'condition' => $responsive_fixed_control_condition, 'layout_patch' => array( 'width' => '611px' ), 'precedence' => array( 'width' => array( 'source_order' => 2, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.fixed-control textarea', 'condition' => $responsive_fixed_control_condition, 'properties' => array( 'width' ) ) ) );
	$responsive_fixed_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_fixed_control_form );
	$responsive_fixed_control_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_fixed_control_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$responsive_fixed_control_css        = (string) ( $responsive_fixed_control_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $responsive_fixed_control_validation['errors'] ) && str_contains( $responsive_fixed_control_css, '@media (min-width: 769px)' ) && str_contains( $responsive_fixed_control_css, 'width:611px;flex:0 1 auto' ) && ! str_contains( $responsive_fixed_control_css, 'width:100%;flex:0 1 auto' ), 'responsive-fixed-provenance-width-neutralizes-jetpack-flex-without-changing-base-fluid-width', $responsive_fixed_control_css );
	$responsive_grid_track_form                                      = $grid_track_form;
	$responsive_grid_track_condition                                 = array( 'kind' => 'media', 'query' => '(max-width: 48rem)' );
	$responsive_grid_track_form['layout_graph']['variants'][]        = array( 'node' => 'form', 'condition' => $responsive_grid_track_condition, 'layout_patch' => array( 'columns' => '1fr' ), 'precedence' => array( 'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 1, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.source-grid', 'condition' => $responsive_grid_track_condition, 'properties' => array( 'grid-template-columns' ) ) ) );
	$responsive_grid_track_validation                                = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $responsive_grid_track_form ) ) );
	$responsive_grid_track_row                                       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $responsive_grid_track_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$responsive_grid_track_css                                       = (string) ( $responsive_grid_track_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $responsive_grid_track_validation['errors'] ) && ! in_array( 'provider_grid_track_control_width', array_column( $responsive_grid_track_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! str_contains( $responsive_grid_track_css, 'width:155.4px' ), 'grid-track-sizing-skips-conditional-track-or-placement-changes', wp_json_encode( array( 'validation' => $responsive_grid_track_validation, 'row' => $responsive_grid_track_row ) ) );

	// V2 percentage facts replace only a complete, provenance-backed sibling row.
	$deep_width_form = array(
		'selector' => 'form.deep-widths',
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First' ),
			array( 'tag' => 'input', 'type' => 'email', 'name' => 'second', 'label' => 'Second' ),
			array( 'tag' => 'input', 'type' => 'tel', 'name' => 'third', 'label' => 'Third' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	$deep_width_topology = array();
	$deep_width_graph    = array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'deep-widths' ) ), 'layout' => array(), 'provenance' => array() ) );
	$parent = null;
	$graph_parent = 'form';
	for ( $depth = 0; $depth < 9; ++$depth ) {
		$id = 'wrapper-' . $depth;
		$deep_width_topology[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $depth, 'tag' => 'div' );
		$deep_width_graph[] = array( 'id' => $id, 'kind' => 'container', 'parent' => $graph_parent, 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array(), 'provenance' => array() );
		$parent = $id;
		$graph_parent = $id;
	}
	foreach ( array( 9 => 'table', 10 => 'tbody', 11 => 'tr' ) as $wrapper => $tag ) {
		$id = 'wrapper-' . $wrapper;
		$deep_width_topology[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $wrapper, 'tag' => $tag );
		$deep_width_graph[] = array( 'id' => $id, 'kind' => 'container', 'parent' => $graph_parent, 'order' => 0, 'source' => array( 'tag' => $tag, 'classes' => array() ), 'layout' => array(), 'provenance' => array() );
		$parent = $id;
		$graph_parent = $id;
	}
	foreach ( array( 12, 14, 16 ) as $column => $cell_id ) {
		$field_id = $cell_id + 1;
		$deep_width_topology[] = array( 'id' => 'wrapper-' . $cell_id, 'kind' => 'wrapper', 'parent' => 'wrapper-11', 'order' => $column, 'depth' => 12, 'tag' => 'td' );
		$deep_width_topology[] = array( 'id' => 'wrapper-' . $field_id, 'kind' => 'wrapper', 'parent' => 'wrapper-' . $cell_id, 'order' => 0, 'depth' => 13, 'tag' => 'div', 'class' => 'field' );
		$deep_width_topology[] = array( 'id' => 'control-' . $column, 'kind' => 'control', 'parent' => 'wrapper-' . $field_id, 'order' => 0, 'depth' => 14, 'control' => $column );
		$deep_width_graph[] = array(
			'id' => 'wrapper-' . $cell_id, 'kind' => 'container', 'parent' => 'wrapper-11', 'order' => $column,
			'source' => array( 'tag' => 'td', 'classes' => array() ), 'layout' => array( 'width' => '33.333333333333%' ),
			'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'width' ) ) ),
		);
	}
	$deep_width_topology[] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 3 );
	$deep_width_form['control_topology'] = array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'nodes' => $deep_width_topology, 'truncated' => false );
	$deep_width_form['layout_graph'] = $v2_layout_graph( $deep_width_graph );
	$responsive_width_conditions = array(
		array( 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 992px)' ), 'layout_patch' => array( 'width' => '50%' ) ),
		array( 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 767px)' ), 'layout_patch' => array( 'display' => 'block', 'width' => '100%' ) ),
	);
	foreach ( array( 12, 14, 16 ) as $cell_id ) {
		foreach ( $responsive_width_conditions as $responsive_width ) {
			$condition = $responsive_width['condition'];
			$precedence = array();
			foreach ( array_keys( $responsive_width['layout_patch'] ) as $property ) {
				$precedence[ $property ] = array( 'source_order' => 1, 'specificity' => 10, 'important' => false );
			}
			$deep_width_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-' . $cell_id, 'condition' => $condition, 'layout_patch' => $responsive_width['layout_patch'], 'precedence' => $precedence, 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.column', 'condition' => $condition, 'properties' => array_keys( $responsive_width['layout_patch'] ) ) ) );
		}
	}
	$deep_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $deep_width_form ) ) );
	$deep_width_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $deep_width_validation['forms'] ?? array() ) );
	$deep_width_row = $deep_width_seed['forms'][0] ?? array();
	$deep_width_markup = (string) ( $deep_width_row['block_markup'] ?? '' );
	$deep_width_receipt = $deep_width_row['computed_layout_receipt'] ?? array();
	$assert( empty( $deep_width_validation['errors'] ) && 'mapped' === ( $deep_width_row['status'] ?? '' ) && true === ( $deep_width_row['runtime_mapped'] ?? false ), 'v2-deep-percentage-row-validates-and-materializes', wp_json_encode( array( 'validation' => $deep_width_validation, 'row' => $deep_width_row ) ) );
	$assert( 3 === substr_count( $deep_width_markup, '"width":33.333' ) && ! str_contains( $deep_width_markup, '<table' ), 'v2-deep-percentage-row-maps-three-provider-field-widths', $deep_width_markup );
	$deep_width_overlay_css = (string) ( $deep_width_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( in_array( 'provider_percentage_width_fields', array_column( $deep_width_receipt['operations'] ?? array(), 'strategy' ), true ) && empty( $deep_width_receipt['losses'] ) && empty( $deep_width_row['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $deep_width_overlay_css, '@media (max-width: 992px)' ) && str_contains( $deep_width_overlay_css, 'width:50%' ) && str_contains( $deep_width_overlay_css, 'display:block;width:100%' ), 'v2-responsive-percentage-row-has-proven-field-overlays', wp_json_encode( $deep_width_row ) );

	$unsafe_variant_form = $deep_width_form;
	$unsafe_variant_form['layout_graph']['variants'][0]['layout_patch'] = array( 'display' => 'none', 'width' => '50%' );
	$unsafe_variant_form['layout_graph']['variants'][0]['precedence']['display'] = array( 'source_order' => 1, 'specificity' => 10, 'important' => false );
	$unsafe_variant_form['layout_graph']['variants'][0]['provenance'][0]['properties'][] = 'display';
	$unsafe_variant_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unsafe_variant_form ) ) );
	$unsafe_variant_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_variant_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unsafe_variant_row['status'] ?? '' ) && ! str_contains( (string) ( $unsafe_variant_row['block_markup'] ?? '' ), '"width":33.333' ), 'unsafe-percentage-variant-fails-closed' );

	$hidden_bookkeeping_form = array(
		'selector' => 'form.runtime-controls',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'token' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 1, 'depth' => 0, 'tag' => 'div' ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
		'layout_graph' => $layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'input', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 1, 'source' => array( 'tag' => 'div', 'classes' => array() ), 'layout' => array( 'display' => 'none' ), 'provenance' => array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display' ) ) ) ) ) ),
	);
	$hidden_bookkeeping_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_bookkeeping_form ) ) );
	$hidden_bookkeeping_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $hidden_bookkeeping_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $hidden_bookkeeping_row['status'] ?? '' ) && in_array( 'provider_omitted_runtime_controls', array_column( $hidden_bookkeeping_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $hidden_bookkeeping_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'hidden-runtime-bookkeeping-wrapper-is-bounded-and-receipted', wp_json_encode( $hidden_bookkeeping_row ) );
	$hidden_variant_form = $hidden_bookkeeping_form;
	$hidden_variant_form['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'layout_patch' => array( 'display' => 'block' ), 'precedence' => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.runtime', 'condition' => array( 'kind' => 'media', 'query' => '(max-width: 48rem)' ), 'properties' => array( 'display' ) ) ) );
	$hidden_variant_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_variant_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $hidden_variant_row['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', array_column( $hidden_variant_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'responsive-hidden-wrapper-remains-unrepresented' );

	$hidden_native_select = array(
		'selector' => 'form.enhanced-select',
		'controls' => array( array( 'tag' => 'select', 'type' => 'select', 'name' => 'choice', 'label' => 'Choice', 'options' => array( 'One' ) ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'replacement-shell' ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ) ) ),
		'layout_graph' => $v2_layout_graph( array( $layout_node( 'form', array(), 'form' ), array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => 'form', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'replacement-shell' ) ), 'layout' => array( 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.replacement-shell', 'condition' => null, 'properties' => array( 'width' ) ) ) ), array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'select', 'classes' => array( 'enhanced' ) ), 'layout' => array( 'display' => 'none', 'width' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '.enhanced', 'condition' => null, 'properties' => array( 'display', 'width' ) ) ) ) ) ),
	);
	$hidden_native_select_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $hidden_native_select ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $hidden_native_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility', array_column( $hidden_native_select_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! str_contains( (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ), 'display:none' ) && str_contains( (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:100%' ), 'provider-native-controls-replace-hidden-display-and-retain-other-layout', wp_json_encode( $hidden_native_select_row ) );
	$unproven_hidden_select = $hidden_native_select;
	$unproven_hidden_select['layout_graph']['nodes'][2]['provenance'][0]['selector'] = '.replacement-shell .enhanced';
	$unproven_hidden_select_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unproven_hidden_select ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $unproven_hidden_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility_unrepresentable', array_column( $unproven_hidden_select_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'source-hidden-native-control-without-replacement-evidence-fails-closed', wp_json_encode( $unproven_hidden_select_row ) );

	$partial_width_form = $deep_width_form;
	$partial_width_form['layout_graph']['nodes'][13]['layout']['width'] = '30%';
	$partial_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $partial_width_form ) ) );
	$partial_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $partial_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $partial_width_row['status'] ?? '' ) && ! str_contains( (string) ( $partial_width_row['block_markup'] ?? '' ), '"width":33.333' ) && ! str_contains( (string) ( $partial_width_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:50%' ), 'partial-percentage-row-fails-closed' );
	$multiple_controls_form = $deep_width_form;
	$multiple_controls_form['control_topology']['nodes'][21]['parent'] = 'wrapper-12';
	$multiple_controls_form['control_topology']['nodes'][21]['order'] = 1;
	$multiple_controls_form['control_topology']['nodes'][21]['depth'] = 13;
	$multiple_controls_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $multiple_controls_form ) ) );
	$multiple_controls_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $multiple_controls_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $multiple_controls_validation['errors'] ) && 'skipped' === ( $multiple_controls_row['status'] ?? '' ) && ! str_contains( (string) ( $multiple_controls_row['block_markup'] ?? '' ), '"width":33.333' ), 'multiple-controls-in-percentage-branch-fail-closed' );
	$v1_with_width = $deep_width_form;
	$v1_with_width['layout_graph']['schema'] = 'generic/computed-layout-graph/v1';
	$v1_with_width['layout_graph']['limits']['depth'] = 8;
	$v1_with_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $v1_with_width ) ) );
	$assert( empty( $v1_with_width_validation['forms'] ) && str_contains( (string) ( $v1_with_width_validation['errors'][0]['message'] ?? '' ), 'producer-supported keys' ), 'v1-graph-rejects-v2-width-vocabulary' );
	$v1_depth_16 = $topology_form;
	$v1_depth_16['forms'][0]['layout_graph']['limits']['depth'] = 16;
	$v1_depth_16_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $v1_depth_16 );
	$assert( empty( $v1_depth_16_validation['forms'] ) && str_contains( (string) ( $v1_depth_16_validation['errors'][0]['message'] ?? '' ), 'exact versioned depth' ), 'v1-graph-rejects-v2-depth-limit' );
	$v2_depth_8 = $deep_width_form;
	$v2_depth_8['layout_graph']['limits']['depth'] = 8;
	$v2_depth_8_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $v2_depth_8 ) ) );
	$assert( empty( $v2_depth_8_validation['forms'] ) && str_contains( (string) ( $v2_depth_8_validation['errors'][0]['message'] ?? '' ), 'exact versioned depth' ), 'v2-graph-rejects-v1-depth-limit' );
	$unproven_table_form = $deep_width_form;
	$unproven_table_form['layout_graph']['nodes'][13]['provenance'] = array();
	$unproven_table_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unproven_table_form ) ) );
	$unproven_table_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unproven_table_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$unproven_reasons = array_column( $unproven_table_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( 'skipped' === ( $unproven_table_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $unproven_reasons, true ), 'unproven-table-semantics-remain-gated', wp_json_encode( $unproven_table_row ) );
	$labelled_width_form = $deep_width_form;
	$labelled_width_form['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$labelled_width_form['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_width_form['layout_graph']['nodes'][1]['source']['tag'] = 'fieldset';
	$labelled_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $labelled_width_form ) ) );
	$labelled_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$labelled_width_reasons = array_column( $labelled_width_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( 'skipped' === ( $labelled_width_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $labelled_width_reasons, true ) && 3 === substr_count( (string) ( $labelled_width_row['block_markup'] ?? '' ), '"width":33.333' ), 'percentage-width-proof-does-not-accept-labelled-fieldset-semantics', wp_json_encode( $labelled_width_row ) );
	$deep_topology_form = $topology_form;
	$deep_nodes         = array();
	$parent             = null;
	for ( $depth = 0; $depth < 9; ++$depth ) {
		$id           = 'wrapper-deep-' . $depth;
		$deep_nodes[] = array( 'id' => $id, 'kind' => 'wrapper', 'parent' => $parent, 'order' => 0, 'depth' => $depth, 'tag' => 'div' );
		$parent       = $id;
	}
	$deep_nodes[] = array( 'id' => 'control-deep-0', 'kind' => 'control', 'parent' => $parent, 'order' => 0, 'depth' => 9, 'control' => 0 );
	for ( $control = 1; $control < 4; ++$control ) {
		$deep_nodes[] = array( 'id' => 'control-deep-' . $control, 'kind' => 'control', 'parent' => null, 'order' => $control, 'depth' => 0, 'control' => $control );
	}
	$deep_topology_form['forms'][0]['control_topology'] = array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'nodes' => $deep_nodes, 'truncated' => false );
	$validated_deep_topology = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $deep_topology_form );
	$deep_topology_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_deep_topology['forms'] ?? array() ) );
	$assert( empty( $validated_deep_topology['errors'] ) && 1 === count( $deep_topology_seed['forms'] ?? array() ), 'depth-nine-topology-validates-and-materializes' );
	$overdeep_topology_form = $deep_topology_form;
	$overdeep_topology_form['forms'][0]['control_topology']['max_depth'] = 17;
	$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $overdeep_topology_form )['errors'] ), 'topology-depth-above-supported-bound-rejects' );
	$provider_map = $topology_seed['forms'][0]['provider_layout_target_map'] ?? array();
	$assert( 'generic/provider-layout-target-map/v1' === ( $provider_map['schema'] ?? '' ) && array() === ( $provider_map['targets'] ?? null ), 'provider-layout-map-omits-flattened-wrapper-targets' );
	$class_owned_form = $validated_topology['forms'][0];
	$class_owned_form['layout_graph']['nodes'][] = array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'field' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'column' ), 'provenance' => array( array( 'selector' => '.field' ) ) );
	$class_owned_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $class_owned_form ) ) );
	$class_owned_losses = $class_owned_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$class_owned_markup = (string) ( $class_owned_seed['forms'][0]['block_markup'] ?? '' );
	$assert( ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $class_owned_losses, 'reason_code' ), true ) && str_contains( $class_owned_markup, 'ssi-source-wrapper-1\u002d\u002dfield' ), 'class-owned-single-field-layout-projects-with-provider-suffixed-wrapper-hook', $class_owned_markup );
	$classless_owned_form = $class_owned_form;
	$classless_owned_form['control_topology']['nodes'][1]['tag'] = 'div';
	$classless_owned_form['control_topology']['nodes'][1]['class'] = '';
	$classless_owned_form['layout_graph']['nodes'][1]['source']['classes'] = array();
	$classless_owned_form['layout_graph']['nodes'][1]['provenance'][0] = array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'c', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display', 'flex-direction' ) );
	$classless_owned_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $classless_owned_form ) ) );
	$classless_owned_losses = $classless_owned_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$classless_owned_markup = (string) ( $classless_owned_seed['forms'][0]['block_markup'] ?? '' );
	$assert( ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $classless_owned_losses, 'reason_code' ), true ) && str_contains( $classless_owned_markup, 'ssi-source-wrapper-1\u002d\u002dssi-node-' ), 'proven-classless-single-field-layout-projects-through-a-generated-wrapper-hook', $classless_owned_markup );
	$projected_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper--field-wrap"><input class="ssi-source-wrapper--field source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="field"><input class="source-input"></div></div>' === $projected_wrapper, 'provider-runtime-rebuilds-source-wrapper-inside-field-shell', $projected_wrapper );
	$layout_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-node-123456789abc-wrap ssi-source-wrapper--field-wrap"><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="field ssi-node-123456789abc-wrap"><input class="source-input"></div></div>' === $layout_wrapper, 'provider-runtime-places-source layout hooks on the restored source wrapper', $layout_wrapper );
	$layered_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper-6--carrier-wrap ssi-source-wrapper-8--input-shell-wrap"><label>Name</label><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><label>Name</label><div class="carrier"><div class="input-shell"><input class="source-input"></div></div></div>' === $layered_wrapper, 'provider-runtime-removes-provider-suffix-before-restoring-ordered-wrapper-carriers', $layered_wrapper );
	$projected_controls = implode( '', array_map( array( Static_Site_Importer_Form_Seeder::class, 'project_provider_wrapper_classes' ), array( '<div class="grunion-field-text-wrap ssi-source-wrapper-2--control-shell-wrap"><input class="control-hook"></div>', '<div class="grunion-field-textarea-wrap ssi-source-wrapper-2--control-shell-wrap"><textarea class="control-hook"></textarea></div>', '<div class="grunion-field-select-wrap ssi-source-wrapper-2--control-shell-wrap"><select class="control-hook"><option>One</option></select></div>' ) ) );
	$assert( 3 === substr_count( $projected_controls, '<div class="control-shell">' ) && 3 === substr_count( $projected_controls, 'class="control-hook"' ) && ! str_contains( $projected_controls, 'ssi-source-wrapper-' ), 'wrapper-projection-preserves-input-textarea-and-select-control-relationships', $projected_controls );
	$phone_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_wrapper, '<div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div>' ) && str_contains( $phone_wrapper, '<div class="control-shell"><input class="jetpack-field__input-element" type="tel"></div><input type="hidden" name="full-phone">' ), 'phone-wrapper-restoration-targets-value-control-without-wrapping-country-search-or-hidden-value', $phone_wrapper );
	$assert( 1 === substr_count( $phone_wrapper, 'class="control-shell"' ) && $phone_wrapper === Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $phone_wrapper ), 'phone-wrapper-restoration-is-single-target-and-idempotent' );
	$prefix_projection = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-0--input-shell-wrap ssi-source-wrapper-prefix-1--country-wrapper-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button data-wp-on--click="actions.toggle">Country</button><input class="jetpack-combobox-search" type="search"><template data-wp-each="context.countries"><span>Country</span></template></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="value"></div></div>' );
	$assert( str_contains( $prefix_projection, '<div class="country-wrapper"><div class="jetpack-field__input-prefix">' ) && str_contains( $prefix_projection, '<div class="input-shell"><input class="jetpack-field__input-element" type="tel"></div>' ) && str_contains( $prefix_projection, 'data-wp-on--click="actions.toggle"' ) && str_contains( $prefix_projection, '<template data-wp-each="context.countries"><span>Country</span></template>' ) && $prefix_projection === Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $prefix_projection ), 'auxiliary-ownership-restores-prefix-and-primary-in-separate-branches-with-runtime-bindings-intact', $prefix_projection );
	$phone_composite = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell ssi-node-222222222222-destination-primary ssi-node-333333333333-destination-carrier"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_composite, 'jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell' ) && str_contains( $phone_composite, '<div class="control-shell ssi-node-333333333333-destination-carrier"><input class="jetpack-field__input-element ssi-node-222222222222-destination-primary" type="tel">' ) && ! str_contains( $phone_composite, 'jetpack-combobox-search ssi-node-' ), 'phone-composite-projection-moves-adapter-declared-primary-and-carrier-hooks-to-the-actual-tel-control', $phone_composite );
	$unmarked_provider_shell = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' );
	$shared_projection = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-shell-0--shared-border-wrap ssi-source-wrapper-prefix-1--prefix-box-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button>Country</button></div><input type="tel"></div></div>' );
	$shared_document = new DOMDocument();
	$shared_document->loadHTML( $shared_projection );
	$shared_xpath = new DOMXPath( $shared_document );
	$assert( 1 === $shared_xpath->query( '//div[@class="shared-border"]/div[@class="jetpack-field__input-phone-wrapper"]/input[@type="tel"]' )->length && 1 === $shared_xpath->query( '//div[@class="shared-border"]//div[@class="prefix-box"]//button' )->length, 'shared-border-wraps-both-prefix-and-value-in-the-rendered-provider-tree' );
	$assert( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' === $unmarked_provider_shell, 'wrapper-projection-does-not-rewrite-unmarked-provider-shells', $unmarked_provider_shell );
	$projected_submit = Static_Site_Importer_Form_Seeder::project_provider_submit_presentation(
		'<div class="wp-block-button ssi-source-submit--source-submit"><button class="wp-block-button__link">Send</button></div>',
		array( 'attrs' => array( 'className' => 'wp-block-button ssi-source-submit--source-submit' ) )
	);
	$assert( '<div class="wp-block-button" style="min-height:0"><button class="wp-block-button__link source-submit" style="min-height:0">Send</button></div>' === $projected_submit, 'provider-runtime-projects-submit-classes-and-neutralizes-provider-minimum-height-on-the-button-and-its-wrapper', $projected_submit );
	$unproven_class_form = $class_owned_form;
	$unproven_class_form['layout_graph']['nodes'][1]['provenance'] = array();
	$unproven_class_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unproven_class_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $unproven_class_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'class-projection-alone-does-not-claim-layout-equivalence' );
	$structural_selector_form = $class_owned_form;
	$structural_selector_form['layout_graph']['nodes'][1]['provenance'][0]['selector'] = '.row-2 > .field';
	$structural_selector_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $structural_selector_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $structural_selector_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'ancestor-dependent-class-selector-does-not-survive-wrapper-flattening' );
	$root_graph = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'form' ) ) );
	$root_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'form', 'selector' => '.ssi-form-123456789abc > form.jetpack-contact-form__form, .ssi-form-123456789abc:not(:has(> form.jetpack-contact-form__form))', 'capabilities' => array( 'container_layout', 'responsive_layout' ) ) ) );
	$root_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $root_map );
	$assert( str_contains( $root_overlay['css'], '.ssi-form-123456789abc > form.jetpack-contact-form__form, .ssi-form-123456789abc:not(:has(> form.jetpack-contact-form__form)){display:flex;flex-direction:row;gap:1rem}' ) && str_contains( $root_overlay['css'], '.ssi-form-123456789abc{position:relative;z-index:1;pointer-events:auto}' ) && 'provider_selector_transposition' === ( $root_overlay['operations'][0]['strategy'] ?? '' ) && 'provider_interaction_carrier' === ( $root_overlay['operations'][1]['strategy'] ?? '' ), 'provider-layout-root-targets-native-jetpack-form-with-an-interaction-carrier' );
	$calc_graph   = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'column', 'gap' => 'calc(32 * 1px)' ), 'form' ) ) );
	$calc_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $calc_graph, $root_map );
	$assert( str_contains( $calc_overlay['css'], 'gap:calc(32 * 1px)' ) && empty( $calc_overlay['losses'] ), 'authored-arithmetic-row-gap-reaches-the-provider-form-instead-of-the-runtime-default', wp_json_encode( $calc_overlay ) );
	$unbalanced_calc_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'gap' => 'calc(32 * 1px' ), 'form' ) ) ), $root_map );
	$assert( '' === $unbalanced_calc_overlay['css'] && 'unsafe_layout_value' === ( $unbalanced_calc_overlay['losses'][0]['reason_code'] ?? '' ), 'unbalanced-arithmetic-value-is-refused' );
	$unsafe_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'display' => 'url(https://example.test/x)' ), 'form' ) ) ), $root_map );
	$assert( '' === $unsafe_overlay['css'] && 'unsafe_layout_value' === ( $unsafe_overlay['losses'][0]['reason_code'] ?? '' ), 'provider-layout-overlay-rejects-unsafe-values' );
	$bad_map = $root_map; $bad_map['targets'][0]['selector'] = 'body .anything';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $bad_map, $root_graph )['error'] ), 'provider-layout-overlay-rejects-arbitrary-selectors' );
	$presentation_graph_fixture = array( 'controls' => array( array( 'index' => 0, 'control' => array( 'styles' => array( 'background_color' => '#fff', 'padding' => '8px', 'font_size' => '16px' ) ) ) ) );
	$destination_fixture = array( 'index' => 0, 'destinations' => array( array( 'role' => 'control', 'selector' => '.ssi-form-123456789abc .ssi-node-111111111111', 'properties' => array( 'background_color', 'padding' ), 'aliases' => array( 'background_color' => '--provider-input-background' ) ), array( 'role' => 'control', 'selector' => '.ssi-form-123456789abc .ssi-node-222222222222', 'properties' => array( 'font_size' ), 'resets' => array( 'flex' => '1 1 0', 'min-width' => '0' ) ) ) );
	$jetpack_destination_map = $root_map; $jetpack_destination_map['presentation_targets'] = array( $destination_fixture );
	$synthetic_destination_map = $jetpack_destination_map; $synthetic_destination_map['provider'] = 'synthetic';
	$jetpack_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $jetpack_destination_map, $presentation_graph_fixture );
	$synthetic_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $synthetic_destination_map, $presentation_graph_fixture );
	$assert( empty( $jetpack_destination_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $jetpack_destination_overlay['overlay'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $synthetic_destination_overlay['overlay'] ), 'partitioned-destinations-account-for-all-source-properties-and-pass-output-admission' );
	$editor_overlay = array( 'schema' => Static_Site_Importer_Provider_Layout_Overlay::OVERLAY_SCHEMA, 'css' => "/* Static Site Importer provider layout overlay: abcdef123456 */\n.ssi-form-123456789abc{display:flex}\n", 'editor_css' => "/* Static Site Importer editor control chrome: abcdef123456 */\n.editor-styles-wrapper .ssi-form-123456789abc .ssi-node-123456789abc{background:#fff}\n", 'sha256' => '', 'bytes' => 0 );
	$editor_overlay['sha256'] = hash( 'sha256', $editor_overlay['css'] ); $editor_overlay['bytes'] = strlen( $editor_overlay['css'] );
	$editor_overlay['editor_sha256'] = hash( 'sha256', $editor_overlay['editor_css'] ); $editor_overlay['editor_bytes'] = strlen( $editor_overlay['editor_css'] );
	$editor_overlay_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/editor-overlay', 'Editor overlay', '', array(), array(), array( $editor_overlay ), array( '/tmp/editor-overlay/style.css' => '/* base */', '/tmp/editor-overlay/assets/css/editor-style.css' => '/* editor */' ) );
	$malicious_editor_overlay = $editor_overlay; $malicious_editor_overlay['editor_css'] = "/* Static Site Importer editor control chrome: abcdef123456 */\n.editor-styles-wrapper body{background:#fff}\n"; $malicious_editor_overlay['editor_sha256'] = hash( 'sha256', $malicious_editor_overlay['editor_css'] ); $malicious_editor_overlay['editor_bytes'] = strlen( $malicious_editor_overlay['editor_css'] );
	$invalid_editor_hash = $editor_overlay; $invalid_editor_hash['editor_bytes']++;
	$oversized_editor_overlay = $editor_overlay; $oversized_editor_overlay['editor_css'] = str_repeat( 'a', 32769 ); $oversized_editor_overlay['editor_sha256'] = hash( 'sha256', $oversized_editor_overlay['editor_css'] ); $oversized_editor_overlay['editor_bytes'] = strlen( $oversized_editor_overlay['editor_css'] );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $editor_overlay ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $malicious_editor_overlay ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $invalid_editor_hash ) && null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $oversized_editor_overlay ) && str_contains( $editor_overlay_writes['/tmp/editor-overlay/style.css'], 'provider layout overlay' ) && ! str_contains( $editor_overlay_writes['/tmp/editor-overlay/style.css'], 'editor control chrome' ) && str_contains( $editor_overlay_writes['/tmp/editor-overlay/assets/css/editor-style.css'], 'editor control chrome' ), 'editor-only overlays require bounded independently hashed admitted rules and survive existing stylesheet writes without changing frontend CSS' );
	$incomplete_destination_map = $jetpack_destination_map;
	array_pop( $incomplete_destination_map['presentation_targets'][0]['destinations'] );
	$incomplete_destination_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $incomplete_destination_map, $presentation_graph_fixture );
	$assert( in_array( 'provider_structure_mismatch', array_column( $incomplete_destination_overlay['losses'], 'reason_code' ), true ), 'destination-property-partition-cannot-silently-drop-source-presentation' );
	$assert( str_contains( $jetpack_destination_overlay['css'], 'background-color:#fff;--provider-input-background:#fff;padding:8px' ) && str_contains( $jetpack_destination_overlay['css'], 'font-size:16px;flex:1 1 0;min-width:0' ) && $jetpack_destination_overlay['css'] === str_replace( 'provider: jetpack', 'provider: synthetic', $synthetic_destination_overlay['css'] ), 'jetpack-and-synthetic-destination-map-fixtures-use-the-same-generic-projector', $jetpack_destination_overlay['css'] );
	$malformed_destination_map = $jetpack_destination_map; $malformed_destination_map['presentation_targets'][0]['destinations'][0]['aliases']['background_color'] = 'background:url(x)';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $malformed_destination_map, $root_graph )['error'] ), 'provider-layout-overlay-rejects-malformed-declarative-destination-maps' );
	$responsive_root = $root_graph;
	$responsive_root['variants'] = array( array( 'node' => 'form', 'condition' => array( 'kind' => 'media', 'query' => '(min-width: 48rem)' ), 'layout_patch' => array( 'direction' => 'column' ) ) );
	$assert( str_contains( Static_Site_Importer_Provider_Layout_Overlay::compile( $responsive_root, $root_map )['css'], '@media (min-width: 48rem)' ), 'provider-layout-overlay-supports-bounded-media-condition' );
	$root_item = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'order' => 1 ), 'form' ) ) ), $root_map );
	$assert( 'direct_child_relationship_unrepresentable' === ( $root_item['losses'][0]['reason_code'] ?? '' ), 'jetpack-form-root-does-not-claim-direct-child-layout' );
	$item_graph = $layout_graph( array( $layout_node( 'control-0', array( 'order' => 1, 'flex_grow' => 1 ), 'input' ) ) );
	$item_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'control-0', 'selector' => '.ssi-form-123456789abc .ssi-node-123456789abc', 'capabilities' => array( 'item_layout', 'direct_child_layout' ) ) ) );
	$item_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( str_contains( $item_overlay['css'], 'order:1;flex-grow:1' ) && empty( $item_overlay['losses'] ), 'provider-layout-emits-item-properties-only-for-proven-direct-child-targets' );
	$justify_self_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'control-0', array( 'justify_self' => 'center' ), 'input' ) ) ), $item_map );
	$assert( str_contains( $justify_self_overlay['css'], 'justify-self:center' ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $justify_self_overlay['overlay'] ), 'provider-layout-validates-compiled-justify-self-css' );
	$item_map['targets'][0]['capabilities'] = array( 'item_layout' );
	$item_without_direct_child = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( '' === $item_without_direct_child['css'] && array( 'direct_child_relationship_unrepresentable', 'direct_child_relationship_unrepresentable' ) === array_column( $item_without_direct_child['losses'], 'reason_code' ), 'provider-layout-does-not-accept-inert-item-layout-capability' );
	$booking = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.booking', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request booking' ) ) ) ) ) );
	$booking_row = $booking['forms'][0] ?? array();
	$assert( 'Request booking' === ( $booking_row['submit_text'] ?? '' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '>Request booking</button>' ), 'canonical-control-text-preserves-request-booking-submit-label' );
	$assert( str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"label":"Guests"' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"step":"0.5"' ) && array( 'min', 'max' ) === array_column( $booking_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-source-attributes-preserve-supported-step-and-report-min-max-losses' );
	$assert( false === ( $booking_row['runtime_mapped'] ?? true ) && array( 'min', 'max' ) === array_column( $booking_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ) && 2 === ( $booking_row['unaccepted_receipt_loss_count'] ?? 0 ), 'number-unsupported-attributes-gate-form-runtime-acceptance' );
	$assert( 'skipped' === ( $booking_row['status'] ?? '' ) && 0 === ( $booking['counts']['error'] ?? -1 ), 'gated-form-is-a-provider-decline-not-a-materialization-error' );
	$height_controls = array();
	$height_html     = '<form class="many-heights">';
	for ( $height_index = 1; $height_index <= 17; ++$height_index ) {
		$height_html       .= '<textarea name="message-' . $height_index . '" style="height:' . $height_index . 'px"></textarea>';
		$height_controls[] = array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message-' . $height_index );
	}
	$height_html       .= '<button type="submit">Send</button></form>';
	$height_controls[] = array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' );
	$height_entity = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/many-heights.html',
			'selector'    => 'form.many-heights',
			'form'        => array(),
			'controls'    => $height_controls,
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/many-heights.html', 'search_block_markup' => $height_html, 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $height_entity ) ) )['forms'][0] ?? array();
	$assert( false === ( $height_row['runtime_mapped'] ?? true ) && 'textarea_height_omitted' === ( $height_row['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && 1 === ( $height_row['unaccepted_receipt_loss_count'] ?? 0 ), 'omitted-textarea-heights-gate-form-runtime-acceptance' );
	$newsletter = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.newsletter', 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Subscribe to newsletter' ) ) ) ) ) );
	$assert( 'Subscribe to newsletter' === ( $newsletter['forms'][0]['submit_text'] ?? '' ), 'canonical-control-text-preserves-newsletter-submit-label' );
	if ( function_exists( 'parse_blocks' ) ) {
		$parsed_markup = parse_blocks( $topology_markup );
		$parsed_names = array();
		$walk_parsed_markup = static function ( array $parsed ) use ( &$walk_parsed_markup, &$parsed_names ): void {
			foreach ( $parsed as $block ) {
				if ( is_array( $block ) ) {
					$parsed_names[] = $block['blockName'] ?? null;
					$walk_parsed_markup( $block['innerBlocks'] ?? array() );
				}
			}
		};
		$walk_parsed_markup( $parsed_markup );
		$assert( array( 'jetpack/contact-form', 'jetpack/field-text', 'jetpack/label', 'jetpack/input', 'jetpack/field-email', 'jetpack/label', 'jetpack/input', 'jetpack/field-textarea', 'jetpack/label', 'jetpack/input', 'core/button' ) === $parsed_names, 'wordpress-parse-blocks-preserves-canonical-provider-grammar', wp_json_encode( $parsed_names ) );
	}
	$unsafe_graph = $topology_form;
	$unsafe_graph['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'grid', 'direction' => 'none', 'item_placement' => array( 'column' => 1 ) );
	$unsafe_graph_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_graph );
	$unsafe_graph_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_graph_validation['forms'] ) );
	$unsafe_losses = $unsafe_graph_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$assert( 'applied' === ( $unsafe_graph_seed['forms'][0]['computed_layout_receipt']['status'] ?? '' ) && array( 'provider_wrapper_layout_unrepresentable', 'unsupported_item_placement' ) === array_column( $unsafe_losses, 'reason_code' ), 'computed-layout-grid-placement-is-gated-despite-represented-field-wrappers', wp_json_encode( $unsafe_graph_seed['forms'][0]['computed_layout_receipt'] ?? array() ) );
	$unknown_layout_key = $topology_form;
	$unknown_layout_key['forms'][0]['layout_graph']['nodes'][0]['layout']['alignment'] = 'center';
	$unknown_layout_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_layout_key );
	$assert( empty( $unknown_layout_validation['forms'] ) && str_contains( (string) ( $unknown_layout_validation['errors'][0]['message'] ?? '' ), 'producer-supported keys' ), 'computed-layout-rejects-alignment-alias-at-runtime-boundary' );
	$unknown_layout_key['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'flex', 'direction' => 'row', 'justify' => 'center' );
	$unknown_layout_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_layout_key );
	$assert( empty( $unknown_layout_validation['forms'] ) && str_contains( (string) ( $unknown_layout_validation['errors'][0]['message'] ?? '' ), 'producer-supported keys' ), 'computed-layout-rejects-justify-alias-at-runtime-boundary' );
	$responsive_flex = $topology_form;
	$responsive_condition = array( 'kind' => 'media', 'query' => '(min-width: 48rem)' );
	$responsive_flex['forms'][0]['layout_graph']['variants'] = array( array( 'node' => 'wrapper-0', 'condition' => $responsive_condition, 'layout_patch' => array( 'direction' => 'column', 'wrap' => 'wrap' ), 'precedence' => array( 'flex-direction' => array( 'source_order' => 4, 'specificity' => 10, 'important' => false ), 'flex-wrap' => array( 'source_order' => 4, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'b', 64 ), 'selector' => '.row-2', 'condition' => $responsive_condition, 'properties' => array( 'flex-direction', 'flex-wrap' ) ) ) ) );
	$responsive_flex_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $responsive_flex );
	$assert( empty( $responsive_flex_validation['errors'] ) && array( 'flex-direction', 'flex-wrap' ) === array_keys( $responsive_flex_validation['forms'][0]['layout_graph']['variants'][0]['precedence'] ?? array() ), 'computed-layout-accepts-responsive-flex-css-provenance-property-names' );
	$unknown_variant_key = $responsive_flex;
	$unknown_variant_key['forms'][0]['layout_graph']['variants'][0]['precedence']['unknown-property'] = array( 'source_order' => 4, 'specificity' => 10, 'important' => false );
	$unknown_variant_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unknown_variant_key );
	$assert( empty( $unknown_variant_validation['forms'] ) && str_contains( (string) ( $unknown_variant_validation['errors'][0]['message'] ?? '' ), 'precedence' ), 'computed-layout-rejects-unknown-producer-precedence-property' );

	// --- Computed layout maps only complete core/group flex facts --------------
	$layout_blocks = array( array( 'name' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'topologyId' => 'wrapper-0' ) );
	$complete_layout = array( 'display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '1rem', 'align_items' => 'center', 'justify_content' => 'space-between' );
	$complete_result = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', $complete_layout ) ) ) ), $layout_blocks );
	$complete_attrs = $complete_result['blocks'][0]['attrs'];
	$assert( array( 'type' => 'flex', 'orientation' => 'horizontal', 'flexWrap' => 'wrap', 'verticalAlignment' => 'center', 'justifyContent' => 'space-between' ) === $complete_attrs['layout'] && '1rem' === $complete_attrs['style']['spacing']['blockGap'], 'computed-layout-maps-wrap-gap-alignment-and-justification' );
	$nowrap_result = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', array( 'display' => 'flex', 'direction' => 'column', 'wrap' => 'nowrap' ) ) ) ) ), $layout_blocks );
	$assert( 'nowrap' === $nowrap_result['blocks'][0]['attrs']['layout']['flexWrap'] && 'vertical' === $nowrap_result['blocks'][0]['attrs']['layout']['orientation'], 'computed-layout-maps-source-nowrap' );
	$conflicting_gaps = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'wrapper-0', array( 'display' => 'flex', 'direction' => 'row', 'row_gap' => '1rem', 'column_gap' => '2rem' ) ) ) ) ), $layout_blocks );
	$assert( 'conflicting_axis_gaps' === ( $conflicting_gaps['receipt']['losses'][0]['reason_code'] ?? '' ), 'computed-layout-defers-conflicting-gaps' );
	$form_flex = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row' ), 'form' ) ) ) ), $layout_blocks );
	$control_item = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( array( $layout_node( 'control-0', array( 'display' => 'flex', 'direction' => 'row', 'order' => 1 ), 'input' ) ) ) ), $layout_blocks );
	$assert( 'layout_target_unrepresentable' === ( $form_flex['receipt']['losses'][0]['reason_code'] ?? '' ) && 'layout_target_unrepresentable' === ( $control_item['receipt']['losses'][0]['reason_code'] ?? '' ), 'computed-layout-defers-form-and-control-item-facts' );
	$receipt_nodes = array();
	for ( $receipt_index = 0; $receipt_index < 33; ++$receipt_index ) $receipt_nodes[] = $layout_node( 'control-' . $receipt_index, array( 'display' => 'flex', 'direction' => 'row' ), 'input' );
	$capped_receipt = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'layout_graph' => $layout_graph( $receipt_nodes ) ), $layout_blocks )['receipt'];
	$assert( 32 === $capped_receipt['loss_count'] && 33 === $capped_receipt['losses_total'] && true === $capped_receipt['truncated'], 'computed-layout-receipt-caps-entries-at-32' );
	$gate_losses = array_fill( 0, 33, array( 'dimension' => 'topology', 'reason_code' => 'provider_wrapper_layout_unrepresentable' ) );
	$gate_overflow_receipt = Static_Site_Importer_Computed_Layout_Strategy::apply( array( 'topology_losses' => $gate_losses ), array() )['receipt'];
	$assert( 1 === ( $gate_overflow_receipt['gate_required_loss_overflow_count'] ?? 0 ) && 64 === strlen( (string) ( $gate_overflow_receipt['gate_required_loss_overflow_hash'] ?? '' ) ), 'computed-layout-records-gate-required-overflow-before-seeder-appends' );
	$overflow_nodes = array();
	for ( $receipt_index = 0; $receipt_index < 33; ++$receipt_index ) $overflow_nodes[] = $layout_node( 'wrapper-' . $receipt_index, array( 'display' => 'flex', 'direction' => 'row' ) );
	$overflow_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.overflow', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'label' => 'Guests', 'min' => '1' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ), 'layout_graph' => $layout_graph( $overflow_nodes ) ) ) ) );
	$overflow_receipt = $overflow_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$assert( 34 === ( $overflow_receipt['losses_total'] ?? 0 ) && 32 === count( $overflow_receipt['losses'] ?? array() ) && true === ( $overflow_receipt['truncated'] ?? false ) && 1 === ( $overflow_receipt['gate_required_loss_overflow_count'] ?? 0 ) && 64 === strlen( (string) ( $overflow_receipt['gate_required_loss_overflow_hash'] ?? '' ) ) && in_array( 'unsupported_control_attribute', array_column( $overflow_receipt['losses'] ?? array(), 'reason_code' ), true ), 'seeder-retains-gate-required-loss-while-preserving-overflow-totals', wp_json_encode( $overflow_receipt ) );
	$overflow_row = $overflow_seed['forms'][0] ?? array();
	$assert( false === ( $overflow_row['runtime_mapped'] ?? true ) && 'form_receipt_gate_loss_overflow' === ( $overflow_row['form_receipt_unaccepted_losses'][1]['reason_code'] ?? '' ), 'gate-required-receipt-overflow-fails-runtime-acceptance', wp_json_encode( $overflow_row ) );
	$variant_only = $topology_form;
	$variant_only['forms'][0]['layout_graph']['nodes'] = array( $layout_node( 'wrapper-0', array(), 'section' ) );
	$variant_only['forms'][0]['layout_graph']['variants'] = array();
	for ( $variant_index = 0; $variant_index < 256; ++$variant_index ) {
		$condition = array( 'kind' => 'media', 'query' => '(min-width: ' . $variant_index . 'px)' );
		$variant_only['forms'][0]['layout_graph']['variants'][] = array( 'node' => 'wrapper-0', 'condition' => $condition, 'layout_patch' => array( 'display' => 'flex' ), 'precedence' => array( 'display' => array( 'source_order' => $variant_index, 'specificity' => 10, 'important' => false ) ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.row-2', 'condition' => $condition, 'properties' => array( 'display' ) ) ) );
	}
	$variant_only_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $variant_only );
	$variant_only_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $variant_only_validation['forms'] ) );
	$variant_only_receipt = $variant_only_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$variant_only_loss = $variant_only_receipt['losses'][0] ?? array();
	$assert( empty( $variant_only_validation['errors'] ) && 256 === count( $variant_only_validation['forms'][0]['layout_graph']['variants'] ?? array() ) && 2 === ( $variant_only_receipt['losses_total'] ?? 0 ) && 'provider_wrapper_layout_unrepresentable' === ( $variant_only_loss['reason_code'] ?? '' ) && 'responsive_layout_ownership' === ( $variant_only_receipt['losses'][1]['reason_code'] ?? '' ) && 256 === ( $variant_only_receipt['losses'][1]['variant_count'] ?? 0 ) && 64 === strlen( (string) ( $variant_only_receipt['losses'][1]['variant_hash'] ?? '' ) ), 'computed-layout-variant-only-retains-bounded-provider-and-responsive-losses', wp_json_encode( $variant_only_receipt ) );
	$semantic_topology = $topology_form;
	$semantic_topology['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$semantic_topology['forms'][0]['control_topology']['nodes'][1]['tag'] = 'label';
	$semantic_topology['forms'][0]['layout_graph']['nodes'] = array();
	$semantic_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $semantic_topology );
	$semantic_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $semantic_validation['forms'] ) );
	$semantic_markup = (string) ( $semantic_seed['forms'][0]['block_markup'] ?? '' );
	$semantic_losses = $semantic_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$assert( 2 === count( $semantic_losses ) && 'semantic' === ( $semantic_losses[0]['dimension'] ?? '' ) && ! str_contains( $semantic_markup, '<fieldset' ) && ! str_contains( $semantic_markup, '<label' ), 'semantic-wrapper-losses-cover-topology-wrappers-without-layout-graph-nodes' );
	$neutral_span = $topology_form;
	$neutral_span['forms'][0]['control_topology']['nodes'][1]['tag'] = 'span';
	$neutral_span_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $neutral_span );
	$neutral_span_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $neutral_span_validation['forms'] ?? array() ) );
	$assert( empty( $neutral_span_validation['errors'] ) && 'mapped' === ( $neutral_span_seed['forms'][0]['status'] ?? '' ) && ! in_array( 'unsupported_semantic_wrapper', array_column( $neutral_span_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'neutral-span-wrapper-flattens-without-semantic-loss', wp_json_encode( array( 'validation' => $neutral_span_validation, 'seed' => $neutral_span_seed ) ) );
	$plain_root_fieldset = $topology_form;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'plain_group';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['class'] = 'source-root';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['order'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][6]['depth'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['order'] = 3;
	$plain_root_fieldset['forms'][0]['layout_graph']['nodes'] = array(
		$layout_node( 'wrapper-0', array(), 'fieldset' ),
	);
	$plain_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $plain_root_fieldset );
	$plain_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $plain_root_fieldset_validation['forms'] ?? array() ) );
	$plain_root_fieldset_markup = (string) ( $plain_root_fieldset_seed['forms'][0]['block_markup'] ?? '' );
	$assert( empty( $plain_root_fieldset_validation['errors'] ) && 'mapped' === ( $plain_root_fieldset_seed['forms'][0]['status'] ?? '' ) && empty( $plain_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $plain_root_fieldset_markup, 'ssi-source-root-fieldset\u002d\u002dsource-root' ) && in_array( 'provider_plain_root_fieldset_projection', array_column( $plain_root_fieldset_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'provider-form-transports-proven-plain-root-fieldset-grouping', wp_json_encode( array( 'validation' => $plain_root_fieldset_validation, 'seed' => $plain_root_fieldset_seed ) ) );
	$plain_root_runtime = Static_Site_Importer_Form_Seeder::project_provider_plain_root_fieldset(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form" action="/submit"><div class="wp-block-jetpack-contact-form ssi-source-root-fieldset ssi-source-root-fieldset--source-root"><div class="grunion-field-text-wrap"><label>Name</label><input name="name"><svg><path d="M0 0"></path></svg></div><div class="wp-block-button"><button type="submit">Send</button></div></div><input type="hidden" name="_wpnonce" value="nonce"></form></div>',
		array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) )
	);
	$assert( str_contains( $plain_root_runtime, '<fieldset class="source-root"><div class="wp-block-jetpack-contact-form"><div class="grunion-field-text-wrap"><label>Name</label><input name="name"><svg><path d="M0 0"></path></svg></div><div class="wp-block-button"><button type="submit">Send</button></div></div></fieldset><input type="hidden" name="_wpnonce" value="nonce">' ) && ! str_contains( $plain_root_runtime, 'ssi-source-root-fieldset' ) && 1 === substr_count( $plain_root_runtime, '<form ' ) && $plain_root_runtime === Static_Site_Importer_Form_Seeder::project_provider_plain_root_fieldset( $plain_root_runtime, array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) ) ), 'provider-runtime-wraps-only-jetpack-native-field-list-and-preserves-handler-and-svg-markup', $plain_root_runtime );
	$plain_root_min_width_runtime = Static_Site_Importer_Form_Seeder::project_provider_plain_root_fieldset(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form ssi-source-root-fieldset ssi-source-root-fieldset--source-root"><div class="grunion-field-text-wrap"><input></div></div><input type="hidden" name="_wpnonce"></form></div>',
		array( 'attrs' => array( 'className' => 'ssi-source-root-fieldset ssi-source-root-fieldset--source-root' ) )
	);
	$assert( str_contains( $plain_root_min_width_runtime, '<fieldset class="source-root"><div class="wp-block-jetpack-contact-form">' ) && ! str_contains( $plain_root_min_width_runtime, 'min-width' ) && ! str_contains( $plain_root_min_width_runtime, '264px' ), 'provider-runtime-leaves-authored-fieldset-min-width-rules-to-the-source-class', $plain_root_min_width_runtime );
	$unmarked_plain_root_runtime = Static_Site_Importer_Form_Seeder::project_provider_plain_root_fieldset( '<div class="wp-block-jetpack-contact-form"><form class="jetpack-contact-form__form"><div class="grunion-field-text-wrap"><input></div></form></div>', array( 'attrs' => array() ) );
	$assert( ! str_contains( $unmarked_plain_root_runtime, '<fieldset' ), 'provider-runtime-does-not-affect-forms-without-a-proven-plain-root-fieldset' );
	$partial_plain_root_fieldset = $plain_root_fieldset;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['parent'] = null;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['depth'] = 0;
	$partial_plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['order'] = 1;
	$partial_plain_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $partial_plain_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$nested_plain_root_fieldset = $plain_root_fieldset;
	$nested_plain_root_fieldset['forms'][0]['control_topology']['nodes'][1]['tag'] = 'fieldset';
	$nested_plain_root_fieldset['forms'][0]['control_topology']['nodes'][1]['fieldset_semantics'] = 'plain_group';
	$nested_plain_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $nested_plain_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$disabled_root_fieldset = $plain_root_fieldset;
	$disabled_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'disabled_group';
	$disabled_root_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $disabled_root_fieldset )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'skipped' === ( $partial_plain_root_row['status'] ?? '' ) && 'skipped' === ( $nested_plain_root_row['status'] ?? '' ) && 'skipped' === ( $disabled_root_row['status'] ?? '' ) && ! str_contains( (string) ( $partial_plain_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ) && ! str_contains( (string) ( $nested_plain_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ) && ! str_contains( (string) ( $disabled_root_row['block_markup'] ?? '' ), 'ssi-source-root-fieldset' ), 'partial-nested-and-disabled-fieldsets-remain-loss-gated' );
	$labelled_root_fieldset = $plain_root_fieldset;
	$labelled_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_root_fieldset );
	$labelled_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_root_fieldset_validation['forms'] ?? array() ) );
	$assert( 'skipped' === ( $labelled_root_fieldset_seed['forms'][0]['status'] ?? '' ) && false === ( $labelled_root_fieldset_seed['forms'][0]['runtime_mapped'] ?? true ) && 'unsupported_semantic_wrapper' === ( $labelled_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && ! in_array( 'provider_radio_fieldset_equivalent', array_column( $labelled_root_fieldset_seed['forms'][0]['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'provider-form-declines-labelled-root-fieldset-without-semantic-equivalence', wp_json_encode( array( 'validation' => $labelled_root_fieldset_validation, 'seed' => $labelled_root_fieldset_seed ) ) );
	$labelled_radio_group = array(
		'forms' => array(
			array(
				'selector' => 'form.wixui-form',
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'Chakra Healing Session', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'Custom Healing Session' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'bindings' => array(
					array(
						'schema' => 'generic/block-binding/v1', 'source_path' => 'website/cchfeedback/index.html', 'occurrence' => 1, 'role' => 'form',
						'search_block_markup' => '<form><div id="comp-kf7in602" class="wixui-radio-button-group"><fieldset role="radiogroup" aria-required="true"><legend><div data-testid="groupLabel">What was your treatment?</div></legend><div data-testid="radioGroup"><label><input type="radio" required name="comp-kf7in602" value="Chakra Healing Session"></label><label><input type="radio" name="comp-kf7in602" value="Custom Healing Session"></label></div></fieldset></div></form>',
					),
				),
				'control_topology' => array(
					'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false,
					'nodes' => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'labelled_group' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div' ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'label' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'control' => 0 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'tag' => 'label' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
					),
				),
			),
		),
	);
	$labelled_radio_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_radio_group );
	$labelled_radio_seed       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_radio_validation['forms'] ?? array() ) );
	$labelled_radio_row        = $labelled_radio_seed['forms'][0] ?? array();
	$labelled_radio_markup     = (string) ( $labelled_radio_row['block_markup'] ?? '' );
	$labelled_radio_receipt    = $labelled_radio_row['computed_layout_receipt'] ?? array();
	$assert( empty( $labelled_radio_validation['errors'] ) && 'mapped' === ( $labelled_radio_row['status'] ?? '' ) && true === ( $labelled_radio_row['runtime_mapped'] ?? false ) && 1 === ( $labelled_radio_row['field_count'] ?? 0 ), 'labelled-radio-fieldset-materializes-one-runtime-field', wp_json_encode( array( 'validation' => $labelled_radio_validation, 'seed' => $labelled_radio_seed ) ) );
	$assert( 1 === substr_count( $labelled_radio_markup, '<!-- wp:jetpack/field-radio ' ) && str_contains( $labelled_radio_markup, '<!-- wp:jetpack/label {"label":"What was your treatment?"} /-->' ) && str_contains( $labelled_radio_markup, '"options":["Chakra Healing Session","Custom Healing Session"]' ) && str_contains( $labelled_radio_markup, '"required":true' ), 'labelled-radio-fieldset-serializes-legend-required-state-and-ordered-options', $labelled_radio_markup );
	$assert( 'provider_radio_fieldset_equivalent' === ( $labelled_radio_receipt['operations'][0]['strategy'] ?? '' ) && 'semantic' === ( $labelled_radio_receipt['operations'][0]['dimension'] ?? '' ) && ! in_array( 'unsupported_semantic_wrapper', array_column( $labelled_radio_receipt['losses'] ?? array(), 'reason_code' ), true ), 'labelled-radio-fieldset-receipt-represents-semantics-without-waiver', wp_json_encode( $labelled_radio_receipt ) );
	$assert( $labelled_radio_markup === serialize_blocks( parse_blocks( $labelled_radio_markup ) ), 'labelled-radio-fieldset-serialized-block-round-trips-through-wordpress', $labelled_radio_markup );
	$ambiguous_radio_group = $labelled_radio_group;
	$ambiguous_radio_group['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'radio', 'name' => 'comp-kf7in602', 'label' => 'No preference' );
	$ambiguous_radio_group['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 3 );
	$ambiguous_radio_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $ambiguous_radio_group );
	$ambiguous_radio_seed       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $ambiguous_radio_validation['forms'] ?? array() ) );
	$assert( 'skipped' === ( $ambiguous_radio_seed['forms'][0]['status'] ?? '' ) && 'unsupported_semantic_wrapper' === ( $ambiguous_radio_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ), 'ambiguous-labelled-radio-fieldset-remains-loss-gated', wp_json_encode( $ambiguous_radio_seed ) );
	$legacy_without_submit = $forms_manifest;
	array_pop( $legacy_without_submit['forms'][0]['controls'] );
	$legacy_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( $legacy_without_submit );
	$legacy_without_submit_markup = (string) ( $legacy_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $legacy_without_submit_markup, '<!-- wp:button ' ) && str_contains( $legacy_without_submit_markup, '>Submit</button>' ), 'legacy-form-without-submit-keeps-default-provider-button' );
	$topology_without_submit = $topology_form;
	array_pop( $topology_without_submit['forms'][0]['controls'] );
	array_pop( $topology_without_submit['forms'][0]['control_topology']['nodes'] );
	$validated_topology_without_submit = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $topology_without_submit );
	$assert( empty( $validated_topology_without_submit['errors'] ), 'topology-without-submit-manifest-validates' );
	$topology_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology_without_submit['forms'] ) );
	$topology_without_submit_markup = (string) ( $topology_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $topology_without_submit_markup, '<!-- wp:button ' ) && str_contains( $topology_without_submit_markup, '>Submit</button>' ), 'topology-without-submit-gets-one-default-provider-button' );
	$invalid_topology = $topology_form;
	$invalid_topology['forms'][0]['control_topology']['truncated'] = true;
	$invalid_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $invalid_topology );
	$assert( empty( $invalid_validation['forms'] ) && str_contains( (string) ( $invalid_validation['errors'][0]['message'] ?? '' ), 'truncated' ), 'topology-truncation-is-reported-not-flattened' );
	$unsupported_tag = $topology_form;
	$unsupported_tag['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$unsupported_tag_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsupported_tag );
	$assert( ! empty( $unsupported_tag_validation['forms'] ) && empty( $unsupported_tag_validation['errors'] ), 'topology-canonical-wrapper-vocabulary-remains-compatible' );
	$unsupported_control = $topology_form;
	$unsupported_control['forms'][0]['controls'][1] = array( 'tag' => 'input', 'type' => 'file', 'name' => 'attachment', 'label' => 'Attachment' );
	$unsupported_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsupported_control );
	$unsupported_control_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsupported_control_validation['forms'] ) );
	$unsupported_control_row = $unsupported_control_seed['forms'][0] ?? array();
	$unsupported_control_losses = array_values( array_filter( $unsupported_control_row['computed_layout_receipt']['losses'] ?? array(), static fn ( $loss ): bool => 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' ) ) );
	$unsupported_control_loss = $unsupported_control_losses[0] ?? array();
	$unsupported_control_markup = (string) ( $unsupported_control_row['block_markup'] ?? '' );
	$assert( empty( $unsupported_control_validation['errors'] ) && array( 'file' ) === ( $unsupported_control_row['skipped_types'] ?? array() ), 'unsupported-file-control-keeps-provider-skipped-type-diagnostic' );
	$assert( 'topology' === ( $unsupported_control_loss['dimension'] ?? '' ) && 'unsupported_control_unrepresentable' === ( $unsupported_control_loss['reason_code'] ?? '' ) && 1 === ( $unsupported_control_loss['control_index'] ?? null ) && hash( 'sha256', 'file' ) === ( $unsupported_control_loss['control_type_hash'] ?? '' ) && 64 === strlen( (string) ( $unsupported_control_loss['node_hash'] ?? '' ) ), 'unsupported-file-control-records-node-addressable-topology-loss' );
	$assert( str_contains( $unsupported_control_markup, 'First name' ) && ! str_contains( $unsupported_control_markup, 'Attachment' ) && str_contains( $unsupported_control_markup, 'Message' ), 'unsupported-file-control-preserves-supported-topology-order-around-loss' );
	$hidden_control = $topology_form;
	$hidden_control['forms'][0]['controls'][] = array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'ucfid', 'value' => '980337499904279388' );
	$hidden_control['forms'][0]['control_topology']['nodes'][] = array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 4 );
	$hidden_control_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $hidden_control );
	$hidden_control_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $hidden_control_validation['forms'] ) );
	$hidden_control_row = $hidden_control_seed['forms'][0] ?? array();
	$hidden_control_markup = (string) ( $hidden_control_row['block_markup'] ?? '' );
	$hidden_control_losses = array_values( array_filter( $hidden_control_row['computed_layout_receipt']['losses'] ?? array(), static fn ( $loss ): bool => 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' ) ) );
	$assert( empty( $hidden_control_validation['errors'] ) && array() === $hidden_control_losses, 'hidden-control-plumbing-records-no-topology-loss' );
	$assert( 'mapped' === ( $hidden_control_row['status'] ?? '' ) && empty( $hidden_control_row['form_receipt_unaccepted_losses'] ), 'hidden-control-plumbing-keeps-the-form-materializable' );
	$assert( str_contains( $hidden_control_markup, 'First name' ) && str_contains( $hidden_control_markup, 'Message' ) && ! str_contains( $hidden_control_markup, 'ucfid' ), 'hidden-control-plumbing-is-dropped-without-disturbing-authored-fields' );
	$list_wrapper = $topology_form;
	$list_wrapper['forms'][0]['control_topology']['nodes'][0]['tag'] = 'ul';
	$list_wrapper['forms'][0]['layout_graph']['nodes'] = array();
	$list_wrapper_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $list_wrapper );
	$list_wrapper_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $list_wrapper_validation['forms'] ) );
	$list_wrapper_row = $list_wrapper_seed['forms'][0] ?? array();
	$list_wrapper_markup = (string) ( $list_wrapper_row['block_markup'] ?? '' );
	$assert( 'mapped' === ( $list_wrapper_row['status'] ?? '' ) && empty( $list_wrapper_row['form_receipt_unaccepted_losses'] ), 'list-grouping-wrapper-keeps-the-form-materializable' );
	$assert( str_contains( $list_wrapper_markup, 'First name' ) && str_contains( $list_wrapper_markup, 'Email' ) && ! str_contains( $list_wrapper_markup, '<ul' ), 'list-grouping-wrapper-flattens-into-provider-fields' );
	$deep_topology = $topology_form;
	$deep_nodes = array();
	for ( $depth = 0; $depth < 8; ++$depth ) {
		$deep_nodes[] = array( 'id' => 'wrapper-' . $depth, 'kind' => 'wrapper', 'parent' => 0 === $depth ? null : 'wrapper-' . ( $depth - 1 ), 'order' => 0, 'depth' => $depth, 'class' => 'depth-' . $depth );
	}
	$deep_nodes[] = array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-7', 'order' => 0, 'depth' => 8, 'control' => 0 );
	$deep_nodes[] = array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 );
	$deep_nodes[] = array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 );
	$deep_nodes[] = array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 3 );
	$deep_topology['forms'][0]['control_topology']['nodes'] = $deep_nodes;
	$deep_topology['forms'][0]['layout_graph']['nodes'] = array();
	$deep_topology_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $deep_topology );
	$deep_topology_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $deep_topology_validation['forms'] ) );
	$deep_topology_markup = (string) ( $deep_topology_seed['forms'][0]['block_markup'] ?? '' );
	$assert( empty( $deep_topology_validation['errors'] ) && ! str_contains( $deep_topology_markup, '<!-- wp:group ' ) && str_contains( $deep_topology_markup, 'First name' ), 'deep-topology-flattens-without-losing-provider-fields' );

	// --- Provider blocks are never claimed without the provider runtime --------
	$GLOBALS['ssi_jetpack_form_blocks_available'] = false;
	$unavailable_seed                              = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$unavailable_row                               = $unavailable_seed['forms'][0] ?? array();
	$assert( 'failed' === ( $unavailable_seed['status'] ?? '' ) && 'static_site_importer_form_provider_unavailable' === ( $unavailable_seed['code'] ?? '' ), 'seed-unavailable-provider-fails-explicitly' );
	$assert( 1 === ( $unavailable_seed['counts']['skipped'] ?? 0 ), 'seed-unavailable-provider-skips-form' );
	$assert( 'provider_unavailable' === ( $unavailable_row['reason'] ?? '' ), 'seed-unavailable-provider-reason' );
	$assert( false === ( $unavailable_row['runtime_mapped'] ?? true ), 'seed-unavailable-provider-not-runtime-mapped' );
	$assert( empty( $unavailable_row['block_markup'] ), 'seed-unavailable-provider-emits-no-block-markup' );
	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	// Canonical declaration bindings carry authored presentation into the adapter.
	$cara_form_html = '<form class="contact-form"><h2>Contact Me</h2><label class="required-note"><span>*</span> Indicates required field</label><input aria-required="true" type="text" name="first"><textarea aria-required="true" name="message" style="height:200px"></textarea><input type="submit" value="Submit" style="position:absolute;left:-9999px"><a class="wsite-button"><span class="wsite-button-inner">Submit</span></a></form>';
	$cara_entity    = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/contact.html',
			'selector'    => 'form.contact-form',
			'form'        => array( 'class' => 'contact-form' ),
			'controls'    => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'aria-required' => 'true' ), array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'aria-required' => 'true' ), array( 'tag' => 'input', 'type' => 'submit' ) ),
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/contact.html', 'search_block_markup' => $cara_form_html, 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$cara_grafted   = (string) ( Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $cara_entity ) ) )['forms'][0]['block_markup'] ?? '' );
	$assert( str_contains( $cara_grafted, '>Contact Me</h2>' ) && str_contains( $cara_grafted, '<p>* Indicates required field</p>' ) && str_contains( $cara_grafted, '"required":true' ) && str_contains( $cara_grafted, 'wsite-button' ), 'canonical-binding-presentation-reaches-provider-markup' );


	// --- Provider override routes to a different registered adapter ----------
	add_filter(
		'static_site_importer_entity_materializers',
		static function ( array $adapters ): array {
			$adapters['gravity_forms_adapter'] = array(
				'id'         => 'gravity_forms_adapter',
				'capability' => 'form',
				'provider'   => 'gravity_forms',
				'waiver_arg' => 'allow_missing_gravity_forms',
				'rollback_contract_id' => 'test/gravity-forms-rollback/v1',
			);
			return $adapters;
		}
	);
	add_filter( 'ssi_form_plugin', static fn ( string $provider ): string => 'gravity_forms' );

	$assert( 'gravity_forms' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-provider-override' );
	$overridden = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'gravity_forms_adapter' === ( $overridden['id'] ?? '' ), 'form-adapter-routes-to-override' );
	// Shop capability stays on the default provider despite the form override.
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-provider-unaffected-by-form-override' );

	// --- Real source boxes keep their own elements and their own layout ------
	// These entities are the retained materialization evidence from a Wix import whose
	// forms were previously declined outright. Each field sits inside nested source
	// boxes addressed by ancestor-dependent selectors, and the source drives display
	// through its own custom properties.
	$kmr = array();
	foreach ( array( 0, 1, 2, 3 ) as $kmr_index ) {
		$kmr_fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/kmr-form-' . $kmr_index . '.json' ), true );
		$kmr_valid   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $kmr_fixture ) ) );
		$assert( empty( $kmr_valid['errors'] ), 'kmr-source-form-' . $kmr_index . '-validates', wp_json_encode( $kmr_valid['errors'] ?? array() ) );
		$kmr[ $kmr_index ] = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $kmr_valid['forms'] ?? array() ) )['forms'][0] ?? array();
	}
	foreach ( array( 0 => 8, 1 => 8, 2 => 2 ) as $kmr_index => $expected_fields ) {
		$kmr_row    = $kmr[ $kmr_index ];
		$kmr_fields = array_values( array_filter( $kmr_row['field_blocks'] ?? array(), static fn( string $name ): bool => 'core/button' !== $name ) );
		$assert(
			'mapped' === ( $kmr_row['status'] ?? '' ) && true === ( $kmr_row['runtime_mapped'] ?? false ) && array() === ( $kmr_row['form_receipt_unaccepted_losses'] ?? array() ),
			'kmr-source-form-' . $kmr_index . '-materializes-natively',
			wp_json_encode( array_column( $kmr_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) )
		);
		$assert( $expected_fields === count( $kmr_fields ), 'kmr-source-form-' . $kmr_index . '-preserves-every-field', wp_json_encode( $kmr_fields ) );
		$assert( str_contains( (string) ( $kmr_row['block_markup'] ?? '' ), '<!-- wp:jetpack/contact-form' ), 'kmr-source-form-' . $kmr_index . '-is-a-native-provider-block' );
	}
	$assert( 'Send' === ( $kmr[0]['submit_text'] ?? '' ) && 'JOIN' === ( $kmr[2]['submit_text'] ?? '' ), 'kmr-source-forms-preserve-their-submit-labels' );
	$kmr_css = (string) ( $kmr[2]['provider_layout_overlay_css']['css'] ?? '' );
	$kmr_map = array_column( $kmr[2]['provider_layout_target_map']['targets'] ?? array(), 'selector', 'node' );
	$kmr_scope = (string) ( $kmr[2]['provider_layout_target_map']['scope'] ?? '' );
	$assert( $kmr_scope === ( $kmr_map['form-box'] ?? '' ) && str_contains( $kmr_css, $kmr_scope . '{align-self:start;grid-area:4 / 1 / 5 / 2' ) === false && str_contains( $kmr_css, $kmr_scope . '{align-self:start;grid-area:1 / 1 / 2 / 2;justify-self:start}' ), 'source-form-box-placement-lands-on-the-provider-block-wrapper', $kmr_css );
	$assert( str_contains( $kmr_css, ' > form.jetpack-contact-form__form, ' ) && str_contains( $kmr_css, ':not(:has(> form.jetpack-contact-form__form)){grid-template-columns:100%;display:grid}' ), 'all-controls-source-box-establishes-the-provider-form-container', $kmr_css );
	$assert( 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}-wrap\{[^}]*grid-area:4 \/ 1 \/ 5 \/ 2[^}]*width:156px(?:;[^}]*)?\}/', $kmr_css ), 'single-field-source-box-keeps-its-own-grid-placement', $kmr_css );
	$assert( str_contains( $kmr_css, 'display:var(--display)' ) && str_contains( $kmr_css, 'justify-content:var(--label-align)' ), 'source-owned-custom-properties-survive-transposition', $kmr_css );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $kmr[2]['provider_layout_overlay_css'] ?? null ), 'kmr-overlay-passes-stylesheet-admission' );
	// A source box chain deeper than the provider's own element pair cannot keep every
	// box, so it stays a decline instead of claiming an equivalence it cannot hold.
	$assert(
		'skipped' === ( $kmr[3]['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', array_column( $kmr[3]['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'source-box-chain-deeper-than-the-provider-shape-fails-closed',
		wp_json_encode( array_column( $kmr[3]['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ) )
	);
	$unsafe_custom_property = Static_Site_Importer_Provider_Layout_Overlay::compile(
		$layout_graph( array( $layout_node( 'form', array( 'display' => 'var(--display); color:red' ), 'form' ) ) ),
		$root_map
	);
	$assert( '' === $unsafe_custom_property['css'] && 'unsafe_layout_value' === ( $unsafe_custom_property['losses'][0]['reason_code'] ?? '' ), 'custom-property-passthrough-still-rejects-injected-declarations' );

	if ( empty( $failures ) && in_array( '--emit-topology-markup', $argv ?? array(), true ) ) {
		echo wp_json_encode( array( 'markup' => $topology_markup, 'styled_markup' => $markup, 'depth_markup' => $deep_topology_markup, 'deep_width_markup' => $deep_width_markup, 'cara_markup' => $cara_grafted ) ) . "\n";
		exit( 0 );
	}

	if ( empty( $failures ) ) {
		echo 'PASS form-materializer-smoke.php (' . $assertions . " assertions)\n";
		exit( 0 );
	}

	echo 'FAILURES (' . count( $failures ) . ' of ' . $assertions . " assertions):\n";
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
