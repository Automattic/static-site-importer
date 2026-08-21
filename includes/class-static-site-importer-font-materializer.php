<?php
/**
 * Materializes compiler-declared web fonts for canonical site plans.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_Font_Materializer {
	private const PLAN_SCHEMA      = 'blocks-engine/php-transformer/font-materialization-plan/v1';
	private const CSS_LIMIT        = 262144;
	private const FONT_LIMIT       = 2097152;
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
			return array(
				'writes'      => array(),
				'diagnostics' => array(),
			);
		}
		if ( self::PLAN_SCHEMA !== (string) ( $plan['schema'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_font_materialization_plan_invalid' );
		}

		$diagnostics    = array();
		$producer_faces = self::producer_faces( $plan, $resolved_plan, $diagnostics );
		if ( is_wp_error( $producer_faces ) ) {
			return $producer_faces;
		}
		$writes = self::stylesheet_writes( $plan );
		if ( is_wp_error( $writes ) ) {
			return $writes;
		}
		if ( null !== $producer_faces ) {
			if ( empty( $producer_faces['faces'] ) ) {
				if ( self::uses_inferred_google_fallback( $plan ) ) {
					$producer_faces = null;
				} elseif ( ! empty( $diagnostics ) || ! self::resolved_plan_has_google_stylesheet( $resolved_plan ) ) {
					return array(
						'writes'         => array(),
						'diagnostics'    => $diagnostics,
						'faces'          => array(),
						'required_faces' => array(),
					);
				}
			} else {
				$materialized = self::materialize_producer_faces( $producer_faces, $diagnostics );
				if ( is_wp_error( $materialized ) ) {
					return $materialized;
				}
				$writes = array_merge( $writes, $materialized['writes'] );
				$svg    = self::materialize_svg_consumers( $producer_faces['svg_consumers'], $materialized, $diagnostics );
				if ( is_wp_error( $svg ) ) {
					return $svg;
				}
				return self::with_runtime_registration( array_merge( $writes, $svg['writes'] ), $resolved_plan, $materialized['required_faces'], $diagnostics, $materialized['faces'], $svg['receipts'], $producer_faces['svg_consumers'] );
			}
		}
		if ( 'google_fonts' !== (string) ( $plan['provider'] ?? '' ) ) {
			return array(
				'writes'      => $writes,
				'diagnostics' => $diagnostics,
			);
		}

		$families   = self::font_families( $plan['fonts'] ?? array() );
		$svg_writes = self::matching_svg_writes( $resolved_plan['writes'] ?? array(), $families );
		if ( empty( $families ) ) {
			return array(
				'writes'      => $writes,
				'diagnostics' => $diagnostics,
			);
		}

		$font_faces = self::resolve_google_font_faces( $plan, $families, $diagnostics );
		if ( 'preserved' === $font_faces['state'] ) {
			$preserved_url = (string) $font_faces['url'];
			$fallback_url  = self::is_google_stylesheet_url( $preserved_url ) ? $preserved_url : '';
			$imports       = array();
			foreach ( $plan['stylesheets'] ?? array() as $stylesheet ) {
				$content = is_array( $stylesheet ) && is_scalar( $stylesheet['content'] ?? null ) ? (string) $stylesheet['content'] : '';
				if ( preg_match_all( '/@import\s+(?:url\()?\s*["\']?([^"\'\s\)]+)["\']?\s*\)?/i', $content, $matches ) ) {
					foreach ( $matches[1] as $candidate ) {
						if ( self::is_google_stylesheet_url( $candidate ) ) {
							$imports[] = $candidate;
						}
					}
				}
			}
			$imports = array_values( array_unique( $imports ) );
			if ( '' === $fallback_url && ! empty( $imports ) ) {
				$fallback_url = $imports[0];
			}
			$css_lines = array();
			foreach ( $imports as $import_url ) {
				$css_lines[] = '@import "' . addcslashes( $import_url, "\"\\\n" ) . '";';
			}
			if ( empty( $css_lines ) && '' !== $fallback_url ) {
				$css_lines[] = '@import "' . addcslashes( $fallback_url, "\"\\\n" ) . '";';
			}
			$css_body     = empty( $css_lines ) ? '' : trim( implode( "\n", $css_lines ) ) . "\n";
			$outer_detail = array(
				'reason'         => (string) $font_faces['reason'],
				'observed_bytes' => (int) $font_faces['observed_bytes'],
				'url'            => $preserved_url,
			);
			if ( 'google_fonts_payloads_partial_preserved' === $outer_detail['reason'] ) {
				$outer_detail['limit_bytes']     = self::TOTAL_FONT_LIMIT;
				$outer_detail['aggregate_bytes'] = (int) ( $font_faces['aggregate_bytes'] ?? $font_faces['observed_bytes'] );
			} elseif ( 'google_fonts_stylesheet_preserved_due_to_size' === $outer_detail['reason'] ) {
				$outer_detail['limit_bytes'] = self::CSS_LIMIT;
			}
			$diagnostics[] = self::diagnostic_with_detail( 'font_materialization_partial_preserved', $outer_detail );
			if ( '' === $css_body ) {
				return new WP_Error( 'static_site_importer_font_materialization_failed', '', $diagnostics );
			}
			$writes[] = self::write( 'assets/css/embedded-fonts.css', $css_body, 'theme.font_materialization' );
			return self::with_runtime_registration( $writes, $resolved_plan, array(), $diagnostics );
		}
		$embedded_css = (string) $font_faces['css'];
		if ( '' === trim( $embedded_css ) ) {
			return new WP_Error( 'static_site_importer_font_materialization_failed', '', $diagnostics );
		}
		$writes[] = self::write( 'assets/css/embedded-fonts.css', trim( $embedded_css ) . "\n", 'theme.font_materialization' );
		foreach ( $svg_writes as $svg_write ) {
			$writes[] = self::write(
				$svg_write['target_path'],
				self::embed_svg_font_faces( $svg_write['content'], $embedded_css ),
				$svg_write['source_path']
			);
		}
		return self::with_runtime_registration( $writes, $resolved_plan, array(), $diagnostics );
	}

	private static function uses_inferred_google_fallback( array $plan ): bool {
		if ( 'google_fonts' !== (string) ( $plan['provider'] ?? '' ) || empty( $plan['fonts'] ) || empty( $plan['imports'] ) ) {
			return false;
		}

		foreach ( $plan['imports'] as $import ) {
			if ( ! is_array( $import ) || 'unsupported' !== (string) ( $import['provider'] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return array{faces:array<int,array<string,mixed>>,imports:array<string,array<string,mixed>>,receipts:array<string,string>,svg_consumers:array<int,array<string,mixed>>}|null|WP_Error */
	private static function producer_faces( array $plan, array $resolved_plan, array &$diagnostics ) {
		$contract = $plan['webfont_contract'] ?? array();
		if ( ! is_array( $contract ) || empty( $contract ) ) {
			return null; // Old producer: retain the legacy Google CSS path below.
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
			return array(
				'faces'         => array(),
				'imports'       => array(),
				'receipts'      => array(),
				'svg_consumers' => array(),
			);
		}
		$imports = array();
		foreach ( $contract['imports'] ?? array() as $import ) {
			$source = is_array( $import ) && is_array( $import['source'] ?? null ) ? $import['source'] : array();
			if ( ! is_array( $import ) || 'declared' !== ( $import['state'] ?? '' ) || ! is_string( $import['id'] ?? null ) || 'css' !== ( $source['format'] ?? '' ) || ! is_string( $source['url'] ?? null ) || ! self::is_google_stylesheet_url( $source['url'] ) ) {
				$diagnostics[] = self::diagnostic( 'producer_import_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_import_invalid', '', $diagnostics );
			}
			$imports[ $import['id'] ] = array(
				'id'              => $import['id'],
				'href'            => $source['url'],
				'expected_digest' => $source['expected_digest'] ?? null,
			);
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
		foreach ( $faces as $face_index => $face ) {
			if ( ! is_array( $face ) || 'declared' !== ( $face['state'] ?? '' ) || ! is_string( $face['id'] ?? null ) || ! isset( $imports[ $face['import_id'] ?? '' ] ) || ! isset( $receipts[ $face['id'] ] ) || ( $face['receipt_id'] ?? null ) !== $receipts[ $face['id'] ] || ! is_array( $face['axes'] ?? null ) || ! is_array( $face['unicode_ranges'] ?? null ) ) {
				$diagnostics[] = self::diagnostic_with_detail(
					'producer_face_or_receipt_invalid',
					array(
						'face_index'     => $face_index,
						'face_id'        => is_array( $face ) && is_string( $face['id'] ?? null ) ? $face['id'] : null,
						'invalid_fields' => self::invalid_producer_face_fields( $face, $imports, $receipts ),
					)
				);
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_invalid', '', $diagnostics );
			}
			$family = trim( (string) ( $face['family'] ?? '' ) );
			$style  = (string) ( $face['style'] ?? 'normal' );
			if ( '' === $family || ! in_array( $style, array( 'normal', 'italic' ), true ) || ! self::valid_weight( $face['weight'] ?? null ) || ! self::valid_axes( $face['axes'] ) ) {
				$diagnostics[] = self::diagnostic_with_detail(
					'producer_face_invalid',
					array(
						'face_index'     => $face_index,
						'face_id'        => $face['id'],
						'invalid_fields' => self::invalid_producer_face_value_fields( $face ),
					)
				);
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_invalid', '', $diagnostics );
			}
			$face['family']     = $family;
			$face['import_ref'] = $face['import_id'];
			$normalized[]       = $face;
		}
		$svg_consumers = self::svg_consumers( $contract, $normalized, $receipts, $resolved_plan, $diagnostics );
		if ( is_wp_error( $svg_consumers ) ) {
			return $svg_consumers;
		}
		return array(
			'faces'         => $normalized,
			'imports'       => $imports,
			'receipts'      => $receipts,
			'svg_consumers' => $svg_consumers,
		);
	}

	/** @return array<int,array<string,mixed>>|WP_Error */
	private static function svg_consumers( array $contract, array $faces, array $receipts, array $resolved_plan, array &$diagnostics ) {
		$consumers = $contract['svg_consumers'] ?? array();
		if ( ! is_array( $consumers ) || ! array_is_list( $consumers ) ) {
			$diagnostics[] = self::diagnostic( 'producer_svg_consumers_invalid' );
			return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_invalid', '', $diagnostics );
		}
		$faces = array_column( $faces, null, 'id' );
		$ids   = array();
		foreach ( $consumers as $consumer_index => $consumer ) {
			if ( ! is_array( $consumer ) ) {
				$diagnostics[] = self::diagnostic( 'producer_svg_consumer_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_invalid', '', $diagnostics );
			}
			$face_ids        = is_array( $consumer['face_ids'] ?? null ) ? $consumer['face_ids'] : array();
			$receipt_ids     = is_array( $consumer['receipt_ids'] ?? null ) ? $consumer['receipt_ids'] : array();
			$matching_writes = array_values( array_filter( $resolved_plan['writes'] ?? array(), static fn( mixed $write ): bool => is_array( $write ) && ( $consumer['source_path'] ?? null ) === ( $write['source_path'] ?? null ) && self::write_payload_hash( $write ) === ( $consumer['pre_transform_payload_hash'] ?? null ) ) );
			$write           = $matching_writes[0] ?? null;
			$matching_assets = array_values( array_filter( $resolved_plan['assets'] ?? array(), static fn( mixed $asset ): bool => is_array( $write ) && is_array( $asset ) && ( $consumer['source_path'] ?? null ) === ( $asset['source_path'] ?? null ) && ( $write['target_path'] ?? null ) === ( $asset['target_path'] ?? null ) && ( ! isset( $asset['content_hash'] ) || ( $consumer['pre_transform_payload_hash'] ?? null ) === $asset['content_hash'] ) ) );
			$expected_id     = 'svg-webfont-consumer-' . substr( hash( 'sha256', (string) ( $consumer['source_path'] ?? '' ) . "\n" . (string) ( $consumer['write_path'] ?? '' ) . "\n" . (string) ( $consumer['pre_transform_payload_hash'] ?? '' ) . "\n" . implode( "\n", $face_ids ) ), 0, 20 );
			$sorted_faces    = $face_ids;
			sort( $sorted_faces, SORT_STRING );
			if ( array_keys( $consumer ) !== array( 'id', 'source_path', 'write_path', 'pre_transform_payload_hash', 'face_ids', 'receipt_ids', 'required' ) || ! is_string( $consumer['id'] ?? null ) || $consumer['id'] !== $expected_id || isset( $ids[ $consumer['id'] ] ) || ( $consumer['required'] ?? null ) !== true || ! is_string( $consumer['source_path'] ?? null ) || ! is_string( $consumer['write_path'] ?? null ) || '' === self::safe_path( $consumer['write_path'] ) || 1 !== count( $matching_writes ) || ! is_array( $write ) || ( $write['source_path'] ?? null ) !== $consumer['source_path'] || ! str_ends_with( strtolower( $consumer['write_path'] ), '.svg' ) || ! self::valid_sha256( $consumer['pre_transform_payload_hash'] ?? null ) || ! hash_equals( $consumer['pre_transform_payload_hash'], self::write_payload_hash( $write ) ) || ( ! empty( $resolved_plan['assets'] ) && 1 !== count( $matching_assets ) ) || ! array_is_list( $face_ids ) || empty( $face_ids ) || array_values( array_unique( $face_ids ) ) !== $face_ids || $sorted_faces !== $face_ids || ! array_is_list( $receipt_ids ) || array_values( array_unique( $receipt_ids ) ) !== $receipt_ids || count( $face_ids ) !== count( $receipt_ids ) ) {
				$diagnostics[] = self::diagnostic( 'producer_svg_consumer_invalid' );
				return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_invalid', '', $diagnostics );
			}
			foreach ( $face_ids as $index => $face_id ) {
				if ( ! is_string( $face_id ) || ! isset( $faces[ $face_id ] ) || ! is_string( $receipt_ids[ $index ] ?? null ) || ( $receipts[ $face_id ] ?? null ) !== $receipt_ids[ $index ] ) {
					$diagnostics[] = self::diagnostic( 'producer_svg_consumer_face_invalid' );
					return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_invalid', '', $diagnostics );
				}
			}
			$consumer['write']            = $write;
			$ids[ $consumer['id'] ]       = true;
			$consumers[ $consumer_index ] = $consumer;
		}
		$sorted = $consumers;
		usort( $sorted, static fn( array $left, array $right ): int => strcmp( $left['id'], $right['id'] ) );
		if ( $sorted !== $consumers ) {
			$diagnostics[] = self::diagnostic( 'producer_svg_consumers_not_canonical' );
			return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_invalid', '', $diagnostics );
		}
		return $consumers;
	}

	private static function valid_weight( mixed $weight ): bool {
		return is_array( $weight ) && ( ( 'static' === ( $weight['kind'] ?? '' ) && is_int( $weight['value'] ?? null ) && 0 < $weight['value'] && 1000 >= $weight['value'] ) || ( 'range' === ( $weight['kind'] ?? '' ) && is_int( $weight['min'] ?? null ) && is_int( $weight['max'] ?? null ) && 0 < $weight['min'] && $weight['min'] <= $weight['max'] && 1000 >= $weight['max'] ) );
	}

	private static function valid_axes( array $axes ): bool {
		foreach ( $axes as $axis => $value ) {
			if ( ! is_string( $axis ) || ! preg_match( '/^[A-Za-z0-9]{4}$/', $axis ) || ! self::valid_axis( $axis, $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_axis( string $axis, mixed $value ): bool {
		if ( 'ital' === $axis && is_array( $value ) && 'static' === ( $value['kind'] ?? '' ) && is_int( $value['value'] ?? null ) ) {
			return in_array( $value['value'], array( 0, 1 ), true );
		}
		return self::valid_weight( $value );
	}

	/** @param array<string,array<string,mixed>> $imports @param array<string,string> $receipts @return array<int,string> */
	private static function invalid_producer_face_fields( mixed $face, array $imports, array $receipts ): array {
		if ( ! is_array( $face ) ) {
			return array( 'face' );
		}
		$invalid = array();
		if ( 'declared' !== ( $face['state'] ?? '' ) ) {
			$invalid[] = 'state';
		}
		if ( ! is_string( $face['id'] ?? null ) ) {
			$invalid[] = 'id';
		}
		if ( ! isset( $imports[ $face['import_id'] ?? '' ] ) ) {
			$invalid[] = 'import_id';
		}
		if ( ! isset( $receipts[ $face['id'] ?? '' ] ) || ( $face['receipt_id'] ?? null ) !== ( $receipts[ $face['id'] ?? '' ] ?? null ) ) {
			$invalid[] = 'receipt_id';
		}
		if ( ! is_array( $face['axes'] ?? null ) ) {
			$invalid[] = 'axes';
		}
		if ( ! is_array( $face['unicode_ranges'] ?? null ) ) {
			$invalid[] = 'unicode_ranges';
		}
		return $invalid;
	}

	/** @param array<string,mixed> $face @return array<int,string> */
	private static function invalid_producer_face_value_fields( array $face ): array {
		$invalid = array();
		if ( '' === trim( (string) ( $face['family'] ?? '' ) ) ) {
			$invalid[] = 'family';
		}
		if ( ! in_array( (string) ( $face['style'] ?? 'normal' ), array( 'normal', 'italic' ), true ) ) {
			$invalid[] = 'style';
		}
		if ( ! self::valid_weight( $face['weight'] ?? null ) ) {
			$invalid[] = 'weight';
		}
		if ( ! self::valid_axes( $face['axes'] ?? array() ) ) {
			$invalid[] = 'axes';
		}
		return $invalid;
	}

	/** @param array{faces:array<int,array<string,mixed>>,imports:array<string,array<string,mixed>>,receipts:array<string,string>} $producer @param array<int,array<string,string>> $diagnostics */
	private static function materialize_producer_faces( array $producer, array &$diagnostics ) {
		$writes             = array();
		$css                = array();
		$materialized_faces = array();
		$svg_faces          = array();
		$required_faces     = array();
		$total              = 0;
		$stylesheet_cache   = array();
		$asset_cache        = array();
		foreach ( $producer['faces'] as $face ) {
			$import = $producer['imports'][ $face['import_ref'] ];
			$url    = (string) $import['href'];
			if ( ! isset( $stylesheet_cache[ $url ] ) ) {
				$response   = self::request( $url, self::CSS_LIMIT );
				$stylesheet = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
				if ( '' === $stylesheet || strlen( $stylesheet ) > self::CSS_LIMIT || ! self::expected_digest_matches( (string) ( $import['expected_digest'] ?? '' ), $stylesheet ) ) {
					$diagnostics[] = self::diagnostic( '' === $stylesheet ? 'producer_stylesheet_fetch_failed' : 'producer_stylesheet_digest_mismatch' );
					return new WP_Error( 'static_site_importer_font_materialization_producer_stylesheet_failed', '', $diagnostics );
				}
				$stylesheet_cache[ $url ] = array(
					'css'             => $stylesheet,
					'observed_digest' => 'sha256:' . hash( 'sha256', $stylesheet ),
				);
			}
			$blocks = self::matching_producer_blocks( $stylesheet_cache[ $url ]['css'], $face );
			if ( empty( $blocks ) ) {
				$diagnostics[] = self::diagnostic( 'producer_face_source_missing' );
				return new WP_Error( 'static_site_importer_font_materialization_producer_face_source_missing', '', $diagnostics );
			}
			$assets = array();
			foreach ( $blocks as $block ) {
				$rewritten     = $block;
				$svg_rewritten = $block;
				foreach ( self::font_urls( $block ) as $source_url ) {
					if ( ! isset( $asset_cache[ $source_url ] ) ) {
						$asset = self::download_font_asset( $source_url, $total, $diagnostics, (string) ( $face['expected_sha256'] ?? '' ) );
						if ( is_wp_error( $asset ) ) {
							return $asset;
						}
						$asset_cache[ $source_url ]      = $asset;
						$writes[ $asset['target_path'] ] = self::write( $asset['target_path'], $asset['payload'], $source_url, 'base64' );
					}
					$asset = $asset_cache[ $source_url ];
					if ( '' !== (string) ( $face['expected_sha256'] ?? '' ) && ! hash_equals( strtolower( (string) $face['expected_sha256'] ), $asset['observed_sha256'] ) ) {
						$diagnostics[] = self::diagnostic( 'producer_font_digest_mismatch' );
						return new WP_Error( 'static_site_importer_font_materialization_producer_font_digest_mismatch', '', $diagnostics );
					}
					$rewritten     = str_replace( $source_url, '../fonts/' . basename( $asset['target_path'] ), $rewritten );
					$svg_rewritten = str_replace( $source_url, 'data:font/' . $asset['format'] . ';base64,' . base64_encode( $asset['payload'] ), $svg_rewritten ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a fetched font asset for a data URL.
					// Writes retain the exact bytes; receipts retain only durable evidence.
					$assets[] = self::asset_receipt( $asset, $source_url );
				}
				$css[ hash( 'sha256', $rewritten ) ]                          = $rewritten;
				$svg_faces[ $face['id'] ][ hash( 'sha256', $svg_rewritten ) ] = $svg_rewritten;
			}
			$receipt_face         = array(
				'face_id'                => $face['id'],
				'import_id'              => $face['import_ref'],
				'receipt_id'             => $producer['receipts'][ $face['id'] ],
				'family'                 => $face['family'],
				'style'                  => $face['style'],
				'weight'                 => $face['weight'],
				'axes'                   => $face['axes'],
				'unicode_ranges'         => $face['unicode_ranges'],
				'import_observed_digest' => $stylesheet_cache[ $url ]['observed_digest'],
				'status'                 => 'materialized',
				'assets'                 => array_values( array_unique( $assets, SORT_REGULAR ) ),
			);
			$materialized_faces[] = $receipt_face;
			$required_faces[]     = $receipt_face;
		}
		$writes['assets/css/embedded-fonts.css'] = self::write( 'assets/css/embedded-fonts.css', implode( "\n", $css ) . "\n", 'theme.font_materialization' );
		return array(
			'writes'         => array_values( $writes ),
			'faces'          => $materialized_faces,
			'required_faces' => $required_faces,
			'svg_faces'      => $svg_faces,
		);
	}

	/** @return array{writes:array<int,array<string,string>>,receipts:array<int,array<string,mixed>>}|WP_Error */
	private static function materialize_svg_consumers( array $consumers, array $materialized, array &$diagnostics ) {
		$writes   = array();
		$receipts = array();
		foreach ( $consumers as $consumer ) {
			$faces   = array();
			$digests = array();
			foreach ( $consumer['face_ids'] as $face_id ) {
				$face_css = $materialized['svg_faces'][ $face_id ] ?? array();
				if ( empty( $face_css ) ) {
					$diagnostics[] = self::diagnostic( 'producer_svg_consumer_face_unmaterialized' );
					return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_failed', '', $diagnostics );
				}
				$faces = array_merge( $faces, array_values( $face_css ) );
				$face  = array_values( array_filter( $materialized['faces'], static fn( array $row ): bool => $face_id === $row['face_id'] ) )[0] ?? array();
				foreach ( $face['assets'] ?? array() as $asset ) {
					$digests[] = $asset['observed_sha256'];
				}
			}
			$input = self::payload_content( $consumer['write'] );
			if ( null === $input ) {
				$diagnostics[] = self::diagnostic( 'producer_svg_consumer_write_missing' );
				return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_failed', '', $diagnostics );
			}
			$output = self::embed_svg_font_faces( $input, implode( "\n", array_values( array_unique( $faces ) ) ) );
			if ( $output === $input || ! str_contains( $output, 'data:font/' ) ) {
				$diagnostics[] = self::diagnostic( 'producer_svg_consumer_embedding_failed' );
				return new WP_Error( 'static_site_importer_font_materialization_svg_consumer_failed', '', $diagnostics );
			}
			$writes[]   = self::write( $consumer['write']['target_path'], $output, $consumer['source_path'] );
			$receipts[] = array(
				'schema'                        => 'static-site-importer/svg-font-materialization-receipt/v1',
				'consumer_id'                   => $consumer['id'],
				'target_path'                   => $consumer['write']['target_path'],
				'write_reconciliation_identity' => $consumer['write']['reconciliation_identity'],
				'input_sha256'                  => $consumer['pre_transform_payload_hash'],
				'output_sha256'                 => hash( 'sha256', $output ),
				'face_ids'                      => $consumer['face_ids'],
				'receipt_ids'                   => $consumer['receipt_ids'],
				'required'                      => $consumer['required'],
				'observed_font_sha256'          => array_values( array_unique( $digests ) ),
			);
		}
		return array(
			'writes'   => $writes,
			'receipts' => $receipts,
		);
	}

	private static function expected_digest_matches( string $expected, string $payload ): bool {
		if ( '' === $expected ) {
			return true;
		}
		$expected = strtolower( str_starts_with( $expected, 'sha256:' ) ? substr( $expected, 7 ) : $expected );
		return 64 === strlen( $expected ) && ctype_xdigit( $expected ) && hash_equals( $expected, hash( 'sha256', $payload ) );
	}

	/** @return array<int,string> */
	private static function matching_producer_blocks( string $css, array $face ): array {
		if ( '' === $css || ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/is', $css, $matches ) ) {
			return array();
		}
		$blocks = array();
		foreach ( $matches[0] as $index => $block ) {
			$declarations = $matches[1][ $index ];
			$family       = self::css_declaration( $declarations, 'font-family' );
			$style_value  = self::css_declaration( $declarations, 'font-style' );
			$style        = '' !== $style_value ? $style_value : 'normal';
			$weight       = self::css_declaration( $declarations, 'font-weight' );
			if ( self::normalize_font_family( $family ) === self::normalize_font_family( (string) $face['family'] ) && $style === $face['style'] && self::weight_matches( $weight, $face['weight'] ) ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	private static function css_declaration( string $css, string $property ): string {
		return preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)/i', $css, $match ) ? trim( $match[1] ) : '';
	}

	private static function weight_matches( string $actual, array $expected ): bool {
		$actual = trim( preg_replace( '/\s+/', ' ', $actual ) ?? '' );
		if ( 'static' === $expected['kind'] ) {
			if ( (string) $expected['value'] === $actual ) {
				return true;
			}
			return preg_match( '/^(\d+)\s+(\d+)$/', $actual, $range ) && $expected['value'] >= (int) $range[1] && $expected['value'] <= (int) $range[2];
		}
		return ( $expected['min'] . ' ' . $expected['max'] ) === $actual || ( $expected['min'] . '..' . $expected['max'] ) === $actual;
	}

	/** @return array<int,string> */
	private static function font_urls( string $css ): array {
		if ( ! preg_match_all( '/url\(\s*(["\']?)([^"\'\s\)]+)\1\s*\)/i', $css, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[2] ) );
	}

	/** @param array<int,array<string,string>> $diagnostics */
	private static function download_font_asset( string $url, int &$total, array &$diagnostics, string $expected_sha256 = '' ) {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) ? strtolower( (string) ( $parts['path'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! preg_match( '/\.woff2?$/', $path ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_url_invalid' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_url_invalid', '', $diagnostics );
		}
		$response = self::request( $url, self::FONT_LIMIT );
		$payload  = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $payload || strlen( $payload ) > self::FONT_LIMIT || self::TOTAL_FONT_LIMIT < $total + strlen( $payload ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_fetch_failed' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_fetch_failed', '', $diagnostics );
		}
		$total += strlen( $payload );
		$hash   = hash( 'sha256', $payload );
		if ( '' !== $expected_sha256 && ! hash_equals( strtolower( $expected_sha256 ), $hash ) ) {
			$diagnostics[] = self::diagnostic( 'producer_font_digest_mismatch' );
			return new WP_Error( 'static_site_importer_font_materialization_producer_font_digest_mismatch', '', $diagnostics );
		}
		$format = str_ends_with( $path, '.woff2' ) ? 'woff2' : 'woff';
		return array(
			'target_path'     => 'assets/fonts/' . $hash . '.' . $format,
			'payload'         => $payload,
			'format'          => $format,
			'expected_sha256' => $expected_sha256,
			'observed_sha256' => $hash,
		);
	}

	/** Return public receipt evidence without carrying the downloaded binary payload. */
	private static function asset_receipt( array $asset, string $source_url ): array {
		return array(
			'target_path'     => $asset['target_path'],
			'format'          => $asset['format'],
			'source_url'      => $source_url,
			'expected_sha256' => $asset['expected_sha256'],
			'observed_sha256' => $asset['observed_sha256'],
		);
	}

	/** @param array<int,array<string,mixed>> $writes @param array<int,array<string,mixed>> $required_faces @param array<int,array<string,string>> $diagnostics */
	private static function with_runtime_registration( array $writes, array $resolved_plan, array $required_faces, array $diagnostics, array $faces = array(), array $svg_receipts = array(), array $svg_consumers = array() ) {
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
		$writes[]   = self::write( 'functions.php', $bootstrap, 'theme.font_materialization' );
		if ( ! empty( $required_faces ) ) {
			$writes[] = self::write( 'assets/js/font-readiness.js', self::readiness_script( $required_faces ), 'theme.font_materialization' );
		}
		return array(
			'writes'         => $writes,
			'diagnostics'    => $diagnostics,
			'faces'          => $faces,
			'required_faces' => $required_faces,
			'svg_receipts'   => $svg_receipts,
			'svg_consumers'  => $svg_consumers,
		);
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
			$rows[] = array(
				'path'    => 'assets/css/fonts.css',
				'content' => trim( (string) $plan['css'] ) . "\n",
			);
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
			$target = is_scalar( $write['target_path'] ?? null ) ? (string) $write['target_path'] : '';
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

	/** @param array<int,string> $families @param array<int,array<string,string>> $diagnostics
	 *  @return array{state:'embedded',css:string}
	 *          | array{state:'preserved',reason:string,observed_bytes:int,url:string,aggregate_bytes?:int}
	 */
	private static function resolve_google_font_faces( array $plan, array $families, array &$diagnostics ): array {
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
			return array(
				'state'          => 'preserved',
				'reason'         => 'stylesheet_import_missing',
				'observed_bytes' => 0,
				'url'            => '',
			);
		}
		foreach ( $imports as $import ) {
			if ( ! self::is_google_stylesheet_url( $import ) ) {
				$diagnostics[] = self::diagnostic( 'untrusted_stylesheet_url' );
				return array(
					'state'          => 'preserved',
					'reason'         => 'untrusted_stylesheet_url',
					'observed_bytes' => 0,
					'url'            => (string) $import,
				);
			}
		}

		$faces              = array();
		$font_payloads      = array();
		$font_payload_bytes = 0;
		foreach ( $imports as $import ) {
			$response = self::request( $import, self::CSS_LIMIT );
			$css      = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $css ) {
				$diagnostics[] = self::diagnostic_with_detail(
					'stylesheet_fetch_failed',
					array(
						'url'            => $import,
						'observed_bytes' => strlen( $css ),
						'limit_bytes'    => self::CSS_LIMIT,
					)
				);
				return array(
					'state'          => 'preserved',
					'reason'         => 'stylesheet_fetch_failed',
					'observed_bytes' => strlen( $css ),
					'url'            => $import,
				);
			}
			if ( strlen( $css ) > self::CSS_LIMIT ) {
				$diagnostics[] = self::diagnostic_with_detail(
					'google_fonts_stylesheet_preserved_due_to_size',
					array(
						'url'            => $import,
						'observed_bytes' => strlen( $css ),
						'limit_bytes'    => self::CSS_LIMIT,
					)
				);
				return array(
					'state'          => 'preserved',
					'reason'         => 'google_fonts_stylesheet_preserved_due_to_size',
					'observed_bytes' => strlen( $css ),
					'url'            => $import,
				);
			}
			$embedded = self::embed_font_sources( $css, $families, $font_payloads, $font_payload_bytes, $diagnostics );
			if ( 'preserved' === $embedded['state'] ) {
				if ( '' === (string) $embedded['url'] ) {
					$embedded['url'] = $import;
				}
				return $embedded;
			}
			$faces[] = (string) $embedded['css'];
		}
		return array(
			'state' => 'embedded',
			'css'   => implode( "\n", $faces ),
		);
	}

	/** @param array<int,string> $families @param array<string,string> $payloads @param array<int,array<string,string>> $diagnostics
	 *  @return array{state:'embedded',css:string}
	 *          | array{state:'preserved',reason:string,observed_bytes:int,url:string,aggregate_bytes?:int}
	 */
	private static function embed_font_sources( string $css, array $families, array &$payloads, int &$payload_bytes, array &$diagnostics ): array {
		if ( ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/is', $css, $faces ) ) {
			$diagnostics[] = self::diagnostic_with_detail(
				'stylesheet_font_faces_missing',
				array(
					'observed_bytes' => strlen( $css ),
					'limit_bytes'    => self::CSS_LIMIT,
				)
			);
			return array(
				'state'          => 'preserved',
				'reason'         => 'stylesheet_font_faces_missing',
				'observed_bytes' => strlen( $css ),
				'url'            => '',
			);
		}
		$embedded           = array();
		$current_source_url = '';
		foreach ( $faces[0] as $index => $face ) {
			$current_source_url = '';
			if ( ! preg_match( '/font-family\s*:\s*(["\']?)([^;"\']+)\1\s*;/i', $faces[1][ $index ], $family ) || ! in_array( trim( $family[2] ), $families, true ) ) {
				continue;
			}
			if ( str_contains( $face, '<' ) || ! preg_match_all( '/url\(\s*(["\']?)([^"\'\s\)]+)\1\s*\)/i', $face, $urls ) ) {
				$diagnostics[] = self::diagnostic( 'untrusted_font_url' );
				return array(
					'state'          => 'preserved',
					'reason'         => 'untrusted_font_url',
					'observed_bytes' => 0,
					'url'            => $current_source_url,
				);
			}
			$rewritten = $face;
			foreach ( array_unique( $urls[2] ) as $url ) {
				$current_source_url = $url;
				$parts              = wp_parse_url( $url );
				$path               = is_array( $parts ) ? strtolower( (string) ( $parts['path'] ?? '' ) ) : '';
				if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || 'fonts.gstatic.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! preg_match( '/\.woff2?$/', $path ) ) {
					$diagnostics[] = self::diagnostic( 'untrusted_font_url' );
					return array(
						'state'          => 'preserved',
						'reason'         => 'untrusted_font_url',
						'observed_bytes' => 0,
						'url'            => $url,
					);
				}
				if ( ! isset( $payloads[ $url ] ) ) {
					$response = self::request( $url, self::FONT_LIMIT );
					$payload  = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
					$observed = strlen( $payload );
					if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $payload || $observed > self::FONT_LIMIT ) {
						$diagnostics[] = self::diagnostic_with_detail(
							'font_payload_fetch_failed',
							array(
								'url'            => $url,
								'observed_bytes' => $observed,
								'limit_bytes'    => self::FONT_LIMIT,
							)
						);
						return array(
							'state'          => 'preserved',
							'reason'         => 'font_payload_fetch_failed',
							'observed_bytes' => $observed,
							'url'            => $url,
						);
					}
					if ( self::TOTAL_FONT_LIMIT < $payload_bytes + $observed ) {
						$diagnostics[] = self::diagnostic_with_detail(
							'google_fonts_payloads_partial_preserved',
							array(
								'url'             => $url,
								'observed_bytes'  => $observed,
								'aggregate_bytes' => $payload_bytes,
								'limit_bytes'     => self::TOTAL_FONT_LIMIT,
							)
						);
						return array(
							'state'           => 'preserved',
							'reason'          => 'google_fonts_payloads_partial_preserved',
							'observed_bytes'  => $observed,
							'aggregate_bytes' => $payload_bytes,
							'url'             => $url,
						);
					}
					$payloads[ $url ] = $payload;
					$payload_bytes   += $observed;
				}
				$mime      = str_ends_with( $path, '.woff2' ) ? 'font/woff2' : 'font/woff';
				$rewritten = str_replace( $url, 'data:' . $mime . ';base64,' . base64_encode( $payloads[ $url ] ), $rewritten ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a fetched font asset for a CSS data URL.
			}
			$embedded[] = $rewritten;
		}
		if ( empty( $embedded ) ) {
			$diagnostics[] = self::diagnostic( 'matching_font_faces_missing' );
		}
		return array(
			'state' => 'embedded',
			'css'   => implode( "\n", $embedded ),
		);
	}

	private static function request( string $url, int $limit ) {
		$args     = array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => $limit,
			'headers'             => array( 'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' ),
		);
		$response = null;
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$response = wp_safe_remote_get( $url, $args );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
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
			$family = self::normalize_font_family( $family );
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
		$value   = preg_replace( '/\s*!important\s*$/i', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 ) ) ?? $value;
		$tokens  = array();
		$token   = '';
		$quote   = '';
		$escaped = false;
		$length  = strlen( $value );
		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			if ( $escaped ) {
				$token  .= $character;
				$escaped = false;
				continue;
			}
			if ( '\\' === $character ) {
				$token  .= $character;
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
				$quote  = $character;
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
			if ( 0 === strcmp( $target, (string) ( $write['target_path'] ?? '' ) ) ) {
				return self::payload_content( $write );
			}
		}
		return null;
	}

	private static function write_payload_hash( array $write ): string {
		$content = self::payload_content( $write );
		return null === $content ? '' : hash( 'sha256', $content );
	}

	private static function valid_sha256( mixed $hash ): bool {
		return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
	}

	private static function payload_content( mixed $write ): ?string {
		if ( ! is_array( $write ) || ! is_array( $write['payload'] ?? null ) || ! is_string( $write['payload']['data'] ?? null ) ) {
			return null;
		}
		if ( 'base64' === ( $write['payload']['encoding'] ?? null ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the declared canonical binary payload.
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
		return array(
			'target_path' => $target,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes an explicitly declared binary write payload.
			'content'     => 'base64' === $encoding ? base64_encode( $content ) : $content,
			'source_path' => $source,
			'encoding'    => $encoding,
		);
	}

	private static function is_google_stylesheet_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && 'fonts.googleapis.com' === strtolower( (string) ( $parts['host'] ?? '' ) ) && ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && in_array( (string) ( $parts['path'] ?? '' ), array( '/css', '/css2' ), true );
	}

	private static function resolved_plan_has_google_stylesheet( array $resolved_plan ): bool {
		foreach ( $resolved_plan['pages'] ?? array() as $page ) {
			$links = is_array( $page ) && is_array( $page['document_metadata']['links'] ?? null ) ? $page['document_metadata']['links'] : array();
			foreach ( $links as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				foreach ( array( 'resolved_url', 'url', 'href' ) as $field ) {
					if ( is_string( $link[ $field ] ?? null ) && self::is_google_stylesheet_url( $link[ $field ] ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** @return array{type:string,source:string,reason:string} */
	private static function diagnostic( string $reason ): array {
		return array(
			'type'   => 'font_materialization_failed',
			'source' => 'static-site-importer/font-materializer',
			'reason' => $reason,
		);
	}

	/** @return array{type:string,source:string,reason:string,details:array<string,mixed>} */
	private static function diagnostic_with_detail( string $reason, array $details ): array {
		return array(
			'type'    => 'font_materialization_failed',
			'source'  => 'static-site-importer/font-materializer',
			'reason'  => $reason,
			'details' => $details,
		);
	}
}
