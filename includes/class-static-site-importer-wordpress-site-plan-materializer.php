<?php
/**
 * Applies the canonical Blocks Engine WordPress site plan to a WordPress runtime.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require_once __DIR__ . '/class-static-site-importer-stylesheet-materializer.php';
if ( ! class_exists( 'Static_Site_Importer_Current_Site_Capabilities' ) ) {
	require_once __DIR__ . '/class-static-site-importer-current-site-capabilities.php';
}

final class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public const RECEIPT_SCHEMA           = 'static-site-importer/materialization-receipt/v1';
	private const RECONCILIATION_META_KEY = '_static_site_importer_reconciliation_identity';
	private const BLOCK_PROVENANCE_LIMIT  = 50;

	/**
	 * Materialize a fully canonical v2 plan. Compilation and plan validation belong to Blocks Engine.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Receipt.
	 */
	public static function materialize( array $plan, array $args = array() ): array {
		$prepared = self::prepare( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			return $prepared['receipt'];
		}
		return self::materialize_prepared( $prepared );
	}

	/**
	 * Validate and resolve every destination without mutating WordPress or the filesystem.
	 *
	 * The resulting state may be passed to materialize_prepared(). That method prepares
	 * again before writing so changes after this check cannot bypass conflict protection.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function prepare( array $plan, array $args = array() ): array {
		$state = array(
			'plan'                         => $plan,
			'plan_hash'                    => self::hash( $plan ),
			'diagnostics'                  => array(),
			'applied'                      => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'                      => array(),
			'existing_matches'             => array( 'pages' => array() ),
			'report_destinations'          => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
			'external_report_destinations' => isset( $args['external_report_destinations'] ) && is_array( $args['external_report_destinations'] ) ? $args['external_report_destinations'] : array(),
			'args'                         => $args,
		);

		try {
			WordPressSitePlan::assertValid( $plan );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][] = array( 'reason_code' => 'canonical_plan_rejected' );
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}

		try {
			$slug = sanitize_key( (string) ( $args['slug'] ?? '' ) );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			$theme_root = get_theme_root();
			if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validates the native theme destination before atomic local writes.
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			$theme_dir = trailingslashit( $theme_root ) . $slug;
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			$theme_uri = trailingslashit( get_theme_root_uri() ) . $slug;
			try {
				// Resolver proof is canonical semantics, not an inference from copied files.
				$resolved = ( new WordPressSitePlanResolver() )->resolve(
					$plan,
					array(
						'theme_uri'            => $theme_uri,
						'require_proven_dynamic_client_assets' => $args['require_proven_dynamic_client_assets'] ?? true,
						'runtime_capabilities' => array( 'asset_materialization' ),
					)
				);
			} catch ( InvalidArgumentException $error ) {
				throw new InvalidArgumentException( $error->getMessage(), 0, $error );
			}
			$state['base_resolved']      = $resolved;
			$state['base_resolved_hash'] = self::hash( $resolved );
			$state['resolved']           = $resolved;
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'] );
			$state['theme_dir'] = $theme_dir;
			$state['theme']     = array(
				'slug' => $slug,
				'dir'  => $theme_dir,
				'uri'  => $theme_uri,
			);
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][] = array( 'reason_code' => $error->getMessage() );
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}
		$state['status']      = 'prepared';
		$state['args']        = $args;
		$state['preparation'] = array(
			'canonical_validations'       => 1,
			'plan_resolutions'            => 1,
			'destination_preflights'      => 1,
			'immutable_projection_reused' => false,
		);
		return $state;
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['plan'] ) || ! is_array( $prepared['plan'] ) ) {
			return self::receipt(
				'rejected',
				array(
					'plan'             => array(),
					'plan_hash'        => '',
					'diagnostics'      => array( array( 'reason_code' => 'invalid_prepared_state' ) ),
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
		$state = self::refresh_prepared_destination( $prepared );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$capabilities = Static_Site_Importer_Current_Site_Capabilities::check_plan( $state );
		if ( is_wp_error( $capabilities ) ) {
			return self::failed_receipt_from_error( $state, $capabilities );
		}
		$args         = $state['args'];
		$font_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay(
			isset( $args['font_materialization'] ) && is_array( $args['font_materialization'] ) ? $args['font_materialization'] : array(),
			$state['resolved']
		);
		if ( is_wp_error( $font_overlay ) ) {
			return self::failed_receipt_from_error( $state, $font_overlay );
		}

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$post = self::materialize_page( $page, $state['source_ids'], (string) ( $args['import_run_id'] ?? '' ) );
			if ( is_wp_error( $post ) ) {
				return self::failed_receipt( $state, $post->get_error_code() );
			}
			$state['page_ids'][ $page['reconciliation_identity'] ] = $post;
			$state['source_ids'][ $page['source_path'] ]           = $post;
			$materialized_markup                                   = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] );
			update_post_meta(
				$post,
				'_static_site_importer_provenance',
				wp_json_encode(
					array(
						'schema'                  => 'static-site-importer/page-provenance/v1',
						'import_run_id'           => (string) ( $args['import_run_id'] ?? '' ),
						'source_path'             => $page['source_path'],
						'reconciliation_identity' => $page['reconciliation_identity'],
						'content_hash'            => hash( 'sha256', $materialized_markup ),
					)
				)
			);
			$state['applied']['posts'][] = array(
				'id'                      => $post,
				'source_path'             => $page['source_path'],
				'reconciliation_identity' => $page['reconciliation_identity'],
			);
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] as &$binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $page['source_path'] ) {
					$binding_report['status']  = 'completed';
					$binding_report['post_id'] = $post;
				}
			}
			unset( $binding_report );
		}

		foreach ( $state['resolved']['writes'] as $write ) {
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( isset( $state['provider_layout_overlay_writes'][ $path ] ) && is_file( $path ) && self::file_hash( $path ) === hash( 'sha256', $state['provider_layout_overlay_writes'][ $path ] ) ) {
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && 'theme_bootstrap' === ( $write['kind'] ?? '' ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				$result = self::merge_batch_bootstrap( $state['theme_dir'], $write );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['files'][] = $result;
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && in_array( $write['kind'] ?? '', array( 'theme_scaffold', 'theme_bootstrap', 'theme_template' ), true ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				continue;
			}
			$result = self::write_file( $state['theme_dir'], $write );
			if ( is_wp_error( $result ) ) {
				return self::failed_receipt( $state, $result->get_error_code() );
			}
			$state['applied']['files'][] = $result;
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
		$font_materialization = self::apply_font_overlay( $state, $font_overlay );
		if ( is_wp_error( $font_materialization ) ) {
			return self::failed_receipt_from_error( $state, $font_materialization );
		}
		$state['applied']['font_materialization'] = $font_materialization;
		$svg_receipts                             = self::verify_svg_font_materialization( $state );
		if ( is_wp_error( $svg_receipts ) ) {
			return self::failed_receipt( $state, $svg_receipts->get_error_code() );
		}
		if ( ! empty( $font_materialization['svg_receipts'] ) ) {
			$publications = self::verify_asset_publications( $state );
			if ( is_wp_error( $publications ) ) {
				return self::failed_receipt( $state, $publications->get_error_code() );
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			foreach ( $state['resolved']['operations'] as $operation ) {
				if ( 'create_page' === $operation['kind'] ) {
					continue;
				}
				$result = self::apply_operation( $operation, $state['page_ids'] );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['operations'][] = $result;
			}
			switch_theme( $state['theme']['slug'] );
			$state['applied']['operations'][] = array(
				'kind'       => 'activate_theme',
				'theme_slug' => $state['theme']['slug'],
			);
			if ( ! isset( $args['disable_smilies'] ) || false !== (bool) $args['disable_smilies'] ) {
				// update_option( 'use_smilies', false ) returns false both on failure and
				// when the stored value is already false, so the existing value is the oracle.
				if ( false !== get_option( 'use_smilies', false ) ) {
					if ( false === update_option( 'use_smilies', false ) ) {
						return self::failed_receipt( $state, 'disable_smilies_not_applied' );
					}
				}
				$state['applied']['runtime_policy']['disable_smilies'] = true;
			}
			if ( '' !== trim( (string) ( $args['site_title'] ?? '' ) ) ) {
				update_option( 'blogname', sanitize_text_field( (string) $args['site_title'] ) );
				$state['applied']['operations'][] = array( 'kind' => 'site_title' );
			}
		}

		return self::receipt( 'completed', $state );
	}

	/**
	 * Recheck mutable destinations without repeating canonical validation and resolution.
	 *
	 * @param array<string,mixed> $prepared Previously validated immutable projection.
	 * @return array<string,mixed>
	 */
	private static function refresh_prepared_destination( array $prepared ): array {
		$plan          = $prepared['plan'] ?? null;
		$base_resolved = $prepared['base_resolved'] ?? null;
		$args          = isset( $prepared['args'] ) && is_array( $prepared['args'] ) ? $prepared['args'] : array();
		if ( ! is_array( $plan ) || ! is_array( $base_resolved ) || self::hash( $plan ) !== ( $prepared['plan_hash'] ?? '' ) || self::hash( $base_resolved ) !== ( $prepared['base_resolved_hash'] ?? '' ) ) {
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', array(
					'plan'             => is_array( $plan ) ? $plan : array(),
					'plan_hash'        => '',
					'diagnostics'      => array( array( 'reason_code' => 'prepared_projection_changed' ) ),
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
				) ),
			);
		}

		$slug       = sanitize_key( (string) ( $args['slug'] ?? '' ) );
		$theme_root = get_theme_root();
		$theme_uri  = trailingslashit( get_theme_root_uri() ) . $slug;
		$theme_dir  = trailingslashit( $theme_root ) . $slug;
		$state      = array(
			'plan'                         => $plan,
			'plan_hash'                    => $prepared['plan_hash'],
			'base_resolved'                => $base_resolved,
			'base_resolved_hash'           => $prepared['base_resolved_hash'],
			'resolved'                     => $base_resolved,
			'diagnostics'                  => array(),
			'applied'                      => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'                      => array(),
			'existing_matches'             => array( 'pages' => array() ),
			'report_destinations'          => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
			'external_report_destinations' => isset( $args['external_report_destinations'] ) && is_array( $args['external_report_destinations'] ) ? $args['external_report_destinations'] : array(),
			'theme_dir'                    => $theme_dir,
			'theme'                        => array(
				'slug' => $slug,
				'dir'  => $theme_dir,
				'uri'  => $theme_uri,
			),
			'args'                         => $args,
			'preparation'                  => array(
				'canonical_validations'       => 1,
				'plan_resolutions'            => 1,
				'destination_preflights'      => 2,
				'immutable_projection_reused' => true,
			),
		);

		try {
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Revalidates the prepared native theme destination before mutation.
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			if ( ( $prepared['theme']['dir'] ?? null ) !== $theme_dir || ( $prepared['theme']['uri'] ?? null ) !== $theme_uri ) {
				throw new InvalidArgumentException( 'prepared_destination_changed' );
			}
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'] );
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][] = array( 'reason_code' => $error->getMessage() );
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}

		$state['status'] = 'prepared';
		return $state;
	}

	/** @param array<string,mixed> $state */
	private static function preflight_state( array &$state, bool $overwrite, string $import_run_id = '' ): void {
		$pages_by_route      = array();
		$state['page_ids']   = array();
		$state['source_ids'] = array();
		foreach ( $state['resolved']['pages'] as &$page ) {
			if ( ! isset( $page['resolved_block_markup'] ) || ! is_string( $page['resolved_block_markup'] ) || '' === trim( $page['resolved_block_markup'] ) ) {
				throw new InvalidArgumentException( 'page_missing_final_block_markup' );
			}
			$route = (string) ( $page['route']['path'] ?? '' );
			if ( isset( $pages_by_route[ $route ] ) ) {
				throw new InvalidArgumentException( 'duplicate_page_route' );
			}
			$pages_by_route[ $route ] = true;

			// The materializer is the consumer boundary: it trusts the type and
			// date the producer declared on the plan row and only falls back to
			// consumer-side detection when the compiler left the row undecided
			// ('page', its default). Classification runs once here so the
			// existing-match, conflict, and materialize paths read one value.
			$classification                            = Static_Site_Importer_Document_Type_Classifier::classify( $page );
			$page['post_type']                         = $classification['post_type'];
			$page['metadata']['detected_date']         = $classification['date'];
			$page['metadata']['classification_signal'] = $classification['signal'];
			$existing                                  = self::reconciled_post( $page['reconciliation_identity'] );
			if ( $existing ) {
				$page = self::plan_existing_page( $state, $page, $existing, 'reconciliation_identity_match' );
				continue;
			}
			$conflict = '' === trim( $route, '/' ) ? null : get_page_by_path( trim( $route, '/' ), OBJECT, $page['post_type'] );
			if ( $conflict && ! $overwrite && ! self::post_belongs_to_run( $conflict, $import_run_id ) ) {
				throw new InvalidArgumentException( 'post_conflict' );
			}
			if ( $conflict ) {
				$page = self::plan_existing_page( $state, $page, $conflict, 'canonical_route_match' );
			}
		}
		unset( $page );
		$state['ordered_pages'] = self::parent_ordered_pages( $state['resolved']['pages'], $import_run_id );
		if ( null === $state['ordered_pages'] ) {
			throw new InvalidArgumentException( 'invalid_page_parent_identity' );
		}
		foreach ( $state['resolved']['operations'] as $operation ) {
			if ( 'create_page' === $operation['kind'] ) {
				continue;
			}
			if ( 'site_reading' !== $operation['kind'] || ( ! isset( $state['page_ids'][ $operation['front_page_reconciliation_identity'] ] ) && ! self::page_exists_in_plan( $state['resolved']['pages'], $operation['front_page_reconciliation_identity'] ) ) ) {
				throw new InvalidArgumentException( 'unsupported_operation' );
			}
		}
		$overlay_writes = self::provider_layout_stylesheet_writes( $state, $state['args']['provider_layout_overlays'] ?? array() );
		if ( is_wp_error( $overlay_writes ) ) {
			throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
		}
		$state['provider_layout_overlay_writes'] = $overlay_writes;
		foreach ( $state['resolved']['writes'] as $write ) {
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( ! self::safe_destination( $state['theme_dir'], $write['target_path'] ) ) {
				throw new InvalidArgumentException( 'unsafe_destination_path' );
			}
			if ( is_dir( $path ) || ( file_exists( $path ) && ! $overwrite && ! self::theme_belongs_to_run( $state['theme_dir'], $import_run_id ) && self::file_hash( $path ) !== self::payload_hash( $write ) && ( ! isset( $overlay_writes[ $path ] ) || self::file_hash( $path ) !== hash( 'sha256', $overlay_writes[ $path ] ) ) ) ) {
				if ( isset( $overlay_writes[ $path ] ) ) {
					throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
				}
				throw new InvalidArgumentException( 'file_conflict' );
			}
		}
		foreach ( $state['report_destinations'] ?? array() as $path ) {
			$parent = is_string( $path ) ? dirname( $path ) : '';
			while ( '' !== $parent && ! file_exists( $parent ) ) {
				$parent = dirname( $parent );
			}
			if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || '' === $parent || is_link( $parent ) || ! is_dir( $parent ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights native report destinations used by atomic local writes.
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
		foreach ( $state['external_report_destinations'] ?? array() as $path ) {
			if ( ! self::safe_external_report_destination( $path ) ) {
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
	}

	/**
	 * External report output is a CLI-only operator seam. Every artifact must be
	 * a new file directly beneath one existing, physical directory.
	 */
	public static function safe_external_report_destination( $path ): bool {
		if ( ! is_string( $path ) || '' === $path || str_contains( str_replace( '\\', '/', $path ), '/../' ) || str_starts_with( str_replace( '\\', '/', $path ), '../' ) || str_ends_with( str_replace( '\\', '/', $path ), '/..' ) || str_contains( str_replace( '\\', '/', $path ), '/./' ) ) {
			return false;
		}
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || is_link( $path ) || file_exists( $path ) || is_link( $parent ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights explicit CLI report destinations before atomic local writes.
			return false;
		}
		while ( DIRECTORY_SEPARATOR !== $parent && '.' !== $parent ) {
			if ( is_link( $parent ) ) {
				return false;
			}
			$parent = dirname( $parent );
		}

		return true;
	}

	/** @param array<string,mixed> $page @param array<string,int> $source_ids */
	private static function materialize_page( array $page, array $source_ids, string $import_run_id = '' ) {
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
			$parent = '' === $page['parent_source_path'] ? 0 : ( $source_ids[ $page['parent_source_path'] ] ?? self::existing_source_page_id( $page['parent_source_path'], $import_run_id ) );
			if ( $parent <= 0 && '' !== $page['parent_source_path'] ) {
				return new WP_Error( 'missing_parent_page', 'The parent route has not been materialized by this import run.', array(
					'source_path'        => $page['source_path'],
					'parent_source_path' => $page['parent_source_path'],
				) );
			}
		} else {
			$parent = 0;
		}
		$post = array(
			'ID'           => (int) ( $page['planned_existing_id'] ?? 0 ),
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
		update_post_meta( (int) $id, self::RECONCILIATION_META_KEY, $page['reconciliation_identity'] );
		return (int) $id;
	}

	/** Apply exact provider bindings to the resolved projection while retaining canonical plan markup. */
	private static function apply_runtime_entity_bindings( array &$plan, array $bindings, array &$reports ): void {
		$pages = array();
		foreach ( $plan['pages'] as $index => $page ) {
			$pages[ $page['source_path'] ] = $index;
		}
		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
		}
		usort(
			$bindings,
			static function ( array $left, array $right ): int {
				$group = strcmp( (string) ( $left['source_path'] ?? '' ) . "\n" . (string) ( $left['search_block_markup'] ?? '' ), (string) ( $right['source_path'] ?? '' ) . "\n" . (string) ( $right['search_block_markup'] ?? '' ) );
				return 0 !== $group ? $group : (int) ( $right['occurrence'] ?? 0 ) <=> (int) ( $left['occurrence'] ?? 0 );
			}
		);
		$seen = array();
		foreach ( $bindings as $binding ) {
			$selectors = $binding['superseded_runtime_selectors'] ?? array();
			if ( ! is_array( $binding ) || 'static-site-importer/runtime-entity-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! isset( $pages[ $binding['source_path'] ] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || ! is_string( $binding['replacement_block_markup'] ?? null ) || '' === trim( $binding['replacement_block_markup'] ) || ! is_string( $binding['reconciliation_identity'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding['reconciliation_identity'] ) || isset( $seen[ $binding['reconciliation_identity'] ] ) || ! is_array( $selectors ) ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
			$selectors = array_values( array_unique( $selectors ) );
			foreach ( $selectors as $selector ) {
				if ( ! is_string( $selector ) || '' === trim( $selector ) || strlen( $selector ) > 1024 ) {
					throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
				}
			}
			$seen[ $binding['reconciliation_identity'] ] = true;
			$index                                       = $pages[ $binding['source_path'] ];
			$content                                     = (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ?? '' );
			if ( substr_count( $content, $binding['search_block_markup'] ) < $binding['occurrence'] ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_cardinality_mismatch' );
			}
			$position = 0;
			for ( $occurrence = 0; $occurrence < $binding['occurrence']; ++$occurrence ) {
				$position = strpos( $content, $binding['search_block_markup'], $position );
				if ( false === $position ) {
					throw new InvalidArgumentException( 'runtime_entity_binding_cardinality_mismatch' );
				}
				if ( $occurrence + 1 < $binding['occurrence'] ) {
					$position += strlen( $binding['search_block_markup'] );
				}
			}
			$materialized = substr( $content, 0, $position ) . $binding['replacement_block_markup'] . substr( $content, $position + strlen( $binding['search_block_markup'] ) );
			$plan['pages'][ $index ]['materialized_block_markup'] = $materialized;
			$reports[ $binding['reconciliation_identity'] ]       = array(
				'status'                       => 'prepared',
				'source_path'                  => $binding['source_path'],
				'role'                         => $binding['role'] ?? '',
				'declaration_id'               => $binding['declaration_id'] ?? '',
				'superseded_runtime_selectors' => $selectors,
			);
		}
		foreach ( $reports as &$report ) {
			$index                               = $pages[ $report['source_path'] ];
			$report['materialized_content_hash'] = hash( 'sha256', (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ) );
		}
		unset( $report );
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $page */
	private static function plan_existing_page( array &$state, array $page, WP_Post $existing, string $reason ): array {
		$id                          = (int) $existing->ID;
		$protected                   = class_exists( 'Static_Site_Importer_Page_Materializer' ) && Static_Site_Importer_Page_Materializer::is_protected_page( $existing );
		$page['planned_existing_id'] = $id;
		$state['page_ids'][ $page['reconciliation_identity'] ] = $id;
		$state['source_ids'][ $page['source_path'] ]           = $id;
		$row                                  = array(
			'post_id'     => $id,
			'source_path' => $page['source_path'],
			'route'       => $page['route']['path'],
			'permalink'   => function_exists( 'get_permalink' ) ? get_permalink( $existing ) : $page['route']['path'],
			'slug'        => $page['slug'],
			'post_type'   => $page['post_type'],
			'protected'   => $protected,
			'reason'      => $reason,
		);
		$state['existing_matches']['pages'][] = $row;
		if ( $protected ) {
			$page['skip_materialization'] = true;
			$state['skipped'][]           = $row;
		}
		return $page;
	}

	/** @param array<string,mixed> $write */
	private static function write_file( string $theme_dir, array $write ) {
		$path = $theme_dir . '/' . $write['target_path'];
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical artifact payload bytes.
		if ( is_file( $path ) && is_string( $data ) && self::file_hash( $path ) === hash( 'sha256', $data ) ) {
			return array(
				'target_path'             => $write['target_path'],
				'hash'                    => self::file_hash( $path ),
				'payload_hash'            => $write['payload_hash'] ?? hash( 'sha256', $data ),
				'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
			);
		}
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'theme_directory_create_failed' );
		}
		$temp = tempnam( dirname( $path ), '.ssi-plan-' );
		if ( false === $data || false === $temp || false === file_put_contents( $temp, $data ) || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.rename_rename -- Atomically materializes the canonical declared theme write.
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

	/** Merge a validated later-batch bootstrap as an idempotent PHP include. */
	private static function merge_batch_bootstrap( string $theme_dir, array $write ) {
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
			$include_write = self::write_file( $theme_dir, array(
				'target_path'  => $include,
				'source_path'  => $write['source_path'],
				'payload'      => array(
					'encoding' => 'utf8',
					'data'     => $bootstrap,
				),
				'payload_hash' => $hash,
			) );
			if ( is_wp_error( $include_write ) ) {
				return $include_write;
			}
			$functions_write = self::write_file( $theme_dir, array(
				'target_path'  => $write['target_path'],
				'source_path'  => $write['source_path'],
				'payload'      => array(
					'encoding' => 'utf8',
					'data'     => $current . $require,
				),
				'payload_hash' => hash( 'sha256', $current . $require ),
			) );
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

	/** Persist admitted provider overlay CSS into every generated frontend/editor stylesheet. */
	private static function apply_provider_layout_overlays( array &$state, array $overlays ) {
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
			$target = ltrim( substr( $path, strlen( trailingslashit( $state['theme_dir'] ) ) ), '/' );
			$existing = file_exists( $path ) ? ( is_readable( $path ) ? file_get_contents( $path ) : false ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies an existing generated stylesheet before reconciliation.
			if ( false === $existing ) {
				return new WP_Error( 'provider_layout_stylesheet_read_failed' );
			}
			if ( $content === $existing ) {
				$reports[] = array(
					'target_path' => $target,
					'hash'        => hash( 'sha256', $content ),
					'status'      => 'already_satisfied',
				);
				continue;
			}
			$result = self::write_file( $state['theme_dir'], array(
				'target_path' => $target,
				'source_path' => $target,
				'payload'     => array(
					'encoding' => 'utf8',
					'data'     => $content,
				),
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['status'] = 'applied';
			$reports[] = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
				}
			}
		}
		return array(
			'status' => array_filter( $reports, static fn( array $report ): bool => 'applied' === ( $report['status'] ?? '' ) ) ? 'completed' : 'already_satisfied',
			'files'  => $reports,
		);
	}

	/** Derive expected overlay-composed stylesheets from their canonical plan payloads. */
	private static function provider_layout_stylesheet_writes( array $state, array $overlays ) {
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
		foreach ( $state['resolved']['writes'] as $write ) {
			$target = (string) ( $write['target_path'] ?? '' );
			$css    = self::payload_data( $write );
			if ( str_ends_with( $target, '.css' ) && '' === $source_css ) {
				$source_css = $css;
			}
			if ( in_array( $target, array( 'style.css', 'assets/css/editor-style.css' ), true ) ) {
				$stylesheets[ $state['theme_dir'] . '/' . $target ] = $css;
			}
		}
		if ( isset( $stylesheets[ $state['theme_dir'] . '/style.css' ], $stylesheets[ $state['theme_dir'] . '/assets/css/editor-style.css' ] ) ) {
			return Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], '', '', array(), array(), $overlays, $stylesheets );
		}
		return '' === $source_css
			? new WP_Error( 'provider_layout_stylesheet_missing' )
			: Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], (string) $state['theme']['slug'], $source_css, array(), array(), $overlays );
	}

	/** @param array<string,mixed> $state @param array{writes:array<int,array<string,string>>,diagnostics:array<int,array<string,string>>} $overlay */
	private static function apply_font_overlay( array &$state, array $overlay ) {
		$reports = array();
		foreach ( $overlay['writes'] as $write ) {
			$target = (string) ( $write['target_path'] ?? '' );
			if ( str_ends_with( strtolower( $target ), '.svg' ) && ! empty( $overlay['svg_consumers'] ) && ! self::valid_svg_font_receipt( $overlay['svg_receipts'] ?? array(), $state['resolved']['writes'], $write ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			if ( ! self::safe_destination( $state['theme_dir'], $target ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_destination_invalid' );
			}
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
			'status'         => 'completed',
			'files'          => $reports,
			'diagnostics'    => $overlay['diagnostics'],
			'faces'          => $overlay['faces'] ?? array(),
			'required_faces' => $overlay['required_faces'] ?? array(),
			'svg_receipts'   => $overlay['svg_receipts'] ?? array(),
			'svg_consumers'  => $overlay['svg_consumers'] ?? array(),
		);
	}

	/** Verify every canonical asset publication against its resolved write and references. */
	private static function verify_asset_publications( array &$state ) {
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
				'references'                    => $references[ $id ] ?? array(),
			);
			if ( ! $valid ) {
				return new WP_Error( 'static_site_importer_asset_publication_verification_failed' );
			}
		}
		return true;
	}

	private static function svg_receipt_for_write( array $receipts, mixed $write ): ?array {
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
	private static function verify_svg_font_materialization( array $state ) {
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

	private static function svg_receipt_for_target( array $receipts, string $target ): bool {
		foreach ( $receipts as $receipt ) {
			if ( is_array( $receipt ) && ( $receipt['target_path'] ?? null ) === $target ) {
				return true;
			}
		}
		return false;
	}

	private static function valid_svg_font_receipt_binding( array $receipt, array $write ): bool {
		return 'static-site-importer/svg-font-materialization-receipt/v1' === ( $receipt['schema'] ?? null )
			&& ( $write['target_path'] ?? null ) === ( $receipt['target_path'] ?? null )
			&& ( $write['reconciliation_identity'] ?? null ) === ( $receipt['write_reconciliation_identity'] ?? null )
			&& hash( 'sha256', self::payload_data( $write ) ) === ( $receipt['input_sha256'] ?? null )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['output_sha256'] ?? '' ) )
			&& true === ( $receipt['required'] ?? null )
			&& ! empty( $receipt['face_ids'] ) && is_array( $receipt['observed_font_sha256'] ?? null ) && ! empty( $receipt['observed_font_sha256'] );
	}

	/** Validate the only permitted post-canonical SVG mutation. */
	private static function valid_svg_font_receipt( array $receipts, array $canonical_writes, array $overlay_write ): bool {
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

	private static function payload_data( array $write ): string {
		$data = $write['payload']['data'] ?? '';
		return 'base64' === ( $write['payload']['encoding'] ?? null ) && is_string( $data ) ? (string) base64_decode( $data, true ) : (string) $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes for receipt validation.
	}

	/** @param array<string,mixed> $operation @param array<string,int> $page_ids */
	private static function apply_operation( array $operation, array $page_ids ) {
		$id = $page_ids[ $operation['front_page_reconciliation_identity'] ] ?? 0;
		if ( ! $id ) {
			return new WP_Error( 'operation_target_missing' );
		}
		update_option( 'show_on_front', $operation['show_on_front'] );
		update_option( 'page_on_front', $id );
		return array(
			'kind'                    => $operation['kind'],
			'order'                   => $operation['order'],
			'reconciliation_identity' => $operation['front_page_reconciliation_identity'],
		);
	}

	private static function reconciled_post( string $identity ) {
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

	private static function existing_source_page_id( string $source_path, string $import_run_id ): int {
		if ( '' === $import_run_id ) {
			return 0; }
		$posts = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'meta_key'    => '_static_site_importer_provenance',
			'numberposts' => -1,
		) );
		foreach ( $posts as $post ) {
			$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
			if ( is_array( $provenance ) && ( $provenance['source_path'] ?? '' ) === $source_path && ( $provenance['import_run_id'] ?? '' ) === $import_run_id ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	private static function post_belongs_to_run( WP_Post $post, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
		return is_array( $provenance ) && ( $provenance['import_run_id'] ?? '' ) === $import_run_id;
	}

	/**
	 * Whether a post type is registered on the runtime import target.
	 *
	 * Mirrors Static_Site_Importer_Page_Materializer::page_post_type(): any
	 * registered type is valid; internal types without an object (revision,
	 * nav_menu_item, wp_template_part) fall back to 'page'.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function is_valid_post_type( string $post_type ): bool {
		if ( function_exists( 'get_post_type_object' ) ) {
			return get_post_type_object( $post_type ) instanceof WP_Post_Type;
		}
		return in_array( $post_type, array( 'page', 'post' ), true );
	}

	/** @param array<int,array<string,mixed>> $pages */
	private static function page_exists_in_plan( array $pages, string $identity ): bool {
		foreach ( $pages as $page ) {
			if ( $page['reconciliation_identity'] === $identity ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>>|null */
	private static function parent_ordered_pages( array $pages, string $import_run_id = '' ): ?array {
		$remaining = array();
		foreach ( $pages as $page ) {
			$remaining[ $page['source_path'] ] = $page;
		}
		$ordered          = array();
		$external_parents = array();
		while ( ! empty( $remaining ) ) {
			$progress = false;
			foreach ( $remaining as $source => $page ) {
				$parent = $page['parent_source_path'];
				if ( '' !== $parent && ! isset( $ordered[ $parent ] ) && ! isset( $external_parents[ $parent ] ) ) {
					if ( ! isset( $remaining[ $parent ] ) ) {
						if ( self::existing_source_page_id( $parent, $import_run_id ) <= 0 ) {
							return null; }
						$external_parents[ $parent ] = true;
						$progress                    = true;
						continue;
					}
					continue;
				}
				$ordered[ $source ] = $page;
				unset( $remaining[ $source ] );
				$progress = true;
			}
			if ( ! $progress ) {
				return null;
			}
		}
		return array_values( $ordered );
	}

	private static function theme_belongs_to_run( string $theme_dir, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$manifest = $theme_dir . '/static-site-importer-manifest.json';
		$data     = is_file( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned run manifest for batch reconciliation.
		return is_array( $data ) && ( $data['import_run_id'] ?? '' ) === $import_run_id;
	}

	private static function safe_destination( string $theme_dir, string $target ): bool {
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
	private static function payload_hash( array $write ): string {
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes before hashing.
		return is_string( $data ) ? hash( 'sha256', $data ) : '';
	}

	private static function file_hash( string $path ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preflight hashes a declared destination file.
		return false === $data ? '' : hash( 'sha256', $data );
	}

	/** @param array<string,mixed> $state */
	private static function failed_receipt( array $state, int|string $reason ): array {
		$state['diagnostics'][] = array( 'reason_code' => (string) $reason );
		return self::receipt( 'partial', $state );
	}

	/** @param array<string,mixed> $state */
	private static function failed_receipt_from_error( array $state, WP_Error $error ): array {
		$state['diagnostics'][] = array( 'reason_code' => $error->get_error_code() );
		$data                   = $error->get_error_data();
		if ( is_array( $data ) ) {
			foreach ( $data as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				$reason = (string) ( $diagnostic['reason_code'] ?? $diagnostic['reason'] ?? $diagnostic['code'] ?? '' );
				if ( '' !== $reason ) {
					$state['diagnostics'][] = array_merge( $diagnostic, array( 'reason_code' => $reason ) );
				}
			}
		}
		return self::receipt( 'partial', $state );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private static function receipt( string $status, array $state ): array {
		$plan                   = $state['plan'];
		$resolved_plan          = $state['resolved'] ?? $plan;
		$materialized_pages     = array();
		$block_provenance       = array();
		$block_provenance_count = 0;
		$written_sources        = array_fill_keys( array_filter( array_column( $state['applied']['posts'] ?? array(), 'source_path' ), 'is_string' ), true );
		foreach ( $resolved_plan['pages'] as &$page ) {
			if ( isset( $written_sources[ $page['source_path'] ] ) ) {
				$materialized_markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
				++$block_provenance_count;
				if ( count( $block_provenance ) < self::BLOCK_PROVENANCE_LIMIT ) {
					$block_provenance[] = self::block_provenance( $page, $materialized_markup );
				}
				if ( isset( $page['materialized_block_markup'] ) ) {
					$materialized_pages[ $page['source_path'] ] = array(
						'block_markup' => $page['materialized_block_markup'],
						'content_hash' => hash( 'sha256', $page['materialized_block_markup'] ),
					);
				}
			}
			if ( isset( $page['materialized_block_markup'] ) ) {
				unset( $page['materialized_block_markup'] );
			}
		}
		unset( $page );
		$errors = array();
		$pages  = isset( $state['source_ids'] ) && is_array( $state['source_ids'] ) ? $state['source_ids'] : array();
		foreach ( $state['diagnostics'] as $diagnostic ) {
			if ( isset( $diagnostic['reason_code'] ) && is_string( $diagnostic['reason_code'] ) ) {
				$errors[] = array(
					'code'    => $diagnostic['reason_code'],
					'message' => $diagnostic['reason_code'],
				);
			}
		}
		return array(
			'schema'                    => self::RECEIPT_SCHEMA,
			'status'                    => $status,
			'plan_hash'                 => $state['plan_hash'],
			'plan'                      => $resolved_plan,
			'theme'                     => $state['theme'] ?? array(),
			'completed'                 => array(
				'pages'                      => $pages,
				'files'                      => $state['applied']['files'],
				'operations'                 => $state['applied']['operations'],
				'runtime_declarations'       => $state['applied']['runtime_declarations'] ?? array( 'asset_publications' => array() ),
				'font_materialization'       => $state['applied']['font_materialization'] ?? array(
					'status'      => 'not_requested',
					'files'       => array(),
					'diagnostics' => array(),
				),
				'provider_layout_overlays'   => $state['applied']['provider_layout_overlays'] ?? array(
					'status' => 'not_requested',
					'files'  => array(),
				),
				'runtime_policy'             => array(
					'disable_smilies' => array(
						'requested' => isset( $state['args']['disable_smilies'] ) ? (bool) $state['args']['disable_smilies'] : true,
						'applied'   => isset( $state['applied']['runtime_policy']['disable_smilies'] ) && true === $state['applied']['runtime_policy']['disable_smilies'],
					),
				),
				'materialized_pages'         => $materialized_pages,
				'block_provenance'           => $block_provenance,
				'block_provenance_count'     => $block_provenance_count,
				'block_provenance_truncated' => $block_provenance_count > count( $block_provenance ),
				'declaration_ids'            => array_keys( $state['applied']['runtime_declarations']['asset_publications'] ?? array() ),
			),
			'reconciliation_identities' => array_merge( array_column( $plan['pages'] ?? array(), 'reconciliation_identity' ), array_column( $plan['writes'] ?? array(), 'reconciliation_identity' ), array_column( $plan['runtime_declarations'] ?? array(), 'reconciliation_identity' ) ),
			'wordpress'                 => $state['applied']['posts'],
			'generated_files'           => $state['applied']['files'],
			'operations'                => $state['applied']['operations'],
			'skipped_targets'           => $state['skipped'],
			'existing_matches'          => $state['existing_matches'],
			'preparation'               => $state['preparation'] ?? array(),
			'diagnostics'               => $state['diagnostics'],
			'errors'                    => $errors,
		);
	}

	/**
	 * Record bounded stage evidence for one WordPress page without retaining markup.
	 *
	 * @param array<string,mixed> $page              Resolved compiler page.
	 * @param string              $materialized_markup WordPress post-content markup.
	 * @return array<string,mixed>
	 */
	private static function block_provenance( array $page, string $materialized_markup ): array {
		$resolved_markup   = (string) ( $page['resolved_block_markup'] ?? '' );
		$resolved_evidence = self::bounded_block_markup_evidence( $resolved_markup );
		$stages            = array(
			array(
				'stage'  => 'blocks-engine/wordpress-site-plan-resolver',
				'output' => $resolved_evidence,
			),
		);
		if ( $resolved_markup !== $materialized_markup ) {
			$stages[] = array(
				'stage'        => 'static-site-importer/runtime-entity-bindings',
				'input_sha256' => $resolved_evidence['sha256'],
				'output'       => self::bounded_block_markup_evidence( $materialized_markup ),
			);
		}

		return array(
			// This mirrors the page meta provenance written during materialization.
			'source' => array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'source_path'             => (string) ( $page['source_path'] ?? '' ),
				'reconciliation_identity' => (string) ( $page['reconciliation_identity'] ?? '' ),
			),
			'stages' => $stages,
		);
	}

	/** @return array{sha256:string,bytes:int} */
	private static function bounded_block_markup_evidence( string $markup ): array {
		return array(
			'sha256' => hash( 'sha256', $markup ),
			'bytes'  => strlen( $markup ),
		);
	}

	/** @param array<string,mixed> $plan */
	private static function hash( array $plan ): string {
		return hash( 'sha256', (string) wp_json_encode( $plan, JSON_UNESCAPED_SLASHES ) );
	}
}
