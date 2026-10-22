<?php
/**
 * Regression for #2293: submit icons retain their producer-declared label side.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI fixture I/O.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/submit-icon-sides.html' ) ),
	)
)->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $declaration['payload']['entities'] ) );
$checks      = array(
	'fixture validates' => empty( $validated['errors'] ) && 2 === count( $validated['forms'] ),
);

// The consumer needs no source HTML or selector-path matching to place an icon.
foreach ( $validated['forms'] as &$form ) {
	unset( $form['html'] );
	foreach ( $form['presentation_graph']['visual_parts'] as &$part ) {
		$part['source_selector'] = 'unused-selector';
	}
	unset( $part );
}
unset( $form );
$rows = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'];
foreach ( array( 'before', 'after' ) as $index => $side ) {
	$row    = $rows[ $index ];
	$markup = $row['block_markup'];
	preg_match( '/<div class="wp-block-button[^>]*><button[^>]*>(.*?)<\/button><\/div>/s', $markup, $match );
	$button                                = $match[1] ?? '';
	$label                                 = 'before' === $side ? 'Send leading' : 'Send trailing';
	$checks[ $side . ' maps' ]             = 'mapped' === $row['status'];
	$checks[ $side . ' captured' ]         = ( $validated['forms'][ $index ]['presentation_graph']['visual_parts'][0]['label_side'] ?? null ) === $side;
	$checks[ $side . ' saved icon order' ] = 1 === substr_count( $button, '<svg' )
		&& str_contains( $button, $label )
		&& ( 'before' === $side ? strpos( $button, '<svg' ) < strpos( $button, $label ) : strpos( $button, '<svg' ) > strpos( $button, $label ) );
}
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
