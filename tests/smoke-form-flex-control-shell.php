<?php
/** A growing source flex control fills its provider field shell. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>.row{display:flex;width:360px}.row .field{flex:1;width:auto}</style></head><body><form><div class="row" style="display:flex"><input class="field" type="email" name="email"><button>Go</button></div></form></body></html>';
$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $html ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$rendered = ssi_role_box_render_form( ssi_role_box_parse_blocks( $row['block_markup'] )[0] ?? array() );
preg_match( '/<input[^>]*class=[\'"]([^\'"]*\bfield\b[^\'"]*)[\'"]/', $rendered, $match );
preg_match_all( '/\bssi-node-[a-f0-9]{12}\b/', $match[1] ?? '', $hooks );
$hook = end( $hooks[0] ) ?: '';
$rule = static fn( string $selector ): string => implode( ';', preg_match_all( '/(?:(?<=\})|^)[^{}]*\.' . preg_quote( $selector, '/' ) . '(?=[,{])[^{}]*\{([^}]*)\}/', $row['provider_layout_overlay_css']['css'] ?? '', $found ) ? $found[1] : array() );
$control = $rule( $hook );
preg_match_all( '/(?:^|;)width:([^;]+)/', $control, $widths );
$checks = array(
	'form maps without losses' => 'mapped' === $row['status'] && empty( $row['mapping_decision']['losses'] ),
	'provider shell wraps the control' => '' !== $hook && str_contains( $rendered, $hook . '-wrap' ),
	'shell carries source field class for flex growth' => str_contains( $rendered, 'field-wrap' ) && str_contains( $control, 'flex:1' ),
	'control fills the shell instead of intrinsic width' => '100%' === end( $widths[1] ),
	'overlay is admitted' => null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $row['provider_layout_overlay_css'] ),
);
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
