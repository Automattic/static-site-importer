<?php
/**
 * Seed the reduced #2620 source form and export what the responsive border
 * test renders on three surfaces: the source page, the front end (a model of
 * Jetpack's server render of the seeded block markup through the provider
 * runtime filters) and the editor canvas (built by the test from the parsed
 * blocks).
 *
 * Two source fields scope their border to a 1280px media query alone; one
 * field keeps an unconditional border; one field scopes its border to the
 * media query without any unconditional rule of its own.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	require_once __DIR__ . '/form-jetpack-render.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;

	$source = (string) file_get_contents( __DIR__ . '/form-responsive-border.html' );
	$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $source ) ) )->toArray();
	$fallback = $compiled['fallbacks'][0] ?? array();
	$valid    = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
	$row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $valid['forms'] ?? array() ) )['forms'][0] ?? array();
	if ( 'mapped' !== ( $row['status'] ?? null ) ) {
		fwrite( STDERR, "source form did not map: " . wp_json_encode( array( 'status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null, 'losses' => $row['form_receipt_unaccepted_losses'] ?? null ) ) . "\n" );
		exit( 1 );
	}
	// The imported theme serves the stylesheets the compile emits (engine
	// support rules, then the projected source CSS and the provider overlay),
	// not the raw source.
	$page_css = implode( "\n", array_map( static fn ( array $asset ): string => (string) $asset['content'], array_filter( $compiled['assets'] ?? array(), static fn ( array $asset ): bool => 'css' === ( $asset['kind'] ?? null ) ) ) );
	$writes   = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Responsive Border', $page_css, array(), array(), array( $row['provider_layout_overlay_css'] ) );
	echo (string) wp_json_encode(
		array(
			'forms' => array(
				array(
					'name'       => 'responsive_border',
					'sourceHtml' => $source,
					'blocks'     => ssi_role_box_parse_blocks( (string) $row['block_markup'] ),
					'frontHtml'  => ssi_role_box_render_form( ssi_role_box_parse_blocks( (string) $row['block_markup'] )[0] ),
					'css'        => $writes['/theme/style.css'],
					'editorCss'  => $writes['/theme/assets/css/editor-style.css'],
				),
			),
		),
		JSON_UNESCAPED_SLASHES
	);
}
