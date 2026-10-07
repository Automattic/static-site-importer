<?php
/** Adversarial coverage for the static artifact content-only boundary. */
require_once __DIR__ . '/fixtures/core-html-api.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
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
$word_bytes = "PK\x03\x04\x00\x00\x00\x00word/document.xml\x00<?php\x00\xFF";
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/_files/ugd/guide.docx', $word_bytes, true ) ), 'binary-word-source-accepted' );
$assert( false === Static_Site_Importer_Content_Policy::is_textual_path( 'website/_files/ugd/guide.docx' ), 'word-document-is-not-page-source' );
$assert( false === Static_Site_Importer_Content_Policy::is_companion_asset_path( 'website/_files/ugd/guide.docx' ), 'word-document-is-not-companion-code' );
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
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/_files/ugd/guide.docx.php', $word_bytes, true ) ) ), 'executable-word-suffix-rejected' );
// Extensionless downloads keep a portable extension inferred from the fetched
// static content type; unknown or executable types infer none and stay rejected.
$assert( 'jpg' === Static_Site_Importer_Content_Policy::portable_extension( 'image/jpeg' ), 'extensionless-jpeg-download-infers-portable-jpg' );
$assert( 'css' === Static_Site_Importer_Content_Policy::portable_extension( 'text/css; charset=utf-8' ), 'extensionless-css-download-infers-portable-css-past-parameters' );
$assert( 'svg' === Static_Site_Importer_Content_Policy::portable_extension( 'Image/SVG+XML' ), 'extensionless-svg-download-infers-portable-svg-case-insensitively' );
$assert( 'woff2' === Static_Site_Importer_Content_Policy::portable_extension( 'font/woff2' ), 'extensionless-woff2-download-infers-portable-woff2' );
$assert( 'mp4' === Static_Site_Importer_Content_Policy::portable_extension( 'video/mp4' ), 'extensionless-github-video-infers-portable-mp4' );
$assert( 'docx' === Static_Site_Importer_Content_Policy::portable_extension( 'Application/Vnd.Openxmlformats-Officedocument.Wordprocessingml.Document; charset=binary' ), 'extensionless-word-download-infers-portable-docx' );
$assert( '' === Static_Site_Importer_Content_Policy::portable_extension( 'application/octet-stream' ), 'opaque-download-type-infers-no-portable-extension' );
$assert( '' === Static_Site_Importer_Content_Policy::portable_extension( 'application/x-httpd-php' ), 'server-code-download-type-infers-no-portable-extension' );
$assert( Static_Site_Importer_Content_Policy::is_static_path( 'website/_external/images.unsplash.com/photo-1535713875002-d1d0cf377fde-32b524cf.' . Static_Site_Importer_Content_Policy::portable_extension( 'image/jpeg' ) ), 'inferred-portable-extension-passes-the-static-boundary' );
$assert( true === Static_Site_Importer_Content_Policy::is_static_path( '_redirects' ), 'root-redirects-manifest-is-static' );
$assert( true === Static_Site_Importer_Content_Policy::is_static_path( 'website/_redirects' ), 'website-root-redirects-manifest-is-static' );
$assert( true === Static_Site_Importer_Content_Policy::is_textual_path( '_redirects' ), 'root-redirects-manifest-is-textual' );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/_redirects', "/blog.html  /blog/index.html  301\n" ) ), 'root-redirects-manifest-accepted' );
$assert( false === Static_Site_Importer_Content_Policy::is_static_path( 'evil' ), 'extensionless-evil-rejected' );
$assert( false === Static_Site_Importer_Content_Policy::is_static_path( 'website/blog/_redirects' ), 'nested-redirects-manifest-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'evil', 'payload' ) ) ), 'extensionless-evil-artifact-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/blog/_redirects', "/a /b 301\n" ) ) ), 'nested-redirects-artifact-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/_redirects', '<?php system("id");' ) ) ), 'redirects-manifest-server-code-rejected' );
$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/_redirects', str_repeat( "/a /b 301\n", 7000 ) ) ) ), 'oversized-redirects-manifest-rejected' );

// Neutral source examples exercise core tokenization, not a parser shape stub.
$html_cases = array(
	'double-quoted-tutorial' => array( '<span data-code="<?php echo 1; ?>">Copy</span>', true ),
	'single-quoted-tutorial' => array( "<span data-code='<?php echo 1; ?>'>Copy</span>", true ),
	'comment' => array( '<!-- <?php echo 1; ?> -->', true ),
	'core-comment-close' => array( '<!-- <?php echo 1; ?> --!>', true ),
	'completed-tag-residue' => array( '<span data-code=<?php echo 1; ?>>Copy</span>', true ),
	'encoded-text' => array( '<pre>&lt;?php echo 1; ?&gt;</pre>', true ),
	'encoded-text-and-inert-marker' => array( '<i data-code="<?php ?>">&lt;?php echo 1;</i>', true ),
	'xml-declaration' => array( '<?xml version="1.0"?><p>Text</p>', true ),
	'bogus-comment' => array( '<!example <?php echo 1; ?>>', false ),
	'xml-with-marker' => array( '<?xml <?php echo 1; ?>', false ),
	'outside-php' => array( '<main><?php echo 1; ?></main>', false ),
	'outside-uppercase-php' => array( '<main><?PHP echo 1; ?></main>', false ),
	'outside-php-processing-target' => array( '<main><?phpx echo 1; ?></main>', false ),
	'outside-short-echo' => array( '<main><?= 1 ?></main>', false ),
	'outside-short-open' => array( '<? echo 1; ?>', false ),
	'script-tag-looking-example' => array( '<script>const example = "<span data-code=\'<?php echo 1; ?>\'>";</script>', false ),
	'style-tag-looking-example' => array( '<style>p::before { content: "<span data-code=\'<?php echo 1; ?>\'>"; }</style>', false ),
	'script-comment-looking-example' => array( '<script><!-- <?php echo 1; ?> --></script>', false ),
	'unterminated-script' => array( '<script>"<?php echo 1; ?>"', false ),
	'unterminated-style' => array( '<style>"<?php echo 1; ?>"', false ),
	'unterminated-double-quote' => array( '<span data-code="<?php echo 1; ?>', false ),
	'unterminated-single-quote' => array( "<span data-code='<?php echo 1; ?>", false ),
	'unterminated-comment' => array( '<!-- <?php echo 1; ?>', false ),
	'inert-then-active' => array( '<span data-code="<?php ?>"></span><?php echo 1; ?>', false ),
);
foreach ( $html_cases as $label => list( $html, $accepted ) ) {
	$result = Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/index.html', $html ) );
	$assert( $accepted ? true === $result : is_wp_error( $result ), 'core-tokenizer:' . $label );
}
foreach ( array( 'js', 'css', 'svg', 'xml', 'txt' ) as $extension ) {
	$assert( is_wp_error( Static_Site_Importer_Content_Policy::validate_artifact( $artifact( 'website/example.' . $extension, '<!-- <?php echo 1; ?> -->' ) ) ), 'non-html-raw:' . $extension );
}

// A filesystem reader makes corruption/unavailability checks inspect real bytes.
$payload_path = tempnam( sys_get_temp_dir(), 'ssi-policy-payload-' );
$payload = '<main>Reference-backed content</main>';
file_put_contents( $payload_path, $payload );
$reader = new class( $payload_path ) {
	public int $reads = 0;
	public function __construct( private string $path ) {}
	public function read( array $reference ): string {
		++$this->reads;
		if ( ! is_readable( $this->path ) ) {
			throw new RuntimeException( 'Missing test payload.' );
		}
		return file_get_contents( $this->path );
	}
};
$reference = array( 'schema' => 'blocks-engine/payload-reference/v1', 'id' => 'source/index.html', 'bytes' => strlen( $payload ), 'sha256' => hash( 'sha256', $payload ) );
$referenced = array( 'files' => array( array( 'path' => 'website/index.html', 'payload_reference' => $reference ) ) );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $referenced, $reader ), 'reference-filesystem-accepted' );
$alias = array( 'files' => array( array( 'path' => 'website/index.html', 'payload' => array( 'reference' => $reference ) ) ) );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $alias, $reader ), 'reference-canonical-alias-accepted' );
$error_code = static function ( $result ): string { return is_wp_error( $result ) ? $result->get_error_code() : ''; };
$assert( 'static_site_importer_payload_reader_missing' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $referenced ) ), 'reference-missing-reader-rejected' );
$assert( 'static_site_importer_payload_reader_missing' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $referenced, new stdClass() ) ), 'reference-non-reader-rejected' );
foreach ( array( 'string-reference', array_diff_key( $reference, array( 'bytes' => true ) ), array_diff_key( $reference, array( 'sha256' => true ) ), array_diff_key( $reference, array( 'id' => true ) ), array_replace( $reference, array( 'bytes' => -1 ) ), array_replace( $reference, array( 'bytes' => 10485761 ) ), array_replace( $reference, array( 'bytes' => '36' ) ) ) as $invalid ) {
	$bad = $referenced;
	$bad['files'][0]['payload_reference'] = $invalid;
	$before = $reader->reads;
	$assert( 'static_site_importer_payload_reference_invalid' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $bad, $reader ) ) && $before === $reader->reads, 'reference-invalid-before-read:' . json_encode( $invalid ) );
}
$bad = $referenced;
$bad['files'][0]['payload_reference']['sha256'] = str_repeat( '0', 64 );
$assert( 'static_site_importer_payload_reference_hash_mismatch' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $bad, $reader ) ), 'reference-hash-mismatch' );
$bad = $referenced;
++$bad['files'][0]['payload_reference']['bytes'];
$assert( 'static_site_importer_payload_reference_hash_mismatch' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $bad, $reader ) ), 'reference-size-mismatch' );
$active = '<?php echo 1; ?>';
file_put_contents( $payload_path, $active );
$bad = $referenced;
$bad['files'][0]['content'] = '<main>Inline decoy</main>';
$bad['files'][0]['payload_reference']['bytes'] = strlen( $active );
$bad['files'][0]['payload_reference']['sha256'] = hash( 'sha256', $active );
$assert( 'static_site_importer_executable_source_rejected' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $bad, $reader ) ), 'reference-authoritative-over-inline-decoy' );
$media = $referenced;
$media['files'][0]['path'] = 'website/preview.png';
$before = $reader->reads;
unlink( $payload_path );
$assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $media, $reader ) && $before === $reader->reads, 'optional-media-reference-not-read' );
$assert( 'static_site_importer_payload_reference_unavailable' === $error_code( Static_Site_Importer_Content_Policy::validate_artifact( $referenced, $reader ) ), 'reference-unreadable-rejected' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: content-only policy smoke passed\n";
