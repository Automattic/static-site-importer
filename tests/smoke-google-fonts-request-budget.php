<?php
/**
 * Google Fonts materialization must not hold an import request open while an
 * external provider is slow. Run: php tests/smoke-google-fonts-request-budget.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_font_budget_requests'] = array();

class WP_Error {
	public function __construct( private string $code, string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_remote_retrieve_response_code( $response ): int { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ): string { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
function apply_filters( string $hook, $value ) { return 'static_site_importer_font_materialization_request_budget' === $hook ? 0.001 : $value; }
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['ssi_font_budget_requests'][] = $url;
	usleep( 2000 );
	return array( 'response' => array( 'code' => 200 ), 'body' => "@font-face { font-family: 'Inter'; src: url(https://fonts.gstatic.com/s/inter/v1/inter.woff2) format('woff2'); }" );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';

$google_url = 'https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap';
$plan       = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Inter' ) ),
	'stylesheets' => array( array( 'path' => 'assets/css/fonts.css', 'content' => '@import url("' . $google_url . '");' ) ),
);
$started    = microtime( true );
$overlay    = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, array( 'writes' => array() ) );
$elapsed    = microtime( true ) - $started;

if ( is_wp_error( $overlay ) || $elapsed > 0.5 || array( $google_url ) !== $GLOBALS['ssi_font_budget_requests'] ) {
	throw new RuntimeException( 'Slow Google Fonts requests must stop at the shared budget before downloading font payloads.' );
}

$writes      = $overlay['writes'] ?? array();
$stylesheets = array_values( array_filter( $writes, static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === ( $write['target_path'] ?? '' ) ) );
$diagnostics = $overlay['diagnostics'] ?? array();
if ( 1 !== count( $stylesheets ) || ! str_contains( $stylesheets[0]['content'] ?? '', '@import "' . $google_url . '";' ) || ! in_array( 'font_materialization_request_budget_exhausted', array_column( array_column( $diagnostics, 'details' ), 'reason' ), true ) ) {
	throw new RuntimeException( 'Budget exhaustion must preserve the source Google stylesheet with diagnostic evidence.' );
}

echo "Google Fonts request budget smoke passed.\n";
