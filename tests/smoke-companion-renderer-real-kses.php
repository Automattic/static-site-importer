<?php
/**
 * The generated companion render template keeps var()-driven color functions
 * through WordPress core wp_kses()/safecss_filter_attr() (#2623), while values
 * core rejects stay dropped.
 *
 * STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-companion-renderer-real-kses.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	$root = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' );
	if ( '' === $root || ! is_readable( $root . '/wp-load.php' ) ) {
		fwrite( STDERR, "FAIL: Set STATIC_SITE_IMPORTER_WP_ROOT to a WordPress install.\n" );
		exit( 1 );
	}
	$GLOBALS['wp_filter'] = array( 'option_active_plugins' => array( 10 => array( 'ssi_skip_installed_importer' => array( 'function' => static fn ( $plugins ): array => array_values( array_filter( (array) $plugins, static fn ( $plugin ): bool => ! str_starts_with( (string) $plugin, 'static-site-importer/' ) ) ), 'accepted_args' => 1 ) ) ) );
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	require $root . '/wp-load.php';
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
$method = new ReflectionMethod( Static_Site_Importer_Companion_Plugin::class, 'editable_content_renderer' );
$method->setAccessible( true );
$renderer = $method->invoke( null );
$render = static function ( string $content ) use ( $renderer ): string {
	$attributes = array( 'content' => $content );
	$block      = null;
	ob_start();
	eval( '?>' . $renderer );
	return (string) ob_get_clean();
};

$cases = array(
	'custom-property-var-color-survives' => array( '<div style="--link-color: rgb(var(--accent-rgb)); --t: var(--accent-rgb)"><p>x</p></div>', '--link-color: rgb(var(--accent-rgb))', true ),
	'rgba-var-color-survives'            => array( '<div style="color: rgba(var(--a), 0.5)">x</div>', 'rgba(var(--a), 0.5)', true ),
	'calc-color-is-dropped'              => array( '<div style="color: rgb(calc(1 + 2), 0, 0)">x</div>', 'style=', false ),
	'url-bearing-color-is-dropped'       => array( '<div style="color: rgb(var(--a)) url(x)">x</div>', 'style=', false ),
	'literal-rgb-is-lowered-to-hex'      => array( '<div style="color: rgb(255, 255, 255)">x</div>', '#ffffff', true ),
	'non-scaling-svg-stroke-survives'    => array( '<svg viewBox="0 0 1 1" preserveAspectRatio="none"><path d="M0,1 L1,1" vector-effect="non-scaling-stroke" stroke-dasharray="576" stroke-dashoffset="1152"></path></svg>', 'vector-effect="non-scaling-stroke" stroke-dasharray="576" stroke-dashoffset="1152"', true ),
);
foreach ( $cases as $label => [ $markup, $expected, $present ] ) {
	$output = $render( $markup );
	if ( str_contains( $output, $expected ) !== $present ) {
		fwrite( STDERR, 'FAIL [' . $label . ']: ' . $output . "\n" );
		exit( 1 );
	}
}
echo 'PASS smoke-companion-renderer-real-kses.php (' . count( $cases ) . " cases)\n";
