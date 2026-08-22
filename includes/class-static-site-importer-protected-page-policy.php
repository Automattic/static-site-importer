<?php
/**
 * Protected-page policy for canonical WordPress site-plan materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Protected_Page_Policy {
	/**
	 * Determine whether an existing page is protected from importer writes.
	 *
	 * The `static_site_importer_protected_pages` option accepts slugs, paths, or
	 * numeric post IDs. The filter lets host products inject their own policy.
	 *
	 * @param WP_Post $post Existing WordPress post.
	 * @return bool
	 */
	public static function is_protected_page( WP_Post $post ): bool {
		$protected = get_option( 'static_site_importer_protected_pages', array() );
		if ( is_string( $protected ) ) {
			$protected = preg_split( '/[\s,]+/', $protected );
		}
		if ( ! is_array( $protected ) ) {
			$protected = array();
		}

		$tokens = array_filter(
			array_map(
				static function ( $value ): string {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				$protected
			),
			static fn( string $value ): bool => '' !== $value
		);

		$path = trim( (string) get_page_uri( $post ), '/' );
		$slug = (string) $post->post_name;
		$id   = (string) $post->ID;

		$is_protected = in_array( $id, $tokens, true ) || in_array( $slug, $tokens, true ) || in_array( $path, $tokens, true ) || in_array( '/' . $path, $tokens, true );

		return (bool) apply_filters( 'static_site_importer_is_protected_page', $is_protected, $post, $tokens );
	}
}
