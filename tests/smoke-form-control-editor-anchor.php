<?php
/**
 * A provider form control keeps the source rules anchored to its id.
 *
 * Jetpack owns each input's id, so the compiler's id-anchored source rules
 * (here a captured textarea height) reach the provider input only through the
 * id's editor-anchor class.
 *
 * Run from the repository root:
 *   php tests/smoke-form-control-editor-anchor.php
 */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$document = static fn( string $device ): string => '<div data-blocks-engine-capture-device-document="' . $device . '"><main><form data-x-submit="send"><label for="name_1">Name</label><input id="name_1" type="text"><div class="box"><label for="textarea_1">Message</label><textarea id="textarea_1" class="ta" rows="2"></textarea></div><button type="submit">Send</button></form></main></div>';
$scope    = static fn( string $device ): string => ':where([data-blocks-engine-capture-device-document="' . $device . '"])';
$html     = '<!doctype html><html><head>'
	. '<style data-blocks-engine-capture-device-style="desktop">' . $scope( 'desktop' ) . ' .box .ta{height:86px}</style>'
	. '<style data-blocks-engine-capture-device-style="mobile">' . $scope( 'mobile' ) . ' .box .ta{height:120px}</style>'
	. '</head><body>' . $document( 'desktop' ) . $document( 'mobile' ) . '</body></html>';

$plan        = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$row         = Static_Site_Importer_Form_Seeder::seed( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) ) )['forms'][0];

$input_class = '';
foreach ( parse_blocks( $row['block_markup'] ) as $block ) {
	$stack = array( $block );
	while ( $stack ) {
		$current = array_pop( $stack );
		if ( 'jetpack/input' === $current['blockName'] && 'textarea' === ( $current['attrs']['type'] ?? '' ) ) {
			$input_class = (string) ( $current['attrs']['className'] ?? '' );
		}
		array_push( $stack, ...$current['innerBlocks'] );
	}
}
$css = implode( '', array_map( static fn( array $asset ): string => is_string( $asset['content'] ?? null ) ? $asset['content'] : '', $plan['assets'] ) );

$checks = array(
	'form maps'                                => 'mapped' === $row['status'],
	'site plan anchors the height to the id'   => 1 === preg_match( '/blocks-engine-editor-anchor-textarea_1\b[^{}]*\{height:86px\}/', $css ),
	'provider textarea wears the anchor class' => in_array( 'blocks-engine-editor-anchor-textarea_1', preg_split( '/\s+/', $input_class ), true ),
);
$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . "\n" );
	exit( 1 );
}
echo "PASS: provider form control keeps id-anchored source rules\n";
