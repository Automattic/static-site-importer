<?php
/**
 * Planned custom page templates during materialization.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Offers a plan's own custom templates to core's template registry.
 *
 * Pages are written and re-saved before the planned theme is installed and
 * activated, and wp_update_post() validates `_wp_page_template` against the
 * active theme's templates. The templates the plan both ships and assigns are
 * the ones the incoming theme registers, so they are added through core's
 * `theme_templates` filter for exactly the materialization window.
 */
final class Static_Site_Importer_Planned_Page_Templates {

	/**
	 * Run a callback with the plan's assigned custom templates registered.
	 *
	 * @template T
	 * @param mixed           $plan     WordPress site plan.
	 * @param callable(): T   $callback Materialization.
	 * @return T
	 */
	public static function during( $plan, callable $callback ) {
		$planned = self::assigned( $plan );
		if ( array() === $planned ) {
			return $callback();
		}
		$register = static function ( array $templates, $theme, $post, string $post_type ) use ( $planned ): array {
			unset( $theme, $post );
			return $templates + ( $planned[ $post_type ] ?? array() );
		};
		add_filter( 'theme_templates', $register, 10, 4 );
		try {
			return $callback();
		} finally {
			remove_filter( 'theme_templates', $register, 10 );
		}
	}

	/**
	 * Custom templates the plan both ships and assigns, keyed by post type.
	 *
	 * @param mixed $plan WordPress site plan.
	 * @return array<string,array<string,string>>
	 */
	public static function assigned( $plan ): array {
		if ( ! is_array( $plan ) ) {
			return array();
		}
		$shipped = array();
		foreach ( is_array( $plan['templates'] ?? null ) ? $plan['templates'] : array() as $template ) {
			if ( is_array( $template ) && is_string( $template['slug'] ?? null ) && '' !== $template['slug'] ) {
				$shipped[ $template['slug'] ] = is_string( $template['title'] ?? null ) && '' !== $template['title'] ? $template['title'] : $template['slug'];
			}
		}
		$assigned = array();
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			$slug      = is_array( $page ) ? ( $page['metadata']['post_meta']['_wp_page_template'] ?? null ) : null;
			$post_type = is_array( $page ) && is_string( $page['post_type'] ?? null ) ? $page['post_type'] : 'page';
			if ( is_string( $slug ) && isset( $shipped[ $slug ] ) ) {
				$assigned[ $post_type ][ $slug ] = $shipped[ $slug ];
			}
		}
		return $assigned;
	}
}
