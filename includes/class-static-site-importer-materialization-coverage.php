<?php
/**
 * Declares what this WordPress runtime can materialize natively.
 *
 * The importer already knows which entity capabilities it serves, which
 * provider is selected for each, and whether that provider's dependencies are
 * present. That knowledge decides whether a source island becomes a native
 * block or stays an island, so a caller deciding whether to spend an import on
 * a source needs it before the import rather than after.
 *
 * This is a statement about this runtime and nothing else. It describes no
 * source, names no capture producer, and borrows no vocabulary from one: the
 * capability keys are the importer's own, from the entity materializer
 * registry. A caller that also measures sources owns any mapping between the
 * two, because that mapping is policy and belongs with whoever runs both.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Materialization_Coverage {

	public const SCHEMA = 'static-site-importer/materialization-coverage/v1';

	/**
	 * Declare native materialization coverage for every registered capability.
	 *
	 * `native` means this runtime can turn that entity into native blocks right
	 * now. Anything else is reported with the reason it cannot, because a
	 * caller needs to tell "install this plugin" apart from "this importer has
	 * no adapter for that at all".
	 *
	 * @return array<string,mixed>
	 */
	public static function declare_coverage(): array {
		$capabilities = array();
		foreach ( array_keys( Static_Site_Importer_Entity_Materializer_Registry::capabilities() ) as $capability ) {
			$capabilities[ $capability ] = self::capability_coverage( (string) $capability );
		}
		ksort( $capabilities, SORT_STRING );
		return array(
			'schema'       => self::SCHEMA,
			'capabilities' => $capabilities,
			'native'       => array_values( array_filter( array_keys( $capabilities ), static fn( string $key ): bool => 'native' === $capabilities[ $key ]['status'] ) ),
		);
	}

	/**
	 * Coverage for one capability.
	 *
	 * @param string $capability Capability key.
	 * @return array<string,mixed>
	 */
	public static function capability_coverage( string $capability ): array {
		$configured = Static_Site_Importer_Entity_Materializer_Registry::capabilities();
		if ( ! isset( $configured[ $capability ] ) ) {
			return array(
				'status'   => 'unsupported',
				'reason'   => 'capability_not_registered',
				'provider' => '',
			);
		}
		$provider = Static_Site_Importer_Entity_Materializer_Registry::provider_for( $capability );
		$adapter  = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( $capability );
		$default  = (string) ( $configured[ $capability ]['default_provider'] ?? '' );
		$coverage = array(
			'status'        => 'unsupported',
			'reason'        => '',
			'provider'      => $provider,
			'selected_from' => $provider === $default ? 'default' : 'configured',
		);
		if ( array() === $adapter ) {
			// A configured provider is never routed to another adapter, so an
			// unserviceable selection is a coverage answer, not a fallback.
			$coverage['reason'] = '' === $provider ? 'provider_not_selected' : 'provider_has_no_adapter';
			return $coverage;
		}
		$coverage['adapter']           = (string) ( $adapter['id'] ?? '' );
		$coverage['label']             = (string) ( $adapter['label'] ?? '' );
		$coverage['entity_type']       = (string) ( $adapter['entity_type'] ?? '' );
		$coverage['entity_collection'] = (string) ( $adapter['entity_collection'] ?? '' );
		$coverage['dependencies']      = self::dependency_rows( $adapter );
		$available                     = Static_Site_Importer_Dependency_Manager::dependencies_available( $adapter );
		$coverage['status']            = $available ? 'native' : 'provider_unavailable';
		$coverage['reason']            = $available ? '' : 'dependencies_unavailable';
		return $coverage;
	}

	/**
	 * Report each declared dependency and whether it is satisfied here.
	 *
	 * @param array<string,mixed> $adapter Registered adapter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function dependency_rows( array $adapter ): array {
		$rows = array();
		foreach ( Static_Site_Importer_Dependency_Manager::adapter_dependencies( $adapter ) as $dependency ) {
			$rows[] = array(
				'type'      => (string) ( $dependency['type'] ?? '' ),
				'slug'      => (string) ( $dependency['slug'] ?? '' ),
				'available' => Static_Site_Importer_Dependency_Manager::dependencies_available( array( 'dependencies' => array( $dependency ) ) ),
			);
		}
		return $rows;
	}
}
