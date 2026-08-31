<?php
/**
 * Provider dependency planning, reporting, and materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the complete lifecycle for dependencies declared by provider adapters. */
class Static_Site_Importer_Dependency_Manager {
	/** @return array<int,array<string,mixed>> */
	public static function adapter_dependencies( array $adapter ): array {
		$dependencies = isset( $adapter['dependencies'] ) && is_array( $adapter['dependencies'] ) ? $adapter['dependencies'] : array();
		return array_values(
			array_filter(
				$dependencies,
				static fn( mixed $dependency ): bool => is_array( $dependency ) && in_array( (string) ( $dependency['type'] ?? '' ), array( 'wp_org_plugin', 'companion_plugin' ), true )
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	public static function materialize_plugin_dependencies( array $adapter, bool $overwrite = false ): array {
		$reports = array();
		foreach ( self::adapter_dependencies( $adapter ) as $dependency ) {
			$slug = (string) ( $dependency['slug'] ?? '' );
			if ( '' !== $slug ) {
				$reports[ $slug ] = self::materialize_dependency( $dependency, $overwrite );
			}
		}
		return $reports;
	}

	/** @return array<string,mixed> */
	public static function dependency_plan( array $lifecycle, string $artifact_sha256 ): array {
		$entries = array();
		foreach ( $lifecycle['dependencies'] ?? array() as $declaration_id => $prepared ) {
			if ( ! is_array( $prepared ) || empty( $prepared['required'] ) || ! is_array( $prepared['adapter'] ?? null ) ) {
				continue;
			}
			$adapter = $prepared['adapter'];
			foreach ( self::adapter_dependencies( $adapter ) as $dependency ) {
				if ( 'wp_org_plugin' !== ( $dependency['type'] ?? '' ) ) {
					continue;
				}
				$slug        = (string) ( $dependency['slug'] ?? '' );
				$plugin_file = (string) ( $dependency['plugin_file'] ?? '' );
				if ( '' === $slug || '' === $plugin_file ) {
					continue;
				}
				$key = 'wp-org:' . $slug;
				if ( ! isset( $entries[ $key ] ) ) {
					$entries[ $key ] = array(
						'source_kind'        => 'wordpress.org-plugin',
						'package'            => $slug,
						'slug'               => $slug,
						'version_policy'     => 'wordpress.org-latest-stable',
						'reference_policy'   => 'resolver-recorded-immutable-package-digest',
						'plugin_entrypoint'  => $plugin_file,
						'activation'         => 'required',
						'integrity'          => array(
							'entrypoint_sha256' => '',
							'provenance'        => 'registry-declared',
						),
						'provenance'         => array(
							'adapter_id'      => (string) ( $adapter['id'] ?? '' ),
							'provider'        => (string) ( $adapter['provider'] ?? '' ),
							'entity_type'     => (string) ( $adapter['entity_type'] ?? '' ),
							'declaration_ids' => array(),
						),
						'provider_readiness' => array_merge(
							$dependency['provider_readiness'] ?? array(),
							array( 'preparation_callback' => $dependency['preparation_callback'] ?? null )
						),
					);
				}
				$entries[ $key ]['provenance']['declaration_ids'][] = (string) $declaration_id;
			}
		}
		ksort( $entries, SORT_STRING );
		return array(
			'schema'          => 'static-site-importer/runtime-dependency-plan/v1',
			'artifact_sha256' => $artifact_sha256,
			'entries'         => array_values( $entries ),
		);
	}

	/** Materialize all prepared provider dependencies. */
	public static function materialize_lifecycle_dependencies( array $lifecycle, array $args ) {
		$reports = array();
		foreach ( $lifecycle['dependencies'] ?? array() as $id => $prepared ) {
			$adapter  = $prepared['adapter'];
			$required = ! empty( $prepared['required'] ) || self::lifecycle_entity_has_bindings( $lifecycle['entities'][ $id ] ?? array() );
			$waived   = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] );
			if ( $waived ) {
				$reports[ $id ] = array(
					'status'   => 'waived',
					'provider' => $adapter['provider'] ?? '',
				);
				continue;
			}
			if ( empty( $args['materialize_dependencies'] ) && ! self::dependencies_available( $adapter ) && $required ) {
				return new WP_Error(
					'static_site_importer_required_runtime_dependency_missing',
					'A required runtime dependency is unavailable and dependency materialization is disabled.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $id,
					)
				);
			}
			$reports[ $id ] = ! empty( $args['materialize_dependencies'] ) ? self::materialize_plugin_dependencies( $adapter, ! empty( $args['overwrite'] ) ) : array( 'status' => 'available' );
			foreach ( $reports[ $id ] as $plugin_report ) {
				if ( is_array( $plugin_report ) && 'failed' === ( $plugin_report['status'] ?? '' ) ) {
					return new WP_Error(
						'static_site_importer_required_runtime_dependency_failed',
						'SSI could not install or activate a required runtime dependency.',
						array(
							'status'         => 'partial',
							'declaration_id' => $id,
							'dependency'     => $plugin_report,
						)
					);
				}
			}
			if ( 'prepare' !== ( $args['runtime_lifecycle_phase'] ?? '' ) && ! self::dependencies_available( $adapter ) && $required ) {
				return new WP_Error(
					'static_site_importer_required_runtime_dependency_missing',
					'SSI could not prepare a required runtime dependency.',
					array(
						'status'                    => 'partial',
						'completed_declaration_ids' => array_keys( $reports ),
						'dependency_reports'        => $reports,
					)
				);
			}
		}
		return $reports;
	}

	/** @return array<string,mixed> */
	public static function companion_plugin_dependency( array $payload ): array {
		$dependency                          = array(
			'type'        => 'companion_plugin',
			'slug'        => Static_Site_Importer_Companion_Plugin::plugin_slug( $payload ),
			'plugin_file' => Static_Site_Importer_Companion_Plugin::plugin_file( $payload ),
			'mu_plugin'   => ! empty( $payload['mu_plugin'] ),
			'payload'     => $payload,
		);
		$dependency['availability_callback'] = static fn(): bool => self::dependency_available( $dependency );
		return $dependency;
	}

	public static function companion_plugin_available( array $dependency ): bool {
		return self::dependency_available( $dependency );
	}

	/** @return array<string,mixed> */
	public static function materialize_companion_dependency( array $dependency, bool $overwrite = false ): array {
		return self::materialize_dependency( $dependency, $overwrite );
	}

	/** @return array<string,mixed> */
	public static function companion_dependency_row( array $dependency, bool $waived ): array {
		$payload  = is_array( $dependency['payload'] ?? null ) ? $dependency['payload'] : array();
		$scaffold = empty( $payload ) ? null : Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		$scaffold = is_array( $scaffold ) ? $scaffold : array();
		return array(
			'type'            => 'companion_plugin',
			'source'          => 'generated',
			'slug'            => (string) ( $dependency['slug'] ?? '' ),
			'plugin_file'     => (string) ( $dependency['plugin_file'] ?? '' ),
			'mu_plugin'       => ! empty( $dependency['mu_plugin'] ),
			'required'        => true,
			'active'          => self::dependency_available( $dependency ),
			'waived'          => $waived,
			'block_names'     => is_array( $scaffold['block_names'] ?? null ) ? array_values( array_map( 'strval', $scaffold['block_names'] ) ) : array(),
			'island_handles'  => is_array( $scaffold['island_handles'] ?? null ) ? array_values( array_map( 'strval', $scaffold['island_handles'] ) ) : array(),
			'runtime_scripts' => is_array( $scaffold['runtime_scripts'] ?? null ) ? array_values( $scaffold['runtime_scripts'] ) : array(),
		);
	}

	/** @return array<string,array<string,mixed>> */
	public static function dependency_rows( array $adapter, array $intent, bool $waived ): array {
		$rows = array();
		foreach ( self::adapter_dependencies( $adapter ) as $dependency ) {
			if ( 'wp_org_plugin' !== ( $dependency['type'] ?? '' ) || '' === (string) ( $dependency['slug'] ?? '' ) ) {
				continue;
			}
			$active                      = self::dependency_available( $dependency );
			$rows[ $dependency['slug'] ] = array(
				'required'      => true,
				'active'        => $active,
				'sources'       => is_array( $intent['sources'] ?? null ) ? $intent['sources'] : array(),
				'product_count' => (int) ( $intent['product_count'] ?? 0 ),
				'waived'        => $waived,
				'missing_apis'  => $active ? array() : self::missing_apis( $dependency ),
			);
		}
		return $rows;
	}

	public static function dependencies_available( array $adapter ): bool {
		foreach ( self::adapter_dependencies( $adapter ) as $dependency ) {
			if ( ! self::dependency_available( $dependency ) ) {
				return false;
			}
		}
		return true;
	}

	public static function primary_dependency_slug( array $adapter ): string {
		$dependencies = self::adapter_dependencies( $adapter );
		$dependency   = reset( $dependencies );
		return is_array( $dependency ) ? (string) ( $dependency['slug'] ?? '' ) : '';
	}

	/** @return array<string,mixed> */
	private static function materialize_dependency( array $dependency, bool $overwrite ): array {
		if ( 'wp_org_plugin' === ( $dependency['type'] ?? '' ) ) {
			return Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
				(string) ( $dependency['slug'] ?? '' ),
				(string) ( $dependency['plugin_file'] ?? '' ),
				$dependency['availability_callback'] ?? null,
				$dependency['preparation_callback'] ?? null
			);
		}
		if ( 'companion_plugin' === ( $dependency['type'] ?? '' ) ) {
			$payload = is_array( $dependency['payload'] ?? null ) ? $dependency['payload'] : array();
			return Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, $dependency['availability_callback'] ?? null, $overwrite );
		}
		return array(
			'status' => 'failed',
			'error'  => array(
				'code'    => 'static_site_importer_dependency_type_unsupported',
				'message' => 'SSI cannot materialize an unsupported dependency type.',
			),
		);
	}

	private static function dependency_available( array $dependency ): bool {
		if ( 'companion_plugin' === ( $dependency['type'] ?? '' ) ) {
			$plugin_file = (string) ( $dependency['plugin_file'] ?? '' );
			if ( '' === $plugin_file ) {
				return false;
			}
			if ( ! empty( $dependency['mu_plugin'] ) ) {
				return defined( 'WPMU_PLUGIN_DIR' ) && file_exists( rtrim( (string) WPMU_PLUGIN_DIR, '/' ) . '/' . $plugin_file );
			}
			return function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
		}
		$callback = $dependency['availability_callback'] ?? null;
		return is_callable( $callback ) && true === (bool) call_user_func( $callback );
	}

	/** @return array<int,string> */
	private static function missing_apis( array $dependency ): array {
		$apis = is_array( $dependency['missing_apis'] ?? null ) ? $dependency['missing_apis'] : array();
		return array_values( array_filter( array_map( 'strval', $apis ) ) );
	}

	private static function lifecycle_entity_has_bindings( array $prepared ): bool {
		$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
		$entities = is_array( $manifest['products'] ?? null ) ? $manifest['products'] : ( is_array( $manifest['forms'] ?? null ) ? $manifest['forms'] : array() );
		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) && ! empty( $entity['bindings'] ) ) {
				return true;
			}
		}
		return false;
	}
}
