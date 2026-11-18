<?php
/** Opt-in, byte-preserving host navigation additions. @package StaticSiteImporter */
final class Static_Site_Importer_Existing_Theme_Navigation {
	/** Resolve only an unambiguous published navigation in a block theme. */
	private static function resolve(): int {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return 0;
		}
		$header = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		$refs   = array();
		$walk   = static function ( array $blocks ) use ( &$walk, &$refs ): void {
			foreach ( $blocks as $block ) {
				if ( 'core/navigation' === $block['blockName'] ) {
					$refs[] = (int) ( $block['attrs']['ref'] ?? 0 );
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $header->content ?? '' ) );
		if ( $refs ) {
			$refs = array_values( array_unique( $refs ) );
			$post = 1 === count( $refs ) && $refs[0] ? get_post( $refs[0] ) : null;
			return $post && 'wp_navigation' === $post->post_type && 'publish' === $post->post_status ? (int) $post->ID : 0;
		}
		$posts = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 2,
			)
		);
		return 1 === count( $posts ) ? (int) $posts[0]->ID : 0;
	}

	/** Public dry-run projection; new page IDs are assigned only on apply. */
	public static function prepare( array &$state ): void {
		if ( ! Static_Site_Importer_Existing_Theme_Styles::enabled( $state ) || true !== ( $state['args']['navigation'] ?? false ) ) {
			return;
		}
		$id                  = self::resolve();
		$state['navigation'] = array(
			'post_id' => $id,
			'links'   => array(),
		);
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			$state['navigation']['post_id'] = 0;
			$state['diagnostics'][]         = array( 'reason_code' => 'existing_theme_navigation_unresolved' );
			return;
		}
		$ownership = get_option( 'ssi_navigation_' . Static_Site_Importer_Existing_Theme_Styles::identity( $state ), array() );
		$links     = array();
		foreach ( $state['resolved']['pages'] as $page ) {
			if ( empty( $page['selected'] ) || ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			if ( 'publish' !== $page['post_status'] ) {
				$state['diagnostics'][] = array(
					'reason_code' => 'existing_theme_navigation_skipped_unpublished',
					'source_path' => $page['source_path'],
				);
				continue;
			}
			$owned = $ownership[ $id ][ $page['source_path'] ] ?? '';
			if ( $owned && str_contains( get_post( $id )->post_content, $owned ) ) {
				continue;
			}
			$links[] = array(
				'source_path' => $page['source_path'],
				'label'       => $page['title'],
				'kind'        => 'post-type',
				'type'        => 'page',
				'id'          => $page['planned_existing_id'] ?? null,
			);
		}
		$state['navigation'] = array(
			'post_id' => $id,
			'links'   => $links,
		);
	}

	/** Append raw blocks, journaling both post content and ownership for rollback. */
	public static function apply( array &$state ): void {
		if ( true !== ( $state['args']['navigation'] ?? false ) ) {
			return;
		}
		self::prepare( $state );
		$report = $state['navigation'] ?? array();
		$id     = $report['post_id'] ?? 0;
		if ( ! $id || empty( $report['links'] ) ) {
			$state['applied']['navigation'] = $report;
			return;
		}
		$key       = 'ssi_navigation_' . Static_Site_Importer_Existing_Theme_Styles::identity( $state );
		$ownership = get_option( $key, array() );
		$content   = get_post( $id )->post_content;
		$added     = array();
		foreach ( $report['links'] as $link ) {
			$post_id = $state['source_ids'][ $link['source_path'] ] ?? 0;
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}
			$link['id']   = $post_id;
			$attrs        = array_intersect_key( $link, array_flip( array( 'label', 'kind', 'type', 'id' ) ) );
			$attrs['url'] = get_permalink( $post_id );
			// Unique ownership disambiguates even an identical owner-created link.
			$attrs['metadata']                        = array( 'ssiNavigationOwner' => wp_generate_uuid4() );
			$bytes                                    = serialize_block(
				array(
					'blockName'    => 'core/navigation-link',
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
			$content                                 .= $bytes;
			$ownership[ $id ][ $link['source_path'] ] = $bytes;
			$added[]                                  = $link;
		}
		Static_Site_Importer_Site_Plan_Persistence::journal_post( $state, array( 'planned_existing_id' => $id ) );
		Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, $key );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			$state['diagnostics'][] = array( 'reason_code' => 'existing_theme_navigation_unresolved' );
			$added                  = array();
		} else {
			update_option( $key, $ownership );
		}
		$state['applied']['navigation'] = array(
			'post_id' => $id,
			'links'   => $added,
		);
	}

	/** Remove only unchanged owned block bytes, never reserialize owner content. */
	public static function remove( string $section ) {
		$key       = 'ssi_navigation_' . $section;
		$ownership = get_option( $key, array() );
		foreach ( $ownership as $id => $links ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				// ssi-error-class: integrity
				return new WP_Error( 'existing_theme_navigation_removal_forbidden', 'Cannot edit the owned navigation.' );
			}
		}
		foreach ( $ownership as $id => $links ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$content = $post->post_content;
			foreach ( $links as $bytes ) {
				$offset = strpos( $content, $bytes );
				if ( false !== $offset ) {
					$content = substr_replace( $content, '', $offset, strlen( $bytes ) );
				}
			}
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_content' => $content,
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		delete_option( $key );
		return true;
	}
}
