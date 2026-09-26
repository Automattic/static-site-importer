<?php
/**
 * Persist plan navigation entities as deterministic wp_navigation posts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Navigation_Entity_Materializer {
	public const TOKEN_PREFIX = '{{wordpress-site-plan:navigation:';
	public const META_KEY     = '_static_site_importer_reconciliation_identity';

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array &$state ) {
		$menus = array();
		if ( isset( $state['resolved']['menus'] ) && is_array( $state['resolved']['menus'] ) ) {
			$menus = $state['resolved']['menus'];
		} elseif ( isset( $state['plan']['menus'] ) && is_array( $state['plan']['menus'] ) ) {
			$menus = $state['plan']['menus'];
		}
		$entities = array();
		foreach ( $menus as $menu ) {
			if ( ! is_array( $menu ) || ! is_string( $menu['token'] ?? null ) || ! preg_match( '/^navigation-[a-f0-9]{16}$/', $menu['token'] ) || ! is_string( $menu['block_markup'] ?? null ) || ! is_string( $menu['reconciliation_identity'] ?? null ) ) {
				continue;
			}
			$entities[] = $menu;
		}
		if ( array() === $entities ) {
			return $state;
		}
		if ( function_exists( 'post_type_exists' ) && ! post_type_exists( 'wp_navigation' ) ) {
			return $state;
		}

		if ( ! isset( $state['applied']['navigation_entities'] ) || ! is_array( $state['applied']['navigation_entities'] ) ) {
			$state['applied']['navigation_entities'] = array();
		}
		$refs       = array();
		$signatures = array();
		foreach ( $entities as $menu ) {
			$content = (string) $menu['block_markup'];
			$id      = self::upsert( $menu, $content, $state );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$refs[ (string) $menu['token'] ] = (int) $id;
			$signature                       = self::destination_signature( $content );
			if ( '' !== $signature ) {
				$signatures[ $signature ] = (int) $id;
			}
			$state['applied']['navigation_entities'][] = array(
				'id'                      => (int) $id,
				'token'                   => (string) $menu['token'],
				'reconciliation_identity' => (string) $menu['reconciliation_identity'],
			);
		}

		if ( isset( $state['resolved']['writes'] ) && is_array( $state['resolved']['writes'] ) ) {
			foreach ( $state['resolved']['writes'] as &$write ) {
				if ( ! is_array( $write ) || 'utf8' !== ( $write['payload']['encoding'] ?? null ) || ! is_string( $write['payload']['data'] ?? null ) ) {
					continue;
				}
				$rewritten = self::rewrite_markup( (string) $write['payload']['data'], $refs, $signatures );
				if ( $rewritten === $write['payload']['data'] ) {
					continue;
				}
				$write['payload']['data'] = $rewritten;
				$write['payload_hash']    = hash( 'sha256', $rewritten );
			}
			unset( $write );
		}

		foreach ( array( 'template_parts', 'pages' ) as $group ) {
			if ( ! isset( $state['resolved'][ $group ] ) || ! is_array( $state['resolved'][ $group ] ) ) {
				continue;
			}
			foreach ( $state['resolved'][ $group ] as &$document ) {
				foreach ( array( 'resolved_block_markup', 'canonical_block_markup', 'materialized_block_markup' ) as $field ) {
					if ( is_string( $document[ $field ] ?? null ) ) {
						$document[ $field ] = self::rewrite_markup( $document[ $field ], $refs, $signatures );
					}
				}
			}
			unset( $document );
		}

		foreach ( $state['applied']['posts'] ?? array() as $post ) {
			$id = (int) ( $post['id'] ?? 0 );
			if ( $id <= 0 || ! function_exists( 'get_post_field' ) ) {
				continue;
			}
			$content = get_post_field( 'post_content', $id );
			if ( ! is_string( $content ) ) {
				continue;
			}
			$rewritten = self::rewrite_markup( $content, $refs, $signatures );
			if ( $rewritten === $content ) {
				continue;
			}
			$updated = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $rewritten ),
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		return $state;
	}

	/**
	 * @param array<string,int> $refs
	 * @param array<string,int> $signatures
	 */
	public static function rewrite_markup( string $content, array $refs, array $signatures = array() ): string {
		$content = self::rewrite_references( $content, $refs );
		return self::rewrite_matching( $content, $signatures );
	}

	/**
	 * @param array<string,int> $refs
	 */
	public static function rewrite_references( string $content, array $refs ): string {
		if ( array() === $refs || ! str_contains( $content, self::TOKEN_PREFIX ) ) {
			return $content;
		}
		foreach ( $refs as $token => $id ) {
			$content = str_replace( '"ref":"' . self::TOKEN_PREFIX . $token . '}}"', '"ref":' . (int) $id, $content );
		}
		return $content;
	}

	/**
	 * @param array<string,int> $signatures
	 */
	public static function rewrite_matching( string $content, array $signatures ): string {
		if ( array() === $signatures ) {
			return $content;
		}
		$edits = array();
		foreach ( self::navigation_blocks( $content ) as $block ) {
			$id = $signatures[ self::destination_signature( $block['inner'] ) ] ?? null;
			if ( ! is_int( $id ) ) {
				continue;
			}
			$attrs        = $block['attrs'];
			$attrs['ref'] = $id;
			$encoded      = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs );
			if ( ! is_string( $encoded ) ) {
				continue;
			}
			$edits[] = array(
				'offset'      => $block['offset'],
				'length'      => $block['length'],
				'replacement' => '<!-- wp:navigation ' . $encoded . ' /-->',
			);
		}
		usort( $edits, static fn( array $left, array $right ): int => $right['offset'] <=> $left['offset'] );
		foreach ( $edits as $edit ) {
			$content = substr( $content, 0, $edit['offset'] ) . $edit['replacement'] . substr( $content, $edit['offset'] + $edit['length'] );
		}
		return $content;
	}

	public static function destination_signature( string $inner ): string {
		$items  = array();
		$offset = 0;
		while ( preg_match( '/<!--\s*wp:navigation-(?:link|submenu)\s*/', $inner, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start   = $match[0][1];
			$open_end = strpos( $inner, '-->', $start );
			if ( false === $open_end ) {
				break;
			}
			$opening = substr( $inner, $start, $open_end + 3 - $start );
			$attrs   = array();
			if ( preg_match( '/\{.*\}/s', $opening, $json ) ) {
				$decoded = json_decode( $json[0], true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}
			$items[] = (string) ( $attrs['label'] ?? '' ) . "\t" . self::destination_path( (string) ( $attrs['url'] ?? '' ) );
			$offset  = $open_end + 3;
		}
		return implode( "\n", $items );
	}

	private static function destination_path( string $url ): string {
		$path = preg_replace( '/#.*$/s', '', $url );

		return is_string( $path ) ? $path : $url;
	}

	/**
	 * @return array<int,array{offset:int,length:int,inner:string,attrs:array<string,mixed>}>
	 */
	private static function navigation_blocks( string $markup ): array {
		$blocks = array();
		if ( ! preg_match_all( '/<!--\s*(\/?)wp:.*?-->/s', $markup, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $blocks;
		}
		$ranges = array();
		$stack  = array();
		foreach ( $matches[0] as $match ) {
			$token  = $match[0];
			$offset = $match[1];
			if ( str_starts_with( $token, '<!-- /wp:' ) ) {
				$open = array_pop( $stack );
				if ( is_array( $open ) ) {
					$ranges[ $open['index'] ]['length'] = $offset + strlen( $token ) - $open['offset'];
				}
			} elseif ( str_ends_with( rtrim( $token ), '/-->' ) ) {
				$ranges[] = array( 'offset' => $offset, 'length' => strlen( $token ) );
			} else {
				$index    = count( $ranges );
				$ranges[] = array( 'offset' => $offset, 'length' => 0 );
				$stack[]  = array( 'index' => $index, 'offset' => $offset );
			}
		}
		foreach ( $ranges as $range ) {
			if ( ( $range['length'] ?? 0 ) < 1 ) {
				continue;
			}
			$block = substr( $markup, $range['offset'], $range['length'] );
			if ( ! preg_match( '/^<!--\s*wp:navigation(?!-)/', $block ) ) {
				continue;
			}
			$open_end = strpos( $block, '-->' );
			if ( false === $open_end ) {
				continue;
			}
			$opening = substr( $block, 0, $open_end + 3 );
			if ( preg_match( '/\/\s*-->$/', $opening ) ) {
				continue;
			}
			$attrs = array();
			if ( preg_match( '/\{.*\}/s', $opening, $json ) ) {
				$decoded = json_decode( $json[0], true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}
			if ( isset( $attrs['ref'] ) ) {
				continue;
			}
			$inner_end = strrpos( $block, '<!-- /wp:navigation' );
			if ( false === $inner_end ) {
				continue;
			}
			$blocks[] = array(
				'offset' => $range['offset'],
				'length' => $range['length'],
				'inner'  => substr( $block, $open_end + 3, $inner_end - ( $open_end + 3 ) ),
				'attrs'  => $attrs,
			);
		}
		return $blocks;
	}

	/**
	 * @param array<string,mixed> $menu
	 * @param array<string,mixed> $state
	 * @return int|WP_Error
	 */
	private static function upsert( array $menu, string $content, array &$state ) {
		$identity = (string) $menu['reconciliation_identity'];
		$existing = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $identity );
		$title    = trim( (string) ( $menu['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = 'Navigation';
		}
		$slug    = function_exists( 'sanitize_title' ) ? sanitize_title( (string) ( $menu['target_slug'] ?? $title ) ) : strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) ( $menu['target_slug'] ?? $title ) ) );
		$postarr = array(
			'post_title'   => $title,
			'post_name'    => '' !== $slug ? $slug : 'navigation',
			'post_status'  => 'publish',
			'post_type'    => 'wp_navigation',
			'post_content' => function_exists( 'wp_slash' ) ? wp_slash( $content ) : $content,
		);
		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = (int) $existing->ID;
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		if ( ! isset( $postarr['ID'] ) ) {
			$state['rollback']['posts'][ $id ] = array( 'existing' => false );
		}
		if ( ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, self::META_KEY, $identity ) ) {
			return new WP_Error( 'navigation_entity_metadata_write_failed' );
		}
		return $id;
	}
}
