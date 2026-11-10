<?php
/** The wrapper paints the frame; the input's border reset must survive materialization. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html   = (string) file_get_contents( __DIR__ . '/fixtures/form-input-border-reset.html' );
$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $html ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row         = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$inputs      = array();
$walk        = static function ( array $blocks ) use ( &$walk, &$inputs ): void {
	foreach ( $blocks as $block ) {
		if ( 'jetpack/input' === ( $block['blockName'] ?? '' ) ) {
			$inputs[] = $block['attrs'];
		}
		$walk( $block['innerBlocks'] ?? array() );
	}
};
$walk( parse_blocks( $row['block_markup'] ) );
$failures = array();
if ( 3 !== count( $inputs ) ) {
	$failures[] = 'text, email and textarea must all materialize';
}
foreach ( $inputs as $index => $attrs ) {
	if ( isset( $attrs['style']['border']['style'] ) && 'none' !== $attrs['style']['border']['style'] ) {
		$failures[] = "control {$index} overrides the source reset: " . json_encode( $attrs['style']['border'] );
	}
}
// Without a reset in the overlay, merely dropping the inline style would
// expose the provider/UA border instead of preserving the authored result.
$css = $row['provider_layout_overlay_css']['css'] ?? '';
foreach ( $inputs as $index => $attrs ) {
	$reset = false;
	foreach ( preg_split( '/\s+/', $attrs['className'] ?? '' ) as $class ) {
		if ( '' !== $class && preg_match( '/\.' . preg_quote( $class, '/' ) . '(?=[,\s{])[^{}]*\{[^}]*border:none(?:;|\})/', $css ) ) {
			$reset = true;
		}
	}
	if ( ! $reset ) {
		$failures[] = "control {$index} must retain its border reset in the overlay";
	}
}
foreach ( $failures as $failure ) {
	fwrite( STDERR, "FAIL: {$failure}\n" );
}
exit( empty( $failures ) ? 0 : 1 );
