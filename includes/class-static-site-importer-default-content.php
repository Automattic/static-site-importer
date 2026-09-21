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

		foreach ( array( 1 => 'post', 2 => 'page', 3 => 'page' ) as $id => $post_type ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || ! self::is_core_seed( $post, $id ) || '' !== (string) get_post_meta( $id, '_static_site_importer_provenance', true ) ) {
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

	/** Whether a discovered seed remains safe to materialize through the normal existing-post lifecycle. */
	public static function is_untouched_seed( array $discovery, WP_Post $post ): bool {
		if ( empty( $discovery['eligible'] ) ) {
			return false;
		}
		foreach ( $discovery['posts'] ?? array() as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === (int) $post->ID && hash_equals( (string) ( $candidate['fingerprint'] ?? '' ), self::post_fingerprint( $post ) ) ) {
				return true;
			}
		}
		return false;
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

	private static function is_core_seed( WP_Post $post, int $id ): bool {
		if ( ! str_ends_with( (string) $post->guid, 1 === $id ? '/?p=1' : '/?page_id=' . $id ) ) {
			return false;
		}
		if ( 3 !== $id ) {
			return true;
		}

		// wp_install_defaults() creates this draft from the localized, filterable
		// core source and assigns this exact page to the privacy-policy option.
		if ( 3 !== (int) get_option( 'wp_page_for_privacy_policy', 0 ) || 'draft' !== $post->post_status || __( 'Privacy Policy' ) !== $post->post_title || __( 'privacy-policy' ) !== $post->post_name || 'default' !== (string) get_post_meta( 3, '_wp_page_template', true ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			$source = ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
			if ( ! is_readable( $source ) ) {
				return false;
			}
			require_once $source;
		}
		return (string) $post->post_content === WP_Privacy_Policy_Content::get_default_content();
	}

	private static function post_fingerprint( WP_Post $post ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $post->ID, $post->post_type, $post->post_status, $post->post_name, $post->post_title, $post->post_content, $post->guid ) ) );
	}

	private static function comment_fingerprint( WP_Comment $comment ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $comment->comment_ID, $comment->comment_post_ID, $comment->comment_author, $comment->comment_author_email, $comment->comment_content ) ) );
	}
}
