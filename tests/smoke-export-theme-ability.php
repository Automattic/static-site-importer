<?php
/**
 * Smoke test: an imported block theme can export static-site artifacts.
 *
 * Run from the repository root:
 * php tests/smoke-export-theme-ability.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$theme_root = sys_get_temp_dir() . '/ssi-export-theme-' . uniqid();
$theme_dir  = $theme_root . '/fixture-theme';

mkdir( $theme_dir . '/parts', 0777, true );
mkdir( $theme_dir . '/templates', 0777, true );
mkdir( $theme_dir . '/assets', 0777, true );
file_put_contents( $theme_dir . '/style.css', 'body{background:#fff;}' );
file_put_contents( $theme_dir . '/assets/app.js', 'document.body.dataset.exported="true";' );
file_put_contents( $theme_dir . '/assets/logo.png', "\x89PNG\0fixture" );
file_put_contents( $theme_dir . '/parts/header.html', '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->' );
file_put_contents( $theme_dir . '/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->' );
file_put_contents( $theme_dir . '/templates/front-page.html', '<!-- wp:post-content /-->' );
file_put_contents( $theme_dir . '/import-report.json', '{"status":"completed","source_documents":{"direct_website_artifact":{"document_count":1}}}' );

$GLOBALS['ssi_export_theme_root'] = $theme_root;
$GLOBALS['ssi_export_bfb_calls']  = array();
register_shutdown_function(
	static function () use ( $theme_root ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $theme_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $theme_root );
	}
);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code, string $message, mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ), '-' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( string $slug ): object {
		$dir = $GLOBALS['ssi_export_theme_root'] . '/' . $slug;

		return new class( $dir ) {
			private string $dir;

			public function __construct( string $dir ) {
				$this->dir = $dir;
			}

			public function exists(): bool {
				return is_dir( $this->dir );
			}

			public function get_stylesheet_directory(): string {
				return $this->dir;
			}
		};
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root( string $stylesheet = '' ): string {
		return $GLOBALS['ssi_export_theme_root'];
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name ) {
		return 'show_on_front' === $name ? 'page' : ( 'page_on_front' === $name ? 42 : '' );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		return array(
			(object) array(
				'ID'           => 42,
				'post_name'    => 'home',
				'post_title'   => 'Edited Home',
				'post_content' => '<!-- wp:paragraph --><p>Edited Playground content</p><!-- /wp:paragraph -->',
			),
		);
	}
}

if ( ! function_exists( 'bfb_convert' ) ) {
	function bfb_convert( string $content, string $from, string $to ): string {
		$GLOBALS['ssi_export_bfb_calls'][] = array( $from, $to );
		return str_replace( array( '<!-- wp:paragraph -->', '<!-- /wp:paragraph -->', '<!-- wp:post-content /-->' ), '', $content );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$result = Static_Site_Importer_Theme_Generator::export_theme(
	array(
		'theme_slug'      => 'fixture-theme',
		'entrypoint'      => 'static-site/index.html',
		'include_pages'   => true,
		'source_metadata' => array( 'source' => 'smoke' ),
	)
);
$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( ! is_wp_error( $result ), 'export-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
$assert( 'static-site-importer/static-site-artifact-set/v1' === ( $result['artifact_set']['schema'] ?? '' ), 'artifact-set-schema' );
$assert( 'static-site' === ( $result['artifact_set']['artifact_type'] ?? '' ), 'artifact-type' );
$assert( 1 === ( $result['artifact_set']['version'] ?? 0 ), 'artifact-version' );
$assert( 'static-site' === ( $result['artifact_set']['root'] ?? '' ), 'artifact-root' );
$assert( 'static-site/index.html' === ( $result['artifact_set']['entrypoint'] ?? '' ), 'entrypoint' );
$assert( 6 === count( $result['files'] ?? array() ), 'exports-entrypoint-assets-and-metadata' );
$assert( 'static-site/style.css' === ( $result['files'][0]['path'] ?? '' ), 'stylesheet-exported' );
$assert( 'static-site/index.html' === ( $result['files'][1]['path'] ?? '' ), 'entrypoint-exported' );
$assert( 'text/html' === ( $result['files'][1]['mime_type'] ?? '' ), 'entrypoint-mime' );
$assert( 'utf8' === ( $result['files'][1]['encoding'] ?? '' ), 'entrypoint-encoding' );
$assert( isset( $result['files'][1]['bytes'] ) && $result['files'][1]['bytes'] > 0, 'entrypoint-bytes' );
$assert( isset( $result['files'][1]['sha256'] ) && 64 === strlen( (string) $result['files'][1]['sha256'] ), 'entrypoint-hash' );
$assert( str_contains( (string) ( $result['files'][1]['content'] ?? '' ), 'Edited Playground content' ), 'page-content-converted' );
$assert( str_contains( (string) ( $result['files'][1]['content'] ?? '' ), '<link rel="stylesheet" href="style.css">' ), 'stylesheet-linked' );
$files_by_path = array();
foreach ( $result['files'] ?? array() as $file ) {
	$files_by_path[ $file['path'] ?? '' ] = $file;
}
$assert( 'script' === ( $files_by_path['static-site/assets/app.js']['role'] ?? '' ), 'script-role' );
$assert( 'text/javascript' === ( $files_by_path['static-site/assets/app.js']['mime_type'] ?? '' ), 'script-mime' );
$assert( 'base64' === ( $files_by_path['static-site/assets/logo.png']['encoding'] ?? '' ), 'binary-base64-encoding' );
$assert( 'image/png' === ( $files_by_path['static-site/assets/logo.png']['mime_type'] ?? '' ), 'binary-mime' );
$assert( 'report' === ( $files_by_path['static-site/import-report.json']['role'] ?? '' ), 'report-role' );
$assert( 'source-document' === ( $files_by_path['static-site/source-documents.json']['role'] ?? '' ), 'source-document-role' );
$assert( 'completed' === ( $result['report']['status'] ?? '' ), 'report-completed' );
$assert( 'passed' === ( $result['artifact_set']['validation']['status'] ?? '' ), 'validation-passed' );
$assert( 'passed' === ( $result['artifact_set']['import']['status'] ?? '' ), 'import-status-passed' );
$assert( 'static-site-importer' === ( $result['artifact_set']['provenance']['producer'] ?? '' ), 'provenance-producer' );
$assert( 'smoke' === ( $result['artifact_set']['provenance']['source_metadata']['source'] ?? '' ), 'provenance-source-metadata' );
$assert( 'static-site/import-report.json' === ( $result['artifact_set']['reports'][0]['path'] ?? '' ), 'report-ref' );
$assert( 'smoke' === ( $result['report']['source_metadata']['source'] ?? '' ), 'source-metadata-preserved' );
$assert( 'completed' === ( $result['report']['import_report']['status'] ?? '' ), 'import-report-preserved' );
$assert( count( $GLOBALS['ssi_export_bfb_calls'] ) >= 4, 'bfb-called-for-block-to-html' );
foreach ( $GLOBALS['ssi_export_bfb_calls'] as $call ) {
	$assert( 'blocks' === $call[0] && 'html' === $call[1], 'bfb-call-uses-blocks-to-html' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: export theme ability smoke passed (' . $assertions . " assertions)\n";
