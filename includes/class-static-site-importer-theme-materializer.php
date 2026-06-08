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
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array{css:string,js:string,assets:array<string,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}|WP_Error
	 */
	public static function materialize_website_artifact_files( string $theme_dir, string $theme_uri, array $artifacts ) {
		$files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$css         = array();
		$js          = array();
		$assets      = array();
		$diagnostics = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$relative = self::normalize_artifact_materialization_path( isset( $file['path'] ) ? (string) $file['path'] : '' );
			if ( '' === $relative ) {
				$diagnostics[] = array(
					'type'    => 'website_artifact_file_skipped',
					'source'  => 'website_artifact:files',
					'reason'  => 'unsafe_artifact_path',
					'path'    => isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '',
					'message' => 'A BAC file artifact was skipped because its path is not safe to materialize inside the generated theme.',
				);
				continue;
			}

			$content = isset( $file['content'] ) && is_scalar( $file['content'] ) ? (string) $file['content'] : '';
			$kind    = isset( $file['kind'] ) ? (string) $file['kind'] : '';
			$lower   = strtolower( $relative );
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
			$dir             = dirname( $target );
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'static_site_importer_artifact_asset_mkdir_failed', sprintf( 'Failed to create website artifact asset directory: %s', $dir ) );
			}

			$result = self::write_file( $target, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$assets[ $relative ] = array(
				'source'     => $relative,
				'path'       => $relative,
				'url'        => trailingslashit( $theme_uri ) . $target_relative,
				'final_url'  => trailingslashit( $theme_uri ) . $target_relative,
				'mime_type'  => self::mime_type( $target ),
				'theme_path' => $target_relative,
				'policy'     => 'theme',
			);
		}

		return array(
			'css'         => trim( implode( "\n\n", array_filter( $css ) ) ),
			'js'          => trim( implode( "\n", array_filter( $js ) ) ),
			'assets'      => $assets,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Normalize BAC template part artifacts into generated theme writes.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array{writes:array<string,string>,reports:array<int,array<string,mixed>>}|WP_Error Absolute write paths and report rows.
	 */
	public static function template_part_artifact_writes( string $theme_dir, array $artifacts ) {
		$template_parts = isset( $artifacts['template_parts'] ) && is_array( $artifacts['template_parts'] ) ? $artifacts['template_parts'] : array();
		$writes  = array();
		$reports = array();
		foreach ( $template_parts as $template_part ) {
			if ( ! is_array( $template_part ) ) {
				return new WP_Error( 'static_site_importer_bac_template_part_invalid', 'BAC template part artifacts must be arrays.' );
			}

			$relative = self::template_part_artifact_relative_path( $template_part );
			if ( '' === $relative ) {
				return new WP_Error( 'static_site_importer_bac_template_part_unsupported', 'BAC template part artifacts must resolve to a supported header or footer theme part.' );
			}

			if ( ! isset( $template_part['block_markup'] ) || ! is_scalar( $template_part['block_markup'] ) ) {
				return new WP_Error( 'static_site_importer_bac_template_part_markup_missing', 'BAC template part artifacts must include serialized block_markup.' );
			}

			$markup = (string) $template_part['block_markup'];
			if ( '' === trim( $markup ) ) {
				return new WP_Error( 'static_site_importer_bac_template_part_markup_empty', 'BAC template part block_markup must not be empty.' );
			}

			$writes[ trailingslashit( $theme_dir ) . $relative ] = $markup;
			$reports[] = self::template_part_artifact_report_payload( $relative, $template_part, $markup );
		}

		if ( ! isset( $writes[ $theme_dir . '/parts/header.html' ] ) ) {
			$header = self::default_header_template_part();
			$writes[ $theme_dir . '/parts/header.html' ] = $header['block_markup'];
			$reports[] = self::template_part_artifact_report_payload( 'parts/header.html', $header, $header['block_markup'] );
		}

		return array(
			'writes'  => $writes,
			'reports' => $reports,
		);
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
	 * Resolve a BAC template part artifact to an SSI-supported theme part path.
	 *
	 * @param array<string,mixed> $template_part BAC template part artifact.
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
	 * Build the minimal generated header required by SSI's page templates.
	 *
	 * @return array<string,mixed> BAC-like template part artifact.
	 */
	private static function default_header_template_part(): array {
		return array(
			'schema'       => 'static-site-importer/template-part/v1',
			'slug'         => 'header',
			'area'         => 'header',
			'source_paths' => array(),
			'generated'    => true,
			'block_markup' => '<!-- wp:group {"tagName":"header","layout":{"type":"constrained"}} --><header class="wp-block-group"><!-- wp:site-title /--></header><!-- /wp:group -->',
		);
	}

	/**
	 * Build a compact report row for a materialized BAC template part artifact.
	 *
	 * @param string              $path          Relative generated theme path.
	 * @param array<string,mixed> $template_part BAC template part artifact.
	 * @param string              $markup        Serialized block markup.
	 * @return array<string,mixed>
	 */
	private static function template_part_artifact_report_payload( string $path, array $template_part, string $markup ): array {
		return array(
			'path'               => $path,
			'slug'               => isset( $template_part['slug'] ) && is_scalar( $template_part['slug'] ) ? (string) $template_part['slug'] : '',
			'area'               => isset( $template_part['area'] ) && is_scalar( $template_part['area'] ) ? (string) $template_part['area'] : '',
			'generated'          => ! empty( $template_part['generated'] ),
			'source_paths'       => isset( $template_part['source_paths'] ) && is_array( $template_part['source_paths'] ) ? array_values( array_filter( $template_part['source_paths'], 'is_scalar' ) ) : array(),
			'source_hash'        => isset( $template_part['source_hash'] ) && is_scalar( $template_part['source_hash'] ) ? (string) $template_part['source_hash'] : '',
			'block_markup_bytes' => strlen( $markup ),
			'block_markup_hash'  => hash( 'sha256', $markup ),
		);
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
