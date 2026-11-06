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
// Exercise the typed svg-artwork renderer with real core KSES, not a stub.
$svg_method = new ReflectionMethod( Static_Site_Importer_Companion_Plugin::class, 'typed_renderer' );
$svg_method->setAccessible( true );
$renderer   = $svg_method->invoke( null, 'blocks-engine/svg-artwork/v1' );
$filter     = '<filter id="tone" color-interpolation-filters="sRGB"><feColorMatrix in="SourceGraphic" type="matrix" values="1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 1 0" result="matrix"></feColorMatrix><feColorMatrix in="matrix" type="saturate" values="0" result="gray"></feColorMatrix><feComponentTransfer in="gray" result="tint"><feFuncR type="table" tableValues="0.1 0.9"></feFuncR><feFuncG type="linear" slope="0.8" intercept="0.1"></feFuncG><feFuncB type="gamma" amplitude="0.9" exponent="1.2" offset="0.1"></feFuncB></feComponentTransfer></filter>';
// HTML serializers may lowercase SVG names; values must remain untouched.
$lowercase_filter = preg_replace_callback( '/(?<=<|\/)[a-z]+|[a-z]+(?==)/i', static fn ( array $name ): string => strtolower( $name[0] ), $filter );
foreach ( array( $filter, $lowercase_filter ) as $filter_input ) {
	$attributes = array( 'svg' => '<svg viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><defs>' . $filter_input . '<feImage href="https://example.com/external.svg" /></defs><rect width="10" height="10" filter="url(#tone)" onclick="alert(3)"></rect></svg>' );
	$block      = null;
	ob_start();
	eval( '?>' . $renderer );
	$output = (string) ob_get_clean();
	if ( ! str_contains( $output, $filter ) || ! str_contains( $output, 'filter="url(#tone)"' ) || preg_match( '/onload|onclick|<script|alert\(|feimage|external\.svg/i', $output ) ) {
		fwrite( STDERR, 'FAIL [svg-artwork-passive-filter]: ' . $output . "\n" );
		exit( 1 );
	}
}
echo 'PASS smoke-companion-renderer-real-kses.php (' . ( count( $cases ) + 2 ) . " cases)\n";
