<?php
/**
 * HTML-only runtime capability coverage.
 *
 * Run from the repository root:
 * php tests/smoke-html-runtime-capabilities.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-runtime-capabilities.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';

assert( array() === ( Static_Site_Importer_Content_Policy::admit_artifact( array( 'files' => array( array( 'path' => 'index.html', 'content' => '<main>HTML works without Markdown classes.</main>' ) ) ) )['findings'] ?? null ) );
$markdown = Static_Site_Importer_Content_Policy::admit_artifact(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			array( 'path' => 'index.html', 'content' => '<main>Site</main>' ),
			array( 'path' => 'README.md', 'content' => '# Documentation' ),
			array( 'path' => 'docs/README.md', 'content' => '# Nested documentation' ),
		),
	)
);
$mdx = Static_Site_Importer_Content_Policy::admit_artifact( array( 'entrypoint' => 'index.md', 'files' => array( array( 'path' => 'index.md', 'content' => '# Unsupported here' ) ) ) );
assert( is_array( $markdown ) && 2 === count( $markdown['findings'] ) );
assert( 'static_site_importer_non_static_file_skipped' === $markdown['findings'][0]['code'] );
assert( 'static_site_importer_non_static_file_skipped' === $markdown['findings'][1]['code'] );
assert( $mdx instanceof WP_Error && 'static_site_importer_source_format_unsupported' === $mdx->get_error_code() );

echo "HTML runtime capability smoke passed.\n";
