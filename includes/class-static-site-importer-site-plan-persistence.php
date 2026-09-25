<?php
/**
 * Persists a prepared WordPress site plan into the destination runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Public_Error_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-public-error-projection.php';
}

if ( ! class_exists( 'Static_Site_Importer_Build_Provenance' ) ) {
	require_once __DIR__ . '/class-static-site-importer-build-provenance.php';
}
if ( ! class_exists( 'Static_Site_Importer_Rewrite_Base_Collision' ) ) {
	require_once __DIR__ . '/class-static-site-importer-rewrite-base-collision.php';
}
if ( ! class_exists( 'Static_Site_Importer_Internal_Link_Runtime' ) ) {
	require_once __DIR__ . '/class-static-site-importer-internal-link-runtime.php';
}
if ( ! class_exists( 'Static_Site_Importer_Source_Route_Redirect' ) ) {
	require_once __DIR__ . '/class-static-site-importer-source-route-redirect.php';
}

/** Writes posts, files, overlays, and journals for a prepared plan. */
final class Static_Site_Importer_Site_Plan_Persistence {
	private const RECONCILIATION_META_KEY          = '_static_site_importer_reconciliation_identity';
	private const PRODUCER_RECONCILIATION_META_KEY = '_blocks_engine_reconciliation_identity';

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['plan'] ) || ! is_array( $prepared['plan'] ) || ! Static_Site_Importer_Site_Plan_Receipt::valid_receipt_instance_id( $prepared['receipt_instance_id'] ?? null ) ) {
			return Static_Site_Importer_Site_Plan_Receipt::receipt(
				'rejected',
				array(
					'plan'             => array(),
					'plan_identity'    => array(),
					'diagnostics'      => array( array( 'reason_code' => 'invalid_prepared_state' ) ),
					'failure_reason'   => 'invalid_prepared_state',
					'applied'          => array(
						'posts'                => array(),
						'files'                => array(),
						'operations'           => array(),
						'runtime_declarations' => array(
							'asset_publications' => array(),
							'entity_bindings'    => array(),
						),
					),
					'skipped'          => array(),
					'existing_matches' => array( 'pages' => array() ),
				)
			);
		}
		$state = Static_Site_Importer_Site_Plan_Preparation::refresh_prepared_destination( $prepared );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$state = Static_Site_Importer_Site_Plan_Preparation::admit_prepared( $state );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$capabilities = Static_Site_Importer_Current_Site_Capabilities::check_plan( $state );
		if ( is_wp_error( $capabilities ) ) {
			return self::failed_receipt_from_error( $state, $capabilities );
		}
		try {
			Static_Site_Importer_Site_Plan_Preparation::validate_materialized_block_documents( $state['resolved'], $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
		} catch ( InvalidArgumentException $error ) {
			$state['failure_reason'] = $error->getMessage();
			return Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state );
		}
		$args                        = $state['args'];
		$font_overlay                = $state['font_overlay'];
		$viewport_overlay            = $state['viewport_overlay'];
		$route_title_overlay         = $state['route_title_overlay'] ?? array();
		$route_head_metadata_overlay = $state['route_head_metadata_overlay'] ?? array();

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			self::journal_post( $state, $page );
			$post = self::materialize_page( $page, $state['source_ids'], (string) ( $args['import_run_id'] ?? '' ) );
			if ( is_wp_error( $post ) ) {
				return self::failed_receipt( $state, $post->get_error_code() );
			}
			if ( empty( $page['planned_existing_id'] ) ) {
				$state['rollback']['posts'][ $post ] = array( 'existing' => false );
			}
			$state['applied']['posts'][]                           = array(
				'id'                      => $post,
				'source_path'             => $page['source_path'],
				'reconciliation_identity' => $page['reconciliation_identity'],
			);
			$state['page_ids'][ $page['reconciliation_identity'] ] = $post;
			$state['source_ids'][ $page['source_path'] ]           = $post;
			if ( ! self::write_post_meta( $post, self::RECONCILIATION_META_KEY, (string) $page['reconciliation_identity'] ) || ! self::write_post_meta( $post, self::PRODUCER_RECONCILIATION_META_KEY, (string) $page['reconciliation_identity'] ) ) {
				return self::failed_receipt( $state, 'materialization_reconciliation_metadata_write_failed' );
			}
			$materialized_markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] );
			$provenance          = array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'import_run_id'           => (string) ( $args['import_run_id'] ?? '' ),
				'source_path'             => $page['source_path'],
				'reconciliation_identity' => $page['reconciliation_identity'],
				'content_hash'            => hash( 'sha256', $materialized_markup ),
			);
			$document_title      = Static_Site_Importer_Route_Document_Metadata::title_from_page( $page );
			if ( '' !== $document_title ) {
				$provenance['document_title'] = $document_title;
			}
			$head_metadata = Static_Site_Importer_Route_Head_Metadata::from_page( $page, is_array( $state['resolved'] ?? null ) ? $state['resolved'] : array() );
			if ( array() !== $head_metadata ) {
				$provenance['head_metadata'] = $head_metadata;
			}
			if ( ! self::write_post_meta( $post, '_static_site_importer_provenance', (string) wp_json_encode( $provenance ) ) ) {
				return self::failed_receipt( $state, 'materialization_provenance_metadata_write_failed' );
			}
			$source_route = Static_Site_Importer_Source_Route_Redirect::public_source_route( (string) $page['source_path'] );
			if ( '' !== $source_route && ! self::write_post_meta( $post, Static_Site_Importer_Source_Route_Redirect::META_KEY, $source_route ) ) {
				return self::failed_receipt( $state, 'materialization_source_route_metadata_write_failed' );
			}
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] as &$binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $page['source_path'] ) {
					$fragment          = (string) ( $binding_report['replacement_block_markup'] ?? '' );
					$persisted_content = function_exists( 'get_post_field' ) ? get_post_field( 'post_content', $post ) : null;
					if ( ! is_string( $persisted_content ) || '' === $fragment || ! str_contains( $persisted_content, $fragment ) ) {
						$binding_report['status'] = 'unresolved';
						continue;
					}
					$binding_report['status']                    = 'completed';
					$binding_report['post_id']                   = $post;
					$binding_report['persisted_fragment_hash']   = hash( 'sha256', $fragment );
					$binding_report['materialized_content_hash'] = hash( 'sha256', $persisted_content );
				}
			}
			unset( $binding_report );
		}
		$route_links = self::rewrite_materialized_route_links( $state );
		if ( is_wp_error( $route_links ) ) {
			return self::failed_receipt_from_error( $state, $route_links );
		}
		if ( ! self::keep_page_routes_reachable( $state ) ) {
			return self::failed_receipt( $state, 'rewrite_base_not_applied' );
		}

		$short_write_attempt = 0;
		// A prepared state from before this boundary existed described a
		// generated theme, which owns its directory and may activate.
		$destination = isset( $state['destination'] ) && is_array( $state['destination'] ) ? $state['destination'] : array(
			'mode'               => Static_Site_Importer_Import_Destination::GENERATED_THEME,
			'owns_theme'         => true,
			'permits_activation' => true,
		);
		foreach ( $state['resolved']['writes'] as $write ) {
			// A destination-owned theme keeps its own scaffold, bootstrap, and
			// templates. Withholding is recorded so the receipt never implies
			// this import replaced the host theme's design.
			if ( ! Static_Site_Importer_Import_Destination::permits_write( $destination, (string) ( $write['kind'] ?? '' ) ) ) {
				$state['diagnostics'][] = Static_Site_Importer_Import_Destination::withheld_write_diagnostic( $destination, (string) ( $write['kind'] ?? '' ), (string) ( $write['target_path'] ?? '' ) );
				$state['skipped'][]     = array(
					'kind'        => (string) ( $write['kind'] ?? '' ),
					'target_path' => (string) ( $write['target_path'] ?? '' ),
					'reason_code' => 'destination_withheld_host_theme_write',
				);
				continue;
			}
			$path        = $state['theme_dir'] . '/' . $write['target_path'];
			$publication = self::asset_publication_receipt( $destination, $state['theme_dir'], (string) ( $state['theme']['uri'] ?? '' ), (string) ( $write['kind'] ?? '' ) );
			self::journal_file( $state, $path );
			if ( isset( $state['composed_theme_writes'][ $path ] ) && is_file( $path ) && self::file_hash( $path ) === hash( 'sha256', $state['composed_theme_writes'][ $path ] ) ) {
				$state['applied']['files'][] = self::canonical_file_receipt( $path, $write ) + array( 'publication' => $publication );
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && 'theme_bootstrap' === ( $write['kind'] ?? '' ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				$result = self::merge_batch_bootstrap( $state['theme_dir'], $write );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['files'][] = $result + array( 'publication' => $publication );
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && in_array( $write['kind'] ?? '', array( 'theme_scaffold', 'theme_bootstrap', 'theme_template' ), true ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				continue;
			}
			$chunk_writer = self::injected_failure( $args, 'theme_write_short' ) ? static function ( $stream, string $data ) use ( &$short_write_attempt ): int {
				if ( 0 < $short_write_attempt++ ) {
					return 0;
				}
				$length = max( 1, intdiv( strlen( $data ), 2 ) );
				return (int) fwrite( $stream, substr( $data, 0, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Deterministic test-only short-write injection.
			} : null;
			$result       = self::write_file( $state['theme_dir'], $write, $state['payload_reader'], $chunk_writer );
			if ( is_wp_error( $result ) ) {
				return self::failed_receipt( $state, $result->get_error_code() );
			}
			$state['applied']['files'][] = $result + array( 'publication' => $publication );
		}
		$provider_layout_overlays = isset( $args['provider_layout_overlays'] ) && is_array( $args['provider_layout_overlays'] ) ? $args['provider_layout_overlays'] : array();
		if ( ! empty( $provider_layout_overlays ) ) {
			$provider_layout_materialization = self::apply_provider_layout_overlays( $state, $provider_layout_overlays );
			if ( is_wp_error( $provider_layout_materialization ) ) {
				return self::failed_receipt( $state, $provider_layout_materialization->get_error_code() );
			}
			$state['applied']['provider_layout_overlays'] = $provider_layout_materialization;
		}
		$publications = self::verify_asset_publications( $state );
		if ( is_wp_error( $publications ) ) {
			return self::failed_receipt( $state, $publications->get_error_code() );
		}
		$media_library = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		if ( is_wp_error( $media_library ) ) {
			return self::failed_receipt_from_error( $state, $media_library );
		}
		$state['applied']['media_library'] = $media_library;
		$font_materialization = self::apply_font_overlay( $state, $font_overlay );
		if ( is_wp_error( $font_materialization ) ) {
			return self::failed_receipt_from_error( $state, $font_materialization );
		}
		$state['applied']['font_materialization'] = $font_materialization;
		$viewport_materialization                 = self::apply_viewport_overlay( $state, $viewport_overlay );
		if ( is_wp_error( $viewport_materialization ) ) {
			return self::failed_receipt_from_error( $state, $viewport_materialization );
		}
		$state['applied']['viewport_metadata'] = $viewport_materialization;
		$route_title_materialization           = self::apply_route_title_overlay( $state, $route_title_overlay );
		if ( is_wp_error( $route_title_materialization ) ) {
			return self::failed_receipt_from_error( $state, $route_title_materialization );
		}
		$state['applied']['route_document_titles'] = $route_title_materialization;
		$route_head_metadata_materialization       = self::apply_route_head_metadata_overlay( $state, $route_head_metadata_overlay );
		if ( is_wp_error( $route_head_metadata_materialization ) ) {
			return self::failed_receipt_from_error( $state, $route_head_metadata_materialization );
		}
		$state['applied']['route_head_metadata'] = $route_head_metadata_materialization;
		$svg_receipts                            = self::verify_svg_font_materialization( $state );
		if ( is_wp_error( $svg_receipts ) ) {
			return self::failed_receipt( $state, $svg_receipts->get_error_code() );
		}
		if ( self::injected_failure( $args, 'font_verification' ) ) {
			return self::failed_receipt( $state, 'injected_font_verification_failure' );
		}
		if ( ! empty( $font_materialization['svg_receipts'] ) ) {
			$publications = self::verify_asset_publications( $state );
			if ( is_wp_error( $publications ) ) {
				return self::failed_receipt( $state, $publications->get_error_code() );
			}
		}
		$companion_loading = self::apply_companion_asset_loading( $state );
		if ( is_wp_error( $companion_loading ) ) {
			return self::failed_receipt( $state, $companion_loading->get_error_code() );
		}
		$state['applied']['companion_asset_loading'] = $companion_loading;

		// Activation is a destination right, not a caller preference: an import
		// into a theme the site already runs must never switch the active theme.
		if ( ! empty( $args['activate'] ) && ! Static_Site_Importer_Import_Destination::permits_activation( $destination ) ) {
			return self::failed_receipt( $state, 'destination_forbids_theme_activation' );
		}
		if ( ! empty( $args['activate'] ) ) {
			self::journal_runtime( $state );
			foreach ( $state['resolved']['operations'] as $operation ) {
				if ( 'create_page' === $operation['kind'] ) {
					continue;
				}
				$result = self::apply_operation( $operation, $state['page_ids'], $args );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['operations'][] = $result;
			}
			switch_theme( $state['theme']['slug'] );
			if ( ! self::active_theme_matches( $state['theme']['slug'] ) ) {
				return self::failed_receipt( $state, 'activate_theme_not_applied' );
			}
			$state['applied']['operations'][] = array(
				'kind'       => 'activate_theme',
				'theme_slug' => $state['theme']['slug'],
			);
			if ( self::injected_failure( $args, 'after_activation' ) ) {
				return self::failed_receipt( $state, 'injected_after_activation_failure' );
			}
			if ( ! isset( $args['disable_smilies'] ) || false !== (bool) $args['disable_smilies'] ) {
				if ( ! self::write_option( 'use_smilies', false ) ) {
					return self::failed_receipt( $state, 'disable_smilies_not_applied' );
				}
				$state['applied']['runtime_policy']['disable_smilies'] = true;
				if ( self::injected_failure( $args, 'after_use_smilies' ) ) {
					return self::failed_receipt( $state, 'injected_after_use_smilies_failure' );
				}
			}
			if ( '' !== trim( (string) ( $args['site_title'] ?? '' ) ) ) {
				$title = sanitize_text_field( (string) $args['site_title'] );
				if ( ! self::write_option( 'blogname', $title ) ) {
					return self::failed_receipt( $state, 'site_title_not_applied' );
				}
				$state['applied']['operations'][] = array( 'kind' => 'site_title' );
				if ( self::injected_failure( $args, 'after_blogname' ) ) {
					return self::failed_receipt( $state, 'injected_after_blogname_failure' );
				}
			}
		}

		$state['applied']['runtime_policy']['remove_default_content'] = ! empty( $args['remove_default_content'] )
			? Static_Site_Importer_Default_Content::remove( $state['default_content'] )
			: array(
				'status'  => 'skipped',
				'reason'  => 'disabled',
				'removed' => array(
					'posts'    => array(),
					'comments' => array(),
				),
				'skipped' => array(),
			);

		// The composed artifact identity is recorded in a site option so a
		// fleet can be queried without parsing generated file headers. The
		// same identity is stamped into the theme/plugin headers that survive
		// this import; both remain readable after SSI itself is removed.
		$artifact_provenance = isset( $args['artifact_provenance'] ) && is_array( $args['artifact_provenance'] ) ? $args['artifact_provenance'] : array();
		if ( array() !== $artifact_provenance && Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $artifact_provenance ) ) {
			Static_Site_Importer_Build_Provenance::record_artifact_identity( Static_Site_Importer_Build_Provenance::describe_artifact( $artifact_provenance ) );
		}

		return Static_Site_Importer_Site_Plan_Receipt::receipt( 'completed', $state );
	}

	/** @param array<string,mixed> $page @param array<string,int> $source_ids */
	public static function materialize_page( array $page, array $source_ids, string $import_run_id = '' ) {
		$post_type = sanitize_key( (string) ( $page['post_type'] ?? 'page' ) );
		if ( ! self::is_valid_post_type( $post_type ) ) {
			$post_type = 'page';
		}
		// Pages keep the plan's canonical route ancestry as post_parent, resolved
		// from this run's source ids or a previously materialized run (batch
		// re-imports). Posts must not carry the synthetic page ancestor: their
		// permalink comes from the site post permalink structure, so carrying it
		// would materialize them at a page-shaped path, not the plan route.
		if ( 'page' === $post_type ) {
			$parent = '' === $page['parent_source_path'] ? 0 : ( $source_ids[ $page['parent_source_path'] ] ?? Static_Site_Importer_Site_Plan_Preparation::existing_source_page_id( $page['parent_source_path'], $import_run_id ) );
			if ( $parent <= 0 && '' !== $page['parent_source_path'] ) {
				return new WP_Error(
					'missing_parent_page',
					'The parent route has not been materialized by this import run.',
					array(
						'source_path'        => $page['source_path'],
						'parent_source_path' => $page['parent_source_path'],
					)
				);
			}
		} else {
			$parent = 0;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$post    = array(
			'ID'           => (int) ( $page['planned_existing_id'] ?? 0 ),
			'post_author'  => $user_id > 0 ? $user_id : 1,
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => (string) $page['title'],
			'post_name'    => (string) $page['slug'],
			'post_parent'  => $parent,
			'post_content' => wp_slash( (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ) ),
		);
		if ( ! empty( $page['metadata']['detected_date'] ) ) {
			// The classifier emits UTC. post_date_gmt stores that absolute value;
			// wp_insert_post derives the site-local post_date from it using the
			// configured timezone, so writing it here would double-shift display
			// on non-UTC sites. Only dated documents set the date at all.
			$post['post_date_gmt'] = (string) $page['metadata']['detected_date'];
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return (int) $id;
	}

	/** Persist and verify importer-owned post metadata. */
	public static function write_post_meta( int $id, string $key, string $value ): bool {
		update_post_meta( $id, $key, wp_slash( $value ) );
		return metadata_exists( 'post', $id, $key ) && (string) get_post_meta( $id, $key, true ) === $value;
	}

	/** Rewrite internal routes to portable post-id references after WordPress has assigned every post. */
	public static function rewrite_materialized_route_links( array &$state ) {
		$routes = array();
		foreach ( $state['ordered_pages'] as $page ) {
			$source_path = (string) ( $page['source_path'] ?? '' );
			$route       = self::normalized_route_path( (string) ( $page['route']['path'] ?? '' ) );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$post_type   = sanitize_key( (string) ( $page['post_type'] ?? 'page' ) );
			if ( ! self::is_valid_post_type( $post_type ) ) {
				$post_type = 'page';
			}
			if ( '' !== $route && $post_id > 0 ) {
				$routes[ $route ] = self::portable_internal_reference( $post_id, $post_type );
			}
		}
		if ( array() === $routes ) {
			return true;
		}

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$source_path = (string) ( $page['source_path'] ?? '' );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$content     = $post_id > 0 && function_exists( 'get_post_field' ) ? get_post_field( 'post_content', $post_id ) : null;
			if ( ! is_string( $content ) ) {
				return new WP_Error( 'route_link_rewrite_failed', 'Materialized page content could not be read for route-link resolution.', array( 'source_path' => $source_path ) );
			}
			$unresolved = array();
			$rewritten  = self::rewrite_route_references( $content, $routes, $unresolved );
			foreach ( array_values( array_unique( $unresolved ) ) as $route ) {
				$state['diagnostics'][] = array(
					'type'        => 'unresolved_internal_link',
					'reason_code' => 'unresolved_internal_link',
					'source_path' => $source_path,
					'route'       => $route,
				);
			}
			if ( $rewritten !== $content ) {
				$updated = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $rewritten ),
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$provenance = json_decode( (string) get_post_meta( $post_id, '_static_site_importer_provenance', true ), true );
				if ( is_array( $provenance ) ) {
					$provenance['content_hash'] = hash( 'sha256', $rewritten );
					if ( ! self::write_post_meta( $post_id, '_static_site_importer_provenance', (string) wp_json_encode( $provenance ) ) ) {
						return new WP_Error( 'route_link_rewrite_failed', 'Materialized page provenance could not be updated after route-link resolution.', array( 'source_path' => $source_path ) );
					}
				}
			}
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] as &$binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $source_path && 'completed' === ( $binding_report['status'] ?? '' ) ) {
					$binding_report['materialized_content_hash'] = hash( 'sha256', $rewritten );
				}
			}
			unset( $binding_report );
			foreach ( $state['resolved']['pages'] as &$resolved_page ) {
				if ( ( $resolved_page['source_path'] ?? '' ) === $source_path ) {
					$resolved_page['materialized_block_markup'] = $rewritten;
					break;
				}
			}
			unset( $resolved_page );
		}

		return true;
	}

	/** @param array<int,array<string,mixed>> $operations */
	public static function front_page_reconciliation_identity( array $operations ): string {
		foreach ( $operations as $operation ) {
			if ( 'site_reading' === ( $operation['kind'] ?? null ) && is_string( $operation['front_page_reconciliation_identity'] ?? null ) ) {
				return $operation['front_page_reconciliation_identity'];
			}
		}

		return '';
	}

	public static function portable_internal_reference( int $post_id, string $post_type ): string {
		return 'page' === $post_type ? '/?page_id=' . $post_id : '/?p=' . $post_id;
	}

	/** @param array<string,string> $routes @param array<int,string>|null $unresolved */
	public static function rewrite_route_references( string $content, array $routes, ?array &$unresolved = null ): string {
		$replace = static function ( array $matches ) use ( $routes, &$unresolved ): string {
			$value = (string) $matches[2];
			if ( '' === $value || preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $value ) ) {
				return $matches[0];
			}
			$suffix = '';
			if ( preg_match( '/^([^?#]*)(.*)$/', $value, $parts ) ) {
				$value  = $parts[1];
				$suffix = $parts[2];
			}
			$route = self::normalized_route_path( $value );
			if ( isset( $routes[ $route ] ) ) {
				return $matches[1] . Static_Site_Importer_Internal_Link_Runtime::join_reference_suffix( $routes[ $route ], $suffix ) . $matches[3];
			}
			if ( is_array( $unresolved ) && self::is_document_route( $route ) ) {
				$unresolved[] = $route;
			}
			return $matches[0];
		};
		foreach ( array(
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*["\'])([^"\']+)(["\'])/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\")([^"\\\\]*)(\\\\")/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)/i',
			'/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i',
		) as $pattern ) {
			$content = preg_replace_callback( $pattern, $replace, $content ) ?? $content;
		}

		return $content;
	}

	public static function is_document_route( string $route ): bool {
		if ( '' === $route || '/' === $route || str_starts_with( $route, '/?' ) ) {
			return false;
		}
		$leaf = basename( $route );
		return ! str_contains( $leaf, '.' ) || (bool) preg_match( '/\.html?$/i', $leaf );
	}

	public static function normalized_route_path( string $path ): string {
		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			return '';
		}
		$normalized = '/' . trim( $path, '/' );
		$normalized = preg_replace( '#/index\.html?$#i', '', $normalized ) ?? $normalized;
		$normalized = '' === $normalized ? '/' : $normalized;
		return '/' === $path ? '/' : $normalized;
	}

	/** @param array<string,mixed> $write */
	public static function write_file( string $theme_dir, array $write, ?object $payload_reader = null, ?Closure $chunk_writer = null ) {
		$path          = $theme_dir . '/' . $write['target_path'];
		$declared_hash = self::payload_hash( $write );
		if ( is_file( $path ) && '' !== $declared_hash && self::file_hash( $path ) === $declared_hash ) {
			return array(
				'target_path'             => $write['target_path'],
				'hash'                    => self::file_hash( $path ),
				'payload_hash'            => $write['payload_hash'] ?? $declared_hash,
				'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
			);
		}
		$data = self::write_payload_bytes( $write, $payload_reader );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'theme_directory_create_failed' );
		}
		$temp    = tempnam( dirname( $path ), '.ssi-plan-' );
		$stream  = false !== $temp ? fopen( $temp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the canonical theme write into an atomic temporary file.
		$written = is_resource( $stream ) && self::write_all( $stream, $data, $chunk_writer ) && fflush( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes complete canonical write bytes before publication.
		$closed  = ! is_resource( $stream ) || fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes canonical write before atomic publication.
		if ( false === $data || false === $temp || ! $written || ! $closed || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomically materializes only complete canonical declared theme writes.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed temporary materialization file.
			}
			return new WP_Error( 'theme_write_failed' );
		}
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => $write['payload_hash'] ?? hash( 'sha256', $data ),
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Write every canonical byte or fail before the temporary file can be published. */
	public static function write_all( $stream, string $data, ?Closure $chunk_writer = null ): bool {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$remaining = substr( $data, $offset );
			$written   = null === $chunk_writer ? fwrite( $stream, $remaining ) : $chunk_writer( $stream, $remaining ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Handles short writes while materializing canonical theme bytes.
			if ( ! is_int( $written ) || 0 >= $written || strlen( $remaining ) < $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/** Report final bytes while retaining the canonical write's reconciliation identity. */
	public static function canonical_file_receipt( string $path, array $write ): array {
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => $write['payload_hash'] ?? self::payload_hash( $write ),
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Include generated stylesheet targets in canonical file receipt projections. */
	public static function record_overlay_stylesheet_file( array &$files, string $path, string $target, string $content ): void {
		foreach ( $files as $file ) {
			if ( ( $file['target_path'] ?? null ) === $target ) {
				return;
			}
		}
		$files[] = array(
			'target_path'             => $target,
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => hash( 'sha256', $content ),
			'reconciliation_identity' => hash( 'sha256', $target . "\n" . $target ),
		);
	}

	/** Merge a validated later-batch bootstrap as an idempotent PHP include. */
	public static function merge_batch_bootstrap( string $theme_dir, array $write ) {
		$bootstrap = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared canonical bootstrap payload.
		if ( false === $bootstrap || ! is_string( $bootstrap ) || ! str_starts_with( ltrim( $bootstrap ), '<?php' ) ) {
			return new WP_Error( 'theme_bootstrap_merge_invalid' );
		}
		$hash      = hash( 'sha256', $bootstrap );
		$include   = 'static-site-importer-batch-bootstrap/' . $hash . '.php';
		$functions = $theme_dir . '/' . $write['target_path'];
		$current   = file_get_contents( $functions ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the importer-owned generated bootstrap.
		if ( false === $current ) {
			return new WP_Error( 'theme_bootstrap_merge_read_failed' );
		}
		$require = "\nrequire_once __DIR__ . '/" . $include . "';\n";
		if ( ! str_contains( $current, $require ) ) {
			$include_write = self::write_file(
				$theme_dir,
				array(
					'target_path'  => $include,
					'source_path'  => $write['source_path'],
					'payload'      => array(
						'encoding' => 'utf8',
						'data'     => $bootstrap,
					),
					'payload_hash' => $hash,
				)
			);
			if ( is_wp_error( $include_write ) ) {
				return $include_write;
			}
			$functions_write = self::write_file(
				$theme_dir,
				array(
					'target_path'  => $write['target_path'],
					'source_path'  => $write['source_path'],
					'payload'      => array(
						'encoding' => 'utf8',
						'data'     => $current . $require,
					),
					'payload_hash' => hash( 'sha256', $current . $require ),
				)
			);
			if ( is_wp_error( $functions_write ) ) {
				return $functions_write;
			}
		}
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $functions ),
			'payload_hash'            => hash( 'sha256', (string) file_get_contents( $functions ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reports the merged importer-owned bootstrap payload.
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Persist admitted provider overlay CSS and its frontend delivery bootstrap. */
	public static function apply_provider_layout_overlays( array &$state, array $overlays ) {
		$admitted = array_filter( $overlays, static fn( $overlay ): bool => null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $overlay ) );
		if ( empty( $admitted ) ) {
			return new WP_Error( 'provider_layout_overlay_rejected' );
		}
		$writes = self::provider_layout_stylesheet_writes( $state, $admitted );
		if ( is_wp_error( $writes ) ) {
			return $writes;
		}
		$reports = array();
		foreach ( $writes as $path => $content ) {
			$target   = ltrim( substr( $path, strlen( trailingslashit( $state['theme_dir'] ) ) ), '/' );
			$existing = file_exists( $path ) ? ( is_readable( $path ) ? file_get_contents( $path ) : false ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies an existing generated stylesheet before reconciliation.
			if ( false === $existing ) {
				return new WP_Error( 'provider_layout_stylesheet_read_failed' );
			}
			$composed = $state['composed_theme_writes'][ $path ] ?? $content;
			if ( $composed === $existing ) {
				$reports[] = array(
					'target_path' => $target,
					'hash'        => hash( 'sha256', $existing ),
					'status'      => 'already_satisfied',
				);
				self::record_overlay_stylesheet_file( $state['applied']['files'], $path, $target, $existing );
				continue;
			}
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path' => $target,
					'source_path' => $target,
					'payload'     => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['status'] = 'applied';
			$reports[]        = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = self::canonical_file_receipt( $path, $file ) + array( 'publication' => self::asset_publication_receipt( $state['destination'] ?? array(), $state['theme_dir'], (string) ( $state['theme']['uri'] ?? '' ), 'provider_layout_stylesheet' ) );
				}
			}
			self::record_overlay_stylesheet_file( $state['applied']['files'], $path, $target, $content );
		}
		return array(
			'status' => array_filter( $reports, static fn( array $report ): bool => 'applied' === ( $report['status'] ?? '' ) ) ? 'completed' : 'already_satisfied',
			'files'  => $reports,
		);
	}

	/** Derive expected overlay-composed stylesheets and frontend delivery bootstrap. */
	public static function provider_layout_stylesheet_writes( array $state, array $overlays ) {
		if ( empty( $overlays ) ) {
			return array();
		}
		foreach ( $overlays as $overlay ) {
			if ( null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $overlay ) ) {
				return new WP_Error( 'provider_layout_overlay_rejected' );
			}
		}
		$stylesheets = array();
		$source_css  = '';
		$functions   = null;
		foreach ( $state['resolved']['writes'] as $write ) {
			$target = (string) ( $write['target_path'] ?? '' );
			$css    = self::payload_data( $write );
			if ( str_ends_with( $target, '.css' ) && '' === $source_css ) {
				$source_css = $css;
			}
			if ( in_array( $target, array( 'style.css', 'assets/css/editor-style.css' ), true ) ) {
				$stylesheets[ $state['theme_dir'] . '/' . $target ] = $css;
			}
			if ( 'functions.php' === $target ) {
				$functions = $css;
			}
		}
		if ( ! is_string( $functions ) || ! str_starts_with( ltrim( $functions ), '<?php' ) ) {
			return new WP_Error( 'provider_layout_stylesheet_missing' );
		}
		if ( isset( $stylesheets[ $state['theme_dir'] . '/style.css' ], $stylesheets[ $state['theme_dir'] . '/assets/css/editor-style.css' ] ) ) {
			$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], '', '', array(), array(), $overlays, $stylesheets );
		} elseif ( '' !== $source_css ) {
			$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], (string) $state['theme']['slug'], $source_css, array(), array(), $overlays, null, isset( $state['args']['artifact_provenance'] ) && is_array( $state['args']['artifact_provenance'] ) ? $state['args']['artifact_provenance'] : array() );
		} else {
			return new WP_Error( 'provider_layout_stylesheet_missing' );
		}
		$bootstrap                                        = "\n/* Static Site Importer provider layout overlay delivery. */\nadd_action( 'wp_enqueue_scripts', static function (): void {\n\twp_enqueue_style( 'static-site-importer-theme', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n}, 20 );\n";
		$writes[ $state['theme_dir'] . '/functions.php' ] = str_contains( $functions, $bootstrap ) ? $functions : $functions . $bootstrap;
		return $writes;
	}

	/** @param array<mixed> $state @param array{writes:array<int,array<string,string>>,diagnostics:array<int,array<string,string>>} $overlay */
	public static function apply_font_overlay( array &$state, array $overlay ) {
		$reports = array();
		foreach ( $overlay['writes'] as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = 'base64' === ( $write['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['content'] ?? '' ), true ) : (string) ( $write['content'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the declared font overlay payload for idempotent reconciliation.
			if ( false === $content ) {
				return new WP_Error( 'static_site_importer_font_materialization_payload_invalid' );
			}
			if ( str_ends_with( strtolower( $target ), '.svg' ) && ! empty( $overlay['svg_consumers'] ) && ! self::valid_svg_font_receipt( $overlay['svg_receipts'] ?? array(), $state['resolved']['writes'], $write ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			if ( ! self::safe_destination( $state['theme_dir'], $target ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_destination_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			if ( is_file( $path ) && self::file_hash( $path ) === hash( 'sha256', $content ) ) {
				$result    = array(
					'target_path'             => $target,
					'hash'                    => self::file_hash( $path ),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "font-materialization\n" . $target ),
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'status'                  => 'already_satisfied',
				);
				$reports[] = $result;
				$file      = $result;
				unset( $file['status'] );
				$replaced = false;
				foreach ( $state['applied']['files'] as $index => $applied_file ) {
					if ( ( $applied_file['target_path'] ?? null ) === $target ) {
						$state['applied']['files'][ $index ] = $file;
						$replaced                            = true;
						break;
					}
				}
				if ( ! $replaced ) {
					$state['applied']['files'][] = $file;
				}
				continue;
			}
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array(
						'encoding' => (string) ( $write['encoding'] ?? 'utf8' ),
						'data'     => (string) ( $write['content'] ?? '' ),
					),
					'payload_hash'            => 'base64' === ( $write['encoding'] ?? 'utf8' ) ? hash( 'sha256', (string) base64_decode( (string) ( $write['content'] ?? '' ), true ) ) : hash( 'sha256', (string) ( $write['content'] ?? '' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Hashes decoded declared font payload bytes.
					'reconciliation_identity' => hash( 'sha256', "font-materialization\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['source_path'] = (string) ( $write['source_path'] ?? $target );
			$reports[]             = $result;
			$replaced              = false;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					$replaced                            = true;
					break;
				}
			}
			if ( ! $replaced ) {
				$state['applied']['files'][] = $result;
			}
		}
		return array(
			'status'         => array_filter( $reports, static fn( array $report ): bool => 'already_satisfied' !== ( $report['status'] ?? '' ) ) ? 'completed' : 'already_satisfied',
			'files'          => $reports,
			'diagnostics'    => $overlay['diagnostics'],
			'faces'          => $overlay['faces'] ?? array(),
			'required_faces' => $overlay['required_faces'] ?? array(),
			'svg_receipts'   => $overlay['svg_receipts'] ?? array(),
			'svg_consumers'  => $overlay['svg_consumers'] ?? array(),
		);
	}

	/** @return array<string,string> */
	public static function font_overlay_writes( string $theme_dir, array $overlay ): array {
		$writes = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = 'base64' === ( $write['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['content'] ?? '' ), true ) : (string) ( $write['content'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the declared font overlay payload for preflight hashing.
			if ( '' !== $target && is_string( $content ) ) {
				$writes[ $theme_dir . '/' . $target ] = $content;
			}
		}
		return $writes;
	}

	/** @param array<string,mixed> $overlay */
	public static function apply_viewport_overlay( array &$state, array $overlay ) {
		if ( 'materialized' !== ( $overlay['status'] ?? '' ) ) {
			return array(
				'status'      => (string) ( $overlay['status'] ?? 'not_requested' ),
				'declaration' => '',
				'files'       => array(),
				'diagnostics' => $overlay['diagnostics'] ?? array(),
			);
		}
		$reports = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = (string) ( $write['content'] ?? '' );
			if ( ! self::safe_destination( $state['theme_dir'], $target ) || ! str_starts_with( ltrim( $content ), '<?php' ) ) {
				return new WP_Error( 'static_site_importer_viewport_metadata_materialization_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "viewport-metadata\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$reports[] = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					continue 2;
				}
			}
			$state['applied']['files'][] = $result;
		}
		return array(
			'status'      => 'completed',
			'declaration' => (string) ( $overlay['declaration'] ?? '' ),
			'files'       => $reports,
			'diagnostics' => $overlay['diagnostics'] ?? array(),
		);
	}

	/** @param array<string,mixed> $overlay */
	public static function apply_route_title_overlay( array &$state, array $overlay ) {
		if ( 'materialized' !== ( $overlay['status'] ?? '' ) ) {
			return array(
				'status' => (string) ( $overlay['status'] ?? 'not_requested' ),
				'files'  => array(),
			);
		}
		$reports = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = (string) ( $write['content'] ?? '' );
			if ( ! self::safe_destination( $state['theme_dir'], $target ) || ! str_starts_with( ltrim( $content ), '<?php' ) ) {
				return new WP_Error( 'static_site_importer_route_document_title_materialization_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "route-document-titles\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$reports[] = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					continue 2;
				}
			}
			$state['applied']['files'][] = $result;
		}
		return array(
			'status' => 'completed',
			'files'  => $reports,
		);
	}

	/** @param array<string,mixed> $overlay */
	public static function apply_route_head_metadata_overlay( array &$state, array $overlay ) {
		if ( 'materialized' !== ( $overlay['status'] ?? '' ) ) {
			return array(
				'status' => (string) ( $overlay['status'] ?? 'not_requested' ),
				'files'  => array(),
			);
		}
		$reports = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = (string) ( $write['content'] ?? '' );
			if ( ! self::safe_destination( $state['theme_dir'], $target ) || ! str_starts_with( ltrim( $content ), '<?php' ) ) {
				return new WP_Error( 'static_site_importer_route_head_metadata_materialization_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "route-head-metadata\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$reports[] = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					continue 2;
				}
			}
			$state['applied']['files'][] = $result;
		}
		return array(
			'status' => 'completed',
			'files'  => $reports,
		);
	}

	/** @param array<string,mixed> $overlay @return array<string,string> */
	public static function viewport_overlay_writes( string $theme_dir, array $overlay ): array {
		$writes = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			if ( is_array( $write ) && is_string( $write['target_path'] ?? null ) && is_string( $write['content'] ?? null ) ) {
				$writes[ $theme_dir . '/' . $write['target_path'] ] = $write['content'];
			}
		}
		return $writes;
	}

	/**
	 * Where an asset lands within a receipt.
	 *
	 * Generated-theme imports publish into the theme they own. Existing-theme
	 * imports publish through the companion plugin primitive, so the receipt
	 * names a theme-independent location for every asset that lands there.
	 */
	public static function asset_publication_receipt( array $destination, string $asset_dir, string $asset_uri, string $kind ): array {
		unset( $kind );
		$mode = Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $destination['mode'] ?? '' )
			? Static_Site_Importer_Companion_Asset_Publication::COMPANION_PLUGIN
			: Static_Site_Importer_Companion_Asset_Publication::GENERATED_THEME;
		return array(
			'mode' => $mode,
			'dir'  => $asset_dir,
			'uri'  => $asset_uri,
		);
	}

	/**
	 * Deliver published stylesheets through the companion loader with page scoping.
	 *
	 * A companion publication home has no generated theme bootstrap to enqueue
	 * its CSS, so the loader registers the scoped assets for the materialized
	 * pages only — on the frontend and in the editor — leaving unrelated pages
	 * and the host theme's global styles untouched.
	 */
	public static function apply_companion_asset_loading( array &$state ) {
		$destination = $state['destination'] ?? array();
		if ( Static_Site_Importer_Import_Destination::EXISTING_THEME !== ( $destination['mode'] ?? '' ) ) {
			return array(
				'status'      => 'skipped',
				'reason'      => 'generated_theme_theme_owned_assets',
				'post_ids'    => array(),
				'files'       => array(),
				'diagnostics' => array(),
			);
		}
		$stylesheet_targets = array();
		foreach ( $state['applied']['files'] as $file ) {
			$target = (string) ( $file['target_path'] ?? '' );
			if ( '' !== $target && str_ends_with( strtolower( $target ), '.css' ) ) {
				$stylesheet_targets[] = array(
					'src'     => $target,
					'version' => (string) ( $file['hash'] ?? '' ),
				);
			}
		}
		if ( array() === $stylesheet_targets ) {
			return array(
				'status'      => 'skipped',
				'reason'      => 'no_published_stylesheets',
				'post_ids'    => array(),
				'files'       => array(),
				'diagnostics' => array(),
			);
		}
		$post_ids = array_map( 'intval', array_column( $state['applied']['posts'] ?? array(), 'id' ) );
		$config   = Static_Site_Importer_Companion_Asset_Publication::scoped_asset_config( $stylesheet_targets, $post_ids, (string) ( $state['theme']['uri'] ?? '' ) );
		$loading  = array(
			'status'      => 'completed',
			'post_ids'    => $post_ids,
			'files'       => array(),
			'diagnostics' => array(),
		);
		foreach ( array(
			'scoped-assets.json' => Static_Site_Importer_Companion_Asset_Publication::scoped_assets_json( $config ),
			'asset-loader.php'   => Static_Site_Importer_Companion_Asset_Publication::scoped_loader_source(),
		) as $target => $content ) {
			$path = $state['theme_dir'] . '/' . $target;
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => $target,
					'payload'                 => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "companion-asset-loading\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'static_site_importer_companion_asset_loader_unavailable', sprintf( 'The companion asset loader could not publish %s at %s.', $target, $path ) );
			}
			$result['publication'] = self::asset_publication_receipt( $destination, $state['theme_dir'], (string) ( $state['theme']['uri'] ?? '' ), 'companion_asset_loader' );
			$loading['files'][]    = $result;
		}
		return $loading;
	}

	/** Verify every canonical asset publication against its resolved write and references. */
	public static function verify_asset_publications( array &$state ) {
		$writes = array();
		foreach ( $state['resolved']['writes'] as $write ) {
			$writes[ $write['reconciliation_identity'] ] = $write;
		}
		$files = array();
		foreach ( $state['applied']['files'] as $file ) {
			$files[ $file['reconciliation_identity'] ] = $file;
		}
		$references = array();
		foreach ( $state['resolved']['resolution']['asset_publication_references'] ?? array() as $reference ) {
			$references[ $reference['declaration_reconciliation_identity'] ][] = $reference;
		}

		foreach ( $state['resolved']['runtime_declarations'] as $declaration ) {
			if ( 'asset_publication' !== ( $declaration['kind'] ?? '' ) ) {
				continue;
			}
			$id    = $declaration['reconciliation_identity'];
			$write = null;
			foreach ( $state['resolved']['writes'] as $candidate ) {
				if ( ( $candidate['source_path'] ?? '' ) === $declaration['source_path'] && ( $candidate['kind'] ?? '' ) === 'theme_asset' ) {
					$write = $candidate;
					break;
				}
			}
			$applied     = is_array( $write ) ? ( $files[ $write['reconciliation_identity'] ] ?? null ) : null;
			$svg_receipt = self::svg_receipt_for_write( $state['applied']['font_materialization']['svg_receipts'] ?? array(), $write );
			$valid       = is_array( $write ) && is_array( $applied )
				&& ( is_array( $svg_receipt )
					? self::valid_svg_font_receipt_binding( $svg_receipt, $write ) && $svg_receipt['output_sha256'] === $applied['hash']
					: ( $write['canonical_payload_hash'] ?? $write['payload_hash'] ) === $declaration['expected_content_hash'] && $applied['hash'] === $write['payload_hash'] );
			foreach ( $references[ $id ] ?? array() as $reference ) {
				$target         = $writes[ $reference['write_reconciliation_identity'] ] ?? null;
				$target_path    = is_array( $target ) ? $state['theme_dir'] . '/' . $target['target_path'] : '';
				$target_content = '' !== $target_path && is_file( $target_path ) ? file_get_contents( $target_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies the final declared theme write.
				$valid          = $valid && is_array( $target )
					&& $reference['target_path'] === $target['target_path']
					&& is_string( $target_content )
					&& hash( 'sha256', $target_content ) === $target['payload_hash']
					&& substr_count( $target_content, $reference['expected_resolved_url'] ) === $reference['count'];
			}
			$state['applied']['runtime_declarations']['asset_publications'][ $id ] = array(
				'status'                        => $valid ? 'completed' : 'failed',
				'capability'                    => $declaration['destination']['capability'],
				'source_path'                   => $declaration['source_path'],
				'target_path'                   => is_array( $write ) ? $write['target_path'] : '',
				'write_reconciliation_identity' => is_array( $write ) ? $write['reconciliation_identity'] : '',
				'expected_content_hash'         => $declaration['expected_content_hash'],
				'actual_content_hash'           => is_array( $applied ) ? $applied['hash'] : '',
				'publication_mode'              => (string) ( $state['theme']['asset_publication']['mode'] ?? '' ),
				'publication_dir'               => $state['theme_dir'],
				'publication_uri'               => (string) ( $state['theme']['uri'] ?? '' ),
				'references'                    => $references[ $id ] ?? array(),
			);
			if ( ! $valid ) {
				return new WP_Error( 'static_site_importer_asset_publication_verification_failed' );
			}
		}
		return true;
	}

	public static function svg_receipt_for_write( array $receipts, mixed $write ): ?array {
		if ( ! is_array( $write ) ) {
			return null;
		}
		foreach ( $receipts as $receipt ) {
			if ( is_array( $receipt ) && ( $write['target_path'] ?? null ) === ( $receipt['target_path'] ?? null ) && ( $write['reconciliation_identity'] ?? null ) === ( $receipt['write_reconciliation_identity'] ?? null ) ) {
				return $receipt;
			}
		}
		return null;
	}

	/** Verify required SVG receipts even when no asset-publication declaration exists. */
	public static function verify_svg_font_materialization( array $state ) {
		$receipts  = $state['applied']['font_materialization']['svg_receipts'] ?? array();
		$consumers = $state['applied']['font_materialization']['svg_consumers'] ?? array();
		$files     = $state['applied']['font_materialization']['files'] ?? array();
		$seen      = array();
		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			$write = null;
			foreach ( $state['resolved']['writes'] as $candidate ) {
				if ( is_array( $candidate ) && ( $receipt['write_reconciliation_identity'] ?? null ) === ( $candidate['reconciliation_identity'] ?? null ) ) {
					$write = $candidate;
					break;
				}
			}
			if ( ! self::valid_svg_font_receipt_binding( $receipt, $write ?? array() ) || isset( $seen[ $receipt['consumer_id'] ?? '' ] ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			$seen[ $receipt['consumer_id'] ] = true;
			$path                            = $state['theme_dir'] . '/' . $receipt['target_path'];
			$file                            = array_values( array_filter( $files, static fn( mixed $row ): bool => is_array( $row ) && ( $receipt['target_path'] ?? null ) === ( $row['target_path'] ?? null ) ) )[0] ?? null;
			if ( ! is_array( $file ) || ! is_file( $path ) || self::file_hash( $path ) !== $receipt['output_sha256'] || ( $file['hash'] ?? null ) !== $receipt['output_sha256'] ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
		}
		$required_ids = array();
		foreach ( $consumers as $consumer ) {
			if ( is_array( $consumer ) && true === ( $consumer['required'] ?? null ) && is_string( $consumer['id'] ?? null ) ) {
				$required_ids[] = $consumer['id'];
			}
		}
		sort( $required_ids, SORT_STRING );
		$receipt_ids = array_keys( $seen );
		sort( $receipt_ids, SORT_STRING );
		if ( $required_ids !== $receipt_ids ) {
			return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
		}
		if ( ! empty( $consumers ) ) {
			foreach ( $files as $file ) {
				if ( is_array( $file ) && str_ends_with( strtolower( (string) ( $file['target_path'] ?? '' ) ), '.svg' ) && ! self::svg_receipt_for_target( $receipts, $file['target_path'] ?? '' ) ) {
					return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
				}
			}
		}
		return true;
	}

	public static function svg_receipt_for_target( array $receipts, string $target ): bool {
		foreach ( $receipts as $receipt ) {
			if ( is_array( $receipt ) && ( $receipt['target_path'] ?? null ) === $target ) {
				return true;
			}
		}
		return false;
	}

	public static function valid_svg_font_receipt_binding( array $receipt, array $write ): bool {
		return 'static-site-importer/svg-font-materialization-receipt/v1' === ( $receipt['schema'] ?? null )
			&& ( $write['target_path'] ?? null ) === ( $receipt['target_path'] ?? null )
			&& ( $write['reconciliation_identity'] ?? null ) === ( $receipt['write_reconciliation_identity'] ?? null )
			&& hash( 'sha256', self::payload_data( $write ) ) === ( $receipt['input_sha256'] ?? null )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['output_sha256'] ?? '' ) )
			&& true === ( $receipt['required'] ?? null )
			&& ! empty( $receipt['face_ids'] ) && is_array( $receipt['observed_font_sha256'] ?? null ) && ! empty( $receipt['observed_font_sha256'] );
	}

	/** Validate the only permitted post-canonical SVG mutation. */
	public static function valid_svg_font_receipt( array $receipts, array $canonical_writes, array $overlay_write ): bool {
		$target  = $overlay_write['target_path'] ?? null;
		$content = (string) ( $overlay_write['content'] ?? '' );
		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) || 'static-site-importer/svg-font-materialization-receipt/v1' !== ( $receipt['schema'] ?? null ) || true !== ( $receipt['required'] ?? null ) || ( $receipt['target_path'] ?? null ) !== $target || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['input_sha256'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['output_sha256'] ?? '' ) ) || ! is_array( $receipt['face_ids'] ?? null ) || empty( $receipt['face_ids'] ) || ! is_array( $receipt['receipt_ids'] ?? null ) || count( $receipt['face_ids'] ) !== count( $receipt['receipt_ids'] ) || ! is_array( $receipt['observed_font_sha256'] ?? null ) || empty( $receipt['observed_font_sha256'] ) ) {
				continue;
			}
			foreach ( $canonical_writes as $canonical ) {
				if ( ( $receipt['write_reconciliation_identity'] ?? null ) === ( $canonical['reconciliation_identity'] ?? null ) && ( $canonical['target_path'] ?? null ) === $target && hash( 'sha256', self::payload_data( $canonical ) ) === $receipt['input_sha256'] && hash( 'sha256', $content ) === $receipt['output_sha256'] && str_contains( $content, 'data:font/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function payload_data( array $write ): string {
		if ( null !== self::payload_reference( $write ) ) {
			return '';
		}
		$data = $write['payload']['data'] ?? '';
		return 'base64' === ( $write['payload']['encoding'] ?? null ) && is_string( $data ) ? (string) base64_decode( $data, true ) : (string) $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes for receipt validation.
	}

	/** @param array<string,mixed> $operation @param array<string,int> $page_ids */
	public static function apply_operation( array $operation, array $page_ids, array $args = array() ) {
		$id = $page_ids[ $operation['front_page_reconciliation_identity'] ] ?? 0;
		if ( ! $id ) {
			return new WP_Error( 'operation_target_missing' );
		}
		if ( ! self::write_option( 'show_on_front', $operation['show_on_front'] ) ) {
			return new WP_Error( 'show_on_front_not_applied' );
		}
		if ( self::injected_failure( $args, 'after_show_on_front' ) ) {
			return new WP_Error( 'injected_after_show_on_front_failure' );
		}
		if ( ! self::write_option( 'page_on_front', $id ) ) {
			return new WP_Error( 'page_on_front_not_applied' );
		}
		if ( self::injected_failure( $args, 'after_page_on_front' ) ) {
			return new WP_Error( 'injected_after_page_on_front_failure' );
		}
		return array(
			'kind'                    => $operation['kind'],
			'order'                   => $operation['order'],
			'reconciliation_identity' => $operation['front_page_reconciliation_identity'],
		);
	}

	public static function reconciled_post( string $identity ) {
		// The reconciliation meta key is unique per document, so no post_type
		// filter is needed; 'any' covers posts, pages, and custom import types.
		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'meta_key'    => self::RECONCILIATION_META_KEY,
				'meta_value'  => $identity,
				'numberposts' => 1,
			)
		);
		return isset( $posts[0] ) ? $posts[0] : null;
	}

	/**
	 * Whether a post type is registered on the runtime import target.
	 *
	 * Any registered type is valid; internal types without an object (revision,
	 * nav_menu_item, wp_template_part) fall back to 'page'.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_valid_post_type( string $post_type ): bool {
		if ( function_exists( 'get_post_type_object' ) ) {
			return get_post_type_object( $post_type ) instanceof WP_Post_Type;
		}
		return in_array( $post_type, array( 'page', 'post' ), true );
	}

	public static function safe_destination( string $theme_dir, string $target ): bool {
		$current = rtrim( $theme_dir, '/' );
		foreach ( explode( '/', dirname( $target ) ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			$current .= '/' . $segment;
			if ( is_link( $current ) || ( file_exists( $current ) && ! is_dir( $current ) ) || ( is_dir( $current ) && ! is_writable( $current ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Rejects unsafe native path segments before atomic local writes.
				return false;
			}
		}
		return ! is_link( $theme_dir . '/' . $target );
	}

	/** @param array<string,mixed> $write */
	public static function payload_hash( array $write ): string {
		$reference = self::payload_reference( $write );
		if ( null !== $reference ) {
			return self::valid_payload_reference( $reference ) ? $reference['sha256'] : '';
		}
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes before hashing.
		return is_string( $data ) ? hash( 'sha256', $data ) : '';
	}

	/** Return a declared binary reference without treating absent inline payloads as references. */
	public static function payload_reference( array $write ): ?array {
		$reference = $write['payload_reference'] ?? ( $write['payload']['reference'] ?? null );
		return is_array( $reference ) ? $reference : ( array_key_exists( 'payload_reference', $write ) || array_key_exists( 'reference', $write['payload'] ?? array() ) ? array() : null );
	}

	public static function valid_payload_reference( array $reference ): bool {
		return 'blocks-engine/payload-reference/v1' === ( $reference['schema'] ?? null )
			&& is_string( $reference['id'] ?? null ) && '' !== $reference['id']
			&& is_string( $reference['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $reference['sha256'] )
			&& ( ! isset( $reference['bytes'] ) || ( is_int( $reference['bytes'] ) && $reference['bytes'] >= 0 ) );
	}

	/** Resolve a reference exactly once at the write boundary and verify declared raw bytes. */
	public static function write_payload_bytes( array $write, ?object $payload_reader ) {
		$reference = self::payload_reference( $write );
		if ( null === $reference ) {
			return 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical artifact payload bytes.
		}
		if ( ! self::valid_payload_reference( $reference ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_invalid' );
		}
		if ( ! is_object( $payload_reader ) || ! is_callable( array( $payload_reader, 'read' ) ) ) {
			return new WP_Error( 'static_site_importer_payload_reader_missing' );
		}
		try {
			$bytes = $payload_reader->read( $reference );
		} catch ( Throwable ) {
			return new WP_Error( 'static_site_importer_payload_reference_unavailable' );
		}
		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_unavailable' );
		}
		if ( ( isset( $reference['bytes'] ) && strlen( $bytes ) !== $reference['bytes'] ) || ! hash_equals( $reference['sha256'], hash( 'sha256', $bytes ) ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_hash_mismatch' );
		}
		return $bytes;
	}

	public static function file_hash( string $path ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preflight hashes a declared destination file.
		return false === $data ? '' : hash( 'sha256', $data );
	}

	/** @param array<string,mixed> $state */
	public static function failed_receipt( array $state, int|string $reason ): array {
		$state['diagnostics'][]  = array( 'reason_code' => (string) $reason );
		$state['failure_reason'] = (string) $reason;
		self::rollback( $state );
		return Static_Site_Importer_Site_Plan_Receipt::receipt( 'partial', $state );
	}

	/** @param array<string,mixed> $state */
	public static function failed_receipt_from_error( array $state, WP_Error $error ): array {
		$state['diagnostics'][]  = array( 'reason_code' => $error->get_error_code() );
		$state['failure_reason'] = $error->get_error_code();
		$data                    = $error->get_error_data();
		if ( is_array( $data ) ) {
			$diagnostics = is_array( $data['diagnostics'] ?? null ) ? $data['diagnostics'] : $data;
			$diagnostics = 'static_site_importer_entity_materialization_failed' === $error->get_error_code() ? Static_Site_Importer_Public_Error_Projection::project_public_diagnostics( $diagnostics ) : $diagnostics;
			foreach ( $diagnostics as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				$reason = (string) ( $diagnostic['reason_code'] ?? $diagnostic['reason'] ?? $diagnostic['code'] ?? '' );
				if ( '' !== $reason ) {
					$state['diagnostics'][] = array_merge( $diagnostic, array( 'reason_code' => $reason ) );
				}
			}
		}
		self::rollback( $state );
		return Static_Site_Importer_Site_Plan_Receipt::receipt( 'partial', $state );
	}

	/** Journal a post before any insert/update so failed theme writes restore it exactly enough for SSI ownership. */
	public static function journal_post( array &$state, array $page ): void {
		$id = (int) ( $page['planned_existing_id'] ?? 0 );
		if ( isset( $state['rollback']['posts'][ $id ] ) ) {
			return; }
		$post = 0 < $id && function_exists( 'get_post' ) ? get_post( $id, ARRAY_A ) : null;
		if ( $post ) {
			$state['rollback']['posts'][ $id ] = array(
				'existing'                                => true,
				'post'                                    => $post,
				'provenance'                              => get_post_meta( $id, '_static_site_importer_provenance', true ),
				'reconciliation_identity'                 => get_post_meta( $id, self::RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity'        => get_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity_exists' => metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ),
			);
			return;
		}
		$state['rollback']['posts'][ 'new:' . (string) $page['source_path'] ] = array(
			'existing'    => false,
			'source_path' => (string) $page['source_path'],
		);
	}

	/** Journal original file bytes before atomic replacement. */
	public static function journal_file( array &$state, string $path ): void {
		if ( isset( $state['rollback']['files'][ $path ] ) ) {
			return; }
		$content                             = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Captures pre-write importer destination bytes for rollback.
		$state['rollback']['files'][ $path ] = false !== $content ? array(
			'exists'  => true,
			'content' => $content,
		) : array( 'exists' => false );
	}

	/** Move core rewrite bases that would route an imported page path to an archive. */
	public static function keep_page_routes_reachable( array &$state ): bool {
		// Without a rewrite engine there are no pretty routes to shadow.
		if ( ! ( $GLOBALS['wp_rewrite'] ?? null ) instanceof WP_Rewrite ) {
			return true;
		}
		$paths = array();
		foreach ( $state['applied']['posts'] as $post ) {
			if ( 'page' === get_post_type( (int) $post['id'] ) ) {
				$paths[] = trim( (string) get_page_uri( (int) $post['id'] ), '/' );
			}
		}
		$moves = Static_Site_Importer_Rewrite_Base_Collision::planned_moves( $paths );
		foreach ( $moves as $move ) {
			self::journal_option( $state, $move['option'] );
		}
		if ( ! Static_Site_Importer_Rewrite_Base_Collision::apply( $moves ) ) {
			return false;
		}
		foreach ( $moves as $taxonomy => $move ) {
			$state['applied']['operations'][] = array(
				'kind'        => 'move_rewrite_base',
				'reason_code' => 'imported_page_route_collision',
				'taxonomy'    => $taxonomy,
				'option'      => $move['option'],
				'from'        => $move['from'],
				'to'          => $move['to'],
			);
		}
		array_push( $state['diagnostics'], ...Static_Site_Importer_Rewrite_Base_Collision::shadowed_route_diagnostics( $paths ) );
		return true;
	}

	/** Snapshot all runtime state this materializer can mutate before activation. */
	public static function journal_runtime( array &$state ): void {
		foreach ( array( 'stylesheet', 'template', 'show_on_front', 'page_on_front', 'use_smilies', 'blogname', 'site_icon' ) as $option ) {
			self::journal_option( $state, $option );
		}
	}

	/** Snapshot one option's pre-import value once, for rollback. */
	public static function journal_option( array &$state, string $option ): void {
		if ( isset( $state['rollback']['options'][ $option ] ) ) {
			return;
		}
		$missing                                 = '__static_site_importer_missing_' . $option . '__';
		$value                                   = get_option( $option, $missing );
		$state['rollback']['options'][ $option ] = array(
			'exists' => $value !== $missing,
			'value'  => $value,
		);
	}

	public static function write_option( string $option, mixed $value ): bool {
		// WordPress sanitizes on write ('blogname' runs through esc_html()), so a title containing
		// & < > " or ' is stored escaped. Verify against what core stores, not the raw value.
		$stored = function_exists( 'sanitize_option' ) ? sanitize_option( $option, $value ) : $value;
		if ( get_option( $option, null ) === $stored ) {
			return true;
		}
		return false !== update_option( $option, $value ) && get_option( $option, null ) === $stored;
	}

	public static function active_theme_matches( string $stylesheet, ?string $template = null ): bool {
		$template = $template ?? $stylesheet;
		return ( function_exists( 'get_stylesheet' ) ? get_stylesheet() : get_option( 'stylesheet', '' ) ) === $stylesheet
			&& ( function_exists( 'get_template' ) ? get_template() : get_option( 'template', '' ) ) === $template;
	}

	public static function injected_failure( array $args, string $stage ): bool {
		return (string) ( $args['inject_materialization_failure'] ?? '' ) === $stage;
	}

	/** Add a late file mutation to a deferred materialization receipt. */
	public static function journal_receipt_file( array &$receipt, string $path ): void {
		if ( isset( $receipt['transaction'] ) && is_object( $receipt['transaction'] ) && is_array( $receipt['transaction']->state ?? null ) ) {
			self::journal_file( $receipt['transaction']->state, $path );
		}
	}

	/** Add a late post mutation to a deferred materialization receipt. */
	public static function journal_receipt_post( array &$receipt, int $id ): void {
		if ( $id <= 0 || ! isset( $receipt['transaction'] ) || ! is_object( $receipt['transaction'] ) || ! is_array( $receipt['transaction']->state ?? null ) ) {
			return;
		}
		if ( isset( $receipt['transaction']->state['rollback']['posts'][ $id ] ) ) {
			return;
		}
		$post = function_exists( 'get_post' ) ? get_post( $id, ARRAY_A ) : null;
		if ( $post ) {
			$receipt['transaction']->state['rollback']['posts'][ $id ] = array(
				'existing'                                => true,
				'post'                                    => $post,
				'provenance'                              => get_post_meta( $id, '_static_site_importer_provenance', true ),
				'reconciliation_identity'                 => get_post_meta( $id, self::RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity'        => get_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity_exists' => metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ),
			);
			$receipt['transaction']->state['applied']['posts'][]       = array( 'id' => $id );
		}
	}

	/** Commit a deferred receipt after every durable projection has completed. */
	public static function commit_receipt( array &$receipt ): void {
		unset( $receipt['transaction'] );
	}

	/** Roll back a deferred receipt, including late files and options, in reverse journal order. */
	public static function rollback_receipt( array &$receipt, string $reason ): array {
		if ( ! isset( $receipt['transaction'] ) || ! is_object( $receipt['transaction'] ) || ! is_array( $receipt['transaction']->state ?? null ) ) {
			return $receipt;
		}
		$state                   = $receipt['transaction']->state;
		$state['diagnostics'][]  = array( 'reason_code' => $reason );
		$state['failure_reason'] = $reason;
		self::rollback( $state );
		$result                        = Static_Site_Importer_Site_Plan_Receipt::receipt( 'partial', $state );
		$receipt['transaction']->state = $state;
		$result['transaction']         = $receipt['transaction'];
		return $result;
	}

	/** Revert importer-owned runtime state, writes, and posts on a failed receipt. */
	public static function rollback( array &$state ): void {
		if ( ! empty( $state['rollback']['done'] ) ) {
			return; }
		$state['rollback']['done'] = true;
		self::restore_runtime( $state );
		foreach ( array_reverse( $state['rollback']['files'] ?? array(), true ) as $path => $before ) {
			if ( ! is_array( $before ) ) {
				continue; }
			try {
				if ( ! empty( $before['exists'] ) && is_string( $before['content'] ?? null ) ) {
					self::restore_file( $path, $before['content'] );
				} elseif ( is_file( $path ) && ! wp_delete_file( $path ) ) {
					throw new RuntimeException( 'materialization_rollback_file_delete_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'file', (string) $path, $error );
			}
		}
		foreach ( array_reverse( $state['applied']['attachments'] ?? array() ) as $attachment_id ) {
			try {
				if ( function_exists( 'wp_delete_attachment' ) && ! wp_delete_attachment( (int) $attachment_id, true ) ) {
					throw new RuntimeException( 'materialization_rollback_attachment_delete_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'post', (string) $attachment_id, $error );
			}
		}
		$state['applied']['attachments'] = array();
		foreach ( array_reverse( $state['applied']['posts'] ?? array() ) as $applied ) {
			$id     = (int) ( $applied['id'] ?? 0 );
			$before = $state['rollback']['posts'][ $id ] ?? $state['rollback']['posts'][ 'new:' . (string) ( $applied['source_path'] ?? '' ) ] ?? null;
			if ( ! is_array( $before ) || $id <= 0 ) {
				continue; }
			try {
				if ( ! empty( $before['existing'] ) ) {
					wp_update_post( $before['post'] );
					if ( ! self::write_post_meta( $id, '_static_site_importer_provenance', (string) $before['provenance'] ) || ! self::write_post_meta( $id, self::RECONCILIATION_META_KEY, (string) $before['reconciliation_identity'] ) ) {
						throw new RuntimeException( 'materialization_rollback_post_meta_restore_failed' );
					}
					if ( ! empty( $before['producer_reconciliation_identity_exists'] ) ) {
						if ( ! self::write_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, (string) $before['producer_reconciliation_identity'] ) ) {
							throw new RuntimeException( 'materialization_rollback_post_meta_restore_failed' );
						}
					} else {
						delete_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY );
						if ( metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ) ) {
							throw new RuntimeException( 'materialization_rollback_post_meta_delete_failed' );
						}
					}
				} elseif ( function_exists( 'wp_delete_post' ) && ! wp_delete_post( $id, true ) ) {
					throw new RuntimeException( 'materialization_rollback_post_delete_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'post', (string) $id, $error );
			}
		}
		$state['applied']['files'] = array();
		$state['applied']['posts'] = array();
		$state['diagnostics'][]    = array( 'reason_code' => 'materialization_rolled_back' );
	}

	/** Restore options and the prior active theme before deleting generated theme files. */
	public static function restore_runtime( array &$state ): void {
		$options    = $state['rollback']['options'] ?? array();
		$stylesheet = $options['stylesheet']['value'] ?? null;
		$template   = $options['template']['value'] ?? null;
		if ( ! empty( $options['stylesheet']['exists'] ) && ! empty( $options['template']['exists'] ) && is_string( $stylesheet ) && '' !== $stylesheet && is_string( $template ) && '' !== $template && function_exists( 'switch_theme' ) ) {
			try {
				switch_theme( $stylesheet );
				if ( ! self::active_theme_matches( $stylesheet, $template ) ) {
					throw new RuntimeException( 'materialization_rollback_theme_restore_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'theme', $stylesheet, $error );
			}
		}
		foreach ( array_reverse( $options, true ) as $option => $before ) {
			try {
				if ( ! empty( $before['exists'] ) ) {
					update_option( $option, $before['value'] );
				} elseif ( function_exists( 'delete_option' ) ) {
					delete_option( $option );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'option', (string) $option, $error );
			}
		}
		if ( isset( $options['category_base'] ) || isset( $options['tag_base'] ) ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/** Retain bounded failure evidence without replaying the immutable journal on retry. */
	public static function record_rollback_failure( array &$state, string $kind, string $target, Throwable $error ): void {
		$state['rollback']['partial'] = true;
		if ( count( $state['rollback']['failures'] ?? array() ) < 32 ) {
			$state['rollback']['failures'][] = array(
				'kind'   => $kind,
				'target' => $target,
				'code'   => $error->getMessage(),
			);
		}
		$state['diagnostics'][] = array(
			'reason_code' => 'materialization_rollback_' . $kind . '_failed',
			'target'      => $target,
		);
	}

	/** Restore a journaled file through the WordPress filesystem abstraction. */
	public static function restore_file( string $path, string $content ): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! is_callable( array( $wp_filesystem, 'put_contents' ) ) || ! call_user_func( array( $wp_filesystem, 'put_contents' ), $path, $content, 0644 ) ) {
			throw new RuntimeException( 'materialization_rollback_file_restore_failed' );
		}
	}
}
