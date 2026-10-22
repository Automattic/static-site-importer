<?php
/** Regression for #2306: linked source CSS and editor twins preserve placeholders. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'      => file_get_contents( __DIR__ . '/fixtures/linked-placeholder-form.html' ),
		'assets/form.css' => file_get_contents( __DIR__ . '/fixtures/assets/form.css' ),
	),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $declaration['payload']['entities'] ) );
$row         = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
$overlay     = $row['provider_layout_overlay_css'];
$state       = array( 'theme_dir' => '/tmp/linked-placeholder-theme', 'theme' => array( 'slug' => 'linked-placeholder' ), 'resolved' => array( 'writes' => $plan['writes'] ) );
$writes      = Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $state, array( $overlay ) );
preg_match( '/\.ssi-form-[a-f0-9]{12}/', $overlay['css'], $match );
$scope       = ':is(' . str_repeat( $match[0], 5 ) . ' *)';
$assets      = array();
$checks      = array( 'fixture maps' => 'mapped' === $row['status'] && empty( $validated['errors'] ) );
foreach ( $plan['writes'] as $write ) {
	$target = $write['target_path'];
	if ( ! str_starts_with( $target, 'assets/assets/' ) || ! str_ends_with( $target, '.css' ) ) {
		continue;
	}
	$path = $state['theme_dir'] . '/' . $target;
	$assets[ $target ] = $writes[ $path ] ?? $write['payload']['data'];
	$checks[ $target . ' preserves authored placeholders' ] = str_contains( $assets[ $target ], 'input' . $scope . '::placeholder' );
	$checks[ $target . ' does not duplicate provider overlay' ] = ! str_contains( $assets[ $target ], '::placeholder{color:revert}' );
}
$checks['linked frontend and editor assets emitted'] = 2 === count( $assets ) && 1 === count( array_filter( array_keys( $assets ), static fn( string $path ): bool => str_ends_with( $path, '.editor.css' ) ) );
$repeat_state = $state;
foreach ( $repeat_state['resolved']['writes'] as &$write ) {
	$path = $state['theme_dir'] . '/' . $write['target_path'];
	$write['payload']['data'] = $writes[ $path ] ?? $write['payload']['data'];
}
unset( $write );
$repeat = Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $repeat_state, array( $overlay ) );
foreach ( $assets as $target => $css ) {
	$checks[ $target . ' is idempotent' ] = ( $repeat[ $state['theme_dir'] . '/' . $target ] ?? $css ) === $css;
}
$checks['no forms leave assets alone'] = array() === Static_Site_Importer_Site_Plan_Persistence::provider_layout_stylesheet_writes( $state, array() );
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( array( 'assets' => $assets, 'css' => $writes[ $state['theme_dir'] . '/style.css' ], 'editor' => $writes[ $state['theme_dir'] . '/assets/css/editor-style.css' ], 'overlay' => $overlay['css'], 'markup' => $row['block_markup'] ) );
	exit;
}
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
