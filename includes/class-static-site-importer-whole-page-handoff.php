<?php
/**
 * Provider-owned source documents and verified whole-page ownership.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Whole_Page_Handoff {

	public const SCHEMA            = 'static-site-importer/whole-page-handoff/v1';
	private const CANDIDATE_SCHEMA = 'blocks-engine/whole-page-candidate/v1';

	/** Give providers only source documents authorized by the canonical producer. */
	public static function documents( array $pages ): array {
		$documents = array();
		$parents   = array_filter( array_column( $pages, 'parent_source_path' ) );
		foreach ( $pages as $page ) {
			$candidates = $page['whole_page_candidates'] ?? array();
			if ( 1 !== count( $candidates ) || ! self::candidate_matches_page( $candidates[0], $page ) ) {
				continue;
			}
			$candidate = $candidates[0];
			$markup    = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
			if ( '' === $markup || '/' === $candidate['source_route'] || ! empty( $page['synthetic'] ) || ! empty( $page['skip_materialization'] ) || in_array( $page['source_path'], $parents, true ) ) {
				continue;
			}
			$declaration = $candidate['declaration_reconciliation_identity'];
			$entity      = $candidate['entity_id'];
			if ( isset( $documents[ $declaration ][ $entity ] ) ) {
				throw new InvalidArgumentException( 'Whole-page source documents must be unambiguous.' );
			}
			$documents[ $declaration ][ $entity ] = $candidate + array(
				'post_content'   => $markup,
				'content_sha256' => hash( 'sha256', $markup ),
			);
		}
		return $documents;
	}

	/** Verify canonical identities and the actual persisted document before route mutation. */
	public static function validate( array $pages, array $results ): array {
		$claims      = array();
		$diagnostics = array();
		$by_source   = array();
		$routes      = array();
		$posts       = array();
		foreach ( $pages as $page ) {
			$by_source[ (string) ( $page['source_path'] ?? '' ) ][] = $page;
		}
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || self::SCHEMA !== ( $result['schema'] ?? null ) || 'committed' !== ( $result['status'] ?? null ) ) {
				$diagnostics[] = array( 'reason_code' => 'whole_page_claim_invalid' );
				continue;
			}
			$source           = (string) ( $result['source_path'] ?? '' );
			$route            = (string) ( $result['source_route'] ?? '' );
			$id               = (int) ( $result['destination_post_id'] ?? 0 );
			$routes[ $route ] = ( $routes[ $route ] ?? 0 ) + 1;
			$posts[ $id ]     = ( $posts[ $id ] ?? 0 ) + 1;
			$matching         = $by_source[ $source ] ?? array();
			$page             = 1 === count( $matching ) ? $matching[0] : array();
			$candidates       = $page['whole_page_candidates'] ?? array();
			$candidate        = 1 === count( $candidates ) ? $candidates[0] : array();
			$valid            = self::candidate_matches_page( $candidate, $page ) && '/' !== $route && $id > 0;
			foreach ( array( 'source_path', 'source_route', 'page_reconciliation_identity', 'declaration_reconciliation_identity', 'entity_id' ) as $field ) {
				$valid = $valid && ( $result[ $field ] ?? null ) === ( $candidate[ $field ] ?? null );
			}
			$post   = $id > 0 ? get_post( $id ) : null;
			$markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
			$valid  = $valid && $post && 'publish' === $post->post_status && ( $result['post_type'] ?? '' ) === $post->post_type
				&& '' !== $markup && hash_equals( hash( 'sha256', $markup ), hash( 'sha256', (string) $post->post_content ) );
			if ( ! $valid || isset( $claims[ $source ] ) ) {
				$diagnostics[] = array(
					'reason_code' => 'whole_page_claim_unproven',
					'source_path' => $source,
				);
				continue;
			}
			$claims[ $source ] = array(
				'page'      => $page,
				'candidate' => $candidate,
				'result'    => $result,
				'post_id'   => $id,
				'route'     => $route,
			);
		}
		foreach ( $claims as $source => $claim ) {
			if ( 1 !== $routes[ $claim['route'] ] || 1 !== $posts[ $claim['post_id'] ] ) {
				unset( $claims[ $source ] );
				$diagnostics[] = array(
					'reason_code' => 'whole_page_claim_ambiguous',
					'source_path' => $source,
				);
			}
		}
		return array(
			'claims'      => $claims,
			'diagnostics' => $diagnostics,
		);
	}

	private static function candidate_matches_page( array $candidate, array $page ): bool {
		if ( self::CANDIDATE_SCHEMA !== ( $candidate['schema'] ?? null ) ) {
			return false;
		}
		foreach ( array( 'source_path', 'source_route', 'page_reconciliation_identity', 'declaration_reconciliation_identity', 'entity_id' ) as $field ) {
			if ( ! is_string( $candidate[ $field ] ?? null ) || '' === $candidate[ $field ] ) {
				return false;
			}
		}
		return ( $page['source_path'] ?? null ) === $candidate['source_path']
			&& ( $page['route']['path'] ?? null ) === $candidate['source_route']
			&& ( $page['reconciliation_identity'] ?? null ) === $candidate['page_reconciliation_identity'];
	}
}
