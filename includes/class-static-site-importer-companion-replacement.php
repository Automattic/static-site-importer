<?php
/**
 * Protect saved instances from incompatible generated block schemas.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Companion_Replacement {
	/**
	 * New attributes, styles and implementation updates remain ordinary refreshes.
	 * Removing/retyping/rebinding an existing attribute or removing a registration
	 * requires checking whether any saved instance still depends on that contract.
	 * This is not a JavaScript save-function compatibility checker.
	 *
	 * @param array<string,mixed> $plan Generated install plan.
	 * @return true|WP_Error
	 */
	public static function validate( array $plan ) {
		$base = rtrim( (string) $plan['base_dir'], '/\\' );
		$root = $base . '/' . $plan['slug'];
		$config_path = $root . '/companion.json';
		if ( ! file_exists( $config_path ) ) {
			return true;
		}
		$config = self::read_json( $config_path );
		if ( ! is_array( $config['block_directories'] ?? null ) ) {
			return self::unavailable( 'Existing companion inventory is unreadable.' );
		}
		foreach ( $config['block_directories'] as $directory ) {
			if ( ! is_string( $directory ) || ! preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $directory ) ) {
				return self::unavailable( 'Existing companion block directory is invalid.' );
			}
			$relative = $plan['slug'] . '/blocks/' . $directory . '/block.json';
			$before = self::read_json( $base . '/' . $relative );
			$after = isset( $plan['files'][ $relative ] ) ? json_decode( $plan['files'][ $relative ], true ) : null;
			if ( ! is_array( $before ) || ! is_string( $before['name'] ?? null ) ) {
				return self::unavailable( 'Existing block metadata is unreadable.' );
			}
			$changed = self::changed_contract( $before, $after );
			if ( empty( $changed ) ) {
				continue;
			}
			$reference = self::saved_reference( $before['name'] );
			if ( is_wp_error( $reference ) ) {
				return $reference;
			}
			if ( null !== $reference ) {
				return new WP_Error(
					'static_site_importer_companion_saved_contract_changed',
					'Saved content uses a block whose registration or existing attribute schema would change. Migrate those instances or retain their block contract.',
					array( 'block_name' => $before['name'], 'changed_contract' => $changed, 'reference' => $reference )
				);
			}
		}
		return true;
	}

	/** @return list<string> */
	private static function changed_contract( array $before, ?array $after ): array {
		if ( null === $after || ( $before['name'] ?? '' ) !== ( $after['name'] ?? '' ) ) {
			return array( 'registration' );
		}
		$changed = array();
		foreach ( $before['attributes'] ?? array() as $name => $schema ) {
			$next = $after['attributes'][ $name ] ?? null;
			if ( ! is_array( $schema ) || ! is_array( $next ) ) {
				$changed[] = 'attributes.' . $name;
				continue;
			}
			// Defaults and descriptions are authoring inputs, not type/source changes.
			unset( $schema['default'], $schema['description'], $next['default'], $next['description'] );
			if ( self::ordered_schema( $schema ) !== self::ordered_schema( $next ) ) {
				$changed[] = 'attributes.' . $name;
			}
		}
		return $changed;
	}

	/** Compare schema objects independently of key order, preserving value types. */
	private static function ordered_schema( array $schema ): array {
		if ( ! array_is_list( $schema ) ) {
			ksort( $schema );
		}
		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $key ] = self::ordered_schema( $value );
			}
		}
		return $schema;
	}

	/** @return array<string,mixed>|WP_Error|null */
	private static function saved_reference( string $name ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'parse_blocks' ) ) {
			return self::unavailable( 'WordPress saved-content lookup is unavailable.' );
		}
		$cursor = 0;
		// Include revisions, reusable blocks, navigation, and template posts as well
		// as pages. Parse candidate comments to avoid prefix/string false positives.
		for ( $batch = 0; $batch < 20; ++$batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A replacement must inspect current saved content, not a stale cached search.
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_content LIKE %s ORDER BY ID ASC LIMIT 50", $cursor, '%' . $wpdb->esc_like( 'wp:' . $name ) . '%' ), ARRAY_A );
			if ( ! is_array( $posts ) || '' !== $wpdb->last_error ) {
				return self::unavailable( 'WordPress saved-content lookup failed.' );
			}
			foreach ( $posts as $post ) {
				if ( self::contains_block( parse_blocks( $post['post_content'] ), $name ) ) {
					return array( 'post_id' => (int) $post['ID'] );
				}
				$cursor = (int) $post['ID'];
			}
			if ( count( $posts ) < 50 ) {
				if ( function_exists( 'get_block_templates' ) ) {
					foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
						foreach ( get_block_templates( array(), $type ) as $template ) {
							if ( self::contains_block( parse_blocks( $template->content ), $name ) ) {
								return array( 'template_id' => $template->id, 'type' => $type );
							}
						}
					}
				}
				return null;
			}
		}
		return self::unavailable( 'Saved-content candidate limit reached; replacement usage could not be established.' );
	}

	private static function contains_block( array $blocks, string $name ): bool {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? null ) || self::contains_block( $block['innerBlocks'] ?? array(), $name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function read_json( string $path ): ?array {
		if ( ! is_readable( $path ) || is_link( $path ) || filesize( $path ) > 1048576 ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local compiler metadata.
		$content = file_get_contents( $path );
		$value = is_string( $content ) ? json_decode( $content, true ) : null;
		return is_array( $value ) ? $value : null;
	}

	private static function unavailable( string $message ): WP_Error {
		return new WP_Error( 'static_site_importer_companion_usage_unverified', $message );
	}
}
