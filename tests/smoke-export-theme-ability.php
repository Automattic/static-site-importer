<?php
/**
 * Smoke test: an imported block theme can export website artifacts.
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
$GLOBALS['ssi_export_format_conversion_calls'] = array();
$GLOBALS['ssi_export_posts_first'] = false;

function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
	$GLOBALS['ssi_export_format_conversion_calls'][] = array( $from, $to, $options );
	return array(
		'schema'    => 'blocks-engine/php-transformer/result/v1',
		'status'    => 'success',
		'documents' => array(
			array(
				'format'  => $to,
				'content' => str_replace( array( '<!-- wp:paragraph -->', '<!-- /wp:paragraph -->', '<!-- wp:post-content /-->' ), '', $content ),
			),
		),
	);
}
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
		$types = (array) ( $args['post_type'] ?? array() );
		$docs  = array(
			(object) array(
				'ID'           => 42,
				'post_type'    => 'page',
				'post_name'    => 'home',
				'post_title'   => 'Edited Home',
				'post_content' => '<!-- wp:paragraph --><p>Edited Playground content</p><!-- /wp:paragraph -->',
			),
			(object) array(
				'ID'           => 44,
				'post_type'    => 'page',
				'post_name'    => 'about',
				'post_title'   => 'About Page',
				'post_content' => '<!-- wp:paragraph --><p>Edited About page</p><!-- /wp:paragraph -->',
			),
		);
		// The exporter must enumerate imported posts as well as pages; a query
		// that includes 'post' returns the dated blog entries too. The colliding
		// page/post pair shares post_name 'about' to prove same-slug entries get
		// distinct artifact paths.
		if ( in_array( 'post', $types, true ) ) {
			$posts = array(
				(object) array(
					'ID'           => 43,
					'post_type'    => 'post',
					'post_name'    => 'hello',
					'post_title'   => 'Hello Post',
					'post_content' => '<!-- wp:paragraph --><p>Edited Post content</p><!-- /wp:paragraph -->',
				),
				(object) array(
					'ID'           => 45,
					'post_type'    => 'post',
					'post_name'    => 'about',
					'post_title'   => 'About Post',
					'post_content' => '<!-- wp:paragraph --><p>Edited About post</p><!-- /wp:paragraph -->',
				),
			);
			// Route allocation must be order-independent: flip the query order
			// so the same-slug post is processed before its page.
			$docs = $GLOBALS['ssi_export_posts_first'] ? array_merge( $posts, $docs ) : array_merge( $docs, $posts );
		}
		return $docs;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable|string $callback ): void {}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {}
}

if ( ! function_exists( 'static_site_importer_source_runtime' ) ) {
	function static_site_importer_source_runtime( array $source ): array {
		return array(
			'artifact'        => array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint' => (string) ( $source['entrypoint'] ?? '' ),
				'files'      => isset( $source['files'] ) && is_array( $source['files'] ) ? $source['files'] : array(),
			),
			'source_metadata' => array(),
			'provider'        => 'test',
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-exporter.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$theme_generator_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php' );
$theme_exporter_source  = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-exporter.php' );

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_artifact = array();
		public static array $last_args = array();

		public static function import_website_artifact( array $artifact, array $args = array() ): array {
			self::$last_artifact = $artifact;
			self::$last_args = $args;

			return array(
				'theme_slug' => 'fixture-theme',
				'report'     => array(),
			);
		}
	}
}

$result = static_site_importer_ability_export_theme(
	array(
		'theme_slug'      => 'fixture-theme',
		'root'            => 'website',
		'entrypoint'      => 'website/index.html',
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
$assert( true === ( $result['success'] ?? false ), 'ability-success' );
$assert( 0 === preg_match( '/public static function export_theme\\s*\\(/', (string) $theme_generator_source ), 'theme-generator-has-no-exporter-copy' );
$assert( 1 === preg_match_all( '/public static function export_theme\\s*\\(/', (string) $theme_exporter_source ), 'theme-exporter-is-the-single-export-implementation' );
$artifact = $result['website_artifact'] ?? array();
$assert( ! isset( $result['artifact_set'] ), 'artifact-set-wrapper-removed' );
$assert( ! isset( $result['files'] ), 'top-level-files-wrapper-removed' );
$assert( ! isset( $result['report'] ), 'top-level-report-wrapper-removed' );
$assert( 'blocks-engine/php-transformer/site-artifact/v1' === ( $artifact['schema'] ?? '' ), 'website-artifact-schema' );
$assert( 'website' === ( $artifact['artifact_type'] ?? '' ), 'artifact-type' );
$assert( 1 === ( $artifact['version'] ?? 0 ), 'artifact-version' );
$assert( 'website' === ( $artifact['root'] ?? '' ), 'artifact-root' );
$assert( 'website/index.html' === ( $artifact['entrypoint'] ?? '' ), 'entrypoint' );
$assert( 9 === count( $artifact['files'] ?? array() ), 'exports-entrypoint-posts-assets-and-metadata' );
$assert( 'website/style.css' === ( $artifact['files'][0]['path'] ?? '' ), 'stylesheet-exported' );
$assert( 'website/index.html' === ( $artifact['files'][1]['path'] ?? '' ), 'entrypoint-exported' );
$assert( 'text/html' === ( $artifact['files'][1]['mime_type'] ?? '' ), 'entrypoint-mime' );
$assert( 'utf8' === ( $artifact['files'][1]['encoding'] ?? '' ), 'entrypoint-encoding' );
$assert( isset( $artifact['files'][1]['bytes'] ) && $artifact['files'][1]['bytes'] > 0, 'entrypoint-bytes' );
$assert( isset( $artifact['files'][1]['sha256'] ) && 64 === strlen( (string) $artifact['files'][1]['sha256'] ), 'entrypoint-hash' );
$assert( str_contains( (string) ( $artifact['files'][1]['content'] ?? '' ), 'Edited Playground content' ), 'page-content-converted' );
$assert( str_contains( (string) ( $artifact['files'][1]['content'] ?? '' ), '<link rel="stylesheet" href="style.css">' ), 'stylesheet-linked' );
$files_by_path = array();
foreach ( $artifact['files'] ?? array() as $file ) {
	$files_by_path[ $file['path'] ?? '' ] = $file;
}
$assert( 'script' === ( $files_by_path['website/assets/app.js']['role'] ?? '' ), 'script-role' );
$assert( 'text/javascript' === ( $files_by_path['website/assets/app.js']['mime_type'] ?? '' ), 'script-mime' );
$assert( 'base64' === ( $files_by_path['website/assets/logo.png']['encoding'] ?? '' ), 'binary-base64-encoding' );
$assert( 'image/png' === ( $files_by_path['website/assets/logo.png']['mime_type'] ?? '' ), 'binary-mime' );
$assert( 'report' === ( $files_by_path['website/import-report.json']['role'] ?? '' ), 'report-role' );
$assert( 'source-document' === ( $files_by_path['website/source-documents.json']['role'] ?? '' ), 'source-document-role' );
$assert( isset( $files_by_path['website/hello/index.html'] ), 'imported-post-survives-export' );
$assert( str_contains( (string) ( $files_by_path['website/hello/index.html']['content'] ?? '' ), 'Edited Post content' ), 'imported-post-content-exported' );
$assert( isset( $files_by_path['website/about/index.html'] ), 'page-keeps-clean-path' );
$assert( isset( $files_by_path['website/post/about/index.html'] ), 'same-slug-post-namespaced-under-post' );
$assert( 2 === ( $artifact['report']['page_count'] ?? 0 ), 'report-page-count-is-pages-only' );
$assert( 2 === ( $artifact['report']['post_count'] ?? 0 ), 'report-post-count-reported-separately' );
$assert( 'completed' === ( $artifact['report']['status'] ?? '' ), 'report-completed' );
$assert( 'passed' === ( $artifact['validation']['status'] ?? '' ), 'validation-passed' );
$assert( 'passed' === ( $artifact['import']['status'] ?? '' ), 'import-status-passed' );
$assert( 'static-site-importer' === ( $artifact['provenance']['producer'] ?? '' ), 'provenance-producer' );
$assert( 'smoke' === ( $artifact['provenance']['source_metadata']['source'] ?? '' ), 'provenance-source-metadata' );
$assert( 'website/import-report.json' === ( $artifact['reports'][0]['path'] ?? '' ), 'report-ref' );
$assert( 'smoke' === ( $artifact['report']['source_metadata']['source'] ?? '' ), 'source-metadata-preserved' );
$assert( 'completed' === ( $artifact['report']['import_report']['status'] ?? '' ), 'import-report-preserved' );
$import_result = static_site_importer_ability_import(
	array(
		'source' => static_site_importer_ability_files_source( $artifact ),
		'slug'     => 'fixture-theme',
		'name'     => 'Fixture Theme',
		'site_title' => 'Fixture Site',
		'stale_page_action' => 'draft',
	)
);
$assert( true === ( $import_result['success'] ?? false ), 'ability-imports-website-artifact' );
$assert( 'blocks-engine/php-transformer/site-artifact/v1' === ( Static_Site_Importer_Theme_Generator::$last_artifact['schema'] ?? '' ), 'ability-import-website-artifact-schema' );
$assert( 'website/index.html' === ( Static_Site_Importer_Theme_Generator::$last_artifact['entrypoint'] ?? '' ), 'ability-import-website-artifact-entrypoint' );
$assert( 'Fixture Site' === ( Static_Site_Importer_Theme_Generator::$last_args['site_title'] ?? '' ), 'ability-import-website-artifact-forwards-site-title' );
$assert( 'draft' === ( Static_Site_Importer_Theme_Generator::$last_args['stale_page_action'] ?? '' ), 'ability-import-website-artifact-forwards-stale-page-action' );
$export_format_conversion_calls = array_filter(
	$GLOBALS['ssi_export_format_conversion_calls'],
	static fn ( array $call ): bool => 'blocks' === $call[0] && 'html' === $call[1]
);
$assert( count( $export_format_conversion_calls ) >= 4, 'format-conversion-called-for-block-to-html' );
$assert( function_exists( 'blocks_engine_php_transformer_convert_format' ), 'export-routes-through-blocks-engine-format-bridge' );

// Route allocation must not depend on query order: re-export with the shared
// slug post processed before its page and assert the same artifact layout.
$GLOBALS['ssi_export_posts_first'] = true;
$flipped = static_site_importer_ability_export_theme(
	array(
		'theme_slug'      => 'fixture-theme',
		'root'            => 'website',
		'entrypoint'      => 'website/index.html',
		'include_pages'   => true,
		'source_metadata' => array( 'source' => 'smoke' ),
	)
);
$assert( true === ( $flipped['success'] ?? false ), 'flipped-order-export-succeeds' );
$flipped_files = array();
foreach ( ( $flipped['website_artifact']['files'] ?? array() ) as $file ) {
	$flipped_files[ $file['path'] ?? '' ] = true;
}
$assert( isset( $flipped_files['website/about/index.html'] ), 'flipped-order-page-keeps-clean-path' );
$assert( isset( $flipped_files['website/post/about/index.html'] ), 'flipped-order-same-slug-post-namespaced' );
$assert( isset( $flipped_files['website/hello/index.html'] ), 'flipped-order-noncolliding-post-exported' );
$assert( count( $flipped_files ) === count( $files_by_path ), 'flipped-order-file-count-stable' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: export theme ability smoke passed (' . $assertions . " assertions)\n";
