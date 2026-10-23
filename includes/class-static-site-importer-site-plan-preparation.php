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

require_once __DIR__ . '/class-static-site-importer-theme-screenshot.php';
if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

/** Validates, resolves, and admits a plan before persistence. */
final class Static_Site_Importer_Site_Plan_Preparation {
	private const BLOCK_PROVENANCE_LIMIT = 50;

	/**
	 * The producer's generic canonical style.css scaffold placeholder.
	 *
	 * The consumer replaces exactly this payload with the resolved site
	 * identity; any other scaffold payload is producer-owned and kept.
	 */
	private const PRODUCER_GENERIC_SCAFFOLD_STYLE_CSS = "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n";

	/**
	 * Resolve the consumer-owned theme identity once for one materialization.
	 *
	 * Priority follows the site-identity primitive: explicit name, site title,
	 * the plan's entrypoint document title, then the defaults. The destination
	 * slug stays authoritative for the slug so the text domain and theme
	 * directory agree by construction.
	 *
	 * @param array<string,mixed> $args Materialization args.
	 * @param array<string,mixed> $plan Canonical plan.
	 * @param string              $slug Destination theme slug.
	 * @return array{name:string,slug:string,title:string,block_namespace:string}
	 */
	private static function resolve_theme_identity( array $args, array $plan, string $slug ): array {
		return Static_Site_Importer_Site_Identity::resolve(
			array(
				'name'       => isset( $args['name'] ) && is_scalar( $args['name'] ) ? (string) $args['name'] : '',
				'site_title' => isset( $args['site_title'] ) && is_scalar( $args['site_title'] ) ? (string) $args['site_title'] : '',
				'slug'       => $slug,
				'plan'       => $plan,
			)
		);
	}

	/**
	 * Replace the producer's generic scaffold header with the resolved identity.
	 *
	 * Only the canonical style.css placeholder is rewritten, byte-for-byte
	 * recognizable, so a producer that already supplies a real header keeps its
	 * payload untouched. The composed header follows the stylesheet materializer
	 * header contract, and payload hashes are recomputed so preflight conflict
	 * detection, reconciliation, and rollback keep operating on the real bytes.
	 *
	 * @param array<string,mixed> $resolved Resolved plan projection.
	 * @param array<string,mixed> $identity Resolved consumer identity.
	 * @param array<string,mixed> $args     Materialization args.
	 * @return array<string,mixed>
	 */
	private static function with_consumer_theme_scaffold( array $resolved, array $identity, array $args ): array {
		if ( ! isset( $resolved['writes'] ) || ! is_array( $resolved['writes'] ) ) {
			return $resolved;
		}
		foreach ( $resolved['writes'] as &$write ) {
			if ( ! is_array( $write ) || 'theme_scaffold' !== ( $write['kind'] ?? '' ) || 'style.css' !== ( $write['target_path'] ?? '' ) ) {
				continue;
			}
			if ( 'utf8' !== ( $write['payload']['encoding'] ?? '' ) || self::PRODUCER_GENERIC_SCAFFOLD_STYLE_CSS !== (string) ( $write['payload']['data'] ?? '' ) ) {
				continue;
			}
			$composed              = Static_Site_Importer_Stylesheet_Materializer::scaffold_style_css(
				(string) $identity['name'],
				(string) $identity['slug'],
				isset( $args['artifact_provenance'] ) && is_array( $args['artifact_provenance'] ) ? $args['artifact_provenance'] : array()
			);
			$write['payload']      = array(
				'encoding' => 'utf8',
				'data'     => $composed,
			);
			$write['payload_hash'] = hash( 'sha256', $composed );
			break;
		}
		unset( $write );
		return $resolved;
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

		// Blocks Engine owns the editability policy: honour its decision once.
		$state['editability_report'] = self::editability_decision( $plan );
		$state['design_system']      = self::design_system( $plan, is_array( $args['source_reports']['editability_report'] ?? null ) ? $args['source_reports']['editability_report'] : array() );
		if ( 'failed' === $state['editability_report']['status'] ) {
			$state['diagnostics'][]  = $state['editability_report']['diagnostic'];
			$state['failure_reason'] = 'editability_policy_failed';
			return array(
				'status'  => 'rejected',
				'receipt' => Static_Site_Importer_Site_Plan_Receipt::receipt( 'rejected', $state ),
			);
		}

		try {
			$destination = Static_Site_Importer_Import_Destination::normalize( $args );
			if ( is_wp_error( $destination ) ) {
				// ssi-error-class: propagated
				throw new InvalidArgumentException( (string) $destination->get_error_code() );
			}
			$slug = (string) $destination['slug'];
			// Writes resolve against the destination's asset surface: the theme
			// directory it owns, or the companion publication home it borrows.
			$theme_dir = (string) ( $destination['asset_dir'] ?? $destination['theme_dir'] );
			$theme_uri = (string) ( $destination['asset_uri'] ?? $destination['theme_uri'] );
			// One consumer-owned identity, resolved once: generated block and
			// classic scaffolds carry this name instead of the producer's
			// generic placeholder header.
			$identity = self::resolve_theme_identity( $args, $plan, $slug );
			// Trust boundary: this is where a plan enters SSI materialization,
			// whether Blocks Engine composed it in this request or it was read
			// back from a lifecycle checkpoint, a retained run workspace, or a
			// public materialize() caller. The Blocks Engine resolver is the one
			// validation of that plan: it asserts the canonical plan contract
			// (including the editability policy shape), rejects a stale or
			// forged plan_identity, and anchors head assets and entity bindings
			// while binding asset tokens to this destination. SSI does not
			// repeat those checks; a rejection propagates as its reason code.
			$resolved = ( new WordPressSitePlanResolver() )->resolve(
				$plan,
				array(
					'theme_uri'                            => $theme_uri,
					'require_proven_dynamic_client_assets' => $args['require_proven_dynamic_client_assets'] ?? true,
					'runtime_capabilities'                 => array( 'asset_materialization' ),
				)
			);
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] ) {
				$projection = Static_Site_Importer_Classic_Theme_Projection::prepare_for_materialization( $args['classic_theme_projection'], $resolved );
				if ( is_wp_error( $projection ) ) {
					// ssi-error-class: propagated
					throw new InvalidArgumentException( (string) $projection->get_error_code() );
				}
				$args['classic_theme_projection'] = $projection;
				$resolved['writes']               = Static_Site_Importer_Classic_Theme_Projection::resolved_writes( $resolved, Static_Site_Importer_Classic_Theme_Projection::writes( $args['classic_theme_projection'], $resolved, $theme_uri, $identity['name'], isset( $args['artifact_provenance'] ) && is_array( $args['artifact_provenance'] ) ? $args['artifact_provenance'] : array() ) );
				foreach ( $resolved['pages'] as &$page ) {
					$page['resolved_block_markup'] = '';
				}
				unset( $page );
			}
			$resolved                                   = Static_Site_Importer_Theme_Screenshot::with_write( $resolved, $args );
			$resolved                                   = self::with_consumer_theme_scaffold( $resolved, $identity, $args );
			$state['base_resolved']                     = $resolved;
			$state['prepared_resolved_projection_hash'] = self::prepared_resolved_projection_hash( $resolved );
			$state['resolved']                          = $resolved;
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			$state['theme_dir']                = $theme_dir;
			$state['destination']              = $destination;
			$state['theme']                    = array(
				'name'              => $identity['name'],
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
			'design_system'                     => $prepared['design_system'] ?? array(),
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
				'name'              => (string) ( $prepared['theme']['name'] ?? '' ) !== '' ? (string) $prepared['theme']['name'] : (string) ( $args['name'] ?? '' ),
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
				// ssi-error-class: propagated
				throw new InvalidArgumentException( $recheck_error );
			}
			if ( '' === $slug ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			if ( Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $destination['mode'] ?? '' ) ) {
				// Revalidate the host theme instead of the generated theme root
				// the destination does not own.
				if ( ! is_dir( (string) $destination['theme_dir'] ) ) {
					// ssi-error-class: operational
					throw new InvalidArgumentException( 'theme_destination_not_ready' );
				}
			} elseif ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Revalidates the prepared native theme destination before mutation.
				// ssi-error-class: operational
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			if ( ( $prepared['theme']['dir'] ?? null ) !== $theme_dir || ( $prepared['theme']['uri'] ?? null ) !== $theme_uri ) {
				// ssi-error-class: integrity
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
			self::mark_taxonomy_archive_pages( $state );
			$pending_blocks = array();
			if ( is_array( $args['theme_runtime_payload'] ?? null ) ) {
				require_once __DIR__ . '/class-static-site-importer-generated-runtime-package.php';
				$package = Static_Site_Importer_Generated_Runtime_Package::theme( $args['theme_runtime_payload'], (string) $state['theme']['slug'] );
				if ( is_wp_error( $package ) ) {
					$state['preflight_error'] = $package;
					// ssi-error-class: propagated
					throw new InvalidArgumentException( (string) $package->get_error_code() );
				}
				$pending_blocks = $package['block_names'];
			}
			// Only validated destination declarations may await installation. Persistence
			// repeats admission against the real registry before writing any documents.
			self::validate_materialized_block_documents( $state['resolved'], $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'], $pending_blocks );
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

	/** @param array $state */
	public static function preflight_state( array &$state, bool $overwrite, string $import_run_id = '' ): void {
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC !== ( $state['args']['theme_materialization'] ?? null ) ) {
			require_once __DIR__ . '/class-static-site-importer-navigation-entity-materializer.php';
			require_once __DIR__ . '/class-static-site-importer-synced-pattern-materializer.php';
			$entity_error = Static_Site_Importer_Navigation_Entity_Materializer::preflight( $state['resolved'] ) ?? Static_Site_Importer_Synced_Pattern_Materializer::preflight( $state['resolved'] );
			if ( $entity_error ) {
				// ssi-error-class: propagated
				throw new InvalidArgumentException( esc_html( (string) $entity_error->get_error_code() ) );
			}
		}
		$pages_by_route      = array();
		$state['page_ids']   = array();
		$state['source_ids'] = array();
		self::mark_taxonomy_archive_pages( $state );
		self::plan_synthetic_hierarchy_parents( $state );
		foreach ( $state['resolved']['pages'] as &$page ) {
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC !== ( $state['args']['theme_materialization'] ?? null ) && ( ! isset( $page['resolved_block_markup'] ) || ! is_string( $page['resolved_block_markup'] ) || '' === trim( $page['resolved_block_markup'] ) ) ) {
				// ssi-error-class: representability:page
				throw new InvalidArgumentException( 'page_missing_final_block_markup' );
			}
			$route = (string) ( $page['route']['path'] ?? '' );
			if ( isset( $pages_by_route[ $route ] ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'duplicate_page_route' );
			}
			$pages_by_route[ $route ] = true;
			if ( ! empty( $page['skip_materialization'] ) ) {
				$archive_match    = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( (string) $page['reconciliation_identity'] );
				$archive_conflict = '' === trim( $route, '/' ) ? null : get_page_by_path( trim( $route, '/' ), OBJECT, 'page' );
				if ( $archive_conflict && ( ! $archive_match || (int) $archive_match->ID !== (int) $archive_conflict->ID ) && ! Static_Site_Importer_Default_Content::is_untouched_seed( $state['default_content'], $archive_conflict ) ) {
					// ssi-error-class: integrity
					throw new InvalidArgumentException( 'taxonomy_archive_route_conflict' );
				}
				continue;
			}

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
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'post_conflict' );
			}
			if ( $conflict ) {
				$page = self::plan_existing_page( $state, $page, $conflict, Static_Site_Importer_Default_Content::is_untouched_seed( $state['default_content'], $conflict ) ? 'default_content_seed_match' : 'canonical_route_match' );
			}
		}
		unset( $page );
		$state['ordered_pages'] = self::parent_ordered_pages( $state['resolved']['pages'], $import_run_id );
		if ( null === $state['ordered_pages'] ) {
			// ssi-error-class: representability:page
			throw new InvalidArgumentException( 'invalid_page_parent_identity' );
		}
		foreach ( $state['resolved']['operations'] as $operation ) {
			if ( 'create_page' === $operation['kind'] ) {
				continue;
			}
			if ( 'site_reading' !== $operation['kind'] || ( ! isset( $state['page_ids'][ $operation['front_page_reconciliation_identity'] ] ) && ! self::page_exists_in_plan( $state['resolved']['pages'], $operation['front_page_reconciliation_identity'] ) ) ) {
				// ssi-error-class: programmer
				throw new InvalidArgumentException( 'unsupported_operation' );
			}
		}
		$overlay_writes = Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $state, $state['args']['provider_layout_overlays'] ?? array() );
		if ( is_wp_error( $overlay_writes ) ) {
			// ssi-error-class: integrity
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
			// ssi-error-class: propagated
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
			: ( is_array( $state['args']['theme_runtime_payload'] ?? null )
				? array(
					'status' => 'skipped',
					'reason' => 'theme_runtime_package_owns_internal_links',
					'writes' => array(),
				)
				: Static_Site_Importer_Internal_Link_Runtime::prepare_overlay( $font_resolved, $route_title_overlay, (string) ( $state['args']['slug'] ?? '' ) ) );
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
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'payload_reference_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( ! Static_Site_Importer_Site_Plan_Persistence::safe_destination( $state['theme_dir'], $write['target_path'] ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'unsafe_destination_path' );
			}
			if ( is_dir( $path ) || ( file_exists( $path ) && ! $overwrite && ! self::theme_belongs_to_run( $state['theme_dir'], $import_run_id ) && Static_Site_Importer_Site_Plan_Persistence::file_hash( $path ) !== Static_Site_Importer_Site_Plan_Persistence::payload_hash( $write ) && ( ! isset( $state['composed_theme_writes'][ $path ] ) || Static_Site_Importer_Site_Plan_Persistence::file_hash( $path ) !== hash( 'sha256', $state['composed_theme_writes'][ $path ] ) ) ) ) {
				if ( isset( $overlay_writes[ $path ] ) ) {
					// ssi-error-class: integrity
					throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
				}
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'file_conflict' );
			}
		}
		foreach ( $state['report_destinations'] ?? array() as $path ) {
			$parent = is_string( $path ) ? dirname( $path ) : '';
			while ( '' !== $parent && ! file_exists( $parent ) ) {
				$parent = dirname( $parent );
			}
			if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || '' === $parent || is_link( $parent ) || ! is_dir( $parent ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights native report destinations used by atomic local writes.
				// ssi-error-class: operational
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
		foreach ( $state['external_report_destinations'] ?? array() as $path ) {
			if ( ! self::safe_external_report_destination( $path ) ) {
				// ssi-error-class: operational
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
	}

	/** Exclude producer-owned taxonomy archive source documents from page writes and page validation. */
	private static function mark_taxonomy_archive_pages( array &$state ): void {
		$archives = array();
		foreach ( $state['resolved']['taxonomy_entities'] ?? array() as $entity ) {
			if ( is_array( $entity ) && is_array( $entity['archive'] ?? null ) && is_string( $entity['archive']['source_path'] ?? null ) ) {
				$archives[ $entity['archive']['source_path'] ] = (string) ( $entity['archive']['source_route'] ?? '' );
			}
		}
		foreach ( $state['resolved']['pages'] as &$page ) {
			$source_path = (string) ( $page['source_path'] ?? '' );
			if ( ! isset( $archives[ $source_path ] ) || ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			if ( ( $page['route']['path'] ?? null ) !== $archives[ $source_path ] ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'taxonomy_archive_route_mismatch' );
			}
			$archive_match = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( (string) ( $page['reconciliation_identity'] ?? '' ) );
			$conflict      = get_page_by_path( trim( $archives[ $source_path ], '/' ), OBJECT, 'page' );
			if ( $conflict && ( ! $archive_match || (int) $archive_match->ID !== (int) $conflict->ID ) && ! Static_Site_Importer_Default_Content::is_untouched_seed( $state['default_content'], $conflict ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'taxonomy_archive_route_conflict' );
			}
			if ( $archive_match ) {
				$page['planned_existing_id'] = (int) $archive_match->ID;
				$page['retire_archive_page'] = true;
			}
			$page['skip_materialization'] = true;
			$state['skipped'][]           = array(
				'source_path' => $source_path,
				'route'       => $archives[ $source_path ],
				'reason'      => 'native_taxonomy_archive',
			);
		}
		unset( $page );
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

	/**
	 * Index every persisted block document a runtime entity binding may target:
	 * page/post entities, generated templates, shared parts, and synced patterns.
	 *
	 * @param array<string,mixed> $plan Resolved plan.
	 * @return array<string,array{group:string,index:int|string}>
	 */
	public static function runtime_binding_documents( array $plan ): array {
		$documents = array();
		foreach ( array( 'pages', 'templates', 'template_parts', 'synced_patterns' ) as $group ) {
			foreach ( is_array( $plan[ $group ] ?? null ) ? $plan[ $group ] : array() as $index => $document ) {
				if ( is_array( $document ) && is_string( $document['source_path'] ?? null ) && ! isset( $documents[ $document['source_path'] ] ) ) {
					$documents[ $document['source_path'] ] = array(
						'group' => $group,
						'index' => $index,
					);
				}
			}
		}
		return $documents;
	}

	/** Apply exact provider bindings to the resolved projection while retaining canonical plan markup. */
	public static function apply_runtime_entity_bindings( array &$plan, array $bindings, array &$reports, array &$diagnostics ): void {
		$documents = self::runtime_binding_documents( $plan );
		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) ) {
				// ssi-error-class: representability:block
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
		$seen          = array();
		$part_patches  = array();
		$page_patches  = array();
		$archive_pages = array();
		foreach ( $bindings as $binding ) {
			$selectors = $binding['superseded_runtime_selectors'] ?? array();
			if ( ! is_array( $binding ) || 'static-site-importer/runtime-entity-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! isset( $documents[ $binding['source_path'] ] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || ! is_string( $binding['replacement_block_markup'] ?? null ) || '' === trim( $binding['replacement_block_markup'] ) || ! is_string( $binding['reconciliation_identity'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding['reconciliation_identity'] ) || isset( $seen[ $binding['reconciliation_identity'] ] ) || ! is_array( $selectors ) ) {
				// ssi-error-class: representability:block
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
			if ( array_key_exists( 'archive_replacement_block_markup', $binding ) && ( ! is_string( $binding['archive_replacement_block_markup'] ) || '' === trim( $binding['archive_replacement_block_markup'] ) ) ) {
				// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- Machine-readable error classification.
				// ssi-error-class: representability:block
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
			$selectors = array_values( array_unique( $selectors ) );
			foreach ( $selectors as $selector ) {
				if ( ! is_string( $selector ) || '' === trim( $selector ) || strlen( $selector ) > 1024 ) {
					// ssi-error-class: representability:block
					throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
				}
			}
			$seen[ $binding['reconciliation_identity'] ] = true;
			self::validate_runtime_entity_binding_fragment( $binding, $diagnostics );
			$group    = $documents[ $binding['source_path'] ]['group'];
			$index    = $documents[ $binding['source_path'] ]['index'];
			$pristine = (string) ( $plan[ $group ][ $index ]['resolved_block_markup'] ?? '' );
			$content  = (string) ( $plan[ $group ][ $index ]['materialized_block_markup'] ?? $pristine );
			$position = self::binding_occurrence_offset( $content, $binding['search_block_markup'], $binding['occurrence'] );
			if ( null === $position ) {
				// ssi-error-class: representability:block
				throw new InvalidArgumentException( 'runtime_entity_binding_cardinality_mismatch' );
			}
			$patch = array(
				'offset'      => self::binding_occurrence_offset( $pristine, $binding['search_block_markup'], $binding['occurrence'] ),
				'search'      => $binding['search_block_markup'],
				'replacement' => $binding['replacement_block_markup'],
			);
			if ( in_array( $group, array( 'templates', 'template_parts' ), true ) ) {
				$part_patches[ $binding['source_path'] ][] = $patch;
			} else {
				$patch['replacement']                      = $binding['archive_replacement_block_markup'] ?? $patch['replacement'];
				$page_patches[ $binding['source_path'] ][] = $patch;
			}
			if ( 'pages' === $group && 'commerce_listing' === ( $binding['role'] ?? '' ) ) {
				$archive_pages[ $binding['source_path'] ] = true;
			}
			$plan[ $group ][ $index ]['materialized_block_markup'] = substr( $content, 0, $position ) . $binding['replacement_block_markup'] . substr( $content, $position + strlen( $binding['search_block_markup'] ) );
			$reports[ $binding['reconciliation_identity'] ]        = array(
				'status'                           => 'prepared',
				'reconciliation_identity'          => $binding['reconciliation_identity'],
				'source_path'                      => $binding['source_path'],
				'selector'                         => (string) ( $binding['selector'] ?? '' ),
				'role'                             => $binding['role'] ?? '',
				'declaration_id'                   => $binding['declaration_id'] ?? '',
				'fallback_reconciliation_identity' => $binding['fallback_reconciliation_identity'] ?? '',
				'fallback_hash'                    => $binding['fallback_hash'] ?? '',
				'materialized_block_hash'          => $binding['materialized_block_hash'] ?? '',
				'replacement_block_markup'         => $binding['replacement_block_markup'],
				'provider'                         => $binding['provider'] ?? '',
				'superseded_runtime_selectors'     => $selectors,
				'replaced_fallback_identities'     => array_values( array_filter( is_array( $binding['replaced_fallback_identities'] ?? null ) ? $binding['replaced_fallback_identities'] : array(), static fn( $identity ): bool => is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) ),
			);
		}
		foreach ( $part_patches as $source_path => $patches ) {
			self::apply_template_part_binding_patches( $plan, $documents[ $source_path ]['index'], $patches, $documents[ $source_path ]['group'] );
		}
		foreach ( array_keys( $archive_pages ) as $source_path ) {
			self::write_product_archive_template( $plan, $documents[ $source_path ]['index'], $page_patches[ $source_path ] );
		}
		foreach ( $reports as &$report ) {
			$document                            = $documents[ $report['source_path'] ];
			$report['materialized_content_hash'] = hash( 'sha256', (string) ( $plan[ $document['group'] ][ $document['index'] ]['materialized_block_markup'] ?? $plan[ $document['group'] ][ $document['index'] ]['resolved_block_markup'] ) );
		}
		unset( $report );
		self::reconcile_variant_form_bindings( $plan, $documents, $reports );
	}

	/**
	 * Reconcile only equivalent forms on routes absorbed by this canonical surface.
	 * Keep exact source identities on the binding so completion still requires its
	 * persisted fragment; neither an unrelated route nor a different form qualifies.
	 *
	 * @param array<string,mixed> $plan      Resolved plan.
	 * @param array<string,array> $documents Binding document index.
	 * @param array<string,array> $reports   Prepared binding reports.
	 */
	private static function reconcile_variant_form_bindings( array $plan, array $documents, array &$reports ): void {
		$fallbacks = $plan['quality']['fallbacks'] ?? array();
		foreach ( $reports as &$report ) {
			if ( 'form' !== ( $report['role'] ?? '' ) ) {
				continue;
			}
			$location = $documents[ $report['source_path'] ];
			$surface  = $plan[ $location['group'] ][ $location['index'] ];
			$variants = $surface['template_surface']['source_variants'] ?? $surface['source_variants'] ?? $surface['metadata']['source_variants'] ?? array();
			$paths    = array_column( $variants, 'source_path' );
			$matches  = array_values( array_filter( $fallbacks, static fn( $fallback ): bool => is_array( $fallback ) && ( $fallback['source_path'] ?? '' ) === $report['source_path'] && Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback ) === $report['fallback_reconciliation_identity'] ) );
			if ( 1 !== count( $matches ) ) {
				continue;
			}
			$canonical = $matches[0];
			if ( '' === (string) ( $canonical['selector'] ?? '' ) || Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $canonical ) !== $report['fallback_hash'] ) {
				continue;
			}
			foreach ( $fallbacks as $fallback ) {
				if ( ! is_array( $fallback ) || ! in_array( $fallback['source_path'] ?? '', $paths, true ) || ( $fallback['source_path'] ?? '' ) === $report['source_path'] || ( $fallback['selector'] ?? null ) !== $canonical['selector'] || Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $fallback ) !== $report['fallback_hash'] ) {
					continue;
				}
				$report['replaced_fallback_identities'][] = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback );
			}
			$report['replaced_fallback_identities'] = array_values( array_unique( $report['replaced_fallback_identities'] ) );
		}
		unset( $report );
	}

	/** Resolve native hierarchy status for synthetic parents from typed route and alias intent. */
	private static function plan_synthetic_hierarchy_parents( array &$state ): void {
		$synthetic = array();
		foreach ( $state['plan']['routes'] ?? array() as $route ) {
			if ( 'synthetic_parent' === ( $route['source_relation'] ?? '' ) ) {
				$synthetic[ $route['source_path'] ] = true;
			}
		}
		if ( array() === $synthetic ) {
			return;
		}
		$rules   = $state['args']['source_route_aliases'] ?? array();
		$targets = array();
		if ( ! empty( $rules ) ) {
			require_once __DIR__ . '/class-static-site-importer-redirects-manifest.php';
			require_once __DIR__ . '/class-static-site-importer-redirection-materializer.php';
			$aliases = Static_Site_Importer_Redirects_Manifest::aliases_for_source_paths( $rules, array_column( $state['resolved']['pages'], 'source_path' ) );
			foreach ( $aliases as $source => $paths ) {
				foreach ( $paths as $path ) {
					$targets[ '/' . ltrim( $path, '/' ) ] = $source;
				}
			}
		}
		foreach ( $state['resolved']['pages'] as &$page ) {
			$path = (string) ( $page['route']['path'] ?? '' );
			if ( empty( $page['synthetic'] ) || ! isset( $synthetic[ $page['source_path'] ] ) ) {
				continue;
			}
			if ( ! isset( $targets[ $path ] ) || $targets[ $path ] === $page['source_path'] ) {
				// No source document backs this parent: the source serves nothing at
				// its URL, so it stays an editable draft unless the owner took it over.
				$page['materialized_post_status'] = self::synthetic_parent_status( $page, $path );
				continue;
			}
			$occupied = Static_Site_Importer_Redirection_Materializer::published_route_owner( $path );
			$owned    = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $page['reconciliation_identity'] );
			if ( $occupied && ( ! $owned || (int) $occupied->ID !== (int) $owned->ID ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'static_site_importer_redirect_route_occupied' );
			}
			// A draft is still an editable native parent and contributes its slug to
			// published descendants, but the exact parent URL belongs to the alias.
			$page['materialized_post_status'] = 'draft';
			$page['alias_target_source_path'] = $targets[ $path ];
		}
		unset( $page );
	}

	/** Draft, unless the owner has since published or edited the parent, or owns its route. */
	private static function synthetic_parent_status( array $page, string $path ): string {
		$owned = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( (string) $page['reconciliation_identity'] );
		if ( ! $owned ) {
			$conflict = '' === trim( $path, '/' ) ? null : get_page_by_path( trim( $path, '/' ), OBJECT, 'page' );
			return $conflict && 'trash' !== $conflict->post_status ? (string) $conflict->post_status : 'draft';
		}
		if ( 'draft' === $owned->post_status ) {
			return 'draft';
		}
		$provenance = json_decode( (string) get_post_meta( $owned->ID, '_static_site_importer_provenance', true ), true );
		$provenance = is_array( $provenance ) ? $provenance : array();
		if ( isset( $provenance['synthetic_parent_status'] ) ) {
			// The importer recorded its own status, so any other one is the owner's.
			return (string) $owned->post_status;
		}
		// An earlier import published it; keep that only once the owner edited it.
		return hash( 'sha256', $owned->post_content ) === ( $provenance['content_hash'] ?? null ) ? 'draft' : (string) $owned->post_status;
	}

	/** Byte offset of the nth occurrence of a search string, or null when it has fewer. */
	private static function binding_occurrence_offset( string $content, string $search, int $occurrence ): ?int {
		$position = 0;
		for ( $found = 0; $found < $occurrence; ++$found ) {
			$position = strpos( $content, $search, $position );
			if ( false === $position ) {
				return null;
			}
			if ( $found + 1 < $occurrence ) {
				$position += strlen( $search );
			}
		}
		return $position;
	}

	/**
	 * A template or part is written from its canonical payload, which keeps asset
	 * tokens that the resolved markup has already expanded. Tokens live inside
	 * attribute values, so both serializations have the same blocks in the same
	 * order: each binding's resolved block maps to the canonical block at the same
	 * index, and the provider replacement is spliced there, last offset first.
	 *
	 * @param array<string,mixed>                                                $plan    Resolved plan.
	 * @param int|string                                                         $index   Surface index.
	 * @param array<int,array{offset:int|null,search:string,replacement:string}> $patches Bindings applied to this surface.
	 * @param string                                                             $group   Template or template-part collection.
	 * @throws InvalidArgumentException When the canonical surface write is missing or mismatched.
	 */
	private static function apply_template_part_binding_patches( array &$plan, int|string $index, array $patches, string $group ): void {
		$part   = $plan[ $group ][ $index ];
		$target = ( 'templates' === $group ? 'templates/' : 'parts/' ) . (string) ( $part['slug'] ?? '' ) . '.html';
		$write  = null;
		foreach ( $plan['writes'] ?? array() as $write_index => $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['target_path'] ?? null ) === $target && 'utf8' === ( $candidate['payload']['encoding'] ?? null ) && is_string( $candidate['payload']['data'] ?? null ) ) {
				$write = $write_index;
				break;
			}
		}
		if ( null === $write ) {
			// ssi-error-class: representability:block
			throw new InvalidArgumentException( 'runtime_entity_binding_template_part_write_missing' );
		}
		$canonical = self::splice_canonical_markup( (string) ( $part['resolved_block_markup'] ?? '' ), (string) $plan['writes'][ $write ]['payload']['data'], $patches );

		$plan['writes'][ $write ]['payload']['data'] = $canonical;
		$plan['writes'][ $write ]['payload_hash']    = hash( 'sha256', $canonical );
	}

	/**
	 * Apply resolved-markup binding patches to the canonical serialization of
	 * the same document. Asset tokens live inside attribute values, so both
	 * serializations have the same blocks in the same order: each binding's
	 * resolved block maps to the canonical block at the same index, and the
	 * provider replacement is spliced there, last offset first.
	 *
	 * @param array<int,array{offset:int|null,search:string,replacement:string}> $patches Bindings applied to the document.
	 */
	private static function splice_canonical_markup( string $resolved, string $canonical, array $patches ): string {
		$resolved_ranges  = WordPressSitePlan::blockRanges( $resolved );
		$canonical_ranges = WordPressSitePlan::blockRanges( $canonical );
		if ( count( $resolved_ranges ) !== count( $canonical_ranges ) ) {
			// ssi-error-class: representability:block
			throw new InvalidArgumentException( 'runtime_entity_binding_template_part_write_mismatch' );
		}
		$splices = array();
		foreach ( $patches as $patch ) {
			$block = null;
			foreach ( $resolved_ranges as $block_index => $range ) {
				if ( $range['offset'] === $patch['offset'] && strlen( $patch['search'] ) === $range['length'] ) {
					$block = $block_index;
					break;
				}
			}
			if ( null === $block ) {
				// ssi-error-class: representability:block
				throw new InvalidArgumentException( 'runtime_entity_binding_template_part_write_mismatch' );
			}
			$splices[] = $canonical_ranges[ $block ] + array( 'replacement' => $patch['replacement'] );
		}
		usort( $splices, static fn( array $left, array $right ): int => $right['offset'] <=> $left['offset'] );
		foreach ( $splices as $splice ) {
			$canonical = substr( $canonical, 0, $splice['offset'] ) . $splice['replacement'] . substr( $canonical, $splice['offset'] + $splice['length'] );
		}
		return $canonical;
	}

	/**
	 * Write the page that holds a store listing as the theme's product archive
	 * template: the page template with the page's own blocks in place of its
	 * content, so WooCommerce's shop archive renders the imported listing.
	 * A theme without a page template has no archive design to derive.
	 *
	 * @param array<string,mixed>                                                $plan    Resolved plan.
	 * @param int|string                                                         $index   Page index.
	 * @param array<int,array{offset:int|null,search:string,replacement:string}> $patches Bindings applied to the page.
	 */
	private static function write_product_archive_template( array &$plan, int|string $index, array $patches ): void {
		$page     = $plan['pages'][ $index ];
		$template = null;
		$existing = null;
		foreach ( $plan['writes'] ?? array() as $write_index => $candidate ) {
			$target = is_array( $candidate ) ? ( $candidate['target_path'] ?? null ) : null;
			if ( 'templates/page.html' === $target && 'utf8' === ( $candidate['payload']['encoding'] ?? null ) && is_string( $candidate['payload']['data'] ?? null ) ) {
				$template = $candidate['payload']['data'];
			}
			if ( Static_Site_Importer_Woo_Product_Listing_Materializer::ARCHIVE_TEMPLATE === $target ) {
				$existing = $write_index;
			}
		}
		if ( null === $template ) {
			return;
		}
		// Theme files keep the canonical asset tokens; a page whose canonical
		// serialization does not line up keeps its resolved markup.
		try {
			$content = self::splice_canonical_markup( (string) ( $page['resolved_block_markup'] ?? '' ), (string) ( $page['canonical_block_markup'] ?? '' ), $patches );
		} catch ( InvalidArgumentException $mismatch ) {
			unset( $mismatch );
			$resolved = (string) ( $page['resolved_block_markup'] ?? '' );
			$content  = self::splice_canonical_markup( $resolved, $resolved, $patches );
		}
		$count  = 0;
		$markup = (string) preg_replace_callback( '/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/s', static fn(): string => $content, $template, 1, $count );
		if ( 1 !== $count || '' === $content ) {
			return;
		}
		$write = array(
			'kind'                    => 'theme_template',
			'source_path'             => '',
			'target_path'             => Static_Site_Importer_Woo_Product_Listing_Materializer::ARCHIVE_TEMPLATE,
			'payload'                 => array(
				'encoding' => 'utf8',
				'data'     => $markup,
			),
			'payload_hash'            => hash( 'sha256', $markup ),
			'reconciliation_identity' => hash( 'sha256', 'theme-template:' . Static_Site_Importer_Woo_Product_Listing_Materializer::ARCHIVE_TEMPLATE ),
		);
		if ( null === $existing ) {
			$plan['writes'][] = $write;
		} else {
			$plan['writes'][ $existing ] = $write;
		}
	}

	/**
	 * Reject a provider fragment unless Core can round-trip it as a complete block document.
	 *
	 * @param array<string,mixed> $binding     Runtime entity binding.
	 * @param array<int,mixed>    $diagnostics Admission diagnostics.
	 * @return void
	 */
	public static function validate_runtime_entity_binding_fragment( array $binding, array &$diagnostics ): void {
		if ( ! self::is_complete_block_document( (string) $binding['replacement_block_markup'] ) || ( array_key_exists( 'archive_replacement_block_markup', $binding ) && ( ! is_string( $binding['archive_replacement_block_markup'] ) || ! self::is_complete_block_document( $binding['archive_replacement_block_markup'] ) ) ) ) {
			$diagnostics[] = array(
				'reason_code'             => 'runtime_entity_binding_replacement_invalid',
				'source_path'             => $binding['source_path'],
				'reconciliation_identity' => $binding['reconciliation_identity'],
				'declaration_id'          => $binding['declaration_id'] ?? '',
				'provider'                => $binding['provider'] ?? '',
			);
			// ssi-error-class: representability:block
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
	public static function validate_materialized_block_documents( array $plan, array $bindings, array &$diagnostics, array $pending_blocks = array() ): void {
		$template_parts = array_values( array_filter( array_merge( $plan['templates'] ?? array(), $plan['template_parts'] ?? array() ), static fn( $part ): bool => is_array( $part ) && isset( $part['materialized_block_markup'] ) ) );
		foreach ( array_merge( $plan['pages'] ?? array(), $template_parts, array_values( $plan['synced_patterns'] ?? array() ) ) as $page ) {
			if ( ! is_array( $page ) || ! empty( $page['skip_materialization'] ) ) {
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
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'runtime_entity_bound_block_document_invalid' );
			}
			$admission   = self::block_document_editor_admission( $markup, $source_path, $pending_blocks );
			$diagnostics = array_merge( $diagnostics, $admission['diagnostics'] );
			if ( ! $admission['admitted'] ) {
				// ssi-error-class: representability:block
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
	public static function block_document_editor_admission( string $markup, string $source_path, array $pending_blocks = array() ): array {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			// ssi-error-class: operational
			throw new InvalidArgumentException( 'block_editor_admission_unavailable' );
		}
		$registry    = WP_Block_Type_Registry::get_instance();
		$diagnostics = array();
		$unsupported = false;
		$inspect     = static function ( array $blocks, ?string $parent_name = null ) use ( &$inspect, $registry, $source_path, &$diagnostics, &$unsupported, $pending_blocks ): void {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
					continue;
				}
				$name       = $block['blockName'];
				$block_type = $registry->get_registered( $name );
				if ( ! $block_type && ! in_array( $name, $pending_blocks, true ) ) {
					$unsupported = true;
					if ( self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'unsupported_persisted_block',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'block_classification' => 'unsupported',
						);
					}
				} elseif ( $block_type ) {
					$classification = str_starts_with( $name, 'core/' ) ? 'registered_core' : 'registered_provider';
					$owners         = $GLOBALS['static_site_importer_companion_block_owners'] ?? array();
					if ( 'registered_provider' === $classification && is_array( $owners ) && isset( $owners[ $name ] ) && is_array( $owners[ $name ] ) ) {
						$classification = 'declared_companion_dependency';
					}
					if ( 'registered_provider' === $classification && 'theme' === ( $GLOBALS['static_site_importer_runtime_block_owners'][ $name ]['owner'] ?? '' ) ) {
						$classification = 'generated_theme_runtime';
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

	/** Drop scheme, credentials, host and port, keeping path, query and fragment. */
	private static function host_free_url( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Plain URL split; runs without WordPress loaded.
		if ( ! is_array( $parts ) ) {
			return '';
		}
		return ( $parts['path'] ?? '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
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
			'permalink'   => function_exists( 'get_permalink' ) ? self::host_free_url( (string) get_permalink( $existing ) ) : $page['route']['path'],
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
	 * Read the Blocks Engine editability decision; SSI keeps no policy of its own.
	 *
	 * The producer measures every page and blocks only on the entrypoint wrapper
	 * ratio. A `failed` status still fails the import; per-page `findings` are
	 * carried as reported losses (the producer also emits them as
	 * `editability_policy_degraded` warnings that the loss ledger classifies).
	 * The policy shape is part of the canonical plan contract the resolver
	 * asserts, so it is not re-validated here.
	 *
	 * @param array<string,mixed> $plan Canonical plan.
	 * @return array<string,mixed>
	 */
	public static function editability_decision( array $plan ): array {
		$policy   = is_array( $plan['quality']['editability_policy'] ?? null ) ? $plan['quality']['editability_policy'] : array();
		$findings = is_array( $policy['findings'] ?? null ) ? array_values( array_filter( $policy['findings'], 'is_array' ) ) : array();
		$decision = array(
			'schema'        => 'static-site-importer/editability-report-admission/v1',
			'owning_layer'  => 'blocks-engine',
			'policy_schema' => (string) ( $policy['schema'] ?? '' ),
			'status'        => 'failed' === ( $policy['status'] ?? null ) ? 'failed' : 'passed',
			'finding_count' => (int) ( $policy['finding_totals']['observed'] ?? count( $findings ) ),
			'findings'      => array_slice( $findings, 0, 10 ),
		);
		if ( 'failed' !== $decision['status'] ) {
			return $decision;
		}
		$failures               = is_array( $policy['failures'] ?? null ) ? array_values( array_filter( $policy['failures'], 'is_array' ) ) : array();
		$decision['diagnostic'] = array(
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
		);
		return $decision;
	}

	/**
	 * Read the Blocks Engine design system the plan's theme was derived from.
	 *
	 * A reported quality signal, never a gate: the plan's `theme.design_system`
	 * (token report plus `metrics`) is carried unchanged; when only the
	 * editability report carries it (`design_system` with `metrics`), that is
	 * used. An older Blocks Engine produces neither and the section is absent.
	 *
	 * @param array<string,mixed> $plan               Canonical plan.
	 * @param array<string,mixed> $editability_report Blocks Engine editability report, when available.
	 * @return array<string,mixed>
	 */
	public static function design_system( array $plan, array $editability_report = array() ): array {
		foreach ( array( $plan['theme']['design_system'] ?? null, $editability_report['design_system'] ?? null ) as $design_system ) {
			if ( is_array( $design_system ) && is_array( $design_system['metrics'] ?? null ) ) {
				return $design_system;
			}
		}
		return array();
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
