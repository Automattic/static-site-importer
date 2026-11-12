<?php
/** Caller-owned page choices at the preparation boundary. @package StaticSiteImporter */
final class Static_Site_Importer_Page_Choices {
	/** Resolve choices without writes; canonical source identities remain unchanged. */
	public static function prepare( array &$state ): void {
		$args           = $state['args'];
		$existing_theme = 'existing_theme' === ( $args['destination'] ?? '' );
		if ( isset( $args['post_status'] ) && ! in_array( $args['post_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			// ssi-error-class: integrity
			throw new InvalidArgumentException( 'invalid_post_status' );
		}
		$known   = array_column( $state['resolved']['pages'], 'source_path' );
		$resolve = static fn( string $path ): string => in_array( $path, $known, true ) ? $path : 'website/' . $path;
		if ( isset( $args['pages'] ) ) {
			$args['pages'] = array_map( $resolve, $args['pages'] );
		}
		$routes = array();
		foreach ( $args['page_routes'] ?? array() as $path => $choice ) {
			$routes[ $resolve( $path ) ] = $choice;
		}
		$args['page_routes'] = $routes;
		foreach ( array_merge( $args['pages'] ?? array(), array_keys( $args['page_routes'] ?? array() ) ) as $source ) {
			if ( ! in_array( $source, $known, true ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'unknown_source_page: ' . esc_html( $source ) );
			}
		}
		if ( $existing_theme ) {
			$state['diagnostics'][]          = array(
				'reason_code' => 'existing_theme_site_settings_withheld',
				'detail'      => 'Source reading settings, navigation, identity and permalink settings are not applied.',
			);
			$state['resolved']['operations'] = array();
			$state['args']['activate']       = false;
		}
		foreach ( $state['resolved']['pages'] as &$page ) {
			$source                           = $page['source_path'];
			$page['selected']                 = ! isset( $args['pages'] ) || in_array( $source, $args['pages'], true );
			$page['action']                   = $page['selected'] ? 'create' : 'skip';
			$page['original_route']           = $page['route']['path'];
			$page['materialized_post_status'] = $args['post_status'] ?? ( $existing_theme ? 'draft' : 'publish' );
			$page['post_status']              = $page['materialized_post_status'];
			$choice                           = $args['page_routes'][ $source ] ?? null;
			if ( $existing_theme && '/' === $page['route']['path'] && ! $choice ) {
				$choice = array( 'slug' => 'index' );
			}
			if ( $choice ) {
				$slug = $choice['slug'] ?? $page['slug'];
				if ( ! is_string( $slug ) || '' === $slug || sanitize_title( $slug ) !== $slug ) {
					// ssi-error-class: integrity
					throw new InvalidArgumentException( 'invalid_page_slug: ' . esc_html( $source ) );
				}
				$parent = $choice['parent'] ?? 0;
				$post   = is_int( $parent ) ? ( $parent ? get_post( $parent ) : null ) : get_page_by_path( trim( $parent, '/' ) );
				if ( $parent && ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) ) {
					// ssi-error-class: integrity
					throw new InvalidArgumentException( 'invalid_page_parent: ' . esc_html( $source ) );
				}
				$page['chosen_parent_id']   = $post ? (int) $post->ID : 0;
				$page['parent_source_path'] = '';
				$page['slug']               = $slug;
				$page['route']['path']      = '/' . ( $post ? trim( (string) get_page_uri( $post ), '/' ) . '/' : '' ) . $slug . '/';
			}
			if ( ! $page['selected'] ) {
				$page['skip_materialization'] = true;
			}
		}
		unset( $page );
		foreach ( $state['resolved']['pages'] as $target ) {
			if ( $target['selected'] ) {
				continue;
			}
			$route = $target['route']['path'];
			if ( Static_Site_Importer_Route_Occupant::find( $route, 'page' ) ) {
				continue;
			}
			foreach ( $state['resolved']['pages'] as $page ) {
				if ( $page['selected'] && ( str_contains( $page['resolved_block_markup'] ?? '', $target['source_path'] ) || str_contains( $page['resolved_block_markup'] ?? '', 'href="' . $target['original_route'] . '"' ) ) ) {
					$state['diagnostics'][] = array(
						'reason_code'        => 'unselected_source_page_link',
						'source_path'        => $page['source_path'],
						'target_source_path' => $target['source_path'],
						'route'              => $route,
					);
				}
			}
		}
	}

	/** Compact public projection, shared by plan and receipt. */
	public static function rows( array $pages ): array {
		return array_map(
			static function ( array $page ): array {
				$row = array(
					'source_path' => preg_replace( '~^website/~', '', $page['source_path'] ),
					'selected'    => $page['selected'] ?? true,
					'route'       => $page['route']['path'],
					'post_status' => $page['post_status'] ?? 'publish',
					'action'      => $page['action'] ?? 'create',
				);
				foreach ( array( 'occupying_post', 'suggested_slug' ) as $field ) {
					if ( isset( $page[ $field ] ) ) {
						$row[ $field ] = $page[ $field ];
					}
				}
				return $row;
			},
			$pages
		);
	}
}
