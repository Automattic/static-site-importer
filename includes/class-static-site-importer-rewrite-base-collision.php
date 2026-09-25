<?php
/**
 * Keeps imported page routes reachable when they collide with a core rewrite base.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_Rewrite puts taxonomy permastructs in extra_rules_top, ahead of the page
 * rules, and the author and search rules also run before them. A page at
 * `category/<slug>` is therefore routed to the category archive and 404s.
 *
 * Core lets a site move the category and tag bases with the `category_base`
 * and `tag_base` options (Settings > Permalinks), so a colliding base is moved
 * to a free path and the source URL keeps pointing at the imported page. A
 * taxonomy the destination already uses keeps its base, so established archive
 * URLs never change without the owner's say. The author, search, and
 * post-format bases have no option. A page left under any base is reported
 * instead of silently 404ing.
 */
final class Static_Site_Importer_Rewrite_Base_Collision {
	/** Core taxonomies whose rewrite base an option can move. */
	private const MOVABLE_BASES = array(
		'category' => 'category_base',
		'post_tag' => 'tag_base',
	);

	/**
	 * Plan a new base for each movable base that shadows an imported page path.
	 *
	 * @param string[] $page_paths Imported page paths, as get_page_uri() returns them.
	 * @return array<string,array{option:string,from:string,to:string,value:string}> Keyed by taxonomy.
	 */
	public static function planned_moves( array $page_paths ): array {
		global $wp_rewrite;
		$moves = array();
		foreach ( self::MOVABLE_BASES as $taxonomy => $option ) {
			$base = self::static_prefix( $wp_rewrite->get_extra_permastruct( $taxonomy ) );
			if ( '' === $base || ! self::shadows( $base, $page_paths ) || self::in_use( $taxonomy ) ) {
				continue;
			}
			$target = self::free_base( $base, $page_paths );
			if ( '' !== $target ) {
				$moves[ $taxonomy ] = array(
					'option' => $option,
					'from'   => $base,
					'to'     => $target,
					// Stored the way Settings > Permalinks stores it.
					'value'  => '/' . $target,
				);
			}
		}
		return $moves;
	}

	/**
	 * Move the planned bases and regenerate the rewrite rules in this request.
	 *
	 * @param array<string,array{option:string,from:string,to:string,value:string}> $moves From planned_moves().
	 */
	public static function apply( array $moves ): bool {
		global $wp_rewrite;
		if ( array() === $moves ) {
			return true;
		}
		foreach ( $moves as $move ) {
			if ( 'category_base' === $move['option'] ) {
				$wp_rewrite->set_category_base( $move['value'] );
			} else {
				$wp_rewrite->set_tag_base( $move['value'] );
			}
			// Reads strip the leading slash (_wp_filter_taxonomy_base()).
			if ( trim( (string) get_option( $move['option'] ), '/' ) !== $move['to'] ) {
				return false;
			}
		}
		// The taxonomies registered their permastructs at init from the old
		// options; re-register them so the flushed rules use the new bases.
		create_initial_taxonomies();
		// WP_Rewrite::rewrite_rules() accumulates permastruct rules in
		// extra_rules_top, so rules built earlier in this request under an
		// old base would otherwise survive the flush and keep shadowing pages.
		foreach ( $moves as $move ) {
			$wp_rewrite->extra_rules_top = array_filter(
				$wp_rewrite->extra_rules_top,
				static fn( $regex ): bool => ! str_starts_with( (string) $regex, $move['from'] . '/' ),
				ARRAY_FILTER_USE_KEY
			);
		}
		flush_rewrite_rules( false );
		return true;
	}

	/**
	 * Whether any term of the taxonomy has published posts, so its archive URLs are live.
	 *
	 * The default category is ignored: every site has it, and a fresh site's
	 * sample post alone does not make the category archives established.
	 */
	private static function in_use( string $taxonomy ): bool {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true, // Term counts cover published posts only.
				'exclude'    => 'category' === $taxonomy ? array( (int) get_option( 'default_category' ) ) : array(),
				'fields'     => 'ids',
				'number'     => 1,
			)
		);
		return is_array( $terms ) && array() !== $terms;
	}

	/**
	 * Report imported pages that a core rewrite base still shadows after any moves.
	 *
	 * @param string[] $page_paths Imported page paths.
	 * @return array<int,array<string,string>>
	 */
	public static function shadowed_route_diagnostics( array $page_paths ): array {
		global $wp_rewrite;
		$taxonomy_detail = ' The taxonomy already has published posts, so its base was kept; the site owner can change it in Settings > Permalinks.';
		$fixed_detail    = ' Core has no option to move that base.';
		$bases           = array(
			'category'    => array( self::static_prefix( $wp_rewrite->get_extra_permastruct( 'category' ) ), $taxonomy_detail ),
			'tag'         => array( self::static_prefix( $wp_rewrite->get_extra_permastruct( 'post_tag' ) ), $taxonomy_detail ),
			'author'      => array( self::static_prefix( $wp_rewrite->get_author_permastruct() ), $fixed_detail ),
			'search'      => array( self::static_prefix( $wp_rewrite->get_search_permastruct() ), $fixed_detail ),
			'post_format' => array( self::static_prefix( $wp_rewrite->get_extra_permastruct( 'post_format' ) ), $fixed_detail ),
		);
		$diagnostics     = array();
		foreach ( $bases as $kind => list( $base, $detail ) ) {
			if ( '' === $base ) {
				continue;
			}
			foreach ( $page_paths as $path ) {
				if ( str_starts_with( $path, $base . '/' ) ) {
					$diagnostics[] = array(
						'reason_code' => 'page_route_shadowed_by_core_rewrite',
						'severity'    => 'warning',
						'rewrite'     => $kind,
						'target_path' => $path,
						'detail'      => 'WordPress routes this path to its ' . $kind . ' rewrite before page rules.' . $detail,
					);
				}
			}
		}
		return $diagnostics;
	}

	/** The literal path before the first rewrite tag, e.g. `category` for `/category/%category%`. */
	private static function static_prefix( $struct ): string {
		if ( ! is_string( $struct ) || ! str_contains( $struct, '%' ) ) {
			return '';
		}
		return trim( substr( $struct, 0, (int) strpos( $struct, '%' ) ), '/' );
	}

	/** @param string[] $page_paths */
	private static function shadows( string $base, array $page_paths ): bool {
		foreach ( $page_paths as $path ) {
			if ( str_starts_with( $path, $base . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param string[] $page_paths */
	private static function free_base( string $base, array $page_paths ): string {
		for ( $n = 1; $n <= 100; ++$n ) {
			$candidate = $base . '-archive' . ( $n > 1 ? '-' . $n : '' );
			if ( null === get_page_by_path( $candidate ) && ! in_array( $candidate, $page_paths, true ) && ! self::shadows( $candidate, $page_paths ) ) {
				return $candidate;
			}
		}
		return '';
	}
}
