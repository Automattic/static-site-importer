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

		foreach ( array( 1, 2, 3 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! self::is_core_seed( $post, $id ) || '' !== (string) get_post_meta( $id, '_static_site_importer_provenance', true ) ) {
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
		return null === self::declined_adoption_reason( $discovery, $post );
	}

	/** Explain why an occupant cannot be adopted as untouched installation content. */
	public static function declined_adoption_reason( array $discovery, WP_Post $post ): ?string {
		if ( empty( $discovery['eligible'] ) ) {
			return 'site_not_fresh';
		}
		foreach ( $discovery['posts'] ?? array() as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === (int) $post->ID ) {
				return hash_equals( (string) ( $candidate['fingerprint'] ?? '' ), self::post_fingerprint( $post ) ) ? null : 'record_changed';
			}
		}
		return self::core_seed_mismatch( $post, (int) $post->ID ) ?? 'not_discovered';
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
		return null === self::core_seed_mismatch( $post, $id );
	}

	private static function core_seed_mismatch( WP_Post $post, int $id ): ?string {
		if ( ! in_array( $id, array( 1, 2, 3 ), true ) || ( 1 === $id ? 'post' : 'page' ) !== $post->post_type || ! str_ends_with( (string) $post->guid, 1 === $id ? '/?p=1' : '/?page_id=' . $id ) ) {
			return 'not_core_seed';
		}
		if ( 3 !== $id ) {
			return null;
		}
		if ( 3 !== (int) get_option( 'wp_page_for_privacy_policy', 0 ) ) {
			return 'option_mismatch';
		}

		// wp_install_defaults() creates this draft from the localized, filterable
		// core source and assigns this exact page to the privacy-policy option.
		if ( 'draft' !== $post->post_status || __( 'Privacy Policy' ) !== $post->post_title || __( 'privacy-policy' ) !== $post->post_name || 'default' !== (string) get_post_meta( 3, '_wp_page_template', true ) ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- These seed strings belong to WordPress core's default text domain.
			return 'metadata_mismatch';
		}
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			$source = ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
			if ( ! is_readable( $source ) ) {
				return 'core_source_unavailable';
			}
			require_once $source;
		}
		// Core baked the installation home into the draft. Rebuild that exact
		// source, without relaxing the comparison for any user-authored text.
		$install_home     = substr( (string) $post->guid, 0, -strlen( '/?page_id=3' ) );
		$use_install_home = static fn () => $install_home;
		add_filter( 'option_home', $use_install_home, PHP_INT_MAX );
		try {
			$expected = WP_Privacy_Policy_Content::get_default_content();
		} finally {
			remove_filter( 'option_home', $use_install_home, PHP_INT_MAX );
		}
		return $expected === (string) $post->post_content ? null : 'content_mismatch';
	}

	private static function post_fingerprint( WP_Post $post ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $post->ID, $post->post_type, $post->post_status, $post->post_name, $post->post_title, $post->post_content, $post->guid ) ) );
	}

	private static function comment_fingerprint( WP_Comment $comment ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $comment->comment_ID, $comment->comment_post_ID, $comment->comment_author, $comment->comment_author_email, $comment->comment_content ) ) );
	}
}
