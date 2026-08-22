<?php
/**
 * Conservative removal of untouched WordPress installation content.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Default_Content {
	/** Capture core seed records before imported posts clear the fresh-site marker. */
	public static function discover(): array {
		$result = array(
			'eligible' => (bool) get_option( 'fresh_site', false ),
			'posts'    => array(),
			'comments' => array(),
		);
		if ( ! $result['eligible'] ) {
			return $result;
		}

		foreach (
			array(
				1 => 'post',
				2 => 'page',
			) as $id => $post_type
		) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || ! self::has_default_guid( $post, $id ) || '' !== (string) get_post_meta( $id, '_static_site_importer_provenance', true ) ) {
				continue;
			}
			$result['posts'][] = array(
				'id'          => $id,
				'fingerprint' => self::post_fingerprint( $post ),
			);
		}

		$comment = get_comment( 1 );
		if ( $comment instanceof WP_Comment && 1 === (int) $comment->comment_post_ID && 'wapuu@wordpress.example' === $comment->comment_author_email ) {
			$result['comments'][] = array(
				'id'          => 1,
				'fingerprint' => self::comment_fingerprint( $comment ),
			);
		}

		return $result;
	}

	/** Remove records that still exactly match their pre-import fingerprints. */
	public static function remove( array $discovery ): array {
		$report = array(
			'status'  => ! empty( $discovery['eligible'] ) ? 'completed' : 'skipped',
			'removed' => array(
				'posts'    => array(),
				'comments' => array(),
			),
			'skipped' => array(),
		);
		if ( empty( $discovery['eligible'] ) ) {
			$report['reason'] = 'site_not_fresh';
			return $report;
		}

		foreach ( $discovery['comments'] ?? array() as $candidate ) {
			$id      = (int) ( $candidate['id'] ?? 0 );
			$comment = get_comment( $id );
			if ( ! $comment instanceof WP_Comment || ! hash_equals( (string) ( $candidate['fingerprint'] ?? '' ), self::comment_fingerprint( $comment ) ) ) {
				$report['skipped'][] = array(
					'type'   => 'comment',
					'id'     => $id,
					'reason' => 'record_changed',
				);
				continue;
			}
			if ( wp_delete_comment( $id, true ) ) {
				$report['removed']['comments'][] = $id;
			} else {
				$report['status']    = 'partial';
				$report['skipped'][] = array(
					'type'   => 'comment',
					'id'     => $id,
					'reason' => 'delete_failed',
				);
			}
		}

		foreach ( $discovery['posts'] ?? array() as $candidate ) {
			$id   = (int) ( $candidate['id'] ?? 0 );
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! hash_equals( (string) ( $candidate['fingerprint'] ?? '' ), self::post_fingerprint( $post ) ) ) {
				$report['skipped'][] = array(
					'type'   => 'post',
					'id'     => $id,
					'reason' => 'record_changed',
				);
				continue;
			}
			if ( wp_delete_post( $id, true ) ) {
				$report['removed']['posts'][] = $id;
			} else {
				$report['status']    = 'partial';
				$report['skipped'][] = array(
					'type'   => 'post',
					'id'     => $id,
					'reason' => 'delete_failed',
				);
			}
		}

		return $report;
	}

	private static function has_default_guid( WP_Post $post, int $id ): bool {
		return str_ends_with( (string) $post->guid, 1 === $id ? '/?p=1' : '/?page_id=2' );
	}

	private static function post_fingerprint( WP_Post $post ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $post->ID, $post->post_type, $post->post_status, $post->post_name, $post->post_title, $post->post_content, $post->guid ) ) );
	}

	private static function comment_fingerprint( WP_Comment $comment ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $comment->comment_ID, $comment->comment_post_ID, $comment->comment_author, $comment->comment_author_email, $comment->comment_content ) ) );
	}
}
