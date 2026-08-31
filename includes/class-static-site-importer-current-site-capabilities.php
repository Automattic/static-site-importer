<?php
/**
 * Current-site materialization capability checks.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Current_Site_Capabilities {
	/** @return bool|WP_Error */
	public static function check_plan( array $state ): bool|WP_Error {
		if ( self::is_cli() ) {
			return true;
		}

		$required = array();
		foreach ( $state['resolved']['writes'] ?? array() as $write ) {
			if ( is_array( $write ) ) {
				if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'static_site_importer_theme_materialization' ) ) {
					return new WP_Error( 'static_site_importer_file_modification_forbidden', 'WordPress file modification policy does not allow theme materialization.' );
				}
				$required[] = array( 'capability' => 'install_themes' );
				break;
			}
		}
		foreach ( $state['ordered_pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) || ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$existing_id = (int) ( $page['planned_existing_id'] ?? 0 );
			if ( $existing_id > 0 ) {
				$required[] = array(
					'capability' => 'edit_post',
					'args'       => array( $existing_id ),
				);
				continue;
			}
			$type = function_exists( 'get_post_type_object' ) ? get_post_type_object( (string) ( $page['post_type'] ?? 'page' ) ) : null;
			if ( ! $type ) {
				return new WP_Error( 'static_site_importer_capability_plan_invalid', 'The materialization plan has an unknown post type.' );
			}
			$cap = $type->cap;
			if ( ! isset( $cap->create_posts, $cap->publish_posts ) ) {
				return new WP_Error( 'static_site_importer_capability_plan_invalid', 'The materialization plan has incomplete post type capabilities.' );
			}
			$required[] = array( 'capability' => (string) $cap->create_posts );
			$required[] = array( 'capability' => (string) $cap->publish_posts );
		}

		$args = is_array( $state['args'] ?? null ) ? $state['args'] : array();
		if ( ! empty( $args['remove_default_content'] ) ) {
			foreach ( $state['default_content']['posts'] ?? array() as $post ) {
				$required[] = array(
					'capability' => 'delete_post',
					'args'       => array( (int) ( $post['id'] ?? 0 ) ),
				);
			}
			if ( ! empty( $state['default_content']['comments'] ) ) {
				$required[] = array( 'capability' => 'moderate_comments' );
			}
		}
		if ( ! empty( $args['activate'] ) ) {
			$required[] = array( 'capability' => 'switch_themes' );
			if ( ! empty( $state['resolved']['operations'] ) || '' !== trim( (string) ( $args['site_title'] ?? '' ) ) || ! isset( $args['disable_smilies'] ) || false !== (bool) $args['disable_smilies'] ) {
				$required[] = array( 'capability' => 'manage_options' );
			}
		}

		return self::check( $required );
	}

	/** @return bool|WP_Error */
	public static function check_plugin_install( bool $activate, bool $install = true ): bool|WP_Error {
		if ( self::is_cli() ) {
			return true;
		}
		if ( $install && function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'static_site_importer_plugin_materialization' ) ) {
			return new WP_Error( 'static_site_importer_file_modification_forbidden', 'WordPress file modification policy does not allow plugin materialization.' );
		}
		$required = $install ? array( array( 'capability' => 'install_plugins' ) ) : array();
		if ( $activate ) {
			$required[] = array( 'capability' => 'activate_plugins' );
		}
		return self::check( $required );
	}

	/** @param array<int,array{capability:string,args?:array<int,mixed>}> $required @return bool|WP_Error */
	private static function check( array $required ): bool|WP_Error {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}
		foreach ( $required as $requirement ) {
			$capability = $requirement['capability'];
			$args       = $requirement['args'] ?? array();
			if ( '' !== $capability && ! current_user_can( $capability, ...$args ) ) {
				return new WP_Error( 'static_site_importer_capability_forbidden', sprintf( 'Current-site materialization requires the %s capability.', $capability ), array( 'capability' => $capability ) );
			}
		}
		return true;
	}

	private static function is_cli(): bool {
		return defined( 'WP_CLI' );
	}
}
