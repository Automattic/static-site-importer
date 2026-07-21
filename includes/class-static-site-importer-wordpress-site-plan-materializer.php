<?php
/**
 * Applies the canonical Blocks Engine WordPress site plan to a WordPress runtime.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

final class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public const RECEIPT_SCHEMA = 'static-site-importer/materialization-receipt/v1';
	private const RECONCILIATION_META_KEY = '_static_site_importer_reconciliation_identity';

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
			'plan'        => $plan,
			'plan_hash'   => self::hash( $plan ),
			'diagnostics' => array(),
			'applied'     => array( 'posts' => array(), 'files' => array(), 'operations' => array() ),
			'skipped'     => array(),
			'existing_matches' => array( 'pages' => array() ),
			'report_destinations' => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
		);

		try {
			WordPressSitePlan::assertValid( $plan );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][] = array( 'reason_code' => 'canonical_plan_rejected' );
			return array( 'status' => 'rejected', 'receipt' => self::receipt( 'rejected', $state ) );
		}

		try {
			$slug = sanitize_key( (string) ( $args['slug'] ?? '' ) );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			$theme_root = get_theme_root();
			if ( ! is_string( $theme_root ) || ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) {
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			$theme_dir = trailingslashit( $theme_root ) . $slug;
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			$theme_uri = trailingslashit( get_theme_root_uri() ) . $slug;
			try {
				// Resolver proof is canonical semantics, not an inference from copied files.
				$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => $theme_uri, 'require_proven_dynamic_client_assets' => true ) );
			} catch ( InvalidArgumentException $error ) {
				throw new InvalidArgumentException( 'canonical_destination_rejected' );
			}
			$state['resolved'] = $resolved;
			$state['theme_dir'] = $theme_dir;
			$state['theme'] = array(
				'slug' => $slug,
				'dir'  => $theme_dir,
				'uri'  => $theme_uri,
			);
			self::preflight_state( $state, ! empty( $args['overwrite'] ) );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][] = array( 'reason_code' => $error->getMessage() );
			return array( 'status' => 'rejected', 'receipt' => self::receipt( 'rejected', $state ) );
		}
		$state['status'] = 'prepared';
		$state['args']   = $args;
		return $state;
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['plan'] ) || ! is_array( $prepared['plan'] ) ) {
			return self::receipt( 'rejected', array( 'plan' => array(), 'plan_hash' => '', 'diagnostics' => array( array( 'reason_code' => 'invalid_prepared_state' ) ), 'applied' => array( 'posts' => array(), 'files' => array(), 'operations' => array() ), 'skipped' => array(), 'existing_matches' => array( 'pages' => array() ) ) );
		}
		$state = self::prepare( $prepared['plan'], isset( $prepared['args'] ) && is_array( $prepared['args'] ) ? $prepared['args'] : array() );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$args = $state['args'];

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$post = self::materialize_page( $page, $state['source_ids'] );
			if ( is_wp_error( $post ) ) {
				return self::failed_receipt( $state, $post->get_error_code() );
			}
			$state['page_ids'][ $page['reconciliation_identity'] ] = $post;
			$state['source_ids'][ $page['source_path'] ] = $post;
			update_post_meta( $post, '_static_site_importer_provenance', wp_json_encode( array( 'schema' => 'static-site-importer/page-provenance/v1', 'import_run_id' => (string) ( $args['import_run_id'] ?? '' ), 'source_path' => $page['source_path'], 'reconciliation_identity' => $page['reconciliation_identity'], 'content_hash' => $page['content_hash'] ) ) );
			$state['applied']['posts'][] = array( 'id' => $post, 'reconciliation_identity' => $page['reconciliation_identity'] );
		}

		foreach ( $state['resolved']['writes'] as $write ) {
			$result = self::write_file( $state['theme_dir'], $write );
			if ( is_wp_error( $result ) ) {
				return self::failed_receipt( $state, $result->get_error_code() );
			}
			$state['applied']['files'][] = $result;
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
			$state['applied']['operations'][] = array( 'kind' => 'activate_theme', 'theme_slug' => $state['theme']['slug'] );
			if ( '' !== trim( (string) ( $args['site_title'] ?? '' ) ) ) {
				update_option( 'blogname', sanitize_text_field( (string) $args['site_title'] ) );
				$state['applied']['operations'][] = array( 'kind' => 'site_title' );
			}
		}

		return self::receipt( 'completed', $state );
	}

	/** @param array<string,mixed> $state */
	private static function preflight_state( array &$state, bool $overwrite ): void {
		$pages_by_route = array();
		$state['page_ids'] = array();
		$state['source_ids'] = array();
		foreach ( $state['resolved']['pages'] as $page ) {
			if ( ! isset( $page['resolved_block_markup'] ) || ! is_string( $page['resolved_block_markup'] ) || '' === trim( $page['resolved_block_markup'] ) ) {
				throw new InvalidArgumentException( 'page_missing_final_block_markup' );
			}
			$route = (string) ( $page['route']['path'] ?? '' );
			if ( isset( $pages_by_route[ $route ] ) ) {
				throw new InvalidArgumentException( 'duplicate_page_route' );
			}
			$pages_by_route[ $route ] = true;
			$existing = self::reconciled_post( $page['reconciliation_identity'] );
			if ( $existing ) {
				$page = self::plan_existing_page( $state, $page, $existing, 'reconciliation_identity_match' );
				$state['resolved']['pages'][ array_search( $page['source_path'], array_column( $state['resolved']['pages'], 'source_path' ), true ) ] = $page;
				continue;
			}
			$conflict = '' === trim( $route, '/' ) ? null : get_page_by_path( trim( $route, '/' ), OBJECT, 'page' );
			if ( $conflict && ! $overwrite ) {
				throw new InvalidArgumentException( 'post_conflict' );
			}
			if ( $conflict ) {
				$page = self::plan_existing_page( $state, $page, $conflict, 'canonical_route_match' );
				$state['resolved']['pages'][ array_search( $page['source_path'], array_column( $state['resolved']['pages'], 'source_path' ), true ) ] = $page;
			}
		}
		$state['ordered_pages'] = self::parent_ordered_pages( $state['resolved']['pages'] );
		if ( null === $state['ordered_pages'] ) {
			throw new InvalidArgumentException( 'invalid_page_parent_identity' );
		}
		foreach ( $state['resolved']['operations'] as $operation ) {
			if ( 'create_page' === $operation['kind'] ) {
				continue;
			}
			if ( 'site_reading' !== $operation['kind'] || ! isset( $state['page_ids'][ $operation['front_page_reconciliation_identity'] ] ) && ! self::page_exists_in_plan( $state['resolved']['pages'], $operation['front_page_reconciliation_identity'] ) ) {
				throw new InvalidArgumentException( 'unsupported_operation' );
			}
		}
		foreach ( $state['resolved']['writes'] as $write ) {
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( ! self::safe_destination( $state['theme_dir'], $write['target_path'] ) ) {
				throw new InvalidArgumentException( 'unsafe_destination_path' );
			}
			if ( is_dir( $path ) || ( file_exists( $path ) && ! $overwrite && self::file_hash( $path ) !== self::payload_hash( $write ) ) ) {
				throw new InvalidArgumentException( 'file_conflict' );
			}
		}
		foreach ( $state['report_destinations'] ?? array() as $path ) {
			$parent = is_string( $path ) ? dirname( $path ) : '';
			while ( '' !== $parent && ! file_exists( $parent ) ) {
				$parent = dirname( $parent );
			}
			if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || '' === $parent || is_link( $parent ) || ! is_dir( $parent ) || ! is_writable( $parent ) ) {
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
	}

	/** @param array<string,mixed> $page @param array<string,int> $source_ids */
	private static function materialize_page( array $page, array $source_ids ) {
		$parent = '' === $page['parent_source_path'] ? 0 : ( $source_ids[ $page['parent_source_path'] ] ?? false );
		if ( false === $parent ) {
			return new WP_Error( 'missing_parent_page' );
		}
		$post = array(
			'ID'           => (int) ( $page['planned_existing_id'] ?? 0 ),
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_parent'  => $parent,
			'post_content' => wp_slash( $page['resolved_block_markup'] ),
		);
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, self::RECONCILIATION_META_KEY, $page['reconciliation_identity'] );
		return (int) $id;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $page */
	private static function plan_existing_page( array &$state, array $page, WP_Post $existing, string $reason ): array {
		$id        = (int) $existing->ID;
		$protected = class_exists( 'Static_Site_Importer_Page_Materializer' ) && Static_Site_Importer_Page_Materializer::is_protected_page( $existing );
		$page['planned_existing_id'] = $id;
		$state['page_ids'][ $page['reconciliation_identity'] ] = $id;
		$state['source_ids'][ $page['source_path'] ] = $id;
		$row = array(
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
			$state['skipped'][] = $row;
		}
		return $page;
	}

	/** @param array<string,mixed> $write */
	private static function write_file( string $theme_dir, array $write ) {
		$path = $theme_dir . '/' . $write['target_path'];
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'theme_directory_create_failed' );
		}
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data'];
		$temp = tempnam( dirname( $path ), '.ssi-plan-' );
		if ( false === $data || false === $temp || false === file_put_contents( $temp, $data ) || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomically materializes the canonical declared theme write.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed temporary materialization file.
			}
			return new WP_Error( 'theme_write_failed' );
		}
		return array( 'target_path' => $write['target_path'], 'hash' => hash( 'sha256', $data ), 'payload_hash' => $write['payload_hash'] ?? hash( 'sha256', $data ), 'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ) );
	}

	/** @param array<string,mixed> $operation @param array<string,int> $page_ids */
	private static function apply_operation( array $operation, array $page_ids ) {
		$id = $page_ids[ $operation['front_page_reconciliation_identity'] ] ?? 0;
		if ( ! $id ) {
			return new WP_Error( 'operation_target_missing' );
		}
		update_option( 'show_on_front', $operation['show_on_front'] );
		update_option( 'page_on_front', $id );
		return array( 'kind' => $operation['kind'], 'order' => $operation['order'], 'reconciliation_identity' => $operation['front_page_reconciliation_identity'] );
	}

	private static function reconciled_post( string $identity ) {
		$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => self::RECONCILIATION_META_KEY, 'meta_value' => $identity, 'numberposts' => 1 ) );
		return isset( $posts[0] ) ? $posts[0] : null;
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
	private static function parent_ordered_pages( array $pages ): ?array {
		$remaining = array();
		foreach ( $pages as $page ) {
			$remaining[ $page['source_path'] ] = $page;
		}
		$ordered = array();
		while ( ! empty( $remaining ) ) {
			$progress = false;
			foreach ( $remaining as $source => $page ) {
				$parent = $page['parent_source_path'];
				if ( '' !== $parent && ! isset( $ordered[ $parent ] ) ) {
					if ( ! isset( $remaining[ $parent ] ) ) {
						return null;
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

	private static function safe_destination( string $theme_dir, string $target ): bool {
		$current = rtrim( $theme_dir, '/' );
		foreach ( explode( '/', dirname( $target ) ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			$current .= '/' . $segment;
			if ( is_link( $current ) || ( file_exists( $current ) && ! is_dir( $current ) ) || ( is_dir( $current ) && ! is_writable( $current ) ) ) {
				return false;
			}
		}
		return ! is_link( $theme_dir . '/' . $target );
	}

	/** @param array<string,mixed> $write */
	private static function payload_hash( array $write ): string {
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data'];
		return is_string( $data ) ? hash( 'sha256', $data ) : '';
	}

	private static function file_hash( string $path ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preflight hashes a declared destination file.
		return false === $data ? '' : hash( 'sha256', $data );
	}

	/** @param array<string,mixed> $state */
	private static function failed_receipt( array $state, string $reason ): array {
		$state['diagnostics'][] = array( 'reason_code' => $reason );
		return self::receipt( 'partial', $state );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private static function receipt( string $status, array $state ): array {
		$plan = $state['plan'];
		$errors = array();
		$pages  = isset( $state['source_ids'] ) && is_array( $state['source_ids'] ) ? $state['source_ids'] : array();
		foreach ( $state['diagnostics'] as $diagnostic ) {
			if ( isset( $diagnostic['reason_code'] ) && is_string( $diagnostic['reason_code'] ) ) {
				$errors[] = array( 'code' => $diagnostic['reason_code'], 'message' => $diagnostic['reason_code'] );
			}
		}
		return array(
			'schema'                    => self::RECEIPT_SCHEMA,
			'status'                    => $status,
			'plan_hash'                 => $state['plan_hash'],
			'plan'                      => $state['resolved'] ?? $plan,
			'theme'                     => $state['theme'] ?? array(),
			'completed'                 => array(
				'pages'      => $pages,
				'files'      => $state['applied']['files'],
				'operations' => $state['applied']['operations'],
			),
			'reconciliation_identities' => array_merge( array_column( $plan['pages'] ?? array(), 'reconciliation_identity' ), array_column( $plan['writes'] ?? array(), 'reconciliation_identity' ) ),
			'wordpress'                 => $state['applied']['posts'],
			'generated_files'           => $state['applied']['files'],
			'operations'                => $state['applied']['operations'],
			'skipped_targets'           => $state['skipped'],
			'existing_matches'          => $state['existing_matches'],
			'diagnostics'               => $state['diagnostics'],
			'errors'                    => $errors,
		);
	}

	/** @param array<string,mixed> $plan */
	private static function hash( array $plan ): string {
		return hash( 'sha256', (string) wp_json_encode( $plan, JSON_UNESCAPED_SLASHES ) );
	}
}
