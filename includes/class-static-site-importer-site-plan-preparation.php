<?php
/**
 * Prepares a canonical WordPress site plan without mutating the runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime as Blocks_Engine_WordPress_Runtime;

/** Validates, resolves, and admits a plan before persistence. */
final class Static_Site_Importer_Site_Plan_Preparation {
	private const BLOCK_PROVENANCE_LIMIT = 50;

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
		// Payload readers hold transient workspace access and must never enter a
		// canonical plan hash, prepared-plan persistence, or receipt projection.
		$payload_reader = $args['_static_site_importer_payload_reader'] ?? null;
		unset( $args['_static_site_importer_payload_reader'] );
		$strategy = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return self::failed_strategy_receipt( $plan, $args, $strategy );
		}
		$args['theme_materialization'] = $strategy['strategy'];
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] && ! is_array( $args['classic_theme_projection'] ?? null ) ) {
			return self::failed_strategy_receipt( $plan, $args, new WP_Error( 'static_site_importer_classic_source_projection_missing', 'Classic materialization requires the SSI source-artifact projection prepared before this plan-only boundary.' ) );
		}
		$default_content = self::discover_default_content( $args );
		$state           = array(
			'plan'                         => $plan,
			'plan_identity'                => self::plan_identity( $plan ),
			'receipt_instance_id'          => Static_Site_Importer_Site_Plan_Receipt::receipt_instance_id(),
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
			'payload_reader'               => is_object( $payload_reader ) ? $payload_reader : null,
			'default_content'              => $default_content,
			'rollback'                     => array(
				'posts'   => array(),
				'files'   => array(),
				'options' => array(),
			),
		);

		try {
			if ( array() === $state['plan_identity'] ) {
				throw new InvalidArgumentException( 'canonical_plan_identity_mismatch' );
			}
			WordPressSitePlan::assertValid( $plan );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][]  = array( 'reason_code' => 'canonical_plan_rejected' );
			$state['failure_reason'] = 'canonical_plan_rejected';
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
			);
		}
		$state['editability_report'] = self::editability_report_admission( $plan );
		if ( in_array( $state['editability_report']['status'], array( 'rejected', 'failed' ), true ) ) {
			$state['diagnostics'][]  = $state['editability_report']['diagnostic'];
			$state['failure_reason'] = (string) ( $state['editability_report']['diagnostic']['reason_code'] ?? 'editability_report_rejected' );
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
			);
		}

		try {
			$destination = Static_Site_Importer_Import_Destination::normalize( $args );
			if ( is_wp_error( $destination ) ) {
				throw new InvalidArgumentException( (string) $destination->get_error_code() );
			}
			$slug = (string) $destination['slug'];
			// Writes resolve against the destination's asset surface: the theme
			// directory it owns, or the companion publication home it borrows.
			$theme_dir = (string) ( $destination['asset_dir'] ?? $destination['theme_dir'] );
			$theme_uri = (string) ( $destination['asset_uri'] ?? $destination['theme_uri'] );
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
			$state['base_resolved'] = $resolved;
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] ) {
				$projection = Static_Site_Importer_Classic_Theme_Projection::prepare_for_materialization( $args['classic_theme_projection'], $resolved );
				if ( is_wp_error( $projection ) ) {
					throw new InvalidArgumentException( (string) $projection->get_error_code() );
				}
				$args['classic_theme_projection'] = $projection;
				$resolved['writes']               = Static_Site_Importer_Classic_Theme_Projection::resolved_writes( $resolved, Static_Site_Importer_Classic_Theme_Projection::writes( $args['classic_theme_projection'], $resolved, $theme_uri, (string) ( $args['name'] ?? $slug ), isset( $args['artifact_provenance'] ) && is_array( $args['artifact_provenance'] ) ? $args['artifact_provenance'] : array() ) );
				foreach ( $resolved['pages'] as &$page ) {
					$page['resolved_block_markup'] = '';
				}
				unset( $page );
				$state['base_resolved'] = $resolved;
			}
			$state['prepared_resolved_projection_hash'] = self::prepared_resolved_projection_hash( $resolved );
			$state['resolved']                          = $resolved;
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			$state['theme_dir']                = $theme_dir;
			$state['destination']              = $destination;
			$state['theme']                    = array(
				'slug'              => $slug,
				'dir'               => $theme_dir,
				'uri'               => $theme_uri,
				'asset_publication' => Static_Site_Importer_Site_Plan_Persistence::asset_publication_receipt( $destination, $theme_dir, $theme_uri, '' ),
			);
			$state['quality_budget_admission'] = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved, $args );
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			if ( isset( $state['preflight_error'] ) && is_wp_error( $state['preflight_error'] ) ) {
				return array(
					'status'  => 'rejected',
					'receipt' => Static_Site_Importer_Site_Plan_Receipt::rejected_receipt_from_error( $state, $state['preflight_error'] ),
				);
			}
			$state['diagnostics'][]  = array( 'reason_code' => $error->getMessage() );
			$state['failure_reason'] = $error->getMessage();
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
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

	/** Prepare the canonical plan state that may safely precede provider provisioning. */
	public static function prepare_for_materialization( array $plan, array $args = array() ): array {
		$args     = self::with_report_destinations( $args );
		$prepared = self::prepare( $plan, $args );
		return 'prepared' === ( $prepared['status'] ?? '' ) ? self::admit_prepared( $prepared ) : $prepared;
	}

	/** Add the importer-owned report targets before canonical destination preflight. */
	public static function with_report_destinations( array $args ): array {
		$theme_dir = trailingslashit( get_theme_root() ) . sanitize_key( (string) ( $args['slug'] ?? '' ) );
		if ( Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $args['destination'] ?? '' ) ) {
			// An existing-theme destination keeps its report artifacts with the
			// companion assets instead of writing them into the host theme.
			$publication = Static_Site_Importer_Companion_Asset_Publication::resolve( $args );
			if ( ! is_wp_error( $publication ) ) {
				$theme_dir = (string) $publication['dir'];
			}
		}
		$reports = array( $theme_dir . '/static-site-importer-manifest.json' );
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$reports = array_merge( $reports, array( $theme_dir . '/import-report.json', $theme_dir . '/import-validation-result.json', $theme_dir . '/finding-packets.json' ) );
		}
		$external = array();
		if ( ! empty( $args['report'] ) ) {
			$external = array( (string) $args['report'], trailingslashit( dirname( (string) $args['report'] ) ) . 'import-validation-result.json', trailingslashit( dirname( (string) $args['report'] ) ) . 'finding-packets.json' );
			$reports  = array_merge( $reports, $external );
		}
		$args['report_destinations']          = $reports;
		$args['external_report_destinations'] = $external;
		return $args;
	}

	/**
	 * Verify all reference-backed payloads before related runtime work begins.
	 *
	 * The success marker is intentionally ephemeral: it is neither part of the
	 * canonical plan hash nor projected into materialization receipts. Individual
	 * writes still reread and verify their payload immediately before mutation.
	 *
	 * @param array<string,mixed> $prepared Prepared materialization state.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function admit_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['resolved']['writes'] ) || ! is_array( $prepared['resolved']['writes'] ) ) {
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt(
					'rejected',
					array(
						'plan'             => isset( $prepared['plan'] ) && is_array( $prepared['plan'] ) ? $prepared['plan'] : array(),
						'plan_identity'    => is_array( $prepared['plan_identity'] ?? null ) ? $prepared['plan_identity'] : array(),
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
				),
			);
		}
		if ( ! empty( $prepared['payload_references_admitted'] ) ) {
			return $prepared;
		}
		$references = self::verify_payload_references( $prepared['resolved']['writes'], is_object( $prepared['payload_reader'] ?? null ) ? $prepared['payload_reader'] : null );
		if ( is_wp_error( $references ) ) {
			$prepared['diagnostics'][]  = array( 'reason_code' => $references->get_error_code() );
			$prepared['failure_reason'] = $references->get_error_code();
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $prepared ),
			);
		}
		$prepared['payload_references_admitted'] = true;
		return $prepared;
	}

	/** Return a non-mutating receipt for a rejected strategy before destination preflight. */
	public static function failed_strategy_receipt( array $plan, array $args, WP_Error $error ): array {
		$state = array(
			'plan'                  => $plan,
			'plan_identity'         => self::plan_identity( $plan ),
			'receipt_instance_id'   => Static_Site_Importer_Site_Plan_Receipt::receipt_instance_id(),
			'diagnostics'           => array( array( 'reason_code' => $error->get_error_code() ) ),
			'failure_reason'        => $error->get_error_code(),
			'applied'               => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'               => array(),
			'existing_matches'      => array( 'pages' => array() ),
			'args'                  => $args,
			'theme_materialization' => is_array( $error->get_error_data() ) && isset( $error->get_error_data()['theme_materialization'] ) && is_array( $error->get_error_data()['theme_materialization'] ) ? $error->get_error_data()['theme_materialization'] : Static_Site_Importer_Site_Plan_Receipt::strategy_evidence( $args ),
		);
		return array(
			'status'  => 'rejected',
			'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
		);
	}

	/**
	 * Recheck mutable destinations without repeating canonical validation and resolution.
	 *
	 * @param array<string,mixed> $prepared Previously validated immutable projection.
	 * @return array<string,mixed>
	 */
	public static function refresh_prepared_destination( array $prepared ): array {
		$plan           = $prepared['plan'] ?? null;
		$base_resolved  = $prepared['base_resolved'] ?? null;
		$args           = isset( $prepared['args'] ) && is_array( $prepared['args'] ) ? $prepared['args'] : array();
		$payload_reader = is_object( $prepared['payload_reader'] ?? null ) ? $prepared['payload_reader'] : null;
		if ( ! is_array( $plan ) || ! is_array( $base_resolved ) || ! Static_Site_Importer_Site_Plan_Receipt::valid_receipt_instance_id( $prepared['receipt_instance_id'] ?? null ) || self::plan_identity( $plan ) !== ( $prepared['plan_identity'] ?? null ) || self::prepared_resolved_projection_hash( $base_resolved ) !== ( $prepared['prepared_resolved_projection_hash'] ?? '' ) ) {
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt(
					'rejected',
					array(
						'plan'             => is_array( $plan ) ? $plan : array(),
						'plan_identity'    => array(),
						'diagnostics'      => array( array( 'reason_code' => 'prepared_projection_changed' ) ),
						'failure_reason'   => 'prepared_projection_changed',
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
				),
			);
		}

		$destination   = isset( $prepared['destination'] ) && is_array( $prepared['destination'] ) ? $prepared['destination'] : null;
		$recheck_error = '';
		$theme_root    = '';
		if ( null !== $destination && Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $destination['mode'] ?? '' ) ) {
			// Mutable existing destinations are rechecked against the site's own
			// active theme and companion publication root before writing.
			$rechecked = Static_Site_Importer_Import_Destination::normalize( $args );
			if ( is_wp_error( $rechecked ) ) {
				$recheck_error = (string) $rechecked->get_error_code();
			} elseif ( $rechecked !== $destination ) {
				$recheck_error = 'prepared_destination_changed';
			}
		} else {
			$destination = null;
		}
		if ( null === $destination ) {
			$slug        = sanitize_key( (string) ( $args['slug'] ?? '' ) );
			$theme_root  = get_theme_root();
			$theme_uri   = trailingslashit( get_theme_root_uri() ) . $slug;
			$theme_dir   = trailingslashit( $theme_root ) . $slug;
			$destination = array(
				'mode'               => Static_Site_Importer_Import_Destination::GENERATED_THEME,
				'slug'               => $slug,
				'theme_dir'          => $theme_dir,
				'theme_uri'          => $theme_uri,
				'owns_theme'         => true,
				'permits_activation' => true,
			);
		} else {
			$slug      = (string) $destination['slug'];
			$theme_dir = (string) ( $destination['asset_dir'] ?? $destination['theme_dir'] );
			$theme_uri = (string) ( $destination['asset_uri'] ?? $destination['theme_uri'] );
		}
		$state = array(
			'plan'                              => $plan,
			'plan_identity'                     => $prepared['plan_identity'],
			'receipt_instance_id'               => $prepared['receipt_instance_id'],
			'editability_report'                => $prepared['editability_report'] ?? array(),
			'base_resolved'                     => $base_resolved,
			'prepared_resolved_projection_hash' => $prepared['prepared_resolved_projection_hash'],
			'resolved'                          => $base_resolved,
			'diagnostics'                       => array(),
			'applied'                           => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'                           => array(),
			'existing_matches'                  => array( 'pages' => array() ),
			'report_destinations'               => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
			'external_report_destinations'      => isset( $args['external_report_destinations'] ) && is_array( $args['external_report_destinations'] ) ? $args['external_report_destinations'] : array(),
			'theme_dir'                         => $theme_dir,
			'destination'                       => $destination,
			'theme'                             => array(
				'slug'              => $slug,
				'dir'               => $theme_dir,
				'uri'               => $theme_uri,
				'asset_publication' => Static_Site_Importer_Site_Plan_Persistence::asset_publication_receipt( $destination, $theme_dir, $theme_uri, '' ),
			),
			'args'                              => $args,
			'payload_reader'                    => $payload_reader,
			'font_overlay'                      => isset( $prepared['font_overlay'] ) && is_array( $prepared['font_overlay'] ) ? $prepared['font_overlay'] : null,
			'viewport_overlay'                  => isset( $prepared['viewport_overlay'] ) && is_array( $prepared['viewport_overlay'] ) ? $prepared['viewport_overlay'] : null,
			'route_title_overlay'               => isset( $prepared['route_title_overlay'] ) && is_array( $prepared['route_title_overlay'] ) ? $prepared['route_title_overlay'] : null,
			'internal_link_overlay'             => isset( $prepared['internal_link_overlay'] ) && is_array( $prepared['internal_link_overlay'] ) ? $prepared['internal_link_overlay'] : null,
			'route_head_metadata_overlay'       => isset( $prepared['route_head_metadata_overlay'] ) && is_array( $prepared['route_head_metadata_overlay'] ) ? $prepared['route_head_metadata_overlay'] : null,
			'default_content'                   => isset( $prepared['default_content'] ) && is_array( $prepared['default_content'] ) ? $prepared['default_content'] : array(),
			'rollback'                          => array(
				'posts'   => array(),
				'files'   => array(),
				'options' => array(),
			),
			'preparation'                       => array(
				'canonical_validations'       => 1,
				'plan_resolutions'            => 1,
				'destination_preflights'      => 2,
				'immutable_projection_reused' => true,
			),
		);

		try {
			if ( '' !== $recheck_error ) {
				throw new InvalidArgumentException( $recheck_error );
			}
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			if ( Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $destination['mode'] ?? '' ) ) {
				// Revalidate the host theme instead of the generated theme root
				// the destination does not own.
				if ( ! is_dir( (string) $destination['theme_dir'] ) ) {
					throw new InvalidArgumentException( 'theme_destination_not_ready' );
				}
			} elseif ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Revalidates the prepared native theme destination before mutation.
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			if ( ( $prepared['theme']['dir'] ?? null ) !== $theme_dir || ( $prepared['theme']['uri'] ?? null ) !== $theme_uri ) {
				throw new InvalidArgumentException( 'prepared_destination_changed' );
			}
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			$state['quality_budget_admission'] = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $state['resolved'], $args, array(), Static_Site_Importer_Quality_Budget_Admission::applied_entity_bindings( $state ) );
			if ( Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $state['quality_budget_admission'] ) ) {
				$state['diagnostics'][]  = array(
					'reason_code'    => 'quality_budget_failed',
					'quality_budget' => $state['quality_budget_admission'],
				);
				$state['failure_reason'] = 'quality_budget_failed';
				return array(
					'status'  => 'rejected',
					'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
				);
			}
			self::validate_materialized_block_documents( $state['resolved'], $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			if ( isset( $state['preflight_error'] ) && is_wp_error( $state['preflight_error'] ) ) {
				return array(
					'status'  => 'rejected',
					'receipt' => Static_Site_Importer_Site_Plan_Receipt::rejected_receipt_from_error( $state, $state['preflight_error'] ),
				);
			}
			$state['diagnostics'][]  = array( 'reason_code' => $error->getMessage() );
			$state['failure_reason'] = $error->getMessage();
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
			);
		}

		$state['status'] = 'prepared';
		return $state;
	}

	/** @param array<string,mixed> $state */
	public static function preflight_state( array &$state, bool $overwrite, string $import_run_id = '' ): void {
		$pages_by_route      = array();
		$state['page_ids']   = array();
		$state['source_ids'] = array();
		foreach ( $state['resolved']['pages'] as &$page ) {
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC !== ( $state['args']['theme_materialization'] ?? null ) && ( ! isset( $page['resolved_block_markup'] ) || ! is_string( $page['resolved_block_markup'] ) || '' === trim( $page['resolved_block_markup'] ) ) ) {
				throw new InvalidArgumentException( 'page_missing_final_block_markup' );
			}
			$route = (string) ( $page['route']['path'] ?? '' );
			if ( isset( $pages_by_route[ $route ] ) ) {
				throw new InvalidArgumentException( 'duplicate_page_route' );
			}
			$pages_by_route[ $route ] = true;

			// The materializer is the consumer boundary: producer content_decision
			// and declared post_type win. Consumer dated-meta / dated-route
			// detection runs only when the producer defaulted the row to page.
			// Classification runs once here so the existing-match, conflict, and
			// materialize paths read one value.
			$classification                            = Static_Site_Importer_Document_Type_Classifier::classify( $page );
			$page['post_type']                         = $classification['post_type'];
			$page['metadata']['detected_date']         = $classification['date'];
			$page['metadata']['classification_signal'] = $classification['signal'];
			$existing                                  = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $page['reconciliation_identity'] );
			if ( $existing ) {
				$page = self::plan_existing_page( $state, $page, $existing, 'reconciliation_identity_match' );
				continue;
			}
			$conflict = '' === trim( $route, '/' ) ? null : get_page_by_path( trim( $route, '/' ), OBJECT, $page['post_type'] );
			if ( $conflict && ! $overwrite && ! self::post_belongs_to_run( $conflict, $import_run_id ) && ! Static_Site_Importer_Default_Content::is_untouched_seed( $state['default_content'], $conflict ) ) {
				throw new InvalidArgumentException( 'post_conflict' );
			}
			if ( $conflict ) {
				$page = self::plan_existing_page( $state, $page, $conflict, Static_Site_Importer_Default_Content::is_untouched_seed( $state['default_content'], $conflict ) ? 'default_content_seed_match' : 'canonical_route_match' );
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
		$overlay_writes = Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $state, $state['args']['provider_layout_overlays'] ?? array() );
		if ( is_wp_error( $overlay_writes ) ) {
			throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
		}
		$state['provider_layout_overlay_writes'] = $overlay_writes;
		$font_resolved                           = $state['resolved'];
		foreach ( $font_resolved['writes'] as &$write ) {
			$path = $state['theme_dir'] . '/' . (string) ( $write['target_path'] ?? '' );
			if ( isset( $overlay_writes[ $path ] ) ) {
				$write['payload']      = array(
					'encoding' => 'utf8',
					'data'     => $overlay_writes[ $path ],
				);
				$write['payload_hash'] = hash( 'sha256', $overlay_writes[ $path ] );
			}
		}
		unset( $write );
		$font_overlay = isset( $state['font_overlay'] ) && is_array( $state['font_overlay'] )
			? $state['font_overlay']
			: Static_Site_Importer_Font_Materializer::prepare_overlay(
				! empty( $state['args']['page_ready_checkpoint'] ) ? array() : ( is_array( $state['plan']['theme']['font_materialization'] ?? null ) ? $state['plan']['theme']['font_materialization'] : array() ),
				$font_resolved
			);
		if ( is_wp_error( $font_overlay ) ) {
			$state['preflight_error'] = $font_overlay;
			throw new InvalidArgumentException( sanitize_key( (string) $font_overlay->get_error_code() ) );
		}
		$viewport_overlay                     = isset( $state['viewport_overlay'] ) && is_array( $state['viewport_overlay'] )
			? $state['viewport_overlay']
			: Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( $font_resolved, $font_overlay );
		$title_bootstrap_overlay              = 'materialized' === ( $viewport_overlay['status'] ?? '' ) ? $viewport_overlay : $font_overlay;
		$route_title_overlay                  = isset( $state['route_title_overlay'] ) && is_array( $state['route_title_overlay'] )
			? $state['route_title_overlay']
			: Static_Site_Importer_Route_Document_Metadata::prepare_overlay( $font_resolved, $title_bootstrap_overlay );
		$internal_link_overlay                = isset( $state['internal_link_overlay'] ) && is_array( $state['internal_link_overlay'] )
			? $state['internal_link_overlay']
			: Static_Site_Importer_Internal_Link_Runtime::prepare_overlay( $font_resolved, $route_title_overlay );
		$head_bootstrap_overlay               = 'materialized' === ( $internal_link_overlay['status'] ?? '' )
			? $internal_link_overlay
			: ( 'materialized' === ( $route_title_overlay['status'] ?? '' ) ? $route_title_overlay : $title_bootstrap_overlay );
		$route_head_metadata_overlay          = isset( $state['route_head_metadata_overlay'] ) && is_array( $state['route_head_metadata_overlay'] )
			? $state['route_head_metadata_overlay']
			: Static_Site_Importer_Route_Head_Metadata::prepare_overlay( $font_resolved, $head_bootstrap_overlay );
		$state['font_overlay']                = $font_overlay;
		$state['viewport_overlay']            = $viewport_overlay;
		$state['internal_link_overlay']       = $internal_link_overlay;
		$state['route_title_overlay']         = $internal_link_overlay;
		$state['route_head_metadata_overlay'] = $route_head_metadata_overlay;
		$state['composed_theme_writes']       = array_merge( $overlay_writes, Static_Site_Importer_Site_Plan_Persistence::font_overlay_writes( $state['theme_dir'], $font_overlay ), Static_Site_Importer_Site_Plan_Persistence::viewport_overlay_writes( $state['theme_dir'], $viewport_overlay ), Static_Site_Importer_Site_Plan_Persistence::viewport_overlay_writes( $state['theme_dir'], $internal_link_overlay ), Static_Site_Importer_Site_Plan_Persistence::viewport_overlay_writes( $state['theme_dir'], $route_head_metadata_overlay ) );
		foreach ( $state['resolved']['writes'] as $write ) {
			if ( null !== Static_Site_Importer_Site_Plan_Persistence::payload_reference( $write ) && ! Static_Site_Importer_Site_Plan_Persistence::valid_payload_reference( Static_Site_Importer_Site_Plan_Persistence::payload_reference( $write ) ) ) {
				throw new InvalidArgumentException( 'payload_reference_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( ! Static_Site_Importer_Site_Plan_Persistence::safe_destination( $state['theme_dir'], $write['target_path'] ) ) {
				throw new InvalidArgumentException( 'unsafe_destination_path' );
			}
			if ( is_dir( $path ) || ( file_exists( $path ) && ! $overwrite && ! self::theme_belongs_to_run( $state['theme_dir'], $import_run_id ) && Static_Site_Importer_Site_Plan_Persistence::file_hash( $path ) !== Static_Site_Importer_Site_Plan_Persistence::payload_hash( $write ) && ( ! isset( $state['composed_theme_writes'][ $path ] ) || Static_Site_Importer_Site_Plan_Persistence::file_hash( $path ) !== hash( 'sha256', $state['composed_theme_writes'][ $path ] ) ) ) ) {
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

	/** Apply exact provider bindings to the resolved projection while retaining canonical plan markup. */
	public static function apply_runtime_entity_bindings( array &$plan, array $bindings, array &$reports, array &$diagnostics ): void {
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
			self::validate_runtime_entity_binding_fragment( $binding, $diagnostics );
			$index   = $pages[ $binding['source_path'] ];
			$content = (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ?? '' );
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
				'status'                           => 'prepared',
				'reconciliation_identity'          => $binding['reconciliation_identity'],
				'source_path'                      => $binding['source_path'],
				'role'                             => $binding['role'] ?? '',
				'declaration_id'                   => $binding['declaration_id'] ?? '',
				'fallback_reconciliation_identity' => $binding['fallback_reconciliation_identity'] ?? '',
				'fallback_hash'                    => $binding['fallback_hash'] ?? '',
				'materialized_block_hash'          => $binding['materialized_block_hash'] ?? '',
				'replacement_block_markup'         => $binding['replacement_block_markup'],
				'provider'                         => $binding['provider'] ?? '',
				'superseded_runtime_selectors'     => $selectors,
			);
		}
		foreach ( $reports as &$report ) {
			$index                               = $pages[ $report['source_path'] ];
			$report['materialized_content_hash'] = hash( 'sha256', (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ) );
		}
		unset( $report );
	}

	/**
	 * Reject a provider fragment unless Core can round-trip it as a complete block document.
	 *
	 * @param array<string,mixed> $binding     Runtime entity binding.
	 * @param array<int,mixed>    $diagnostics Admission diagnostics.
	 * @return void
	 */
	public static function validate_runtime_entity_binding_fragment( array $binding, array &$diagnostics ): void {
		if ( ! self::is_complete_block_document( (string) $binding['replacement_block_markup'] ) ) {
			$diagnostics[] = array(
				'reason_code'             => 'runtime_entity_binding_replacement_invalid',
				'source_path'             => $binding['source_path'],
				'reconciliation_identity' => $binding['reconciliation_identity'],
				'declaration_id'          => $binding['declaration_id'] ?? '',
				'provider'                => $binding['provider'] ?? '',
			);
			throw new InvalidArgumentException( 'runtime_entity_binding_replacement_invalid' );
		}
	}

	/**
	 * Admit each post document after runtime dependencies and bindings are ready.
	 *
	 * @param array<string,mixed> $plan        Resolved plan with runtime replacements.
	 * @param array<string,mixed> $bindings    Binding reports keyed by reconciliation identity.
	 * @param array<int,mixed>    $diagnostics Admission diagnostics.
	 * @return void
	 */
	public static function validate_materialized_block_documents( array $plan, array $bindings, array &$diagnostics ): void {
		foreach ( $plan['pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$markup        = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
			$source_path   = (string) ( $page['source_path'] ?? '' );
			$page_bindings = array_values(
				array_filter(
					$bindings,
					static fn ( $binding ): bool => is_array( $binding ) && ( $binding['source_path'] ?? '' ) === $source_path
				)
			);
			if ( ! self::is_complete_block_document( $markup ) ) {
				$diagnostic = array(
					'reason_code' => 'runtime_entity_bound_block_document_invalid',
					'source_path' => $source_path,
				);
				if ( array() !== $page_bindings ) {
					$diagnostic['binding_reconciliation_identities'] = array_values( array_filter( array_column( $page_bindings, 'reconciliation_identity' ), 'is_string' ) );
				}
				$diagnostics[] = $diagnostic;
				throw new InvalidArgumentException( 'runtime_entity_bound_block_document_invalid' );
			}
			$admission   = self::block_document_editor_admission( $markup, $source_path );
			$diagnostics = array_merge( $diagnostics, $admission['diagnostics'] );
			if ( ! $admission['admitted'] ) {
				throw new InvalidArgumentException( 'unsupported_persisted_block' );
			}
		}
	}

	/**
	 * Use WordPress's parser and serializer as the transport boundary.
	 *
	 * @param string $markup Block document markup.
	 * @return bool
	 */
	public static function is_complete_block_document( string $markup ): bool {
		$runtime = new Blocks_Engine_WordPress_Runtime();
		$blocks  = $runtime->parseBlocks( $markup );
		if ( ! self::block_document_has_only_blocks( $blocks ) ) {
			return false;
		}
		$serialized    = $runtime->serializeBlocks( $blocks );
		$round_tripped = $runtime->parseBlocks( $serialized );
		return self::block_document_has_only_blocks( $round_tripped ) && self::block_document_topology( $blocks ) === self::block_document_topology( $round_tripped );
	}

	/**
	 * Verify that the editor can load every persisted block without a name allowlist.
	 *
	 * @return array{admitted:bool,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function block_document_editor_admission( string $markup, string $source_path ): array {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			throw new InvalidArgumentException( 'block_editor_admission_unavailable' );
		}
		$registry    = WP_Block_Type_Registry::get_instance();
		$diagnostics = array();
		$unsupported = false;
		$inspect     = static function ( array $blocks, ?string $parent_name = null ) use ( &$inspect, $registry, $source_path, &$diagnostics, &$unsupported ): void {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
					continue;
				}
				$name       = $block['blockName'];
				$block_type = $registry->get_registered( $name );
				if ( ! $block_type ) {
					$unsupported = true;
					if ( self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'unsupported_persisted_block',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'block_classification' => 'unsupported',
						);
					}
				} else {
					$classification = str_starts_with( $name, 'core/' ) ? 'registered_core' : 'registered_provider';
					$owners         = $GLOBALS['static_site_importer_companion_block_owners'] ?? array();
					if ( 'registered_provider' === $classification && is_array( $owners ) && isset( $owners[ $name ] ) && is_array( $owners[ $name ] ) ) {
						$classification = 'declared_companion_dependency';
					}
					if ( is_array( $block_type->parent ) && ! in_array( $parent_name, $block_type->parent, true ) && self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'block_parent_requirement_not_met',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'parent_block_name'    => $parent_name,
							'block_classification' => $classification,
						);
					}
					$parent_type = null === $parent_name ? null : $registry->get_registered( $parent_name );
					if ( $parent_type && is_array( $parent_type->allowed_blocks ) && ! in_array( $name, $parent_type->allowed_blocks, true ) && self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'block_child_not_allowed',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'parent_block_name'    => $parent_name,
							'block_classification' => $classification,
						);
					}
				}
				$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
				$inspect( $inner_blocks, $name );
			}
		};
		$inspect( ( new Blocks_Engine_WordPress_Runtime() )->parseBlocks( $markup ) );
		return array(
			'admitted'    => ! $unsupported,
			'diagnostics' => $diagnostics,
		);
	}

	/** @param array<array-key,mixed> $blocks @return bool */
	public static function block_document_has_only_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}
			$name = $block['blockName'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					return false;
				}
				continue;
			}
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) || ! self::block_document_has_only_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<array-key,mixed> $blocks @return array<int,mixed> */
	public static function block_document_topology( array $blocks ): array {
		$topology = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
				continue;
			}
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( ! is_array( $inner_blocks ) ) {
				$inner_blocks = array();
			}
			$topology[] = array(
				'name'  => $block['blockName'],
				'inner' => self::block_document_topology( $inner_blocks ),
			);
		}
		return $topology;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $page */
	public static function plan_existing_page( array &$state, array $page, WP_Post $existing, string $reason ): array {
		$id                          = (int) $existing->ID;
		$protected                   = Static_Site_Importer_Protected_Page_Policy::is_protected_page( $existing );
		$page['planned_existing_id'] = $id;
		$state['page_ids'][ $page['reconciliation_identity'] ] = $id;
		$state['source_ids'][ $page['source_path'] ]           = $id;
		$row                                  = array(
			'post_id'     => $id,
			'source_path' => $page['source_path'],
			'route'       => $page['route']['path'],
			// Host-free: the manifest ships inside the theme and must not name the
			// host that built it.
			'permalink'   => function_exists( 'get_permalink' ) ? wp_make_link_relative( (string) get_permalink( $existing ) ) : $page['route']['path'],
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

	public static function existing_source_page_id( string $source_path, string $import_run_id ): int {
		if ( '' === $import_run_id ) {
			return 0; }
		$posts = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'meta_key'    => '_static_site_importer_provenance',
				'numberposts' => -1,
			)
		);
		foreach ( $posts as $post ) {
			$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
			if ( is_array( $provenance ) && ( $provenance['source_path'] ?? '' ) === $source_path && ( $provenance['import_run_id'] ?? '' ) === $import_run_id ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	public static function post_belongs_to_run( WP_Post $post, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
		return is_array( $provenance ) && ( $provenance['import_run_id'] ?? '' ) === $import_run_id;
	}

	/** @param array<int,array<string,mixed>> $pages */
	public static function page_exists_in_plan( array $pages, string $identity ): bool {
		foreach ( $pages as $page ) {
			if ( $page['reconciliation_identity'] === $identity ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>>|null */
	public static function parent_ordered_pages( array $pages, string $import_run_id = '' ): ?array {
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

	public static function theme_belongs_to_run( string $theme_dir, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$manifest = $theme_dir . '/static-site-importer-manifest.json';
		$data     = is_file( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned run manifest for batch reconciliation.
		return is_array( $data ) && ( $data['import_run_id'] ?? '' ) === $import_run_id;
	}

	/**
	 * Admit every reference-backed write before materialization starts.
	 *
	 * Each payload is read and discarded independently so an unavailable or
	 * corrupted later resource cannot leave earlier pages or files behind.
	 * write_payload_bytes() repeats the verification immediately before a write
	 * to keep the write boundary safe if workspace contents change meanwhile.
	 *
	 * @param array<int,array<string,mixed>> $writes
	 */
	public static function verify_payload_references( array $writes, ?object $payload_reader ) {
		foreach ( $writes as $write ) {
			if ( null === Static_Site_Importer_Site_Plan_Persistence::payload_reference( $write ) ) {
				continue;
			}
			$bytes = Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $write, $payload_reader );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	public static function discover_default_content( array &$args ): array {
		$requested                      = isset( $args['remove_default_content'] ) ? (bool) $args['remove_default_content'] : true;
		$args['remove_default_content'] = (bool) apply_filters( 'static_site_importer_remove_default_content', $requested, $args );
		return $args['remove_default_content'] ? Static_Site_Importer_Default_Content::discover() : array(
			'eligible' => false,
			'posts'    => array(),
			'comments' => array(),
		);
	}

	/**
	 * Admit producer-owned editability evidence without recreating its metrics or policy.
	 *
	 * Current Blocks Engine plans carry the required policy but not the report binding.
	 * That compatibility path is explicit and can be retired by setting
	 * quality.editability_report_required on plans from upgraded producers.
	 *
	 * @param array<string,mixed> $plan
	 * @return array<string,mixed>
	 */
	public static function editability_report_admission( array $plan ): array {
		$quality = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$policy  = isset( $quality['editability_policy'] ) && is_array( $quality['editability_policy'] ) ? $quality['editability_policy'] : array();
		$base    = array(
			'schema'        => 'static-site-importer/editability-report-admission/v1',
			'owning_layer'  => 'blocks-engine',
			'policy_schema' => (string) ( $policy['schema'] ?? '' ),
		);
		if ( array() !== $policy && ( 'blocks-engine/php-transformer/editability-policy/v1' !== ( $policy['schema'] ?? null ) || 'required' !== ( $policy['enforcement'] ?? null ) || ! in_array( $policy['status'] ?? null, array( 'passed', 'failed' ), true ) || ! isset( $policy['failures'] ) || ! is_array( $policy['failures'] ) ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_policy_invalid' );
		}
		if ( 'failed' === ( $policy['status'] ?? null ) ) {
			$failures = array_values( array_filter( $policy['failures'], 'is_array' ) );
			return array_merge(
				$base,
				array(
					'status'     => 'failed',
					'diagnostic' => array(
						// The producer already measured and phrased every breach; state it as a
						// failing diagnostic so the public projection can carry it to the reader.
						'code'                    => 'editability_policy_failed',
						'severity'                => 'error',
						'reason_code'             => 'editability_policy_failed',
						'owning_layer'            => 'blocks-engine',
						'source_path'             => (string) ( $failures[0]['source_path'] ?? '' ),
						'detail'                  => (string) ( $failures[0]['message'] ?? '' ),
						'threshold_failure_count' => count( $failures ),
						'threshold_failures'      => array_slice( $failures, 0, 10 ),
					),
				)
			);
		}

		$report = $quality['editability_report'] ?? null;
		if ( null === $report ) {
			if ( ! empty( $quality['editability_report_required'] ) ) {
				return self::rejected_editability_report_admission( $base, 'editability_report_required' );
			}
			return array_merge(
				$base,
				array(
					'status'     => 'compatibility_policy_only',
					'diagnostic' => array(
						'reason_code'  => 'editability_report_compatibility_policy_only',
						'owning_layer' => 'blocks-engine',
					),
				)
			);
		}
		if ( ! is_array( $report ) || 'blocks-engine/php-transformer/editability-report/v2' !== ( $report['schema'] ?? null ) || ! is_array( $report['metrics'] ?? null ) || ! is_array( $report['block_types'] ?? null ) || ! is_array( $report['signals'] ?? null ) || ! is_array( $report['signal_totals'] ?? null ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_schema_invalid' );
		}
		$bound_hash = $quality['editability_report_plan_hash'] ?? null;
		if ( ! is_string( $bound_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $bound_hash ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_plan_hash_invalid' );
		}
		$unbound_plan = $plan;
		unset( $unbound_plan['plan_identity'], $unbound_plan['quality']['editability_report'], $unbound_plan['quality']['editability_report_plan_hash'], $unbound_plan['quality']['editability_report_required'] );
		$expected_hash = WordPressSitePlan::planIdentity( $unbound_plan )['hash'];
		if ( ! hash_equals( $expected_hash, $bound_hash ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_plan_hash_mismatch' );
		}
		return array_merge(
			$base,
			array(
				'status'        => 'passed',
				'report_schema' => $report['schema'],
				'plan_hash'     => $bound_hash,
				'diagnostic'    => array(
					'reason_code'  => 'editability_report_verified',
					'owning_layer' => 'blocks-engine',
				),
			)
		);
	}

	/** @param array<string,mixed> $base @param array<int,mixed> $failures @return array<string,mixed> */
	public static function rejected_editability_report_admission( array $base, string $reason_code, array $failures = array() ): array {
		$diagnostic = array(
			'reason_code'  => $reason_code,
			'owning_layer' => 'blocks-engine',
		);
		if ( array() !== $failures ) {
			$diagnostic['threshold_failures'] = array_slice( array_values( array_filter( $failures, 'is_array' ) ), 0, 10 );
		}
		return array_merge(
			$base,
			array(
				'status'     => 'rejected',
				'diagnostic' => $diagnostic,
			)
		);
	}

	/** @param array<string,mixed> $plan */
	public static function hash( array $plan ): string {
		$context = hash_init( 'sha256' );
		self::hash_json_value( $context, $plan );
		return hash_final( $context );
	}

	/** @return array{schema:string,hash:string}|array{} */
	public static function plan_identity( array $plan ): array {
		$identity = $plan['plan_identity'] ?? null;
		if ( ! is_array( $identity ) || 'blocks-engine/wordpress-site-plan-identity/v1' !== ( $identity['schema'] ?? null ) || ! is_string( $identity['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity['hash'] ) ) {
			return array();
		}
		return array(
			'schema' => $identity['schema'],
			'hash'   => $identity['hash'],
		);
	}

	/** Hash the resolved projection only for prepare-to-write change detection. */
	public static function prepared_resolved_projection_hash( array $projection ): string {
		return self::hash( $projection );
	}

	/** Stream the exact JSON token sequence previously passed to hash(). */
	public static function hash_json_value( $context, mixed $value ): void {
		if ( ! is_array( $value ) ) {
			hash_update( $context, (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( self::json_list( $value ) ) {
			hash_update( $context, '[' );
			foreach ( $value as $index => $item ) {
				if ( 0 !== $index ) {
					hash_update( $context, ',' );
				}
				self::hash_json_value( $context, $item );
			}
			hash_update( $context, ']' );
			return;
		}
		hash_update( $context, '{' );
		$first = true;
		foreach ( $value as $key => $item ) {
			if ( ! $first ) {
				hash_update( $context, ',' );
			}
			$first = false;
			hash_update( $context, (string) wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES ) );
			hash_update( $context, ':' );
			self::hash_json_value( $context, $item );
		}
		hash_update( $context, '}' );
	}

	/** Match PHP's array-to-JSON list detection without materializing JSON. */
	public static function json_list( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}
		return true;
	}
}
