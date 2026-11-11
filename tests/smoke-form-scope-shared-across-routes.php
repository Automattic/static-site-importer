<?php
/** The same form captured on several routes shares one generated class and rule set. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

$form = file_get_contents( __DIR__ . '/fixtures/form-readable-boxes.html' );
$other = file_get_contents( __DIR__ . '/fixtures/form-context-spacing.html' );
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => $form, 'about.html' => $form, 'contact.html' => $other ),
) )->toArray()['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$rows = Static_Site_Importer_Form_Seeder::seed( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) ) )['forms'];

$by_page = array();
foreach ( $rows as $row ) {
	if ( empty( $row['runtime_mapped'] ) || ! preg_match( '/\bssi-form-[a-f0-9]{12}\b/', $row['block_markup'], $scope ) ) {
		fwrite( STDERR, 'FAIL: every form maps with a generated class (' . $row['source_path'] . ")\n" );
		exit( 1 );
	}
	$by_page[ basename( $row['source_path'] ) ][] = array( $scope[0], $row['block_markup'], $row['provider_layout_overlay_css'] );
}
ksort( $by_page );
$checks = array(
	'Every captured route has its form'                         => array( 'about.html', 'contact.html', 'index.html' ) === array_keys( $by_page ),
	'The same form on two routes has one class, markup and CSS' => $by_page['index.html'] === $by_page['about.html'],
	'A differently presented form keeps its own class'          => $by_page['index.html'][0][0] !== $by_page['contact.html'][0][0],
);
foreach ( $checks as $label => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
}
echo "OK\n";
