<?php
/** Validates provider-owned whole-page transfers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keeps whole-page ownership separate from fragment bindings and HTML inference. */
final class Static_Site_Importer_Whole_Page_Handoff {
	public const SCHEMA = 'static-site-importer/whole-page-handoff/v1';

	/** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $results */
	public static function validate( array $pages, array $results ): array {
		$by_source = array(); $page_routes = array();
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && '' !== (string) ( $page['source_path'] ?? '' ) ) {
				$source = (string) $page['source_path'];
				$route  = self::route( $page['route']['path'] ?? '' );
				$by_source[ $source ][] = $page;
				$page_routes[ $route ][] = $page;
			}
		}
		$candidates = array();
		$diagnostics = array();
		foreach ( $pages as $page ) {
			foreach ( is_array( $page['whole_page_candidates'] ?? null ) ? $page['whole_page_candidates'] : array() as $candidate ) {
				$source = is_array( $candidate ) ? (string) ( $candidate['source_path'] ?? '' ) : '';
				$route  = is_array( $candidate ) ? self::route( $candidate['route']['path'] ?? $candidate['route_path'] ?? '' ) : '';
				$owner  = is_array( $page ) ? (string) ( $page['reconciliation_identity'] ?? '' ) : '';
				$key    = $source . "\n" . $route;
				$matching_pages = array_values( array_filter( $by_source[ $source ] ?? array(), static fn( $item ): bool => $route === self::route( $item['route']['path'] ?? '' ) ) );
				if ( ! is_array( $candidate ) || '' === $source || '' === $route || 1 !== count( $matching_pages ) || $owner !== (string) ( $candidate['page_reconciliation_identity'] ?? $candidate['reconciliation_identity'] ?? '' ) || isset( $candidates[ $key ] ) ) {
					$diagnostics[] = array( 'reason_code' => 'whole_page_candidate_invalid', 'source_path' => $source, 'route' => $route );
					continue;
				}
				$candidates[ $key ] = array( 'candidate' => $candidate, 'page' => $matching_pages[0] );
			}
		}
		$claims = array(); $routes = array(); $posts = array(); $result_keys = array(); $result_posts = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || ! in_array( $result['status'] ?? '', array( 'committed', 'completed', 'materialized', 'mapped' ), true ) ) { continue; }
			$key = (string) ( $result['source_path'] ?? '' ) . "\n" . self::route( $result['route']['path'] ?? $result['route_path'] ?? '' );
			$id  = (int) ( $result['destination_post_id'] ?? $result['post_id'] ?? 0 );
			$result_keys[ $key ] = ( $result_keys[ $key ] ?? 0 ) + 1;
			if ( $id > 0 ) { $result_posts[ $id ] = ( $result_posts[ $id ] ?? 0 ) + 1; }
		}
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || ! in_array( $result['status'] ?? '', array( 'committed', 'completed', 'materialized', 'mapped' ), true ) ) { continue; }
			$source = (string) ( $result['source_path'] ?? '');
			$route  = self::route( $result['route']['path'] ?? $result['route_path'] ?? '' );
			$key    = $source . "\n" . $route;
			$entry  = $candidates[ $key ] ?? array();
			$candidate = $entry['candidate'] ?? array(); $page = $entry['page'] ?? array();
			$id = (int) ( $result['destination_post_id'] ?? $result['post_id'] ?? 0 );
			$valid = 1 === ( $result_keys[ $key ] ?? 0 ) && 1 === ( $result_posts[ $id ] ?? 0 ) && $id > 0 && is_array( $candidate ) && is_array( $page ) && (string) ( $result['post_type'] ?? '' ) !== '' && (string) ( $result['page_reconciliation_identity'] ?? $result['reconciliation_identity'] ?? '' ) === (string) ( $page['reconciliation_identity'] ?? '' ) && (string) ( $result['declaration_id'] ?? '' ) === (string) ( $candidate['declaration_id'] ?? '' ) && (string) ( $result['row_identity'] ?? '' ) === (string) ( $candidate['row_identity'] ?? '' ) && ! isset( $routes[ $route ] ) && ! isset( $posts[ $id ] ) && self::destination_proves_document( $id, $result, $page );
			if ( ! $valid ) { $diagnostics[] = array( 'reason_code' => 'whole_page_claim_unproven', 'source_path' => $source, 'route' => $route, 'post_id' => $id ); continue; }
			$claims[ $source ] = array( 'page' => $page, 'candidate' => $candidate, 'result' => $result, 'post_id' => $id, 'route' => $route );
			$routes[ $route ] = true; $posts[ $id ] = true;
		}
		return array( 'claims' => $claims, 'diagnostics' => $diagnostics );
	}

	public static function route( $route ): string {
		if ( ! is_string( $route ) || '' === trim( $route ) || str_contains( $route, '://' ) ) { return ''; }
		return '/' . trim( preg_replace( '~/{2,}~', '/', trim( $route ) ), '/' );
	}

	private static function destination_proves_document( int $id, array $result, array $page ): bool {
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;
		$hash = (string) ( $result['content_sha256'] ?? $result['content_hash'] ?? '' );
		$markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
		return $post && 'publish' === $post->post_status && (string) $result['post_type'] === (string) $post->post_type && true === ( $result['images_preserved'] ?? false ) && '' !== $hash && hash_equals( $hash, hash( 'sha256', (string) $post->post_content ) ) && ( '' === (string) ( $result['source_content_sha256'] ?? '' ) || hash_equals( (string) $result['source_content_sha256'], hash( 'sha256', $markup ) ) );
	}
}
