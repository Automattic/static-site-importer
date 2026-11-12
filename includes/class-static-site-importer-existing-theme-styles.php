<?php
/**
 * Database-only styling for imports into a host-owned theme.
 *
 * @package StaticSiteImporter
 */

/** Owns only a delimited CSS section, never the surrounding user settings. */
final class Static_Site_Importer_Existing_Theme_Styles {
	/** Stable across re-imports, independent of the host theme and post IDs. */
	public static function identity( array $state ): string {
		return substr( hash( 'sha256', (string) ( $state['args']['slug'] ?? $state['args']['import_run_id'] ?? '' ) ), 0, 24 );
	}

	/** Stable page containment boundary. */
	public static function page_class( array $state, string $source ): string {
		return 'ssi-page-' . self::identity( $state ) . '-' . substr( hash( 'sha256', $source ), 0, 12 );
	}

	/** Whether this state targets the current host theme. */
	public static function enabled( array $state ): bool {
		return 'existing_theme' === ( $state['destination']['mode'] ?? $state['args']['destination'] ?? '' );
	}

	/** Plan-time limitations are also retained in the apply receipt. */
	public static function diagnose( array &$state ): void {
		if ( ! self::enabled( $state ) ) {
			return;
		}
		$state['diagnostics'][] = array(
			'reason_code' => 'existing_theme_styles_per_theme',
			'detail'      => 'Imported Global Styles belong to the active theme and do not carry across a theme switch.',
		);
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_css' ) ) {
			$state['diagnostics'][] = array(
				'reason_code' => 'existing_theme_edit_css_required',
				'detail'      => 'Custom CSS will not survive without edit_css; apply is blocked.',
			);
		}
		foreach ( $state['resolved']['writes'] ?? array() as $write ) {
			$state['diagnostics'][] = Static_Site_Importer_Import_Destination::withheld_write_diagnostic( $state['destination'], (string) ( $write['kind'] ?? '' ), (string) $write['target_path'] );
		}
		require_once __DIR__ . '/class-static-site-importer-existing-theme-runtime.php';
		$host = Static_Site_Importer_Existing_Theme_Runtime::host();
		foreach ( $state['resolved']['pages'] ?? array() as $page ) {
			$markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
			preg_match_all( '/<!-- wp:(?!core\/)([a-z0-9-]+\/[a-z0-9-]+)/', $markup, $matches );
			foreach ( array_unique( $matches[1] ) as $name ) {
				if ( $host && str_starts_with( $name, $host['namespace'] . '/' ) ) {
					$state['diagnostics'][] = array(
						'reason_code' => in_array( substr( $name, strlen( $host['namespace'] ) + 1 ), $host['config']['block_directories'], true ) ? 'existing_theme_generated_block_reused' : 'existing_theme_generated_block_added',
						'source_path' => $page['source_path'],
						'block'       => $name,
					);
					continue;
				}
				if ( ! class_exists( 'WP_Block_Type_Registry' ) || WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
					continue;
				}
				$state['diagnostics'][] = array(
					'reason_code' => 'existing_theme_generated_block_unavailable',
					'source_path' => $page['source_path'],
					'block'       => $name,
					'detail'      => 'Page is not importable: its generated block requires registration files this destination never writes.',
				);
			}
		}
		self::stylesheet( $state );
	}

	/** Ordered document styles, including repeated A/B/A occurrences and media. */
	public static function stylesheet( array &$state ): string {
		$writes = array_column( $state['resolved']['writes'] ?? array(), null, 'target_path' );
		$css    = '';
		foreach ( $state['resolved']['pages'] ?? array() as $page ) {
			$source = (string) $page['source_path'];
			foreach ( \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::documentStylesheets( $state['plan'], $source ) as $row ) {
				$write = $writes[ $row['target_path'] ] ?? null;
				if ( ! is_array( $write ) ) {
					continue;
				}
				$bytes = Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $write, $state['payload_reader'] ?? null );
				if ( is_wp_error( $bytes ) ) {
					$state['diagnostics'][] = array(
						'reason_code' => (string) $bytes->get_error_code(),
						'source_path' => $source,
					);
					continue;
				}
				$bytes = strtr( (string) $bytes, $state['existing_theme_asset_urls'] ?? array() );
				if ( ! empty( $state['existing_theme_persisting'] ) ) {
					$bytes = \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(
						(string) $bytes,
						static function ( string $url ) use ( &$state, $source, $write ): string {
							$path = $url;
							if ( ! preg_match( '~^(?:[a-z]+:|/)~i', $url ) ) {
								$segments   = explode( '/', dirname( $write['target_path'] ) . '/' . $url );
								$normalized = array();
								foreach ( $segments as $segment ) {
									if ( '..' === $segment ) {
										array_pop( $normalized );
									} elseif ( '.' !== $segment && '' !== $segment ) {
										$normalized[] = $segment;
									}
								}
								$path = $state['theme']['uri'] . '/' . implode( '/', $normalized );
							}
							if ( isset( $state['existing_theme_asset_urls'][ $path ] ) ) {
								return $state['existing_theme_asset_urls'][ $path ];
							}
							$uploads = wp_upload_dir();
							if ( str_starts_with( $url, trailingslashit( $uploads['baseurl'] ) ) || str_starts_with( $url, '#' ) ) {
								return $url;
							}
							$state['diagnostics'][] = array(
								'reason_code' => 'existing_theme_css_asset_omitted',
								'source_path' => $source,
								'detail'      => 'CSS URL was not materialized to the Media Library; replaced with an empty URL.',
							);
							return 'data:,';
						}
					);
				}
				$scoped = self::scope( (string) $bytes, '.' . self::page_class( $state, $source ), $state['diagnostics'] );
				$media  = (string) ( $row['media'] );
				$css   .= '' !== $media && 'all' !== $media ? '@media ' . $media . '{' . $scoped . "}\n" : $scoped . "\n";
			}
		}
		return $css;
	}

	/**
	 * Parse balanced CSS rules, not braces inside strings/comments. Unknown
	 * at-rules (including fonts, imports, keyframes and property registration)
	 * are deliberately omitted rather than leaking a document-global name.
	 */
	public static function scope( string $css, string $root, array &$diagnostics ): string {
		$parser = new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer();
		$output = '';
		foreach ( $parser->splitStyleRuleBody( $css ) as $rule ) {
			if ( isset( $rule['declarations'] ) ) {
				if ( '' !== trim( $rule['declarations'] ) ) {
					$diagnostics[] = array(
						'reason_code' => 'existing_theme_css_omitted',
						'detail'      => 'Omitted top-level statements (including @import), or malformed CSS.',
					);
				}
				continue;
			}
			$name = $rule['at_rule'];
			if ( '' !== $name ) {
				if ( in_array( $name, array( 'media', 'supports', 'container' ), true ) ) {
					$output .= $rule['prelude'] . '{' . self::scope( $rule['body'], $root, $diagnostics ) . '}';
				} else {
					$diagnostics[] = array(
						'reason_code' => 'existing_theme_css_at_rule_omitted',
						'construct'   => '@' . $name,
						'detail'      => 'Omitted: only conditional @media, @supports and @container rules are carried. Fonts and global registrations are not materialized.',
					);
				}
				continue;
			}
			$selectors = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList( $rule['prelude'] );
			if ( null === $selectors ) {
				$diagnostics[] = array( 'reason_code' => 'existing_theme_css_selector_omitted' );
				continue;
			}
			$scoped = array();
			foreach ( $selectors as $selector ) {
				$selector = trim( (string) preg_replace( '~/\*.*?\*/~s', '', $selector ) );
				// Root aliases become the imported root, never a host ancestor.
				$selector = (string) preg_replace_callback( '/^(?:(?:html|body|:root)(?=[\s.#:\[>+~]|$)\s*)+/', static fn( array $alias ): string => $root . ( preg_match( '/\s$/', $alias[0] ) ? ' ' : '' ), $selector );
				if ( str_starts_with( $selector, $root ) ) {
					// Root siblings could escape the boundary. Scope the entire rule
					// under the root instead (an intentionally non-matching fallback).
					if ( preg_match( '/[+~]/', $selector ) ) {
						$diagnostics[] = array(
							'reason_code' => 'existing_theme_css_root_sibling_omitted',
							'detail'      => 'Root sibling selectors cannot be contained and are omitted.',
						);
						continue;
					}
					$scoped[] = $selector;
				} elseif ( '*' === $selector ) {
					$scoped[] = $root;
					$scoped[] = $root . ' *';
				} else {
					$scoped[] = $root . ' ' . $selector;
				}
			}
			// Nested selectors can use sibling combinators or global at-rules.
			// Until flattened, omit those rules instead of escaping containment.
			$parts = $parser->splitStyleRuleBody( $rule['body'] );
			if ( array_filter( $parts, static fn( array $part ): bool => ! isset( $part['declarations'] ) ) ) {
				$diagnostics[] = array(
					'reason_code' => 'existing_theme_css_nesting_omitted',
					'detail'      => 'Nested style rules are omitted; conditional group rules at stylesheet level are retained.',
				);
				continue;
			}
			$output .= implode( ',', $scoped ) . '{' . $rule['body'] . '}';
		}
		return $output;
	}

	/** Replace exactly our section; an empty replacement removes it. */
	public static function replace_section( string $css, string $id, string $replacement ): string {
		$start = '/* static-site-importer:' . $id . ':start */';
		$end   = '/* static-site-importer:' . $id . ':end */';
		$from  = strpos( $css, $start );
		$to    = false === $from ? false : strpos( $css, $end, $from );
		$next  = '' === $replacement ? '' : $start . "\n" . $replacement . $end;
		if ( false !== $from && false !== $to ) {
			return substr( $css, 0, $from ) . $next . substr( $css, $to + strlen( $end ) );
		}
		return $css . $next;
	}

	/** Save through the core Global Styles REST controller (including edit_css). */
	public static function save( string $id, string $css, ?int $post_id = null ) {
		$data          = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$post_id       = $post_id ?? (int) $data['ID'];
		$raw           = json_decode( get_post( $post_id )->post_content, true );
		$styles        = $raw['styles'] ?? array();
		$styles['css'] = self::replace_section( (string) ( $styles['css'] ?? '' ), $id, $css );
		$request       = new WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $post_id );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'styles', $styles );
		$controller = new WP_REST_Global_Styles_Controller();
		$allowed    = $controller->update_item_permissions_check( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$result = $controller->update_item( $request );
		WP_Theme_JSON_Resolver::clean_cached_data();
		return is_wp_error( $result ) ? $result : $post_id;
	}

	/** Remove only this import's pages and its active-theme CSS section. */
	public static function remove( string $id ) {
		if ( ! current_user_can( 'edit_css' ) || ! current_user_can( 'delete_pages' ) ) {
			// ssi-error-class: integrity
			return new WP_Error( 'existing_theme_removal_forbidden', 'Removing imported pages and CSS requires delete_pages and edit_css.' );
		}
		$ownership = get_option( 'ssi_runtime_fold_' . $id, array() );
		if ( $ownership ) {
			foreach ( $ownership['files'] as $path => $hash ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Never remove files edited since import.
				if ( ! is_file( $path ) || hash( 'sha256', (string) file_get_contents( $path ) ) !== $hash ) {
					// ssi-error-class: integrity
					return new WP_Error( 'existing_theme_runtime_block_conflict', 'Imported runtime files were edited; removal requires review.' );
				}
			}
			foreach ( $ownership['files'] as $path => $hash ) {
				wp_delete_file( $path );
			}
			$directories = array_values( $ownership['directories'] );
			usort( $directories, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			foreach ( $directories as $directory ) {
				rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only empty importer-created block directories.
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the latest shared config before removing only our entries.
			$current = json_decode( (string) file_get_contents( $ownership['config_path'] ), true );
			if ( $current === $ownership['config_after'] ) {
				$bytes = $ownership['previous_config'];
			} else {
				foreach ( array( 'block_directories', 'editor_scripts' ) as $key ) {
					foreach ( $ownership['config_after'][ $key ] ?? array() as $entry ) {
						if ( ! in_array( $entry, $ownership['config_before'][ $key ] ?? array(), true ) ) {
							$current[ $key ] = array_values( array_filter( $current[ $key ] ?? array(), static fn( $candidate ): bool => $candidate !== $entry ) );
						}
					}
				}
				$bytes = wp_json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
			}
			file_put_contents( $ownership['config_path'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore only importer-owned runtime config entries.
			delete_option( 'ssi_runtime_fold_' . $id );
		}
		$result = self::save( $id, '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_static_site_importer_css_section',
				'meta_value'     => $id,
			)
		);
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'delete_post', $post->ID ) ) {
				continue;
			}
			wp_delete_post( $post->ID, true );
		}
		return $result;
	}

	/** Database-only materialization after Media Library URL rebinding. */
	public static function apply( array &$state ): array {
		if ( ! current_user_can( 'edit_css' ) ) {
			return Static_Site_Importer_Site_Plan_Persistence::failed_receipt( $state, 'existing_theme_edit_css_required' );
		}
		$id = self::identity( $state );
		require_once __DIR__ . '/class-static-site-importer-existing-theme-runtime.php';
		if ( is_array( $state['args']['existing_theme_runtime_payload'] ?? null ) ) {
			$projection = Static_Site_Importer_Existing_Theme_Runtime::project( $state['args']['existing_theme_runtime_payload'] );
			if ( is_wp_error( $projection ) ) {
				return Static_Site_Importer_Site_Plan_Persistence::failed_receipt_from_error( $state, $projection );
			}
			if ( $projection ) {
				$config_path = $projection['host']['dir'] . '/ssi-runtime/companion.json';
				$ownership   = array(
					'files'           => array(),
					'directories'     => array(),
					'config_path'     => $config_path,
					'config_before'   => $projection['host']['config'],
					'config_after'    => $projection['config'],
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Snapshot importer-owned config for precise removal.
					'previous_config' => file_get_contents( $config_path ),
				);
				foreach ( $projection['writes'] as $write ) {
					$parent = dirname( $projection['host']['dir'] . '/' . $write['target_path'] );
					while ( ! is_dir( $parent ) && str_starts_with( $parent, $projection['host']['dir'] . '/ssi-runtime/' ) ) {
						$ownership['directories'][ $parent ]                                = $parent;
						$state['rollback']['existing_theme_runtime_directories'][ $parent ] = $parent;
						$parent = dirname( $parent );
					}
					Static_Site_Importer_Site_Plan_Persistence::journal_file( $state, $projection['host']['dir'] . '/' . $write['target_path'] );
					$result = Static_Site_Importer_Site_Plan_Persistence::write_file( $projection['host']['dir'], $write );
					if ( is_wp_error( $result ) ) {
						return Static_Site_Importer_Site_Plan_Persistence::failed_receipt_from_error( $state, $result );
					}
				}
				foreach ( $projection['writes'] as $write ) {
					if ( 'ssi-runtime/companion.json' === $write['target_path'] ) {
						continue;
					}
					$ownership['files'][ $projection['host']['dir'] . '/' . $write['target_path'] ] = $write['payload_hash'];
				}
				$state['rollback']['theme_runtime_registration'] = Static_Site_Importer_Generated_Runtime_Package::registration_snapshot( $projection['package'], $projection['host']['dir'] );
				foreach ( $projection['added'] as $block ) {
					$directory  = $projection['host']['dir'] . '/ssi-runtime/blocks/' . $block;
					$registered = register_block_type( $directory );
					if ( ! $registered ) {
						return Static_Site_Importer_Site_Plan_Persistence::failed_receipt( $state, 'static_site_importer_theme_runtime_registration_incomplete' );
					}
					$GLOBALS['static_site_importer_runtime_block_owners'][ $registered->name ] = array(
						'owner' => 'theme',
						'path'  => $projection['host']['dir'] . '/ssi-runtime/runtime.php',
					);
				}
				if ( $projection['writes'] ) {
					Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, 'ssi_runtime_fold_' . $id );
					update_option( 'ssi_runtime_fold_' . $id, $ownership );
				}
			}
		}
		$state['existing_theme_persisting'] = true;
		self::diagnose( $state );
		if ( in_array( 'existing_theme_generated_block_unavailable', array_column( $state['diagnostics'], 'reason_code' ), true ) ) {
			return Static_Site_Importer_Site_Plan_Persistence::failed_receipt( $state, 'existing_theme_generated_block_unavailable' );
		}
		$css = self::stylesheet( $state );
		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$markup                            = strtr( (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ), $state['existing_theme_asset_urls'] ?? array() );
			$class                             = self::page_class( $state, (string) $page['source_path'] );
			$page['materialized_block_markup'] = '<!-- wp:group {"className":"' . $class . '","layout":{"type":"default"}} --><div class="wp-block-group ' . $class . '">' . $markup . '</div><!-- /wp:group -->';
			Static_Site_Importer_Site_Plan_Persistence::journal_post( $state, $page );
			$post = Static_Site_Importer_Site_Plan_Persistence::materialize_page( $page, $state['source_ids'], (string) ( $state['args']['import_run_id'] ?? '' ) );
			if ( is_wp_error( $post ) ) {
				return Static_Site_Importer_Site_Plan_Persistence::failed_receipt_from_error( $state, $post );
			}
			$state['page_ids'][ $page['reconciliation_identity'] ] = $post;
			$state['source_ids'][ $page['source_path'] ]           = $post;
			foreach ( array( '_static_site_importer_reconciliation_identity', '_blocks_engine_reconciliation_identity' ) as $key ) {
				Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $post, $key, (string) $page['reconciliation_identity'] );
			}
			Static_Site_Importer_Site_Plan_Persistence::write_post_meta(
				$post,
				'_static_site_importer_provenance',
				(string) wp_json_encode(
					array(
						'schema'                  => 'static-site-importer/page-provenance/v1',
						'import_run_id'           => $state['args']['import_run_id'] ?? '',
						'source_path'             => $page['source_path'],
						'reconciliation_identity' => $page['reconciliation_identity'],
						'content_hash'            => hash( 'sha256', $page['materialized_block_markup'] ),
					)
				)
			);
			update_post_meta( $post, '_static_site_importer_css_section', $id );
			$state['rollback']['posts'][ $post ] ??= array( 'existing' => false );
			$state['applied']['posts'][]           = array(
				'id'          => $post,
				'source_path' => $page['source_path'],
			);
		}
		$links = Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $state );
		if ( is_wp_error( $links ) ) {
			return Static_Site_Importer_Site_Plan_Persistence::failed_receipt_from_error( $state, $links );
		}
		$user         = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false );
		$raw          = ! empty( $user['ID'] ) ? json_decode( get_post( $user['ID'] )->post_content, true ) : array();
		$previous_css = (string) ( $raw['styles']['css'] ?? '' );
		$start        = '/* static-site-importer:' . $id . ':start */';
		$end          = '/* static-site-importer:' . $id . ':end */';
		$from         = strpos( $previous_css, $start );
		$to           = false === $from ? false : strpos( $previous_css, $end, $from );
		$previous     = false !== $from && false !== $to ? substr( $previous_css, $from + strlen( $start ) + 1, $to - $from - strlen( $start ) - 1 ) : '';
		$saved        = self::save( $id, $css );
		if ( is_wp_error( $saved ) ) {
			return Static_Site_Importer_Site_Plan_Persistence::failed_receipt_from_error( $state, $saved );
		}
		$state['rollback']['global_styles_section'] = array(
			'id'       => $id,
			'post_id'  => $saved,
			'previous' => $previous,
		);
		$state['applied']['global_styles']          = array(
			'id'        => $saved,
			'section'   => $id,
			'per_theme' => true,
		);
		return Static_Site_Importer_Site_Plan_Receipt::receipt( 'completed', $state );
	}
}
