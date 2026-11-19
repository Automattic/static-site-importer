<?php
/**
 * A source form that resolves to `display:none` as a grid item generates no
 * box. Its provider form stays a real Jetpack form, but the outermost rendered
 * wrapper (`.jetpack-contact-form-container`) must carry the hidden state too:
 * a 0-high block wrapper still takes a grid row and the parent's gap (#3149).
 */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

$html = file_get_contents( __DIR__ . '/fixtures/form-hidden-grid-item.html' );
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $html ),
) )->toArray()['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$rows        = Static_Site_Importer_Form_Seeder::seed( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) ) )['forms'];
$row         = $rows[0] ?? array();
$markup      = (string) ( $row['block_markup'] ?? '' );
$css         = (string) ( $row['provider_layout_overlay_css']['css'] ?? '' );
$editor_css  = (string) ( $row['provider_layout_overlay_css']['editor_css'] ?? '' );
preg_match( '/\bssi-form-[a-f0-9]{12}\b/', $markup, $scope );
$scope     = $scope[0] ?? '';
$container = '.' . $scope . '.' . $scope . '.jetpack-contact-form-container{display:none}';

$checks = array(
	'The hidden source form stays a real Jetpack form' => ! empty( $row['runtime_mapped'] ) && str_contains( $markup, '<!-- wp:jetpack/contact-form' ) && '' !== $scope,
	'The scoped CSS hides the outermost provider container' => str_contains( $css, $container ),
	'The editor keeps the form visible and editable' => ! preg_match( '/\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})?\{display:none\}/', $editor_css ),
);
foreach ( $checks as $label => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$label}\n{$css}\n" );
		exit( 1 );
	}
}
echo "OK\n";
