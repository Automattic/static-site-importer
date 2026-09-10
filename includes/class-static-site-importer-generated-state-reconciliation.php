<?php
/**
 * Generated-state reconciliation for prior Static Site Importer manifests.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Protected_Page_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-protected-page-policy.php';
}

final class Static_Site_Importer_Generated_State_Reconciliation {
	/**
	 * Remove generated theme files from the previous SSI manifest when absent from the new desired manifest.
	 *
	 * @param string              $theme_dir        Theme directory.
	 * @param array<string,mixed> $current_manifest Current source-of-truth manifest.
	 * @param array<string,mixed> $args             Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function cleanup_stale_generated_theme_files( string $theme_dir, array $current_manifest, array $args = array(), array &$receipt = array() ) {
		$previous_manifest_path = trailingslashit( $theme_dir ) . 'static-site-importer-manifest.json';
		$cleanup                = array(
			'enabled'                => true,
			'policy'                 => 'previous_manifest_file_targets_only',
			'previous_manifest_path' => 'static-site-importer-manifest.json',
			'deleted'                => array(),
			'skipped'                => array(),
			'pages'                  => array(
				'enabled'     => true,
				'policy'      => 'previous_manifest_provenance_report_first',
				'action'      => self::stale_page_action( $args ),
				'stale_pages' => array(),
				'skipped'     => array(),
				'counts'      => array(
					'stale_pages'   => 0,
					'pages_drafted' => 0,
					'pages_deleted' => 0,
					'skipped'       => 0,
				),
				'notes'       => array( 'Stale SSI-owned pages are reported by default. Drafting requires explicit stale_page_action=draft; deletion is not supported here.' ),
			),
			'counts'                 => array(
				'deleted'       => 0,
				'skipped'       => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
			),
			'protected'              => array(
				'pages_deleted' => 0,
				'pages_drafted' => 0,
				'notes'         => array( 'Page deletion is intentionally disabled; this cleanup only removes prior SSI-generated theme files and assets.' ),
			),
		);
		if ( (string) ( $args['inject_materialization_failure'] ?? '' ) === 'stale_cleanup' ) {
			return new WP_Error( 'injected_stale_cleanup_failure', 'Injected stale cleanup failure.' );
		}
		if ( ! is_file( $previous_manifest_path ) ) {
			$cleanup['skipped'][]         = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_missing',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}
		$previous_manifest_json = file_get_contents( $previous_manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned local manifest file.
		if ( false === $previous_manifest_json ) {
			return new WP_Error( 'static_site_importer_previous_manifest_read_failed', 'Failed to read the previous Static Site Importer manifest.' );
		}
		$previous_manifest = json_decode( $previous_manifest_json, true );
		if ( ! is_array( $previous_manifest ) || 'static-site-importer/source-of-truth-manifest/v1' !== (string) ( $previous_manifest['schema'] ?? '' ) ) {
			$cleanup['skipped'][]         = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_invalid',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}
		$page_reconciliation = self::reconcile_stale_manifest_pages( $previous_manifest, $current_manifest, $cleanup['pages']['action'], $receipt );
		if ( is_wp_error( $page_reconciliation ) ) {
			return $page_reconciliation;
		}
		$cleanup['pages']                      = $page_reconciliation;
		$cleanup['protected']['pages_deleted'] = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['protected']['pages_drafted'] = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );
		$cleanup['counts']['pages_deleted']    = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['counts']['pages_drafted']    = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );
		$current_paths                         = self::manifest_theme_file_paths( $current_manifest );
		$previous_paths                        = self::manifest_theme_file_paths( $previous_manifest );
		$stale_paths                           = array_values( array_diff( array_keys( $previous_paths ), array_keys( $current_paths ) ) );
		foreach ( $stale_paths as $relative ) {
			$path = trailingslashit( $theme_dir ) . $relative;
			if ( ! file_exists( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'already_missing',
				);
				continue;
			}
			if ( ! is_file( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'not_a_file',
				);
				continue;
			}
			Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_file( $receipt, $path );
			if ( ! wp_delete_file( $path ) ) {
				return new WP_Error( 'static_site_importer_stale_generated_file_delete_failed', sprintf( 'Failed to delete stale generated theme file: %s', $relative ) );
			}
			$cleanup['deleted'][] = array(
				'path'   => $relative,
				'reason' => 'absent_from_current_manifest',
			);
		}
		$cleanup['counts']['deleted'] = count( $cleanup['deleted'] );
		$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
		return $cleanup;
	}

	/** @param array<string,mixed> $args */
	private static function stale_page_action( array $args ): string {
		$action = isset( $args['stale_page_action'] ) && is_scalar( $args['stale_page_action'] ) ? sanitize_key( (string) $args['stale_page_action'] ) : '';
		if ( '' === $action ) {
			$option = get_option( 'static_site_importer_stale_page_action', '' );
			$action = is_scalar( $option ) ? sanitize_key( (string) $option ) : '';
		}
		return 'draft' === $action ? 'draft' : 'report_only';
	}

	/** @return array<string,mixed>|WP_Error */
	private static function reconcile_stale_manifest_pages( array $previous_manifest, array $current_manifest, string $action, array &$receipt = array() ) {
		$reconciliation  = array(
			'enabled'     => true,
			'policy'      => 'previous_manifest_provenance_report_first',
			'action'      => 'draft' === $action ? 'draft' : 'report_only',
			'stale_pages' => array(),
			'skipped'     => array(),
			'counts'      => array(
				'stale_pages'   => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
				'skipped'       => 0,
			),
			'notes'       => array( 'Only pages with valid Static Site Importer provenance meta are eligible. Protected pages and pages without SSI provenance are never mutated.' ),
		);
		$current_sources = array();
		$current_posts   = array();
		foreach ( self::manifest_pages( $current_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( '' !== $source_path ) {
				$current_sources[ $source_path ] = true;
			}
			if ( $post_id > 0 ) {
				$current_posts[ $post_id ] = true;
			}
		}
		foreach ( self::manifest_pages( $previous_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( ( '' !== $source_path && isset( $current_sources[ $source_path ] ) ) || $post_id <= 0 || isset( $current_posts[ $post_id ] ) ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'reason'      => 'post_missing',
				);
				continue;
			}
			if ( Static_Site_Importer_Protected_Page_Policy::is_protected_page( $post ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'protected_page',
				);
				continue;
			}
			if ( empty( self::page_provenance( $post_id ) ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'missing_static_site_importer_provenance',
				);
				continue;
			}
			$row = array(
				'post_id'         => $post_id,
				'post_type'       => (string) $post->post_type,
				'slug'            => (string) $post->post_name,
				'source_path'     => $source_path,
				'previous_status' => (string) $post->post_status,
				'action'          => 'report_only',
			);
			if ( 'draft' === $reconciliation['action'] ) {
				if ( 'draft' !== $post->post_status ) {
					Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_post( $receipt, $post_id );
					$result = wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'draft',
						),
						true
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					++$reconciliation['counts']['pages_drafted'];
				}
				$row['action']     = 'drafted';
				$row['new_status'] = 'draft';
			}
			$reconciliation['stale_pages'][] = $row;
		}
		$reconciliation['counts']['stale_pages'] = count( $reconciliation['stale_pages'] );
		$reconciliation['counts']['skipped']     = count( $reconciliation['skipped'] );
		return $reconciliation;
	}

	/** @return array<int,array<string,mixed>> */
	private static function manifest_pages( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$pages   = isset( $desired['pages'] ) && is_array( $desired['pages'] ) ? $desired['pages'] : array();
		return array_values( array_filter( $pages, 'is_array' ) );
	}

	/** @return array<string,mixed> */
	private static function page_provenance( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, '_static_site_importer_provenance', true );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$provenance = json_decode( $raw, true );
		return is_array( $provenance ) && 'static-site-importer/page-provenance/v1' === (string) ( $provenance['schema'] ?? '' ) ? $provenance : array();
	}

	/** @return array<string,true> */
	private static function manifest_theme_file_paths( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$paths   = array();
		foreach ( $desired['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$relative = self::normalize_manifest_theme_relative_path( isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}
		foreach ( $desired['assets'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$relative = self::normalize_manifest_theme_relative_path( isset( $asset['theme_path'] ) && is_scalar( $asset['theme_path'] ) ? (string) $asset['theme_path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}
		return $paths;
	}

	private static function normalize_manifest_theme_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		if ( '' === $path || str_contains( $path, "\0" ) || str_starts_with( $path, '../' ) || str_contains( $path, '/../' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}
		return $path;
	}
}
