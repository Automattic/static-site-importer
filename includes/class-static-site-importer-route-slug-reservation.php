<?php
/**
 * Reserves planned route slugs against attachments created during an import.
 *
 * @package StaticSiteImporter
 */

/**
 * WordPress gives top-level hierarchical posts (pages) and attachments one slug
 * namespace: whichever is inserted first keeps the slug and the other is
 * suffixed. An import creates attachments (Media Library images, provider
 * entity images such as product photos) before it materializes pages, so an
 * attachment titled like a page would take the page's source route and the
 * page would land at `<slug>-2`.
 *
 * While a reservation is active, core treats every planned top-level route
 * slug as a bad attachment slug, so an attachment is suffixed exactly as it
 * would be beside an existing page. Flat post types (posts, products) never
 * share their namespace with attachments, so only hierarchical routes reserve.
 */
final class Static_Site_Importer_Route_Slug_Reservation {

	/**
	 * Top-level hierarchical route slugs a plan will materialize.
	 *
	 * @param array<int,mixed> $pages Planned (resolved or ordered) page rows.
	 * @return array<string,true>
	 */
	public static function slugs( array $pages ): array {
		$slugs = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$slug = (string) ( $page['slug'] ?? '' );
			$type = (string) ( $page['post_type'] ?? 'page' );
			if ( '' === $slug || ! self::shares_attachment_namespace( $type ) ) {
				continue;
			}
			// Mirror materialize_page(): a chosen parent wins; otherwise only pages
			// carry their planned route ancestry as post_parent.
			$chosen    = $page['chosen_parent_id'] ?? null;
			$top_level = null !== $chosen ? 0 === (int) $chosen : ( 'page' !== $type || '' === (string) ( $page['parent_source_path'] ?? '' ) );
			if ( $top_level ) {
				$slugs[ $slug ] = true;
			}
		}
		return $slugs;
	}

	/**
	 * Reserve the plan's route slugs until the returned release callback runs.
	 *
	 * @param array<int,mixed> $pages Planned page rows.
	 * @return callable(): void Release callback; safe to call more than once.
	 */
	public static function reserve( array $pages ): callable {
		$slugs = self::slugs( $pages );
		if ( empty( $slugs ) || ! function_exists( 'add_filter' ) ) {
			return static function (): void {};
		}
		$filter = static fn( $bad, $slug ): bool => (bool) $bad || isset( $slugs[ (string) $slug ] );
		add_filter( 'wp_unique_post_slug_is_bad_attachment_slug', $filter, 10, 2 );
		return static function () use ( $filter ): void {
			remove_filter( 'wp_unique_post_slug_is_bad_attachment_slug', $filter, 10 );
		};
	}

	/**
	 * Run a callback with the plan's route slugs reserved.
	 *
	 * @template T
	 * @param array<int,mixed> $pages    Planned page rows.
	 * @param callable(): T    $callback Work that may create attachments.
	 * @return T
	 */
	public static function during( array $pages, callable $callback ) {
		$release = self::reserve( $pages );
		try {
			return $callback();
		} finally {
			$release();
		}
	}

	private static function shares_attachment_namespace( string $post_type ): bool {
		if ( 'page' === $post_type ) {
			return true;
		}
		return 'nav_menu_item' !== $post_type && function_exists( 'is_post_type_hierarchical' ) && is_post_type_hierarchical( $post_type );
	}
}
