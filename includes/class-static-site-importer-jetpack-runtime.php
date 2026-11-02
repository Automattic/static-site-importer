<?php
/**
 * Shared Jetpack module lifecycle for native providers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Jetpack_Runtime {
	/** Activate only the modules required by the selected provider. */
	public static function prepare_modules( array $modules ) {
		foreach ( array( 'is_module_active', 'activate_default_modules' ) as $method ) {
			if ( ! Static_Site_Importer_Jetpack_Forms_Runtime::runtime_static_method_exists( 'Jetpack', $method ) ) {
				return new WP_Error( 'static_site_importer_jetpack_lifecycle_missing', 'Jetpack module lifecycle is unavailable.', array( 'missing' => array( 'Jetpack::' . $method ) ) );
			}
		}
		$inactive = array_values( array_filter( $modules, static fn( string $module ): bool => ! Static_Site_Importer_Jetpack_Forms_Runtime::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( $module ) ) ) );
		if ( ! empty( $inactive ) ) {
			Static_Site_Importer_Jetpack_Forms_Runtime::invoke_runtime_static_method( 'Jetpack', 'activate_default_modules', array( 999, 1, $inactive, false, false ) );
		}
		foreach ( $modules as $module ) {
			if ( ! Static_Site_Importer_Jetpack_Forms_Runtime::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( $module ) ) ) {
				return new WP_Error( 'static_site_importer_jetpack_activation_failed', 'Jetpack module activation failed.', array( 'module' => $module ) );
			}
		}
		return true;
	}

	/** Load active Blocks on disconnected sites before init, just like Forms. */
	public static function bootstrap_blocks(): void {
		$runtime = Static_Site_Importer_Jetpack_Forms_Runtime::class;
		if ( ! $runtime::runtime_static_method_exists( 'Jetpack', 'is_module_active' ) || ! $runtime::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( 'blocks' ) ) ) {
			return;
		}
		foreach ( array( 'load_block_editor_extensions', 'load_independent_blocks', 'register_block_metadata_collection' ) as $method ) {
			if ( $runtime::runtime_static_method_exists( 'Jetpack_Gutenberg', $method ) ) {
				$runtime::invoke_runtime_static_method( 'Jetpack_Gutenberg', $method );
			}
		}
	}
}
