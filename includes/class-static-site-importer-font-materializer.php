<?php
/**
 * Materializes compiler-declared web fonts for canonical site plans.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_Font_Materializer {
	private const PLAN_SCHEMA = 'blocks-engine/php-transformer/font-materialization-plan/v1';
	private const CSS_LIMIT = 262144;
	private const FONT_LIMIT = 2097152;
	private const TOTAL_FONT_LIMIT = 4194304;

	/**
	 * Resolve an explicit font plan into receipt-owned theme writes.
	 *
	 * @param array<string,mixed> $plan          Compiler font materialization plan.
	 * @param array<string,mixed> $resolved_plan Resolved canonical WordPress site plan.
	 * @return array{writes:array<int,array<string,string>>,diagnostics:array<int,array<string,string>>}|WP_Error
	 */
	public static function prepare_overlay( array $plan, array $resolved_plan ) {
		if ( empty( $plan ) ) {
			return array( 'writes' => array(), 'diagnostics' => array() );
		}
		if ( self::PLAN_SCHEMA !== (string) ( $plan['schema'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_font_materialization_plan_invalid' );
		}

		$writes = self::stylesheet_writes( $plan );
		if ( is_wp_error( $writes ) ) {
			return $writes;
		}
		$diagnostics = array();
		if ( 'google_fonts' !== (string) ( $plan['provider'] ?? '' ) ) {
			return array( 'writes' => $writes, 'diagnostics' => $diagnostics );
		}

		$families = self::font_families( $plan['fonts'] ?? array() );
		$svg_writes = self::matching_svg_writes( $resolved_plan['writes'] ?? array(), $families );
		if ( empty( $families ) ) {
			return array( 'writes' => $writes, 'diagnostics' => $diagnostics );
		}

		$font_faces = self::resolve_google_font_faces( $plan, $families, $diagnostics );
		if ( '' === $font_faces ) {
			return new WP_Error( 'static_site_importer_font_materialization_failed', '', $diagnostics );
		}

		$writes[] = self::write( 'assets/css/embedded-fonts.css', trim( $font_faces ) . "\n", 'theme.font_materialization' );
		foreach ( $svg_writes as $svg_write ) {
			$writes[] = self::write(
				$svg_write['target_path'],
				self::embed_svg_font_faces( $svg_write['content'], $font_faces ),
				$svg_write['source_path']
			);
		}

		$bootstrap = self::canonical_write_content( $resolved_plan['writes'] ?? array(), 'functions.php' );
		if ( null === $bootstrap ) {
			return new WP_Error( 'static_site_importer_font_materialization_bootstrap_target_missing' );
		}
		$bootstrap .= "\nadd_action( 'wp_enqueue_scripts', static function (): void {\n";
		$bootstrap .= "    wp_enqueue_style( 'static-site-importer-embedded-fonts', get_theme_file_uri( 'assets/css/embedded-fonts.css' ), array(), null );\n";
		$bootstrap .= "} );\n";
		$writes[] = self::write( 'functions.php', $bootstrap, 'theme.font_materialization' );

		return array( 'writes' => $writes, 'diagnostics' => $diagnostics );
	}

	/** @return array<int,array<string,string>>|WP_Error */
	private static function stylesheet_writes( array $plan ) {
		$rows = isset( $plan['stylesheets'] ) && is_array( $plan['stylesheets'] ) ? $plan['stylesheets'] : array();
		if ( empty( $rows ) && isset( $plan['css'] ) && is_scalar( $plan['css'] ) && '' !== trim( (string) $plan['css'] ) ) {
			$rows[] = array( 'path' => 'assets/css/fonts.css', 'content' => trim( (string) $plan['css'] ) . "\n" );
		}
		$writes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_scalar( $row['path'] ?? null ) || ! is_scalar( $row['content'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_stylesheet_invalid' );
			}
			$path = self::safe_path( (string) $row['path'] );
			if ( '' === $path ) {
				return new WP_Error( 'static_site_importer_font_materialization_stylesheet_path_invalid' );
			}
			$content = (string) $row['content'];
			if ( '' !== trim( $content ) ) {
				$writes[] = self::write( $path, $content, 'theme.font_materialization' );
			}
		}
		return $writes;
	}

	/** @return array<int,string> */
	private static function font_families( mixed $fonts ): array {
		if ( ! is_array( $fonts ) ) {
			return array();
		}
		$families = array();
		foreach ( $fonts as $font ) {
			$family = is_array( $font ) ? ( $font['family'] ?? $font['font_family'] ?? '' ) : $font;
			if ( is_scalar( $family ) && '' !== trim( (string) $family ) ) {
				$families[] = trim( (string) $family );
			}
		}
		return array_values( array_unique( $families ) );
	}

	/** @param array<int,array<string,mixed>> $writes @param array<int,string> $families @return array<int,array{target_path:string,source_path:string,content:string}> */
	private static function matching_svg_writes( array $writes, array $families ): array {
		$matches = array();
		foreach ( $writes as $write ) {
			$target = is_array( $write ) && is_scalar( $write['target_path'] ?? null ) ? (string) $write['target_path'] : '';
			if ( ! str_ends_with( strtolower( $target ), '.svg' ) ) {
				continue;
			}
			$content = self::payload_content( $write );
			if ( null !== $content && self::svg_uses_font_family( $content, $families ) ) {
				$matches[] = array(
					'target_path' => $target,
					'source_path' => is_scalar( $write['source_path'] ?? null ) ? (string) $write['source_path'] : $target,
					'content'     => $content,
				);
			}
		}
		return $matches;
	}

	/** @param array<int,string> $families @param array<int,array<string,string>> $diagnostics */
	private static function resolve_google_font_faces( array $plan, array $families, array &$diagnostics ): string {
		$imports = array();
		foreach ( $plan['stylesheets'] ?? array() as $stylesheet ) {
			$content = is_array( $stylesheet ) && is_scalar( $stylesheet['content'] ?? null ) ? (string) $stylesheet['content'] : '';
			if ( preg_match_all( '/@import\s+(?:url\()?\s*["\']?([^"\'\s\)]+)["\']?\s*\)?/i', $content, $matches ) ) {
				$imports = array_merge( $imports, $matches[1] );
			}
		}
		$imports = array_values( array_unique( $imports ) );
		if ( empty( $imports ) ) {
			$diagnostics[] = self::diagnostic( 'stylesheet_import_missing' );
			return '';
		}
		foreach ( $imports as $import ) {
			if ( ! self::is_google_stylesheet_url( $import ) ) {
				$diagnostics[] = self::diagnostic( 'untrusted_stylesheet_url' );
				return '';
			}
		}

		$faces = array();
		$font_payloads = array();
		$font_payload_bytes = 0;
		foreach ( $imports as $import ) {
			$response = self::request( $import, self::CSS_LIMIT );
			$css = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $css ) {
				$diagnostics[] = self::diagnostic( 'stylesheet_fetch_failed' );
				return '';
			}
			if ( strlen( $css ) > self::CSS_LIMIT ) {
				$diagnostics[] = self::diagnostic( 'stylesheet_response_too_large' );
				return '';
			}
			$embedded = self::embed_font_sources( $css, $families, $font_payloads, $font_payload_bytes, $diagnostics );
			if ( '' === $embedded ) {
				return '';
			}
			$faces[] = $embedded;
		}
		return implode( "\n", $faces );
	}

	/** @param array<int,string> $families @param array<string,string> $payloads @param array<int,array<string,string>> $diagnostics */
	private static function embed_font_sources( string $css, array $families, array &$payloads, int &$payload_bytes, array &$diagnostics ): string {
		if ( ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/is', $css, $faces ) ) {
			$diagnostics[] = self::diagnostic( 'stylesheet_font_faces_missing' );
			return '';
		}
		$embedded = array();
		foreach ( $faces[0] as $index => $face ) {
			if ( ! preg_match( '/font-family\s*:\s*(["\']?)([^;"\']+)\1\s*;/i', $faces[1][ $index ], $family ) || ! in_array( trim( $family[2] ), $families, true ) ) {
				continue;
			}
			if ( str_contains( $face, '<' ) || ! preg_match_all( '/url\(\s*(["\']?)([^"\'\s\)]+)\1\s*\)/i', $face, $urls ) ) {
				$diagnostics[] = self::diagnostic( 'untrusted_font_url' );
				return '';
			}
			$rewritten = $face;
			foreach ( array_unique( $urls[2] ) as $url ) {
				$parts = wp_parse_url( $url );
				$path = is_array( $parts ) ? strtolower( (string) ( $parts['path'] ?? '' ) ) : '';
				if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || 'fonts.gstatic.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! preg_match( '/\.woff2?$/', $path ) ) {
					$diagnostics[] = self::diagnostic( 'untrusted_font_url' );
					return '';
				}
				if ( ! isset( $payloads[ $url ] ) ) {
					$response = self::request( $url, self::FONT_LIMIT );
					$payload = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
					if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $payload || strlen( $payload ) > self::FONT_LIMIT ) {
						$diagnostics[] = self::diagnostic( 'font_payload_fetch_failed' );
						return '';
					}
					if ( self::TOTAL_FONT_LIMIT < $payload_bytes + strlen( $payload ) ) {
						$diagnostics[] = self::diagnostic( 'font_payload_total_too_large' );
						return '';
					}
					$payloads[ $url ] = $payload;
					$payload_bytes += strlen( $payload );
				}
				$mime = str_ends_with( $path, '.woff2' ) ? 'font/woff2' : 'font/woff';
				$rewritten = str_replace( $url, 'data:' . $mime . ';base64,' . base64_encode( $payloads[ $url ] ), $rewritten );
			}
			$embedded[] = $rewritten;
		}
		if ( empty( $embedded ) ) {
			$diagnostics[] = self::diagnostic( 'matching_font_faces_missing' );
		}
		return implode( "\n", $embedded );
	}

	private static function request( string $url, int $limit ) {
		$args = array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => $limit,
			'headers'             => array( 'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' ),
		);
		$response = null;
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$response = wp_safe_remote_get( $url, $args );
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( ! is_wp_error( $response ) && 0 !== $status && ! in_array( $status, array( 408, 429 ), true ) && $status < 500 ) {
				break;
			}
			if ( $attempt < 3 ) {
				usleep( 100000 * $attempt );
			}
		}
		return $response;
	}

	/** @param array<int,string> $families */
	private static function svg_uses_font_family( string $svg, array $families ): bool {
		if ( ! preg_match( '/<text\b/i', $svg ) ) {
			return false;
		}
		foreach ( $families as $family ) {
			if ( preg_match( '/font-family\s*[:=]\s*(["\']?)' . preg_quote( $family, '/' ) . '\1/i', $svg ) ) {
				return true;
			}
		}
		return false;
	}

	private static function embed_svg_font_faces( string $svg, string $font_faces ): string {
		if ( ! preg_match( '/<svg\b[^>]*>/i', $svg, $match, PREG_OFFSET_CAPTURE ) ) {
			return $svg;
		}
		$offset = $match[0][1] + strlen( $match[0][0] );
		return substr( $svg, 0, $offset ) . '<style type="text/css">' . $font_faces . '</style>' . substr( $svg, $offset );
	}

	/** @param array<int,array<string,mixed>> $writes */
	private static function canonical_write_content( array $writes, string $target ): ?string {
		foreach ( $writes as $write ) {
			if ( is_array( $write ) && $target === ( $write['target_path'] ?? null ) ) {
				return self::payload_content( $write );
			}
		}
		return null;
	}

	private static function payload_content( mixed $write ): ?string {
		if ( ! is_array( $write ) || ! is_array( $write['payload'] ?? null ) || ! is_string( $write['payload']['data'] ?? null ) ) {
			return null;
		}
		if ( 'base64' === ( $write['payload']['encoding'] ?? null ) ) {
			$decoded = base64_decode( $write['payload']['data'], true );
			return false === $decoded ? null : $decoded;
		}
		return 'utf8' === ( $write['payload']['encoding'] ?? null ) ? $write['payload']['data'] : null;
	}

	private static function safe_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '#(?:^|/)\.\.(?:/|$)|^[a-z][a-z0-9+.-]*:#i', $path ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $path ) ) {
			return '';
		}
		return $path;
	}

	/** @return array{target_path:string,content:string,source_path:string} */
	private static function write( string $target, string $content, string $source ): array {
		return array( 'target_path' => $target, 'content' => $content, 'source_path' => $source );
	}

	private static function is_google_stylesheet_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && 'fonts.googleapis.com' === strtolower( (string) ( $parts['host'] ?? '' ) ) && ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && in_array( (string) ( $parts['path'] ?? '' ), array( '/css', '/css2' ), true );
	}

	/** @return array{type:string,source:string,reason:string} */
	private static function diagnostic( string $reason ): array {
		return array( 'type' => 'font_materialization_failed', 'source' => 'static-site-importer/font-materializer', 'reason' => $reason );
	}
}
