<?php
/**
 * Materializes the Blocks Engine WordPress site-plan contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies final Blocks Engine WordPress site plans without source transformation.
 */
class Static_Site_Importer_WordPress_Site_Plan_Materializer {

	public const PLAN_SCHEMA    = 'blocks-engine/wordpress-site-plan/v1';
	public const RECEIPT_SCHEMA = 'static-site-importer/materialization-receipt/v1';

	/**
	 * Preflight and apply a self-contained WordPress site plan.
	 *
	 * @param array<string,mixed> $plan Plan emitted by Blocks Engine.
	 * @param array<string,mixed> $args Materialization arguments.
	 * @return array<string,mixed>
	 */
	public static function materialize( array $plan, array $args = array() ): array {
		$preflight = self::preflight( $plan, $args );
		if ( ! empty( $preflight['diagnostics'] ) ) {
			return self::receipt( 'rejected', $preflight, array(), array() );
		}

		$applied = array(
			'posts' => array(),
			'files' => array(),
		);
		$skipped = $preflight['skipped'];
		foreach ( $preflight['posts'] as $document ) {
			if ( ! empty( $document['protected'] ) ) {
				continue;
			}
			$postarr = array(
				'post_title'   => $document['title'],
				'post_name'    => $document['slug'],
				'post_status'  => $document['status'],
				'post_type'    => $document['post_type'],
				'post_content' => $document['final_block_markup'],
			);
			if ( $document['existing_post_id'] ) {
				$postarr['ID'] = $document['existing_post_id'];
			}
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) {
				$skipped[] = array( 'target' => $document['reconciliation_identity'], 'reason' => $post_id->get_error_code() );
				continue;
			}
			$provenance = wp_json_encode( $document['provenance'], JSON_UNESCAPED_SLASHES );
			update_post_meta( (int) $post_id, '_static_site_importer_provenance', wp_slash( false === $provenance ? '{}' : $provenance ) );
			update_post_meta( (int) $post_id, '_static_site_importer_source_path', $document['source_path'] );
			update_post_meta( (int) $post_id, '_static_site_importer_reconciliation_identity', $document['reconciliation_identity'] );
			$applied['posts'][] = array(
				'id'                        => (int) $post_id,
				'source_path'               => $document['source_path'],
				'reconciliation_identity'   => $document['reconciliation_identity'],
			);
		}

		foreach ( $preflight['files'] as $file ) {
			if ( ! wp_mkdir_p( dirname( $file['path'] ) ) || false === file_put_contents( $file['path'], $file['content'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Applies a preflighted plan payload to its declared generated-theme target.
				$skipped[] = array( 'target' => $file['target_path'], 'reason' => 'write_failed' );
				continue;
			}
			$applied['files'][] = array(
				'path'                      => $file['target_path'],
				'hash'                      => hash( 'sha256', $file['content'] ),
				'reconciliation_identity'   => $file['reconciliation_identity'],
			);
		}

		$status = empty( $skipped ) ? 'applied' : 'partial';
		return self::receipt( $status, $preflight, $applied, $skipped );
	}

	/**
	 * Validate every target and payload before creating any post or directory.
	 *
	 * @param array<string,mixed> $plan Plan emitted by Blocks Engine.
	 * @param array<string,mixed> $args Materialization arguments.
	 * @return array<string,mixed>
	 */
	private static function preflight( array $plan, array $args ): array {
		$state = array(
			'diagnostics' => array(),
			'posts'       => array(),
			'files'       => array(),
			'skipped'     => array(),
			'plan'        => $plan,
			'plan_hash'   => self::plan_hash( $plan ),
			'source'      => isset( $plan['source'] ) && is_array( $plan['source'] ) ? $plan['source'] : array(),
			'theme_slug'  => sanitize_key( (string) ( $args['slug'] ?? '' ) ),
		);
		if ( self::PLAN_SCHEMA !== ( $plan['schema'] ?? null ) ) {
			return self::reject( $state, 'unsupported_schema' );
		}
		foreach ( array( 'source', 'pages', 'templates', 'template_parts', 'assets', 'writes', 'routes', 'navigation_links', 'menus', 'asset_rewrite_candidates', 'theme', 'visual_repair', 'diagnostics', 'quality' ) as $key ) {
			if ( ! isset( $plan[ $key ] ) || ! is_array( $plan[ $key ] ) ) {
				return self::reject( $state, 'missing_or_invalid_' . $key );
			}
		}
		if ( '' === $state['theme_slug'] || $state['theme_slug'] !== (string) ( $args['slug'] ?? '' ) ) {
			return self::reject( $state, 'invalid_theme_slug' );
		}
		if ( ! self::valid_source( $plan['source'] ) || ! self::valid_quality( $plan['quality'] ) ) {
			return self::reject( $state, 'invalid_source_or_quality' );
		}
		$assets = array();
		foreach ( $plan['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! self::safe_path( $asset['source_path'] ?? null ) || ! self::safe_path( $asset['target_path'] ?? null ) || ! is_string( $asset['mime_type'] ?? null ) || ! is_string( $asset['hash'] ?? null ) || ! self::hash( $asset['hash'] ) || ! self::valid_load( $asset['load'] ?? null ) ) {
				return self::reject( $state, 'invalid_asset_identity' );
			}
			$assets[ $asset['source_path'] . "\n" . $asset['target_path'] ] = $asset;
		}

		$documents = array_merge(
			self::documents( $plan['pages'], 'page' ),
			self::documents( $plan['templates'], 'template' ),
			self::documents( $plan['template_parts'], 'template_part' )
		);
		foreach ( $documents as $document ) {
			if ( is_wp_error( $document ) ) {
				return self::reject( $state, $document->get_error_code() );
			}
			if ( 'page' === $document['kind'] ) {
				$existing = get_page_by_path( $document['slug'], OBJECT, $document['post_type'] );
				$document['existing_post_id'] = $existing instanceof WP_Post ? (int) $existing->ID : 0;
				$document['protected'] = $existing instanceof WP_Post && '' === (string) get_post_meta( $existing->ID, '_static_site_importer_reconciliation_identity', true );
				if ( $document['existing_post_id'] && ! $document['protected'] && empty( $args['overwrite'] ) ) {
					return self::reject( $state, 'post_conflict' );
				}
				if ( $document['protected'] ) {
					$state['skipped'][] = array( 'target' => $document['reconciliation_identity'], 'reason' => 'protected_post' );
				}
				$state['posts'][] = $document;
				continue;
			}
			$target_path = ( 'template' === $document['kind'] ? 'templates/' : 'parts/' ) . $document['slug'] . '.html';
			$state['files'][] = self::file_row( $target_path, $document['final_block_markup'], $document['reconciliation_identity'] );
		}
		foreach ( $plan['writes'] as $write ) {
			$file = self::write_file_row( $write, $assets );
			if ( is_wp_error( $file ) ) {
				return self::reject( $state, $file->get_error_code() );
			}
			$state['files'][] = $file;
		}

		$theme_dir = trailingslashit( get_theme_root() ) . $state['theme_slug'];
		$parent    = dirname( $theme_dir );
		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return self::reject( $state, 'theme_destination_not_ready' );
		}
		$seen = array();
		foreach ( $state['files'] as &$file ) {
			if ( isset( $seen[ $file['target_path'] ] ) ) {
				return self::reject( $state, 'duplicate_target_path' );
			}
			$seen[ $file['target_path'] ] = true;
			$file['path'] = $theme_dir . '/' . $file['target_path'];
			if ( file_exists( $file['path'] ) && empty( $args['overwrite'] ) ) {
				return self::reject( $state, 'file_conflict' );
			}
		}
		unset( $file );

		return $state;
	}

	/** @return array<int,array<string,mixed>|WP_Error> */
	private static function documents( array $documents, string $kind ): array {
		$validated = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || ! self::safe_path( $document['source_path'] ?? null ) || ! is_string( $document['slug'] ?? null ) || '' === sanitize_key( $document['slug'] ) || ! is_string( $document['title'] ?? null ) || ! is_string( $document['post_type'] ?? null ) || ! is_string( $document['parent_source_path'] ?? null ) || ! is_bool( $document['entrypoint'] ?? null ) || ! is_string( $document['final_block_markup'] ?? null ) || '' === trim( $document['final_block_markup'] ) || ! str_contains( $document['final_block_markup'], '<!-- wp:' ) || ! is_array( $document['metadata'] ?? null ) || ! is_array( $document['provenance'] ?? null ) || ! self::hash( $document['reconciliation_identity'] ?? null ) ) {
			return array( new WP_Error( 'invalid_' . $kind . '_document' ) );
			}
			if ( 'template_part' === $kind && ( ! is_string( $document['area'] ?? null ) || '' === $document['area'] ) ) {
				return array( new WP_Error( 'invalid_template_part_area' ) );
			}
			$validated[] = array_merge( $document, array( 'kind' => $kind, 'slug' => sanitize_key( $document['slug'] ), 'status' => 'publish' ) );
		}
		return $validated;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function write_file_row( $write, array $assets ) {
		if ( ! is_array( $write ) || 'theme_asset' !== ( $write['kind'] ?? null ) || ! self::safe_path( $write['source_path'] ?? null ) || ! self::safe_path( $write['target_path'] ?? null ) || ! is_array( $write['payload'] ?? null ) || ! is_string( $write['mime_type'] ?? null ) || '' === $write['mime_type'] || ! self::hash( $write['hash'] ?? null ) ) {
			return new WP_Error( 'invalid_theme_asset_write' );
		}
		$encoding = $write['payload']['encoding'] ?? null;
		$data     = $write['payload']['data'] ?? null;
		if ( ! in_array( $encoding, array( 'utf8', 'base64' ), true ) || ! is_string( $data ) ) {
			return new WP_Error( 'invalid_asset_payload' );
		}
		$asset = $assets[ $write['source_path'] . "\n" . $write['target_path'] ] ?? null;
		if ( ! is_array( $asset ) || $asset['mime_type'] !== $write['mime_type'] || $asset['hash'] !== $write['hash'] ) {
			return new WP_Error( 'inconsistent_asset_identity' );
		}
		$content = 'base64' === $encoding ? base64_decode( $data, true ) : $data;
		if ( false === $content || ( 'utf8' === $encoding && 1 !== preg_match( '//u', $content ) ) || ! hash_equals( $write['hash'], hash( 'sha256', $content ) ) ) {
			return new WP_Error( 'inconsistent_asset_payload' );
		}
		return self::file_row( $write['target_path'], $content, hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] . "\n" . $write['hash'] ) );
	}

	/** @return array<string,mixed> */
	private static function file_row( string $target_path, string $content, string $identity ): array {
		return array( 'target_path' => $target_path, 'content' => $content, 'reconciliation_identity' => $identity );
	}

	/** @param array<string,mixed> $source */
	private static function valid_source( array $source ): bool {
		return 'blocks-engine/php-transformer/compiled-site/v1' === ( $source['schema'] ?? null ) && self::hash( $source['source_hash'] ?? null ) && self::safe_path( $source['entry_path'] ?? null ) && is_array( $source['provenance'] ?? null );
	}

	/** @param array<string,mixed> $quality */
	private static function valid_quality( array $quality ): bool {
		return is_string( $quality['status'] ?? null ) && is_array( $quality['metrics'] ?? null ) && is_array( $quality['fallbacks'] ?? null );
	}

	private static function valid_load( $load ): bool {
		return is_array( $load ) && is_string( $load['placement'] ?? null ) && is_string( $load['type'] ?? null ) && is_bool( $load['defer'] ?? null ) && is_bool( $load['async'] ?? null );
	}

	private static function safe_path( $path ): bool {
		if ( ! is_string( $path ) || '' === $path || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	private static function hash( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private static function reject( array $state, string $code ): array {
		$state['diagnostics'][] = array( 'code' => $code );
		return $state;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $applied @param array<int,array<string,mixed>> $skipped @return array<string,mixed> */
	private static function receipt( string $status, array $state, array $applied, array $skipped ): array {
		return array(
			'schema'                      => self::RECEIPT_SCHEMA,
			'status'                      => $status,
			'plan_hash'                   => $state['plan_hash'],
			'source'                      => $state['source'],
			'reconciliation_identities'   => array_merge( array_column( $applied['posts'] ?? array(), 'reconciliation_identity' ), array_column( $applied['files'] ?? array(), 'reconciliation_identity' ) ),
			'wordpress'                   => array( 'posts' => $applied['posts'] ?? array() ),
			'generated_files'             => $applied['files'] ?? array(),
			'skipped_targets'             => $skipped,
			'diagnostics'                 => $state['diagnostics'],
		);
	}

	private static function plan_hash( array $plan ): string {
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $json ? '' : $json );
	}
}
