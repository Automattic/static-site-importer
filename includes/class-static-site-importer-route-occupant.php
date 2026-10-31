<?php
/**
 * Type-restricted route lookup for conflict planning.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Route_Occupant {
	/**
	 * Find an occupant only among the requested post types.
	 *
	 * Core's scalar get_page_by_path lookup also searches attachments. Never
	 * adopt that fallback as a page or post during route reconciliation.
	 *
	 * @param string          $path Route path.
	 * @param string|string[] $post_types Allowed post types.
	 * @return WP_Post|null
	 */
	public static function find( string $path, $post_types ): ?WP_Post {
		$post = get_page_by_path( trim( $path, '/' ), OBJECT, $post_types );
		return $post instanceof WP_Post && in_array( $post->post_type, (array) $post_types, true ) ? $post : null;
	}
}
