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
		foreach ( array( 'init-before-after', 'missing-loader', 'missing-init', 'partial-blocks' ) as $child_case ) {
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
	$GLOBALS['ssi_init_actions']          = 'init-before-after' === $case ? 0 : 1;
	$GLOBALS['ssi_missing_blocks']        = 'partial-blocks' === $case ? array( 'jetpack/field-email' ) : array();

	class WP_Error {
		public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function did_action( string $hook ): int { return 'init' === $hook ? $GLOBALS['ssi_init_actions'] : 0; }

	class Jetpack {
		public static int $activations = 0;
		public static function activate_module( string $module, bool $redirect, bool $options ): bool {
			unset( $redirect, $options );
			++self::$activations;
			$GLOBALS['ssi_contact_form_active'] = 'contact-form' === $module;
			return true;
		}
	}
	class WP_Block_Type_Registry {
		public static function get_instance(): self { return new self(); }
		public function is_registered( string $name ): bool { return ! in_array( $name, $GLOBALS['ssi_missing_blocks'], true ); }
	}
}

namespace Automattic\Jetpack {
	class Modules {
		public function is_active( string $module ): bool { return 'contact-form' === $module && ! empty( $GLOBALS['ssi_contact_form_active'] ); }
	}
}

namespace Automattic\Jetpack\Forms {
	if ( 'missing-loader' !== $GLOBALS['ssi_provider_adapter_case'] ) {
		class Jetpack_Forms {
			public static int $loads = 0;
			public static function load_contact_form(): void { ++self::$loads; }
		}
	}
}

namespace Automattic\Jetpack\Forms\ContactForm {
	class Contact_Form {}
	if ( 'missing-init' !== $GLOBALS['ssi_provider_adapter_case'] ) {
		class Contact_Form_Plugin {
			public static int $initializations = 0;
			public static function init(): void { ++self::$initializations; }
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';

	$result = Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime();
	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			echo 'FAIL ' . $message . "\n";
			exit( 1 );
		}
	};

	if ( 'init-before-after' === $case ) {
		$assert( true === $result, 'initial preparation succeeds' );
		$assert( 1 === Jetpack::$activations, 'contact-form activates once without a connection' );
		$assert( 1 === \Automattic\Jetpack\Forms\Jetpack_Forms::$loads, 'canonical loader runs before init' );
		$assert( 0 === \Automattic\Jetpack\Forms\ContactForm\Contact_Form_Plugin::$initializations, 'init hook remains deferred before init' );
		$GLOBALS['ssi_init_actions'] = 1;
		$assert( true === Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime(), 'late preparation succeeds' );
		$assert( 1 === \Automattic\Jetpack\Forms\ContactForm\Contact_Form_Plugin::$initializations, 'late canonical init runs once' );
		$assert( true === Static_Site_Importer_Form_Seeder::prepare_jetpack_forms_runtime(), 'repeated preparation succeeds' );
		$assert( 1 === \Automattic\Jetpack\Forms\ContactForm\Contact_Form_Plugin::$initializations, 'late canonical init is idempotent' );
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
