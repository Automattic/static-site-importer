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

		$diagnostics = array();
		$producer_faces = self::producer_faces( $plan, $diagnostics );
		if ( is_wp_error( $producer_faces ) ) {
			return $producer_faces;
		}
		$writes = self::stylesheet_writes( $plan );
		if ( is_wp_error( $writes ) ) {
			return $writes;
		}
		if ( isset( $producer_faces['faces'] ) ) {
			if ( empty( $producer_faces['faces'] ) ) {
				if ( ! empty( $diagnostics ) ) {
					return array( 'writes' => array(), 'diagnostics' => $diagnostics, 'faces' => array(), 'required_faces' => array() );
				}
				$producer_faces = array();
			} else {
				$materialized = self::materialize_producer_faces( $producer_faces, $diagnostics );
				if ( is_wp_error( $materialized ) ) {
					return $materialized;
				}
				$writes = array_merge( $writes, $materialized['writes'] );
				return self::with_runtime_registration( $writes, $resolved_plan, $materialized['required_faces'], $diagnostics, $materialized['faces'] );
			}
		}
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

		return self::with_runtime_registration( $writes, $resolved_plan, array(), $diagnostics );
	}

	/** @return array{faces:array<int,array<string,mixed>>,imports:array<string,array<string,mixed>>,receipts:array<string,string>}|WP_Error */
	private static function producer_faces( array $plan, array &$diagnostics ) {
		$contract = $plan['webfont_contract'] ?? array();
		if ( ! is_array( $contract ) || empty( $contract ) ) {
			return array(); // Old producer: retain the legacy Google CSS path below.
		}
		if ( 'blocks-engine/webfont-materialization/v1' !== (string) ( $contract['schema'] ?? '' ) ) {
			$diagnostics[] = self::diagnostic( 'producer_contract_invalid' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_contract_invalid', '', $diagnostics );
		}
		$required = 'required' === (string) ( $contract['browser_readiness']['state'] ?? '' );
		foreach ( $contract['diagnostics'] ?? array() as $diagnostic ) {
			if ( is_array( $diagnostic ) && '' !== (string) ( $diagnostic['code'] ?? '' ) ) {
				$diagnostics[] = self::diagnostic( 'producer_' . (string) $diagnostic['code'] );
				if ( $required ) {
					return new WP_Error( 'static_site_importer_font_materialization_producer_diagnostic', '', $diagnostics );
				}
			}
		}
		$faces = $contract['faces'] ?? array();
		if ( ! is_array( $faces ) || empty( $faces ) ) {
			return array( 'faces' => array(), 'imports' => array(), 'receipts' => array() );
		}
		$imports = array();
		foreach ( $contract['imports'] ?? array() as $import ) {
			$source = is_array( $import ) && is_array( $import['source'] ?? null ) ? $import['source'] : array();
			if ( ! is_array( $import ) || 'declared' !== ( $import['state'] ?? '' ) || ! is_string( $import['id'] ?? null ) || 'css' !== ( $source['format'] ?? '' ) || ! is_string( $source['url'] ?? null ) || ! self::is_google_stylesheet_url( $source['url'] ) ) {
				$diagnostics[] = self::diagnostic( 'producer_import_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_import_invalid', '', $diagnostics );
			}
			$imports[ $import['id'] ] = array( 'id' => $import['id'], 'href' => $source['url'], 'expected_digest' => $source['expected_digest'] ?? null );
		}
		$receipts = array();
		foreach ( $contract['receipts'] ?? array() as $receipt ) {
			if ( is_array( $receipt ) && 'pending_browser_readiness' === ( $receipt['state'] ?? '' ) && is_string( $receipt['id'] ?? null ) && is_string( $receipt['face_id'] ?? null ) ) {
				$receipts[ $receipt['face_id'] ] = $receipt['id'];
			}
		}
		if ( $required && array_values( $receipts ) !== array_values( $contract['browser_readiness']['required_receipt_ids'] ?? array() ) ) {
			$diagnostics[] = self::diagnostic( 'producer_readiness_receipts_invalid' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_receipts_invalid', '', $diagnostics );
		}
		$normalized = array();
		foreach ( $faces as $face ) {
			if ( ! is_array( $face ) || 'declared' !== ( $face['state'] ?? '' ) || ! is_string( $face['id'] ?? null ) || ! isset( $imports[ $face['import_id'] ?? '' ] ) || ! isset( $receipts[ $face['id'] ] ) || $receipts[ $face['id'] ] !== ( $face['receipt_id'] ?? null ) || ! is_array( $face['axes'] ?? null ) || ! is_array( $face['unicode_ranges'] ?? null ) ) {
				$diagnostics[] = self::diagnostic( 'producer_face_or_receipt_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_invalid', '', $diagnostics );
			}
			$family = trim( (string) ( $face['family'] ?? '' ) );
			$style = (string) ( $face['style'] ?? 'normal' );
			if ( '' === $family || ! in_array( $style, array( 'normal', 'italic' ), true ) || ! self::valid_weight( $face['weight'] ?? null ) || ! self::valid_axes( $face['axes'] ) ) {
				$diagnostics[] = self::diagnostic( 'producer_face_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_invalid', '', $diagnostics );
			}
			$face['family'] = $family;
			$face['import_ref'] = $face['import_id'];
			$normalized[] = $face;
		}
		return array( 'faces' => $normalized, 'imports' => $imports, 'receipts' => $receipts );
	}

	private static function valid_weight( mixed $weight ): bool {
		return is_array( $weight ) && ( ( 'static' === ( $weight['kind'] ?? '' ) && is_int( $weight['value'] ?? null ) && 0 < $weight['value'] && 1000 >= $weight['value'] ) || ( 'range' === ( $weight['kind'] ?? '' ) && is_int( $weight['min'] ?? null ) && is_int( $weight['max'] ?? null ) && 0 < $weight['min'] && $weight['min'] <= $weight['max'] && 1000 >= $weight['max'] ) );
	}

	private static function valid_axes( array $axes ): bool {
		foreach ( $axes as $axis => $value ) {
			if ( ! is_string( $axis ) || ! preg_match( '/^[A-Za-z0-9]{4}$/', $axis ) || ! self::valid_weight( $value ) ) return false;
		}
		return true;
	}

	/** @param array{faces:array<int,array<string,mixed>>,imports:array<string,array<string,mixed>>,receipts:array<string,string>} $producer @param array<int,array<string,string>> $diagnostics */
	private static function materialize_producer_faces( array $producer, array &$diagnostics ) {
		$writes = array();
		$css = array();
		$materialized_faces = array();
		$required_faces = array();
		$total = 0;
		$stylesheet_cache = array();
		$asset_cache = array();
		foreach ( $producer['faces'] as $face ) {
			$import = $producer['imports'][ $face['import_ref'] ];
			$url = (string) $import['href'];
			if ( ! isset( $stylesheet_cache[ $url ] ) ) {
				$response = self::request( $url, self::CSS_LIMIT );
				$stylesheet = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
				if ( '' === $stylesheet || strlen( $stylesheet ) > self::CSS_LIMIT || ! self::expected_digest_matches( (string) ( $import['expected_digest'] ?? '' ), $stylesheet ) ) {
					$diagnostics[] = self::diagnostic( '' === $stylesheet ? 'producer_stylesheet_fetch_failed' : 'producer_stylesheet_digest_mismatch' );
					return new WP_Error( 'static_site_importer_font_materialization_producer_stylesheet_failed', '', $diagnostics );
				}
				$stylesheet_cache[ $url ] = array( 'css' => $stylesheet, 'observed_digest' => 'sha256:' . hash( 'sha256', $stylesheet ) );
			}
			$blocks = self::matching_producer_blocks( $stylesheet_cache[ $url ]['css'], $face );
			if ( empty( $blocks ) ) {
				$diagnostics[] = self::diagnostic( 'producer_face_source_missing' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_source_missing', '', $diagnostics );
			}
			$assets = array();
			foreach ( $blocks as $block ) {
				$rewritten = $block;
				foreach ( self::font_urls( $block ) as $source_url ) {
					if ( ! isset( $asset_cache[ $source_url ] ) ) {
						$asset = self::download_font_asset( $source_url, $total, $diagnostics, (string) ( $face['expected_sha256'] ?? '' ) );
						if ( is_wp_error( $asset ) ) return $asset;
						$asset_cache[ $source_url ] = $asset;
						$writes[ $asset['target_path'] ] = self::write( $asset['target_path'], $asset['payload'], $source_url, 'base64' );
					}
					$asset = $asset_cache[ $source_url ];
					if ( '' !== (string) ( $face['expected_sha256'] ?? '' ) && ! hash_equals( strtolower( (string) $face['expected_sha256'] ), $asset['observed_sha256'] ) ) {
						$diagnostics[] = self::diagnostic( 'producer_font_digest_mismatch' );
						return new WP_Error( 'static_site_importer_font_materialization_producer_font_digest_mismatch', '', $diagnostics );
					}
					$rewritten = str_replace( $source_url, '../fonts/' . basename( $asset['target_path'] ), $rewritten );
					$assets[] = $asset + array( 'source_url' => $source_url );
				}
				$css[ hash( 'sha256', $rewritten ) ] = $rewritten;
			}
			$receipt_face = array( 'face_id' => $face['id'], 'import_id' => $face['import_ref'], 'receipt_id' => $producer['receipts'][ $face['id'] ], 'family' => $face['family'], 'style' => $face['style'], 'weight' => $face['weight'], 'axes' => $face['axes'], 'unicode_ranges' => $face['unicode_ranges'], 'import_observed_digest' => $stylesheet_cache[ $url ]['observed_digest'], 'status' => 'materialized', 'assets' => array_values( array_unique( $assets, SORT_REGULAR ) ) );
			$materialized_faces[] = $receipt_face;
			$required_faces[] = $receipt_face;
		}
		$writes['assets/css/embedded-fonts.css'] = self::write( 'assets/css/embedded-fonts.css', implode( "\n", $css ) . "\n", 'theme.font_materialization' );
		return array( 'writes' => array_values( $writes ), 'faces' => $materialized_faces, 'required_faces' => $required_faces );
	}

	private static function expected_digest_matches( string $expected, string $payload ): bool {
		if ( '' === $expected ) return true;
		$expected = strtolower( str_starts_with( $expected, 'sha256:' ) ? substr( $expected, 7 ) : $expected );
		return 64 === strlen( $expected ) && ctype_xdigit( $expected ) && hash_equals( $expected, hash( 'sha256', $payload ) );
	}

	/** @return array<int,string> */
	private static function matching_producer_blocks( string $css, array $face ): array {
		if ( '' === $css || ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/is', $css, $matches ) ) return array();
		$blocks = array();
		foreach ( $matches[0] as $index => $block ) {
			$declarations = $matches[1][ $index ];
			$family = self::css_declaration( $declarations, 'font-family' );
			$style = self::css_declaration( $declarations, 'font-style' ) ?: 'normal';
			$weight = self::css_declaration( $declarations, 'font-weight' );
			if ( self::normalize_font_family( $family ) === self::normalize_font_family( (string) $face['family'] ) && $style === $face['style'] && self::weight_matches( $weight, $face['weight'] ) ) $blocks[] = $block;
		}
		return $blocks;
	}

	private static function css_declaration( string $css, string $property ): string {
		return preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)/i', $css, $match ) ? trim( $match[1] ) : '';
	}

	private static function weight_matches( string $actual, array $expected ): bool {
		$actual = trim( preg_replace( '/\s+/', ' ', $actual ) ?? '' );
		if ( 'static' === $expected['kind'] ) {
			if ( (string) $expected['value'] === $actual ) return true;
			return preg_match( '/^(\d+)\s+(\d+)$/', $actual, $range ) && $expected['value'] >= (int) $range[1] && $expected['value'] <= (int) $range[2];
		}
		return ( $expected['min'] . ' ' . $expected['max'] ) === $actual || ( $expected['min'] . '..' . $expected['max'] ) === $actual;
	}

	/** @return array<int,string> */
	private static function font_urls( string $css ): array {
		if ( ! preg_match_all( '/url\(\s*(["\']?)([^"\'\s\)]+)\1\s*\)/i', $css, $matches ) ) return array();
		return array_values( array_unique( $matches[2] ) );
	}

	/** @param array<int,array<string,string>> $diagnostics */
	private static function download_font_asset( string $url, int &$total, array &$diagnostics, string $expected_sha256 = '' ) {
		$parts = wp_parse_url( $url );
		$path = is_array( $parts ) ? strtolower( (string) ( $parts['path'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! preg_match( '/\.woff2?$/', $path ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_url_invalid' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_url_invalid', '', $diagnostics );
		}
		$response = self::request( $url, self::FONT_LIMIT );
		$payload = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $payload || strlen( $payload ) > self::FONT_LIMIT || self::TOTAL_FONT_LIMIT < $total + strlen( $payload ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_fetch_failed' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_fetch_failed', '', $diagnostics );
		}
		$total += strlen( $payload );
		$hash = hash( 'sha256', $payload );
		if ( '' !== $expected_sha256 && ! hash_equals( strtolower( $expected_sha256 ), $hash ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_digest_mismatch' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_digest_mismatch', '', $diagnostics );
		}
		$format = str_ends_with( $path, '.woff2' ) ? 'woff2' : 'woff';
		return array( 'target_path' => 'assets/fonts/' . $hash . '.' . $format, 'payload' => $payload, 'format' => $format, 'expected_sha256' => $expected_sha256, 'observed_sha256' => $hash );
	}

	/** @param array<int,array<string,mixed>> $writes @param array<int,array<string,mixed>> $required_faces @param array<int,array<string,string>> $diagnostics */
	private static function with_runtime_registration( array $writes, array $resolved_plan, array $required_faces, array $diagnostics, array $faces = array() ) {
		$bootstrap = self::canonical_write_content( $resolved_plan['writes'] ?? array(), 'functions.php' );
		if ( null === $bootstrap ) {
			return new WP_Error( 'static_site_importer_font_materialization_bootstrap_target_missing' );
		}
		$bootstrap .= "\nadd_action( 'wp_enqueue_scripts', static function (): void {\n";
		$bootstrap .= "    wp_enqueue_style( 'static-site-importer-embedded-fonts', get_theme_file_uri( 'assets/css/embedded-fonts.css' ), array(), null );\n";
		if ( ! empty( $required_faces ) ) {
			$bootstrap .= "    wp_enqueue_script( 'static-site-importer-font-readiness', get_theme_file_uri( 'assets/js/font-readiness.js' ), array(), null, false );\n";
		}
		$bootstrap .= "} );\n";
		$writes[] = self::write( 'functions.php', $bootstrap, 'theme.font_materialization' );
		if ( ! empty( $required_faces ) ) {
			$writes[] = self::write( 'assets/js/font-readiness.js', self::readiness_script( $required_faces ), 'theme.font_materialization' );
		}
		return array( 'writes' => $writes, 'diagnostics' => $diagnostics, 'faces' => $faces, 'required_faces' => $required_faces );
	}

	/** @param array<int,array<string,mixed>> $faces */
	private static function readiness_script( array $faces ): string {
		$faces_json = wp_json_encode( $faces, JSON_UNESCAPED_SLASHES );
		return '(async()=>{const faces=' . $faces_json . ';const glyphs="SSI glyph evidence 0123456789";const weight=face=>face.weight.kind==="range"?face.weight.min+" "+face.weight.max:face.weight.value;const results=await Promise.all(faces.map(async face=>{const descriptor=(face.style||"normal")+" "+weight(face)+" 1em "+JSON.stringify(face.family);try{await document.fonts.load(descriptor,glyphs);return {...face,status:document.fonts.check(descriptor,glyphs)?"loaded":"missing"};}catch(error){return {...face,status:"missing",error:String(error)}}}));const readiness={schema:"static-site-importer/font-readiness/v1",status:results.every(face=>face.status==="loaded")?"loaded":"missing",faces:results};window.__staticSiteImporterFontReadiness=readiness;document.documentElement.dataset.staticSiteImporterFontReadiness=readiness.status;let record=document.getElementById("static-site-importer-font-readiness");if(!record){record=document.createElement("script");record.id="static-site-importer-font-readiness";record.type="application/json";document.head.append(record)}record.textContent=JSON.stringify(readiness);})();' . "\n";
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
	public static function svg_uses_font_family( string $svg, array $families ): bool {
		if ( ! preg_match( '/<text\b/i', $svg ) ) {
			return false;
		}
		$expected = array();
		foreach ( $families as $family ) {
			$family = self::normalize_font_family( is_scalar( $family ) ? (string) $family : '' );
			if ( '' !== $family ) {
				$expected[ $family ] = true;
			}
		}
		if ( empty( $expected ) ) {
			return false;
		}

		$values = array();
		if ( preg_match_all( '/\bfont-family\s*=\s*(["\'])(.*?)\1/is', $svg, $attributes ) ) {
			$values = array_merge( $values, $attributes[2] );
		}
		if ( preg_match_all( '/\bfont-family\s*=\s*(?!["\'])([^\s>]+)/i', $svg, $attributes ) ) {
			$values = array_merge( $values, $attributes[1] );
		}
		if ( preg_match_all( '/\bfont-family\s*:\s*([^;}]+)/i', $svg, $declarations ) ) {
			$values = array_merge( $values, $declarations[1] );
		}

		foreach ( $values as $value ) {
			foreach ( self::font_family_tokens( (string) $value ) as $family ) {
				if ( isset( $expected[ $family ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array<int,string> */
	private static function font_family_tokens( string $value ): array {
		$value = preg_replace( '/\s*!important\s*$/i', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 ) ) ?? $value;
		$tokens = array();
		$token = '';
		$quote = '';
		$escaped = false;
		$length = strlen( $value );
		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			if ( $escaped ) {
				$token .= $character;
				$escaped = false;
				continue;
			}
			if ( '\\' === $character ) {
				$token .= $character;
				$escaped = true;
				continue;
			}
			if ( '' !== $quote ) {
				$token .= $character;
				if ( $quote === $character ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
				$token .= $character;
				continue;
			}
			if ( ',' === $character ) {
				$family = self::normalize_font_family( $token );
				if ( '' !== $family ) {
					$tokens[] = $family;
				}
				$token = '';
				continue;
			}
			$token .= $character;
		}
		$family = self::normalize_font_family( $token );
		if ( '' !== $family ) {
			$tokens[] = $family;
		}
		return array_values( array_unique( $tokens ) );
	}

	private static function normalize_font_family( string $family ): string {
		$family = trim( $family );
		if ( 2 <= strlen( $family ) && ( ( '"' === $family[0] && '"' === $family[ strlen( $family ) - 1 ] ) || ( "'" === $family[0] && "'" === $family[ strlen( $family ) - 1 ] ) ) ) {
			$family = substr( $family, 1, -1 );
		}
		$family = preg_replace( '/\s+/', ' ', trim( $family ) ) ?? '';
		return strtolower( $family );
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

	/** @return array{target_path:string,content:string,source_path:string,encoding:string} */
	private static function write( string $target, string $content, string $source, string $encoding = 'utf8' ): array {
		return array( 'target_path' => $target, 'content' => 'base64' === $encoding ? base64_encode( $content ) : $content, 'source_path' => $source, 'encoding' => $encoding );
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
