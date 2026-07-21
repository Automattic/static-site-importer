<?php
/**
 * Smoke coverage for materialized post_content block validation reporting.
 *
 * Run from the repository root:
 * php tests/smoke-materialized-block-content-validation.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );

		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		$value = strtolower( $value );

		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?: '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): ?object {
		return 'page' === $post_type ? (object) array( 'name' => 'page' ) : null;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
if ( ! is_readable( $parser ) || ! is_readable( $blocks ) ) {
	fwrite( STDERR, "SKIP: WordPress parser/serializer files are unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 0 );
}

require_once $parser;
require_once $blocks;

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact(
	array(
		'source_path'  => 'index.html',
		'slug'         => 'home',
		'post_type'    => 'page',
		'title'        => 'Home',
		'block_markup' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
	)
);
if ( $page instanceof WP_Error ) {
	fwrite( STDERR, $page->get_error_message() . "\n" );
	exit( 1 );
}

$reflection      = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );
$report_property = $reflection->getProperty( 'conversion_report' );
$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );

$analyze = $reflection->getMethod( 'analyze_imported_page_content_documents' );
$analyze->invoke(
	null,
	array( 'index.html' => $page ),
	array( 'index.html' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' )
);

$report       = $report_property->getValue();
$materialized = $report['materialized_content']['block_documents'][0] ?? array();
$generated    = $report['generated_theme']['block_documents'][0] ?? array();

$assert( 'posts/page-home.post_content' === ( $materialized['path'] ?? '' ), 'materialized-path' );
$assert( 'materialized_post_content' === ( $materialized['target'] ?? '' ), 'materialized-target' );
$assert( 'index.html' === ( $materialized['source_path'] ?? '' ), 'source-path' );
$assert( 'home' === ( $materialized['slug'] ?? '' ), 'slug' );
$assert( 'page' === ( $materialized['post_type'] ?? '' ), 'post-type' );
$assert( true === ( $materialized['validation_available'] ?? false ), 'validation-available' );
$assert( 'wordpress_parse_blocks_serialize_blocks' === ( $materialized['validation_method'] ?? '' ), 'validation-method' );
$assert( 1 === ( $materialized['block_count'] ?? 0 ), 'block-count' );
$assert( 0 === ( $materialized['invalid_block_count'] ?? -1 ), 'valid-content-has-no-invalid-blocks' );
$assert( $materialized === $generated, 'legacy-generated-theme-bucket-preserved' );

$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );
$analyze->invoke(
	null,
	array( 'index.html' => $page ),
	array( 'index.html' => '<p>Loose unparsed HTML</p>' )
);

$invalid_report      = $report_property->getValue();
$invalid_diagnostic = array();
foreach ( $invalid_report['diagnostics'] ?? array() as $diagnostic ) {
	if ( is_array( $diagnostic ) && 'invalid_block_document' === ( $diagnostic['type'] ?? '' ) ) {
		$invalid_diagnostic = $diagnostic;
		break;
	}
}

$assert( 'invalid_block_document' === ( $invalid_diagnostic['type'] ?? '' ), 'invalid-document-diagnostic-type' );
$assert( 'posts/page-home.post_content' === ( $invalid_diagnostic['source'] ?? '' ), 'invalid-document-source' );
$assert( 'unparsed_html' === ( $invalid_diagnostic['block_name'] ?? '' ), 'invalid-document-block-name' );
$assert( '0' === ( $invalid_diagnostic['block_path'] ?? '' ), 'invalid-document-block-path' );
$assert( 'innerHTML' === ( $invalid_diagnostic['attribute_path'] ?? '' ), 'invalid-document-attribute-path' );
$assert( '' !== ( $invalid_diagnostic['validation_message'] ?? '' ), 'invalid-document-validation-message' );
$assert( '' !== ( $invalid_diagnostic['parser_validation_message'] ?? '' ), 'invalid-document-parser-validation-message' );

$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );
$analyze->invoke(
	null,
	array( 'index.html' => $page ),
	array( 'index.html' => '<!-- wp:navigation-link {"label":"Home","url":"http:\\/\\/localhost:8881\\/","kind":"custom"} /-->' )
);
$slash_report = $report_property->getValue();
$assert( 0 === ( $slash_report['quality']['invalid_block_count'] ?? -1 ), 'escaped-url-slashes-are-not-invalid-blocks' );

$form_html    = '<form class="newsletter" action="#" method="post"><input type="email" name="email" required><button type="submit">Subscribe</button></form>';
$form_content = '<!-- wp:html ' . wp_json_encode( array( 'content' => $form_html ) ) . ' -->' . $form_html . '<!-- /wp:html -->';
$form_report  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' );
Static_Site_Importer_Block_Document_Reporter::analyze_generated_block_document( 'parts/footer.html', $form_content, $form_report );
$form_findings = array_values( array_filter( $form_report['diagnostics'], static fn ( array $diagnostic ): bool => 'generated_document_contains_core_html' === ( $diagnostic['reason'] ?? '' ) ) );
$form_finding  = $form_findings[0] ?? array();
$assert( 'email' === ( $form_finding['controls'][0]['name'] ?? '' ), 'generated-form-diagnostic-retains-control-name' );
$assert( 'post' === ( $form_finding['form']['method'] ?? '' ), 'generated-form-diagnostic-retains-method' );

Static_Site_Importer_Block_Document_Reporter::reset_generated_block_document_analysis( $form_report );
Static_Site_Importer_Block_Document_Reporter::analyze_generated_block_document( 'parts/footer.html', '<!-- wp:jetpack/contact-form --><!-- wp:jetpack/field-email {"label":"Email"} /--><!-- /wp:jetpack/contact-form -->', $form_report );
$assert( 0 === ( $form_report['quality']['core_html_block_count'] ?? -1 ), 'post-graft-analysis-has-no-core-html' );
$assert( 0 === count( array_filter( $form_report['diagnostics'], static fn ( array $diagnostic ): bool => 'generated_document_contains_core_html' === ( $diagnostic['reason'] ?? '' ) ) ), 'pre-graft-core-html-diagnostic-removed' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: materialized block content validation smoke passed (' . $assertions . " assertions)\n";
