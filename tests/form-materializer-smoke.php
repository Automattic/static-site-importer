<?php
/**
 * Smoke coverage for the configurable form provider layer and Jetpack form adapter.
 *
 * Run from the repository root:
 * php tests/form-materializer-smoke.php
 *
 * @package StaticSiteImporter
 */

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
			unset( $name );
			return $default;
		}
	}

	$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
	$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
	$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
	if ( is_readable( $parser ) && is_readable( $blocks ) ) {
		require_once $parser;
		require_once $blocks;
	}

	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			public static function get_instance(): self {
				return new self();
			}

			public function is_registered( string $name ): bool {
				return ! empty( $GLOBALS['ssi_jetpack_form_blocks_available'] ) && in_array( $name, array( 'jetpack/contact-form', 'jetpack/field-text' ), true );
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

	$transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php';
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

	// --- Jetpack form seeder maps controls to contact-form blocks -----------
	$forms_manifest = array(
		'forms' => array(
			array(
				'selector' => 'form.contact',
				'form'     => array( 'action' => 'mailto:hello@example.com', 'method' => 'post', 'class' => 'form contact' ),
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'text', 'id' => 'contact-name', 'name' => 'name', 'label' => 'Your name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'input', 'type' => 'number', 'name' => 'attendees', 'label' => 'Attendees' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'Sales' ), array( 'label' => 'Support' ) ) ),
					array( 'tag' => 'input', 'type' => 'radio', 'name' => 'format', 'label' => 'In person', 'options' => array( 'In person', 'Online' ) ),
					array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Send me updates' ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send message' ),
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
	$assert( ! str_contains( $markup, 'wp:jetpack/field-telephone' ), 'markup-avoids-unstable-field-telephone-template' );
	$assert( str_contains( $markup, 'wp:jetpack/field-number' ), 'markup-field-number' );
	$assert( str_contains( $markup, 'wp:jetpack/field-select' ), 'markup-field-select' );
	$assert( str_contains( $markup, 'wp:jetpack/field-radio' ), 'markup-field-radio' );
	$assert( str_contains( $markup, 'wp:jetpack/field-checkbox' ), 'markup-field-checkbox' );
	$assert( str_contains( $markup, 'wp:jetpack/field-textarea' ), 'markup-field-textarea' );
	$assert( str_contains( $markup, 'wp:jetpack/button' ), 'markup-submit-button' );
	$assert( 1 === substr_count( $markup, 'wp:jetpack/button' ), 'legacy-submit-control-emits-one-button' );
	$assert( str_contains( $markup, 'hello@example.com' ), 'markup-mailto-recipient' );
	$assert( str_contains( $markup, '"options":["Sales","Support"]' ), 'markup-select-options' );
	$assert( 1 === preg_match( '/<div class="wp-block-jetpack-contact-form form contact ssi-form-[a-f0-9]{12}">/', $markup ), 'markup-contact-form-wrapper-and-source-classes' );
	$assert( 1 === preg_match( '/<!-- wp:jetpack\/field-text \{"required":true,"id":"contact-name","className":"ssi-node-[a-f0-9]{12}"\} -->/', $markup ), 'markup-field-wrapper-open' );
	$assert( str_contains( $markup, '<div><!-- wp:jetpack/label {"label":"Your name","requiredText":"*"} /-->' ), 'markup-field-label-child' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/input {"type":"text"} /--></div>' ), 'markup-field-input-child-and-wrapper-close' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/input {"type":"dropdown"} /-->' ), 'markup-select-input-child' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/input {"type":"textarea"} /-->' ), 'markup-textarea-input-child' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/input {"type":"tel"} /-->' ), 'markup-telephone-input-child' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/options {"type":"radio"} -->' ), 'markup-radio-options-child' );
	$assert( str_contains( $markup, '<ul><!-- wp:jetpack/option {"label":"In person"} /-->' ), 'markup-radio-option-wrapper' );
	$assert( str_contains( $markup, '<!-- wp:jetpack/option {"label":"Send me updates","isStandalone":true} /-->' ), 'markup-checkbox-option-child' );

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
						array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'section', 'class' => 'row-2', 'source_id' => 'contact-row' ),
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
					'nodes' => array( array( 'id' => 'wrapper-0', 'kind' => 'container', 'parent' => null, 'order' => 0, 'source' => array( 'tag' => 'section', 'id' => 'contact-row', 'classes' => array( 'row-2' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'row', 'wrap' => 'nowrap' ), 'provenance' => array() ) ),
				),
			),
		),
	);
	$validated_topology = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $topology_form );
	$assert( empty( $validated_topology['errors'] ), 'topology-manifest-validates' );
	$topology_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$topology_markup = (string) ( $topology_seed['forms'][0]['block_markup'] ?? '' );
	$topology_receipt = $topology_seed['forms'][0]['computed_layout_receipt'] ?? array();
	$assert( 1 === preg_match( '/<section id="contact-row" class="wp-block-group row-2 ssi-node-[a-f0-9]{12} is-layout-flex"/', $topology_markup ), 'topology-preserves-row-tag-id-and-class' );
	$assert( 3 === substr_count( $topology_markup, 'class="wp-block-group field' ), 'topology-preserves-field-groups' );
	$assert( 1 === preg_match( '/wp:group \{"className":"row-2 ssi-node-[a-f0-9]{12}","anchor":"contact-row","tagName":"section","layout":\{"type":"flex","orientation":"horizontal","flexWrap":"nowrap"\}\}/', $topology_markup ), 'topology-serializes-gutenberg-group-tag' );
	$assert( str_contains( $topology_markup, 'First name' ) && str_contains( $topology_markup, 'Email' ) && str_contains( $topology_markup, 'Message' ), 'topology-preserves-labels' );
	$assert( 1 === substr_count( $topology_markup, 'wp:jetpack/button' ), 'topology-submit-control-emits-one-button-in-source-position' );
	$assert( 'applied' === ( $topology_receipt['status'] ?? '' ) && 2 === ( $topology_receipt['operation_count'] ?? 0 ) && str_contains( $topology_markup, 'is-layout-flex' ) && 'provider_selector_transposition' === ( $topology_receipt['operations'][1]['strategy'] ?? '' ), 'computed-layout-flex-applies-with-bounded-receipt' );
	$topology_seed_repeat = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$assert( $topology_markup === (string) ( $topology_seed_repeat['forms'][0]['block_markup'] ?? '' ), 'provider-layout-classes-are-stable-for-identical-source-form' );
	$provider_map = $topology_seed['forms'][0]['provider_layout_target_map'] ?? array();
	$assert( 'generic/provider-layout-target-map/v1' === ( $provider_map['schema'] ?? '' ) && str_contains( (string) ( $provider_map['targets'][0]['selector'] ?? '' ), '.ssi-form-' ) && ! str_contains( (string) ( $provider_map['targets'][0]['selector'] ?? '' ), 'row-2' ), 'provider-layout-map-keeps-source-classes-out-of-selectors' );
	$root_graph = $layout_graph( array( $layout_node( 'form', array( 'display' => 'flex', 'direction' => 'row', 'gap' => '1rem' ), 'form' ) ) );
	$root_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'form', 'selector' => '.ssi-form-123456789abc > form.jetpack-contact-form__form', 'capabilities' => array( 'container_layout', 'responsive_layout' ) ) ) );
	$root_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $root_graph, $root_map );
	$assert( str_contains( $root_overlay['css'], '.ssi-form-123456789abc > form.jetpack-contact-form__form{display:flex;flex-direction:row;gap:1rem}' ) && 'provider_selector_transposition' === ( $root_overlay['operations'][0]['strategy'] ?? '' ), 'provider-layout-root-targets-native-jetpack-form' );
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
	$assert( 'Request booking' === ( $booking_row['submit_text'] ?? '' ) && str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"text":"Request booking"' ), 'canonical-control-text-preserves-request-booking-submit-label' );
	$assert( str_contains( (string) ( $booking_row['block_markup'] ?? '' ), '"type":"number","step":"0.5"' ) && array( 'min', 'max' ) === array_column( $booking_row['computed_layout_receipt']['losses'] ?? array(), 'attribute' ), 'number-source-attributes-preserve-supported-step-and-report-min-max-losses' );
	$number_control_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$number_control_report['diagnostics'][] = array(
		'type' => 'unsupported_html_fallback', 'diagnostic_code' => 'html_form_fallback', 'loss_class' => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path' => 'website/index.html', 'selector' => 'form.booking', 'tag' => 'form',
		'controls' => array( array( 'tag' => 'input', 'type' => 'number', 'name' => 'guests', 'label' => 'Guests', 'min' => '1', 'max' => '8', 'step' => '0.5' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Request booking' ) ),
	);
	$number_control_seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $number_control_report, array() );
	$number_control_finding = $number_control_report['diagnostics'][0] ?? array();
	$assert( false === ( $number_control_finding['runtime_mapped'] ?? true ) && array( 'min', 'max' ) === array_column( $number_control_finding['form_receipt_unaccepted_losses'] ?? array(), 'attribute' ) && 2 === ( $number_control_seeding['unaccepted_receipt_loss_count'] ?? 0 ), 'number-unsupported-attributes-gate-form-runtime-acceptance' );
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
		$assert( array( 'jetpack/contact-form', 'core/group', 'core/group', 'jetpack/field-text', 'jetpack/label', 'jetpack/input', 'core/group', 'jetpack/field-email', 'jetpack/label', 'jetpack/input', 'core/group', 'jetpack/field-textarea', 'jetpack/label', 'jetpack/input', 'jetpack/button' ) === $parsed_names, 'wordpress-parse-blocks-preserves-complete-emitted-form-topology', wp_json_encode( $parsed_names ) );
	}
	$unsafe_graph = $topology_form;
	$unsafe_graph['forms'][0]['layout_graph']['nodes'][0]['layout'] = array( 'display' => 'grid', 'direction' => 'none', 'item_placement' => array( 'column' => 1 ) );
	$unsafe_graph_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $unsafe_graph );
	$unsafe_graph_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $unsafe_graph_validation['forms'] ) );
	$unsafe_losses = $unsafe_graph_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$assert( 'applied' === ( $unsafe_graph_seed['forms'][0]['computed_layout_receipt']['status'] ?? '' ) && in_array( 'unsupported_item_placement', array_column( $unsafe_losses, 'reason_code' ), true ) && in_array( 'unsafe_layout_value', array_column( $unsafe_losses, 'reason_code' ), true ), 'computed-layout-grid-placement-is-deferred' );
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
	$assert( empty( $variant_only_validation['errors'] ) && 256 === count( $variant_only_validation['forms'][0]['layout_graph']['variants'] ?? array() ) && 1 === ( $variant_only_receipt['losses_total'] ?? 0 ) && 'responsive_layout_ownership' === ( $variant_only_loss['reason_code'] ?? '' ) && 256 === ( $variant_only_loss['variant_count'] ?? 0 ) && 64 === strlen( (string) ( $variant_only_loss['variant_hash'] ?? '' ) ), 'computed-layout-variant-only-no-base-layout-retains-256-and-records-one-target-loss' );
	$semantic_topology = $topology_form;
	$semantic_topology['forms'][0]['control_topology']['nodes'][0]['tag'] = 'fieldset';
	$semantic_topology['forms'][0]['control_topology']['nodes'][1]['tag'] = 'label';
	$semantic_topology['forms'][0]['layout_graph']['nodes'] = array();
	$semantic_validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $semantic_topology );
	$semantic_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $semantic_validation['forms'] ) );
	$semantic_markup = (string) ( $semantic_seed['forms'][0]['block_markup'] ?? '' );
	$semantic_losses = $semantic_seed['forms'][0]['computed_layout_receipt']['losses'] ?? array();
	$assert( 2 === count( $semantic_losses ) && 'semantic' === ( $semantic_losses[0]['dimension'] ?? '' ) && ! str_contains( $semantic_markup, '<fieldset' ) && ! str_contains( $semantic_markup, '<label' ), 'semantic-wrapper-losses-cover-topology-wrappers-without-layout-graph-nodes' );
	$legacy_without_submit = $forms_manifest;
	array_pop( $legacy_without_submit['forms'][0]['controls'] );
	$legacy_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( $legacy_without_submit );
	$legacy_without_submit_markup = (string) ( $legacy_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $legacy_without_submit_markup, 'wp:jetpack/button' ) && str_contains( $legacy_without_submit_markup, '"text":"Submit"' ), 'legacy-form-without-submit-keeps-default-provider-button' );
	$topology_without_submit = $topology_form;
	array_pop( $topology_without_submit['forms'][0]['controls'] );
	array_pop( $topology_without_submit['forms'][0]['control_topology']['nodes'] );
	$validated_topology_without_submit = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $topology_without_submit );
	$assert( empty( $validated_topology_without_submit['errors'] ), 'topology-without-submit-manifest-validates' );
	$topology_without_submit_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology_without_submit['forms'] ) );
	$topology_without_submit_markup = (string) ( $topology_without_submit_seed['forms'][0]['block_markup'] ?? '' );
	$assert( 1 === substr_count( $topology_without_submit_markup, 'wp:jetpack/button' ) && str_contains( $topology_without_submit_markup, '"text":"Submit"' ), 'topology-without-submit-gets-one-default-provider-button' );
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
	$unsupported_control_loss = $unsupported_control_row['computed_layout_receipt']['losses'][0] ?? array();
	$unsupported_control_markup = (string) ( $unsupported_control_row['block_markup'] ?? '' );
	$assert( empty( $unsupported_control_validation['errors'] ) && array( 'file' ) === ( $unsupported_control_row['skipped_types'] ?? array() ), 'unsupported-file-control-keeps-provider-skipped-type-diagnostic' );
	$assert( 'topology' === ( $unsupported_control_loss['dimension'] ?? '' ) && 'unsupported_control_unrepresentable' === ( $unsupported_control_loss['reason_code'] ?? '' ) && 1 === ( $unsupported_control_loss['control_index'] ?? null ) && hash( 'sha256', 'file' ) === ( $unsupported_control_loss['control_type_hash'] ?? '' ) && 64 === strlen( (string) ( $unsupported_control_loss['node_hash'] ?? '' ) ), 'unsupported-file-control-records-node-addressable-topology-loss' );
	$assert( str_contains( $unsupported_control_markup, 'First name' ) && ! str_contains( $unsupported_control_markup, 'Attachment' ) && str_contains( $unsupported_control_markup, 'Message' ), 'unsupported-file-control-preserves-supported-topology-order-around-loss' );
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
	$assert( empty( $deep_topology_validation['errors'] ) && 8 === substr_count( $deep_topology_markup, '<!-- wp:group ' ), 'topology-materializes-eight-wrapper-depth' );

	// --- Provider blocks are never claimed without the provider runtime --------
	$GLOBALS['ssi_jetpack_form_blocks_available'] = false;
	$unavailable_seed                              = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$unavailable_row                               = $unavailable_seed['forms'][0] ?? array();
	$assert( 1 === ( $unavailable_seed['counts']['skipped'] ?? 0 ), 'seed-unavailable-provider-skips-form' );
	$assert( 'provider_unavailable' === ( $unavailable_row['reason'] ?? '' ), 'seed-unavailable-provider-reason' );
	$assert( false === ( $unavailable_row['runtime_mapped'] ?? true ), 'seed-unavailable-provider-not-runtime-mapped' );
	$assert( empty( $unavailable_row['block_markup'] ), 'seed-unavailable-provider-emits-no-block-markup' );
	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	// --- Native html_form_fallback row is enriched into a form finding -------
	$enrich   = new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'diagnostic_from_conversion_report_fallback' );
	$enriched = $enrich->invoke(
		null,
		array(
			'diagnostic_code' => 'html_form_fallback',
			'reason'          => 'form_requires_runtime',
			'source_path'     => 'website/index.html',
			'selector'        => 'form.contact',
			'tag'             => 'form',
			'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
			'controls'        => array(
				array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			),
			'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'nodes' => array(), 'truncated' => false ),
			'control_count'   => 1,
		)
	);
	$assert( 'html_form_fallback' === ( $enriched['diagnostic_code'] ?? '' ), 'enrich-carries-diagnostic-code' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === ( $enriched['loss_class'] ?? '' ), 'enrich-loss-class-preserved-runtime-island' );
	$assert( isset( $enriched['form']['action'] ) && 'mailto:hello@example.com' === $enriched['form']['action'], 'enrich-carries-form-metadata' );
	$assert( isset( $enriched['controls'][0]['type'] ) && 'email' === $enriched['controls'][0]['type'], 'enrich-carries-controls' );
	$assert( 'generic/form-control-topology/v1' === ( $enriched['control_topology']['schema'] ?? '' ), 'enrich-carries-control-topology' );
	$assert( 'form' === ( $enriched['tag'] ?? '' ), 'enrich-tag-form' );
	$assert( Static_Site_Importer_Report_Diagnostics::has_materializable_form_findings( array( 'diagnostics' => array( $enriched ) ) ), 'form-finding-requires-provider-dependency' );
	$assert( ! Static_Site_Importer_Report_Diagnostics::has_materializable_form_findings( array( 'diagnostics' => array( array( 'diagnostic_code' => 'html_product_grid_fallback' ) ) ) ), 'non-form-finding-does-not-require-provider-dependency' );

	// --- Gate loop: a mapped form finding receives the runtime-mapped signal --
	$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.contact',
		'tag'             => 'form',
		'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
		'controls'        => array(
			array( 'tag' => 'input', 'type' => 'text', 'label' => 'Your name', 'required' => true ),
			array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	// A second form with no mappable controls must stay unmapped (unacceptable loss).
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.search-only',
		'tag'             => 'form',
		'controls'        => array(
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Go' ),
		),
	);

	$seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $report, array() );
	$assert( 'jetpack' === ( $seeding['provider'] ?? '' ), 'materialize-provider-jetpack' );
	$assert( 2 === ( $seeding['form_count'] ?? 0 ), 'materialize-counts-two-form-findings' );
	$assert( 1 === ( $seeding['mapped_count'] ?? 0 ), 'materialize-one-form-mapped' );

	$mapped   = $report['diagnostics'][0];
	$unmapped = $report['diagnostics'][1];
	$assert( true === ( $mapped['runtime_mapped'] ?? false ), 'finding-runtime-mapped-set' );
	$assert( 'jetpack' === ( $mapped['mapped_provider'] ?? '' ), 'finding-mapped-provider' );
	$assert( 'jetpack/contact-form' === ( $mapped['block_name'] ?? '' ), 'finding-block-name' );
	$assert( 'acceptable_preservation' === ( $mapped['acceptability'] ?? '' ), 'finding-acceptable-preservation' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $mapped ), 'finding-stays-preserved-runtime-island' );
	$assert( empty( $unmapped['runtime_mapped'] ), 'unmappable-form-stays-unsignaled' );

	// --- Mixed controls preserve provider work but gate unaccepted receipt loss --
	$mixed_control_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$mixed_control_report['diagnostics'][] = array(
		'type' => 'unsupported_html_fallback', 'diagnostic_code' => 'html_form_fallback', 'loss_class' => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path' => 'website/index.html', 'selector' => 'form.upload', 'tag' => 'form',
		'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'label' => 'Name' ), array( 'tag' => 'input', 'type' => 'file', 'label' => 'Attachment' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
		'control_topology' => array( 'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false, 'nodes' => array( array( 'id' => 'control-0', 'kind' => 'control', 'parent' => null, 'order' => 0, 'depth' => 0, 'control' => 0 ), array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ), array( 'id' => 'control-2', 'kind' => 'control', 'parent' => null, 'order' => 2, 'depth' => 0, 'control' => 2 ) ) ),
	);
	$mixed_control_seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $mixed_control_report, array() );
	$mixed_control_finding = $mixed_control_report['diagnostics'][0];
	$matrix_diagnostics = ( new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'compact_import_report_diagnostics' ) )->invoke( null, array( $mixed_control_finding ) );
	$assert( true === ( $mixed_control_finding['provider_mapped'] ?? false ) && false === ( $mixed_control_finding['runtime_mapped'] ?? true ) && 'unacceptable_imported_output_defect' === ( $mixed_control_finding['acceptability'] ?? '' ), 'mixed-file-control-provider-mapping-does-not-resolve-unaccepted-loss' );
	$assert( 'unsupported_control_unrepresentable' === ( $mixed_control_finding['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ) && 1 === ( $mixed_control_seeding['unaccepted_receipt_loss_count'] ?? 0 ) && 1 === count( $mixed_control_seeding['receipt_losses'] ?? array() ), 'mixed-file-control-receipt-loss-reaches-seeding-report' );
	$assert( 'unsupported_control_unrepresentable' === ( $matrix_diagnostics[0]['form_receipt_unaccepted_losses'][0]['reason_code'] ?? '' ), 'mixed-file-control-receipt-loss-reaches-matrix-intake' );

	// --- Graft bridges source HTML paths to generated post_content keys ---------
	$mapped_source_report                                                       = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$mapped_source_report['source_documents']['blocks_engine_documents'][]      = array(
		'source_path'  => 'website/index.html',
		'post_type'    => 'page',
		'slug'         => 'home',
		'materialized' => true,
	);
	$mapped_source_report['diagnostics'][]                                      = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.contact',
		'tag'             => 'form',
		'controls'        => array(
			array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
		'readable_blocks' => array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Email Send</p>',
				'innerContent' => array( '<p>Email Send</p>' ),
			),
		),
	);
	$mapped_source_contents                                                     = array( 'posts/page-home.post_content' => '<!-- wp:paragraph --><p>Email Send</p><!-- /wp:paragraph -->' );
	$mapped_source_seeding                                                      = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $mapped_source_report, array(), $mapped_source_contents );
	$assert( 1 === ( $mapped_source_seeding['grafted_count'] ?? 0 ), 'graft-source-document-to-post-content-key' );
	$assert( str_contains( (string) $mapped_source_contents['posts/page-home.post_content'], 'wp:jetpack/contact-form' ), 'graft-source-document-key-contact-form' );
	$assert( 'posts/page-home.post_content' === ( $mapped_source_report['diagnostics'][0]['graft_source_path'] ?? '' ), 'graft-source-path-recorded' );

	// --- Generated core/html form diagnostics materialize per page ---------------
	$core_html_form = '<form class="newsletter-form" action="#" method="post" novalidate><input type="email" name="email" placeholder="your@email.com" autocomplete="email" required aria-label="Email address"><button type="submit">Subscribe</button></form>';
	$core_html_block = static function ( string $html ): string {
		return '<!-- wp:html ' . json_encode( array( 'content' => $html ) ) . ' -->' . $html . '<!-- /wp:html -->';
	};
	$duplicate_generated_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	foreach ( array( 'posts/page-home.post_content', 'posts/page-contact.post_content' ) as $post_content_key ) {
		$duplicate_generated_report['diagnostics'][] = array(
			'type'                => 'core_html_block',
			'diagnostic_code'     => 'generated_document_contains_core_html',
			'reason_code'         => 'generated_document_contains_core_html',
			'loss_class'          => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
			'source_path'         => $post_content_key,
			'selector'            => 'form.newsletter-form',
			'tag_name'            => 'FORM',
			'block_name'          => 'core/html',
			'source_html_preview' => $core_html_form,
		);
	}
	$duplicate_generated_contents = array(
		'posts/page-home.post_content'    => '<!-- wp:group --><div class="wp-block-group">' . $core_html_block( $core_html_form ) . '</div><!-- /wp:group -->',
		'posts/page-contact.post_content' => '<!-- wp:group --><div class="wp-block-group">' . $core_html_block( $core_html_form ) . '</div><!-- /wp:group -->',
	);
	$duplicate_generated_seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $duplicate_generated_report, array(), $duplicate_generated_contents );
	$assert( 2 === ( $duplicate_generated_seeding['mapped_count'] ?? 0 ), 'graft-generated-duplicate-forms-mapped' );
	$assert( 2 === ( $duplicate_generated_seeding['grafted_count'] ?? 0 ), 'graft-generated-duplicate-forms-grafted' );
	$assert( str_contains( (string) $duplicate_generated_contents['posts/page-home.post_content'], 'wp:jetpack/contact-form' ), 'graft-generated-home-contact-form' );
	$assert( str_contains( (string) $duplicate_generated_contents['posts/page-contact.post_content'], 'wp:jetpack/contact-form' ), 'graft-generated-contact-contact-form' );
	$assert( ! str_contains( (string) $duplicate_generated_contents['posts/page-home.post_content'], '<!-- wp:html' ), 'graft-generated-home-core-html-removed' );
	$assert( ! str_contains( (string) $duplicate_generated_contents['posts/page-contact.post_content'], '<!-- wp:html' ), 'graft-generated-contact-core-html-removed' );

	// A source fallback delegates to its generated-document finding instead of
	// reporting a duplicate unanchorable graft after URL/class normalization.
	$delegated_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$delegated_report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'source_path'     => 'website/index.html',
		'selector'        => 'main > form',
		'form'            => array( 'class' => 'source-form', 'action' => 'index.html', 'method' => 'post' ),
		'controls'        => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
	);
	$delegated_report['diagnostics'][] = array(
		'type'                => 'core_html_block',
		'reason'              => 'generated_document_contains_core_html',
		'stage'               => 'generated_theme_block_analysis',
		'source'              => 'parts/footer.html',
		'source_path'         => 'parts/footer.html',
		'selector'            => 'form.generated-form',
		'tag_name'            => 'FORM',
		'block_name'          => 'core/html',
		'source_html_preview' => $core_html_form,
		'form'                => array( 'class' => 'newsletter-form', 'action' => '#', 'method' => 'post' ),
		'controls'            => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
	);
	$delegated_contents = array( 'parts/footer.html' => $core_html_block( $core_html_form ) );
	$delegated_seeding  = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $delegated_report, array(), $delegated_contents );
	$assert( true === ( $delegated_report['diagnostics'][0]['graft_delegated_to_generated_document'] ?? false ), 'source-form-graft-delegated' );
	$assert( 1 === ( $delegated_seeding['grafted_count'] ?? 0 ), 'delegated-generated-form-grafted-once' );
	$assert( 0 === count( array_filter( $delegated_report['diagnostics'], static fn ( array $diagnostic ): bool => 'form_block_graft_unanchorable' === ( $diagnostic['type'] ?? '' ) ) ), 'delegated-source-form-no-unanchorable-warning' );

	// --- Form finding enrich carries readable_blocks for graft anchoring --------
	$enrich_readable = $enrich->invoke(
		null,
		array(
			'diagnostic_code' => 'html_form_fallback',
			'reason'          => 'form_requires_runtime',
			'source_path'     => 'website/index.html',
			'selector'        => 'form.contact',
			'tag'             => 'form',
			'controls'        => array( array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ) ),
			'readable_blocks' => array( array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array() ) ),
		)
	);
	$assert( isset( $enrich_readable['readable_blocks'][0]['blockName'] ) && 'core/group' === $enrich_readable['readable_blocks'][0]['blockName'], 'enrich-carries-readable-blocks-for-graft' );

	// --- Graft: seeded contact-form markup replaces the readable fallback -------
	$transformer_available = function_exists( 'blocks_engine_php_transformer_transform_html' );
	$build_form_diagnostic = static function ( array $transformer_fallback, string $source_path ) use ( $enrich ): array {
		return $enrich->invoke(
			null,
			array(
				'diagnostic_code' => 'html_form_fallback',
				'reason'          => 'form_requires_runtime',
				'source_path'     => $source_path,
				'selector'        => $transformer_fallback['selector'] ?? '',
				'tag'             => 'form',
				'form'            => $transformer_fallback['form'] ?? array(),
				'controls'        => $transformer_fallback['controls'] ?? array(),
				'readable_blocks' => $transformer_fallback['readable_blocks'] ?? array(),
			)
		);
	};

	if ( $transformer_available ) {
		// Single-form page: text + email + textarea + submit.
		$single_html       = '<section><h2>Contact</h2><form class="contact" action="mailto:hello@example.com" method="post"><input id="name" type="text" name="name" required aria-label="Your name"><input id="email" type="email" name="email" required aria-label="Email"><textarea name="msg" aria-label="Message"></textarea><button type="submit">Send</button></form></section>';
		$single_transform  = blocks_engine_php_transformer_transform_html( $single_html );
		$single_serialized = (string) ( $single_transform['serialized_blocks'] ?? '' );
		$single_fallback   = $single_transform['fallbacks'][0] ?? array();

		$single_report                       = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/contact.html' );
		$single_report['diagnostics'][]      = $build_form_diagnostic( $single_fallback, 'website/contact.html' );
		$single_contents                     = array( 'website/contact.html' => $single_serialized );
		$single_seeding                      = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $single_report, array(), $single_contents );

		$single_grafted = (string) ( $single_contents['website/contact.html'] ?? '' );
		$single_finding = $single_report['diagnostics'][0] ?? array();
		$assert( 'completed' === ( $single_seeding['status'] ?? '' ), 'graft-seed-status-completed' );
		$assert( 1 === ( $single_seeding['mapped_count'] ?? 0 ), 'graft-one-form-mapped' );
		$assert( 1 === ( $single_seeding['grafted_count'] ?? 0 ), 'graft-one-form-grafted' );
		$assert( true === ( $single_finding['content_grafted'] ?? false ), 'graft-finding-content-grafted' );
		$assert( true === ( $single_finding['runtime_mapped'] ?? false ), 'graft-finding-runtime-mapped' );
		$assert( 'jetpack/contact-form' === ( $single_finding['block_name'] ?? '' ), 'graft-finding-block-name' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/contact-form' ), 'graft-content-has-contact-form' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-text' ), 'graft-content-has-field-text' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-email' ), 'graft-content-has-field-email' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-textarea' ), 'graft-content-has-field-textarea' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/button' ), 'graft-content-has-submit-button' );
		$assert( ! str_contains( $single_grafted, 'Your name (required)' ), 'graft-content-drops-paragraph-fallback' );
		$assert( str_contains( $single_grafted, 'Contact' ), 'graft-content-preserves-surrounding-content' );
		$assert( ! str_contains( $single_grafted, '<!-- wp:html' ), 'graft-content-has-no-core-html-island' );

		// Multi-form page: two forms on one page graft independently.
		$multi_html       = '<section><h2>Contact A</h2><form class="contact-a" action="mailto:a@example.com" method="post"><input id="a-email" type="email" name="email" required aria-label="Email"><textarea name="msg" aria-label="Message"></textarea><button type="submit">Send A</button></form></section><section><h2>Contact B</h2><form class="contact-b" action="mailto:b@example.com" method="post"><input id="b-name" type="text" name="name" required aria-label="Name"><button type="submit">Send B</button></form></section>';
		$multi_transform  = blocks_engine_php_transformer_transform_html( $multi_html );
		$multi_serialized = (string) ( $multi_transform['serialized_blocks'] ?? '' );

		$multi_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/contact.html' );
		foreach ( $multi_transform['fallbacks'] ?? array() as $multi_fallback ) {
			$multi_report['diagnostics'][] = $build_form_diagnostic( $multi_fallback, 'website/contact.html' );
		}
		$multi_contents = array( 'website/contact.html' => $multi_serialized );
		$multi_seeding  = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $multi_report, array(), $multi_contents );

		$multi_grafted = (string) ( $multi_contents['website/contact.html'] ?? '' );
		$assert( 2 === ( $multi_seeding['mapped_count'] ?? 0 ), 'graft-multi-two-forms-mapped' );
		$assert( 2 === ( $multi_seeding['grafted_count'] ?? 0 ), 'graft-multi-two-forms-grafted' );
		// Each form contributes one opening contact-form comment delimiter.
		$assert( 2 === substr_count( $multi_grafted, '<!-- wp:jetpack/contact-form' ), 'graft-multi-two-contact-form-blocks' );
		$assert( str_contains( $multi_grafted, 'wp:jetpack/field-email' ), 'graft-multi-form-a-field-email' );
		$assert( str_contains( $multi_grafted, 'wp:jetpack/field-text' ), 'graft-multi-form-b-field-text' );
		$assert( ! str_contains( $multi_grafted, 'Send A</a>' ), 'graft-multi-drops-form-a-fallback' );
		$assert( str_contains( $multi_grafted, 'Contact A' ) && str_contains( $multi_grafted, 'Contact B' ), 'graft-multi-preserves-both-sections' );
	}

	// --- Graft leaves an unanchorable finding's fallback in place --------------
	$unanchorable_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/unanchorable.html' );
	$unanchorable_report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/unanchorable.html',
		'selector'        => 'form.no-readable',
		'tag'             => 'form',
		'form'            => array(),
		'controls'        => array( array( 'tag' => 'input', 'type' => 'text', 'label' => 'Name' ) ),
	);
	$unanchorable_contents                = array( 'website/unanchorable.html' => '<!-- wp:paragraph --><p>keep this fallback page</p><!-- /wp:paragraph -->' );
	$unanchorable_seeding                 = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $unanchorable_report, array(), $unanchorable_contents );

	$unanchorable_grafted = (string) ( $unanchorable_contents['website/unanchorable.html'] ?? '' );
	$unanchorable_finding = $unanchorable_report['diagnostics'][0] ?? array();
	$unanchorable_diag    = null;
	foreach ( $unanchorable_report['diagnostics'] ?? array() as $unanchorable_row ) {
		if ( is_array( $unanchorable_row ) && 'form_block_graft_unanchorable' === ( $unanchorable_row['type'] ?? '' ) ) {
			$unanchorable_diag = $unanchorable_row;
			break;
		}
	}
	$assert( 1 === ( $unanchorable_seeding['mapped_count'] ?? 0 ), 'graft-unanchorable-still-mapped' );
	$assert( 0 === ( $unanchorable_seeding['grafted_count'] ?? 0 ), 'graft-unanchorable-not-grafted' );
	$assert( true === ( $unanchorable_finding['runtime_mapped'] ?? false ), 'graft-unanchorable-runtime-mapped-kept' );
	$assert( false === ( $unanchorable_finding['content_grafted'] ?? true ), 'graft-unanchorable-content-not-grafted' );
	$assert( null !== $unanchorable_diag, 'graft-unanchorable-diagnostic-recorded' );
	$assert( 'html_form_fallback_graft_unanchorable' === ( $unanchorable_diag['diagnostic_code'] ?? '' ), 'graft-unanchorable-diagnostic-code' );
	$assert( 'no_readable_fallback_blocks' === ( $unanchorable_diag['reason'] ?? '' ), 'graft-unanchorable-reason' );
	$assert( '<!-- wp:paragraph --><p>keep this fallback page</p><!-- /wp:paragraph -->' === $unanchorable_grafted, 'graft-unanchorable-fallback-left-in-place' );

	// --- Provider override routes to a different registered adapter ----------
	add_filter(
		'static_site_importer_entity_materializers',
		static function ( array $adapters ): array {
			$adapters['gravity_forms_adapter'] = array(
				'id'         => 'gravity_forms_adapter',
				'capability' => 'form',
				'provider'   => 'gravity_forms',
				'waiver_arg' => 'allow_missing_gravity_forms',
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
		echo wp_json_encode( array( 'markup' => $topology_markup, 'depth_markup' => $deep_topology_markup ) ) . "\n";
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
