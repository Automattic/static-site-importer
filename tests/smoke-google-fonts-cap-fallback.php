<?php
/**
 * Cap-fallback coverage for the Google Fonts path in #732.
 *
 * Run: php tests/smoke-google-fonts-cap-fallback.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_cap_requests']   = array();
$GLOBALS['ssi_cap_response']  = null;
$GLOBALS['ssi_cap_payloads']  = array();

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
	$GLOBALS['ssi_cap_requests'][] = $url;
	$response = $GLOBALS['ssi_cap_response'] ?? null;
	if ( null === $response ) {
		if ( isset( $GLOBALS['ssi_cap_payloads'][ $url ] ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => $GLOBALS['ssi_cap_payloads'][ $url ] );
		}
		return new WP_Error( 'unexpected_request' );
	}
	if ( is_callable( $response ) ) {
		return $response( $url );
	}
	return $response;
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$google_url        = 'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700&family=Noto+Naskh+Arabic:wght@400;700&display=swap';
$google_url_legacy = 'https://fonts.googleapis.com/css?family=Inter:wght@400;700';
$functions_php     = array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) );

// Case 1: Google CSS body > 256 KiB -> preserved stylesheet with @import.
$GLOBALS['ssi_cap_response'] = function ( string $url ) use ( $google_url ): array {
	if ( $url === $google_url ) {
		$body = "@font-face { font-family: 'Noto Sans JP'; src: url(https://fonts.gstatic.com/s/noto/jp.woff2) format('woff2'); }\n";
		$body = str_repeat( $body, 8000 );
		return array( 'response' => array( 'code' => 200 ), 'body' => $body );
	}
	return new WP_Error( 'unexpected_request' );
};
$plan_too_large = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Noto Sans JP' ), array( 'family' => 'Noto Naskh Arabic' ) ),
	'stylesheets' => array(
		array(
			'path'    => 'assets/css/fonts.css',
			'content' => "@import url('" . $google_url . "');\n",
		),
	),
);
$overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan_too_large, array( 'writes' => array( $functions_php ) ) );
$assert( ! is_wp_error( $overlay ), 'case 1: oversized Google CSS preserves instead of aborting' );
$css_writes = array_values( array_filter( $overlay['writes'] ?? array(), static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) );
$font_writes = array_values( array_filter( $overlay['writes'] ?? array(), static fn( array $write ): bool => str_starts_with( $write['target_path'], 'assets/fonts/' ) ) );
$assert( 1 === count( $css_writes ), 'case 1: exactly one embedded-fonts.css write' );
$assert( 0 === count( $font_writes ), 'case 1: no embedded font assets are downloaded when the CSS body itself is oversized' );
$assert( str_contains( $css_writes[0]['content'], '@import "' . $google_url . '";' ), 'case 1: preserved stylesheet body re-imports the original Google URL' );
$preserved = array_values( array_filter( $overlay['diagnostics'] ?? array(), static fn( array $row ): bool => ( $row['reason'] ?? '' ) === 'font_materialization_partial_preserved' ) );
$assert( 1 === count( $preserved ), 'case 1: emits a font_materialization_partial_preserved diagnostic' );
$assert( 'google_fonts_stylesheet_preserved_due_to_size' === ( $preserved[0]['details']['reason'] ?? '' ), 'case 1: inner reason is google_fonts_stylesheet_preserved_due_to_size' );
$assert( ( $preserved[0]['details']['observed_bytes'] ?? 0 ) > 262144, 'case 1: observed_bytes exceeds CSS_LIMIT' );
$assert( 262144 === ( $preserved[0]['details']['limit_bytes'] ?? 0 ), 'case 1: limit_bytes equals CSS_LIMIT' );
$assert( $google_url === ( $preserved[0]['details']['url'] ?? '' ), 'case 1: url echoes the offending import' );

// Case 2: aggregate woff2 > 4 MiB -> preserved stylesheet with @import.
$GLOBALS['ssi_cap_response']   = null;
$GLOBALS['ssi_cap_payloads']   = array();
$woff2_url_a   = 'https://fonts.gstatic.com/s/noto/jp-regular.woff2';
$woff2_url_b   = 'https://fonts.gstatic.com/s/noto/arabic-regular.woff2';
$woff2_url_c   = 'https://fonts.gstatic.com/s/noto/arabic-700.woff2';
$css_body_small = "@font-face { font-family: 'Noto Sans JP'; src: url(" . $woff2_url_a . ") format('woff2'); }\n@font-face { font-family: 'Noto Naskh Arabic'; src: url(" . $woff2_url_b . ") format('woff2'); font-weight: 400; }\n@font-face { font-family: 'Noto Naskh Arabic'; src: url(" . $woff2_url_c . ") format('woff2'); font-weight: 700; }\n";
$one_five_mib   = intdiv( 3 * 1024 * 1024, 2 );
$GLOBALS['ssi_cap_response'] = function ( string $url ) use ( $google_url, $css_body_small, $woff2_url_a, $woff2_url_b, $woff2_url_c, $one_five_mib ): array {
	if ( $url === $google_url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $css_body_small );
	}
	if ( $url === $woff2_url_a ) {
		// 1.5 MiB (under FONT_LIMIT 2 MiB).
		return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'a', $one_five_mib ) );
	}
	if ( $url === $woff2_url_b ) {
		// 1.5 MiB; aggregate now 3 MiB, still under 4 MiB.
		return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'b', $one_five_mib ) );
	}
	if ( $url === $woff2_url_c ) {
		// 1.5 MiB; would push aggregate to 4.5 MiB, exceeds TOTAL_FONT_LIMIT 4 MiB.
		return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'c', $one_five_mib ) );
	}
	return new WP_Error( 'unexpected_request' );
};
$plan_too_big = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Noto Sans JP' ), array( 'family' => 'Noto Naskh Arabic' ) ),
	'stylesheets' => array(
		array(
			'path'    => 'assets/css/fonts.css',
			'content' => "@import url('" . $google_url . "');\n",
		),
	),
);
$overlay_big = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan_too_big, array( 'writes' => array( $functions_php ) ) );
$assert( ! is_wp_error( $overlay_big ), 'case 2: aggregate overflow preserves instead of aborting' );
$css_writes_big = array_values( array_filter( $overlay_big['writes'] ?? array(), static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) );
$assert( 1 === count( $css_writes_big ), 'case 2: exactly one embedded-fonts.css write' );
$assert( str_contains( $css_writes_big[0]['content'], '@import "' . $google_url . '";' ), 'case 2: preserved stylesheet body re-imports the original Google URL' );
$preserved_big = array_values( array_filter( $overlay_big['diagnostics'] ?? array(), static fn( array $row ): bool => ( $row['reason'] ?? '' ) === 'font_materialization_partial_preserved' ) );
$assert( 1 === count( $preserved_big ), 'case 2: emits a font_materialization_partial_preserved diagnostic' );
$assert( 'google_fonts_payloads_partial_preserved' === ( $preserved_big[0]['details']['reason'] ?? '' ), 'case 2: inner reason is google_fonts_payloads_partial_preserved' );
$assert( 4194304 === ( $preserved_big[0]['details']['limit_bytes'] ?? 0 ), 'case 2: limit_bytes equals TOTAL_FONT_LIMIT' );
$assert( isset( $preserved_big[0]['details']['aggregate_bytes'] ), 'case 2: aggregate_bytes is reported' );

// Case 3: producer path not regressed. Use the existing smoke's stubbed google_fonts response to keep this file standalone.
$GLOBALS['ssi_cap_response']  = function ( string $url ): array {
	if ( str_starts_with( $url, 'https://fonts.googleapis.com/css2?family=Inter:' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "@font-face{font-family:'Inter';font-style:normal;font-weight:100 900;font-stretch:75% 125%;src:url(https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2) format('woff2');unicode-range:U+0000-00FF}" );
	}
	if ( 'https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'fixture-37-inter-variable-font' );
	}
	return new WP_Error( 'unexpected_request' );
};
$fixture_html  = '<!doctype html><html><head><link rel="stylesheet" href="css/style.css"></head><body><main>Inter fixture</main></body></html>';
$fixture_css   = "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap');\n:root{--font:'Inter',system-ui,sans-serif}body{font-family:var(--font)}";
$producer_plan = ( new Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder() )->fromWebFontSources(
	$fixture_html,
	$fixture_css,
	array( array( 'path' => 'css/style.css', 'content' => $fixture_css, 'source_hash' => hash( 'sha256', $fixture_css ) ) )
);
$producer_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay(
	$producer_plan,
	array( 'writes' => array( $functions_php ) )
);
$assert( ! is_wp_error( $producer_overlay ), 'case 3: producer path still materializes (regression sentinel)' );
$producer_diagnostic_failure = Static_Site_Importer_Font_Materializer::prepare_overlay(
	array_merge( $producer_plan, array( 'webfont_contract' => array_merge( $producer_plan['webfont_contract'], array( 'diagnostics' => array( array( 'code' => 'webfont_import_unresolved' ) ) ) ) ) ),
	array( 'writes' => array( $functions_php ) )
);
$assert( is_wp_error( $producer_diagnostic_failure ) && 'static_site_importer_font_materialization_producer_diagnostic' === $producer_diagnostic_failure->get_error_code(), 'case 3: required producer diagnostics still gate materialization' );

// Case 4: empty plan + empty stylesheets is preserved as preserved (no import URL to import) but a WP_Error is surfaced because no @import can be written.
$GLOBALS['ssi_cap_response'] = function (): array { return new WP_Error( 'unexpected_request' ); };
$plan_no_imports = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Inter' ) ),
	'stylesheets' => array( array( 'path' => 'assets/css/fonts.css', 'content' => ':root { color: red; }' ) ),
);
$overlay_no_imports = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan_no_imports, array( 'writes' => array( $functions_php ) ) );
$assert( is_wp_error( $overlay_no_imports ) && 'static_site_importer_font_materialization_failed' === $overlay_no_imports->get_error_code(), 'case 4: absent Google @import with no fallback URL still reports materialization failure' );
$no_imports_diagnostics = $overlay_no_imports->get_error_data();
$assert( is_array( $no_imports_diagnostics ) && count( array_filter( $no_imports_diagnostics, static fn( array $row ): bool => ( $row['reason'] ?? '' ) === 'stylesheet_import_missing' ) ) >= 1, 'case 4: surfaces the stylesheet_import_missing reason before failing closed' );

// Case 5: legacy v1 css path (fontfamily=...) -- same preservation contract.
$GLOBALS['ssi_cap_response'] = function ( string $url ) use ( $google_url_legacy ): array {
	if ( $url === $google_url_legacy ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( '@font-face { font-family: "Inter"; src: url(https://fonts.gstatic.com/legacy.woff2); }' . "\n", 7000 ) );
	}
	return new WP_Error( 'unexpected_request' );
};
$plan_legacy = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Inter' ) ),
	'stylesheets' => array(
		array(
			'path'    => 'assets/css/fonts.css',
			'content' => "@import url('" . $google_url_legacy . "');\n",
		),
	),
);
$overlay_legacy = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan_legacy, array( 'writes' => array( $functions_php ) ) );
$assert( ! is_wp_error( $overlay_legacy ), 'case 5: legacy /css path also preserves when the response is oversized' );
$css_writes_legacy = array_values( array_filter( $overlay_legacy['writes'] ?? array(), static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) );
$assert( 1 === count( $css_writes_legacy ) && str_contains( $css_writes_legacy[0]['content'], '@import "' . $google_url_legacy . '";' ), 'case 5: legacy /css import URL is round-tripped in the preserved stylesheet' );

echo "Google Fonts cap-fallback smoke passed.\n";
