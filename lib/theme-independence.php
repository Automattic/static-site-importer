<?php
/**
 * Theme-independence outcome collector.
 *
 * Execute inside the imported WordPress runtime; return JSON, not rendered
 * output. The caller (see docs/existing-runtime-acceptance.md, "Theme
 * independence evidence") owns transport, exactly as for
 * lib/outcome-inventory.php. The snapshot records every fact #2430 assigns to
 * a native WordPress owner — content, routes, redirects, menus, media, site
 * title, tagline, language, SEO fields — so the outcome check can deep-compare
 * owners across a theme switch. The active theme itself is the one fact allowed
 * to change; the check normalizes it for the switched phase and requires it
 * back exactly after restore.
 *
 * @package StaticSiteImporter
 */

if ( ! function_exists( 'ssi_theme_owner_snapshot' ) ) {
	/**
	 * Snapshot every #2430 native owner of the imported site.
	 *
	 * @return array<string,mixed> Theme-owner snapshot, schema
	 * static-site-importer/theme-owner-snapshot/v1.
	 */
	function ssi_theme_owner_snapshot() {
		$pages = array();
		foreach ( get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => array_values( get_post_stati() ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		) as $post ) {
			$permalink = get_permalink( $post );
			if ( '' === (string) $permalink ) {
				throw new RuntimeException( esc_html( "Post {$post->ID} has no permalink to snapshot." ) );
			}
			// Every meta row is an owner surface: route SEO fields (Jetpack SEO
			// title/description), featured image binding and import provenance
			// all live here. A theme-owned override cannot dodge the snapshot.
			$pages[] = array(
				'id'      => (int) $post->ID,
				'type'    => $post->post_type,
				'status'  => $post->post_status,
				'title'   => $post->post_title,
				'excerpt' => $post->post_excerpt,
				'content' => $post->post_content,
				'url'     => $permalink,
				'meta'    => get_post_meta( $post->ID ),
			);
		}
		$menus = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		) as $menu ) {
			$menus[ (int) $menu->ID ] = array(
				'title'   => $menu->post_title,
				'status'  => $menu->post_status,
				'content' => $menu->post_content,
			);
		}
		$media = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		) as $attachment ) {
			$file = get_attached_file( $attachment->ID );
			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				throw new RuntimeException( esc_html( "Attachment {$attachment->ID} has no file bytes to snapshot." ) );
			}
			$media[ (int) $attachment->ID ] = array(
				'url'  => wp_get_attachment_url( $attachment->ID ),
				'hash' => hash_file( 'sha256', $file ),
			);
		}
		// Native redirect ownership lives in the redirection materializer's
		// ownership option (its rules are journaled inside it). The literal key
		// keeps this collector loadable without the materializer class in scope.
		return array(
			'schema'    => 'static-site-importer/theme-owner-snapshot/v1',
			'pages'     => $pages,
			'menus'     => (object) $menus,
			'media'     => (object) $media,
			'redirects' => (object) array(
				// Keys stay stable whether or not the native redirect provider
				// is installed; ownership presence itself is an owned fact.
				'ownership' => get_option( 'static_site_importer_redirect_ownership', null ),
			),
			'settings'  => (object) array(
				'blogname'            => get_option( 'blogname' ),
				'blogdescription'     => get_option( 'blogdescription' ),
				'site_icon'           => get_option( 'site_icon' ),
				'WPLANG'              => get_option( 'WPLANG' ),
				'locale'              => get_locale(),
				'show_on_front'       => get_option( 'show_on_front' ),
				'page_on_front'       => get_option( 'page_on_front' ),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'active_theme'        => array(
					'stylesheet' => get_stylesheet(),
					'template'   => get_template(),
				),
			),
		);
	}
}

if ( ! function_exists( 'ssi_theme_independence_activate' ) ) {
	/**
	 * Switch to the probed default core theme, remembering the imported origin.
	 *
	 * @param string $theme Theme directory name to activate.
	 * @return void
	 */
	function ssi_theme_independence_activate( $theme ) {
		if ( ! preg_match( '/^[a-z0-9_-]+$/i', $theme ) || ! wp_get_theme( $theme )->exists() ) {
			throw new RuntimeException( esc_html( "Theme {$theme} is not installed." ) );
		}
		update_option(
			'ssi_theme_independence_origin',
			array(
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
			)
		);
		switch_theme( $theme );
		flush_rewrite_rules();
	}
}

if ( ! function_exists( 'ssi_theme_independence_restore' ) ) {
	/**
	 * Restore the imported theme recorded by ssi_theme_independence_activate().
	 *
	 * A missing origin marker is a no-op returning false: activation never
	 * happened, so there is nothing to restore and no failure to report.
	 *
	 * @return bool True when the imported theme was restored.
	 */
	function ssi_theme_independence_restore() {
		$origin = get_option( 'ssi_theme_independence_origin', null );
		if ( ! is_array( $origin ) || empty( $origin['stylesheet'] ) ) {
			return false;
		}
		switch_theme( (string) $origin['stylesheet'] );
		delete_option( 'ssi_theme_independence_origin' );
		flush_rewrite_rules();
		return true;
	}
}
