<?php
/**
 * Applies a prepared canonical plan with its source-import runtime declarations.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require_once __DIR__ . '/class-static-site-importer-entity-compensation.php';
require_once __DIR__ . '/class-static-site-importer-runtime-entity-binding-validation.php';

final class Static_Site_Importer_Prepared_Plan_Application {
	/**
	 * Materialize runtime dependencies, entities, and the prepared plan as one transaction.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array $prepared, array $lifecycle, $companion_payload, array $gutenberg_gaps, array $theme_materialization ) {
		$args      = is_array( $prepared['args'] ?? null ) ? $prepared['args'] : array();
		$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $lifecycle, is_array( $prepared['resolved'] ?? null ) ? $prepared['resolved'] : array() );
		$classic   = Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === ( $args['theme_materialization'] ?? null );
		$preflight = $classic ? Static_Site_Importer_Runtime_Entity_Binding_Validation::preflight_classic_runtime_entity_bindings( $prepared['args']['classic_theme_projection'], $lifecycle, $args ) : Static_Site_Importer_Runtime_Entity_Binding_Validation::preflight_runtime_entity_binding_anchors( $prepared['resolved'] ?? array(), $lifecycle, $args );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$page_ready = ! empty( $args['page_ready_checkpoint'] );
		if ( $page_ready && Static_Site_Importer_Entity_Materializer_Registry::page_ready_requires_final_hydration( $lifecycle, $args ) ) {
			return new WP_Error(
				'static_site_importer_page_ready_runtime_bindings_deferred',
				'Page-ready materialization requires runtime entity bindings and must wait for complete-snapshot hydration.',
				array(
					'status'                => 'deferred',
					'materialization_scope' => 'page_ready',
				)
			);
		}
		$companion = self::materialize_companion_dependency( $companion_payload, $prepared );
		if ( is_wp_error( $companion ) ) {
			return $companion;
		}
		$dependencies = $page_ready ? array() : Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_runtime_dependencies( $lifecycle, $args );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}
		$entity_result = $page_ready ? array(
			'reports' => array(),
			'error'   => null,
		) : Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, $args );
		$entities      = $entity_result['reports'];
		if ( null !== $entity_result['error'] ) {
			return self::lifecycle_failure( $entity_result['error'], $lifecycle, $dependencies, $entities, 'entity_materialization' );
		}
		$bindings = $page_ready ? array() : Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $entities );
		if ( is_wp_error( $bindings ) ) {
			return self::lifecycle_failure(
				array(
					'code'    => $bindings->get_error_code(),
					'message' => $bindings->get_error_message(),
				),
				$lifecycle,
				$dependencies,
				$entities,
				'runtime_entity_bindings'
			);
		}
		$prepared['args']['runtime_entity_bindings'] = $classic ? array() : $bindings;
		if ( $classic ) {
			$classic_bindings = Static_Site_Importer_Entity_Materializer_Registry::classic_bindings( $lifecycle, $entities );
			if ( is_wp_error( $classic_bindings ) ) {
				return self::lifecycle_failure(
					array(
						'code'    => $classic_bindings->get_error_code(),
						'message' => $classic_bindings->get_error_message(),
					),
					$lifecycle,
					$dependencies,
					$entities,
					'classic_runtime_entity_bindings'
				);
			}
			$projection = Static_Site_Importer_Classic_Theme_Projection::apply_runtime_bindings( $prepared['args']['classic_theme_projection'], $classic_bindings );
			if ( is_wp_error( $projection ) ) {
				return self::lifecycle_failure(
					array(
						'code'    => $projection->get_error_code(),
						'message' => $projection->get_error_message(),
					),
					$lifecycle,
					$dependencies,
					$entities,
					'classic_runtime_projection'
				);
			}
			$prepared['args']['classic_theme_projection']  = $projection;
			$prepared['base_resolved']                     = Static_Site_Importer_Classic_Theme_Projection::with_projection_writes( $prepared['base_resolved'], $projection, (string) $prepared['theme']['uri'], (string) ( $prepared['args']['name'] ?? $prepared['theme']['slug'] ) );
			$prepared['prepared_resolved_projection_hash'] = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepared_resolved_projection_hash( $prepared['base_resolved'] );
			$prepared['args']['classic_runtime_bindings']  = $classic_bindings;
		}
		$prepared['args']['provider_layout_overlays']     = $page_ready ? array() : Static_Site_Importer_Entity_Materializer_Registry::provider_layout_overlays( $entities );
		$prepared['args']['font_materialization']         = $page_ready ? array() : ( $prepared['args']['font_materialization'] ?? array() );
		$prepared['args']['activate']                     = $page_ready ? false : ! empty( $prepared['args']['activate'] );
		$prepared['args']['defer_materialization_commit'] = true;

		$receipt                                  = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $prepared );
		$receipt['completed']['companion_plugin'] = $companion;
		$receipt['extensions']['gutenberg_gaps']  = Static_Site_Importer_Receipt_Projection::project_gutenberg_gaps( $gutenberg_gaps, (string) ( $companion['status'] ?? 'not_materialized' ) );
		$receipt['completed']['runtime_declarations']['dependencies'] = $dependencies;
		$receipt['completed']['runtime_declarations']['entities']     = $entities;
		$receipt['runtime_lifecycle']                                 = $lifecycle;
		if ( $classic ) {
			$receipt['completed']['runtime_declarations']['classic_html_bindings'] = $prepared['args']['classic_runtime_bindings'] ?? array();
		}
		$receipt['theme_materialization'] = $theme_materialization;
		if ( 'completed' !== $receipt['status'] ) {
			$error = $receipt['errors'][0] ?? array();
			Static_Site_Importer_Entity_Compensation::append( $receipt, $lifecycle, $entities, 'wordpress_site_plan_materialization', (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ) );
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan materialization failed.' ), $receipt );
		}
		return array(
			'receipt'      => $receipt,
			'lifecycle'    => $lifecycle,
			'dependencies' => $dependencies,
			'entities'     => $entities,
		);
	}

	/** Return a provider-compensated error before canonical plan mutation begins. */
	private static function lifecycle_failure( array $error, array $lifecycle, array $dependencies, array $entities, string $stage ): WP_Error {
		$failure = array(
			'status'            => 'partial',
			'runtime_lifecycle' => $lifecycle,
			'dependencies'      => $dependencies,
			'entities'          => $entities,
		);
		Static_Site_Importer_Entity_Compensation::append( $failure, $lifecycle, $entities, $stage, (string) $error['code'] );
		return new WP_Error( (string) $error['code'], (string) $error['message'], $failure );
	}

	/** Materialize the compiler-declared companion before provider dependencies. */
	private static function materialize_companion_dependency( $payload, array $prepared ) {
		$args = is_array( $prepared['args'] ?? null ) ? $prepared['args'] : array();
		if ( null === $payload ) {
			return array(
				'status' => 'skipped',
				'reason' => 'companion_plugin_payload_absent',
			);
		}
		if ( array_key_exists( 'materialize_dependencies', $args ) && false === (bool) $args['materialize_dependencies'] ) {
			return array(
				'status' => 'skipped',
				'reason' => 'dependency_materialization_disabled',
			);
		}
		$payload    = self::resolve_companion_asset_references( $payload, $prepared['plan'] ?? array(), $prepared['resolved'] ?? array() );
		$dependency = Static_Site_Importer_Dependency_Manager::companion_plugin_dependency( $payload );
		$result     = Static_Site_Importer_Dependency_Manager::materialize_companion_dependency( $dependency, ! empty( $args['overwrite'] ) );
		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			$error = $result['error'] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_companion_plugin_materialization_failed' ), (string) ( $error['message'] ?? 'Companion-plugin materialization failed.' ), $result );
		}
		return $result;
	}

	/** Resolve browser-visible asset references carried by generated block renders. */
	private static function resolve_companion_asset_references( array $payload, array $plan, array $resolved ): array {
		$tokens    = isset( $plan['reference_tokens'] ) && is_array( $plan['reference_tokens'] ) ? $plan['reference_tokens'] : array();
		$theme_uri = isset( $resolved['resolution']['theme_uri'] ) && is_string( $resolved['resolution']['theme_uri'] ) ? $resolved['resolution']['theme_uri'] : '';
		if ( empty( $tokens ) || '' === $theme_uri ) {
			return $payload;
		}

		$entries       = array_values( array_filter( $plan['pages'] ?? array(), static fn( mixed $page ): bool => is_array( $page ) && ! empty( $page['entrypoint'] ) ) );
		$entry         = $entries[0] ?? null;
		$origin        = is_array( $entry ) && is_string( $entry['source_path'] ?? null ) ? $entry['source_path'] : '';
		$root          = '' === $origin || '.' === dirname( $origin ) ? '' : trim( dirname( $origin ), '/' );
		$canonicalizer = new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer( $tokens, $root );
		$references    = WordPressSitePlanResolver::references( $tokens, $theme_uri );

		foreach ( $payload['blocks'] ?? array() as $index => $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['render'] ?? null ) ) {
				continue;
			}
			$canonical                             = $canonicalizer->content( $block['render'], $origin );
			$payload['blocks'][ $index ]['render'] = WordPressSitePlanResolver::resolvePayload( $canonical, $references );
		}

		return $payload;
	}
}
