<?php
/**
 * Regression for #2305: context copy must not double projected sibling spacing.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression, using filesystem and output without WordPress.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-context-spacing.html' ) ),
	)
)->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $declaration['payload']['entities'] ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$css         = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$overlay     = $row['provider_layout_overlay_css']['css'];
$markup      = $row['block_markup'];

if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'overlay', 'markup' ) );
	exit;
}

$checks = array(
	'fixture maps'          => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'context copy retained' => str_contains( $markup, 'No obligation.' ),
	'context spacing reset' => (bool) preg_match( '/\.ssi-context-[a-f0-9]{12}[^{}]*\{[^}]*margin-block-start:0!important/', $overlay ),
);
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
	}
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
