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
			return json_encode( $value, $flags, $depth );
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
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

	$transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';
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
	$layout_graph = static function ( array $nodes ): array {
		return array( 'schema' => 'generic/computed-layout-graph/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'nodes' => 128, 'depth' => 8, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(), 'nodes' => $nodes );
	};
	$v2_layout_graph = static function ( array $nodes ): array {
		return array( 'schema' => 'generic/computed-layout-graph/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'nodes' => 128, 'depth' => 16, 'rules_per_node' => 16 ), 'variants' => array(), 'diagnostics' => array(), 'nodes' => $nodes );
	};
	$layout_node = static function ( string $id, array $layout, string $tag = 'div' ): array {
		return array( 'id' => $id, 'kind' => 'control' === substr( $id, 0, 7 ) ? 'control' : 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => $tag, 'classes' => array() ), 'layout' => $layout, 'provenance' => array() );
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

	// Truncated graphs remain producer fallback evidence, never runtime input.
	$truncated_css   = str_repeat( '@media (min-width:1px){', 9 ) . '.form{display:grid}' . str_repeat( '}', 9 );
	$truncated_forms = str_repeat( '<form class="form"><input name="email"><button type="submit">Send</button></form>', 8 );
	$truncated_result = ( new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
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
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-text \{"required":true,"id":"contact-name","className":"ssi-node-[a-f0-9]{12}"\} -->/', $markup ), 'markup-field-wrapper-keeps-provider-layout-class' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/label {"label":"Your name","className":"source-label"} /-->' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"className":"source-field"} /-->' ), 'markup-field-canonical-label-and-input-children-carry-source-classes' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-select {"options":["Sales","Support"]' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"type":"dropdown"} /-->' ), 'markup-select-options-and-dropdown-input' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-radio {"options":["In person","Online"]' ) && str_contains( $markup, '<!-- wp:jetpack/options {"type":"radio"} -->' ), 'markup-radio-options-on-field-and-child-list' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-checkbox ' ) && str_contains( $markup, '<!-- wp:jetpack/option {"label":"Send me updates","isStandalone":true} /-->' ), 'markup-checkbox-uses-standalone-option-child' );
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
	$composed_result = ( new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
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
	$assert( 'error' === ( $unsafe_variant_row['status'] ?? '' ) && ! str_contains( (string) ( $unsafe_variant_row['block_markup'] ?? '' ), '"width":33.333' ), 'unsafe-percentage-variant-fails-closed' );

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
	$assert( 'error' === ( $hidden_variant_row['status'] ?? '' ) && in_array( 'provider_wrapper_layout_unrepresentable', array_column( $hidden_variant_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'responsive-hidden-wrapper-remains-unrepresented' );

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
	$assert( 'error' === ( $unproven_hidden_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility_unrepresentable', array_column( $unproven_hidden_select_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ), 'source-hidden-native-control-without-replacement-evidence-fails-closed', wp_json_encode( $unproven_hidden_select_row ) );

	$partial_width_form = $deep_width_form;
	$partial_width_form['layout_graph']['nodes'][13]['layout']['width'] = '30%';
	$partial_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $partial_width_form ) ) );
	$partial_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $partial_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'error' === ( $partial_width_row['status'] ?? '' ) && ! str_contains( (string) ( $partial_width_row['block_markup'] ?? '' ), '"width":33.333' ) && ! str_contains( (string) ( $partial_width_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:50%' ), 'partial-percentage-row-fails-closed' );
	$multiple_controls_form = $deep_width_form;
	$multiple_controls_form['control_topology']['nodes'][21]['parent'] = 'wrapper-12';
	$multiple_controls_form['control_topology']['nodes'][21]['order'] = 1;
	$multiple_controls_form['control_topology']['nodes'][21]['depth'] = 13;
	$multiple_controls_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $multiple_controls_form ) ) );
	$multiple_controls_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $multiple_controls_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $multiple_controls_validation['errors'] ) && 'error' === ( $multiple_controls_row['status'] ?? '' ) && ! str_contains( (string) ( $multiple_controls_row['block_markup'] ?? '' ), '"width":33.333' ), 'multiple-controls-in-percentage-branch-fail-closed' );
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
	$assert( 'error' === ( $unproven_table_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $unproven_reasons, true ), 'unproven-table-semantics-remain-gated', wp_json_encode( $unproven_table_row ) );
	$labelled_width_form = $deep_width_form;
	$labelled_width_form['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$labelled_width_form['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_width_form['layout_graph']['nodes'][1]['source']['tag'] = 'fieldset';
	$labelled_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $labelled_width_form ) ) );
	$labelled_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$labelled_width_reasons = array_column( $labelled_width_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$assert( 'error' === ( $labelled_width_row['status'] ?? '' ) && in_array( 'unsupported_semantic_wrapper', $labelled_width_reasons, true ) && 3 === substr_count( (string) ( $labelled_width_row['block_markup'] ?? '' ), '"width":33.333' ), 'percentage-width-proof-does-not-accept-labelled-fieldset-semantics', wp_json_encode( $labelled_width_row ) );
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
	$assert( ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $class_owned_losses, 'reason_code' ), true ) && str_contains( $class_owned_markup, 'ssi-source-wrapper-1\u002d\u002dfield' ), 'class-owned-single-field-layout-projects-with-its-provider-wrapper-hook', $class_owned_markup );
	$projected_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper--field-wrap"><input class="ssi-source-wrapper--field source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="field"><input class="source-input"></div></div>' === $projected_wrapper, 'provider-runtime-rebuilds-source-wrapper-inside-field-shell', $projected_wrapper );
	$layered_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper-6--carrier-wrap ssi-source-wrapper-8--input-shell-wrap"><label>Name</label><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><label>Name</label><div class="carrier"><div class="input-shell"><input class="source-input"></div></div></div>' === $layered_wrapper, 'provider-runtime-preserves-ordered-input-wrapper-carriers-below-label', $layered_wrapper );
	$projected_submit = Static_Site_Importer_Form_Seeder::project_provider_submit_presentation(
		'<div class="wp-block-button ssi-source-submit--source-submit"><button class="wp-block-button__link">Send</button></div>',
		array( 'attrs' => array( 'className' => 'wp-block-button ssi-source-submit--source-submit' ) )
	);
	$assert( '<div class="wp-block-button"><button class="wp-block-button__link source-submit" style="min-height:0">Send</button></div>' === $projected_submit, 'provider-runtime-projects-submit-classes-and-neutralizes-provider-minimum-height', $projected_submit );
	$unproven_class_form = $class_owned_form;
	$unproven_class_form['layout_graph']['nodes'][1]['provenance'] = array();
	$unproven_class_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unproven_class_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $unproven_class_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'class-projection-alone-does-not-claim-layout-equivalence' );
	$structural_selector_form = $class_owned_form;
	$structural_selector_form['layout_graph']['nodes'][1]['provenance'][0]['selector'] = '.row-2 > .field';
	$structural_selector_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $structural_selector_form ) ) );
	$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $structural_selector_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'ancestor-dependent-class-selector-does-not-survive-wrapper-flattening' );
	$root_graph = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'form' ) ) );
	$root_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'form', 'selector' => '.ssi-form-123456789abc > form.jetpack-contact-form__form', 'capabilities' => array( 'container_layout', 'responsive_layout' ) ) ) );
	$root_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $root_map );
	$assert( str_contains( $root_overlay['css'], '.ssi-form-123456789abc > form.jetpack-contact-form__form{display:flex;flex-direction:row;gap:1rem}' ) && str_contains( $root_overlay['css'], '.ssi-form-123456789abc{position:relative;z-index:1;pointer-events:auto}' ) && 'provider_selector_transposition' === ( $root_overlay['operations'][0]['strategy'] ?? '' ) && 'provider_interaction_carrier' === ( $root_overlay['operations'][1]['strategy'] ?? '' ), 'provider-layout-root-targets-native-jetpack-form-with-an-interaction-carrier' );
	$unsafe_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'display' => 'url(https://example.test/x)' ), 'form' ) ) ), $root_map );
	$assert( '' === $unsafe_overlay['css'] && 'unsafe_layout_value' === ( $unsafe_overlay['losses'][0]['reason_code'] ?? '' ), 'provider-layout-overlay-rejects-unsafe-values' );
	$bad_map = $root_map; $bad_map['targets'][0]['selector'] = 'body .anything';
	$assert( isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $bad_map, $root_graph )['error'] ), 'provider-layout-overlay-rejects-arbitrary-selectors' );
	$responsive_root = $root_graph;
	$responsive_root['variants'] = array( array( 'node' => 'form', 'condition' => array( 'kind' => 'media', 'query' => '(min-width: 48rem)' ), 'layout_patch' => array( 'direction' => 'column' ) ) );
	$assert( str_contains( Static_Site_Importer_Provider_Layout_Overlay::compile( $responsive_root, $root_map )['css'], '@media (min-width: 48rem)' ), 'provider-layout-overlay-supports-bounded-media-condition' );
	$root_item = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'form', array( 'order' => 1 ), 'form' ) ) ), $root_map );
	$assert( 'direct_child_relationship_unrepresentable' === ( $root_item['losses'][0]['reason_code'] ?? '' ), 'jetpack-form-root-does-not-claim-direct-child-layout' );
	$item_graph = $layout_graph( array( $layout_node( 'control-0', array( 'order' => 1, 'flex_grow' => 1 ), 'input' ) ) );
	$item_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'control-0', 'selector' => '.ssi-form-123456789abc .ssi-node-123456789abc', 'capabilities' => array( 'item_layout', 'direct_child_layout' ) ) ) );
	$item_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( str_contains( $item_overlay['css'], 'order:1;flex-grow:1' ) && empty( $item_overlay['losses'] ), 'provider-layout-emits-item-properties-only-for-proven-direct-child-targets' );
	$item_map['targets'][0]['capabilities'] = array( 'item_layout' );
	$item_without_direct_child = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( '' === $item_without_direct_child['css'] && array( 'direct_child_relationship_unrepresentable', 'direct_child_relationship_unrepresentable' ) === array_column( $item_without_direct_child['losses'], 'reason_code' ), 'provider-layout-does-not-accept-inert-item-layout-capability' );
	$booking = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.booking', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request booking' ) ) ) ) ) );
	$booking_row = $booking['forms'][0] ?? array();
	$assert( 'Request booking' === ( $booking_row['submit_text'] ?? '' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '>Request booking</button>' ), 'canonical-control-text-preserves-request-booking-submit-label' );
	$assert( str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"label":"Guests"' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"step":"0.5"' ) && array( 'min', 'max' ) === array_column( $booking_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-source-attributes-preserve-supported-step-and-report-min-max-losses' );
	$assert( false === ( $booking_row['runtime_mapped'] ?? true ) && array( 'min', 'max' ) === array_column( $booking_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ) && 2 === ( $booking_row['unaccepted_receipt_loss_count'] ?? 0 ), 'number-unsupported-attributes-gate-form-runtime-acceptance' );
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
	$plain_root_fieldset = $topology_form;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'plain_group';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][5]['order'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][6]['depth'] = 2;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['parent'] = 'wrapper-0';
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['depth'] = 1;
	$plain_root_fieldset['forms'][0]['control_topology']['nodes'][7]['order'] = 3;
	$plain_root_fieldset['forms'][0]['layout_graph']['nodes'] = array();
	$plain_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $plain_root_fieldset );
	$plain_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $plain_root_fieldset_validation['forms'] ?? array() ) );
	$assert( empty( $plain_root_fieldset_validation['errors'] ) && 'mapped' === ( $plain_root_fieldset_seed['forms'][0]['status'] ?? '' ) && empty( $plain_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'] ?? array() ), 'provider-form-carries-plain-root-fieldset-grouping', wp_json_encode( array( 'validation' => $plain_root_fieldset_validation, 'seed' => $plain_root_fieldset_seed ) ) );
	$labelled_root_fieldset = $plain_root_fieldset;
	$labelled_root_fieldset['forms'][0]['control_topology']['nodes'][0]['fieldset_semantics'] = 'labelled_group';
	$labelled_root_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $labelled_root_fieldset );
	$labelled_root_fieldset_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $labelled_root_fieldset_validation['forms'] ?? array() ) );
	$assert( 'error' === ( $labelled_root_fieldset_seed['forms'][0]['status'] ?? '' ) && 'unsupported_semantic_wrapper' === ( $labelled_root_fieldset_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ), 'provider-form-rejects-labelled-root-fieldset-loss', wp_json_encode( array( 'validation' => $labelled_root_fieldset_validation, 'seed' => $labelled_root_fieldset_seed ) ) );
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
	$assert( 'error' === ( $ambiguous_radio_seed['forms'][0]['status'] ?? '' ) && 'unsupported_semantic_wrapper' === ( $ambiguous_radio_seed['forms'][0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ), 'ambiguous-labelled-radio-fieldset-remains-loss-gated', wp_json_encode( $ambiguous_radio_seed ) );
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
