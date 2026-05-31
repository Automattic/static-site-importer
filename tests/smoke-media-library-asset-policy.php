<?php
/**
 * Smoke test: media-library asset policy imports resolved local assets as attachments.
 *
 * Run from the repository root:
 * php tests/smoke-media-library-asset-policy.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) ?? '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		$parts = parse_url( $url );
		if ( -1 === $component ) {
			return $parts;
		}

		$map = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);

		return isset( $map[ $component ], $parts[ $map[ $component ] ] ) ? $parts[ $map[ $component ] ] : null;
	}
}

$GLOBALS['ssi_media_uploads']     = array();
$GLOBALS['ssi_media_next_id']     = 700;
$GLOBALS['ssi_media_upload_base'] = sys_get_temp_dir() . '/ssi-media-uploads-' . uniqid();

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename ): array {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$types     = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'svg'  => 'image/svg+xml',
		);

		return array(
			'ext'  => $extension,
			'type' => $types[ $extension ] ?? false,
		);
	}
}

if ( ! function_exists( 'wp_upload_bits' ) ) {
	function wp_upload_bits( string $name, $deprecated, string $bits ): array {
		$dir = trailingslashit( $GLOBALS['ssi_media_upload_base'] );
		wp_mkdir_p( $dir );
		$file = $dir . sanitize_file_name( $name );
		file_put_contents( $file, $bits );

		return array(
			'file'  => $file,
			'url'   => 'https://example.test/wp-content/uploads/' . basename( $file ),
			'error' => false,
		);
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( array $attachment, string $file ): int {
		$id = ++$GLOBALS['ssi_media_next_id'];
		$GLOBALS['ssi_media_uploads'][ $id ] = array(
			'attachment' => $attachment,
			'file'       => $file,
			'url'        => 'https://example.test/wp-content/uploads/' . basename( $file ),
			'meta'       => array(),
		);

		return $id;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $id ): string {
		return $GLOBALS['ssi_media_uploads'][ $id ]['url'] ?? '';
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( int $id, string $file ): array {
		return array( 'file' => basename( $file ) );
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( int $id, array $metadata ): bool {
		$GLOBALS['ssi_media_uploads'][ $id ]['metadata'] = $metadata;
		return true;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $id, string $key, string $value ): bool {
		$GLOBALS['ssi_media_uploads'][ $id ]['meta'][ $key ] = $value;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$tmp        = sys_get_temp_dir() . '/ssi-media-library-policy-' . uniqid();
$source_dir = $tmp . '/source';
$theme_dir  = $tmp . '/theme';
wp_mkdir_p( $source_dir . '/assets' );
wp_mkdir_p( $source_dir . '/pages' );
wp_mkdir_p( $theme_dir );

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );
file_put_contents( $source_dir . '/assets/hero.png', $png );
file_put_contents( $source_dir . '/assets/readme.txt', 'not media' );

$class = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );
foreach ( array(
	'active_source_dir'            => $source_dir,
	'active_theme_dir'             => $theme_dir,
	'active_theme_uri'             => 'https://example.test/wp-content/themes/imported',
	'active_asset_map'             => array(),
	'active_asset_metadata'        => array(),
	'active_asset_materialization_policy' => 'copy_to_theme',
	'active_asset_policy'          => 'media-library',
	'active_imported_media_assets' => array(),
	'recorded_local_asset_keys'    => array(),
	'conversion_report'            => array(
		'assets'      => array(
			'policy'       => 'media-library',
			'local_policy' => 'copy_to_theme',
			'local'        => array(),
			'svg_icons'    => array(),
			'svg_sprites'  => array(),
		),
		'asset_map'   => array(
			'supplied'         => false,
			'entry_count'      => 0,
			'resolved_count'   => 0,
			'unresolved_count' => 0,
			'resolved'         => array(),
			'unresolved'       => array(),
		),
		'diagnostics' => array(),
	),
) as $property => $value ) {
	$ref = $class->getProperty( $property );
	$ref->setValue( null, $value );
}

$rewrite_html = $class->getMethod( 'rewrite_local_asset_references' );
$html         = $rewrite_html->invoke(
	null,
	'<figure><img src="../assets/hero.png" alt="Hero"><img src="../assets/hero.png" alt="Duplicate"><img src="../assets/readme.txt" alt="Text"><img src="../../secret.png" alt="Unsafe"></figure>',
	'pages/about.html',
	'main:pages/about.html'
);

$assert( str_contains( $html, 'src="https://example.test/wp-content/uploads/hero.png"' ), 'media-url-rewritten', $html );
$assert( str_contains( $html, 'data-id="701"' ), 'attachment-id-added', $html );
$assert( str_contains( $html, 'wp-image-701' ), 'wp-image-class-added', $html );
$assert( 1 === count( $GLOBALS['ssi_media_uploads'] ), 'duplicate-source-imported-once' );
$assert( 'Hero' === ( $GLOBALS['ssi_media_uploads'][701]['meta']['_wp_attachment_image_alt'] ?? '' ), 'alt-written-to-attachment' );

$metadata = $class->getProperty( 'active_asset_metadata' )->getValue();
$asset    = $metadata['../assets/hero.png'] ?? array();
$assert( 701 === ( $asset['attachment_id'] ?? null ), 'metadata-attachment-id' );
$assert( 701 === ( $asset['id'] ?? null ), 'metadata-id' );
$assert( 'https://example.test/wp-content/uploads/hero.png' === ( $asset['url'] ?? '' ), 'metadata-final-url' );
$assert( 'https://example.test/wp-content/uploads/hero.png' === ( $asset['final_url'] ?? '' ), 'metadata-explicit-final-url' );
$assert( 'Hero' === ( $asset['alt'] ?? '' ), 'metadata-alt' );
$assert( 1 === ( $asset['width'] ?? null ), 'metadata-width' );
$assert( 1 === ( $asset['height'] ?? null ), 'metadata-height' );

$report = $class->getProperty( 'conversion_report' )->getValue();
$assert( 1 === count( $report['assets']['local'] ?? array() ), 'duplicate-source-single-report-row' );
$assert( 'media-library' === ( $report['assets']['local'][0]['policy'] ?? '' ), 'report-policy' );
$assert( 'copy_to_theme' === ( $report['assets']['local'][0]['materialization_policy'] ?? '' ), 'report-materialization-policy' );
$assert( 'copied' === ( $report['assets']['local'][0]['outcome'] ?? '' ), 'report-outcome' );
$assert( 701 === ( $report['assets']['local'][0]['attachment_id'] ?? null ), 'report-attachment-id' );

$diagnostics = array_column( $report['diagnostics'] ?? array(), 'type' );
$assert( in_array( 'local_asset_unsupported_type', $diagnostics, true ), 'unsupported-file-diagnostic-recorded' );
$assert( in_array( 'local_asset_unsafe_path', $diagnostics, true ), 'unsafe-path-diagnostic-recorded' );
$assert( str_contains( $html, 'src="../assets/readme.txt"' ), 'unsupported-left-unchanged', $html );
$assert( str_contains( $html, 'src="../../secret.png"' ), 'unsafe-left-unchanged', $html );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: media-library asset policy smoke passed (' . $assertions . " assertions)\n";
