<?php
/**
 * Run: php tests/smoke-ico-site-identity.php
 * Includes fake-runtime receipt and upload isolation checks.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once ABSPATH . 'includes/class-static-site-importer-ico-asset.php';

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . hash( 'crc32b', $type . $data, true );
$png = static function ( int $width = 16, int $height = 16, int $depth = 8, int $color = 6, ?string $rows = null, string $palette = '', int $interlace = 0 ) use ( $chunk ): string {
	$channels = array( 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4 );
	$rows = $rows ?? str_repeat( "\0" . str_repeat( "\0", intdiv( $width * $depth * $channels[$color] + 7, 8 ) ), $height );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, $depth, $color, 0, 0, $interlace ) ) . ( '' === $palette ? '' : $chunk( 'PLTE', $palette ) ) . $chunk( 'IDAT', gzcompress( $rows ) ) . $chunk( 'IEND', '' );
};
$dib = static function ( int $width = 16, int $height = 16, int $bits = 32, int $size = 40, int $compression = 0, ?int $colors = null ): string {
	$colors = $colors ?? ( $bits <= 8 ? 1 << $bits : 0 );
	$header = 12 === $size ? pack( 'Vvvvv', 12, $width, 2 * $height, 1, $bits ) : pack( 'VVVvvVVVVVV', $size, $width, 2 * $height, 1, $bits, $compression, 0, 0, 0, $colors, 0 ) . str_repeat( "\0", $size - 40 );
	return $header . str_repeat( "\0", $colors * ( 12 === $size ? 3 : 4 ) + intdiv( $width * $bits + 31, 32 ) * 4 * $height + intdiv( $width + 31, 32 ) * 4 * $height );
};
$ico = static function ( array $entries ): string {
	$directory = pack( 'vvv', 0, 1, count( $entries ) );
	$payloads = '';
	$offset = 6 + count( $entries ) * 16;
	foreach ( $entries as [$payload, $width, $height, $bits, $colors] ) {
		$directory .= pack( 'CCCCvvVV', $width % 256, $height % 256, $colors % 256, 0, 1, $bits, strlen( $payload ), $offset );
		$offset += strlen( $payload );
		$payloads .= $payload;
	}
	return $directory . $payloads;
};
$wrap = static fn( string $payload, int $bits = 32, int $width = 16, int $height = 16, int $colors = 0 ): string => $ico( array( array( $payload, $width, $height, $bits, $colors ) ) );
$check = static function ( string $bytes, string $status, string $label, ?string $reason = null ) use ( $assert ): array {
	$before = hash( 'sha256', $bytes );
	$result = Static_Site_Importer_Ico_Asset::inspect( $bytes );
	$assert( $result['status'] === $status, $label . ': ' . json_encode( $result ) );
	$assert( '' !== $result['reason'] && ( null === $reason || $reason === $result['reason'] ), $label . ' reason' );
	$assert( $before === hash( 'sha256', $bytes ), $label . ' preserves bytes' );
	return $result;
};

$valid = $wrap( $dib() );
$png_valid = $wrap( $png() );
$check( $valid, 'supported', '32-bit DIB' );
$check( $png_valid, 'supported', 'RGBA PNG' );
foreach ( array( 12, 40, 108, 124 ) as $size ) {
	foreach ( array( 1, 4, 8, 24, 32 ) as $bits ) {
		$check( $wrap( $dib( 17, 9, $bits, $size ), $bits, 17, 9 ), 'supported', "DIB $size/$bits padded rows" );
	}
}
foreach ( array( 0 => array( 1, 2, 4, 8, 16 ), 2 => array( 8, 16 ), 3 => array( 1, 2, 4, 8 ), 4 => array( 8, 16 ), 6 => array( 8, 16 ) ) as $color => $depths ) {
	foreach ( $depths as $depth ) {
		$check( $wrap( $png( 16, 16, $depth, $color, null, 3 === $color ? str_repeat( "\0", 3 * ( 1 << $depth ) ) : '' ), 0 ), 'supported', "PNG color $color depth $depth" );
	}
}
$largest = $ico( array( array( $dib(), 16, 16, 32, 0 ), array( $png( 256, 256 ), 256, 256, 32, 0 ) ) );
$result = $check( $largest, 'supported', 'multi-image zero means 256' );
$assert( 256 === $result['width'] && 256 === $result['height'], 'largest validated dimensions' );
$check( $ico( array_fill( 0, 64, array( $dib( 1, 1 ), 1, 1, 32, 0 ) ) ), 'supported', '64 entries' );
$check( $ico( array_fill( 0, 65, array( $dib( 1, 1 ), 1, 1, 32, 0 ) ) ), 'invalid_ico', '65 entries' );
$check( '', 'invalid_ico', 'empty' );
$check( pack( 'vvv', 0, 1, 0 ), 'invalid_ico', 'zero count' );
$check( str_repeat( 'x', 2097153 ), 'invalid_ico', 'byte cap', 'byte_limit' );
$check( substr_replace( $valid, pack( 'v', 2 ), 2, 2 ), 'invalid_ico', 'cursor', 'cursor_not_icon' );
$check( substr_replace( $valid, "\1", 0, 1 ), 'invalid_ico', 'header reserved' );
$check( substr_replace( $valid, "\1", 9, 1 ), 'invalid_ico', 'entry reserved byte 3' );
$check( substr_replace( $valid, pack( 'v', 2 ), 10, 2 ), 'invalid_ico', 'directory planes' );
$check( substr_replace( $valid, pack( 'V', 6 ), 18, 4 ), 'invalid_ico', 'payload inside directory' );
$check( substr_replace( $valid, pack( 'V', 0xffffffff ), 14, 4 ), 'invalid_ico', 'size overflow' );
$check( substr_replace( $valid, pack( 'V', 0xffffffff ), 18, 4 ), 'invalid_ico', 'offset overflow' );
$check( substr_replace( $largest, pack( 'V', 38 ), 34, 4 ), 'invalid_ico', 'overlap', 'overlapping_payloads' );
$check( $wrap( substr( $dib(), 0, -1 ) ), 'invalid_ico', 'truncated AND mask' );
$check( $wrap( $dib() . "\0" ), 'invalid_ico', 'DIB trailing bytes' );
$check( $wrap( $dib( 15, 16 ) ), 'invalid_ico', 'DIB dimensions mismatch' );
$check( $wrap( substr_replace( $dib(), pack( 'V', 16 ), 8, 4 ) ), 'invalid_ico', 'DIB height must include mask' );
$check( $wrap( substr_replace( $dib(), pack( 'V', 1 ), 20, 4 ) ), 'invalid_ico', 'DIB declared image size' );
$check( $wrap( $dib( 16, 16, 8, 40, 0, 257 ), 8 ), 'invalid_ico', 'oversized palette' );
$check( $wrap( substr( $dib( 16, 16, 8 ), 0, -1024 ), 8 ), 'invalid_ico', 'missing palette' );
$unsupported = $wrap( $dib( 16, 16, 16 ), 16 );
$check( $unsupported, 'unsupported_ico', '16-bit explicit unsupported', 'dib_unsupported_16_bit' );
$check( $wrap( $dib( 16, 16, 8, 40, 1 ), 8 ), 'unsupported_ico', 'RLE explicit unsupported', 'dib_unsupported_compression' );
$check( $wrap( pack( 'V', 64 ) . str_repeat( "\0", 60 ) ), 'unsupported_ico', 'other DIB header' );
$check( $wrap( substr_replace( $dib( 16, 16, 32, 124 ), pack( 'V', 124 ), 112, 4 ) ), 'unsupported_ico', 'V5 profile' );
$mixed = $ico( array( array( $dib( 16, 16, 16 ), 16, 16, 16, 0 ), array( substr( $dib(), 0, -1 ), 16, 16, 32, 0 ) ) );
$check( $mixed, 'invalid_ico', 'invalid outranks unsupported' );

$check( $wrap( substr_replace( $png(), "\1", 29, 1 ) ), 'invalid_ico', 'PNG CRC failure', 'png_crc' );
$check( $wrap( $png( 15, 16 ) ), 'invalid_ico', 'PNG dimension mismatch' );
$check( $wrap( $png() . 'trailing' ), 'invalid_ico', 'PNG trailing data' );
$check( $wrap( substr( $png(), 0, -12 ) ), 'invalid_ico', 'missing IEND' );
$check( $wrap( $png( 16, 16, 8, 6, str_repeat( "\0", 1000000 ) ) ), 'invalid_ico', 'bounded decompression bomb', 'png_scanline_length' );
$check( $wrap( $png( 16, 16, 8, 6, str_repeat( "\5" . str_repeat( "\0", 64 ), 16 ) ) ), 'invalid_ico', 'invalid scanline filter', 'png_scanline_filter' );
for ( $filter = 0; $filter <= 4; ++$filter ) {
	$check( $wrap( $png( 16, 16, 8, 6, str_repeat( chr( $filter ) . str_repeat( "\0", 64 ), 16 ) ) ), 'supported', 'filter ' . $filter );
}
$check( $wrap( $png( 16, 16, 1, 3 ), 1 ), 'invalid_ico', 'indexed PNG missing palette' );
$check( $wrap( $png( 16, 16, 1, 3, null, str_repeat( "\0", 9 ) ), 1 ), 'invalid_ico', 'indexed palette exceeds depth' );
$check( $wrap( $png( 16, 16, 8, 0, null, "\0\0\0" ), 8 ), 'invalid_ico', 'grayscale palette forbidden' );
$prefix = substr( $png(), 0, 33 );
$scanlines = str_repeat( "\0" . str_repeat( "\0", 64 ), 16 );
$check( $wrap( $prefix . $chunk( 'IHDR', substr( $png(), 16, 13 ) ) . substr( $png(), 33 ) ), 'invalid_ico', 'duplicate IHDR' );
$check( $wrap( $prefix . $chunk( 'IDAT', gzcompress( $scanlines ) . 'junk' ) . $chunk( 'IEND', '' ) ), 'invalid_ico', 'zlib trailing bytes' );
$compressed = gzcompress( $scanlines );
$check( $wrap( $prefix . $chunk( 'IDAT', substr( $compressed, 0, 5 ) ) . $chunk( 'tEXt', 'key' . "\0value" ) . $chunk( 'IDAT', substr( $compressed, 5 ) ) . $chunk( 'IEND', '' ) ), 'invalid_ico', 'noncontiguous IDAT' );
$check( $wrap( $prefix . $chunk( 'ABCD', '' ) . substr( $png(), 33 ) ), 'unsupported_ico', 'unknown critical PNG chunk' );
$check( $wrap( $prefix . $chunk( 'PLTE', "\0\0\0" ) . $chunk( 'PLTE', "\0\0\0" ) . substr( $png(), 33 ) ), 'invalid_ico', 'duplicate PLTE' );
$check( $wrap( $prefix . $chunk( 'tRNS', "\0" ) . substr( $png(), 33 ) ), 'invalid_ico', 'RGBA transparency chunk' );
$rgb = $png( 16, 16, 8, 2 );
$check( $wrap( substr( $rgb, 0, 33 ) . $chunk( 'tRNS', pack( 'nnn', 256, 0, 0 ) ) . substr( $rgb, 33 ), 24 ), 'invalid_ico', 'transparency sample exceeds depth' );
$check( $wrap( substr( $rgb, 0, 33 ) . $chunk( 'tRNS', pack( 'nnn', 0, 0, 0 ) ) . $chunk( 'PLTE', "\0\0\0" ) . substr( $rgb, 33 ), 24 ), 'invalid_ico', 'PLTE after transparency' );
$check( $wrap( $prefix . $chunk( 'abca', '' ) . substr( $png(), 33 ) ), 'invalid_ico', 'PNG chunk reserved bit' );
$check( $wrap( $prefix . pack( 'N', 0xffffffff ) . 'IDAT' ), 'invalid_ico', 'chunk length overflow' );
$adam = '';
foreach ( array( array( 0, 0, 8, 8 ), array( 4, 0, 8, 8 ), array( 0, 4, 4, 8 ), array( 2, 0, 4, 4 ), array( 0, 2, 2, 4 ), array( 1, 0, 2, 2 ), array( 0, 1, 1, 2 ) ) as [$x, $y, $dx, $dy] ) {
	$adam .= str_repeat( "\0" . str_repeat( "\0", intdiv( 16 - $x + $dx - 1, $dx ) * 4 ), intdiv( 16 - $y + $dy - 1, $dy ) );
}
$check( $wrap( $png( 16, 16, 8, 6, $adam, '', 1 ) ), 'supported', 'Adam7 scanline bounds' );
$check( $wrap( $png( 16, 16, 8, 6, $scanlines, '', 1 ) ), 'invalid_ico', 'Adam7 wrong scanline geometry' );

{
	require_once ABSPATH . 'includes/class-static-site-importer-import-destination.php';
	require_once ABSPATH . 'includes/class-static-site-importer-site-plan-persistence.php';
	require_once ABSPATH . 'includes/class-static-site-importer-media-library-materializer.php';
	function get_option( $key, $default = false ) { return $GLOBALS['ico_options'][$key] ?? $default; }
	function update_option( $key, $value, ...$args ) { $GLOBALS['ico_options'][$key] = $value; return true; }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function sanitize_file_name( $name ) { return $name; }
	function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
	function get_posts( $args ) { return array(); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function update_post_meta( ...$args ) { return true; }
	function add_filter( ...$args ) { ++$GLOBALS['ico_filters']; }
	function remove_filter( ...$args ) { --$GLOBALS['ico_filters']; }
	function wp_check_filetype( $file ) { return array( 'type' => 'image/x-icon' ); }
	function wp_upload_bits( $name, $unused, $bytes ) {
		++$GLOBALS['ico_uploads'];
		$GLOBALS['ico_uploaded_bytes'] = $bytes;
		$file = $GLOBALS['ico_tmp'] . '/upload.ico';
		file_put_contents( $file, $bytes );
		return array( 'file' => $file, 'url' => 'https://example.test/uploads/icon.ico', 'error' => false );
	}
	function wp_insert_attachment( ...$args ) { ++$GLOBALS['ico_inserts']; return 73; }
	function wp_update_attachment_metadata( $id, $metadata ) { $GLOBALS['ico_metadata'] = $metadata; return true; }
	function _wp_relative_upload_path( $file ) { return basename( $file ); }
	function wp_generate_attachment_metadata( $id, $file ) {
		++$GLOBALS['ico_core_metadata_calls'];
		return array( 'width' => 16, 'height' => 16, 'sizes' => array() );
	}
	function wp_delete_file( $file ) { unlink( $file ); }
	$GLOBALS['ico_tmp'] = sys_get_temp_dir() . '/ssi-ico-' . bin2hex( random_bytes( 8 ) );
	mkdir( $GLOBALS['ico_tmp'] );
	try {
		foreach ( array( array( $valid, 'applied' ), array( $png_valid, 'applied' ), array( $unsupported, 'unsupported_ico' ), array( substr_replace( $valid, "\1", 9, 1 ), 'invalid_ico' ) ) as [$bytes, $expected] ) {
			$GLOBALS['ico_options'] = array();
			$GLOBALS['ico_uploads'] = $GLOBALS['ico_inserts'] = $GLOBALS['ico_filters'] = 0;
			$GLOBALS['ico_uploaded_bytes'] = null;
			$GLOBALS['ico_metadata'] = array();
			$GLOBALS['ico_core_metadata_calls'] = 0;
			if ( is_file( $GLOBALS['ico_tmp'] . '/upload.ico' ) ) {
				unlink( $GLOBALS['ico_tmp'] . '/upload.ico' );
			}
			file_put_contents( $GLOBALS['ico_tmp'] . '/icon.ico', $bytes );
			$state = array( 'args' => array( 'activate' => true, 'native_site_identity_evidence' => array( 'icon' => 'icon.ico', 'icon_source_path' => 'icon.ico' ) ), 'theme' => array( 'uri' => 'https://example.test/theme' ), 'theme_dir' => $GLOBALS['ico_tmp'], 'resolved' => array( 'writes' => array( array( 'source_path' => 'icon.ico', 'target_path' => 'icon.ico', 'kind' => 'theme_asset' ) ) ) );
			$report = Static_Site_Importer_Media_Library_Materializer::materialize_identity( $state );
			$assert( is_array( $report ) && ( $report['site_icon']['status'] ?? '' ) === $expected, 'identity receipt ' . $expected . ': ' . json_encode( $report ) );
			$assert( 0 === $GLOBALS['ico_filters'], 'no upload MIME filter leak' );
			if ( 'applied' === $expected ) {
				$assert( 1 === $GLOBALS['ico_uploads'] && 1 === $GLOBALS['ico_inserts'], 'one native attachment' );
				$assert( $bytes === $GLOBALS['ico_uploaded_bytes'], 'uploaded bytes unchanged' );
				$assert( 1 === $GLOBALS['ico_core_metadata_calls'], 'reuse core metadata helper' );
				$assert( 73 === get_option( 'site_icon' ) && array( 73 ) === ( $state['applied']['attachments'] ?? array() ), 'native option and rollback attachment journal' );
				$assert( 16 === ( $GLOBALS['ico_metadata']['width'] ?? 0 ) && 16 === ( $GLOBALS['ico_metadata']['height'] ?? 0 ) && empty( $GLOBALS['ico_metadata']['sizes'] ), 'native dimensions without generated subimages' );
			} else {
				$assert( 0 === $GLOBALS['ico_uploads'] && 0 === $GLOBALS['ico_inserts'], 'rejected asset has no upload or attachment' );
				$assert( ! is_file( $GLOBALS['ico_tmp'] . '/upload.ico' ), 'rejected asset has no upload file leak' );
				$assert( false === get_option( 'site_icon' ) && empty( $state['applied']['attachments'] ), 'rejected asset has no option or journal leak' );
			}
		}
	} finally {
		foreach ( glob( $GLOBALS['ico_tmp'] . '/*' ) as $file ) { unlink( $file ); }
		rmdir( $GLOBALS['ico_tmp'] );
	}
}

foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
fwrite( empty( $failures ) ? STDOUT : STDERR, sprintf( "ICO smoke: %d assertions, %d failures\n", $assertions, count( $failures ) ) );
exit( empty( $failures ) ? 0 : 1 );
