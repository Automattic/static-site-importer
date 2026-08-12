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
foreach ( array( 'website/shell.php', 'website/shell.phtml', 'website/shell.jsp', 'website/shell.cgi' ) as $path ) {
	$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( $path, 'payload' ) ) ), 'executable-extension-rejected:' . $path );
}
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/index.html', '<?php system("id");', true ) ) ), 'base64-server-code-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/site.js', '<?php system("id");' ) ) ), 'server-code-marker-in-static-extension-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/logo.svg', '<svg><?php system("id");</svg>', true ) ) ), 'textual-svg-server-code-rejected' );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/photo.jpeg', "\xFF\xD8\xFF\xFE\x00\x07<?php\xFF\xD9", true ) ), 'binary-jpeg-php-tag-bytes-accepted' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: content-only policy smoke passed\n";
