<?php
/**
 * Bounded, byte-preserving inspection of local ICO assets (no pixel decoder).
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Ico_Asset {

	/**
	 * Dimensions describe the largest entry by area, then width, then height.
	 * Unsupported entries never make a partially validated container supported.
	 *
	 * @return array{status:string,reason:string,width:int,height:int}
	 */
	public static function inspect( string $bytes ): array {
		$result = array( 'status' => 'invalid_ico', 'reason' => 'invalid_header', 'width' => 0, 'height' => 0 );
		$length = strlen( $bytes );
		if ( $length > 2097152 ) {
			$result['reason'] = 'byte_limit';
			return $result;
		}
		if ( $length < 6 ) {
			return $result;
		}
		$header = unpack( 'vreserved/vtype/vcount', $bytes );
		if ( 0 !== $header['reserved'] || 1 !== $header['type'] ) {
			$result['reason'] = 2 === $header['type'] ? 'cursor_not_icon' : 'invalid_header';
			return $result;
		}
		if ( $header['count'] < 1 || $header['count'] > 64 || $length < 6 + 16 * $header['count'] ) {
			$result['reason'] = 'invalid_entry_count';
			return $result;
		}
		$entries = array();
		foreach ( range( 0, $header['count'] - 1 ) as $index ) {
			$entry = unpack( 'Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', $bytes, 6 + 16 * $index );
			$entry['width']  = $entry['width'] ?: 256;
			$entry['height'] = $entry['height'] ?: 256;
			if ( 0 !== $entry['reserved'] || $entry['planes'] > 1 || 0 === $entry['size'] || $entry['offset'] < 6 + 16 * $header['count'] || $entry['offset'] > $length || $entry['size'] > $length - $entry['offset'] ) {
				$result['reason'] = 'invalid_directory_entry';
				return $result;
			}
			foreach ( $entries as $prior ) {
				if ( $entry['offset'] < $prior['offset'] + $prior['size'] && $prior['offset'] < $entry['offset'] + $entry['size'] ) {
					$result['reason'] = 'overlapping_payloads';
					return $result;
				}
			}
			$entries[] = $entry;
			if ( array( $entry['width'] * $entry['height'], $entry['width'], $entry['height'] ) > array( $result['width'] * $result['height'], $result['width'], $result['height'] ) ) {
				$result['width']  = $entry['width'];
				$result['height'] = $entry['height'];
			}
		}
		$unsupported = '';
		foreach ( $entries as $entry ) {
			$payload = substr( $bytes, $entry['offset'], $entry['size'] );
			$check   = str_starts_with( $payload, "\x89PNG\r\n\x1a\n" ) ? self::png( $payload, $entry ) : self::dib( $payload, $entry );
			if ( 'invalid_ico' === $check[0] ) {
				$result['reason'] = $check[1];
				return $result;
			}
			if ( 'unsupported_ico' === $check[0] && '' === $unsupported ) {
				$unsupported = $check[1];
			}
		}
		$result['status'] = '' === $unsupported ? 'supported' : 'unsupported_ico';
		$result['reason'] = '' === $unsupported ? 'validated' : $unsupported;
		return $result;
	}

	/** Validate PNG framing and filtered scanline layout, without unfiltering pixels. */
	private static function png( string $bytes, array $entry ): array {
		$invalid = static fn( string $reason ): array => array( 'invalid_ico', $reason );
		$offset = 8;
		$length = strlen( $bytes );
		$ihdr = null;
		$palette = 0;
		$idat = '';
		$seen_idat = false;
		$ended_idat = false;
		$ended = false;
		$seen = array();
		$unsupported = '';
		while ( $offset < $length ) {
			if ( $length - $offset < 12 ) {
				return $invalid( 'png_truncated_chunk' );
			}
			$size = unpack( 'Nsize', $bytes, $offset )['size'];
			$type = substr( $bytes, $offset + 4, 4 );
			if ( $size > $length - $offset - 12 || ! preg_match( '/^[A-Za-z]{2}[A-Z][A-Za-z]$/D', $type ) ) {
				return $invalid( 'png_invalid_chunk' );
			}
			$data = substr( $bytes, $offset + 8, $size );
			if ( hash( 'crc32b', $type . $data, true ) !== substr( $bytes, $offset + 8 + $size, 4 ) ) {
				return $invalid( 'png_crc' );
			}
			if ( null === $ihdr && 'IHDR' !== $type ) {
				return $invalid( 'png_ihdr_first' );
			}
			if ( 'IDAT' !== $type && $seen_idat ) {
				$ended_idat = true;
			}
			if ( 'IHDR' === $type ) {
				if ( null !== $ihdr || 13 !== $size ) {
					return $invalid( 'png_invalid_ihdr' );
				}
				$ihdr = unpack( 'Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data );
				$depths = array( 0 => array( 1, 2, 4, 8, 16 ), 2 => array( 8, 16 ), 3 => array( 1, 2, 4, 8 ), 4 => array( 8, 16 ), 6 => array( 8, 16 ) );
				if ( $ihdr['width'] !== $entry['width'] || $ihdr['height'] !== $entry['height'] || ! isset( $depths[ $ihdr['color'] ] ) || ! in_array( $ihdr['depth'], $depths[ $ihdr['color'] ], true ) || $ihdr['compression'] || $ihdr['filter'] || $ihdr['interlace'] > 1 ) {
					return $invalid( 'png_invalid_ihdr' );
				}
				$channels = array( 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4 );
				$bits = $channels[ $ihdr['color'] ] * $ihdr['depth'];
				if ( 0 !== $entry['bits'] && $entry['bits'] !== $bits ) {
					return $invalid( 'png_directory_bits' );
				}
			} elseif ( 'PLTE' === $type ) {
				if ( $palette || $seen_idat || isset( $seen['tRNS'] ) || 0 === $size || $size % 3 || $size > 768 || in_array( $ihdr['color'], array( 0, 4 ), true ) || ( 3 === $ihdr['color'] && $size / 3 > ( 1 << $ihdr['depth'] ) ) ) {
					return $invalid( 'png_invalid_palette' );
				}
				$palette = intdiv( $size, 3 );
			} elseif ( 'IDAT' === $type ) {
				if ( $ended_idat || ( 3 === $ihdr['color'] && ! $palette ) ) {
					return $invalid( 'png_idat_order' );
				}
				$seen_idat = true;
				$idat .= $data;
			} elseif ( 'IEND' === $type ) {
				if ( $size || ! $seen_idat || $offset + 12 !== $length ) {
					return $invalid( 'png_invalid_iend' );
				}
				$ended = true;
			} elseif ( 'tRNS' === $type ) {
				if ( isset( $seen[$type] ) || $seen_idat || ! in_array( $ihdr['color'], array( 0, 2, 3 ), true ) || ( 3 === $ihdr['color'] ? ( ! $palette || ! $size || $size > $palette ) : $size !== ( 0 === $ihdr['color'] ? 2 : 6 ) ) ) {
					return $invalid( 'png_invalid_transparency' );
				}
				if ( 3 !== $ihdr['color'] ) {
					foreach ( unpack( 'n*', $data ) as $sample ) {
						if ( $sample >= ( 1 << $ihdr['depth'] ) ) {
							return $invalid( 'png_invalid_transparency' );
						}
					}
				}
			} elseif ( ord( $type[0] ) <= 90 || in_array( $type, array( 'acTL', 'fcTL', 'fdAT' ), true ) ) {
				$unsupported = 'png_unsupported_chunk';
			}
			$seen[$type] = true;
			$offset += 12 + $size;
		}
		if ( ! $ended || '' === $idat || ( 0 !== $entry['colors'] && $entry['colors'] !== $palette ) ) {
			return $invalid( 'png_incomplete' );
		}
		// Adam7 passes only change scanline geometry; no samples are reconstructed.
		$passes = $ihdr['interlace'] ? array( array( 0, 0, 8, 8 ), array( 4, 0, 8, 8 ), array( 0, 4, 4, 8 ), array( 2, 0, 4, 4 ), array( 0, 2, 2, 4 ), array( 1, 0, 2, 2 ), array( 0, 1, 1, 2 ) ) : array( array( 0, 0, 1, 1 ) );
		$rows = array();
		$expected = 0;
		foreach ( $passes as [$x, $y, $dx, $dy] ) {
			$width  = max( 0, intdiv( $ihdr['width'] - $x + $dx - 1, $dx ) );
			$height = max( 0, intdiv( $ihdr['height'] - $y + $dy - 1, $dy ) );
			if ( ! $width || ! $height ) {
				continue;
			}
			$row = 1 + intdiv( $width * $bits + 7, 8 );
			$rows[] = array( $height, $row );
			$expected += $height * $row;
		}
		if ( ! function_exists( 'gzuncompress' ) || ! function_exists( 'inflate_get_read_len' ) ) {
			return array( 'unsupported_ico', 'png_zlib_unavailable' );
		}
		$scanlines = @gzuncompress( $idat, $expected + 1 );
		if ( false === $scanlines || strlen( $scanlines ) !== $expected ) {
			return $invalid( 'png_scanline_length' );
		}
		// The bounded decompression above admits this stream before checking its end.
		$stream = inflate_init( ZLIB_ENCODING_DEFLATE );
		if ( false === @inflate_add( $stream, $idat, ZLIB_FINISH ) || ZLIB_STREAM_END !== inflate_get_status( $stream ) || strlen( $idat ) !== inflate_get_read_len( $stream ) ) {
			return $invalid( 'png_deflate_trailing_data' );
		}
		$offset = 0;
		foreach ( $rows as [$height, $row] ) {
			for ( $y = 0; $y < $height; ++$y ) {
				if ( ord( $scanlines[$offset] ) > 4 ) {
					return $invalid( 'png_scanline_filter' );
				}
				$offset += $row;
			}
		}
		return array( '' === $unsupported ? 'supported' : 'unsupported_ico', '' === $unsupported ? 'validated' : $unsupported );
	}

	private static function dib( string $bytes, array $entry ): array {
		$invalid = static fn( string $reason ): array => array( 'invalid_ico', $reason );
		if ( strlen( $bytes ) < 4 ) {
			return $invalid( 'dib_truncated_header' );
		}
		$size = unpack( 'Vsize', $bytes )['size'];
		if ( ! in_array( $size, array( 12, 40, 108, 124 ), true ) ) {
			return array( 'unsupported_ico', 'dib_unsupported_header' );
		}
		if ( strlen( $bytes ) < $size ) {
			return $invalid( 'dib_truncated_header' );
		}
		$header = 12 === $size ? unpack( 'vwidth/vheight/vplanes/vbits', $bytes, 4 ) : unpack( 'Vwidth/Vheight/vplanes/vbits/Vcompression/Vimage_size/Vxppm/Vyppm/Vcolors/Vimportant', $bytes, 4 );
		if ( $header['width'] !== $entry['width'] || $header['height'] !== 2 * $entry['height'] || 1 !== $header['planes'] || ( $entry['bits'] && $entry['bits'] !== $header['bits'] ) ) {
			return $invalid( 'dib_invalid_dimensions_or_planes' );
		}
		if ( ! empty( $header['compression'] ) ) {
			return array( 'unsupported_ico', 'dib_unsupported_compression' );
		}
		if ( ! in_array( $header['bits'], array( 1, 4, 8, 24, 32 ), true ) ) {
			return array( 'unsupported_ico', 16 === $header['bits'] ? 'dib_unsupported_16_bit' : 'dib_unsupported_bit_depth' );
		}
		if ( $size >= 108 ) {
			// BI_RGB has no bitfield masks. Embedded/linked profiles are not local pixels.
			if ( substr( $bytes, 40, 16 ) !== str_repeat( "\0", 16 ) ) {
				return array( 'unsupported_ico', 'dib_unsupported_masks' );
			}
			if ( 124 === $size ) {
				$profile = unpack( 'Voffset/Vsize/Vreserved', $bytes, 112 );
				if ( $profile['reserved'] ) {
					return $invalid( 'dib_reserved' );
				}
				if ( $profile['offset'] || $profile['size'] ) {
					return array( 'unsupported_ico', 'dib_unsupported_profile' );
				}
			}
			$color_space = unpack( 'Vtype', $bytes, 56 )['type'];
			if ( ! in_array( $color_space, array( 0, 0x73524742, 0x57696e20 ), true ) ) {
				return array( 'unsupported_ico', 'dib_unsupported_color_space' );
			}
		}
		$colors = $header['colors'] ?? 0;
		if ( $header['bits'] <= 8 ) {
			$colors = $colors ?: 1 << $header['bits'];
			if ( $colors > ( 1 << $header['bits'] ) ) {
				return $invalid( 'dib_palette_bounds' );
			}
		} elseif ( $colors ) {
			return array( 'unsupported_ico', 'dib_truecolor_palette' );
		}
		if ( ( $entry['colors'] && $entry['colors'] !== $colors ) || ( $header['important'] ?? 0 ) > $colors ) {
			return $invalid( 'dib_palette_bounds' );
		}
		$pixels = intdiv( $entry['width'] * $header['bits'] + 31, 32 ) * 4 * $entry['height'];
		$mask   = intdiv( $entry['width'] + 31, 32 ) * 4 * $entry['height'];
		if ( ! empty( $header['image_size'] ) && ! in_array( $header['image_size'], array( $pixels, $pixels + $mask ), true ) ) {
			return $invalid( 'dib_image_size' );
		}
		if ( strlen( $bytes ) !== $size + $colors * ( 12 === $size ? 3 : 4 ) + $pixels + $mask ) {
			return $invalid( 'dib_pixel_or_mask_bounds' );
		}
		return array( 'supported', 'validated' );
	}
}
