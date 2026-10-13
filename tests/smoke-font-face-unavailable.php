<?php
/**
 * An unavailable web font face is a finding (Automattic/static-site-importer#2054, item 5).
 *
 * The font materializer drops a face whose stylesheet, matching source or
 * font binary is unavailable (or keeps the source's remote Google @import) and
 * reports `font_face_unavailable`; the remaining faces materialize and the
 * import continues. A digest mismatch stays an integrity failure. The finding
 * reaches Receipt_Projection::compose() as an accepted, reported asset loss.
 *
 * Run from the repository root:
 * php tests/smoke-font-face-unavailable.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- Standalone smoke stubs WordPress.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub.
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function sanitize_key( $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ): string { return trim( (string) $text ); }
function wp_remote_retrieve_response_code( $response ): int { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ): string { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
/** Serve `$GLOBALS['ssi_font_responses']`; every other URL is unavailable. */
function wp_safe_remote_get( string $url, array $args ) {
	$body = $GLOBALS['ssi_font_responses'][ $url ] ?? null;
	return null === $body ? new WP_Error( 'http_request_failed' ) : array(
		'response' => array( 'code' => 200 ),
		'body'     => $body,
	);
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
foreach ( array( 'product-handoff-contract', 'import-report', 'diagnostic-loss-classes', 'source-findings', 'diagnostic-contract', 'artifact-diagnostics-adapter', 'report-diagnostics', 'asset-reporter', 'document-metadata-reporter', 'block-document-reporter', 'receipt-projection', 'font-materializer' ) as $class_file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $class_file . '.php';
}

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$google_url = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap';
$font_400   = 'https://fonts.gstatic.com/s/inter/v1/inter-400.woff2';
$font_700   = 'https://fonts.gstatic.com/s/inter/v1/inter-700.woff2';
$face_css   = static fn ( int $weight, string $url ): string => "@font-face { font-family: 'Inter'; font-style: normal; font-weight: {$weight}; src: url({$url}) format('woff2'); }";
$fixture    = "@import url('{$google_url}');\n:root{--font:'Inter',system-ui,sans-serif}body{font-family:var(--font)}";
$plan       = ( new Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder() )->fromWebFontSources(
	'<!doctype html><html><head><link rel="stylesheet" href="css/style.css"></head><body><main>Inter</main></body></html>',
	$fixture,
	array(
		array(
			'path'        => 'css/style.css',
			'content'     => $fixture,
			'source_hash' => hash( 'sha256', $fixture ),
		),
	)
);
$resolved   = array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) );
$findings   = static fn ( array $overlay ): array => array_values( array_filter( $overlay['diagnostics'] ?? array(), static fn ( array $row ): bool => 'font_face_unavailable' === ( $row['code'] ?? '' ) ) );
$write      = static function ( array $overlay, string $target ): ?array {
	foreach ( $overlay['writes'] ?? array() as $candidate ) {
		if ( $target === $candidate['target_path'] ) {
			return $candidate;
		}
	}
	return null;
};
$weights    = static fn ( array $faces ): array => array_map( static fn ( array $face ): int => (int) $face['weight']['value'], $faces );
$assert( array( 700, 400 ) === $weights( $plan['webfont_contract']['faces'] ?? array() ), 'fixture: the producer declares the 700 and 400 Inter faces' );

// Producer: one font binary unavailable. That face is dropped; the other materializes.
$GLOBALS['ssi_font_responses'] = array(
	$google_url => $face_css( 400, $font_400 ) . "\n" . $face_css( 700, $font_700 ),
	$font_400   => 'inter-400-bytes',
);
$partial = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, $resolved );
$assert( ! is_wp_error( $partial ), 'producer-partial: an unavailable font binary no longer fails the import' );
$dropped = $findings( $partial );
$assert( 1 === count( $dropped ) && 'producer_font_fetch_failed' === ( $dropped[0]['reason_code'] ?? '' ) && 'dropped' === ( $dropped[0]['resolution'] ?? '' ) && $font_700 === ( $dropped[0]['url'] ?? '' ), 'producer-partial: one font_face_unavailable finding names the unavailable binary' );
$assert( array( 400 ) === $weights( $partial['required_faces'] ?? array() ), 'producer-partial: only the available face is required by the readiness probe' );
$embedded = $write( $partial, 'assets/css/embedded-fonts.css' );
$assert( null !== $embedded && str_contains( $embedded['content'], 'font-weight: 400' ) && ! str_contains( $embedded['content'], 'font-weight: 700' ) && ! str_contains( $embedded['content'], 'fonts.gstatic.com' ), 'producer-partial: embedded-fonts.css carries only the localized face' );

// Producer: the stylesheet is unavailable. Every face is dropped; the theme keeps its fallback fonts.
$GLOBALS['ssi_font_responses'] = array();
$unavailable                   = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, $resolved );
$assert( ! is_wp_error( $unavailable ), 'producer-stylesheet: an unavailable stylesheet no longer fails the import' );
$assert( array( 'producer_stylesheet_fetch_failed', 'producer_stylesheet_fetch_failed' ) === array_column( $findings( $unavailable ), 'reason_code' ), 'producer-stylesheet: each face reports font_face_unavailable once' );
$assert( null === $write( $unavailable, 'assets/css/embedded-fonts.css' ) && ! str_contains( (string) ( $write( $unavailable, 'functions.php' )['content'] ?? '' ), 'embedded-fonts.css' ), 'producer-stylesheet: no empty embedded stylesheet is written or enqueued' );

// Producer: the stylesheet carries no source for one face, and an untrusted URL for the other.
$GLOBALS['ssi_font_responses'] = array( $google_url => $face_css( 700, 'http://fonts.example.test/inter-700.woff2' ) );
$sourceless                    = Static_Site_Importer_Font_Materializer::prepare_overlay( $plan, $resolved );
$assert( ! is_wp_error( $sourceless ), 'producer-source: missing and untrusted face sources no longer fail the import' );
$reasons = array_column( $findings( $sourceless ), 'reason_code' );
sort( $reasons );
$assert( array( 'producer_face_source_missing', 'producer_font_url_invalid' ) === $reasons, 'producer-source: the missing source and the invalid URL are each reported' );

// Digest mismatches stay integrity failures.
$GLOBALS['ssi_font_responses'] = array(
	$google_url => $face_css( 400, $font_400 ) . "\n" . $face_css( 700, $font_700 ),
	$font_400   => 'inter-400-bytes',
	$font_700   => 'inter-700-bytes',
);
$digest_plan = $plan;
$digest_plan['webfont_contract']['imports'][0]['source']['expected_digest'] = 'sha256:' . str_repeat( '0', 64 );
$digest      = Static_Site_Importer_Font_Materializer::prepare_overlay( $digest_plan, $resolved );
$assert( is_wp_error( $digest ) && 'static_site_importer_font_materialization_producer_stylesheet_digest_mismatch' === $digest->get_error_code(), 'integrity: a stylesheet digest mismatch fails closed' );
$face_digest_plan = $plan;
$face_digest_plan['webfont_contract']['faces'][0]['expected_sha256'] = str_repeat( 'a', 64 );
$face_digest = Static_Site_Importer_Font_Materializer::prepare_overlay( $face_digest_plan, $resolved );
$assert( is_wp_error( $face_digest ) && 'static_site_importer_font_materialization_producer_font_digest_mismatch' === $face_digest->get_error_code(), 'integrity: a font binary digest mismatch fails closed' );

// Legacy Google path: an unavailable stylesheet keeps the remote @import.
$legacy = array(
	'schema'      => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'provider'    => 'google_fonts',
	'fonts'       => array( array( 'family' => 'Inter' ) ),
	'stylesheets' => array(
		array(
			'path'    => 'assets/css/fonts.css',
			'content' => "@import url('{$google_url}');\n",
		),
	),
);
$GLOBALS['ssi_font_responses'] = array();
$legacy_overlay                = Static_Site_Importer_Font_Materializer::prepare_overlay( $legacy, $resolved );
$assert( ! is_wp_error( $legacy_overlay ), 'legacy: an unavailable Google stylesheet no longer fails the import' );
$legacy_findings = $findings( $legacy_overlay );
$assert( 1 === count( $legacy_findings ) && 'remote_import_retained' === ( $legacy_findings[0]['resolution'] ?? '' ) && 'stylesheet_fetch_failed' === ( $legacy_findings[0]['reason_code'] ?? '' ), 'legacy: one font_face_unavailable finding records the retained remote import' );
$assert( str_contains( (string) ( $write( $legacy_overlay, 'assets/css/embedded-fonts.css' )['content'] ?? '' ), '@import "' . $google_url . '"' ), 'legacy: the remote Google @import is kept' );

// The findings reach Receipt_Projection::compose() as accepted, reported asset losses.
$receipt = array(
	'plan'             => array(
		'schema'         => 'blocks-engine/wordpress-site-plan/v2',
		'diagnostics'    => array(),
		'quality'        => array(),
		'source'         => array(
			'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
			'source_hash' => 'font-face-unavailable',
			'entry_path'  => 'website/index.html',
			'provenance'  => array(),
		),
		'template_parts' => array(),
		'pages'          => array(),
		'writes'         => array(),
		'assets'         => array(),
	),
	'theme'            => array(
		'slug' => 'font-face-unavailable',
		'dir'  => sys_get_temp_dir() . '/font-face-unavailable',
	),
	'completed'        => array(
		'files'                => array(),
		'font_materialization' => array(
			'files'       => array(),
			'diagnostics' => array_merge( $partial['diagnostics'], $legacy_overlay['diagnostics'] ),
		),
	),
	'existing_matches' => array( 'pages' => array() ),
	'extensions'       => array(),
);
$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), array(), array(), array(), 'font-face-unavailable-run', array(), array() );
$report     = $projection['report'];
$quality    = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$stored     = json_decode( (string) json_encode( $report->to_array() ), true );
$rows       = array_values( array_filter( $stored['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && 'font_face_unavailable' === ( $row['code'] ?? '' ) ) );
$assert( 2 === count( $rows ), 'compose: both font_face_unavailable findings reach the stored report' );
foreach ( $rows as $row ) {
	$assert(
		'asset' === ( $row['unit'] ?? '' ) && 'static-site-importer' === ( $row['owning_layer'] ?? '' ) && 'omitted_unit' === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && '' !== ( $row['location'] ?? '' ),
		'compose: ' . ( $row['reason_code'] ?? '' ) . ' is an accepted, reported asset loss at its URL'
	);
}
$assert( array() === array_filter( $stored['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && 'font_materialization_failed' === ( $row['type'] ?? '' ) ), 'compose: internal font evidence rows stay in the receipt, not the findings' );
$assert( false === $quality['fail_import'], 'compose: an unavailable font face does not fail the import' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-font-face-unavailable: ok\n";
