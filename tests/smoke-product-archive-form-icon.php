<?php
/**
 * A form on the page written as the product archive keeps its inline icon.
 *
 * Blocks Engine keeps a form's inline SVG icon as an image asset only in the
 * form's readable blocks, which the form provider builds its replacement from.
 * No plan document references that asset. The archive template is spliced
 * from the page and its provider replacements, so it carries the icon's
 * token: the theme's runtime asset map in functions.php must resolve it, and
 * the final reference check must accept exactly what that map covers.
 *
 * Run: php tests/smoke-product-archive-form-icon.php
 *
 * @package StaticSiteImporter
 */

// Reuse the source-only Core bootstrap and real adopted-listing fixture.
require __DIR__ . '/smoke-woo-product-listing-archive.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

$failures   = array();
$assertions = 0;

// The reduced page: a product card and a form holding an inline SVG icon.
$fixture_dir = __DIR__ . '/fixtures/product-archive-form-icon';
$compiled    = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html'  => file_get_contents( $fixture_dir . '/index.html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
			'product.png' => file_get_contents( $fixture_dir . '/product.png' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$forms       = array_values( array_filter( $compiled['quality']['fallbacks'] ?? array(), static fn( array $fallback ): bool => 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? '' ) ) );
$form_blocks = serialize_blocks( $forms[0]['readable_blocks'] ?? array() );
$assert( 1 === count( $forms ) && 1 === preg_match( '~\{\{wordpress-site-plan:asset:([^}]+)\}\}~', $form_blocks, $icon ), 'form-readable-blocks-keep-the-icon-as-an-asset-token', $form_blocks );
$documents = implode( "\n", array_column( array_merge( $compiled['templates'], $compiled['template_parts'], $compiled['pages'] ), 'canonical_block_markup' ) );
$assert( ! str_contains( $documents, $icon[0] ), 'no-plan-document-references-the-form-icon' );
$icon_asset = array_values( array_filter( $compiled['reference_tokens'], static fn( array $reference ): bool => $icon[1] === $reference['token'] ) )[0];

// The theme's runtime map, exactly as Blocks Engine wrote it into functions.php.
$functions = (string) ( array_values( array_filter( $compiled['writes'], static fn( array $write ): bool => 'functions.php' === $write['target_path'] ) )[0]['payload']['data'] ?? '' );
$runtime   = preg_match( '/\$blocks_engine_template_asset_tokens = (array \(.*?\n\));/s', $functions, $map ) ? eval( 'return ' . $map[1] . ';' ) : array(); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- The generated map literal.
$assert( ( $runtime[ $icon[0] ] ?? null ) === $icon_asset['target_path'], 'the-theme-runtime-map-resolves-the-form-icon', $functions );
$assert( array_keys( Static_Site_Importer_Site_Plan_Persistence::template_asset_tokens( $compiled ) ) === array_keys( $runtime ), 'the-reference-check-covers-exactly-the-runtime-map' );

$theme_uri                         = 'https://example.test/wp-content/themes/imported';
$fixture                           = $plan;
$fixture['reference_tokens']       = $compiled['reference_tokens'];
$fixture['quality']['fallbacks']   = $compiled['quality']['fallbacks'];
$fixture['resolution']             = array( 'theme_uri' => $theme_uri );
$form_html                         = '<!-- wp:html --><form action="/contact" method="post"><input name="name"></form><!-- /wp:html -->';
$fixture['pages'][0]['canonical_block_markup'] .= $form_html;
$fixture['pages'][0]['resolved_block_markup']  .= $form_html;
$references                        = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver::references( $fixture['reference_tokens'], $theme_uri );

// The provider replacement carries the readable blocks' token, or its theme
// URL once a Media Library rebind resolved the provider fragments.
foreach ( array(
	'token' => $form_blocks,
	'url'   => strtr( $form_blocks, $references ),
) as $case => $replacement ) {
	$prepared     = $fixture;
	$reports      = array();
	$diagnostics  = array();
	$form_binding = array(
		'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
		'source_path'              => $record['source_path'],
		'search_block_markup'      => $form_html,
		'replacement_block_markup' => $replacement,
		'occurrence'               => 1,
		'role'                     => 'form',
		'reconciliation_identity'  => str_repeat( 'f', 64 ),
	);
	Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, array( $record, $form_binding ), $reports, $diagnostics );
	$archives = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
	$archive  = (string) ( $archives[0]['payload']['data'] ?? '' );
	$error    = Static_Site_Importer_Site_Plan_Persistence::validate_output_content( $archive, 'templates/archive-product.html', Static_Site_Importer_Site_Plan_Persistence::template_asset_tokens( $prepared ) );
	$assert( 1 === count( $archives ) && str_contains( $archive, 'woocommerce/product-collection' ), $case . '-archive-holds-the-product-collection' );
	$assert( null === $error, $case . '-archive-carries-only-tokens-the-theme-maps', null === $error ? '' : $error->get_error_message() );
	$assert( str_contains( $archive, $icon[0] ) && ! str_contains( $archive, 'https://example.test/' ), $case . '-archive-keeps-the-portable-icon-token', $archive );
	// As the theme's template filter renders it: every token becomes its theme file URL.
	$rendered = strtr( $archive, array_map( static fn( string $path ): string => $theme_uri . '/' . $path, $runtime ) );
	$assert( str_contains( $rendered, 'src="' . $theme_uri . '/' . $icon_asset['target_path'] . '"' ) && ! str_contains( $rendered, '{{wordpress-site-plan:' ), $case . '-rendered-archive-serves-the-icon', $rendered );
	$assert( str_contains( (string) $prepared['pages'][0]['materialized_block_markup'], $replacement ), $case . '-page-keeps-the-provider-form' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic.
	exit( 1 );
}
echo 'PASS: product archive form icon (' . $assertions . " assertions)\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI summary.
