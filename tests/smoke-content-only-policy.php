<?php
/** Adversarial coverage for the static artifact content-only boundary. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$artifact = static function ( string $path, string $content, bool $encoded = false ): array {
	return array(
		'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files' => array( $encoded ? array( 'path' => $path, 'content_base64' => base64_encode( $content ) ) : array( 'path' => $path, 'content' => $content ) ),
	);
};

$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/index.html', '<main>Safe</main>' ) ), 'html-source-accepted' );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/assets/pointer.CUR', file_get_contents( __DIR__ . '/fixtures/cursor.cur' ), true ) ), 'binary-cursor-source-accepted' );
$assert( false === Static_Site_Importer_Content_Policy::is_static_path( 'website/.config.ts' ), 'dotfile-typescript-rejected-with-pathinfo-semantics' );
$assert( true === Static_Site_Importer_Content_Policy::is_static_path( 'website/.mjs' ), 'dotfile-mjs-accepted-with-pathinfo-semantics' );
foreach ( array( 'website/shell.php', 'website/shell.phtml', 'website/shell.jsp', 'website/shell.cgi', 'website/pointer.cur.php' ) as $path ) {
	$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( $path, 'payload' ) ) ), 'executable-extension-rejected:' . $path );
}
// Negative-policy lane: matrix collection may omit build sources, but public
// intake must continue rejecting a TypeScript file supplied by any caller.
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/quartz.config.ts', 'export default {};' ) ) ), 'typescript-source-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/index.html', '<?php system("id");', true ) ) ), 'base64-server-code-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/site.js', '<?php system("id");' ) ) ), 'server-code-marker-in-static-extension-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/logo.svg', '<svg><?php system("id");</svg>', true ) ) ), 'textual-svg-server-code-rejected' );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/photo.jpeg', "\xFF\xD8\xFF\xFE\x00\x07<?php\xFF\xD9", true ) ), 'binary-jpeg-php-tag-bytes-accepted' );
// Extensionless downloads keep a portable extension inferred from the fetched
// static content type; unknown or executable types infer none and stay rejected.
$assert( 'jpg' === Static_Site_Importer_Content_Policy::portable_extension( 'image/jpeg' ), 'extensionless-jpeg-download-infers-portable-jpg' );
$assert( 'css' === Static_Site_Importer_Content_Policy::portable_extension( 'text/css; charset=utf-8' ), 'extensionless-css-download-infers-portable-css-past-parameters' );
$assert( 'svg' === Static_Site_Importer_Content_Policy::portable_extension( 'Image/SVG+XML' ), 'extensionless-svg-download-infers-portable-svg-case-insensitively' );
$assert( 'woff2' === Static_Site_Importer_Content_Policy::portable_extension( 'font/woff2' ), 'extensionless-woff2-download-infers-portable-woff2' );
$assert( 'mp4' === Static_Site_Importer_Content_Policy::portable_extension( 'video/mp4' ), 'extensionless-github-video-infers-portable-mp4' );
$assert( '' === Static_Site_Importer_Content_Policy::portable_extension( 'application/octet-stream' ), 'opaque-download-type-infers-no-portable-extension' );
$assert( '' === Static_Site_Importer_Content_Policy::portable_extension( 'application/x-httpd-php' ), 'server-code-download-type-infers-no-portable-extension' );
$assert( Static_Site_Importer_Content_Policy::is_static_path( 'website/_external/images.unsplash.com/photo-1535713875002-d1d0cf377fde-32b524cf.' . Static_Site_Importer_Content_Policy::portable_extension( 'image/jpeg' ) ), 'inferred-portable-extension-passes-the-static-boundary' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: content-only policy smoke passed\n";
