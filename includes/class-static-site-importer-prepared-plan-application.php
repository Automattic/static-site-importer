<?php
/**
 * Applies a prepared canonical plan with its source-import runtime declarations.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require_once __DIR__ . '/class-static-site-importer-entity-compensation.php';
require_once __DIR__ . '/class-static-site-importer-runtime-entity-binding-validation.php';
require_once __DIR__ . '/class-static-site-importer-receipt-projection.php';

final class Static_Site_Importer_Prepared_Plan_Application {
	/**
	 * Materialize runtime dependencies, entities, and the prepared plan as one transaction.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array $prepared, array $lifecycle, $companion_payload, array $gutenberg_gaps, array $theme_materialization ) {
		$args      = is_array( $prepared['args'] ?? null ) ? $prepared['args'] : array();
		$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $lifecycle, is_array( $prepared['resolved'] ?? null ) ? $prepared['resolved'] : array() );
		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}
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
		// Keep the established companion/dependency transaction ordering. A companion
		// failure must occur before any runtime dependency can require compensation.
		$companion = self::materialize_companion_dependency( self::with_form_visual_states( $companion_payload, $lifecycle, $args ), $prepared );
		if ( is_wp_error( $companion ) ) {
			return $companion;
		}
		$dependencies = $page_ready ? array() : Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_runtime_dependencies( $lifecycle, $args );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}
		$entity_args = $args;
		if ( ! $page_ready ) {
			$entity_args['resolved_product_images'] = self::resolve_product_image_references( $lifecycle, $prepared );
		}
		$entity_result = $page_ready ? array(
			'reports' => array(),
			'error'   => null,
		) : Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, $entity_args );
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
			$prepared['base_resolved']                     = Static_Site_Importer_Classic_Theme_Projection::with_projection_writes( $prepared['base_resolved'], $projection, (string) $prepared['theme']['uri'], (string) ( $prepared['args']['name'] ?? $prepared['theme']['slug'] ), isset( $args['artifact_provenance'] ) && is_array( $args['artifact_provenance'] ) ? $args['artifact_provenance'] : array() );
			$prepared['prepared_resolved_projection_hash'] = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepared_resolved_projection_hash( $prepared['base_resolved'] );
			$prepared['args']['classic_runtime_bindings']  = $classic_bindings;
		}
		$prepared['args']['provider_layout_overlays']     = $page_ready ? array() : Static_Site_Importer_Entity_Materializer_Registry::provider_layout_overlays( $entities );
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

	/** Add topology-derived provider field states before the established companion phase. */
	private static function with_form_visual_states( $payload, array $lifecycle, array $args ) {
		$states = array();
		foreach ( $lifecycle['entities'] ?? array() as $prepared_entity ) {
			$manifest = is_array( $prepared_entity['manifest'] ?? null ) ? $prepared_entity['manifest'] : array();
			if ( ! isset( $manifest['forms'] ) ) {
				continue;
			}
			$states = array_merge( $states, Static_Site_Importer_Form_Seeder::visual_states( $manifest ) );
		}
		if ( empty( $states ) ) {
			return $payload;
		}
		if ( ! is_array( $payload ) ) {
			$payload = array(
				'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
				'site_slug' => (string) ( $args['slug'] ?? '' ),
				'site_name' => (string) ( $args['name'] ?? '' ),
				'blocks'    => array(),
			);
		}
		$payload['form_visual_states'] = array_values( array_unique( $states, SORT_REGULAR ) );
		return $payload;
	}

	/** Return a provider-compensated error before canonical plan mutation begins. */
	private static function lifecycle_failure( array $error, array $lifecycle, array $dependencies, array $entities, string $stage ): WP_Error {
		$diagnostics = is_array( $error['diagnostics'] ?? null ) ? $error['diagnostics'] : array();
		if ( ! empty( $diagnostics ) ) {
			$lifecycle['diagnostics'] = array_merge( is_array( $lifecycle['diagnostics'] ?? null ) ? $lifecycle['diagnostics'] : array(), $diagnostics );
		}
		$failure = array(
			'status'            => 'partial',
			'runtime_lifecycle' => $lifecycle,
			'dependencies'      => $dependencies,
			'entities'          => $entities,
			'diagnostics'       => $diagnostics,
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

		$origin        = self::plan_entrypoint_source_path( $plan );
		$root          = self::plan_entrypoint_root( $origin );
		$canonicalizer = new AssetReferenceCanonicalizer( $tokens, $root );
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

	/** Resolve the compiled plan's entrypoint page source path, used to root artifact-relative asset references. */
	private static function plan_entrypoint_source_path( array $plan ): string {
		$entries = array_values( array_filter( $plan['pages'] ?? array(), static fn( mixed $page ): bool => is_array( $page ) && ! empty( $page['entrypoint'] ) ) );
		$entry   = $entries[0] ?? null;
		return is_array( $entry ) && is_string( $entry['source_path'] ?? null ) ? $entry['source_path'] : '';
	}

	/** Resolve the artifact directory a root-relative asset reference (e.g. `/media/x.jpg`) is rooted under. */
	private static function plan_entrypoint_root( string $origin ): string {
		return '' === $origin || '.' === dirname( $origin ) ? '' : trim( dirname( $origin ), '/' );
	}

	/**
	 * Resolve every distinct source image a `products` entity collection declares
	 * to its real materialized bytes, the same canonicalizer + declared-write
	 * lookup already used to resolve source media referenced by page content
	 * (see resolve_companion_asset_references() above), so a seeded product can
	 * carry its source image without a new downloader or sideloader.
	 *
	 * @param array<string,mixed> $lifecycle Runtime entity lifecycle.
	 * @param array<string,mixed> $prepared  Prepared plan state (`plan`, `resolved`, `payload_reader`).
	 * @return array<string,array{bytes:string,mime_type:string,target_path:string}> Resolved images keyed by their manifest `image` value.
	 */
	private static function resolve_product_image_references( array $lifecycle, array $prepared ): array {
		$plan     = isset( $prepared['plan'] ) && is_array( $prepared['plan'] ) ? $prepared['plan'] : array();
		$resolved = isset( $prepared['resolved'] ) && is_array( $prepared['resolved'] ) ? $prepared['resolved'] : array();
		$tokens   = isset( $plan['reference_tokens'] ) && is_array( $plan['reference_tokens'] ) ? $plan['reference_tokens'] : array();
		$writes   = isset( $resolved['writes'] ) && is_array( $resolved['writes'] ) ? $resolved['writes'] : array();
		if ( empty( $tokens ) || empty( $writes ) ) {
			return array();
		}

		$sources = array();
		foreach ( $lifecycle['entities'] ?? array() as $prepared_entity ) {
			if ( ! is_array( $prepared_entity ) || 'products' !== (string) ( $prepared_entity['adapter']['entity_collection'] ?? '' ) ) {
				continue;
			}
			$products = isset( $prepared_entity['manifest']['products'] ) && is_array( $prepared_entity['manifest']['products'] ) ? $prepared_entity['manifest']['products'] : array();
			foreach ( $products as $product ) {
				if ( is_array( $product ) && isset( $product['image'] ) && is_string( $product['image'] ) && '' !== $product['image'] ) {
					$sources[ $product['image'] ] = true;
				}
			}
		}
		if ( empty( $sources ) ) {
			return array();
		}

		$origin         = self::plan_entrypoint_source_path( $plan );
		$root           = self::plan_entrypoint_root( $origin );
		$payload_reader = is_object( $prepared['payload_reader'] ?? null ) ? $prepared['payload_reader'] : null;
		try {
			$canonicalizer = new AssetReferenceCanonicalizer( $tokens, $root );
		} catch ( \Throwable ) {
			return array();
		}

		$writes_by_target_path = array();
		foreach ( $writes as $write ) {
			if ( is_array( $write ) && 'theme_asset' === ( $write['kind'] ?? null ) && is_string( $write['target_path'] ?? null ) ) {
				$writes_by_target_path[ $write['target_path'] ] = $write;
			}
		}
		$target_path_by_token = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && is_string( $token['token'] ?? null ) && is_string( $token['target_path'] ?? null ) ) {
				$target_path_by_token[ WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}' ] = $token['target_path'];
			}
		}
		$mime_type_by_target_path = array();
		foreach ( isset( $plan['assets'] ) && is_array( $plan['assets'] ) ? $plan['assets'] : array() as $asset ) {
			if ( is_array( $asset ) && is_string( $asset['target_path'] ?? null ) && is_string( $asset['mime_type'] ?? null ) ) {
				$mime_type_by_target_path[ $asset['target_path'] ] = $asset['mime_type'];
			}
		}

		$resolved_images = array();
		foreach ( array_keys( $sources ) as $source ) {
			$token = $canonicalizer->reference( $source, $origin );
			if ( ! is_string( $token ) || '' === $token ) {
				continue;
			}
			$target_path = $target_path_by_token[ $token ] ?? '';
			$write       = '' !== $target_path ? ( $writes_by_target_path[ $target_path ] ?? null ) : null;
			if ( null === $write ) {
				continue;
			}
			$bytes = Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $write, $payload_reader );
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				continue;
			}
			$resolved_images[ $source ] = array(
				'bytes'       => $bytes,
				'mime_type'   => (string) ( $mime_type_by_target_path[ $target_path ] ?? '' ),
				'target_path' => $target_path,
			);
		}
		return $resolved_images;
	}
}
