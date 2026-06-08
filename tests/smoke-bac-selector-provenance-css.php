<?php
/**
 * Smoke test: BAC selector provenance drives CSS selector transposition.
 *
 * Run from the repository root:
 * php tests/smoke-bac-selector-provenance-css.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$failures, &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$css = '@media (max-width:560px){h1.hero-title{font-size:2rem}.feature-row{display:flex}.contact-actions .btn{width:100%}}';

$artifacts = array(
	'documents'      => array(
		array(
			'selector_provenance' => array(
				array(
					'source'          => array(
						'selector' => 'h1.hero-title',
					),
					'generated_block' => array(
						'type'    => 'core/heading',
						'targets' => array(
							array( 'selector' => '.wp-block-heading.hero-title' ),
						),
					),
				),
				array(
					'source'          => array(
						'selector' => 'a.btn.btn-ghost',
					),
					'generated_block' => array(
						'type'    => 'core/button',
						'targets' => array(
							array( 'selector' => '.wp-block-button.btn' ),
						),
					),
				),
			),
		),
	),
	'template_parts' => array(
		array(
			'selector_provenance' => array(
				array(
					'source'          => array(
						'selector' => 'section.feature-row',
					),
					'generated_block' => array(
						'type'    => 'core/group',
						'targets' => array(
							array( 'selector' => '.wp-block-group.feature-row' ),
						),
					),
				),
			),
		),
	),
);

$collector = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'selector_provenance_from_artifacts' );
$provenance = $collector->invoke( null, $artifacts );

$style = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'style_css' );
$style_css = (string) $style->invoke( null, 'BAC Provenance Smoke', $css, array(), $provenance );

$editor = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'editor_style_css' );
$editor_css = (string) $editor->invoke( null, $css, array(), $provenance );

$assert( 3 === count( $provenance ), 'collector-reads-document-and-template-part-provenance' );
$assert( str_contains( $style_css, 'Static Site Importer: transpose source selectors using block conversion provenance.' ), 'style-includes-provenance-bridge-comment' );
$assert( str_contains( $style_css, '@media (max-width:560px) { .wp-block-heading.hero-title {font-size:2rem} .wp-block-group.feature-row {display:flex} .contact-actions .wp-block-button.btn {width:100%} }' ), 'style-preserves-media-scoped-provenance-rules', $style_css );
$assert( str_contains( $editor_css, '@media (max-width:560px) { .wp-block-heading.hero-title {font-size:2rem} .wp-block-group.feature-row {display:flex} .contact-actions .wp-block-button.btn {width:100%} }' ), 'editor-preserves-media-scoped-provenance-rules', $editor_css );

$documents = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'bac_documents_from_compiled_site_pages' );
$missing_identity = $documents->invoke(
	null,
	array(
		array(
			'source_path' => 'index.html',
			'slug'        => 'home',
		),
	),
	array(
		array(
			'source_path'  => 'index.html',
			'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
		),
	)
);
$assert( $missing_identity instanceof WP_Error, 'compiled-site-page-identity-is-required' );
$assert( 'static_site_importer_compiled_site_page_identity_incomplete' === ( $missing_identity instanceof WP_Error ? $missing_identity->get_error_code() : '' ), 'compiled-site-page-identity-error-code' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: BAC selector provenance CSS smoke passed (' . $assertions . " assertions)\n";
