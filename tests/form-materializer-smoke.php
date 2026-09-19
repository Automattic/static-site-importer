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

	$wp_root = (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
	$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
	$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
	if ( is_readable( $parser ) && is_readable( $blocks ) ) {
		require_once $parser;
		require_once $blocks;
	}
	if ( ! function_exists( 'serialize_blocks' ) ) {
		// This test declares the wordpress-runtime environment; a missing
		// dependency here must fail closed rather than silently report success.
		fwrite( STDERR, "FAIL: WordPress block serialization is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
		exit( 1 );
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
	$punctuated_source_condition = array( 'kind' => 'media', 'query' => '(max-width: 48rem)' );
	$punctuated_source_form      = array(
		'forms' => array(
			array(
				'controls'     => array( array( 'tag' => 'input', 'type' => 'email' ) ),
				'layout_graph' => $v2_layout_graph(
					array(
						array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array() ), 'layout' => array(), 'provenance' => array() ),
					)
				),
			)
		)
	);
	$punctuated_source_form['forms'][0]['layout_graph']['variants'][] = array(
		'node'         => 'form',
		'condition'    => $punctuated_source_condition,
		'layout_patch' => array( 'display' => 'flex' ),
		'precedence'   => array( 'display' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ) ),
		'provenance'   => array( array( 'source_path' => 'website/comms-&-use-cases/index.html', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.contact-form', 'condition' => $punctuated_source_condition, 'properties' => array( 'display' ) ) ),
	);
	$assert( empty( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $punctuated_source_form )['errors'] ), 'form-layout-provenance-accepts-canonical-punctuated-artifact-path' );

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
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'description' => 'We only use this to contact you back.' ),
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
	$placeholder_select_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'select',
		'select',
		array(
			'name'    => 'membership_type',
			'label'   => 'Membership type',
			'options' => array(
				array( 'label' => 'Select', 'value' => '', 'placeholder' => true, 'disabled' => true, 'selected' => true ),
				array( 'label' => 'Ordinary member', 'value' => 'Ordinary member' ),
				array( 'label' => 'Life member', 'value' => 'Life member' ),
				array( 'label' => 'Overseas member', 'value' => 'Overseas member' ),
			),
		)
	);
	$placeholder_select_input = array();
	foreach ( $placeholder_select_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$placeholder_select_input = $inner;
			break;
		}
	}
	$assert( array( 'Ordinary member', 'Life member', 'Overseas member' ) === ( $placeholder_select_field['attrs']['options'] ?? null ), 'select-placeholder-option-is-omitted-from-the-provider-option-list', wp_json_encode( $placeholder_select_field ) );
	$assert( 'Select' === ( $placeholder_select_input['attrs']['placeholder'] ?? null ), 'select-placeholder-option-maps-onto-jetpack-input-placeholder', wp_json_encode( $placeholder_select_input ) );
	$assert( ! in_array( 'Select an option', $placeholder_select_field['attrs']['options'] ?? array(), true ), 'select-does-not-emit-a-synthetic-select-an-option-label', wp_json_encode( $placeholder_select_field ) );
	$placeholder_select_markup = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => array(
				array(
					'form'     => array( 'action' => '/join', 'method' => 'post' ),
					'controls' => array(
						array(
							'tag'     => 'select',
							'type'    => 'select',
							'name'    => 'membership_type',
							'label'   => 'Membership type',
							'options' => array(
								array( 'label' => 'Select', 'value' => '', 'placeholder' => true, 'disabled' => true, 'selected' => true ),
								array( 'label' => 'Ordinary member' ),
								array( 'label' => 'Life member' ),
								array( 'label' => 'Overseas member' ),
							),
						),
						array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
					),
				),
			),
		)
	)['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $placeholder_select_markup, '"options":["Ordinary member","Life member","Overseas member"]' ) && str_contains( $placeholder_select_markup, '"placeholder":"Select"' ) && ! str_contains( $placeholder_select_markup, 'Select an option' ), 'seeded-select-emits-source-placeholder-and-source-options-only', $placeholder_select_markup );
	$assert( 1 === preg_match( '/<div class="wp-block-jetpack-contact-form form contact ssi-form-[a-f0-9]{12}">/', $markup ), 'markup-contact-form-wrapper-and-source-classes' );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-text (?=[^\n]*"required":true)(?=[^\n]*"id":"ssi-form-[a-f0-9]{12}-field-0")(?=[^\n]*"className":"ssi-node-[a-f0-9]{12}")(?=[^\n]*"shareFieldAttributes":false)[^\n]* -->/', $markup ), 'markup-field-wrapper-keeps-provider-layout-class-and-instance-identity' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/label {"label":"Your name","className":"source-label"} /-->' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"className":"source-field"} /-->' ), 'markup-field-canonical-label-and-input-children-carry-source-classes' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-select {"options":["Sales","Support"]' ) && str_contains( $markup, '<!-- wp:jetpack/input {"style":{"border":{"style":"solid"}},"type":"dropdown"} /-->' ), 'markup-select-options-and-dropdown-input' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-radio {"options":["In person","Online"]' ) && str_contains( $markup, '<!-- wp:jetpack/options {"type":"radio"} -->' ), 'markup-radio-options-on-field-and-child-list' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/field-checkbox ' ) && str_contains( $markup, '<!-- wp:jetpack/option {"label":"Send me updates","isStandalone":true} /-->' ), 'markup-checkbox-uses-standalone-option-child' );

	// --- A control's own description reaches the provider as Jetpack's shared help-text attribute ---
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-email \{[^\n]*"helpText":"We only use this to contact you back\."[^\n]*\} -->/', $markup ), 'markup-described-control-carries-source-description-as-jetpack-help-text', $markup );
	$assert( 1 === substr_count( $markup, '"helpText"' ), 'markup-undescribed-controls-in-the-same-form-carry-no-help-text-attribute', $markup );

	// Jetpack declares `helpText` on every jetpack/field-* block, but a field
	// whose editor and frontend renderer never surface it (checkbox, radio -
	// the grouped fields this seeder emits as jetpack/field-checkbox(-multiple)
	// and jetpack/field-radio) must not silently store a value the visitor
	// never sees. A supported field type carries it as `helpText`; an
	// unsupported one reports the same `unsupported_control_attribute` loss
	// an unrepresentable numeric step already uses, rather than a
	// parallel mechanism.
	$described_url_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'url',
		array(
			'name'        => 'portfolio',
			'label'       => 'Portfolio URL',
			'placeholder' => 'https://yourportfolio.com',
			'description' => 'Link to your design work (Behance, Dribbble, personal site, etc.)',
		)
	);
	$assert( 'Link to your design work (Behance, Dribbble, personal site, etc.)' === ( $described_url_field['attrs']['helpText'] ?? null ), 'described-url-control-carries-jetpack-help-text-attribute', wp_json_encode( $described_url_field ) );
	$assert( array() === ( $described_url_field['losses'] ?? array() ), 'described-url-control-reports-no-loss', wp_json_encode( $described_url_field ) );
	$undescribed_url_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'url',
		array(
			'name'        => 'portfolio',
			'label'       => 'Portfolio URL',
			'placeholder' => 'https://yourportfolio.com',
		)
	);
	$assert( ! array_key_exists( 'helpText', $undescribed_url_field['attrs'] ?? array() ), 'undescribed-url-control-carries-no-help-text-attribute', wp_json_encode( $undescribed_url_field ) );
	$described_checkbox_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'checkbox',
		array( 'name' => 'updates', 'label' => 'Send me updates', 'description' => 'We send about once a month.' )
	);
	$described_checkbox_losses = array_values( array_filter( $described_checkbox_field['losses'] ?? array(), static fn( array $loss ): bool => 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' ) && 'description' === ( $loss['attribute'] ?? '' ) ) );
	$assert( ! array_key_exists( 'helpText', $described_checkbox_field['attrs'] ?? array() ), 'checkbox-description-is-not-stored-where-jetpack-never-renders-it', wp_json_encode( $described_checkbox_field ) );
	$assert( 1 === count( $described_checkbox_losses ), 'checkbox-description-is-reported-as-an-unsupported-control-attribute-loss-instead-of-a-silent-drop', wp_json_encode( $described_checkbox_field ) );
	$described_radio_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'radio',
		array( 'name' => 'format', 'label' => 'In person', 'options' => array( 'In person', 'Online' ), 'description' => 'Choose whichever suits you.' )
	);
	$described_radio_losses = array_values( array_filter( $described_radio_field['losses'] ?? array(), static fn( array $loss ): bool => 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' ) && 'description' === ( $loss['attribute'] ?? '' ) ) );
	$assert( ! array_key_exists( 'helpText', $described_radio_field['attrs'] ?? array() ), 'radio-description-is-not-stored-where-jetpack-never-renders-it', wp_json_encode( $described_radio_field ) );
	$assert( 1 === count( $described_radio_losses ), 'radio-description-is-reported-as-an-unsupported-control-attribute-loss-instead-of-a-silent-drop', wp_json_encode( $described_radio_field ) );

	// jetpack/input declares min and max as numbers and does not declare step.
	// A bounded number control must become jetpack/field-number with those
	// attributes on the inner input; step stays a real unsupported-attribute loss.
	$bounded_number_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'number',
		array( 'name' => 'household', 'label' => 'Household size', 'min' => '1', 'max' => '50' )
	);
	$bounded_number_input = array();
	foreach ( $bounded_number_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$bounded_number_input = $inner;
			break;
		}
	}
	$assert( 'jetpack/field-number' === ( $bounded_number_field['name'] ?? '' ), 'bounded-number-control-maps-to-jetpack-field-number', wp_json_encode( $bounded_number_field ) );
	$assert( 1 === ( $bounded_number_input['attrs']['min'] ?? null ) && 50 === ( $bounded_number_input['attrs']['max'] ?? null ), 'bounded-number-control-carries-min-max-onto-jetpack-input', wp_json_encode( $bounded_number_input ) );
	$assert( array() === ( $bounded_number_field['losses'] ?? array() ), 'bounded-number-min-max-are-not-losses', wp_json_encode( $bounded_number_field ) );
	$stepped_number_field = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
		'input',
		'number',
		array( 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' )
	);
	$stepped_number_input = array();
	foreach ( $stepped_number_field['innerBlocks'] ?? array() as $inner ) {
		if ( 'jetpack/input' === ( $inner['name'] ?? '' ) ) {
			$stepped_number_input = $inner;
			break;
		}
	}
	$assert( 1 === ( $stepped_number_input['attrs']['min'] ?? null ) && 8 === ( $stepped_number_input['attrs']['max'] ?? null ) && ! array_key_exists( 'step', $stepped_number_input['attrs'] ?? array() ), 'stepped-number-control-carries-min-max-and-does-not-store-step', wp_json_encode( $stepped_number_input ) );
	$assert( array( 'step' ) === array_column( $stepped_number_field['losses'] ?? array(), 'attribute' ), 'stepped-number-control-reports-step-as-an-unsupported-control-attribute-loss', wp_json_encode( $stepped_number_field ) );

	// The same unsupported-attribute loss the seeder already gates provider
	// mapping on (see the numeric step overflow coverage below) also
	// gates a described checkbox: an invisible attribute is not a fidelity
	// win, so the seeder declines to claim the field is represented rather
	// than shipping a value the visitor will never see.
	$described_checkbox_seed = Static_Site_Importer_Form_Seeder::seed(
		array( 'forms' => array( array( 'selector' => 'form.updates', 'controls' => array(
			array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates', 'description' => 'We send about once a month.' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ),
		) ) ) )
	);
	$described_checkbox_row = $described_checkbox_seed['forms'][0] ?? array();
	$assert( false === ( $described_checkbox_row['runtime_mapped'] ?? true ) && 'form_receipt_loss_unaccepted' === ( $described_checkbox_row['reason'] ?? '' ) && in_array( 'description', array_column( $described_checkbox_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ), true ), 'seeder-declines-rather-than-silently-drops-an-unrenderable-checkbox-description', wp_json_encode( $described_checkbox_row ) );

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
	$markerless_form = $marker_form;
	unset( $markerless_form['controls'][0]['required_text'] );
	$markerless_form['controls'][0]['required_indicator'] = false;
	$markerless_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $markerless_form ) ) )['forms'][0];
	$assert( str_contains( $markerless_row['block_markup'], '"requiredIndicator":false' ) && ! str_contains( $markerless_row['block_markup'], '"requiredText"' ), 'captured-markerless-required-field-preserves-validation-without-a-provider-default-marker' );
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
	$topology_ops = array_column( $topology_receipt['operations'] ?? array(), 'strategy' );
	$assert( 'applied' === ( $topology_receipt['status'] ?? '' ) && in_array( 'provider_equal_width_fields', $topology_ops, true ) && in_array( 'provider_interaction_carrier', $topology_ops, true ) && 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.5rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.5rem\);margin-block-start:0!important\}/', (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ) ), 'computed-layout-equal-grid-applies-with-bounded-receipt', wp_json_encode( array( 'ops' => $topology_ops, 'css' => $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ) ) );
	$assert( str_contains( $topology_markup, 'ssi-textarea-rows-2' ) && str_contains( (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' ) && ! str_contains( (string) ( $topology_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' ), 'topology-unstyled-source-textarea-carries-two-rows-and-neutralizes-the-provider-height' );

	// --- A source utility-framework grid row materializes as provider field widths ---
	// Reproduces a real base44/Tailwind CSS v4 contact form (labels are plain,
	// unassociated siblings with no `for`/`id`/`name`, exactly as captured): a
	// `grid grid-cols-1 md:grid-cols-2 gap-6` row stacks Name/Phone on narrow
	// viewports and only bands them into two equal columns at a proven
	// widening breakpoint. Tailwind v4 emits that breakpoint using the modern
	// CSS range syntax `(width>=768px)` rather than `(min-width:768px)`.
	// Jetpack field width has no responsive states, so the widened, two-up
	// state is what materializes; every other wrapper here is a redundant
	// single-control `<div>` a Jetpack field already owns as its own unit.
	$tailwind_grid_css   = '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$tailwind_grid_html  = '<style>' . $tailwind_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<div><label>Email *</label><input type="email" required></div>'
		. '<div><label>Project Type</label><select><option value="">Select a project type</option><option>Kitchen Renovation</option></select></div>'
		. '<div><label>Project Details *</label><textarea rows="5" placeholder="Tell us about your project..." required></textarea></div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$tailwind_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $tailwind_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$tailwind_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $tailwind_grid_source ) ) );
	$assert( empty( $tailwind_grid_validated['errors'] ), 'mobile-first-grid-row-manifest-validates', wp_json_encode( $tailwind_grid_validated ) );
	$tailwind_grid_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $tailwind_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$tailwind_grid_markup = (string) ( $tailwind_grid_row['block_markup'] ?? '' );
	$assert(
		'mapped' === ( $tailwind_grid_row['status'] ?? '' )
			&& empty( $tailwind_grid_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 5 === ( $tailwind_grid_row['field_count'] ?? 0 )
			&& 2 === substr_count( $tailwind_grid_markup, '"width":50' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/contact-form' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{"required":true[^}]*"width":50\} -->/', $tailwind_grid_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{"required":true[^}]*"width":50\} -->/', $tailwind_grid_markup )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-email' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-select' )
			&& str_contains( $tailwind_grid_markup, '"options":["Select a project type","Kitchen Renovation"]' )
			&& str_contains( $tailwind_grid_markup, 'wp:jetpack/field-textarea' )
			&& str_contains( $tailwind_grid_markup, '"placeholder":"Tell us about your project..."' )
			&& str_contains( $tailwind_grid_markup, 'ssi-textarea-rows-5' )
			&& ! str_contains( $tailwind_grid_markup, 'wp:group' ),
		'mobile-first-tailwind-v4-grid-row-materializes-as-two-jetpack-fields-at-width-50',
		wp_json_encode( array( 'row' => $tailwind_grid_row, 'markup' => $tailwind_grid_markup ) )
	);
	$tailwind_grid_css_out = (string) ( $tailwind_grid_row['provider_layout_overlay_css']['css'] ?? '' );
	// A carried source sibling-stacking utility (Tailwind's `space-y-*`) is
	// authored against the ORIGINAL sibling relationships, at unbounded
	// specificity, and lands on the provider form unscoped. Once flattening
	// makes Name and Phone adjacent provider siblings that rule still matches
	// them, so a same-specificity reset cannot reliably out-rank it; the reset
	// must carry `!important` on every flattened field shell, paired or not,
	// or the source rule can still re-stack the row.
	$assert(
		empty( $tailwind_grid_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.75rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.75rem\);margin-block-start:0!important\}/', $tailwind_grid_css_out )
			&& 3 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{margin-block-start:0!important\}/', $tailwind_grid_css_out )
			&& ! str_contains( $tailwind_grid_css_out, 'margin-block-start:0}' )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $tailwind_grid_row['provider_layout_overlay_css'] ?? null ),
		'mobile-first-equal-column-row-overlay-compensates-jetpack-gap-and-flattened-sibling-margins',
		$tailwind_grid_css_out
	);
	// A submit control is never routed through Jetpack's grunion field renderer, so
	// its generated hook never gets the `-wrap` class suffix a field's does; it must
	// still be reset, because it sits as a flex item beside the flattened fields in
	// the same `space-y-*`-classed container and would otherwise carry both the
	// container's flex `gap` and the carried sibling-margin, doubling the space the
	// source spent once.
	$assert(
		1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{margin-block-start:0!important\}/', $tailwind_grid_css_out ),
		'mobile-first-equal-column-row-also-neutralizes-the-flattened-submit-siblings-margin',
		$tailwind_grid_css_out
	);
	// A source form that occupies a page-grid item through a host wrapper (the
	// wrapper the provider container replaces) must keep that item's column span
	// on the materialized block, without matching any one utility class name.
	$host_span_css  = '.page-grid{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:3rem}'
		. '@media (width>=1024px){.page-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.form-host,.bp\\:span-2{grid-column:span 2}}'
		. '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '.px-8{padding-left:2rem;padding-right:2rem}.py-4{padding-top:1rem;padding-bottom:1rem}'
		. '.bg-foreground{background-color:rgb(0,0,0)}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.tracking-wide{letter-spacing:.025em}';
	$host_span_html = '<style>' . $host_span_css . '</style><div class="page-grid"><div class="form-host bp:span-2"><form class="stack">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<div><label>Email *</label><input type="email" required></div>'
		. '<div><label>Project Type</label><select><option value="">Select a project type</option><option>Kitchen Renovation</option></select></div>'
		. '<div><label>Project Details *</label><textarea rows="5" required></textarea></div>'
		. '<button type="submit" class="bg-foreground px-8 py-4 font-semibold uppercase tracking-wide">Submit</button>'
		. '</form></div><aside>contact</aside></div>';
	$host_span_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $host_span_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	if ( is_array( $host_span_source['binding'] ?? null ) && is_string( $host_span_source['binding']['search_block_markup'] ?? null ) ) {
		$host_span_source['bindings'] = array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'source_path'         => 'contact.html',
				'search_block_markup' => $host_span_source['binding']['search_block_markup'],
				'occurrence'          => 1,
				'role'                => 'form',
			),
		);
	}
	$host_span_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $host_span_source ) ) );
	$host_span_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $host_span_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$host_span_markup    = (string) ( $host_span_row['block_markup'] ?? '' );
	$host_span_css_out   = (string) ( $host_span_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $host_span_validated['errors'] ), 'host-wrapper-span-manifest-validates', wp_json_encode( $host_span_validated ) );
	$assert(
		'mapped' === ( $host_span_row['status'] ?? '' )
			&& empty( $host_span_row['form_receipt_unaccepted_losses'] ?? array() )
			&& 2 === substr_count( $host_span_markup, '"width":50' )
			&& str_contains( $host_span_markup, 'form-host' )
			&& str_contains( $host_span_markup, 'bp:span-2' )
			&& in_array( 'provider_host_wrapper_class_projection', array_column( $host_span_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& str_contains( $host_span_css_out, 'padding-top:1rem' )
			&& str_contains( $host_span_css_out, 'padding-right:2rem' )
			&& str_contains( $host_span_css_out, 'padding-bottom:1rem' )
			&& str_contains( $host_span_css_out, 'padding-left:2rem' )
			&& str_contains( $host_span_css_out, 'font-weight:600' )
			&& str_contains( $host_span_css_out, 'letter-spacing:.025em' )
			&& str_contains( $host_span_css_out, 'text-transform:uppercase' )
			&& 2 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.75rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.75rem\);margin-block-start:0!important\}/', $host_span_css_out )
			&& ! str_contains( $host_span_markup, 'wp:group' ),
		'provider-container-inherits-replaced-host-wrapper-column-span-and-authored-submit-box',
		wp_json_encode( array( 'row' => $host_span_row, 'markup' => $host_span_markup, 'css' => $host_span_css_out, 'binding' => $host_span_source['binding'] ?? null ) )
	);
	$host_span_runtime = Static_Site_Importer_Form_Seeder::project_provider_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form ssi-form-123456789abc form-host">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'stack form-host ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $host_span_runtime, 'class="jetpack-contact-form-container stack form-host ssi-form-123456789abc"' )
			&& str_contains( $host_span_runtime, 'wp-block-jetpack-contact-form ssi-form-123456789abc form-host' ),
		'provider-runtime-hoists-the-form-box-layout-role-onto-the-jetpack-page-grid-item',
		$host_span_runtime
	);
	$field_list_hoist = Static_Site_Importer_Form_Seeder::project_provider_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form panel grid gap-6 sm:grid-cols-2 ssi-form-123456789abc">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'panel grid gap-6 sm:grid-cols-2 ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $field_list_hoist, 'class="jetpack-contact-form-container panel ssi-form-123456789abc"' )
			&& str_contains( $field_list_hoist, 'wp-block-jetpack-contact-form panel grid gap-6 sm:grid-cols-2 ssi-form-123456789abc' ),
		'provider-runtime-keeps-field-list-grid-classes-off-the-page-item',
		$field_list_hoist
	);
	$card_chrome_hoist = Static_Site_Importer_Form_Seeder::project_provider_form_container_placement(
		'<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form space-y-5 rounded-lg border bg-card p-7 ssi-form-123456789abc">fields</div></form></div>',
		array( 'attrs' => array( 'className' => 'space-y-5 rounded-lg border bg-card p-7 ssi-form-123456789abc' ) )
	);
	$assert(
		str_contains( $card_chrome_hoist, 'class="jetpack-contact-form-container space-y-5 rounded-lg border bg-card p-7 ssi-form-123456789abc"' )
			&& str_contains( $card_chrome_hoist, 'wp-block-jetpack-contact-form space-y-5 ssi-form-123456789abc' )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\bp-7\b/', $card_chrome_hoist )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\brounded-lg\b/', $card_chrome_hoist )
			&& ! preg_match( '/wp-block-jetpack-contact-form[^"]*\bbg-card\b/', $card_chrome_hoist ),
		'provider-runtime-keeps-card-chrome-off-the-inner-field-list',
		$card_chrome_hoist
	);
	// In-form heading + a nested `grid sm:grid-cols-2` name/phone row. The heading
	// is copy inside the form (producer `context_before`), so it is an inner block
	// of jetpack/contact-form rather than a page-grid sibling. The row maps through
	// provider_equal_width_fields onto Jetpack `width: 50`; its grid classes must
	// not be copied onto the form container.
	$in_form_css  = '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}.rounded-lg{border-radius:.5rem}.border{border-width:1px}.p-7{padding:1.75rem}'
		. '.grid{display:grid}.gap-5{gap:1.25rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$in_form_html = '<style>' . $in_form_css . '</style><div class="page-grid"><section class="intro"><h1>Contact</h1></section>'
		. '<form class="space-y-5 rounded-lg border bg-card p-7">'
		. '<h2 class="font-serif text-2xl text-card-foreground">Send a message</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Your name</span><input required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '</div>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Subject</span><input required></label>'
		. '<label class="block"><span>Message</span><textarea rows="5" required></textarea></label>'
		. '<button type="submit">Send message</button>'
		. '</form></div>';
	$in_form_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $in_form_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	if ( is_array( $in_form_source['binding'] ?? null ) && is_string( $in_form_source['binding']['search_block_markup'] ?? null ) ) {
		$in_form_source['bindings'] = array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'source_path'         => 'contact.html',
				'search_block_markup' => $in_form_source['binding']['search_block_markup'],
				'occurrence'          => 1,
				'role'                => 'form',
			),
		);
	}
	$in_form_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $in_form_source ) ) );
	$in_form_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $in_form_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$in_form_markup    = (string) ( $in_form_row['block_markup'] ?? '' );
	$in_form_parsed    = array_values( array_filter( parse_blocks( $in_form_markup ), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$in_form_contact   = $in_form_parsed[0] ?? array();
	$in_form_inners    = array_values( array_filter( $in_form_contact['innerBlocks'] ?? array(), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$in_form_attrs     = is_array( $in_form_contact['attrs'] ?? null ) ? $in_form_contact['attrs'] : array();
	$in_form_class     = (string) ( $in_form_attrs['className'] ?? '' );
	$in_form_ops       = array_column( $in_form_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $in_form_validated['errors'] ) && 'mapped' === ( $in_form_row['status'] ?? '' ), 'in-form-heading-and-field-row-manifest-validates', wp_json_encode( $in_form_validated ) );
	$assert(
		1 === count( $in_form_parsed )
			&& 'jetpack/contact-form' === ( $in_form_contact['blockName'] ?? '' )
			&& 'core/heading' === ( $in_form_inners[0]['blockName'] ?? '' )
			&& 'Send a message' === trim( wp_strip_all_tags( (string) ( $in_form_inners[0]['innerHTML'] ?? '' ) ) )
			&& ( '' === (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' )
				|| ( (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' ) === ( $in_form_inners[0]['attrs']['className'] ?? '' )
					&& str_contains( (string) ( $in_form_inners[0]['innerHTML'] ?? '' ), 'class="wp-block-heading ' . (string) ( $in_form_source['form']['context_before'][0]['class'] ?? '' ) . '"' ) ) )
			&& ! preg_match( '/<!-- wp:heading[\s\S]*<!-- wp:jetpack\/contact-form /', $in_form_markup )
			&& 2 === substr_count( $in_form_markup, '"width":50' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50/', $in_form_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{[^}]*"width":50/', $in_form_markup )
			&& in_array( 'provider_equal_width_fields', $in_form_ops, true )
			&& ! preg_match( '/(?:^|\s)grid(?:\s|$)/', $in_form_class )
			&& ! str_contains( $in_form_class, 'grid-cols-2' )
			&& str_contains( $in_form_class, 'space-y-5' )
			&& $in_form_markup === serialize_blocks( parse_blocks( $in_form_markup ) ),
		'in-form-heading-stays-inside-contact-form-and-name-phone-use-jetpack-width-not-host-grid',
		wp_json_encode(
			array(
				'ops'     => $in_form_ops,
				'class'   => $in_form_class,
				'inners'  => array_column( $in_form_inners, 'blockName' ),
				'markup'  => $in_form_markup,
				'context' => $in_form_source['form']['context_before'] ?? null,
			)
		)
	);
	$bare_row_html = '<form class="space-y-5"><h2>Send a message</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Your name</span><input required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '</div>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<button type="submit">Send</button></form>';
	$bare_row_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $bare_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$bare_row_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $bare_row_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$bare_row_markup = (string) ( $bare_row_row['block_markup'] ?? '' );
	$bare_row_class  = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $bare_row_markup, $bare_row_attrs ) ) {
		$bare_row_decoded = json_decode( $bare_row_attrs[1], true );
		$bare_row_class   = is_array( $bare_row_decoded ) ? (string) ( $bare_row_decoded['className'] ?? '' ) : '';
	}
	$assert(
		'mapped' === ( $bare_row_row['status'] ?? '' )
			&& 2 === substr_count( $bare_row_markup, '"width":50' )
			&& in_array( 'provider_equal_width_fields', array_column( $bare_row_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true )
			&& ! preg_match( '/(?:^|\s)grid(?:\s|$)/', $bare_row_class )
			&& ! str_contains( $bare_row_class, 'grid-cols-2' ),
		'class-token-grid-row-without-layout-node-still-maps-to-jetpack-field-width',
		wp_json_encode( array( 'row' => $bare_row_row, 'class' => $bare_row_class, 'markup' => $bare_row_markup ) )
	);
	// One 2-column grid holding eight fields (four visual rows) plus two full-width
	// fields outside it. Column count comes from the resolved track list, not from
	// sibling count, so every grid child materializes at Jetpack `width: 50`.
	$multi_row_css  = '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}'
		. '.grid{display:grid}.gap-5{gap:1.25rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	$multi_row_html = '<style>' . $multi_row_css . '</style><form class="space-y-5">'
		. '<h2>Member details</h2>'
		. '<div class="grid gap-5 sm:grid-cols-2">'
		. '<label class="block"><span>Full name</span><input required></label>'
		. '<label class="block"><span>Father name</span><input required></label>'
		. '<label class="block"><span>CNIC number</span><input required></label>'
		. '<label class="block"><span>Date of birth</span><input type="date" required></label>'
		. '<label class="block"><span>Mobile number</span><input type="tel" required></label>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Membership type</span><select required><option>Ordinary member</option></select></label>'
		. '<label class="block"><span>Family members</span><input type="number" min="1" max="50" required></label>'
		. '</div>'
		. '<label class="block"><span>Residential address</span><textarea rows="3" required></textarea></label>'
		. '<label class="block"><span>Occupation</span><input></label>'
		. '<button type="submit">Submit registration</button>'
		. '</form>';
	$multi_row_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'membership.html', 'files' => array( 'membership.html' => $multi_row_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$multi_row_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $multi_row_source ) ) );
	$multi_row_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $multi_row_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$multi_row_markup    = (string) ( $multi_row_row['block_markup'] ?? '' );
	$multi_row_css_out   = (string) ( $multi_row_row['provider_layout_overlay_css']['css'] ?? '' );
	$multi_row_ops       = array_column( $multi_row_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $multi_row_validated['errors'] ) && 'mapped' === ( $multi_row_row['status'] ?? '' ), 'multi-row-equal-column-grid-manifest-validates', wp_json_encode( $multi_row_validated ) );
	$assert(
		10 === ( $multi_row_row['field_count'] ?? 0 )
			&& 8 === substr_count( $multi_row_markup, '"width":50' )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-text \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-telephone \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-email \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-select \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-number \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-date \{[^}]*"width":50/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-date[\s\S]*?<!-- wp:jetpack\/input \{"style":\{"border":\{"style":"solid"\}\},"className":"ssi-node-[a-f0-9]{12}"\} \/\-->/', $multi_row_markup )
			&& 1 === preg_match( '/<!-- wp:jetpack\/field-textarea \{(?![^}]*"width":50)/', $multi_row_markup )
			&& in_array( 'provider_equal_width_fields', $multi_row_ops, true )
			&& 8 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap\{width:calc\(50% - 0\.625rem\);flex-grow:0;flex-shrink:0;flex-basis:calc\(50% - 0\.625rem\);margin-block-start:0!important\}/', $multi_row_css_out )
			&& $multi_row_markup === serialize_blocks( parse_blocks( $multi_row_markup ) ),
		'eight-field-two-column-grid-emits-jetpack-width-50-on-every-grid-child',
		wp_json_encode( array( 'ops' => $multi_row_ops, 'markup' => $multi_row_markup, 'css' => $multi_row_css_out, 'count' => substr_count( $multi_row_markup, '"width":50' ) ) )
	);
	// Real Tailwind v4 captures often keep `display:grid` as a class token while
	// layered `sm:grid-cols-2` never becomes a layout-graph node. Sibling count
	// is not column count: eight field boxes in that grid are still two columns.
	$class_only_html = '<form class="space-y-5">'
		. '<div class="grid gap-5">'
		. '<label class="block"><span>Full name</span><input required></label>'
		. '<label class="block"><span>Father name</span><input required></label>'
		. '<label class="block"><span>CNIC</span><input required></label>'
		. '<label class="block"><span>Date of birth</span><input type="date" required></label>'
		. '<label class="block"><span>Mobile</span><input type="tel" required></label>'
		. '<label class="block"><span>Email</span><input type="email"></label>'
		. '<label class="block"><span>Type</span><select required><option>Ordinary</option></select></label>'
		. '<label class="block"><span>Family</span><input type="number" required></label>'
		. '</div>'
		. '<label class="block"><span>Address</span><textarea rows="3" required></textarea></label>'
		. '<label class="block"><span>Occupation</span><input></label>'
		. '<button type="submit">Submit</button></form>';
	$class_only_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'membership.html', 'files' => array( 'membership.html' => $class_only_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$class_only_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $class_only_source ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$class_only_markup = (string) ( $class_only_row['block_markup'] ?? '' );
	$assert(
		'mapped' === ( $class_only_row['status'] ?? '' )
			&& 8 === substr_count( $class_only_markup, '"width":50' )
			&& in_array( 'provider_equal_width_fields', array_column( $class_only_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ),
		'class-token-grid-with-eight-field-children-still-maps-to-jetpack-width-50',
		wp_json_encode( array( 'markup' => $class_only_markup, 'topo' => $class_only_source['control_topology']['nodes'][0]['class'] ?? null, 'layout' => $class_only_source['layout_graph'] ?? null ) )
	);
	// A narrowing (max-width) variant is not the proven mobile-first widening
	// shape and must keep the existing decline: Jetpack cannot represent "two
	// columns by default that collapse to one," and this is not that either.
	$narrowing_grid_css      = '.grid{display:grid}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width<768px){.sm\\:grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}}';
	$narrowing_grid_html     = '<style>' . $narrowing_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-2 sm:grid-cols-1 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$narrowing_grid_source   = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $narrowing_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$narrowing_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $narrowing_grid_source ) ) );
	$narrowing_grid_row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $narrowing_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		'skipped' === ( $narrowing_grid_row['status'] ?? '' )
			&& 'form_receipt_loss_unaccepted' === ( $narrowing_grid_row['reason'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', array_column( $narrowing_grid_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'narrowing-max-width-grid-variant-keeps-the-wrapper-layout-decline',
		wp_json_encode( $narrowing_grid_row )
	);
	// Two variants on the same row (e.g. a third breakpoint changing the track
	// count again) is outside the single proven widening this rule accepts.
	$two_variant_grid_css    = '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-6{gap:1.5rem}'
		. '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media (width>=1024px){.lg\\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}}';
	$two_variant_grid_html   = '<style>' . $two_variant_grid_css . '</style><form class="space-y-6">'
		. '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">'
		. '<div><label>Name *</label><input type="text" required></div>'
		. '<div><label>Phone *</label><input type="tel" required></div>'
		. '</div>'
		. '<button type="submit">Submit</button>'
		. '</form>';
	$two_variant_grid_source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $two_variant_grid_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$two_variant_grid_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $two_variant_grid_source ) ) );
	$two_variant_grid_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $two_variant_grid_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		'skipped' === ( $two_variant_grid_row['status'] ?? '' )
			&& in_array( 'provider_wrapper_layout_unrepresentable', array_column( $two_variant_grid_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' ), true ),
		'a-second-breakpoint-changing-the-track-count-again-keeps-the-wrapper-layout-decline',
		wp_json_encode( $two_variant_grid_row )
	);
	// A source that deliberately sizes two textareas differently through their own
	// `rows` attribute - rather than an authored CSS height a cascade compiler could
	// capture - must not materialize both onto this provider's one fixed default;
	// each field's own row count must reach the rendered textarea.
	$few_rows_form = $topology_form;
	$few_rows_form['forms'][0]['controls'][2]['rows'] = '3';
	$few_rows_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $few_rows_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$many_rows_form = $topology_form;
	$many_rows_form['forms'][0]['controls'][2]['rows'] = '6';
	$many_rows_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $many_rows_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		str_contains( (string) ( $few_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-3' )
			&& str_contains( (string) ( $many_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-6' )
			&& ! str_contains( (string) ( $few_rows_row['block_markup'] ?? '' ), 'ssi-textarea-rows-6' )
			&& str_contains( (string) ( $few_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' )
			&& str_contains( (string) ( $many_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:auto' )
			&& ! str_contains( (string) ( $few_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' )
			&& ! str_contains( (string) ( $many_rows_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:200px' ),
		'distinctly-authored-textarea-row-counts-materialize-onto-the-rendered-control-instead-of-one-provider-default',
		wp_json_encode( array( 'few' => $few_rows_row['block_markup'] ?? null, 'many' => $many_rows_row['block_markup'] ?? null ) )
	);
	$jetpack_textarea = "<div class=\"grunion-field-textarea-wrap\"><textarea\n\t\t                style=''\n\t\t                name='message'\n\t\t                id='contact-form-comment-message'\n\t\t                rows='20'\n\t\t                class='textarea ssi-textarea-rows-6'></textarea></div>";
	$projected_rows   = Static_Site_Importer_Form_Seeder::project_provider_textarea_rows( $jetpack_textarea );
	$assert(
		str_contains( $projected_rows, "rows='6'" )
			&& ! str_contains( $projected_rows, "rows='20'" )
			&& ! str_contains( $projected_rows, 'ssi-textarea-rows-' ),
		'provider-runtime-rewrites-jetpack-textarea-rows-20-to-the-authored-count',
		$projected_rows
	);
	$projected_rows_via_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $jetpack_textarea );
	$assert(
		str_contains( $projected_rows_via_wrapper, "rows='6'" )
			&& ! str_contains( $projected_rows_via_wrapper, "rows='20'" )
			&& ! str_contains( $projected_rows_via_wrapper, 'ssi-textarea-rows-' ),
		'provider-wrapper-projection-also-rewrites-authored-textarea-rows',
		$projected_rows_via_wrapper
	);
	// A source textarea sized by rows="6" plus captured padding/font/line-height
	// (12px / 14px / 20px) must keep that row count on the materialized control
	// so the browser can size 6×20 + 12+12 + 1+1 = 146px instead of Jetpack's
	// rows="20" at 200px or a provider-default overlay of 178px.
	$authored_rows_html = '<style>textarea{box-sizing:border-box;padding:12px;font-size:14px;line-height:20px;border:1px solid}</style><form>'
		. '<div><label>Message</label><textarea rows="6" required></textarea></div>'
		. '<button type="submit">Send</button></form>';
	$authored_rows_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $authored_rows_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$authored_rows_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $authored_rows_source ) ) );
	$authored_rows_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_rows_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_rows_markup    = (string) ( $authored_rows_row['block_markup'] ?? '' );
	$authored_rows_css       = (string) ( $authored_rows_row['provider_layout_overlay_css']['css'] ?? '' );
	$authored_rows_rendered  = Static_Site_Importer_Form_Seeder::project_provider_textarea_rows(
		"<textarea rows='20' class='textarea ssi-textarea-rows-6'></textarea>"
	);
	$assert(
		empty( $authored_rows_validated['errors'] )
			&& 'mapped' === ( $authored_rows_row['status'] ?? '' )
			&& str_contains( $authored_rows_markup, 'ssi-textarea-rows-6' )
			&& str_contains( $authored_rows_css, 'height:auto' )
			&& ! str_contains( $authored_rows_css, 'height:178px' )
			&& ! str_contains( $authored_rows_css, 'height:200px' )
			&& str_contains( $authored_rows_rendered, "rows='6'" )
			&& ! str_contains( $authored_rows_rendered, "rows='20'" ),
		'compiled-textarea-rows-6-reaches-the-rendered-control-with-provider-height-neutralized',
		wp_json_encode( array( 'errors' => $authored_rows_validated['errors'] ?? array(), 'markup' => $authored_rows_markup, 'css' => $authored_rows_css, 'rendered' => $authored_rows_rendered, 'source' => $authored_rows_source['controls'][0] ?? null ) )
	);
	// A cascade-resolved height or minimum height already captured for this textarea
	// is authoritative; the row-count height override above only fills what it omits.
	$authored_textarea_height_form = $topology_form;
	$authored_textarea_height_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array( array( 'index' => 2, 'control' => array( 'styles' => array( 'height' => '9rem' ), 'provenance' => array() ) ) ),
	);
	$authored_textarea_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_textarea_height_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert(
		str_contains( (string) ( $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:9rem' )
			&& ! str_contains( (string) ( $authored_textarea_height_row['provider_layout_overlay_css']['css'] ?? '' ), 'height:82px' ),
		'source-cascade-resolved-textarea-height-is-authoritative-over-the-row-count-fallback',
		wp_json_encode( $authored_textarea_height_row['provider_layout_overlay_css'] ?? null )
	);
	$direct_label_form = array(
		'forms' => array( array(
			'selector' => 'form.direct-labels',
			'form' => array(),
			'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ), array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 16, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
			'sibling_relations' => array( 'schema' => 'generic/form-sibling-relations/v1', 'max_pairs' => 128, 'truncated' => false, 'pairs' => array( array( 'control' => 0 ), array( 'control' => 1 ) ) ),
			'layout_graph' => $v2_layout_graph( array( array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'direct-labels' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => '1.2rem' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.direct-labels', 'condition' => null, 'properties' => array( 'display', 'flex-direction', 'gap' ) ) ) ) ) ),
		) ),
	);
	$direct_label_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $direct_label_form );
	$direct_label_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $direct_label_validation['forms'] ?? array() ) );
	$direct_label_css = (string) ( $direct_label_seed['forms'][0]['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $direct_label_validation['errors'] ) && 3 === substr_count( $direct_label_css, 'gap:1.2rem' ) && 2 === preg_match_all( '/\.ssi-node-[a-f0-9]{12}-wrap\{display:flex;flex-direction:column;gap:1\.2rem\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__input-error:not\(\.has-errors\)\{display:none\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__field-hints\{display:contents\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.contact-form__field-format\{display:none\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.ssi-field-row > label\{margin-block-end:0\}/', $direct_label_css ) && 1 === preg_match_all( '/\.ssi-form-[a-f0-9]{12} \.grunion-field-wrap \.grunion-field::placeholder\{color:revert\}/', $direct_label_css ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $direct_label_seed['forms'][0]['provider_layout_overlay_css'] ?? null ), 'proven direct label/control sibling pairs preserve native placeholder appearance and suppress only inactive provider errors' );
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
	// A source field-group grid that groups every mapped control except a
	// submit sitting outside it (a submit is always the field container's
	// sibling in Jetpack's own rendering, never its descendant, so requiring
	// an exact match against every mapped control - submit included - made a
	// perfectly representable field-group box an unconditional loss) is now
	// transposed onto the form element, and the exempted submit is told to
	// span every column that grid produces instead of being auto-placed into
	// just one of them. The captured responsive condition and grid track list
	// use the exact shapes a real Tailwind v4 stylesheet compiles to: a
	// `width >= Nrem` media range (not the legacy `min-width:` prefix), a
	// `repeat(N, minmax(0, 1fr))` track list, and a leading-dot decimal inside
	// `calc()` - proving the overlay's value grammar admits all three.
	$grid_condition        = array( 'kind' => 'media', 'query' => '(width>=40rem)' );
	$grid_box_form         = array(
		'forms' => array( array(
			'selector'         => 'form.grid-fields',
			'controls'         => array(
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'label' => 'First' ),
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'second', 'label' => 'Second' ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
			'control_topology' => array(
				'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
				'nodes'  => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'fields' ),
					array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'label', 'class' => 'field' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
					array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'label', 'class' => 'field' ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'control' => 1 ),
					array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
				),
			),
			'layout_graph'     => $v2_layout_graph( array(
				array( 'id' => 'form', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'form', 'classes' => array( 'grid-fields' ) ), 'layout' => array(), 'provenance' => array() ),
				array(
					'id'         => 'wrapper-0',
					'kind'       => 'container',
					'parent'     => 'form',
					'order'      => 0,
					'source'     => array( 'tag' => 'div', 'classes' => array( 'fields' ) ),
					// Base facts are unconditional (Tailwind's own `grid gap-5`,
					// present at every width); only the column count is
					// conditional (`sm:grid-cols-2`), exactly like a real
					// Tailwind v4 responsive field-group grid.
					'layout'     => array( 'display' => 'grid', 'gap' => 'calc(.25rem * 5)' ),
					'provenance' => array(
						array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.fields', 'condition' => null, 'properties' => array( 'display', 'gap' ) ),
					),
				),
			) ),
		) ),
	);
	$grid_box_form['forms'][0]['layout_graph']['variants'][] = array(
		'node'         => 'wrapper-0',
		'condition'    => $grid_condition,
		'layout_patch' => array( 'columns' => 'repeat(2,minmax(0,1fr))' ),
		'precedence'   => array(
			'grid-template-columns' => array( 'source_order' => 1, 'specificity' => 10, 'important' => false ),
		),
		'provenance'   => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.sm\:grid-cols-2', 'condition' => $grid_condition, 'properties' => array( 'grid-template-columns' ) ) ),
	);
	$grid_box_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $grid_box_form );
	$grid_box_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $grid_box_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$grid_box_css        = (string) ( $grid_box_row['provider_layout_overlay_css']['css'] ?? '' );
	$grid_box_losses     = array_column( $grid_box_row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' );
	$assert(
		empty( $grid_box_validation['errors'] ) && 'mapped' === ( $grid_box_row['status'] ?? '' ) && true === ( $grid_box_row['runtime_mapped'] ?? false ) && ! in_array( 'provider_wrapper_layout_unrepresentable', $grid_box_losses, true ),
		'field-group-grid-missing-only-its-sibling-submit-is-a-representable-form-box',
		wp_json_encode( array( 'validation' => $grid_box_validation, 'row' => $grid_box_row ) )
	);
	$assert(
		null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $grid_box_row['provider_layout_overlay_css'] ?? null )
			&& str_contains( $grid_box_css, 'gap:calc(.25rem * 5)' )
			&& str_contains( $grid_box_css, '@media (width>=40rem){' )
			&& str_contains( $grid_box_css, 'grid-template-columns:repeat(2,minmax(0,1fr))' ),
		'form-box-grid-carries-modern-media-range-repeat-minmax-tracks-and-leading-dot-calc',
		$grid_box_css
	);
	$assert(
		str_contains( $grid_box_css, 'grid-column:1 / -1' ),
		'submit-exempted-from-its-field-groups-grid-spans-every-column-instead-of-one',
		$grid_box_css
	);
	$assert(
		str_contains( $grid_box_css, 'margin-block-start:calc(0px - (.25rem * 5))' )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - \(\.25rem \* 5\)\)/', $grid_box_css ),
		'submit-outside-a-gapped-field-list-cancels-the-transposed-gap-instead-of-adding-it-to-its-margin',
		$grid_box_css
	);
	$dup_gap_form = $grid_box_form;
	$dup_gap_form['forms'][0]['layout_graph']['nodes'][0]['layout']     = array( 'gap' => 'calc(.25rem * 5)' );
	$dup_gap_form['forms'][0]['layout_graph']['nodes'][0]['provenance'] = array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'd', 64 ), 'selector' => '.grid-fields', 'condition' => null, 'properties' => array( 'gap' ) ) );
	$dup_gap_css = (string) ( Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $dup_gap_form )['forms'] ?? array() ) )['forms'][0]['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - \(\.25rem \* 5\)\)/', $dup_gap_css ),
		'submit-still-cancels-a-field-list-gap-the-form-node-already-declares',
		$dup_gap_css
	);
	$sibling_submit_css  = '.grid{display:grid}.gap-6{gap:1.5rem}'
		. '@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '.mt-9{margin-top:2.25rem}';
	$sibling_submit_html = '<style>' . $sibling_submit_css . '</style><form>'
		. '<div class="grid gap-6 sm:grid-cols-2">'
		. '<div><label>Name</label><input type="text"></div>'
		. '<div><label>Email</label><input type="email"></div>'
		. '<div><label>Message</label><textarea rows="6"></textarea></div>'
		. '</div>'
		. '<button type="submit" class="mt-9">Send message</button>'
		. '</form>';
	$sibling_submit_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $sibling_submit_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$sibling_submit_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $sibling_submit_source ) ) );
	$sibling_submit_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $sibling_submit_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$sibling_submit_overlay   = (string) ( $sibling_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $sibling_submit_validated['errors'] )
			&& 'mapped' === ( $sibling_submit_row['status'] ?? '' )
			&& true === ( $sibling_submit_row['runtime_mapped'] ?? false )
			&& str_contains( $sibling_submit_overlay, 'gap:1.5rem' )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - 1\.5rem\)/', $sibling_submit_overlay )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*margin-top:2\.25rem/', $sibling_submit_overlay ),
		'source-sibling-submit-keeps-its-authored-margin-and-does-not-also-consume-the-field-list-gap',
		wp_json_encode( array( 'validation' => $sibling_submit_validated, 'css' => $sibling_submit_overlay, 'markup' => $sibling_submit_row['block_markup'] ?? '' ) )
	);
	$assert(
		str_contains( $sibling_submit_overlay, 'display:grid' ),
		'field-list-overlay-carries-authored-grid-display',
		$sibling_submit_overlay
	);
	$assert(
		str_contains( $grid_box_css, 'display:grid' ),
		'form-box-grid-overlay-carries-authored-display',
		$grid_box_css
	);
	$col_span_css  = '.grid{display:grid}.gap-6{gap:1.5rem}'
		. '@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\\:col-span-2{grid-column:span 2 / span 2}}';
	$col_span_html = '<style>' . $col_span_css . '</style><form class="panel p-7">'
		. '<div class="grid gap-6 sm:grid-cols-2">'
		. '<div><label>Nominee name</label><input type="text"></div>'
		. '<div><label>Category</label><select><option>One</option></select></div>'
		. '<div><label>Organisation</label><input type="text"></div>'
		. '<div><label>Your name</label><input type="text"></div>'
		. '<div class="sm:col-span-2"><label>Why this nomination</label><textarea rows="6"></textarea></div>'
		. '<div class="sm:col-span-2"><label>Supporting links</label><input type="text"></div>'
		. '</div>'
		. '<button type="submit" class="mt-9">Submit nomination</button>'
		. '</form>';
	$col_span_source    = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $col_span_html ) ) )->toArray() )['fallbacks'][0] ?? array();
	$col_span_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $col_span_source ) ) );
	$col_span_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $col_span_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$col_span_overlay   = (string) ( $col_span_row['provider_layout_overlay_css']['css'] ?? '' );
	$col_span_form_rule = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} > form\.jetpack-contact-form__form, \.ssi-form-[a-f0-9]{12}:not\(:has\(> form\.jetpack-contact-form__form\)\)\{([^}]+)\}/', $col_span_overlay, $col_span_form ) ? $col_span_form[1] : '';
	$assert(
		empty( $col_span_validated['errors'] )
			&& 'mapped' === ( $col_span_row['status'] ?? '' )
			&& true === ( $col_span_row['runtime_mapped'] ?? false )
			&& str_contains( $col_span_form_rule, 'display:grid' )
			&& str_contains( $col_span_form_rule, 'gap:1.5rem' )
			&& ! str_contains( $col_span_form_rule, 'grid-template-columns' ),
		'full-span-field-list-keeps-authored-display-and-leaves-column-tracks-to-the-source',
		wp_json_encode( array( 'validation' => $col_span_validated, 'css' => $col_span_overlay, 'form' => $col_span_form_rule, 'markup' => $col_span_row['block_markup'] ?? '' ) )
	);
	$class_display_form = $grid_box_form;
	$class_display_form['forms'][0]['layout_graph']['nodes'][1]['layout']     = array();
	$class_display_form['forms'][0]['layout_graph']['nodes'][1]['provenance'] = array();
	$class_display_form['forms'][0]['layout_graph']['variants']               = array();
	$class_display_form['forms'][0]['control_topology']['nodes'][0]['class']  = 'grid gap-6';
	$class_display_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $class_display_form );
	$class_display_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $class_display_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$class_display_css        = (string) ( $class_display_row['provider_layout_overlay_css']['css'] ?? '' );
	$class_display_markup     = (string) ( $class_display_row['block_markup'] ?? '' );
	$assert(
		empty( $class_display_validation['errors'] )
			&& 'mapped' === ( $class_display_row['status'] ?? '' )
			&& str_contains( $class_display_markup, 'grid gap-6 ssi-form-' )
			&& str_contains( $class_display_css, 'display:grid' )
			&& ! str_contains( $class_display_css, 'grid-template-columns' ),
		'class-only-field-list-display-outranks-the-provider-flex-default',
		wp_json_encode( array( 'css' => $class_display_css, 'markup' => $class_display_markup, 'row' => $class_display_row ) )
	);
	$flex_list_form = $grid_box_form;
	$flex_list_form['forms'][0]['layout_graph']['nodes'][1]['layout'] = array( 'display' => 'flex', 'direction' => 'column', 'gap' => '2rem' );
	$flex_list_form['forms'][0]['layout_graph']['nodes'][1]['provenance'][0]['properties'] = array( 'display', 'flex-direction', 'gap' );
	$flex_list_form['forms'][0]['layout_graph']['variants'] = array();
	$flex_list_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $flex_list_form )['forms'] ?? array() ) )['forms'][0] ?? array();
	$flex_list_css = (string) ( $flex_list_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		str_contains( $flex_list_css, 'display:flex' )
			&& str_contains( $flex_list_css, 'gap:2rem' )
			&& 1 === preg_match( '/\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{[^}]*margin-block-start:calc\(0px - 2rem\)/', $flex_list_css )
			&& ! str_contains( $flex_list_css, 'grid-column:1 / -1' ),
		'submit-outside-a-column-flex-field-list-also-cancels-the-transposed-gap',
		$flex_list_css
	);
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
	$unrelated_adjacent_phone_popup['controls'][0]['aria_haspopup'] = 'false';
	$unrelated_adjacent_phone_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $unrelated_adjacent_phone_popup ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	// A composite shell wrapping every mapped control except a submit that sits
	// outside it (Jetpack's own architecture: a submit is always the form's
	// sibling, never a field-group descendant) is now a representable form box,
	// so this materializes instead of loss-gating; the popup-supersession
	// exemption itself - the actual behavior under test - is unaffected.
	$assert( 'mapped' === ( $unrelated_adjacent_phone_row['status'] ?? '' ) && str_contains( (string) ( $unrelated_adjacent_phone_row['block_markup'] ?? '' ), 'type="button"' ) && ! in_array( 'provider_auxiliary_popup_control', array_column( $unrelated_adjacent_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'plain-button-with-aria-haspopup-false-remains-native-and-is-not-superseded', wp_json_encode( $unrelated_adjacent_phone_row ) );
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
	$assert( 'skipped' === ( $incompatible_mobile_phone_row['status'] ?? '' ) && str_contains( (string) ( $incompatible_mobile_phone_row['block_markup'] ?? '' ), 'type="button"' ) && ! in_array( 'provider_auxiliary_popup_control', array_column( $incompatible_mobile_phone_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'explicitly-incompatible-country-popup-remains-native-and-loss-gated', wp_json_encode( $incompatible_mobile_phone_row ) );
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
	$assert( empty( $validated_presentation['errors'] ) && str_contains( $presentation_css, 'background-color:transparent;border:0;padding:8px 0;font-size:16px;line-height:24px;font-family:revert' ) && ! str_contains( $presentation_css, 'line-height:24px;line-height:revert' ) && str_contains( $presentation_css, 'font-size:14px;font-weight:400;line-height:1.4;margin-bottom:8px' ) && str_contains( $presentation_css, 'background-color:rgb(254,126,3);color:#fff;border:0;border-radius:100px;padding:11px 15px;font-size:16px;font-family:inherit;line-height:inherit;min-height:0' ), 'bounded-form-presentation-transposes-control-label-and-submit-styles', $presentation_css );
	$native_line_height_form = $presentation_form;
	$native_line_height_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'padding' => '8px' ), array( 'padding' ), 'input' ) ) );
	$native_line_height_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_line_height_form );
	$native_line_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_line_height_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$native_default_css = (string) ( $native_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $native_line_height_validation['errors'] ) && str_contains( $native_default_css, 'padding:8px;font-family:revert;line-height:revert' ) && ! str_contains( $native_default_css, 'Arial' ), 'provider-native-typography-reverts-to-the-browser-default-when-the-source-omits-it', $native_default_css );
	$authored_typography_form = $native_line_height_form;
	$authored_typography_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'font_family' => 'Georgia', 'line_height' => '1.5' ), array( 'font_family', 'line_height' ), 'input' ) ) );
	$authored_typography_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_typography_form );
	$authored_typography_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_typography_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_typography_css = (string) ( $authored_typography_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_typography_validation['errors'] ) && str_contains( $authored_typography_css, 'font-family:Georgia;line-height:1.5' ) && ! str_contains( $authored_typography_css, 'font-family:Arial' ) && ! str_contains( $authored_typography_css, 'line-height:normal' ), 'authored-control-typography-overrides-provider-default-neutralization', $authored_typography_css );
	$native_submit_form = $presentation_form;
	$native_submit_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'padding' => '8px' ), array( 'padding' ), 'button' ) ) );
	$native_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_submit_form );
	$native_submit_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $native_submit_validation['errors'] ) && str_contains( (string) ( $native_submit_row['provider_layout_overlay_css']['css'] ?? '' ), 'padding:8px;font-family:inherit;line-height:inherit;min-height:0' ), 'provider-submit-unowned-typography-inherits-the-source-document-line-box', wp_json_encode( $native_submit_row ) );
	// A submit control is often a bare direct child of its source container,
	// unlike every other field, which sits inside its own wrapping box. A
	// sibling-stacking utility (Tailwind's `space-y-*`) that matches direct
	// children therefore captures a real vertical margin fact against the
	// button itself, even though the provider's own field gap already
	// reproduces that inter-sibling spacing structurally. The captured fact
	// must still be represented (no receipt loss for a real cascade match),
	// but an unconditional, later, `!important` reset must win the cascade
	// so the button's own wrapper does not carry that spacing twice.
	$submit_sibling_margin_form = $presentation_form;
	$submit_sibling_margin_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'margin_top' => 'calc(1.5rem * calc(1 - var(--tw-space-y-reverse)))', 'margin_bottom' => 'calc(1.5rem * var(--tw-space-y-reverse))' ), array( 'margin-top', 'margin-bottom' ), 'button' ) ) );
	$submit_sibling_margin_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_sibling_margin_form );
	$submit_sibling_margin_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_sibling_margin_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_sibling_margin_css        = (string) ( $submit_sibling_margin_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $submit_sibling_margin_validation['errors'] )
			&& array() === ( $submit_sibling_margin_row['form_receipt_unaccepted_losses'] ?? array() )
			&& str_contains( $submit_sibling_margin_css, 'margin-top:calc(1.5rem * calc(1 - var(--tw-space-y-reverse)))' )
			&& 1 === preg_match( '/> \.wp-block-button__link\{margin:0!important\}$/m', trim( $submit_sibling_margin_css ) )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $submit_sibling_margin_row['provider_layout_overlay_css'] ?? null ),
		'captured-submit-sibling-margin-is-represented-but-an-unconditional-important-reset-wins-the-cascade',
		wp_json_encode( array( 'css' => $submit_sibling_margin_css, 'losses' => $submit_sibling_margin_row['form_receipt_unaccepted_losses'] ?? array() ) )
	);
	$authored_submit_line_height_form = $presentation_form;
	$authored_submit_line_height_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'padding' => '16px', 'font_size' => '11.2px', 'line_height' => '16.8px' ), array( 'padding', 'font-size', 'line-height' ), 'button' ) ) );
	$authored_submit_line_height_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $authored_submit_line_height_form );
	$authored_submit_line_height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_submit_line_height_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_submit_line_height_css = (string) ( $authored_submit_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_submit_line_height_validation['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{padding:16px;font-size:11\.2px;line-height:16\.8px;font-family:inherit;min-height:0\}/', $authored_submit_line_height_css ) && ! str_contains( $authored_submit_line_height_css, 'line-height:16.8px;line-height:inherit' ), 'authored-submit-line-height-reaches-the-rendered-button-instead-of-a-provider-reset', $authored_submit_line_height_css );
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
	$assert( empty( $validated_all_controls['errors'] ) && 4 === count( $all_controls_hooks ) && empty( array_filter( $all_controls_hooks, static fn( string $hook ): bool => ! str_contains( $all_controls_markup, $hook ) || ! str_contains( $all_controls_css, '.' . $hook ) ) ) && str_contains( $all_controls_css, 'border:1px solid #111;padding:7px;font-family:revert;line-height:revert' ) && 1 === preg_match( '/\.ssi-node-[a-f0-9]{12} select\{border:2px solid #222!important;padding:8px!important;font-family:revert!important;line-height:revert!important\}/', $all_controls_css ) && str_contains( $all_controls_css, 'border:3px solid #333;min-height:9rem;font-family:revert;line-height:revert' ) && str_contains( $all_controls_css, 'background-color:#444;padding:9px 12px;font-family:inherit;line-height:inherit;min-height:0' ) && str_contains( $all_controls_css, '@media (max-width:48rem){' ) && str_contains( $all_controls_css, '> .wp-block-button__link{background-color:#444;padding:9px 12px;font-family:inherit;line-height:inherit;min-height:0}' ) && ! str_contains( $all_controls_css, 'control-shell' ) && ! str_contains( $all_controls_css, 'control-hook' ), 'presentation-overlay-reverts-unowned-typography-to-each-browser-native-controls', wp_json_encode( array( 'markup' => $all_controls_markup, 'css' => $all_controls_css, 'targets' => $all_controls_targets ) ) );
	$submit_width_form = $presentation_form;
	$submit_width_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'width' => '100%' ), array( 'width' ), 'button' ) ) );
	$submit_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_width_form );
	$submit_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( empty( $submit_width_validation['errors'] ) && preg_match( '/\.ssi-node-[a-f0-9]{12}\{width:100%\}/', (string) ( $submit_width_row['provider_layout_overlay_css']['css'] ?? '' ) ), 'source-submit-width-targets-the-wrapper-instead-of-its-shrink-wrapped-inner-button' );
	$submit_block_form = $presentation_form;
	$submit_block_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'display' => 'block' ), array( 'display' ), 'button' ) ) );
	$submit_block_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_block_form );
	$submit_block_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_block_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_block_css = (string) ( $submit_block_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $submit_block_validation['errors'] ) && 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}\{display:block\}/', $submit_block_css ) && ! str_contains( $submit_block_css, '> .wp-block-button__link{display:block}' ), 'source-block-submit-display-targets-the-core-button-wrapper-for-automatic-full-row-width', $submit_block_css );
	$positioned_submit_form = $presentation_form;
	$positioned_submit_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'display' => 'flex', 'inset' => '0', 'position' => 'absolute' ), array( 'display', 'inset', 'position' ), 'button' ) ) );
	$positioned_submit_form['forms'][0]['presentation_graph']['variants'] = array( array( 'index' => 3, 'role' => 'control', 'condition' => array( 'kind' => 'media', 'query' => '(max-width:768px)' ), 'style_patch' => array( 'display' => 'flex', 'inset' => '0', 'position' => 'absolute' ), 'precedence' => array( 'display' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ), 'inset' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ), 'position' => array( 'source_order' => 2, 'specificity' => 20, 'important' => false ) ), 'provenance' => array() ) );
	$positioned_submit_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $positioned_submit_form );
	$positioned_submit_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $positioned_submit_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$positioned_submit_css        = (string) ( $positioned_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $positioned_submit_validation['errors'] ) && 'mapped' === ( $positioned_submit_row['status'] ?? '' ) && true === ( $positioned_submit_row['runtime_mapped'] ?? false ) && empty( $positioned_submit_row['form_receipt_unaccepted_losses'] ?? array() ) && str_contains( $positioned_submit_css, '{display:flex}' ) && ! str_contains( $positioned_submit_css, 'inset:0' ) && ! str_contains( $positioned_submit_css, 'position:absolute' ), 'positioned-submit-maps-without-stretching-the-inner-button-over-the-form', $positioned_submit_css );
	$submit_min_width_form = $presentation_form;
	$submit_min_width_form['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 3, 'control' => $presentation_role( array( 'min_width' => '100%' ), array( 'min_width' ), 'button' ) ) );
	$submit_min_width_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_min_width_form );
	$submit_min_width_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $submit_min_width_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_min_width_css = (string) ( $submit_min_width_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $submit_min_width_validation['errors'] ) && preg_match( '/\.ssi-node-[a-f0-9]{12}\{min-width:100%\}/', $submit_min_width_css ) && ! str_contains( $submit_min_width_css, '> .wp-block-button__link{min-width:100%}' ), 'source-submit-min-width-targets-the-wrapper-instead-of-its-inner-button', $submit_min_width_css );
	// A producer can capture a submit button's own presentation as a bounded
	// per-control style rather than running it through the full source-CSS-cascade
	// presentation_graph compiler. Before this seam was wired up, that capture only
	// flagged the button with the ssi-provider-submit-presentation marker class -
	// the source's real background, text color, typography, and full-width intent
	// never reached the rendered button, so it fell back to a transparent,
	// default-sized wp-element-button. The seam must resolve this capture through
	// the same provider layout overlay every other captured control already uses.
	$submit_control_style_form = $topology_form;
	$submit_control_style_form['forms'][0]['controls'][3]['presentation'] = array(
		'style' => array(
			'width'      => '100%',
			'color'      => array(
				'background' => 'oklch(0.2689 0.0057 156.83)',
				'text'       => 'oklch(0.956 0.0115 84.58)',
			),
			'typography' => array(
				'fontSize'      => '12px',
				'fontWeight'    => '600',
				'letterSpacing' => '1.92px',
				'textTransform' => 'uppercase',
			),
			'border'     => array( 'radius' => '9999px' ),
			'spacing'    => array(
				'padding' => array(
					'top'    => '1rem',
					'right'  => '2rem',
					'bottom' => '1rem',
					'left'   => '2rem',
				),
			),
		),
	);
	$validated_submit_control_style = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_control_style_form );
	$submit_control_style_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_control_style['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_control_style_markup    = (string) ( $submit_control_style_row['block_markup'] ?? '' );
	$submit_control_style_css       = (string) ( $submit_control_style_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_control_style['errors'] )
			&& 'mapped' === ( $submit_control_style_row['status'] ?? '' )
			&& str_contains( $submit_control_style_markup, 'ssi-provider-submit-presentation' )
			&& str_contains( $submit_control_style_css, '> .wp-block-button__link{background-color:oklch(0.2689 0.0057 156.83);color:oklch(0.956 0.0115 84.58);font-size:12px;font-weight:600;letter-spacing:1.92px;text-transform:uppercase;border-radius:9999px;padding-top:1rem;padding-right:2rem;padding-bottom:1rem;padding-left:2rem;font-family:inherit;line-height:inherit;min-height:0}' )
			&& preg_match( '/\.ssi-node-[a-f0-9]{12}\{width:100%\}/', $submit_control_style_css )
			&& ! str_contains( $submit_control_style_css, '> .wp-block-button__link{width:100%' ),
		'source-submit-control-style-capture-resolves-through-the-provider-overlay-instead-of-an-inert-marker-class',
		wp_json_encode( array( 'markup' => $submit_control_style_markup, 'css' => $submit_control_style_css ) )
	);
	// The saved block still claims no style attribute of its own: the overlay
	// paints the button, so the saved markup keeps agreeing with core/button's
	// own save() output and the imported form stays clean in the editor.
	$submit_control_style_attrs = array();
	foreach ( parse_blocks( $submit_control_style_markup ) as $parsed_submit_control_style_form ) {
		$collect_submit_control_style = static function ( array $blocks, callable $collect ) use ( &$submit_control_style_attrs ): void {
			foreach ( $blocks as $parsed ) {
				if ( 'core/button' === ( $parsed['blockName'] ?? '' ) ) {
					$submit_control_style_attrs[] = $parsed['attrs'] ?? array();
				}
				$collect( $parsed['innerBlocks'] ?? array(), $collect );
			}
		};
		$collect_submit_control_style( array( $parsed_submit_control_style_form ), $collect_submit_control_style );
	}
	$assert( 1 === count( $submit_control_style_attrs ) && ! array_key_exists( 'style', $submit_control_style_attrs[0] ), 'source-submit-control-style-capture-still-claims-no-unrenderable-block-style-attribute', wp_json_encode( $submit_control_style_attrs ) );
	$submit_style_line_height_form = $submit_control_style_form;
	$submit_style_line_height_form['forms'][0]['controls'][3]['presentation']['style']['typography']['lineHeight'] = '16.8px';
	$validated_submit_style_line_height = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_style_line_height_form );
	$submit_style_line_height_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_style_line_height['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_style_line_height_css       = (string) ( $submit_style_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_style_line_height['errors'] )
			&& 1 === preg_match( '/> \.wp-block-button__link\{[^}]*line-height:16\.8px;[^}]*font-family:inherit;min-height:0\}/', $submit_style_line_height_css )
			&& ! str_contains( $submit_style_line_height_css, 'line-height:16.8px;line-height:inherit' ),
		'captured-submit-style-line-height-reaches-the-rendered-button-geometry',
		$submit_style_line_height_css
	);
	$submit_preflight_form = $submit_control_style_form;
	$submit_preflight_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(
			array(
				'index'   => 3,
				'control' => array(
					'styles'     => array( 'background_color' => 'rgb(0,0,0)', 'padding' => '0', 'padding_top' => '.75rem', 'font_weight' => 'inherit' ),
					'provenance' => array(),
				),
			),
		),
	);
	$validated_submit_preflight = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $submit_preflight_form );
	$submit_preflight_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_submit_preflight['forms'] ?? array() ) )['forms'][0] ?? array();
	$submit_preflight_css       = (string) ( $submit_preflight_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_submit_preflight['errors'] )
			&& str_contains( $submit_preflight_css, 'padding-top:1rem' )
			&& str_contains( $submit_preflight_css, 'padding-right:2rem' )
			&& str_contains( $submit_preflight_css, 'padding-bottom:1rem' )
			&& str_contains( $submit_preflight_css, 'padding-left:2rem' )
			&& str_contains( $submit_preflight_css, 'font-weight:600' )
			&& ! str_contains( $submit_preflight_css, 'padding:0' )
			&& ! str_contains( $submit_preflight_css, 'font-weight:inherit' )
			&& str_contains( $submit_preflight_css, 'background-color:oklch(0.2689 0.0057 156.83)' ),
		'authored-submit-style-wins-over-cascade-preflight-padding-and-weight-resets',
		$submit_preflight_css
	);
	foreach ( array( '' => 'inherit', 'font-weight:600;' => '600' ) as $source_weight => $expected_weight ) {
		$label_artifact = ( new $artifact_compiler() )->compile( array(
			'entrypoint' => 'index.html',
			'files' => array( 'index.html' => '<style>form{font-weight:400}label{font-size:15px;' . $source_weight . '}</style><form><label for="name">Driver name</label><input id="name" name="name"><button>Send</button></form>' ),
		) )->toArray();
		$label_manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $label_artifact['fallbacks'][0] ) ) );
		$label_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $label_manifest['forms'] ?? array() ) )['forms'][0] ?? array();
		$label_overlay = $label_row['provider_layout_overlay_css'] ?? null;
		$assert( empty( $label_manifest['errors'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $label_overlay ) && str_contains( $label_overlay['css'] ?? '', 'font-weight:' . $expected_weight ) && ( '' === $source_weight || ! str_contains( $label_overlay['css'] ?? '', 'font-weight:inherit' ) ), 'artifact-label-font-weight-preserves-' . $expected_weight );
	}
	$status_manifest = array( 'selector' => 'form', 'controls' => array( array( 'tag' => 'input', 'name' => 'name', 'type' => 'text' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Send' ) ), 'form' => array( 'trailing_status' => array( 'role' => 'status', 'id' => 'form-status', 'margin_top' => '0.5rem' ) ) );
	$status_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $status_manifest ) ) );
	$status_markup = $status_seed['forms'][0]['block_markup'] ?? '';
	$assert( str_contains( $status_markup, '<output id="form-status" class="wp-block-group" style="margin-top:0.5rem"></output>' ) && ! str_contains( $status_markup, 'wp:html' ), 'empty-source-status-retains-native-output-and-authored-spacing' );
	$editor_form = $presentation_form;
	$editor_form['forms'][0]['form']['container_presentation'] = array( 'schema' => 'generic/form-container-presentation/v1', 'styles' => array( 'max_width' => '500px', 'margin' => '0 auto', 'text_align' => 'left' ), 'provenance' => array(), 'variants' => array() );
	$editor_manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $editor_form );
	$editor_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $editor_manifest['forms'] ) )['forms'][0];
	$editor_css = $editor_row['provider_layout_overlay_css']['editor_css'] ?? '';
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $editor_row['provider_layout_overlay_css'] ) && str_contains( $editor_css, 'max-width:500px;margin:0 auto;text-align:left' ) && str_contains( $editor_css, ' > label{' ) && str_contains( $editor_css, 'font:-webkit-small-control;' ), 'editor-maps-source-form-box-labels-and-native-button-typography-through-validated-overlay', $editor_css );
	// The captured form box's own padding is the same kind of bounded source-CSS-cascade
	// evidence every other captured control already carries. It must reach the rendered
	// page, not only editor chrome, and it must still resolve when a captured field
	// wrapper (control_containers) is also present on the same presentation graph -
	// both destinations previously shared one loop variable, so the wrapper's own row
	// silently clobbered the form box's captured styles before this ever compiled.
	$container_padding_form = $topology_form;
	$container_padding_form['forms'][0]['form']['container_presentation'] = array( 'schema' => 'generic/form-container-presentation/v1', 'styles' => array( 'padding' => '36px' ), 'provenance' => array(), 'variants' => array() );
	$container_padding_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v2', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(),
		'visual_parts' => array(),
		'visual_groups' => array(),
		'control_containers' => array( array( 'index' => 0, 'source_selector' => '.field', 'styles' => array( 'background_color' => '#fff' ), 'provenance' => array() ) ),
	);
	$validated_container_padding = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $container_padding_form );
	$container_padding_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_container_padding['forms'] ?? array() ) )['forms'][0] ?? array();
	$container_padding_css       = (string) ( $container_padding_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert(
		empty( $validated_container_padding['errors'] )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $container_padding_row['provider_layout_overlay_css'] ?? null )
			&& preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12}\.jetpack-contact-form-container\{padding:36px\}/', $container_padding_css ),
		'captured-form-container-padding-reaches-the-rendered-page-alongside-a-captured-field-wrapper',
		wp_json_encode( array( 'css' => $container_padding_css, 'validation' => $validated_container_padding ) )
	);
	// A provider select is a wrapper nest: Jetpack parks input className on
	// `.contact-form__select-wrapper` and paints that wrapper, then independently
	// pads the nested `<select>`. The authored box must resolve to one node, the
	// inner control, while width stays on the wrapper so it does not shrink-wrap.
	$select_box_form = array(
		'forms' => array( array(
			'selector'           => 'form.select-field',
			'controls'           => array( array( 'tag' => 'select', 'type' => 'select', 'name' => 'kind', 'label' => 'Kind' ) ),
			'presentation_graph' => array(
				'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 32 ), 'variants' => array(), 'diagnostics' => array(),
				'controls' => array( array( 'index' => 0, 'control' => array( 'styles' => array( 'padding' => '12px 16px', 'border' => '1px solid #ccc', 'background' => '#fff', 'width' => '100%' ), 'provenance' => array() ) ) ),
			),
		) ),
	);
	$select_box_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $select_box_form );
	$select_box_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $select_box_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$select_box_css        = (string) ( $select_box_row['provider_layout_overlay_css']['css'] ?? '' );
	$select_inner_rule     = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12} select\{([^}]+)\}/', $select_box_css, $select_inner ) ? $select_inner[1] : '';
	$select_wrapper_rule   = 1 === preg_match( '/\.ssi-form-[a-f0-9]{12}\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}\{([^}]+)\}/', $select_box_css, $select_wrapper ) ? $select_wrapper[1] : '';
	$assert(
		empty( $select_box_validation['errors'] )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $select_box_row['provider_layout_overlay_css'] ?? null )
			&& str_contains( $select_inner_rule, 'padding:12px 16px' )
			&& str_contains( $select_inner_rule, 'border:1px solid #ccc' )
			&& str_contains( $select_inner_rule, 'background:#fff' )
			&& ! str_contains( $select_inner_rule, 'width:100%' )
			&& str_contains( $select_wrapper_rule, 'width:100%' )
			&& str_contains( $select_wrapper_rule, 'padding:0' )
			&& str_contains( $select_wrapper_rule, 'border:0' )
			&& ! str_contains( $select_wrapper_rule, 'padding:12px 16px' )
			&& ! str_contains( $select_wrapper_rule, 'border:1px solid #ccc' ),
		'authored-select-box-reaches-the-nested-control-once-and-width-stays-on-the-wrapper',
		wp_json_encode( array( 'css' => $select_box_css, 'inner' => $select_inner_rule, 'wrapper' => $select_wrapper_rule, 'validation' => $select_box_validation ) )
	);
	$compile_form = static function ( string $css ) use ( $artifact_compiler ): array {
		$compiled = ( new $artifact_compiler() )->compile( array(
			'entrypoint' => 'index.html',
			'files'      => array( 'index.html' => '<style>' . $css . '</style><form><input type="email" name="email"><button type="submit">Send</button></form>' ),
		) )->toArray();
		return $compiled['fallbacks'][0] ?? array();
	};
	$stretch_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column}}' );
	$stretch_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $stretch_source ) ) );
	$stretch_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $stretch_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$stretch_css    = (string) ( $stretch_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $stretch_form['errors'] ) && 2 === count( $stretch_source['control_topology']['nodes'] ?? array() ) && 1 === count( $stretch_source['layout_graph']['nodes'] ?? array() ) && str_contains( $stretch_css, '@media (min-width: 768px){.ssi-form-') && str_contains( $stretch_css, '{align-self:stretch}' ) && in_array( 'form_layout_intent_flex_stretch_submit', array_column( $stretch_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'artifact-form-responsive-column-stretch-targets-the-generated-submit-wrapper', $stretch_css );
	$inherited_submit_source = $compile_form( 'html{line-height:1.5}button{padding:16px;font-size:.7rem;width:100%;background:gold}' );
	$inherited_submit_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $inherited_submit_source ) ) );
	$inherited_submit_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $inherited_submit_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$inherited_submit_css    = (string) ( $inherited_submit_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $inherited_submit_form['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{[^}]*font-size:\.7rem;[^}]*font-family:inherit;line-height:inherit;min-height:0\}/', $inherited_submit_css ), 'document-inherited-submit-line-height-is-not-reverted-to-the-ua-normal-line-box', $inherited_submit_css );
	$authored_button_line_height_source = $compile_form( 'button{padding:16px;font-size:.7rem;line-height:16.8px;background:gold;color:#fff}' );
	$authored_button_line_height_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $authored_button_line_height_source ) ) );
	$authored_button_line_height_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $authored_button_line_height_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$authored_button_line_height_css    = (string) ( $authored_button_line_height_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $authored_button_line_height_form['errors'] ) && 1 === preg_match( '/> \.wp-block-button__link\{[^}]*line-height:16\.8px;/', $authored_button_line_height_css ) && ! str_contains( $authored_button_line_height_css, 'line-height:16.8px;line-height:inherit' ), 'artifact-authored-submit-line-height-reaches-the-rendered-button-geometry', $authored_button_line_height_css );
	$center_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column;align-items:center}}' );
	$center_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $center_source ) ) );
	$center_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $center_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! str_contains( (string) ( $center_row['provider_layout_overlay_css']['css'] ?? '' ), 'align-self:stretch' ), 'artifact-form-align-items-center-does-not-stretch-submit' );
	$self_source = $compile_form( '@media (min-width: 768px){form{display:flex;flex-direction:column}button{align-self:flex-start}}' );
	$self_form   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $self_source ) ) );
	$self_row    = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $self_form['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( ! str_contains( (string) ( $self_row['provider_layout_overlay_css']['css'] ?? '' ), 'align-self:stretch' ), 'artifact-form-align-self-override-does-not-stretch-submit' );
	$phone_presentation = $presentation_form;
	$phone_presentation['forms'][0]['controls'][0]['type'] = 'phone';
	$phone_presentation['forms'][0]['presentation_graph']['controls'] = array( array( 'index' => 0, 'control' => $presentation_role( array( 'background_color' => '#fff', 'border_color' => '#1e4b6e', 'border_radius' => '0', 'font_size' => '16px', 'line_height' => '24px', 'padding_block_start' => '8px', 'padding_block_end' => '8px', 'padding_inline_start' => '8px', 'padding_inline_end' => '8px', 'height' => '40px' ), array( 'background-color', 'border-color', 'border-radius', 'font-size', 'line-height', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end', 'height' ), 'input' ) ) );
	$phone_presentation['forms'][0]['presentation_graph']['controls'][0]['control']['styles']['text_indent'] = '4px';
	$validated_phone_presentation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_presentation );
	$phone_presentation_row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_phone_presentation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_presentation_css       = (string) ( $phone_presentation_row['provider_layout_overlay_css']['css'] ?? '' );
	$phone_presentation_target    = $phone_presentation_row['provider_layout_target_map']['presentation_targets'][0] ?? array();
	$phone_destinations = $phone_presentation_target['destinations'] ?? array();
	$phone_markup = (string) ( $phone_presentation_row['block_markup'] ?? '' );
	$phone_destination_hooks = array();
	foreach ( $phone_destinations as $destination ) {
		if ( is_array( $destination ) && preg_match( '/\.((?:ssi-node)-[a-f0-9]{12}-destination-(?:shell|primary|carrier|prefix))$/', (string) ( $destination['selector'] ?? '' ), $matches ) ) {
			$phone_destination_hooks[] = $matches[1];
		}
	}
	$assert( '0' === ( $phone_destinations[0]['resets']['text-indent'] ?? null ) && '0' === ( $phone_destinations[0]['resets']['gap'] ?? null ) && str_contains( $phone_presentation_css, 'text-indent:0!important' ) && str_contains( $phone_presentation_css, 'gap:0!important' ) && str_contains( $phone_presentation_css, 'text-indent:4px!important' ), 'phone-text-indentation-belongs-to-value-not-structural-prefix-container' );
	$assert( null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $phone_presentation_row['provider_layout_overlay_css'] ?? array() ), 'composite-provider-destination-overlay-survives-stylesheet-admission' );
	$assert( empty( $validated_phone_presentation['errors'] ) && 4 === count( $phone_destinations ) && empty( $phone_destinations[0]['properties'] ) && str_contains( (string) ( $phone_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && str_contains( (string) ( $phone_destinations[3]['selector'] ?? '' ), '-destination-prefix' ) && 'flex' === ( $phone_destinations[3]['resets']['display'] ?? null ) && 'center' === ( $phone_destinations[3]['resets']['align-items'] ?? null ) && '100%' === ( $phone_destinations[3]['resets']['height'] ?? null ) && str_contains( $phone_presentation_css, 'background-color:#fff!important' ) && str_contains( $phone_presentation_css, 'border-color:#1e4b6e!important' ) && str_contains( $phone_presentation_css, 'padding-block-start:8px!important' ) && str_contains( $phone_presentation_css, 'padding-inline-end:8px!important' ) && str_contains( $phone_presentation_css, 'padding:0!important;border:0!important;background:transparent!important;text-indent:0!important;gap:0!important' ) && str_contains( $phone_presentation_css, 'display:flex!important;align-items:center!important;height:100%!important' ), 'phone-presentation-keeps-input-styles-on-value-and-neutralizes-provider-added-shell', wp_json_encode( array( 'css' => $phone_presentation_css, 'target' => $phone_presentation_target ) ) );
	$assert( 4 === count( $phone_destination_hooks ) && empty( array_filter( $phone_destination_hooks, static fn( string $hook ): bool => ! str_contains( $phone_markup, $hook ) ) ), 'phone-markup-hooks-and-overlay-destinations-share-one-prepared-calculation', wp_json_encode( array( 'markup' => $phone_markup, 'hooks' => $phone_destination_hooks ) ) );
	$whitespace_phone_presentation                            = $phone_presentation;
	$whitespace_phone_presentation['forms'][0]['controls'][0]['type'] = ' tel ';
	$validated_whitespace_phone_presentation                  = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $whitespace_phone_presentation );
	$whitespace_phone_row                                     = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_whitespace_phone_presentation['forms'] ?? array() ) )['forms'][0] ?? array();
	$whitespace_phone_destinations                             = $whitespace_phone_row['provider_layout_target_map']['presentation_targets'][0]['destinations'] ?? array();
	$whitespace_phone_markup                                  = (string) ( $whitespace_phone_row['block_markup'] ?? '' );
	$whitespace_phone_hooks                                   = array();
	foreach ( $whitespace_phone_destinations as $destination ) {
		if ( is_array( $destination ) && preg_match( '/\.((?:ssi-node)-[a-f0-9]{12}-destination-(?:shell|primary|carrier|prefix))$/', (string) ( $destination['selector'] ?? '' ), $matches ) ) {
			$whitespace_phone_hooks[] = $matches[1];
		}
	}
	$assert( empty( $validated_whitespace_phone_presentation['errors'] ) && 4 === count( $whitespace_phone_hooks ) && empty( array_filter( $whitespace_phone_hooks, static fn( string $hook ): bool => ! str_contains( $whitespace_phone_markup, $hook ) ) ), 'whitespace-padded-tel-shares-phone-markup-hooks-and-overlay-destinations', wp_json_encode( array( 'markup' => $whitespace_phone_markup, 'hooks' => $whitespace_phone_hooks ) ) );
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
	$assert( empty( $phone_editor_chrome_validation['errors'] ) && 5 === count( $phone_editor_chrome_destinations ) && str_contains( (string) ( $phone_editor_chrome_destinations[1]['selector'] ?? '' ), '-destination-primary' ) && '0' === ( $phone_editor_chrome_destinations[0]['resets']['padding'] ?? null ) && str_contains( (string) ( $phone_editor_chrome_destinations[2]['selector'] ?? '' ), '-destination-carrier' ) && str_contains( (string) ( $phone_editor_chrome_destinations[3]['selector'] ?? '' ), '-destination-prefix' ) && str_contains( (string) ( $phone_editor_chrome_destinations[4]['selector'] ?? '' ), '-destination-shell' ) && str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), 'padding-inline-end:2px!important' ) && str_contains( (string) ( $phone_editor_chrome_overlay['editor_css'] ?? '' ), '-destination-shell{border:1px solid rgba(30,75,110,.6);background:rgb(247,249,251);border-radius:0}' ) && ! str_contains( (string) ( $phone_editor_chrome_overlay['css'] ?? '' ), '1px solid rgba(30,75,110,.6)' ), 'phone-control-container-chrome-targets-the-editor-shell-while-primary-and-carrier-destinations-retain-their-separate-ownership', wp_json_encode( $phone_editor_chrome_row ) );
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
	$candidate_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<link rel="stylesheet" href="style.css"><main><form><div><button type="button" aria-haspopup="listbox" aria-label="Phone country selector"><span class="sourcegroup"><svg class="globe" width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><svg class="chevron" width="16" height="16" viewBox="0 0 16 16"><path d="M4 7l4 4 4-4"/></svg></span></button><input type="tel" name="phone"></div></form></main>', 'style.css' => '.sourcegroup{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:8px}.globe{width:24px;color:rgb(30,75,110)}.chevron{width:16px}@media (min-width:769px){.sourcegroup{gap:4px}.chevron{width:12px}}' ) );
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
	$visual_provider_html = Static_Site_Importer_Provider_Form_Runtime_V1::project_empty_country_visual_state( '<div class="jetpack-field__input-phone-wrapper" data-wp-context="{&quot;defaultCountry&quot;:&quot;&quot;}"><button class="jetpack-combobox-trigger"><span class="jetpack-combobox-trigger-arrow"><svg></svg></span><span class="jetpack-combobox-selected" data-wp-text="context.selectedCountry.value"></span></button><input type="hidden" id="' . ( $visual_state['field_id'] ?? '' ) . '"></div>' );
	$auxiliary_target = $visual_state_row['provider_layout_target_map']['presentation_targets'][0]['destinations'][0] ?? array();
	$auxiliary_overlay = (string) ( $visual_state_row['provider_layout_overlay_css']['css'] ?? '' );
	$assert( empty( $validated_visual_state['errors'] ) && 'submit' === ( $validated_visual_state['forms'][0]['controls'][0]['type'] ?? '' ) && true === ( $visual_state_row['runtime_mapped'] ?? false ) && 2 === count( $visual_state['parts'] ?? array() ) && 'visual-group-' === substr( (string) ( $visual_state['group']['id'] ?? '' ), 0, 13 ) && str_contains( $visual_provider_html, '&quot;defaultCountry&quot;:&quot;US&quot;' ) && ! str_contains( $visual_provider_html, 'data-wp-bind--hidden="context.selectedCountry.value"' ) && str_contains( $visual_provider_html, 'data-wp-bind--hidden="!context.selectedCountry.value"' ) && str_contains( $visual_provider_html, '.jetpack-combobox-selected' ) && str_contains( $visual_provider_html, ':not([hidden])' ) && str_contains( $visual_provider_html, 'display:flex!important' ) && str_contains( $visual_provider_html, 'gap:8px!important' ) && str_contains( $visual_provider_html, 'gap:4px!important' ) && str_contains( $visual_provider_html, 'width:24px!important' ) && str_contains( $visual_provider_html, 'width:12px!important' ) && str_contains( $visual_provider_html, '@media (min-width:769px)' ) && str_contains( $visual_provider_html, 'jetpack-combobox-trigger-arrow' ) && str_contains( $visual_provider_html, (string) ( $visual_state['trigger_class'] ?? '' ) ) && str_contains( (string) ( $auxiliary_target['selector'] ?? '' ), '-destination-country-trigger' ) && str_contains( $auxiliary_overlay, 'position:relative!important' ) && str_contains( $auxiliary_overlay, 'flex-shrink:0!important' ) && str_contains( $auxiliary_overlay, 'transform:translateX(0)!important' ) && empty( $visual_state_row['form_receipt_unaccepted_losses'] ?? array() ) && ! str_contains( $visual_provider_html, 'base64' ), 'candidate-producer-submit-coercion-still-materializes-country-selector-with-functional-default', wp_json_encode( array( 'validation' => $validated_visual_state, 'row' => $visual_state_row, 'html' => $visual_provider_html ) ) );
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
	$assert( '<div class="grunion-field-email-wrap ssi-node-a1b2c3d4e5f6-wrap"><div class="ssi-field-row source-grid"><label>Email</label><div class="ssi-node-0f1e2d3c4b5a-wrap"><input type="email"></div></div></div>' === $fullspan_child_runtime, 'full-span-grid-rebuilds-a-real-value-child-inside-the-source-grid-container', $fullspan_child_runtime );
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
	$inline_hidden_bookkeeping_form = $hidden_bookkeeping_form;
	$inline_hidden_bookkeeping_form['controls'][0] = array( 'tag' => 'input', 'type' => 'text', 'name' => '_app_id' );
	$inline_hidden_bookkeeping_form['layout_graph']['nodes'][1]['layout'] = array( 'display' => 'none' );
	$inline_hidden_bookkeeping_form['layout_graph']['nodes'][1]['provenance'] = array( array( 'source_path' => 'inline-style', 'source_sha256' => str_repeat( 'e', 64 ), 'selector' => '[style]', 'condition' => null, 'properties' => array( 'display' ) ) );
	$inline_hidden_bookkeeping_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $inline_hidden_bookkeeping_form ) ) )['forms'] ?? array() ) )['forms'][0] ?? array();
	$assert( 'mapped' === ( $inline_hidden_bookkeeping_row['status'] ?? '' ) && empty( $inline_hidden_bookkeeping_row['form_receipt_unaccepted_losses'] ?? array() ) && in_array( 'provider_omitted_runtime_controls', array_column( $inline_hidden_bookkeeping_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'inline-hidden-private-text-bookkeeping-is-omitted-without-visibility-loss', wp_json_encode( $inline_hidden_bookkeeping_row ) );
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
	$assert( 'mapped' === ( $hidden_native_select_row['status'] ?? '' ) && in_array( 'provider_native_control_visibility', array_column( $hidden_native_select_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ) && ! preg_match( '/\.ssi-node-[a-f0-9]{12}(?:-wrap)?(?: > [^{]+)?\{display:none\}/', (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ) ) && str_contains( (string) ( $hidden_native_select_row['provider_layout_overlay_css']['css'] ?? '' ), 'width:100%' ), 'provider-native-controls-replace-hidden-display-and-retain-other-layout', wp_json_encode( $hidden_native_select_row ) );
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
	$placeholder_email_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array(
			'forms' => array(
				array(
					'selector' => 'form.newsletter',
					'controls' => array(
						array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'placeholder' => 'Email Address' ),
						array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Claim My Reward' ),
					),
					'control_topology' => array(
						'schema'    => 'generic/form-control-topology/v1',
						'max_depth' => 8,
						'max_nodes' => 128,
						'truncated' => false,
						'nodes'     => array(
							array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ),
							array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
						),
					),
				),
			),
		)
	);
	$placeholder_email_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $placeholder_email_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$placeholder_email_markup = (string) ( $placeholder_email_row['block_markup'] ?? '' );
	$assert( empty( $placeholder_email_validation['errors'] ) && 'mapped' === ( $placeholder_email_row['status'] ?? '' ) && str_contains( $placeholder_email_markup, '"placeholder":"Email Address"' ) && ! str_contains( $placeholder_email_markup, '"label":"Email Address"' ), 'placeholder-only email fields keep placeholder without inventing a label', $placeholder_email_markup );
	$name_fieldset_form = array(
		'forms' => array(
			array(
				'selector'         => 'form.contact',
				'form'             => array( 'class' => 'react-form-contents' ),
				'controls'         => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'fname', 'label' => 'First Name' ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'lname', 'label' => 'Last Name' ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'control_topology' => array(
					'schema'     => 'generic/form-control-topology/v1',
					'max_depth'  => 8,
					'max_nodes'  => 128,
					'truncated'  => false,
					'nodes'      => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'form-item fields name', 'fieldset_semantics' => 'labelled_group' ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'field first-name' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 3, 'control' => 0 ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-1', 'order' => 1, 'depth' => 2, 'tag' => 'div', 'class' => 'field last-name' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'wrapper-4', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'div', 'class' => 'field email' ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-4', 'order' => 0, 'depth' => 2, 'control' => 2 ),
						array( 'id' => 'control-3', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'control' => 3 ),
					),
				),
				'layout_graph'     => $v2_layout_graph(
					array(
						$layout_node( 'form', array(), 'form' ),
						array(
							'id'         => 'wrapper-0',
							'kind'       => 'container',
							'parent'     => 'form',
							'order'      => 0,
							'source'     => array( 'tag' => 'div', 'classes' => array( 'field-list' ) ),
							'layout'     => array(),
							'provenance' => array(),
						),
						array(
							'id'         => 'wrapper-1',
							'kind'       => 'container',
							'parent'     => 'wrapper-0',
							'order'      => 0,
							'source'     => array( 'tag' => 'fieldset', 'classes' => array( 'form-item', 'fields', 'name' ) ),
							'layout'     => array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ),
							'provenance' => array(
								array(
									'source_path'  => 'assets/form.css',
									'source_sha256' => str_repeat( 'e', 64 ),
									'selector'     => '.form-item.fields.name',
									'condition'    => null,
									'properties'   => array( 'display', 'flex-direction', 'gap' ),
								),
							),
						),
					)
				),
			),
		),
	);
	$name_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $name_fieldset_form );
	$name_fieldset_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $name_fieldset_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$name_fieldset_reasons    = array_column( $name_fieldset_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$name_fieldset_ops        = array_column( $name_fieldset_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $name_fieldset_validation['errors'] ) && 'mapped' === ( $name_fieldset_row['status'] ?? '' ) && true === ( $name_fieldset_row['runtime_mapped'] ?? false ) && in_array( 'provider_labelled_text_fieldset_projection', $name_fieldset_ops, true ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), $name_fieldset_reasons ) && str_contains( (string) ( $name_fieldset_row['block_markup'] ?? '' ), 'First Name' ) && str_contains( (string) ( $name_fieldset_row['block_markup'] ?? '' ), 'Last Name' ), 'nested-labelled-name-fieldset-materializes-without-semantic-loss', wp_json_encode( $name_fieldset_row ) );
	$phone_fieldset_form = array(
		'forms' => array(
			array(
				'selector'         => 'form.contact',
				'controls'         => array(
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
				),
				'control_topology' => array(
					'schema'    => 'generic/form-control-topology/v1',
					'max_depth' => 8,
					'max_nodes' => 128,
					'truncated' => false,
					'nodes'     => array(
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div', 'class' => 'field-list' ),
						array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'div', 'class' => 'field email' ),
						array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
						array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'tag' => 'fieldset', 'class' => 'form-item fields phone', 'fieldset_semantics' => 'labelled_group' ),
						array( 'id' => 'wrapper-3', 'kind' => 'wrapper', 'parent' => 'wrapper-2', 'order' => 0, 'depth' => 2, 'tag' => 'div', 'class' => 'field' ),
						array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-3', 'order' => 0, 'depth' => 3, 'control' => 1 ),
						array( 'id' => 'control-2', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 2, 'depth' => 1, 'control' => 2 ),
					),
				),
				'layout_graph'     => $v2_layout_graph(
					array(
						$layout_node( 'form', array(), 'form' ),
						array(
							'id'         => 'wrapper-2',
							'kind'       => 'container',
							'parent'     => 'form',
							'order'      => 1,
							'source'     => array( 'tag' => 'fieldset', 'classes' => array( 'form-item', 'fields', 'phone' ) ),
							'layout'     => array(),
							'provenance' => array(),
						),
					)
				),
			),
		),
	);
	$phone_fieldset_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $phone_fieldset_form );
	$phone_fieldset_row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $phone_fieldset_validation['forms'] ?? array() ) )['forms'][0] ?? array();
	$phone_fieldset_reasons    = array_column( $phone_fieldset_row['form_receipt_unaccepted_losses'] ?? array(), 'reason_code' );
	$phone_fieldset_ops        = array_column( $phone_fieldset_row['computed_layout_receipt']['operations'] ?? array(), 'strategy' );
	$assert( empty( $phone_fieldset_validation['errors'] ) && 'mapped' === ( $phone_fieldset_row['status'] ?? '' ) && true === ( $phone_fieldset_row['runtime_mapped'] ?? false ) && in_array( 'provider_labelled_text_fieldset_projection', $phone_fieldset_ops, true ) && ! array_intersect( array( 'unsupported_semantic_wrapper', 'provider_wrapper_layout_unrepresentable' ), $phone_fieldset_reasons ), 'nested-labelled-phone-fieldset-without-legend-materializes', wp_json_encode( $phone_fieldset_row ) );
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
	$provider_map_selectors = array_column( is_array( $provider_map['targets'] ?? null ) ? $provider_map['targets'] : array(), 'selector' );
	$assert( 'generic/provider-layout-target-map/v1' === ( $provider_map['schema'] ?? '' ) && 2 <= preg_match_all( '/^\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12}-wrap$/m', implode( "\n", $provider_map_selectors ) ), 'equal-width-field-shells-are-the-flattened-row-overlay-targets', wp_json_encode( $provider_map ) );
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
	$assert( '<div class="grunion-field-text-wrap"><div class="ssi-field-row field"><input class="source-input"></div></div>' === $projected_wrapper, 'provider-runtime-rebuilds-source-wrapper-inside-field-shell', $projected_wrapper );
	$layout_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-node-123456789abc-wrap ssi-source-wrapper--field-wrap"><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap ssi-node-123456789abc-wrap"><div class="ssi-field-row field"><input class="source-input"></div></div>' === $layout_wrapper, 'provider-runtime-keeps-the-generated-layout-hook-on-the-field-shell', $layout_wrapper );
	$layered_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap ssi-source-wrapper-6--carrier-wrap ssi-source-wrapper-8--input-shell-wrap"><label>Name</label><input class="source-input"></div>' );
	$assert( '<div class="grunion-field-text-wrap"><div class="ssi-field-row carrier"><label>Name</label><div class="input-shell"><input class="source-input"></div></div></div>' === $layered_wrapper, 'provider-runtime-keeps-the-label-inside-the-outermost-source-wrapper', $layered_wrapper );
	$projected_controls = implode( '', array_map( array( Static_Site_Importer_Form_Seeder::class, 'project_provider_wrapper_classes' ), array( '<div class="grunion-field-text-wrap ssi-source-wrapper-2--control-shell-wrap"><input class="control-hook"></div>', '<div class="grunion-field-textarea-wrap ssi-source-wrapper-2--control-shell-wrap"><textarea class="control-hook"></textarea></div>', '<div class="grunion-field-select-wrap ssi-source-wrapper-2--control-shell-wrap"><select class="control-hook"><option>One</option></select></div>' ) ) );
	$assert( 3 === substr_count( $projected_controls, '<div class="ssi-field-row control-shell">' ) && 3 === substr_count( $projected_controls, 'class="control-hook"' ) && ! str_contains( $projected_controls, 'ssi-source-wrapper-' ), 'wrapper-projection-preserves-input-textarea-and-select-control-relationships', $projected_controls );
	$phone_wrapper = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_wrapper, '<div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div>' ) && str_contains( $phone_wrapper, '<div class="control-shell"><input class="jetpack-field__input-element" type="tel"></div><input type="hidden" name="full-phone">' ), 'phone-wrapper-restoration-targets-value-control-without-wrapping-country-search-or-hidden-value', $phone_wrapper );
	$assert( 1 === substr_count( $phone_wrapper, 'class="control-shell"' ) && $phone_wrapper === Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $phone_wrapper ), 'phone-wrapper-restoration-is-single-target-and-idempotent' );
	$prefix_projection = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-0--input-shell-wrap ssi-source-wrapper-prefix-1--country-wrapper-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button data-wp-on--click="actions.toggle">Country</button><input class="jetpack-combobox-search" type="search"><template data-wp-each="context.countries"><span>Country</span></template></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="value"></div></div>' );
	$assert( str_contains( $prefix_projection, '<div class="country-wrapper"><div class="jetpack-field__input-prefix">' ) && str_contains( $prefix_projection, '<div class="input-shell"><input class="jetpack-field__input-element" type="tel"></div>' ) && str_contains( $prefix_projection, 'data-wp-on--click="actions.toggle"' ) && str_contains( $prefix_projection, '<template data-wp-each="context.countries"><span>Country</span></template>' ) && $prefix_projection === Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $prefix_projection ), 'auxiliary-ownership-restores-prefix-and-primary-in-separate-branches-with-runtime-bindings-intact', $prefix_projection );
	$phone_composite = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-2--control-shell-wrap"><div class="jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell ssi-node-222222222222-destination-primary ssi-node-333333333333-destination-carrier"><div class="jetpack-combobox-dropdown"><input class="jetpack-combobox-search" type="text"></div><input class="jetpack-field__input-element" type="tel"><input type="hidden" name="full-phone"></div></div>' );
	$assert( str_contains( $phone_composite, 'jetpack-field__input-phone-wrapper ssi-node-111111111111-destination-shell' ) && str_contains( $phone_composite, '<div class="control-shell ssi-node-333333333333-destination-carrier"><input class="jetpack-field__input-element ssi-node-222222222222-destination-primary" type="tel">' ) && ! str_contains( $phone_composite, 'jetpack-combobox-search ssi-node-' ), 'phone-composite-projection-moves-adapter-declared-primary-and-carrier-hooks-to-the-actual-tel-control', $phone_composite );
	$phone_prefix_chrome = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap"><div class="jetpack-field__input-phone-wrapper ssi-node-444444444444-destination-prefix"><div class="jetpack-field__input-prefix"><div class="jetpack-custom-combobox"><button type="button">Country</button></div></div><input class="jetpack-field__input-element" type="tel"></div></div>' );
	$assert( str_contains( $phone_prefix_chrome, 'jetpack-field__input-prefix ssi-node-444444444444-destination-prefix' ) && str_contains( $phone_prefix_chrome, 'jetpack-custom-combobox ssi-node-444444444444-destination-prefix' ) && ! str_contains( $phone_prefix_chrome, 'jetpack-field__input-phone-wrapper ssi-node-444444444444-destination-prefix' ) && $phone_prefix_chrome === Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $phone_prefix_chrome ), 'phone-prefix-chrome-projection-moves-the-stretch-hook-onto-provider-prefix-and-combobox', $phone_prefix_chrome );
	$unmarked_provider_shell = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' );
	$shared_projection = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( '<div class="grunion-field-phone-wrap ssi-source-wrapper-shell-0--shared-border-wrap ssi-source-wrapper-prefix-1--prefix-box-wrap"><div class="jetpack-field__input-phone-wrapper"><div class="jetpack-field__input-prefix"><button>Country</button></div><input type="tel"></div></div>' );
	$shared_document = new DOMDocument();
	$shared_document->loadHTML( $shared_projection );
	$shared_xpath = new DOMXPath( $shared_document );
	$assert( 1 === $shared_xpath->query( '//div[@class="shared-border"]/div[@class="jetpack-field__input-phone-wrapper"]/input[@type="tel"]' )->length && 1 === $shared_xpath->query( '//div[@class="shared-border"]//div[@class="prefix-box"]//button' )->length, 'shared-border-wraps-both-prefix-and-value-in-the-rendered-provider-tree' );
	$assert( '<div class="grunion-field-text-wrap unrelated-wrap"><input class="control-hook"></div>' === $unmarked_provider_shell, 'wrapper-projection-does-not-rewrite-unmarked-provider-shells', $unmarked_provider_shell );
	$described_row_html = '<div class="grunion-field-textarea-wrap ssi-source-wrapper-2--flex-wrap ssi-source-wrapper-2--flex-col-wrap ssi-source-wrapper-2--gap-2-wrap"><label>Why this nomination stands up</label><textarea rows="6"></textarea><div class="contact-form__input-error"></div></div>';
	$described_row      = Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $described_row_html );
	$assert(
		str_contains( $described_row, '<div class="ssi-field-row flex flex-col gap-2">' )
			&& str_contains( $described_row, '<label>Why this nomination stands up</label>' )
			&& 1 === preg_match( '/<div class="ssi-field-row flex flex-col gap-2">.*<label>Why this nomination stands up<\/label>.*<textarea\b/s', $described_row )
			&& ! str_contains( $described_row, 'grunion-field-textarea-wrap flex' ),
		'outermost-source-field-row-keeps-the-label-inside-the-restored-wrapper',
		$described_row
	);
	$described_source_html = '<style>.flex{display:flex}.flex-col{flex-direction:column}.gap-2{gap:.5rem}label{display:block}textarea{box-sizing:border-box;padding:12px;font-size:14px;line-height:20px;border:1px solid}</style><form>'
		. '<div class="flex flex-col gap-2"><label>Why this nomination stands up</label><textarea rows="6" required></textarea><p class="text-xs">Evidence, outcomes and dates carry more weight than adjectives.</p></div>'
		. '<button type="submit">Send</button></form>';
	$described_source = class_exists( $artifact_compiler ) ? ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $described_source_html ) ) )->toArray() )['fallbacks'][0] ?? array() : array();
	$described_validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $described_source ) ) );
	$described_seed      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $described_validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$described_markup    = (string) ( $described_seed['block_markup'] ?? '' );
	$described_css       = (string) ( $described_seed['provider_layout_overlay_css']['css'] ?? '' );
	$described_help_text = (string) ( $described_source['controls'][0]['description'] ?? '' );
	$assert(
		empty( $described_validated['errors'] )
			&& 'mapped' === ( $described_seed['status'] ?? '' )
			&& str_contains( $described_markup, 'ssi-textarea-rows-6' )
			&& str_contains( $described_css, 'display:contents' )
			&& str_contains( $described_css, 'contact-form__field-hints' )
			&& ( '' === $described_help_text || 1 === preg_match( '/<!-- wp:jetpack\/field-textarea \{[^\n]*"helpText":"Evidence, outcomes and dates carry more weight than adjectives\."[^\n]*\} -->/', $described_markup ) )
			&& null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $described_seed['provider_layout_overlay_css'] ?? null ),
		'compiled-field-row-keeps-authored-textarea-rows-and-stops-hint-chrome-from-forming-its-own-box',
		wp_json_encode( array( 'errors' => $described_validated['errors'] ?? array(), 'markup' => $described_markup, 'css' => $described_css, 'description' => $described_help_text ) )
	);
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
	$prefix_margin_graph = $v2_layout_graph( array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'prefix' ) ), 'layout' => array( 'display' => 'flex', 'margin_inline_start' => '12px' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.prefix', 'condition' => null, 'properties' => array( 'display', 'margin-inline-start' ) ) ) ) ) );
	$prefix_margin_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'wrapper-0', 'selector' => '.ssi-form-123456789abc .ssi-node-123456789abc', 'capabilities' => array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' ) ) ) );
	$prefix_margin_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $prefix_margin_graph, $prefix_margin_map );
	$assert( str_contains( $prefix_margin_overlay['css'], 'margin-inline-start:12px' ) && empty( $prefix_margin_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $prefix_margin_overlay['overlay'] ), 'provider-layout-projects-logical-margin-longhands-onto-reconstructed-wrappers', wp_json_encode( $prefix_margin_overlay ) );
	$prefix_height_graph = $v2_layout_graph( array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'span', 'classes' => array( 'prefix' ) ), 'layout' => array( 'display' => 'block', 'height' => '100%' ), 'provenance' => array( array( 'source_path' => 'assets/form.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.prefix', 'condition' => null, 'properties' => array( 'display', 'height' ) ) ) ) ) );
	$prefix_height_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $prefix_height_graph, $prefix_margin_map );
	$assert( str_contains( $prefix_height_overlay['css'], 'height:100%' ) && empty( $prefix_height_overlay['losses'] ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $prefix_height_overlay['overlay'] ), 'provider-layout-projects-explicit-height-onto-reconstructed-wrappers', wp_json_encode( $prefix_height_overlay ) );
	$justify_self_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $layout_graph( array( $layout_node( 'control-0', array( 'justify_self' => 'center' ), 'input' ) ) ), $item_map );
	$assert( str_contains( $justify_self_overlay['css'], 'justify-self:center' ) && null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $justify_self_overlay['overlay'] ), 'provider-layout-validates-compiled-justify-self-css' );
	$item_map['targets'][0]['capabilities'] = array( 'item_layout' );
	$item_without_direct_child = Static_Site_Importer_Provider_Layout_Overlay::compile( $item_graph, $item_map );
	$assert( '' === $item_without_direct_child['css'] && array( 'direct_child_relationship_unrepresentable', 'direct_child_relationship_unrepresentable' ) === array_column( $item_without_direct_child['losses'], 'reason_code' ), 'provider-layout-does-not-accept-inert-item-layout-capability' );
	$membership = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.membership', 'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'tag' => 'input', 'type' => 'number', 'name' => 'household', 'label' => 'Household size', 'min' => '1', 'max' => '50' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Register' ) ) ) ) ) );
	$membership_row    = $membership['forms'][0] ?? array();
	$membership_markup = (string) ( $membership_row['block_markup'] ?? '' );
	$assert( true === ( $membership_row['runtime_mapped'] ?? false ) && 'mapped' === ( $membership_row['status'] ?? '' ) && 0 === ( $membership['counts']['skipped'] ?? -1 ), 'number-min-max-does-not-decline-the-form', wp_json_encode( $membership_row ) );
	$assert( str_contains( $membership_markup, 'wp:jetpack/field-number' ) && str_contains( $membership_markup, 'wp:jetpack/field-email' ) && str_contains( $membership_markup, '"label":"Household size"' ), 'number-min-max-materializes-field-number-inside-the-form', $membership_markup );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/input \{[^\n]*"min":1[^\n]*"max":50[^\n]*\} \/-->/', $membership_markup ), 'number-min-max-are-serialized-onto-jetpack-input', $membership_markup );
	$assert( array() === array_column( $membership_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-min-max-are-not-receipt-losses', wp_json_encode( $membership_row['computed_layout_receipt'] ?? array() ) );
	$booking = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.booking', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request booking' ) ) ) ) ) );
	$booking_row = $booking['forms'][0] ?? array();
	$assert( 'Request booking' === ( $booking_row['submit_text'] ?? '' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '>Request booking</button>' ), 'canonical-control-text-preserves-request-booking-submit-label' );
	$assert( str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"label":"Guests"' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"min":1' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"max":8' ) && ! str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"step"' ) && array( 'step' ) === array_column( $booking_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-source-attributes-preserve-supported-min-max-and-report-step-loss' );
	$assert( false === ( $booking_row['runtime_mapped'] ?? true ) && array( 'step' ) === array_column( $booking_row['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ) && 1 === ( $booking_row['unaccepted_receipt_loss_count'] ?? 0 ), 'number-unsupported-step-gates-form-runtime-acceptance' );
	$assert( 'skipped' === ( $booking_row['status'] ?? '' ) && 0 === ( $booking['counts']['error'] ?? -1 ), 'gated-form-is-a-provider-decline-not-a-materialization-error' );
	$height_controls = array();
	for ( $height_index = 1; $height_index <= 17; ++$height_index ) {
		$height_controls[] = array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message-' . $height_index, 'height' => $height_index . 'px' );
	}
	$height_controls[] = array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' );
	$height_entity = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/many-heights.html',
			'selector'    => 'form.many-heights',
			'form'        => array( 'class' => 'many-heights', 'textarea_height_omitted_count' => 1 ),
			'controls'    => $height_controls,
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/many-heights.html', 'search_block_markup' => '<!-- wp:html --><form class="many-heights"></form><!-- /wp:html -->', 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$height_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $height_entity ) ) )['forms'][0] ?? array();
	$assert( false === ( $height_row['runtime_mapped'] ?? true ) && 'textarea_height_omitted' === ( $height_row['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && 1 === ( $height_row['unaccepted_receipt_loss_count'] ?? 0 ), 'omitted-textarea-heights-gate-form-runtime-acceptance' );
	$binding_ignored = $height_entity;
	$binding_ignored['bindings'][0]['occurrence'] = 0;
	$assert( 1 === ( Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $binding_ignored )['form']['textarea_height_omitted_count'] ?? 0 ), 'producer-presentation-does-not-depend-on-binding-html' );
	$multi_form_mismatch = $height_entity;
	$multi_form_mismatch['bindings'][0]['search_block_markup'] = '<form><input name="unexpected"></form>';
	$assert( 1 === ( Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $multi_form_mismatch )['form']['textarea_height_omitted_count'] ?? 0 ), 'producer-presentation-ignores-conflicting-binding-html' );
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
	$overflow_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( array( 'selector' => 'form.overflow', 'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'label' => 'Guests', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ), 'layout_graph' => $layout_graph( $overflow_nodes ) ) ) ) );
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
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'fieldset', 'fieldset_semantics' => 'labelled_group', 'legend' => 'What was your treatment?' ),
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
	// Search mode buttons are not lead-form submits, and a nested source label still
	// maps to Jetpack's one field label when it owns exactly one provider field.
	$native_controls = array(
		'forms' => array(
			array(
				'selector' => 'form.property-search',
				'controls' => array(
					array( 'tag' => 'button', 'type' => 'button', 'text' => 'Buy', 'class' => 'mode-active' ),
					array( 'tag' => 'button', 'type' => 'button', 'text' => 'Rent', 'class' => 'mode-idle' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'property_type', 'label' => 'Property type', 'options' => array( 'House', 'Apartment' ) ),
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'location', 'label' => 'Location' ),
					array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Search' ),
				),
				'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false, 'nodes' => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'div' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 1, 'depth' => 1, 'control' => 1 ),
					array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 2 ),
					array( 'id' => 'control-3', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 3 ),
					array( 'id' => 'control-4', 'kind' => 'control', 'parent' => null, 'order' => 3, 'depth' => 0, 'control' => 4 ),
				) ),
			),
			array(
				'selector' => 'form.valuation',
				'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ), array( 'tag' => 'button', 'type' => 'submit', 'text' => 'Request valuation' ) ),
				'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 16, 'truncated' => false, 'nodes' => array(
					array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'label' ),
					array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'tag' => 'span' ),
					array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-1', 'order' => 0, 'depth' => 2, 'control' => 0 ),
					array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
				) ),
			),
		),
	);
	$native_controls_valid = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $native_controls );
	$native_controls_seed  = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $native_controls_valid['forms'] ?? array() ) );
	$native_search         = $native_controls_seed['forms'][0] ?? array();
	$native_lead           = $native_controls_seed['forms'][1] ?? array();
	$assert( empty( $native_controls_valid['errors'] ) && 'mapped' === ( $native_search['status'] ?? '' ) && 'mapped' === ( $native_lead['status'] ?? '' ) && 2 === ( $native_search['field_count'] ?? 0 ) && 1 === ( $native_lead['field_count'] ?? 0 ), 'native-buttons-and-nested-labels-materialize-as-separate-provider-forms', wp_json_encode( $native_controls_seed ) );
	$assert( 2 === substr_count( (string) ( $native_search['block_markup'] ?? '' ), 'type="button"' ) && str_contains( (string) ( $native_search['block_markup'] ?? '' ), '>Buy</button>' ) && str_contains( (string) ( $native_search['block_markup'] ?? '' ), '>Rent</button>' ) && empty( $native_lead['form_receipt_unaccepted_losses'] ), 'native-mode-buttons-and-one-control-nested-label-keep-their-semantics', wp_json_encode( array( $native_search['computed_layout_receipt'] ?? array(), $native_lead['computed_layout_receipt'] ?? array() ) ) );
	$assert( (string) ( $native_search['block_markup'] ?? '' ) === serialize_blocks( parse_blocks( (string) ( $native_search['block_markup'] ?? '' ) ) ) && (string) ( $native_lead['block_markup'] ?? '' ) === serialize_blocks( parse_blocks( (string) ( $native_lead['block_markup'] ?? '' ) ) ), 'native-control-provider-markup-round-trips-through-wordpress' );
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
	$cara_entity    = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity(
		array(
			'source_path' => 'website/contact.html',
			'selector'    => 'form.contact-form',
			'form'        => array(
				'class'               => 'contact-form',
				'context_before'      => array(
					array( 'type' => 'heading', 'level' => 2, 'text' => 'Contact Me', 'class' => 'font-serif text-2xl' ),
					array( 'type' => 'paragraph', 'text' => '* Indicates required field' ),
				),
				'submit_presentation' => array(
					'text'          => 'Submit',
					'classes'       => array( 'wsite-button' ),
					'label_classes' => array( 'wsite-button-inner' ),
				),
			),
			'controls'    => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'first', 'aria-required' => 'true' ), array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'aria-required' => 'true', 'height' => '200px' ), array( 'tag' => 'input', 'type' => 'submit' ) ),
			'bindings'    => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/contact.html', 'search_block_markup' => '<!-- wp:html --><form class="contact-form"></form><!-- /wp:html -->', 'occurrence' => 1, 'role' => 'form' ) ),
		)
	);
	$cara_grafted   = (string) ( Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $cara_entity ) ) )['forms'][0]['block_markup'] ?? '' );
	$cara_parsed    = array_values( array_filter( parse_blocks( $cara_grafted ), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );
	$cara_contact   = $cara_parsed[0] ?? array();
	$cara_inners    = array_column( array_values( array_filter( $cara_contact['innerBlocks'] ?? array(), static fn( array $block ): bool => ! empty( $block['blockName'] ) ) ), 'blockName' );
	$assert( str_contains( $cara_grafted, '>Contact Me</h2>' ) && str_contains( $cara_grafted, 'class="wp-block-heading font-serif text-2xl"' ) && str_contains( $cara_grafted, '"className":"font-serif text-2xl"' ) && str_contains( $cara_grafted, '<p>* Indicates required field</p>' ) && str_contains( $cara_grafted, '"required":true' ) && str_contains( $cara_grafted, 'wsite-button' ), 'canonical-binding-presentation-reaches-provider-markup' );
	$assert(
		1 === count( $cara_parsed )
			&& 'jetpack/contact-form' === ( $cara_contact['blockName'] ?? '' )
			&& array( 'core/heading', 'core/paragraph' ) === array_slice( $cara_inners, 0, 2 ),
		'canonical-in-form-context-is-emitted-as-contact-form-inner-blocks',
		wp_json_encode( array( 'names' => array_column( $cara_parsed, 'blockName' ), 'inners' => $cara_inners, 'markup' => $cara_grafted ) )
	);


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
	$mixed_row_graph = array(
		'schema'   => 'generic/computed-layout-graph/v2',
		'nodes'    => array(
			array( 'id' => 'grid', 'kind' => 'container', 'layout' => array( 'display' => 'grid', 'columns' => '100%' ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '1 / 1 / 2 / 2' ) ),
			array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '1 / 1 / 2 / 2' ) ),
			array( 'id' => 'wrapper-2', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '2 / 1 / 3 / 2', 'width' => '611px' ) ),
		),
		'variants' => array(),
	);
	$mixed_row_result = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $mixed_row_graph );
	$mixed_row_layout = array_column( $mixed_row_result['nodes'], 'layout', 'id' );
	$uniform_row_result = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( array(
		'schema'   => 'generic/computed-layout-graph/v2',
		'nodes'    => array(
			array( 'id' => 'grid', 'kind' => 'container', 'layout' => array( 'display' => 'grid', 'columns' => '100%' ) ),
			array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '4 / 1 / 5 / 2' ) ),
			array( 'id' => 'wrapper-1', 'kind' => 'wrapper', 'parent' => 'grid', 'layout' => array( 'area' => '4 / 1 / 5 / 2' ) ),
		),
		'variants' => array(),
	) );
	$assert(
		! isset( $mixed_row_layout['wrapper-0']['area'] ) && ! isset( $mixed_row_layout['wrapper-2']['area'] ) && '611px' === ( $mixed_row_layout['wrapper-2']['width'] ?? '' ) && array( 'display' => 'grid' ) === $mixed_row_layout['grid'] && array( 'display' => 'grid', 'columns' => '100%' ) === array_column( $uniform_row_result['nodes'], 'layout', 'id' )['grid'] && '4 / 1 / 5 / 2' === ( array_column( $uniform_row_result['nodes'], 'layout', 'id' )['wrapper-0']['area'] ?? '' ),
		'source-rows-that-neither-pair-with-each-box-nor-share-one-band-drop-their-provider-placement',
		wp_json_encode( array( 'mixed' => $mixed_row_layout, 'uniform' => array_column( $uniform_row_result['nodes'], 'layout', 'id' ) ) )
	);
	// A sibling that another strategy already represented is absent from the overlay
	// graph; on its own the remainder looks like an ordered sequence.
	$reduced_row_graph          = $mixed_row_graph;
	$reduced_row_graph['nodes'] = array_values( array_filter( $mixed_row_graph['nodes'], static fn ( array $node ): bool => 'wrapper-1' !== $node['id'] ) );
	$reduced_row_layout         = array_column( Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $reduced_row_graph, $mixed_row_graph )['nodes'], 'layout', 'id' );
	$assert(
		! isset( $reduced_row_layout['wrapper-2']['area'] ) && '611px' === ( $reduced_row_layout['wrapper-2']['width'] ?? '' ) && isset( $reduced_row_layout['wrapper-0'] ) && ! isset( $reduced_row_layout['wrapper-0']['area'] ),
		'partly-represented-sibling-sets-are-judged-against-the-complete-source-graph',
		wp_json_encode( $reduced_row_layout )
	);
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
	$receipt_argument = array_values( array_filter( $argv ?? array(), static fn( string $argument ): bool => str_starts_with( $argument, '--retained-form-receipt=' ) ) );
	if ( ! empty( $receipt_argument ) ) {
		$receipt_path = substr( $receipt_argument[0], strlen( '--retained-form-receipt=' ) );
		$retained_raw = is_readable( $receipt_path ) ? (string) file_get_contents( $receipt_path ) : '';
		// Retained artifacts can carry a large per-form metadata set, so the replay
		// fixture is stored gzip-compressed and decoded by its magic header.
		if ( str_starts_with( $retained_raw, "\x1f\x8b" ) ) {
			$retained_raw = (string) gzdecode( $retained_raw );
		}
		$receipt      = '' !== $retained_raw ? json_decode( $retained_raw, true ) : null;
		$retained     = array();
		$collect      = static function ( mixed $value ) use ( &$collect, &$retained ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['source_path'], $value['controls'], $value['presentation_graph'] ) && is_array( $value['controls'] ) && is_array( $value['presentation_graph'] ) ) {
				$retained[ (string) ( $value['fallback_identity'] ?? hash( 'sha256', (string) $value['source_path'] . "\n" . wp_json_encode( $value['controls'] ) ) ) ] = $value;
				return;
			}
			foreach ( $value as $child ) {
				$collect( $child );
			}
		};
		$collect( $receipt );
		$retained_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array_values( $retained ) ) );
		$retained_rows       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $retained_validation['forms'] ?? array() ) )['forms'] ?? array();
		$assert( 24 === count( $retained ) && empty( $retained_validation['errors'] ) && 24 === count( $retained_rows ) && 24 === count( array_filter( $retained_rows, static fn( array $row ): bool => 'mapped' === ( $row['status'] ?? '' ) && true === ( $row['runtime_mapped'] ?? false ) && empty( $row['form_receipt_unaccepted_losses'] ?? array() ) ) ), 'retained-artifact-forms-materialize-without-receipt-losses', wp_json_encode( array( 'path' => $receipt_path, 'forms' => count( $retained ), 'errors' => $retained_validation['errors'] ?? array(), 'rows' => $retained_rows ) ) );
	}

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
