<?php
/** End-to-end import fixture for #2507. */
// phpcs:ignoreFile -- Standalone CLI fixture.
require_once __DIR__ . '/core-html-api.php';
require_once __DIR__ . '/form-seeder-cli-stubs.php';
require_once __DIR__ . '/form-jetpack-render.php';

$source = '<style>*{margin:0;font-family:Georgia,serif}.cta{display:inline-block;font-family:inherit;font-size:16px;padding:12px 24px;background:#123456;border:2px solid #789abc;color:white;text-decoration:none}.box{font-family:"Courier New",monospace}</style><div class="box"><p>Box text</p><a class="cta" href="https://example.com/">Go</a></div><form class="box" action="https://example.com/s" method="post"><input type="email" name="email"><button class="cta" type="submit">Submit</button></form>';
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $source ) ) )->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
if ( 'mapped' !== $row['status'] || ! empty( $validated['errors'] ) ) {
	throw new RuntimeException( 'Fixture did not map.' );
}
$css = implode( "\n", array_map( static fn( array $asset ): string => $asset['content'], array_filter( $compiled['assets'], static fn( array $asset ): bool => 'css' === $asset['kind'] ) ) );
$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Submit carriers', $css, array(), array(), array( $row['provider_layout_overlay_css'] ) );
$blocks = ssi_role_box_parse_blocks( $row['block_markup'] );
$form = ssi_role_box_render_form( $blocks[0] );
$html = str_replace( $compiled['blocks'][1]['innerHTML'], $form, $compiled['serialized_blocks'] );
echo json_encode( array( 'source' => $source, 'html' => $html, 'css' => $writes['/theme/style.css'] ), JSON_UNESCAPED_SLASHES );
