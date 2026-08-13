<?php
/**
 * Deterministic Google Fonts retry coverage for #661.
 *
 * Transient stylesheet and font-payload failures are retried through the
 * bounded request policy and must resolve to self-contained fonts. Exhausted
 * retries fail closed with the specific diagnostic instead of preserving a
 * remote @import fallback that would let visual parity score fallback fonts.
 *
 * Run: php tests/smoke-google-fonts-retry-determinism.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_retry_attempts']  = array();
$GLOBALS['ssi_retry_responder'] = null;

class WP_Error {
	private string $code;
	private mixed $data;
	public function __construct( string $code, string $message = '', mixed $data = null ) { $this->code = $code; $this->data = $data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_remote_retrieve_response_code( $response ): int { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ): string { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
function wp_safe_remote_get( string $url, array $args ) {
	$attempt                             = ( $GLOBALS['ssi_retry_attempts'][ $url ] ?? 0 ) + 1;
	$GLOBALS['ssi_retry_attempts'][ $url ] = $attempt;
	$responder                           = $GLOBALS['ssi_retry_responder'];
	return is_callable( $responder ) ? $responder( $url, $attempt, $args ) : new WP_Error( 'unexpected_request' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$google_url    = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap';
$woff2_url     = 'https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2';
$css_body      = "@font-face { font-family: 'Inter'; font-style: normal; font-weight: 400; src: url({$woff2_url}) format('woff2'); }\n@font-face { font-family: 'Inter'; font-style: normal; font-weight: 700; src: url({$woff2_url}) format('woff2'); }";
$functions_php = array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) );
$plan          = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Inter' ) ),
	'stylesheets' => array(
		array(
			'path'    => 'assets/css/fonts.css',
			'content' => "@import url('" . $google_url . "');\n",
		),
	),
);

// Case 1: transient stylesheet failure (two failed attempts) followed by success embeds self-contained fonts.
$GLOBALS['ssi_retry_attempts']  = array();
$GLOBALS['ssi_retry_responder'] = static function ( string $url, int $attempt ) use ( $google_url, $woff2_url, $css_body ) {
	if ( $url === $google_url ) {
		return $attempt < 3 ? new WP_Error( 'http_request_failed' ) : array( 'response' => array( 'code' => 200 ), 'body' => $css_body );
	}
	if ( $url === $woff2_url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'inter-font-payload' );
	}
	return new WP_Error( 'unexpected_request' );
};
$overlay_stylesheet = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, array( 'writes' => array( $functions_php ) ) );
$assert( ! is_wp_error( $overlay_stylesheet ), 'case 1: transient stylesheet failure recovers into embedded fonts' );
$assert( 3 === ( $GLOBALS['ssi_retry_attempts'][ $google_url ] ?? 0 ), 'case 1: stylesheet request retries through the bounded policy before succeeding' );
$css_writes = array_values( array_filter( $overlay_stylesheet['writes'] ?? array(), static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) );
$assert( 1 === count( $css_writes ), 'case 1: exactly one embedded-fonts.css write' );
$assert( str_contains( $css_writes[0]['content'], 'data:font/woff2;base64,' ) && ! str_contains( $css_writes[0]['content'], '@import' ), 'case 1: embedded stylesheet is self-contained' );

// Case 2: transient font-payload failure (two failed attempts) followed by success embeds self-contained fonts.
$GLOBALS['ssi_retry_attempts']  = array();
$GLOBALS['ssi_retry_responder'] = static function ( string $url, int $attempt ) use ( $google_url, $woff2_url, $css_body ) {
	if ( $url === $google_url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $css_body );
	}
	if ( $url === $woff2_url ) {
		return $attempt < 3 ? new WP_Error( 'http_request_failed' ) : array( 'response' => array( 'code' => 200 ), 'body' => 'inter-font-payload' );
	}
	return new WP_Error( 'unexpected_request' );
};
$overlay_payload = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, array( 'writes' => array( $functions_php ) ) );
$assert( ! is_wp_error( $overlay_payload ), 'case 2: transient font-payload failure recovers into embedded fonts' );
$assert( 3 === ( $GLOBALS['ssi_retry_attempts'][ $woff2_url ] ?? 0 ), 'case 2: font payload request retries through the bounded policy before succeeding' );
$css_writes_payload = array_values( array_filter( $overlay_payload['writes'] ?? array(), static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) );
$assert( 1 === count( $css_writes_payload ) && str_contains( $css_writes_payload[0]['content'], 'data:font/woff2;base64,' ), 'case 2: embedded stylesheet is self-contained after payload recovery' );

// Case 3: exhausted stylesheet retries fail closed with the specific diagnostic retained.
$GLOBALS['ssi_retry_attempts']  = array();
$GLOBALS['ssi_retry_responder'] = static function ( string $url ) use ( $google_url ) {
	return $url === $google_url ? new WP_Error( 'http_request_failed' ) : new WP_Error( 'unexpected_request' );
};
$overlay_exhausted_stylesheet = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, array( 'writes' => array( $functions_php ) ) );
$assert( is_wp_error( $overlay_exhausted_stylesheet ) && 'static_site_importer_font_materialization_failed' === $overlay_exhausted_stylesheet->get_error_code(), 'case 3: exhausted stylesheet retries produce an evidence failure' );
$assert( 3 === ( $GLOBALS['ssi_retry_attempts'][ $google_url ] ?? 0 ), 'case 3: stylesheet request exhausts the bounded retry budget' );
$stylesheet_diagnostics = $overlay_exhausted_stylesheet->get_error_data();
$assert( is_array( $stylesheet_diagnostics ) && count( array_filter( $stylesheet_diagnostics, static fn( array $row ): bool => 'stylesheet_fetch_failed' === ( $row['reason'] ?? '' ) ) ) >= 1, 'case 3: retains the stylesheet_fetch_failed diagnostic' );

// Case 4: exhausted font-payload retries fail closed with the specific diagnostic retained.
$GLOBALS['ssi_retry_attempts']  = array();
$GLOBALS['ssi_retry_responder'] = static function ( string $url ) use ( $google_url, $woff2_url, $css_body ) {
	if ( $url === $google_url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $css_body );
	}
	return $url === $woff2_url ? new WP_Error( 'http_request_failed' ) : new WP_Error( 'unexpected_request' );
};
$overlay_exhausted_payload = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, array( 'writes' => array( $functions_php ) ) );
$assert( is_wp_error( $overlay_exhausted_payload ) && 'static_site_importer_font_materialization_failed' === $overlay_exhausted_payload->get_error_code(), 'case 4: exhausted font-payload retries produce an evidence failure' );
$assert( 3 === ( $GLOBALS['ssi_retry_attempts'][ $woff2_url ] ?? 0 ), 'case 4: font-payload request exhausts the bounded retry budget' );
$payload_diagnostics = $overlay_exhausted_payload->get_error_data();
$assert( is_array( $payload_diagnostics ) && count( array_filter( $payload_diagnostics, static fn( array $row ): bool => 'font_payload_fetch_failed' === ( $row['reason'] ?? '' ) ) ) >= 1, 'case 4: retains the font_payload_fetch_failed diagnostic' );

echo "Google Fonts retry determinism smoke passed.\n";
