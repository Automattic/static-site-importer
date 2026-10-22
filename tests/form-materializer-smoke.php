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
	if ( ! function_exists( 'has_filter' ) ) {
		function has_filter( string $hook ): bool {
			return ! empty( $GLOBALS['ssi_test_hooks'][ $hook ] );
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

	// The deterministic gate invokes this file directly in a fresh worktree.
	// Install the locked test dependencies if either the parser or compiler is
	// missing from the supplied runtimes.
	$repo_root        = dirname( __DIR__ );
	$wp_root          = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: $repo_root . '/vendor/wordpress-core';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: $repo_root . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer_bootstrap = rtrim( $transformer_root, '/\\' ) . '/php-transformer.php';
	// The producer can be supplied as either its package root or the Blocks
	// Engine checkout. The latter keeps the PHP package in php-transformer/.
	if ( ! is_readable( $transformer_bootstrap ) && is_readable( rtrim( $transformer_root, '/\\' ) . '/php-transformer/php-transformer.php' ) ) {
		$transformer_bootstrap = rtrim( $transformer_root, '/\\' ) . '/php-transformer/php-transformer.php';
	}
	if ( ( ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php' ) || ! is_readable( $transformer_bootstrap ) ) && is_file( $repo_root . '/composer.lock' ) ) {
		passthru( 'composer --working-dir=' . escapeshellarg( $repo_root ) . ' install --no-interaction --prefer-dist --no-progress 2>&1', $install_status );
		if ( 0 !== $install_status ) {
			fwrite( STDERR, "FAIL: Could not install locked smoke-test dependencies.\n" );
			exit( 1 );
		}
	}
	$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
	$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
	if ( is_readable( $parser ) && is_readable( $blocks ) ) {
		require_once $parser;
		require_once $blocks;
	}
	if ( ! function_exists( 'serialize_blocks' ) ) {
		// This source-only test needs core's parser and serializer; a missing
		// dependency here must fail closed rather than silently report success.
		fwrite( STDERR, "FAIL: WordPress block serialization is unavailable. Run composer install or set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
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

	if ( is_readable( $transformer_bootstrap ) ) {
		require_once $transformer_bootstrap;
	}

	$failures   = array();
	$assertions = 0;
	// Count declarations per selector, whether equivalent targets share a rule or not.
	$expand_selector_lists = static function ( string $css ): string {
		return preg_replace_callback(
			'/^((?:@(?:media|container) [^{}\n]+\{)*)([^{}\n]+)\{([^{}\n]*)\}(\}*)$/m',
			static fn( array $rule ): string => implode( "\n", array_map( static fn( string $selector ): string => $rule[1] . $selector . '{' . $rule[3] . '}' . $rule[4], explode( ', ', $rule[2] ) ) ),
			$css
		) ?? $css;
	};
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

	// --- Shared fixtures ----------------------------------------------------
	// Suites read only these, the harness and the helpers above, so each suite
	// also runs alone: php tests/form-materializer-smoke.php --suite=<name>.
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
	$topology_seed = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated_topology['forms'] ) );
	$topology_markup = (string) ( $topology_seed['forms'][0]['block_markup'] ?? '' );

	$presentation_form = $topology_form;
	$presentation_role = static function ( array $styles, array $properties, string $selector ): array {
		return array(
			'styles'     => $styles,
			'provenance' => array( array( 'source_path' => 'assets/forms.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => $selector, 'condition' => null, 'properties' => $properties ) ),
		);
	};
	$presentation_form['forms'][0]['presentation_graph'] = array(
		'schema' => 'generic/computed-form-presentation/v1', 'basis' => 'source_css_cascade', 'truncated' => false, 'limits' => array( 'controls' => 128, 'rules_per_role' => 96 ), 'variants' => array(), 'diagnostics' => array(),
		'controls' => array(
			array(
				'index'   => 0,
				'control' => $presentation_role( array( 'background_color' => 'transparent', 'border' => '0', 'padding' => '8px 0', 'font_size' => '16px', 'line_height' => '24px' ), array( 'background-color', 'border', 'padding', 'font-size', 'line-height' ), 'input' ),
				'label'   => $presentation_role( array( 'font_size' => '14px', 'font_weight' => '400', 'line_height' => '1.4', 'margin_bottom' => '8px' ), array( 'font-size', 'font-weight', 'line-height', 'margin-bottom' ), 'label' ),
			),
			array( 'index' => 3, 'control' => $presentation_role( array( 'background_color' => 'rgb(254,126,3)', 'color' => '#fff', 'border' => '0', 'border_radius' => '100px', 'padding' => '11px 15px', 'font_size' => '16px' ), array( 'background-color', 'color', 'border', 'border-radius', 'padding', 'font-size' ), 'button' ) ),
		),
	);

	$compile_form = static function ( string $css ) use ( $artifact_compiler ): array {
		$compiled = ( new $artifact_compiler() )->compile( array(
			'entrypoint' => 'index.html',
			'files'      => array( 'index.html' => '<style>' . $css . '</style><form><input type="email" name="email"><button type="submit">Send</button></form>' ),
		) )->toArray();
		return $compiled['fallbacks'][0] ?? array();
	};

	$class_owned_form = $validated_topology['forms'][0];
	$class_owned_form['layout_graph']['nodes'][] = array( 'id' => 'wrapper-1', 'kind' => 'container', 'parent' => 'wrapper-0', 'order' => 0, 'source' => array( 'tag' => 'div', 'classes' => array( 'field' ) ), 'layout' => array( 'display' => 'flex', 'direction' => 'column' ), 'provenance' => array( array( 'selector' => '.field' ) ) );

	$root_map = array( 'schema' => 'generic/provider-layout-target-map/v1', 'provider' => 'jetpack', 'scope' => '.ssi-form-123456789abc', 'targets' => array( array( 'node' => 'form', 'selector' => '.ssi-form-123456789abc > form.jetpack-contact-form__form, .ssi-form-123456789abc:not(:has(> form.jetpack-contact-form__form))', 'capabilities' => array( 'container_layout', 'responsive_layout' ) ) ) );

	$suite_argument = array_values( array_filter( $argv ?? array(), static fn( string $argument ): bool => str_starts_with( $argument, '--suite=' ) ) );
	foreach ( array( 'hidden-ancestor', 'adapter-and-field-mapping', 'grid-rows', 'row-and-cell-layout', 'textarea-and-wrappers', 'presentation', 'compiled-presentation-and-placement', 'hidden-controls-and-fieldsets', 'provider-runtime-and-overlay', 'receipts-and-source-boxes' ) as $suite ) {
		if ( empty( $suite_argument ) || '--suite=' . $suite === $suite_argument[0] ) {
			require __DIR__ . '/form-materializer-' . $suite . '.inc';
		}
	}

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
