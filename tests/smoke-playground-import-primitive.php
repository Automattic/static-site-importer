<?php
/**
 * Smoke test: public Playground import-on-boot primitive.
 *
 * Proves the reusable primitives that build the "import-on-boot" Playground
 * blueprint:
 *  - static_site_importer_playground_import_steps() includes the installPlugin
 *    step by default and always includes login + runPHP;
 *  - passing [ 'install' => false ] omits the installPlugin step (for runtimes
 *    where SSI is already present) while keeping login + runPHP;
 *  - the runPHP code runs the proven import entrypoint;
 *  - static_site_importer_playground_import_blueprint() produces the same step
 *    set as the legacy static_site_importer_rest_playground_blueprint(), proving
 *    the legacy function delegates to the public primitive.
 *
 * Run from the repository root:
 * php tests/smoke-playground-import-primitive.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) ) {
	define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';

/**
 * Return the step identifiers in a blueprint steps array.
 *
 * @param array<int,array<string,mixed>> $steps Blueprint steps.
 * @return array<int,string>
 */
$step_names = static function ( array $steps ): array {
	return array_values(
		array_map(
			static function ( $step ): string {
				return is_array( $step ) && isset( $step['step'] ) ? (string) $step['step'] : '';
			},
			$steps
		)
	);
};

$assert(
	function_exists( 'static_site_importer_playground_import_steps' ),
	'steps-function-exists',
	'public steps primitive is defined'
);
$assert(
	function_exists( 'static_site_importer_playground_import_blueprint' ),
	'blueprint-function-exists',
	'public blueprint primitive is defined'
);

$input = array(
	'name'     => 'Primitive Demo',
	'slug'     => 'primitive-demo',
	'activate' => true,
	'artifact' => array(
		'schema'     => 'static-site-importer/website-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><title>Primitive</title><h1>Hi</h1>',
			),
		),
	),
);

// --- Case 1: default steps include installPlugin + runPHP (+ login). ---
$default_steps = static_site_importer_playground_import_steps( $input );
$default_names = $step_names( $default_steps );

$assert( is_array( $default_steps ), 'default-array', 'default steps is an array' );
$assert(
	in_array( 'login', $default_names, true ),
	'default-login',
	'default steps include login'
);
$assert(
	in_array( 'installPlugin', $default_names, true ),
	'default-install',
	'default steps include installPlugin'
);
$assert(
	in_array( 'runPHP', $default_names, true ),
	'default-runphp',
	'default steps include runPHP'
);

// --- Case 2: install=false omits installPlugin but keeps login + runPHP. ---
$no_install_steps = static_site_importer_playground_import_steps( $input, array( 'install' => false ) );
$no_install_names = $step_names( $no_install_steps );

$assert(
	! in_array( 'installPlugin', $no_install_names, true ),
	'no-install-omits-install',
	'install=false omits the installPlugin step'
);
$assert(
	in_array( 'login', $no_install_names, true ),
	'no-install-keeps-login',
	'install=false keeps the login step'
);
$assert(
	in_array( 'runPHP', $no_install_names, true ),
	'no-install-keeps-runphp',
	'install=false keeps the runPHP step'
);

// --- Case 3: runPHP code runs the proven import entrypoint. ---
$run_php_code = '';
foreach ( $no_install_steps as $step ) {
	if ( is_array( $step ) && isset( $step['step'] ) && 'runPHP' === $step['step'] ) {
		$run_php_code = (string) ( $step['code'] ?? '' );
		break;
	}
}

$assert(
	false !== strpos( $run_php_code, 'static_site_importer_ability_import_website_artifact' ),
	'runphp-entrypoint',
	'runPHP code calls the import website artifact ability'
);
$assert(
	false !== strpos( $run_php_code, 'website/index.html' ),
	'runphp-embedded-artifact',
	'runPHP code embeds the artifact entrypoint via var_export'
);
$assert(
	false !== strpos( $run_php_code, 'static_site_importer_playground_preview_result' ),
	'runphp-preview-result',
	'runPHP code persists the preview result option'
);

// --- Case 4: blueprint primitive matches the legacy REST blueprint (delegation parity). ---
$primitive_blueprint = static_site_importer_playground_import_blueprint( $input );
$legacy_blueprint    = static_site_importer_rest_playground_blueprint( $input );

$assert(
	$primitive_blueprint === $legacy_blueprint,
	'delegation-parity',
	'public blueprint is byte-identical to the legacy REST blueprint'
);
$assert(
	isset( $primitive_blueprint['steps'] ) && $primitive_blueprint['steps'] === $default_steps,
	'blueprint-steps-parity',
	'public blueprint embeds the public default steps'
);

// --- Report. ---
if ( empty( $failures ) ) {
	echo 'PASS: smoke-playground-import-primitive (' . (int) $assertions . " assertions)\n";
	exit( 0 );
}

echo 'FAILURES (' . count( $failures ) . ' of ' . (int) $assertions . " assertions):\n";
foreach ( $failures as $failure ) {
	echo ' - ' . $failure . "\n";
}
exit( 1 );
