<?php
/**
 * Theme hygiene counters reach the import report quality gate (#3109).
 *
 * Runs a reduced import: compiles one page through ArtifactCompiler, writes the
 * resolved theme and its scoped runtime package into a temporary theme
 * directory, then composes and finalizes the import report. Each case asserts
 * every counter is present in quality and the import-metrics summary, and that
 * gated counters fail `quality_pass` only when above zero. A counter whose
 * producer value is missing reports null and passes.
 *
 * Run: php tests/smoke-theme-hygiene-quality-gate.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone smoke writes a temporary theme.

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require dirname( __DIR__ ) . '/vendor/autoload.php';
// Reuse the companion scaffolder's standalone WordPress doubles.
define( 'SSI_COMPANION_DOUBLES_ONLY', true );
require __DIR__ . '/smoke-companion-plugin.php';
foreach ( array( 'generated-runtime-package', 'published-file-mode', 'theme-hygiene', 'import-report', 'diagnostic-loss-classes', 'source-findings', 'diagnostic-contract', 'artifact-diagnostics-adapter', 'report-diagnostics', 'asset-reporter', 'document-metadata-reporter', 'block-document-reporter', 'receipt-projection' ) as $class_file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $class_file . '.php';
}

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$gated    = Static_Site_Importer_Theme_Hygiene::GATED;
$counters = array_keys( Static_Site_Importer_Theme_Hygiene::defaults() );

// The bootstrap feature vocabulary covers every feature Blocks Engine's bootstrap serves.
$bootstrap_source = (string) file_get_contents( dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/ThemeBootstrap.php' );
preg_match_all( '~self::serve\(\$lines, \$served, \$units, ([^;]+)\);~', $bootstrap_source, $serve_calls );
preg_match_all( "~'([a-z_]+)'~", implode( ' ', $serve_calls[1] ), $served_features );
$unknown = array_diff( array_unique( $served_features[1] ), Static_Site_Importer_Theme_Hygiene::BOOTSTRAP_FEATURES, array( 'navigation-link' ) );
$assert( array() !== $served_features[1] && array() === $unknown, 'bootstrap-feature-vocabulary: ' . implode( ', ', $unknown ) );

// Reduced import: compile one page and resolve its WordPress site plan.
$compiled = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><title>Studio</title></head><body><header><a href="index.html">Studio</a></header><main><h1>Portfolio</h1><p>Weddings and portraits.</p></main><footer>Contact the studio</footer></body></html>',
		),
	)
)->toArray();
$plan     = $compiled['source_reports']['wordpress_site_plan'];
$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://example.test/wp-content/themes/hygiene-studio' ) );
$payload  = Static_Site_Importer_Generated_Runtime_Package::scoped_payload(
	array(
		'schema'           => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
		'site_slug'        => 'hygiene-studio',
		'site_name'        => 'Hygiene studio',
		'blocks'           => array(),
		'runtime_features' => array(),
	),
	array( $resolved )
);
$package  = Static_Site_Importer_Generated_Runtime_Package::theme( $payload, 'hygiene-studio' );
$assert( ! is_wp_error( $package ), 'runtime-package-builds' );
foreach ( $package['files'] as $target => $bytes ) {
	$resolved['writes'][] = array(
		'target_path' => $target,
		'payload'     => array(
			'encoding' => 'utf8',
			'data'     => $bytes,
		),
	);
}

/**
 * Materialize the resolved writes into a fresh theme directory and compose,
 * finalize and summarize the import report.
 */
$import = static function ( array $plan, array $resolved, array $package, array $payload, callable $after_write ): array {
	$theme_dir = sys_get_temp_dir() . '/ssi-theme-hygiene-' . bin2hex( random_bytes( 6 ) );
	$files     = array();
	foreach ( $resolved['writes'] as $write ) {
		$path = $theme_dir . '/' . $write['target_path'];
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		$data = (string) ( $write['payload']['data'] ?? '' );
		file_put_contents( $path, 'base64' === ( $write['payload']['encoding'] ?? '' ) ? base64_decode( $data ) : $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plan's transport encoding.
		Static_Site_Importer_Published_File_Mode::apply_file_mode( $path );
		$files[] = array( 'target_path' => $write['target_path'] );
	}
	$receipt = array(
		'status'           => 'completed',
		'plan'             => $plan + array( 'writes' => array() ),
		'theme'            => array(
			'slug' => 'hygiene-studio',
			'dir'  => $theme_dir,
		),
		'destination'      => array( 'mode' => 'generated_theme' ),
		'completed'        => array(
			'files'         => $files,
			'theme_runtime' => array(
				'status'           => 'theme_owned',
				'owner'            => 'theme',
				'owner_slug'       => $package['owner_slug'],
				'block_names'      => $package['block_names'],
				'runtime_scripts'  => $package['runtime_scripts'],
				'shipped_files'    => Static_Site_Importer_Generated_Runtime_Package::shipped_files( $package ),
				'runtime_features' => Static_Site_Importer_Generated_Runtime_Package::used_features( $payload ),
			),
		),
		'existing_matches' => array( 'pages' => array() ),
		'extensions'       => array(),
	);
	$receipt = $after_write( $receipt, $theme_dir );
	$report  = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), array(), array(), array(), 'theme-hygiene-run', array(), array() )['report'];
	$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
	$summary = Static_Site_Importer_Diagnostic_Projection::import_report_summary( $report, $quality );
	exec( 'rm -rf ' . escapeshellarg( $theme_dir ) );
	return array( $quality, $summary );
};

require_once dirname( __DIR__ ) . '/includes/cli.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';

$cases = array(
	'clean import passes; a counter its producer did not emit is null' => array(
		'plan'     => static function ( array $plan ): array {
			unset( $plan['theme']['design_system']['metrics']['unnamed_preset_count'], $plan['theme']['design_system']['metrics']['duplicate_preset_name_count'] );
			return $plan;
		},
		'receipt'  => static fn( array $receipt ): array => $receipt,
		'pass'     => true,
		'reasons'  => array(),
		'expected' => array(
			'non_public_theme_file_count'          => 0,
			'literal_preset_color_count'           => 0,
			'empty_asset_count'                    => 0,
			'unreferenced_engine_class_count'      => 0,
			'unnamed_preset_count'                 => null,
			'duplicate_preset_name_count'          => null,
			'unused_runtime_file_count'            => 0,
			'bootstrap_unit_without_feature_count' => 0,
		),
	),
	'every gated counter above zero fails; the token share stays report-only' => array(
		'plan'     => static function ( array $plan ): array {
			$plan['quality']['metrics']['empty_asset_count']                = 2;
			$plan['quality']['metrics']['unreferenced_engine_class_count']  = 3;
			$plan['quality']['metrics']['engine_class_token_share']         = 0.75;
			$plan['theme']['design_system']['metrics']['unnamed_preset_count']        = 4;
			$plan['theme']['design_system']['metrics']['duplicate_preset_name_count'] = 1;
			$plan['theme']['design_system']['metrics']['literal_preset_color_count']  = 5;
			$plan['theme']['bootstrap_units'][] = array( 'hook' => 'init', 'feature' => '' );
			$plan['theme']['bootstrap_units'][] = array( 'hook' => 'wp_head', 'feature' => 'not_a_plan_feature' );
			return $plan;
		},
		'receipt'  => static function ( array $receipt, string $theme_dir ): array {
			chmod( $theme_dir . '/style.css', 0600 );
			$receipt['completed']['theme_runtime']['shipped_files'][] = 'ssi-runtime/includes/provider-form-runtime-v1.php';
			return $receipt;
		},
		'pass'     => false,
		'reasons'  => array_values( $gated ),
		'expected' => array(
			'non_public_theme_file_count'          => 1,
			'literal_preset_color_count'           => 5,
			'empty_asset_count'                    => 2,
			'unreferenced_engine_class_count'      => 3,
			'unnamed_preset_count'                 => 4,
			'duplicate_preset_name_count'          => 1,
			'unused_runtime_file_count'            => 1,
			'bootstrap_unit_without_feature_count' => 2,
			'engine_class_token_share'             => 0.75,
		),
	),
);

foreach ( $cases as $name => $case ) {
	list( $quality, $summary ) = $import( $case['plan']( $plan ), $resolved, $package, $payload, $case['receipt'] );
	foreach ( $counters as $counter ) {
		if ( ! array_key_exists( $counter, $quality ) || ! array_key_exists( $counter, $summary ) ) {
			$assert( false, "{$name}: {$counter} is present in quality and the summary" );
			continue;
		}
		$assert( $quality[ $counter ] === $summary[ $counter ], "{$name}: the summary reports quality's {$counter}" );
	}
	foreach ( $case['expected'] as $counter => $value ) {
		$actual = array_key_exists( $counter, $quality ) ? $quality[ $counter ] : 'missing';
		$assert( $value === $actual, "{$name}: {$counter} is " . var_export( $value, true ) . ', got ' . var_export( $actual, true ) );
	}
	$hygiene_reasons = array_values( array_intersect( $quality['failure_reasons'], $gated ) );
	$assert( $case['reasons'] === $hygiene_reasons, "{$name}: hygiene failure reasons are " . implode( ', ', $case['reasons'] ) . '; got ' . implode( ', ', $hygiene_reasons ) );
	$assert( $case['pass'] === $quality['pass'] && $case['pass'] === $summary['quality_pass'], "{$name}: quality_pass is " . var_export( $case['pass'], true ) . '; reasons ' . implode( ', ', $quality['failure_reasons'] ) );
	$assert( ! in_array( 'engine_class_token_share', $quality['failure_reasons'], true ), "{$name}: engine_class_token_share is never a failure reason" );
	$response = Static_Site_Importer_Canonical_Import_Service::success( array( 'quality' => $quality, 'import_report_summary' => $summary ), array(), false );
	$receipt  = static_site_importer_cli_import_receipt( $response, 1 );
	$assert( true === $receipt['response']['success'] && 'completed' === $receipt['status'], "{$name}: materialized import completes successfully" );
	$assert( $quality === $receipt['response']['result']['quality'], "{$name}: receipt preserves every quality counter and reason" );
	if ( ! $case['pass'] ) {
		$assert( 2 === $receipt['response']['result']['quality']['empty_asset_count'] && in_array( 'empty_asset', $receipt['response']['result']['quality']['failure_reasons'], true ), 'end-to-end: nonzero hygiene counter and reason survive successful completion' );
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "PASS: materialized imports complete with hygiene counters and reasons intact\n";
