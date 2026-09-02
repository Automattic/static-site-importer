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

assert( true === Static_Site_Importer_Content_Policy::validate_artifact( array( 'files' => array( array( 'path' => 'index.html', 'content' => '<main>HTML works without Markdown classes.</main>' ) ) ) ) );
$markdown = Static_Site_Importer_Content_Policy::validate_artifact( array( 'files' => array( array( 'path' => 'notes.md', 'content' => '# Unsupported here' ) ) ) );
$mdx = Static_Site_Importer_Content_Policy::validate_artifact( array( 'files' => array( array( 'path' => 'component.mdx', 'content' => '# Unsupported here' ) ) ) );
assert( $markdown instanceof WP_Error && 'static_site_importer_source_format_unsupported' === $markdown->get_error_code() );
assert( $mdx instanceof WP_Error && 'static_site_importer_source_format_unsupported' === $mdx->get_error_code() );
assert( str_contains( $markdown->get_error_message(), 'markdown conversion capability' ) );

echo "HTML runtime capability smoke passed.\n";
