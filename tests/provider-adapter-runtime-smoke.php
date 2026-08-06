<?php
/**
 * Provider adapter lifecycle coverage for the Jetpack Forms runtime.
 *
 * @package StaticSiteImporter
 */

namespace {
	$case = $argv[1] ?? '';
	if ( '' === $case ) {
		$failures = array();
		foreach ( array( 'supported-late', 'already-active-late', 'init-pending', 'activation-failed', 'missing-lifecycle', 'missing-loader', 'missing-init', 'partial-blocks' ) as $child_case ) {
			$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $child_case );
			exec( $command, $output, $status );
			if ( 0 !== $status ) {
				$failures[] = $child_case . ': ' . implode( "\n", $output );
			}
			$output = array();
		}
		if ( empty( $failures ) ) {
			echo "PASS provider-adapter-runtime-smoke.php\n";
			exit( 0 );
		}
		echo "FAILURES:\n" . implode( "\n", $failures ) . "\n";
		exit( 1 );
	}

	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	$GLOBALS['ssi_provider_adapter_case'] = $case;
	$GLOBALS['ssi_init_actions']          = 'init-pending' === $case ? 0 : 1;
	$GLOBALS['ssi_hooks']                 = array();
	$GLOBALS['ssi_missing_blocks']        = 'partial-blocks' === $case ? array( 'jetpack/field-email' ) : array();

	class WP_Error {
		public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function did_action( string $hook ): int { return 'init' === $hook ? $GLOBALS['ssi_init_actions'] : 0; }
	function add_action( string $hook, $callback ): void { $GLOBALS['ssi_hooks'][ $hook ][] = $callback; }
	function has_action( string $hook, $callback = false ) {
		foreach ( $GLOBALS['ssi_hooks'][ $hook ] ?? array() as $registered ) {
			if ( $registered === $callback || ltrim( (string) $registered, '\\' ) === ltrim( (string) $callback, '\\' ) ) {
				return 9;
			}
		}
		return false;
	}
	function ssi_run_init(): void {
		$GLOBALS['ssi_init_actions'] = 1;
		foreach ( $GLOBALS['ssi_hooks']['init'] ?? array() as $callback ) {
			call_user_func( $callback );
		}
	}

	if ( 'missing-lifecycle' === $case ) {
		class Jetpack {
			public static function is_module_active( string $module ): bool { return false; }
		}
	} else {
		class Jetpack {
			public static int $default_activations = 0;
			public static bool $contact_form_active = false;
			public static function is_module_active( string $module ): bool {
				return 'contact-form' === $module && self::$contact_form_active;
			}
			public static function activate_default_modules( int $min, int $max, array $modules, bool $network_wide, bool $reactivate ): void {
				unset( $network_wide, $reactivate );
				++self::$default_activations;
				if ( 'activation-failed' === $GLOBALS['ssi_provider_adapter_case'] ) {
					return;
				}
				self::$contact_form_active = 999 === $min && 1 === $max && array( 'contact-form' ) === $modules;
				if ( self::$contact_form_active && class_exists( 'Automattic\\Jetpack\\Forms\\Jetpack_Forms' ) ) {
					\Automattic\Jetpack\Forms\Jetpack_Forms::load_contact_form();
				}
			}
		}
	}

	class WP_Block_Type_Registry {
		private static ?self $instance = null;
		private array $registered = array();
		public static function get_instance(): self { return self::$instance ??= new self(); }
		public function register( string $name ): void { $this->registered[ $name ] = true; }
		public function is_registered( string $name ): bool { return isset( $this->registered[ $name ] ) && ! in_array( $name, $GLOBALS['ssi_missing_blocks'], true ); }
	}
}

namespace Automattic\Jetpack\Forms {
	if ( 'missing-loader' !== $GLOBALS['ssi_provider_adapter_case'] ) {
		class Jetpack_Forms {
			public static int $loads = 0;
			public static function load_contact_form(): void {
				++self::$loads;
				\add_action( 'init', '\\Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form_Plugin::init' );
			}
		}
	}
}

namespace Automattic\Jetpack\Forms\ContactForm {
	class Contact_Form {}
	if ( 'missing-init' !== $GLOBALS['ssi_provider_adapter_case'] ) {
		class Contact_Form_Plugin {
			public static int $initializations = 0;
			public static function init(): void {
				if ( self::$initializations ) {
					return;
				}
				++self::$initializations;
				foreach ( \Static_Site_Importer_Form_Seeder::required_block_types() as $block_name ) {
					\WP_Block_Type_Registry::get_instance()->register( $block_name );
				}
			}
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';

	if ( 'already-active-late' === $case ) {
		Jetpack::$contact_form_active = true;
	}

	$result = Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime();
	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			echo 'FAIL ' . $message . "\n";
			exit( 1 );
		}
	};

	if ( 'supported-late' === $case ) {
		$assert( true === $result, 'late preparation succeeds' );
		$assert( 1 === Jetpack::$default_activations, 'only the explicit default module is activated' );
		$assert( 1 === \Automattic\Jetpack\Forms\Jetpack_Forms::$loads, 'module lifecycle loads Forms once' );
		$assert( 1 === \Automattic\Jetpack\Forms\ContactForm\Contact_Form_Plugin::$initializations, 'late singleton init runs once' );
		$assert( true === Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime(), 'repeated preparation succeeds' );
		$assert( 1 === Jetpack::$default_activations && 1 === \Automattic\Jetpack\Forms\Jetpack_Forms::$loads, 'repeated preparation does not replay lifecycle work' );
	}
	if ( 'already-active-late' === $case ) {
		$assert( true === $result, 'active module is prepared' );
		$assert( 0 === Jetpack::$default_activations, 'active module is not reactivated' );
		$assert( 1 === \Automattic\Jetpack\Forms\Jetpack_Forms::$loads, 'missing package hook is installed once' );
	}
	if ( 'init-pending' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_init_pending' === $result->get_error_code(), 'pre-init preparation is bounded' );
		ssi_run_init();
		$assert( true === Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime(), 'normal init hook completes readiness' );
		$assert( 1 === Jetpack::$default_activations && 1 === \Automattic\Jetpack\Forms\Jetpack_Forms::$loads, 'init completion does not replay lifecycle work' );
	}
	if ( 'activation-failed' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_activation_failed' === $result->get_error_code(), 'failed default activation is specific' );
	}
	if ( 'missing-lifecycle' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_lifecycle_missing' === $result->get_error_code(), 'missing lifecycle API is bounded' );
		$assert( array( 'Jetpack::activate_default_modules' ) === $result->get_error_data()['missing'], 'missing lifecycle API is identified' );
	}
	if ( 'missing-loader' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_loader_missing' === $result->get_error_code(), 'missing loader is bounded' );
	}
	if ( 'missing-init' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_init_missing' === $result->get_error_code(), 'missing init is bounded' );
	}
	if ( 'partial-blocks' === $case ) {
		$assert( is_wp_error( $result ) && 'static_site_importer_jetpack_forms_blocks_missing' === $result->get_error_code(), 'partial block registry fails readiness' );
		$assert( array( 'jetpack/field-email' ) === $result->get_error_data()['missing'], 'missing block diagnostic is bounded and specific' );
	}
}
