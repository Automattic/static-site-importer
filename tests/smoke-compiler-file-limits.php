<?php
/**
 * Smoke coverage for the single compiler-limit owner and omitted-file detection.
 *
 * Run from the repository root:
 * php tests/smoke-compiler-file-limits.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		unset( $hook );
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-compiler-limits.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// One owner: defaults are the compiler hard caps, bounds only tighten.
$defaults = Static_Site_Importer_Compiler_Limits::resolve();
$assert( 5000 === $defaults['max_files'], 'default max_files is the 5,000-file compiler cap' );
$assert( 12 === Static_Site_Importer_Compiler_Limits::resolve( array( 'max_files' => 12 ) )['max_files'], 'a tighter intake bound applies' );
$assert( 5000 === Static_Site_Importer_Compiler_Limits::resolve( array( 'max_files' => 90000 ) )['max_files'], 'a bound never widens the hard cap' );
$staged = static_site_importer_staged_archive_compiler_limits();
$assert( 5000 === $staged['max_files'] && 10485760 === $staged['max_file_bytes'] && 262144000 === $staged['max_total_bytes'], 'staged archives clamp through the same owner' );

// The canonical service declares limits for every non-Figma source type.
$service = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php' );
$assert( str_contains( $service, "\$runtime_source['metadata']['compiler_limits'] = Static_Site_Importer_Compiler_Limits::resolve();" ), 'inline sources declare the shared compiler contract' );

// Real compiler: an undeclared contract truncates; the declared one does not.
$files = array();
for ( $index = 0; $index < 520; $index++ ) {
	$files[] = array(
		'path'    => 'page-' . $index . '.html',
		'content' => '<!doctype html><html><body><main><h1>Page ' . $index . '</h1></main></body></html>',
	);
}
$compile = static function ( array $artifact ): array {
	$result = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $artifact );
	return $result->toCompactWordPressSitePlanView()['wordpress_site_plan']['diagnostics'] ?? array();
};
$truncated = $compile(
	array(
		'entrypoint' => 'page-0.html',
		'files'      => $files,
	)
);
$assert( 20 === Static_Site_Importer_Diagnostic_Loss_Classes::omitted_artifact_file_count( $truncated ), 'the compiler default drops 20 of 520 files and the drop is counted' );
$complete = $compile(
	array(
		'entrypoint'      => 'page-0.html',
		'files'           => $files,
		'compiler_limits' => Static_Site_Importer_Compiler_Limits::resolve(),
	)
);
$assert( 0 === Static_Site_Importer_Diagnostic_Loss_Classes::omitted_artifact_file_count( $complete ), 'the shared contract admits all 520 files' );

// The aggregate rejection row is reowned as an omitted-files loss.
$aggregate = array_values(
	array_filter( $truncated, static fn( $row ): bool => 'artifact_inputs_rejected' === ( $row['code'] ?? '' ) )
)[0] ?? array();
$reowned   = Static_Site_Importer_Diagnostic_Loss_Classes::reown_compiler_file_drop( $aggregate );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_ARTIFACT_FILES_TYPE === ( $reowned['type'] ?? '' ), 'aggregate rejection becomes an omitted-files diagnostic' );
$assert( 20 === Static_Site_Importer_Diagnostic_Loss_Classes::omitted_artifact_file_count( array( $reowned ) ), 'the reowned row keeps its dropped count' );

// Direct imports refuse a partial site before materialization.
$direct = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-direct-artifact-import.php' );
$refuse = strpos( $direct, 'static_site_importer_artifact_files_omitted' );
$assert( false !== $refuse && $refuse < strpos( $direct, 'import_website_artifact( $artifact, $args )' ), 'omitted files fail before materialization' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-compiler-file-limits: ok\n";
