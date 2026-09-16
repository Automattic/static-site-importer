<?php
/**
 * Jetpack Forms runtime bootstrap and availability.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
}

/**
 * Loads and reports Jetpack Forms runtime readiness.
 */
final class Static_Site_Importer_Jetpack_Forms_Runtime {
	/** Whether this process has explicitly completed the late Jetpack Forms init. */
	private static bool $jetpack_forms_initialized = false;

	/** Register the provider bootstrap needed on every WordPress request. */
	public static function register_runtime_bootstrap(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'jetpack_loaded', array( __CLASS__, 'bootstrap_jetpack_forms_runtime' ) );
		}
		if ( function_exists( 'add_filter' ) ) {
			Static_Site_Importer_Provider_Form_Runtime_V1::register();
		}
	}

	/**
	 * Load Forms after Jetpack's autoloader is ready and before WordPress init.
	 *
	 * Jetpack 16 skips its normal after_setup_theme module loader for disconnected
	 * sites. Its persisted contact-form module flag therefore needs this adapter
	 * bootstrap on later frontend requests as well as during import preparation.
	 */
	public static function bootstrap_jetpack_forms_runtime(): void {
		if ( ! self::runtime_static_method_exists( 'Jetpack', 'is_module_active' ) || ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( 'contact-form' ) ) ) {
			return;
		}

		$loader = 'Automattic\\Jetpack\\Forms\\Jetpack_Forms';
		if ( self::runtime_static_method_exists( $loader, 'load_contact_form' ) ) {
			self::invoke_runtime_static_method( $loader, 'load_contact_form' );
		}
	}

	/**
	 * Map a source control type to a Jetpack field block name.
	 *
	 * @return array<string,string>
	 */
	public static function field_block_map(): array {
		return array(
			'text'     => 'jetpack/field-text',
			'search'   => 'jetpack/field-text',
			'password' => 'jetpack/field-text',
			'number'   => 'jetpack/field-number',
			'email'    => 'jetpack/field-email',
			'tel'      => 'jetpack/field-telephone',
			'phone'    => 'jetpack/field-telephone',
			'url'      => 'jetpack/field-url',
			'date'     => 'jetpack/field-date',
			'textarea' => 'jetpack/field-textarea',
			'select'   => 'jetpack/field-select',
			'checkbox' => 'jetpack/field-checkbox',
			'radio'    => 'jetpack/field-radio',
		);
	}

	/** @return array<int,string> Every Jetpack block type the adapter can emit. */
	public static function required_block_types(): array {
		return array_values( array_unique( array_merge( array( 'jetpack/contact-form', 'jetpack/field-checkbox-multiple', 'jetpack/input', 'jetpack/label', 'jetpack/option', 'jetpack/options', 'jetpack/phone-input' ), array_values( self::field_block_map() ) ) ) );
	}

	/** @return array<int,string> Provider APIs required by the declared adapter. */
	public static function required_runtime_apis(): array {
		return array( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' );
	}

	/**
	 * Determine whether the Jetpack Forms runtime is available to host seeded forms.
	 *
	 * Public so the registry availability callback and the dependency gate can run
	 * before forms are materialized into a runtime that can carry submissions.
	 *
	 * @return bool
	 */
	public static function jetpack_forms_available(): bool {
		$availability = self::jetpack_forms_availability_details();
		return ! empty( $availability['available'] );
	}

	/** Activate and prepare Jetpack Forms through its canonical module lifecycle. */
	public static function prepare_jetpack_forms_runtime() {
		$lifecycle_apis         = array(
			'Jetpack::is_module_active'         => self::runtime_static_method_exists( 'Jetpack', 'is_module_active' ),
			'Jetpack::activate_default_modules' => self::runtime_static_method_exists( 'Jetpack', 'activate_default_modules' ),
		);
		$missing_lifecycle_apis = array_keys( array_filter( $lifecycle_apis, static fn ( bool $available ): bool => ! $available ) );
		if ( ! empty( $missing_lifecycle_apis ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_lifecycle_missing', $missing_lifecycle_apis );
		}

		$required_modules = array( 'blocks', 'contact-form' );
		$inactive_modules = array_values( array_filter(
			$required_modules,
			static fn ( string $module ): bool => ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( $module ) )
		) );
		$availability     = self::jetpack_forms_availability_details();
		if ( empty( $inactive_modules ) && ! empty( $availability['available'] ) ) {
			return true;
		}

		if ( ! empty( $inactive_modules ) ) {
			// Jetpack uses this inverted range to activate only explicitly supplied defaults.
			self::invoke_runtime_static_method( 'Jetpack', 'activate_default_modules', array( 999, 1, $inactive_modules, false, false ) );
			$inactive_modules = array_values( array_filter(
				$required_modules,
				static fn ( string $module ): bool => ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( $module ) )
			) );
			if ( ! empty( $inactive_modules ) ) {
				return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_activation_failed', $inactive_modules );
			}
		}

		$loader = 'Automattic\\Jetpack\\Forms\\Jetpack_Forms';
		if ( ! self::runtime_static_method_exists( $loader, 'load_contact_form' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_loader_missing', array( $loader . '::load_contact_form' ) );
		}

		$initializer = 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form_Plugin';
		if ( ! self::runtime_static_method_exists( $initializer, 'init' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_init_missing', array( $initializer . '::init' ) );
		}

		$init_callback_loaded = function_exists( 'has_action' ) && (
			false !== has_action( 'init', '\\' . $initializer . '::init' )
			|| false !== has_action( 'init', $initializer . '::init' )
		);
		if ( ! $init_callback_loaded ) {
			self::invoke_runtime_static_method( $loader, 'load_contact_form' );
		}

		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_init_pending', array( 'init' ) );
		}

		if ( ! self::$jetpack_forms_initialized ) {
			self::invoke_runtime_static_method( $initializer, 'init' );
			self::$jetpack_forms_initialized = true;
		}

		$availability = self::jetpack_forms_availability_details();
		if ( ! empty( $availability['available'] ) ) {
			return true;
		}

		return self::jetpack_forms_runtime_error(
			'static_site_importer_jetpack_forms_blocks_missing',
			array_keys( array_filter( $availability['required_blocks'], static fn ( bool $registered ): bool => ! $registered ) ),
			$availability
		);
	}

	/** Check an extension-owned static API without binding analysis to that extension's stubs. */
	public static function runtime_static_method_exists( string $class_name, string $method ): bool {
		return class_exists( $class_name ) && method_exists( $class_name, $method );
	}

	/**
	 * Invoke an extension-owned static API after runtime_static_method_exists() succeeds.
	 *
	 * @phpstan-impure
	 */
	public static function invoke_runtime_static_method( string $class_name, string $method, array $args = array() ) {
		return ( new ReflectionMethod( $class_name, $method ) )->invokeArgs( null, $args );
	}

	/** Build a bounded provider-readiness error. */
	public static function jetpack_forms_runtime_error( string $code, array $missing, array $details = array() ): WP_Error {
		return new WP_Error(
			$code,
			'Jetpack Forms provider runtime is not ready.',
			array_filter(
				array(
					'missing' => array_slice( array_values( $missing ), 0, 20 ),
					'details' => $details,
				)
			)
		);
	}

	/**
	 * Return the specific Jetpack Forms APIs present in the current runtime.
	 *
	 * @return array<string,mixed>
	 */
	public static function jetpack_forms_availability_details(): array {
		$required_apis = array();
		foreach ( self::required_runtime_apis() as $api ) {
			$required_apis[ $api ] = class_exists( $api );
		}
		$contact_form_class = ! empty( $required_apis['Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form'] );
		$legacy_class       = class_exists( 'Grunion_Contact_Form' ) || class_exists( 'Contact_Form' );
		$registered_blocks  = array_fill_keys( self::required_block_types(), false );

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			foreach ( array_keys( $registered_blocks ) as $block_name ) {
				$registered_blocks[ $block_name ] = $registry->is_registered( $block_name );
			}
		}
		$contact_form_block        = $registered_blocks['jetpack/contact-form'];
		$field_text_block          = $registered_blocks['jetpack/field-text'];
		$required_blocks_available = ! empty( $registered_blocks ) && ! in_array( false, $registered_blocks, true );
		$required_apis_available   = ! empty( $required_apis ) && ! in_array( false, $required_apis, true );

		return array(
			'available'          => $required_apis_available && $contact_form_block && $required_blocks_available,
			'contact_form_class' => $contact_form_class,
			'legacy_class'       => $legacy_class,
			'contact_form_block' => $contact_form_block,
			'field_text_block'   => $field_text_block,
			'required_apis'      => $required_apis,
			'required_blocks'    => $registered_blocks,
			'registered_blocks'  => $registered_blocks,
		);
	}
}
