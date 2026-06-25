<?php
/**
 * Theme file materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes generated theme artifacts to disk.
 */
class Static_Site_Importer_Theme_Materializer {

	/**
	 * Build static generated theme file writes.
	 *
	 * @param string $theme_dir       Theme directory.
	 * @param string $theme_slug      Theme slug.
	 * @param string $theme_name      Theme name.
	 * @param string $css             Source CSS.
	 * @param bool   $has_header_part Whether a header template part exists.
	 * @param bool   $has_footer_part Whether a footer template part exists.
	 * @param array<int,array<string,mixed>> $scripts     Materialized script asset rows.
	 * @param array<int,array<string,mixed>> $stylesheets Materialized stylesheet asset rows.
	 * @return array<string,string> Absolute write paths mapped to file contents.
	 */
	public static function base_theme_writes( string $theme_dir, string $theme_slug, string $theme_name, string $css, bool $has_header_part, bool $has_footer_part, array $scripts = array(), array $stylesheets = array() ): array {
		return array(
			$theme_dir . '/functions.php'             => self::functions_php( $theme_slug, $scripts, $stylesheets ),
			$theme_dir . '/theme.json'                => self::theme_json( $theme_name, $css ),
			$theme_dir . '/templates/front-page.html' => self::content_template( '', $has_header_part, $has_footer_part ),
			$theme_dir . '/templates/page.html'       => self::content_template( '', $has_header_part, $has_footer_part ),
			$theme_dir . '/templates/index.html'      => self::content_template( '', $has_header_part, $has_footer_part ),
		);
	}

	/**
	 * Build a template that renders imported page content.
	 *
	 * @param string $background_blocks Background decoration blocks.
	 * @param bool   $has_header_part   Whether a shared header template part was generated.
	 * @param bool   $has_footer_part   Whether a shared footer template part was generated.
	 * @return string
	 */
	public static function content_template( string $background_blocks, bool $has_header_part, bool $has_footer_part ): string {
		$header_part = $has_header_part ? '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' : '';
		$footer_part = $has_footer_part ? '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' : '';

		return trim(
			$header_part . "\n\n" .
			$background_blocks . "\n\n" .
			'<!-- wp:post-content {"tagName":"main"} /-->' . "\n\n" .
			$footer_part
		) . "\n";
	}

	/**
	 * Build a theme pattern file for an imported page body.
	 *
	 * @param string $title        Pattern title.
	 * @param string $pattern_slug Pattern slug.
	 * @param string $content      Block markup.
	 * @return string
	 */
	public static function pattern_file( string $title, string $pattern_slug, string $content ): string {
		return "<?php\n" .
			"/**\n" .
			' * Title: ' . $title . "\n" .
			' * Slug: ' . $pattern_slug . "\n" .
			" * Categories: static-site-importer\n" .
			" */\n" .
			"?>\n" .
			trim( $content ) . "\n";
	}

	/**
	 * Ensure theme directories exist.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return true|WP_Error
	 */
	public static function ensure_dirs( string $theme_dir ) {
		foreach ( array( $theme_dir, $theme_dir . '/templates', $theme_dir . '/parts', $theme_dir . '/patterns', $theme_dir . '/assets', $theme_dir . '/assets/css', $theme_dir . '/assets/icons', $theme_dir . '/assets/media' ) as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'static_site_importer_mkdir_failed', sprintf( 'Failed to create directory: %s', $dir ) );
			}
		}

		return true;
	}

	/**
	 * Write compiler-emitted files that can be consumed without re-importing HTML.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param string              $theme_uri Theme URI.
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @param bool                $write_files Whether to write materialized asset files.
	 * @return array{css:string,js:string,assets:array<string,array<string,mixed>>,scripts:array<int,array<string,mixed>>,stylesheets:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}|WP_Error
	 */
	public static function materialize_website_artifact_files( string $theme_dir, string $theme_uri, array $artifacts, bool $write_files = true ) {
		$native_plan = self::materialize_materialization_plan_assets( $theme_dir, $theme_uri, $artifacts, $write_files );
		if ( is_wp_error( $native_plan ) || null !== $native_plan ) {
			return $native_plan;
		}

		$files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$css         = array();
		$assets      = array();
		$diagnostics = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$relative = self::normalize_artifact_materialization_path( isset( $file['path'] ) ? (string) $file['path'] : '' );
			$retention = self::source_retention_policy( $file, $relative, 'website_artifact:files' );
			if ( '' === $relative ) {
				$diagnostics[] = array(
					'type'    => 'website_artifact_file_skipped',
					'source'  => 'website_artifact:files',
					'reason'  => 'unsafe_artifact_path',
					'path'    => isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '',
					'message' => 'A website artifact file was skipped because its path is not safe to materialize inside the generated theme.',
				);
				continue;
			}
			if ( isset( $retention['diagnostic'] ) ) {
				$diagnostics[] = $retention['diagnostic'];
			}

			$content = self::materialization_plan_asset_content( $file, $relative );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$kind  = isset( $file['kind'] ) ? (string) $file['kind'] : '';
			$lower = strtolower( $relative );
			if ( 'css' === $kind || str_ends_with( $lower, '.css' ) ) {
				$css[] = trim( $content );
				continue;
			}
			if ( 'js' === $kind || str_ends_with( $lower, '.js' ) ) {
				$js[] = trim( $content );
				continue;
			}

			$target_relative = 'assets/materialized/' . $relative;
			$target          = trailingslashit( $theme_dir ) . $target_relative;
			if ( $write_files ) {
				$dir = dirname( $target );
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error( 'static_site_importer_artifact_asset_mkdir_failed', sprintf( 'Failed to create website artifact asset directory: %s', $dir ) );
				}

				$result = self::write_file( $target, $content );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$assets[ $relative ] = array(
				'source'     => $relative,
				'path'       => $relative,
				'url'        => trailingslashit( $theme_uri ) . $target_relative,
				'final_url'  => trailingslashit( $theme_uri ) . $target_relative,
				'mime_type'  => self::mime_type( $target ),
				'theme_path' => $target_relative,
				'policy'     => 'theme',
				'source_role'      => $retention['source_role'],
				'keep_source'      => $retention['keep_source'],
				'deletion_allowed' => $retention['deletion_allowed'],
			);
		}

		return array(
			'css'         => trim( implode( "\n\n", array_filter( $css ) ) ),
			'js'          => '',
			'assets'      => $assets,
			'scripts'     => array(),
			'stylesheets' => array(),
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Write native Blocks Engine asset materialization rows when the plan carries payloads.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param string              $theme_uri Theme URI.
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @param bool                $write_files Whether to write materialized asset files.
	 * @return array{css:string,js:string,assets:array<string,array<string,mixed>>,scripts:array<int,array<string,mixed>>,stylesheets:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}|null|WP_Error
	 */
	private static function materialize_materialization_plan_assets( string $theme_dir, string $theme_uri, array $artifacts, bool $write_files = true ) {
		$site = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' !== (string) ( $site['schema'] ?? '' ) || ! array_key_exists( 'assets', $site ) ) {
			return null;
		}

		if ( ! is_array( $site['assets'] ) ) {
			return new WP_Error( 'static_site_importer_materialization_plan_assets_invalid', 'Blocks Engine materialization_plan.assets must be an array.' );
		}

		if ( ! self::materialization_plan_assets_include_payloads( $site['assets'] ) ) {
			return null;
		}

		$css         = array();
		$assets      = array();
		$scripts     = array();
		$stylesheets = array();
		$diagnostics = array();
		$order       = 0;

		foreach ( $site['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_asset_invalid', 'Blocks Engine materialization_plan.assets entries must be arrays.' );
			}

			$relative = self::normalize_artifact_materialization_path( isset( $asset['path'] ) && is_scalar( $asset['path'] ) ? (string) $asset['path'] : '' );
			$retention = self::source_retention_policy( $asset, $relative, 'materialization_plan.assets' );
			if ( '' === $relative ) {
				return new WP_Error( 'static_site_importer_materialization_plan_asset_path_invalid', 'Blocks Engine materialization_plan.assets entries must include safe relative paths.' );
			}
			if ( isset( $retention['diagnostic'] ) ) {
				$diagnostics[] = $retention['diagnostic'];
			}

			$content = self::materialization_plan_asset_content( $asset, $relative );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			$kind  = isset( $asset['kind'] ) && is_scalar( $asset['kind'] ) ? (string) $asset['kind'] : '';
			$role  = isset( $asset['role'] ) && is_scalar( $asset['role'] ) ? (string) $asset['role'] : '';
			$lower = strtolower( $relative );
			if ( 'html' === $kind || str_ends_with( $lower, '.html' ) || str_ends_with( $lower, '.htm' ) ) {
				continue;
			}
			++$order;

			$target_relative = 'assets/materialized/' . $relative;
			$target          = trailingslashit( $theme_dir ) . $target_relative;
			if ( $write_files ) {
				$dir = dirname( $target );
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error( 'static_site_importer_materialization_plan_asset_mkdir_failed', sprintf( 'Failed to create materialization-plan asset directory: %s', $dir ) );
				}

				$result = self::write_file( $target, $content );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$assets[ $relative ] = self::materialization_plan_asset_report( $asset, $relative, trailingslashit( $theme_uri ) . $target_relative, $target_relative, self::mime_type( $target ), $order, $retention );

			if ( 'css' === $kind || 'stylesheet' === $role || str_ends_with( $lower, '.css' ) ) {
				$css[] = array(
					'path'    => $relative,
					'content' => trim( $content ),
				);
				continue;
			}
			if ( 'js' === $kind || 'script' === $role || str_ends_with( $lower, '.js' ) ) {
				$scripts[] = $assets[ $relative ];
				continue;
			}
		}

		$font_stylesheets = self::materialize_font_materialization_stylesheets( $theme_dir, $theme_uri, $site, $assets, $order, $write_files );
		if ( is_wp_error( $font_stylesheets ) ) {
			return $font_stylesheets;
		}
		$stylesheets = array_merge( $stylesheets, $font_stylesheets );

		return array(
			'css'         => trim( implode( "\n\n", array_filter( self::rewrite_materialized_css_chunks( $css, $assets ) ) ) ),
			'js'          => '',
			'assets'      => $assets,
			'scripts'     => $scripts,
			'stylesheets' => $stylesheets,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Write theme font materialization stylesheet rows declared by Blocks Engine.
	 *
	 * @param string                            $theme_dir Theme directory.
	 * @param string                            $theme_uri Theme URI.
	 * @param array<string,mixed>               $site      Materialization plan site artifact.
	 * @param array<string,array<string,mixed>> $assets    Materialized asset rows keyed by source path.
	 * @param int                               $order     Current materialization order.
	 * @param bool                               $write_files Whether to write materialized asset files.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function materialize_font_materialization_stylesheets( string $theme_dir, string $theme_uri, array $site, array &$assets, int &$order, bool $write_files = true ) {
		$theme = isset( $site['theme'] ) && is_array( $site['theme'] ) ? $site['theme'] : array();
		$plan  = isset( $theme['font_materialization'] ) && is_array( $theme['font_materialization'] ) ? $theme['font_materialization'] : array();
		if ( 'blocks-engine/php-transformer/font-materialization-plan/v1' !== (string) ( $plan['schema'] ?? '' ) ) {
			return array();
		}

		$rows = isset( $plan['stylesheets'] ) && is_array( $plan['stylesheets'] ) ? $plan['stylesheets'] : array();
		if ( empty( $rows ) && isset( $plan['css'] ) && is_scalar( $plan['css'] ) && '' !== trim( (string) $plan['css'] ) ) {
			$rows[] = array(
				'path'      => 'assets/css/fonts.css',
				'role'      => 'stylesheet',
				'mime_type' => 'text/css',
				'content'   => trim( (string) $plan['css'] ) . "\n",
			);
		}

		$stylesheets = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_stylesheet_invalid', 'Blocks Engine font_materialization.stylesheets entries must be arrays.' );
			}

			$relative = self::normalize_artifact_materialization_path( isset( $row['path'] ) && is_scalar( $row['path'] ) ? (string) $row['path'] : '' );
			if ( '' === $relative ) {
				return new WP_Error( 'static_site_importer_font_materialization_stylesheet_path_invalid', 'Blocks Engine font_materialization.stylesheets entries must include safe relative paths.' );
			}

			$content = isset( $row['content'] ) && is_scalar( $row['content'] ) ? (string) $row['content'] : '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$target = trailingslashit( $theme_dir ) . $relative;
			if ( $write_files ) {
				$dir = dirname( $target );
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error( 'static_site_importer_font_materialization_stylesheet_mkdir_failed', sprintf( 'Failed to create font materialization stylesheet directory: %s', $dir ) );
				}

				$result = self::write_file( $target, $content );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			++$order;
			$asset               = array_merge(
				$row,
				array(
					'path'   => $relative,
					'role'   => isset( $row['role'] ) && is_scalar( $row['role'] ) ? (string) $row['role'] : 'stylesheet',
					'kind'   => isset( $row['kind'] ) && is_scalar( $row['kind'] ) ? (string) $row['kind'] : 'css',
					'origin' => 'theme.font_materialization',
				)
			);
			$retention           = self::source_retention_policy( $asset, $relative, 'theme.font_materialization' );
			$assets[ $relative ] = self::materialization_plan_asset_report( $asset, $relative, trailingslashit( $theme_uri ) . $relative, $relative, self::mime_type( $target ), $order, $retention );
			$stylesheets[]       = $assets[ $relative ];
		}

		return $stylesheets;
	}

	/**
	 * Build an SSI asset report row while preserving native Blocks Engine metadata.
	 *
	 * @param array<string,mixed> $asset           Blocks Engine asset row.
	 * @param string              $relative        Safe source-relative path.
	 * @param string              $url             Materialized theme URL.
	 * @param string              $target_relative Materialized theme-relative path.
	 * @param string              $fallback_mime   MIME type inferred from written path.
	 * @param int                 $order           Native asset order.
	 * @param array<string,mixed> $retention       Source retention policy.
	 * @return array<string,mixed>
	 */
	private static function materialization_plan_asset_report( array $asset, string $relative, string $url, string $target_relative, string $fallback_mime, int $order, array $retention = array() ): array {
		$mime_type = isset( $asset['mime_type'] ) && is_scalar( $asset['mime_type'] ) && '' !== (string) $asset['mime_type'] ? (string) $asset['mime_type'] : $fallback_mime;
		$origin    = isset( $asset['origin'] ) && is_scalar( $asset['origin'] ) && '' !== (string) $asset['origin'] ? (string) $asset['origin'] : 'materialization_plan.assets';
		if ( empty( $retention ) ) {
			$retention = self::source_retention_policy( $asset, $relative, $origin );
		}
		$report    = array(
			'source'     => $relative,
			'path'       => $relative,
			'url'        => $url,
			'final_url'  => $url,
			'mime_type'  => $mime_type,
			'theme_path' => $target_relative,
			'policy'     => 'theme',
			'origin'     => $origin,
			'order'      => $order,
			'source_role'      => $retention['source_role'],
			'keep_source'      => $retention['keep_source'],
			'deletion_allowed' => $retention['deletion_allowed'],
		);

		foreach ( array( 'role', 'kind', 'type', 'media', 'as', 'crossorigin', 'integrity', 'placement', 'source_hash' ) as $key ) {
			if ( isset( $asset[ $key ] ) && is_scalar( $asset[ $key ] ) && '' !== (string) $asset[ $key ] ) {
				$report[ $key ] = (string) $asset[ $key ];
			}
		}

		foreach ( array( 'defer', 'async' ) as $key ) {
			if ( array_key_exists( $key, $asset ) ) {
				$report[ $key ] = (bool) $asset[ $key ];
			}
		}

		return $report;
	}

	/**
	 * Resolve source retention semantics for materialized artifact payloads.
	 *
	 * Canonical sources are retained unless the row explicitly declares an ephemeral/importer-owned source role.
	 *
	 * @param array<string,mixed> $source Source artifact row.
	 * @param string              $path   Source-relative path.
	 * @param string              $origin Source origin label for diagnostics.
	 * @return array{source_role:string,keep_source:bool,deletion_allowed:bool,diagnostic?:array<string,mixed>}
	 */
	private static function source_retention_policy( array $source, string $path, string $origin ): array {
		$source_role = isset( $source['source_role'] ) && is_scalar( $source['source_role'] ) && '' !== trim( (string) $source['source_role'] ) ? strtolower( preg_replace( '/[^a-z0-9_-]+/i', '_', trim( (string) $source['source_role'] ) ) ?? '' ) : 'canonical';
		if ( '' === $source_role ) {
			$source_role = 'canonical';
		}
		$keep_source = array_key_exists( 'keep_source', $source ) ? (bool) $source['keep_source'] : true;
		$ephemeral   = in_array( $source_role, array( 'ephemeral', 'importer_owned', 'importer-owned', 'temporary', 'temp' ), true );

		$policy = array(
			'source_role'      => $source_role,
			'keep_source'      => $keep_source || ! $ephemeral,
			'deletion_allowed' => $ephemeral && ! $keep_source,
		);

		if ( ! $keep_source && ! $ephemeral ) {
			$policy['diagnostic'] = array(
				'type'             => 'website_artifact_source_retention_guard',
				'severity'         => 'warning',
				'source'           => $origin,
				'source_path'      => $path,
				'path'             => $path,
				'source_role'      => $source_role,
				'keep_source'      => true,
				'deletion_allowed' => false,
				'reason'           => 'canonical_source_retained',
				'message'          => 'Website artifact source was retained because destructive source handling requires source_role=ephemeral or source_role=importer_owned.',
			);
		}

		return $policy;
	}

	/**
	 * Rewrite CSS url(...) references to materialized theme URLs.
	 *
	 * @param array<int,array{path:string,content:string}> $chunks CSS chunks and their source paths.
	 * @param array<string,array<string,mixed>>             $assets Materialized asset rows keyed by source path.
	 * @return array<int,string>
	 */
	private static function rewrite_materialized_css_chunks( array $chunks, array $assets ): array {
		$rewritten = array();
		foreach ( $chunks as $chunk ) {
			$rewritten[] = self::rewrite_materialized_css_urls( $chunk['content'], $chunk['path'], $assets );
		}

		return $rewritten;
	}

	/**
	 * Rewrite one CSS payload's relative url(...) references.
	 *
	 * @param string                            $css             CSS payload.
	 * @param string                            $stylesheet_path Source-relative stylesheet path.
	 * @param array<string,array<string,mixed>> $assets          Materialized asset rows keyed by source path.
	 * @return string
	 */
	private static function rewrite_materialized_css_urls( string $css, string $stylesheet_path, array $assets ): string {
		if ( '' === trim( $css ) || empty( $assets ) ) {
			return $css;
		}

		$stylesheet_dir = dirname( $stylesheet_path );
		return preg_replace_callback(
			'/url\(\s*(["\']?)([^)"\']+)\1\s*\)/i',
			static function ( array $matches ) use ( $assets, $stylesheet_dir ): string {
				$url = trim( (string) $matches[2] );
				if ( '' === $url || str_starts_with( $url, '#' ) || str_starts_with( strtolower( $url ), 'data:' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) || str_starts_with( $url, '/' ) ) {
					return $matches[0];
				}

				$candidate = self::resolve_materialized_relative_url( $stylesheet_dir, $url );
				if ( '' === $candidate || ! isset( $assets[ $candidate ]['final_url'] ) || ! is_scalar( $assets[ $candidate ]['final_url'] ) ) {
					return $matches[0];
				}

				return 'url(' . $matches[1] . (string) $assets[ $candidate ]['final_url'] . $matches[1] . ')';
			},
			$css
		) ?? $css;
	}

	/**
	 * Resolve a relative asset URL against a source stylesheet path.
	 *
	 * @param string $base_dir Source stylesheet directory.
	 * @param string $url      Relative CSS URL.
	 * @return string Safe source-relative path, or empty string when it escapes the artifact root.
	 */
	private static function resolve_materialized_relative_url( string $base_dir, string $url ): string {
		$path     = ( '.' === $base_dir ? '' : trim( $base_dir, '/' ) . '/' ) . strtok( $url, '?#' );
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( empty( $segments ) ) {
					return '';
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return self::normalize_artifact_materialization_path( implode( '/', $segments ) );
	}

	/**
	 * Check whether native asset rows include write payloads.
	 *
	 * @param array<int,mixed> $assets Blocks Engine materialization-plan asset rows.
	 * @return bool
	 */
	private static function materialization_plan_assets_include_payloads( array $assets ): bool {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && ( array_key_exists( 'content', $asset ) || array_key_exists( 'content_base64', $asset ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode a native materialization-plan asset payload.
	 *
	 * @param array<string,mixed> $asset    Blocks Engine asset row.
	 * @param string              $relative Safe relative asset path.
	 * @return string|WP_Error
	 */
	private static function materialization_plan_asset_content( array $asset, string $relative ) {
		if ( isset( $asset['content_base64'] ) ) {
			if ( ! is_scalar( $asset['content_base64'] ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_asset_content_invalid', sprintf( 'Blocks Engine materialization_plan.assets content_base64 must be scalar: %s', $relative ) );
			}

			$base64 = preg_replace( '/\s+/', '', (string) $asset['content_base64'] ) ?? '';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared artifact payload content, not executable code.
			$decoded = base64_decode( $base64, true );
			if ( false === $decoded ) {
				return new WP_Error( 'static_site_importer_materialization_plan_asset_base64_invalid', sprintf( 'Blocks Engine materialization_plan.assets content_base64 is not valid base64: %s', $relative ) );
			}

			return $decoded;
		}

		if ( isset( $asset['content'] ) && is_scalar( $asset['content'] ) ) {
			return (string) $asset['content'];
		}

		return new WP_Error( 'static_site_importer_materialization_plan_asset_content_missing', sprintf( 'Blocks Engine materialization_plan.assets entries must include content or content_base64: %s', $relative ) );
	}

	/**
	 * Normalize template part artifacts into generated theme writes.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @return array{writes:array<string,string>,reports:array<int,array<string,mixed>>}|WP_Error Absolute write paths and report rows.
	 */
	public static function template_part_artifact_writes( string $theme_dir, array $artifacts ) {
		$template_parts = self::template_part_artifacts_from_materialization_plan( $artifacts );
		if ( is_wp_error( $template_parts ) ) {
			return $template_parts;
		}

		if ( null === $template_parts ) {
			$template_parts = self::navigation_template_parts_from_materialization_plan( $artifacts );
			if ( is_wp_error( $template_parts ) ) {
				return $template_parts;
			}
		}

		if ( null === $template_parts ) {
			$template_parts = isset( $artifacts['template_parts'] ) && is_array( $artifacts['template_parts'] ) ? $artifacts['template_parts'] : array();
		}

		$writes  = array();
		$reports = array();
		foreach ( $template_parts as $template_part ) {
			if ( ! is_array( $template_part ) ) {
				return new WP_Error( 'static_site_importer_template_part_invalid', 'Template part artifacts must be arrays.' );
			}

			$relative = self::template_part_artifact_relative_path( $template_part );
			if ( '' === $relative ) {
				return new WP_Error( 'static_site_importer_template_part_unsupported', 'Template part artifacts must resolve to a supported header or footer theme part.' );
			}

			if ( ! isset( $template_part['block_markup'] ) || ! is_scalar( $template_part['block_markup'] ) ) {
				return new WP_Error( 'static_site_importer_template_part_markup_missing', 'Template part artifacts must include serialized block_markup.' );
			}

			$markup = (string) $template_part['block_markup'];
			if ( '' === trim( $markup ) ) {
				return new WP_Error( 'static_site_importer_template_part_markup_empty', 'Template part artifact block_markup must not be empty.' );
			}

			$writes[ trailingslashit( $theme_dir ) . $relative ] = $markup;
			$reports[] = self::template_part_artifact_report_payload( $relative, $template_part, $markup );
		}

		return array(
			'writes'  => $writes,
			'reports' => $reports,
		);
	}

	/**
	 * Read generic WordPress template-part writes from a Blocks Engine materialization plan.
	 *
	 * @param array<string,mixed> $artifacts WordPress artifacts from the transformer adapter.
	 * @return array<int,array<string,mixed>>|null|WP_Error Template part artifacts, null when no plan write list exists.
	 */
	private static function template_part_artifacts_from_materialization_plan( array $artifacts ) {
		$site = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' !== (string) ( $site['schema'] ?? '' ) || ! array_key_exists( 'template_part_writes', $site ) ) {
			return null;
		}

		if ( ! is_array( $site['template_part_writes'] ) ) {
			return new WP_Error( 'static_site_importer_materialization_plan_template_part_writes_invalid', 'Blocks Engine materialization_plan.template_part_writes must be an array.' );
		}

		$template_parts = array();
		foreach ( $site['template_part_writes'] as $write ) {
			if ( ! is_array( $write ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_template_part_write_invalid', 'Blocks Engine template part write entries must be arrays.' );
			}

			if ( 'wp_template_part' !== (string) ( $write['type'] ?? '' ) ) {
				continue;
			}

			$content = isset( $write['content'] ) && is_scalar( $write['content'] ) ? (string) $write['content'] : '';
			if ( '' === trim( $content ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_template_part_content_missing', 'Blocks Engine wp_template_part writes must include non-empty content.' );
			}

			$template_parts[] = array_filter(
				array(
					'source_path'  => isset( $write['source_path'] ) && is_scalar( $write['source_path'] ) ? (string) $write['source_path'] : '',
					'slug'         => isset( $write['slug'] ) && is_scalar( $write['slug'] ) ? (string) $write['slug'] : '',
					'title'        => isset( $write['title'] ) && is_scalar( $write['title'] ) ? (string) $write['title'] : '',
					'area'         => isset( $write['area'] ) && is_scalar( $write['area'] ) ? (string) $write['area'] : '',
					'block_markup' => $content,
				),
				static fn ( string $value ): bool => '' !== $value
			);
		}

		return $template_parts;
	}

	/**
	 * Read native navigation rows from a Blocks Engine materialization plan.
	 *
	 * @param array<string,mixed> $artifacts WordPress artifacts from the transformer adapter.
	 * @return array<int,array<string,mixed>>|null|WP_Error Navigation-backed template parts, null when no rows exist.
	 */
	private static function navigation_template_parts_from_materialization_plan( array $artifacts ) {
		$site = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' !== (string) ( $site['schema'] ?? '' ) || ! array_key_exists( 'navigation', $site ) ) {
			return null;
		}

		if ( ! is_array( $site['navigation'] ) ) {
			return new WP_Error( 'static_site_importer_materialization_plan_navigation_invalid', 'Blocks Engine materialization_plan.navigation must be an array.' );
		}

		$template_parts = array();
		foreach ( $site['navigation'] as $navigation ) {
			if ( ! is_array( $navigation ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_navigation_row_invalid', 'Blocks Engine materialization-plan navigation entries must be arrays.' );
			}

			$content = '';
			foreach ( array( 'block_markup', 'content' ) as $key ) {
				if ( isset( $navigation[ $key ] ) && is_scalar( $navigation[ $key ] ) && '' !== trim( (string) $navigation[ $key ] ) ) {
					$content = (string) $navigation[ $key ];
					break;
				}
			}

			if ( '' === trim( $content ) ) {
				return new WP_Error( 'static_site_importer_materialization_plan_navigation_content_missing', 'Blocks Engine navigation rows must include non-empty block_markup or content.' );
			}

			$template_parts[] = array_filter(
				array(
					'source_path'  => isset( $navigation['source_path'] ) && is_scalar( $navigation['source_path'] ) ? (string) $navigation['source_path'] : '',
					'slug'         => isset( $navigation['slug'] ) && in_array( sanitize_key( (string) $navigation['slug'] ), array( 'header', 'footer' ), true ) ? sanitize_key( (string) $navigation['slug'] ) : 'header',
					'title'        => isset( $navigation['title'] ) && is_scalar( $navigation['title'] ) ? (string) $navigation['title'] : 'Navigation',
					'area'         => isset( $navigation['area'] ) && in_array( sanitize_key( (string) $navigation['area'] ), array( 'header', 'footer' ), true ) ? sanitize_key( (string) $navigation['area'] ) : 'header',
					'block_markup' => $content,
				),
				static fn ( string $value ): bool => '' !== $value
			);
		}

		return $template_parts;
	}

	/**
	 * Normalize compiler file paths before writing them to a generated theme.
	 *
	 * @param string $path Artifact file path.
	 * @return string Safe relative path, or empty string when unsafe.
	 */
	public static function normalize_artifact_materialization_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = preg_replace( '/\0+/', '', $path );
		if ( ! is_string( $path ) || '' === $path || str_starts_with( $path, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Resolve a template part artifact to an SSI-supported theme part path.
	 *
	 * @param array<string,mixed> $template_part Template part artifact.
	 * @return string Relative theme path, or empty string when unsupported.
	 */
	private static function template_part_artifact_relative_path( array $template_part ): string {
		$slug = isset( $template_part['slug'] ) && is_scalar( $template_part['slug'] ) ? sanitize_key( (string) $template_part['slug'] ) : '';
		$area = isset( $template_part['area'] ) && is_scalar( $template_part['area'] ) ? sanitize_key( (string) $template_part['area'] ) : '';
		$part = '';

		if ( in_array( $slug, array( 'header', 'footer' ), true ) ) {
			$part = $slug;
		} elseif ( in_array( $area, array( 'header', 'footer' ), true ) ) {
			$part = $area;
		}

		return '' !== $part ? 'parts/' . $part . '.html' : '';
	}

	/**
	 * Build a compact report row for a materialized template part artifact.
	 *
	 * @param string              $path          Relative generated theme path.
	 * @param array<string,mixed> $template_part Template part artifact.
	 * @param string              $markup        Serialized block markup.
	 * @return array<string,mixed>
	 */
	private static function template_part_artifact_report_payload( string $path, array $template_part, string $markup ): array {
		$source_paths = isset( $template_part['source_paths'] ) && is_array( $template_part['source_paths'] ) ? array_values( array_filter( $template_part['source_paths'], 'is_scalar' ) ) : array();
		if ( empty( $source_paths ) && isset( $template_part['source_path'] ) && is_scalar( $template_part['source_path'] ) && '' !== (string) $template_part['source_path'] ) {
			$source_paths = array( (string) $template_part['source_path'] );
		}

		return array(
			'path'               => $path,
			'slug'               => isset( $template_part['slug'] ) && is_scalar( $template_part['slug'] ) ? (string) $template_part['slug'] : '',
			'area'               => isset( $template_part['area'] ) && is_scalar( $template_part['area'] ) ? (string) $template_part['area'] : '',
			'generated'          => ! empty( $template_part['generated'] ),
			'source_paths'       => $source_paths,
			'source_hash'        => isset( $template_part['source_hash'] ) && is_scalar( $template_part['source_hash'] ) ? (string) $template_part['source_hash'] : '',
			'block_markup_bytes' => strlen( $markup ),
			'block_markup_hash'  => hash( 'sha256', $markup ),
		);
	}

	/**
	 * Build functions.php.
	 *
	 * @param string $theme_slug Theme slug.
	 * @param array<int,array<string,mixed>> $scripts     Materialized script asset rows.
	 * @param array<int,array<string,mixed>> $stylesheets Materialized stylesheet asset rows.
	 * @return string
	 */
	private static function functions_php( string $theme_slug, array $scripts = array(), array $stylesheets = array() ): string {
		$style_handle     = sanitize_key( $theme_slug ) . '-style';
		$editor_handle    = sanitize_key( $theme_slug ) . '-editor-style';
		$script_handle    = sanitize_key( $theme_slug ) . '-site';
		$script_lines     = '';
		$stylesheet_lines = '';
		$stylesheet_deps  = array();

		foreach ( $stylesheets as $stylesheet ) {
			if ( ! isset( $stylesheet['theme_path'] ) || ! is_scalar( $stylesheet['theme_path'] ) ) {
				continue;
			}

			$theme_path = self::normalize_artifact_materialization_path( (string) $stylesheet['theme_path'] );
			if ( '' === $theme_path ) {
				continue;
			}

			$handle            = sanitize_key( $theme_slug . '-asset-' . preg_replace( '/\.[^.]+$/', '', str_replace( '/', '-', $theme_path ) ) );
			$stylesheet_deps[] = $handle;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
			$stylesheet_lines .= "\twp_enqueue_style( " . var_export( $handle, true ) . ', get_template_directory_uri() . ' . var_export( '/' . $theme_path, true ) . ", array(), wp_get_theme()->get( 'Version' )";
			$media             = isset( $stylesheet['media'] ) && is_scalar( $stylesheet['media'] ) && '' !== (string) $stylesheet['media'] ? (string) $stylesheet['media'] : '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
			$stylesheet_lines .= '' !== $media ? ', ' . var_export( $media, true ) . " );\n" : " );\n";
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP array literals for functions.php.
		$stylesheet_dependencies = empty( $stylesheet_deps ) ? 'array()' : var_export( $stylesheet_deps, true );

		foreach ( $scripts as $script ) {
			if ( ! isset( $script['theme_path'] ) || ! is_scalar( $script['theme_path'] ) ) {
				continue;
			}

			$theme_path = self::normalize_artifact_materialization_path( (string) $script['theme_path'] );
			if ( '' === $theme_path ) {
				continue;
			}

			$handle    = sanitize_key( $theme_slug . '-asset-' . preg_replace( '/\.[^.]+$/', '', str_replace( '/', '-', $theme_path ) ) );
			$in_footer = 'head' !== (string) ( $script['placement'] ?? '' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
			$script_lines .= "\twp_enqueue_script( " . var_export( $handle, true ) . ', get_template_directory_uri() . ' . var_export( '/' . $theme_path, true ) . ", array(), wp_get_theme()->get( 'Version' ), " . ( $in_footer ? 'true' : 'false' ) . " );\n";
			if ( ! empty( $script['defer'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
				$script_lines .= "\twp_script_add_data( " . var_export( $handle, true ) . ", 'defer', true );\n";
			}
			if ( ! empty( $script['async'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
				$script_lines .= "\twp_script_add_data( " . var_export( $handle, true ) . ", 'async', true );\n";
			}
			if ( isset( $script['type'] ) && 'module' === strtolower( (string) $script['type'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates quoted PHP string literals for functions.php.
				$script_lines .= "\twp_script_add_data( " . var_export( $handle, true ) . ", 'type', 'module' );\n";
			}
		}

		return "<?php\n" .
			"/**\n" .
			" * Generated theme bootstrap.\n" .
			" */\n\n" .
			"add_action( 'after_setup_theme', static function (): void {\n" .
			"\tadd_theme_support( 'editor-styles' );\n" .
			"\tadd_editor_style( 'assets/css/editor-style.css' );\n" .
			"} );\n\n" .
			"add_action( 'wp_enqueue_scripts', static function (): void {\n" .
			$stylesheet_lines .
			"\twp_enqueue_style( '" . $style_handle . "', get_stylesheet_uri(), " . $stylesheet_dependencies . ", wp_get_theme()->get( 'Version' ) );\n" .
			"\tif ( file_exists( get_template_directory() . '/assets/site.js' ) ) {\n" .
			"\t\twp_enqueue_script( '" . $script_handle . "', get_template_directory_uri() . '/assets/site.js', array(), wp_get_theme()->get( 'Version' ), true );\n" .
			"\t}\n" .
			$script_lines .
			"} );\n\n" .
			"add_action( 'enqueue_block_editor_assets', static function (): void {\n" .
			$stylesheet_lines .
			"\twp_enqueue_style( '" . $editor_handle . "', get_template_directory_uri() . '/assets/css/editor-style.css', " . $stylesheet_dependencies . ", wp_get_theme()->get( 'Version' ) );\n" .
			"} );\n";
	}

	/**
	 * Build theme.json.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function theme_json( string $theme_name, string $css ): string {
		$data = array(
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => $theme_name,
			'settings' => array(
				'layout' => array(
					'contentSize' => '760px',
					'wideSize'    => '1200px',
				),
			),
		);

		$design_tokens = self::design_tokens_from_css( $css );
		if ( ! empty( $design_tokens['palette'] ) ) {
			$data['settings']['color']['palette'] = $design_tokens['palette'];
		}

		$data['styles']['spacing']['blockGap'] = '0';

		if ( ! empty( $design_tokens['styles'] ) ) {
			$data['styles']['color'] = $design_tokens['styles'];
		}

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Extract conservative design tokens from obvious :root custom properties.
	 *
	 * @param string $css Source CSS.
	 * @return array{palette:array<int,array{slug:string,name:string,color:string}>,styles:array<string,string>}
	 */
	private static function design_tokens_from_css( string $css ): array {
		$palette = array();
		$styles  = array();
		$seen    = array();

		if ( '' === trim( $css ) || ! preg_match_all( '/:root\s*\{([^}]*)\}/i', $css, $root_matches ) ) {
			return array(
				'palette' => $palette,
				'styles'  => $styles,
			);
		}

		foreach ( $root_matches[1] as $root_body ) {
			$root_body = (string) preg_replace( '/\/\*.*?\*\//s', '', $root_body );
			if ( ! preg_match_all( '/--([A-Za-z0-9_-]+)\s*:\s*([^;{}]+);/', $root_body, $property_matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $property_matches as $property_match ) {
				$token_name = strtolower( $property_match[1] );
				$color      = trim( $property_match[2] );
				$slug       = sanitize_title( $token_name );

				if ( '' === $slug || isset( $seen[ $slug ] ) || ! self::is_safe_color_value( $color ) ) {
					continue;
				}

				$seen[ $slug ] = true;
				$palette[]     = array(
					'slug'  => $slug,
					'name'  => ucwords( str_replace( array( '-', '_' ), ' ', $token_name ) ),
					'color' => $color,
				);

				if ( ! isset( $styles['background'] ) && in_array( $slug, array( 'bg', 'background' ), true ) ) {
					$styles['background'] = 'var(--wp--preset--color--' . $slug . ')';
				}

				if ( ! isset( $styles['text'] ) && in_array( $slug, array( 'fg', 'foreground', 'text' ), true ) ) {
					$styles['text'] = 'var(--wp--preset--color--' . $slug . ')';
				}
			}
		}

		return array(
			'palette' => $palette,
			'styles'  => $styles,
		);
	}

	/**
	 * Check whether a CSS value is safe to expose as a theme palette color.
	 *
	 * @param string $value CSS value.
	 * @return bool
	 */
	private static function is_safe_color_value( string $value ): bool {
		$value = trim( $value );

		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[-+0-9.%\s,\/]+\s*\)$/i', $value );
	}

	/**
	 * Write a generated file.
	 *
	 * @param string $path    File path.
	 * @param string $content File content.
	 * @return true|WP_Error
	 */
	public static function write_file( string $path, string $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes generated block-theme files to the selected theme directory.
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'static_site_importer_write_failed', sprintf( 'Failed to write file: %s', $path ) );
		}

		return true;
	}

	/**
	 * Write a copy of the import report to a caller-selected path.
	 *
	 * @param string $path    Report path.
	 * @param string $content Report JSON.
	 * @return true|WP_Error
	 */
	public static function write_external_report( string $path, string $content ) {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_report_mkdir_failed', sprintf( 'Failed to create report directory: %s', $dir ) );
		}

		return self::write_file( $path, $content );
	}

	/**
	 * Determine whether a path resolves inside a base directory.
	 *
	 * @param string $path Path to test.
	 * @param string $base Base directory.
	 * @return bool
	 */
	public static function path_is_under( string $path, string $base ): bool {
		$real_path = realpath( $path );
		$real_base = realpath( $base );

		if ( false === $real_path || false === $real_base ) {
			return false;
		}

		return 0 === strpos( trailingslashit( $real_path ), trailingslashit( $real_base ) );
	}

	/**
	 * Resolve a MIME type from a materialized path.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function mime_type( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'text/html',
			'css'         => 'text/css',
			'js', 'mjs'    => 'text/javascript',
			'json'        => 'application/json',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			default       => 'application/octet-stream',
		};
	}
}
