<?php
/**
 * Regression for #2295: the submit control, not its wrapper, owns its height.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI fixture I/O.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/submit-box.html' ) ),
	)
)->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $declaration['payload']['entities'] ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$css         = implode( "\n", array_map( static fn( array $write ): string => str_ends_with( $write['target_path'], '.css' ) ? $write['payload']['data'] : '', $plan['writes'] ) );
$overlay     = $row['provider_layout_overlay_css']['css'];
$markup      = $row['block_markup'];
preg_match( '/<!-- wp:(?:core\/)?button (.*?) -->/', $markup, $match );
$attrs = json_decode( $match[1] ?? '{}', true );
preg_match( '/<div class="wp-block-button[^>]*>.*?<\/div>/s', $markup, $match );
$button = Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $match[0] ?? '', array( 'attrs' => $attrs ) );
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( compact( 'css', 'overlay', 'button' ) );
	exit;
}
preg_match_all( '/ssi-node-[a-f0-9]{12}/', $attrs['className'] ?? '', $hooks );
$wrapper_selector = '\\.(?:' . implode( '|', $hooks[0] ) . ')';
$checks           = array(
	'fixture maps'                       => 'mapped' === $row['status'] && empty( $validated['errors'] ),
	'wrapper has no fixed source height' => ! preg_match( '/' . $wrapper_selector . '\\{(?:[^}]*;)?height:/', $overlay ),
	'link owns base source height'       => (bool) preg_match( '/> \.wp-block-button__link\{[^}]*[;{]height:2\.25rem/', $overlay ),
	'link owns responsive source height' => (bool) preg_match( '/> \.wp-block-button__link\{(?:[^}]*;)?height:2rem/', $overlay ),
);
// Layout-only heights, non-submit controls and ancestor boxes retain ownership.
$graph = array(
	'nodes'    => array(
		array(
			'id'     => 'control-0',
			'layout' => array( 'height' => '36px' ),
		),
		array(
			'id'     => 'control-1',
			'layout' => array( 'height' => '36px' ),
		),
		array(
			'id'     => 'wrapper-0',
			'layout' => array( 'height' => '36px' ),
		),
	),
	'variants' => array(
		array(
			'node'         => 'control-0',
			'condition'    => array(
				'kind'  => 'media',
				'query' => '(max-width:600px)',
			),
			'layout_patch' => array( 'height' => '40px' ),
		),
	),
);
$form  = array(
	'controls'           => array( array( 'type' => 'submit' ), array( 'type' => 'text' ) ),
	'presentation_graph' => array(
		'controls' => array(
			array(
				'index'   => 1,
				'control' => array( 'styles' => array( 'height' => '36px' ) ),
			),
		),
		'variants' => array(),
	),
);
$checks['layout-only and non-submit heights remain'] = Static_Site_Importer_Form_Layout_Projection::without_presented_submit_heights( $graph, $form ) === $graph;
$form['presentation_graph']['controls'][]            = array(
	'index'   => 0,
	'control' => array( 'styles' => array( 'height' => '36px' ) ),
);
$projected = Static_Site_Importer_Form_Layout_Projection::without_presented_submit_heights( $graph, $form );
$checks['only duplicate base submit height leaves'] = ! isset( $projected['nodes'][0]['layout']['height'] ) && $graph['nodes'][1] === $projected['nodes'][1] && $graph['nodes'][2] === $projected['nodes'][2] && $graph['variants'] === $projected['variants'];
$form['presentation_graph']['variants'][]           = array(
	'index'       => 0,
	'role'        => 'control',
	'condition'   => array(
		'kind'  => 'media',
		'query' => '(max-width:480px)',
	),
	'style_patch' => array( 'height' => '40px' ),
);
$checks['different responsive conditions remain']   = Static_Site_Importer_Form_Layout_Projection::without_presented_submit_heights( $graph, $form ) === $projected;
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
