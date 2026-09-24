<?php
/**
 * Layout adapter registry.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-layout-adapter.php';
}
if ( ! class_exists( 'Static_Site_Importer_None_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-none-layout-adapter.php';
}
if ( ! class_exists( 'Static_Site_Importer_Canvas_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-canvas-layout-adapter.php';
}

/**
 * Resolves the `layout` capability to exactly one registered layout adapter.
 *
 * The capability follows the entity materializer registry contract: a default
 * provider (`none`), a core option override, a capability-scoped provider
 * filter, and adapters registered through the
 * `static_site_importer_layout_adapters` filter. A configured provider without
 * a matching adapter resolves to nothing, never to a different adapter.
 */
final class Static_Site_Importer_Layout_Adapter_Registry {

	public const CAPABILITY             = 'layout';
	public const ADAPTERS_FILTER        = 'static_site_importer_layout_adapters';
	public const CROSS_PROVIDER_FILTER  = 'ssi_entity_materializer_provider';

	/**
	 * Per-capability provider selection contract.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function capabilities(): array {
		return array(
			self::CAPABILITY => array(
				'default_provider' => 'none',
				'option'           => 'static_site_importer_layout_plugin',
				'filter'           => 'ssi_layout_plugin',
			),
		);
	}

	/**
	 * Resolve the selected provider id for a capability.
	 *
	 * Resolution order: capability default, core option override, the
	 * capability-scoped filter, then the cross-capability provider filter.
	 *
	 * @param string $capability Capability key.
	 * @return string
	 */
	public static function provider_for( string $capability ): string {
		$capabilities = self::capabilities();
		$config       = $capabilities[ $capability ] ?? array();
		$provider     = (string) ( $config['default_provider'] ?? '' );

		$option_key = (string) ( $config['option'] ?? '' );
		if ( '' !== $option_key && function_exists( 'get_option' ) ) {
			$stored = get_option( $option_key, '' );
			if ( is_string( $stored ) && '' !== trim( $stored ) ) {
				$provider = trim( $stored );
			}
		}

		$capability_filter = (string) ( $config['filter'] ?? '' );
		if ( '' !== $capability_filter && function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the provider selected for the layout capability.
			 *
			 * @param string $provider   Selected provider id.
			 * @param string $capability Capability key.
			 */
			$provider = (string) apply_filters( $capability_filter, $provider, $capability );
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the provider selected for any materializer capability.
			 *
			 * @param string $provider   Selected provider id.
			 * @param string $capability Capability key.
			 */
			$provider = (string) apply_filters( self::CROSS_PROVIDER_FILTER, $provider, $capability );
		}

		return $provider;
	}

	/**
	 * Return the registered adapters, keyed by id.
	 *
	 * @return array<string,Static_Site_Importer_Layout_Adapter>
	 */
	public static function adapters(): array {
		$adapters = array();
		foreach ( array( 'Static_Site_Importer_None_Layout_Adapter', 'Static_Site_Importer_Canvas_Layout_Adapter' ) as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}
			$adapter = new $adapter_class();
			if ( ! $adapter instanceof Static_Site_Importer_Layout_Adapter ) {
				continue;
			}
			$adapters[ $adapter->id() ] = $adapter;
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters registered SSI layout adapters.
			 *
			 * @param array<string,Static_Site_Importer_Layout_Adapter> $adapters Adapter instances keyed by id.
			 */
			$filtered = apply_filters( self::ADAPTERS_FILTER, $adapters );
			if ( is_array( $filtered ) ) {
				foreach ( $filtered as $id => $adapter ) {
					if ( ! $adapter instanceof Static_Site_Importer_Layout_Adapter || ! is_string( $id ) || '' === $id || $adapter->id() !== $id ) {
						unset( $filtered[ $id ] );
					}
				}
				$adapters = $filtered;
			}
		}

		return $adapters;
	}

	/**
	 * Return one registered adapter by id.
	 *
	 * @param string $id Adapter id.
	 * @return Static_Site_Importer_Layout_Adapter|null
	 */
	public static function adapter( string $id ): ?Static_Site_Importer_Layout_Adapter {
		$adapters = self::adapters();
		return $adapters[ $id ] ?? null;
	}

	/**
	 * Resolve the adapter that serves a capability's selected provider.
	 *
	 * @param string $capability Capability key.
	 * @return Static_Site_Importer_Layout_Adapter|null
	 */
	public static function adapter_for_capability( string $capability ): ?Static_Site_Importer_Layout_Adapter {
		if ( self::CAPABILITY !== $capability ) {
			return null;
		}
		$adapters = self::adapters();
		$provider = self::provider_for( $capability );
		return $adapters[ $provider ] ?? null;
	}

	/**
	 * Return the adapter that serves the layout capability.
	 *
	 * @return Static_Site_Importer_Layout_Adapter|null
	 */
	public static function layout_adapter(): ?Static_Site_Importer_Layout_Adapter {
		return self::adapter_for_capability( self::CAPABILITY );
	}

	/**
	 * Dependency rows for one adapter's required block types.
	 *
	 * @param Static_Site_Importer_Layout_Adapter $adapter Adapter.
	 * @return array<string,array<string,mixed>>
	 */
	public static function dependency_rows( Static_Site_Importer_Layout_Adapter $adapter ): array {
		$rows = array();
		foreach ( $adapter->required_block_types() as $block_type ) {
			$rows[ $block_type ] = array(
				'type'           => 'block_type',
				'block_type'     => $block_type,
				'active'         => self::block_type_registered( $block_type ),
				'available_from' => $adapter->id(),
			);
		}
		return $rows;
	}

	/**
	 * Report whether every dependency of one adapter is available.
	 *
	 * @param Static_Site_Importer_Layout_Adapter $adapter Adapter.
	 * @return bool
	 */
	public static function dependencies_available( Static_Site_Importer_Layout_Adapter $adapter ): bool {
		foreach ( self::dependency_rows( $adapter ) as $row ) {
			if ( empty( $row['active'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Report whether one block type is registered in this WordPress runtime.
	 *
	 * @param string $block_type Block type name.
	 * @return bool
	 */
	private static function block_type_registered( string $block_type ): bool {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return false;
		}
		$registry = WP_Block_Type_Registry::get_instance();
		return $registry instanceof WP_Block_Type_Registry && $registry->is_registered( $block_type );
	}
}
