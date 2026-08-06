<?php
/**
 * Cross-repository webfont handoff coverage for Blocks Engine #708 / SSI #742.
 *
 * Run: php tests/smoke-webfont-producer-consumer.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

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
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['ssi_webfont_requests'][] = $url;
	if ( str_starts_with( $url, 'https://fonts.googleapis.com/css2?family=Inter:' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "@font-face{font-family:'Inter';font-style:normal;font-weight:100 900;font-stretch:75% 125%;src:url(https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2) format('woff2');unicode-range:U+0000-00FF}@font-face{font-family:'Inter';font-style:normal;font-weight:100 900;font-stretch:75% 125%;src:url(https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2) format('woff2');unicode-range:U+0100-024F}" );
	}
	if ( 'https://fonts.gstatic.com/s/inter/v1/inter-latin.woff2' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'fixture-37-inter-variable-font' );
	}
	return new WP_Error( 'unexpected_request' );
}
function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$fixture_html = '<!doctype html><html><head><link rel="stylesheet" href="css/style.css"></head><body><main>Inter fixture</main></body></html>';
$fixture_css  = "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap');\n:root{--font:'Inter',system-ui,sans-serif}body{font-family:var(--font)}";
$producer_plan = ( new Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder() )->fromWebFontSources(
	$fixture_html,
	$fixture_css,
	array( array( 'path' => 'css/style.css', 'content' => $fixture_css, 'source_hash' => hash( 'sha256', $fixture_css ) ) )
);
$contract = $producer_plan['webfont_contract'] ?? array();
$assert( 'blocks-engine/webfont-materialization/v1' === ( $contract['schema'] ?? '' ) && 1 === count( $contract['imports'] ?? array() ) && 9 === count( $contract['faces'] ?? array() ), 'fixture 37 sibling compiler emits the nested shared import and typed Inter face records' );
$assert( count( $contract['faces'] ?? array() ) === count( $contract['receipts'] ?? array() ) && 'required' === ( $contract['browser_readiness']['state'] ?? '' ), 'fixture 37 producer preserves nested browser readiness receipt IDs' );

$overlay = Static_Site_Importer_Font_Materializer::prepare_overlay(
	$producer_plan,
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) )
);
$assert( ! is_wp_error( $overlay ), 'fixture 37 producer contract materializes in SSI' );
$assets = array_values( array_filter( $overlay['writes'], static fn( array $write ): bool => str_starts_with( $write['target_path'], 'assets/fonts/' ) ) );
$css = (string) ( array_values( array_filter( $overlay['writes'], static fn( array $write ): bool => 'assets/css/embedded-fonts.css' === $write['target_path'] ) )[0]['content'] ?? '' );
$readiness = (string) ( array_values( array_filter( $overlay['writes'], static fn( array $write ): bool => 'assets/js/font-readiness.js' === $write['target_path'] ) )[0]['content'] ?? '' );
$assert( 1 === count( $assets ) && 1 === count( array_filter( $GLOBALS['ssi_webfont_requests'], static fn( string $url ): bool => str_contains( $url, 'fonts.gstatic.com' ) ) ), 'all fixture 37 Inter records deduplicate to one local font asset and one payload request' );
$assert( str_contains( $css, 'font-weight:100 900' ) && str_contains( $css, 'font-stretch:75% 125%' ) && str_contains( $css, 'unicode-range:U+0000-00FF' ) && str_contains( $css, 'unicode-range:U+0100-024F' ), 'local @font-face output preserves variable axes and unicode ranges' );
$assert( array_column( $contract['faces'], 'id' ) === array_column( $overlay['faces'], 'face_id' ) && array_column( $contract['receipts'], 'id' ) === array_column( $overlay['required_faces'], 'receipt_id' ), 'materialization receipt retains every producer face and receipt identity' );
$assert( str_contains( $readiness, 'static-site-importer-font-readiness' ) && str_contains( $readiness, 'receipt_id' ) && str_contains( $readiness, 'status:"missing"' ), 'browser readiness serializes loaded or missing records with producer receipt IDs into the captured DOM' );
$assert( hash( 'sha256', 'fixture-37-inter-variable-font' ) === ( $overlay['required_faces'][0]['assets'][0]['observed_sha256'] ?? '' ), 'materialization receipt retains the observed payload digest for each producer face' );

$mixed_plan = $producer_plan;
$mixed_plan['webfont_contract']['imports'][] = array(
	'id'          => 'webfont-import-unsupported-fixture',
	'provider'    => 'unsupported',
	'state'       => 'unsupported',
	'source'      => array( 'url' => 'https://fonts.example.test/site.css', 'format' => 'css', 'expected_digest' => null, 'observed_digest' => null ),
	'provenance'  => array(),
	'diagnostics' => array(),
);
$mixed_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $mixed_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $mixed_overlay ) && array_column( $contract['faces'], 'id' ) === array_column( $mixed_overlay['faces'], 'face_id' ), 'unsupported imports coexist with independently declared materializable faces' );

$protocol_relative_plan = $producer_plan;
$protocol_relative_plan['webfont_contract']['imports'][0]['source']['url'] = preg_replace( '#^https:#', '', $protocol_relative_plan['webfont_contract']['imports'][0]['source']['url'] );
$protocol_relative_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $protocol_relative_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $protocol_relative_overlay ), 'protocol-relative Google font imports normalize to the portable HTTPS source' );
$normalize_google_url = new ReflectionMethod( Static_Site_Importer_Font_Materializer::class, 'normalize_google_stylesheet_url' );
$assert( 'https://fonts.googleapis.com/css?family=Fjalla+One' === $normalize_google_url->invoke( null, '//fonts.googleapis.com/css?family=Fjalla+One?1473722252' ), 'legacy cache busters embedded in Google family values are removed' );
$legacy_family_plan = $producer_plan;
$legacy_family_plan['webfont_contract']['faces'][0]['family'] .= '?1473722252';
$legacy_family_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $legacy_family_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $legacy_family_overlay ) && 'Inter' === ( $legacy_family_overlay['faces'][0]['family'] ?? '' ), 'legacy cache busters are removed from typed face families before stylesheet matching' );

$svg = '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="Inter">Fixture 37</text></svg>';
$svg_source_path = 'assets/materialized-svg/fixture-37.svg';
$svg_write_path = 'assets/materialized-svg/fixture-37.svg';
$svg_resolved_target = 'assets/assets/materialized-svg/fixture-37.svg';
$svg_hash = hash( 'sha256', $svg );
$consumer_plan = $producer_plan;
$consumer_plan['webfont_contract']['faces'][0]['receipt_id'] = 'webfont-receipt-z';
$consumer_plan['webfont_contract']['faces'][1]['receipt_id'] = 'webfont-receipt-a';
$consumer_plan['webfont_contract']['receipts'][0]['id'] = 'webfont-receipt-z';
$consumer_plan['webfont_contract']['receipts'][1]['id'] = 'webfont-receipt-a';
$consumer_plan['webfont_contract']['browser_readiness']['required_receipt_ids'] = array_column( $consumer_plan['webfont_contract']['receipts'], 'id' );
$svg_face_ids = array( $contract['faces'][0]['id'], $contract['faces'][1]['id'] );
$svg_receipt_ids = array( 'webfont-receipt-z', 'webfont-receipt-a' );
$svg_consumer_id = 'svg-webfont-consumer-' . substr( hash( 'sha256', $svg_source_path . "\n" . $svg_write_path . "\n" . $svg_hash . "\n" . implode( "\n", $svg_face_ids ) ), 0, 20 );
$consumer_plan['webfont_contract']['svg_consumers'] = array(
	array( 'id' => $svg_consumer_id, 'source_path' => $svg_source_path, 'write_path' => $svg_write_path, 'pre_transform_payload_hash' => $svg_hash, 'face_ids' => $svg_face_ids, 'receipt_ids' => $svg_receipt_ids, 'required' => true ),
);
$consumer_resolved = array( 'assets' => array( array( 'source_path' => $svg_source_path, 'target_path' => $svg_resolved_target, 'content_hash' => $svg_hash ) ), 'writes' => array(
	array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ),
	array( 'source_path' => $svg_source_path, 'target_path' => $svg_resolved_target, 'reconciliation_identity' => hash( 'sha256', "wordpress-site-plan/write/v2\n{$svg_source_path}\n{$svg_resolved_target}" ), 'payload' => array( 'encoding' => 'utf8', 'data' => $svg ) ),
	array( 'target_path' => 'assets/plain.svg', 'reconciliation_identity' => hash( 'sha256', 'plain' ), 'payload' => array( 'encoding' => 'utf8', 'data' => '<svg><path d="M0 0"/></svg>' ) ),
) );
$consumer_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $consumer_plan, $consumer_resolved );
$assert( ! is_wp_error( $consumer_overlay ), 'validated SVG consumer materializes: ' . ( is_wp_error( $consumer_overlay ) ? $consumer_overlay->get_error_code() : '' ) );
$consumer_svg_writes = array_values( array_filter( $consumer_overlay['writes'] ?? array(), static fn( array $write ): bool => $svg_resolved_target === $write['target_path'] ) );
$assert( ! is_wp_error( $consumer_overlay ) && 1 === count( $consumer_svg_writes ) && str_contains( $consumer_svg_writes[0]['content'], 'data:font/woff2;base64,') && ! str_contains( $consumer_svg_writes[0]['content'], 'fonts.gstatic.com' ) && 1 === count( $consumer_overlay['svg_receipts'] ?? array() ) && $svg_receipt_ids === $consumer_overlay['svg_receipts'][0]['receipt_ids'], 'a prefixed resolved target receives its two index-linked faces and deterministic receipt without sorting receipt IDs' );
$assert( $svg_hash === ( $consumer_overlay['svg_receipts'][0]['input_sha256'] ?? '' ) && hash( 'sha256', $consumer_svg_writes[0]['content'] ) === ( $consumer_overlay['svg_receipts'][0]['output_sha256'] ?? '' ) && hash( 'sha256', 'fixture-37-inter-variable-font' ) === ( $consumer_overlay['svg_receipts'][0]['observed_font_sha256'][0] ?? '' ), 'SVG receipt binds canonical input, final output, resolved write identity, and observed font digest' );
$assert( ! array_filter( $consumer_overlay['writes'], static fn( array $write ): bool => 'assets/plain.svg' === $write['target_path'] ), 'non-consumer SVG bytes are never overlaid' );
$tampered_consumer = $consumer_plan;
$tampered_consumer['webfont_contract']['svg_consumers'][0]['pre_transform_payload_hash'] = str_repeat( '0', 64 );
$tampered_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $tampered_consumer, $consumer_resolved );
$assert( is_wp_error( $tampered_overlay ) && 'static_site_importer_font_materialization_svg_consumer_invalid' === $tampered_overlay->get_error_code(), 'tampered SVG input hashes fail before font fetches' );
$cross_write_consumer = $consumer_plan;
$cross_write_consumer['webfont_contract']['svg_consumers'][0]['source_path'] = 'assets/stale.svg';
$cross_write_consumer['webfont_contract']['svg_consumers'][0]['id'] = 'svg-webfont-consumer-' . substr( hash( 'sha256', 'assets/stale.svg' . "\n" . $svg_write_path . "\n" . $svg_hash . "\n" . $contract['faces'][0]['id'] ), 0, 20 );
$cross_write_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $cross_write_consumer, $consumer_resolved );
$assert( is_wp_error( $cross_write_overlay ) && 'static_site_importer_font_materialization_svg_consumer_invalid' === $cross_write_overlay->get_error_code(), 'stale source/write bindings fail closed' );
$harness = <<<'JS'
const source = Buffer.from(process.argv[1], 'base64').toString('utf8');
let record;
global.document = {
  fonts: { load: async () => [], check: () => true },
  documentElement: { dataset: {} },
  getElementById: () => record,
  createElement: () => (record = {}),
  head: { append: () => {} },
};
global.window = global;
(async () => {
  eval(source);
  await new Promise((resolve) => setImmediate(resolve));
  console.log(JSON.stringify({ candidate_dom_artifact: `<script id="static-site-importer-font-readiness" type="application/json">${record.textContent}</script>`, readiness: JSON.parse(record.textContent) }));
})();
JS;
$process = proc_open( array( 'node', '-e', $harness, base64_encode( $readiness ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
$harness_output = is_resource( $process ) ? stream_get_contents( $pipes[1] ) : '';
if ( is_resource( $process ) ) {
	$harness_error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$harness_status = proc_close( $process );
} else {
	$harness_error = 'node process unavailable';
	$harness_status = 1;
}
$harness_result = json_decode( $harness_output, true );
$assert( 0 === $harness_status && '' === $harness_error && is_array( $harness_result ) && 'loaded' === ( $harness_result['readiness']['status'] ?? '' ) && array_column( $contract['receipts'], 'id' ) === array_column( $harness_result['readiness']['faces'] ?? array(), 'receipt_id' ) && str_contains( $harness_result['candidate_dom_artifact'] ?? '', 'static-site-importer-font-readiness' ), 'readiness script executes in a deterministic DOM harness and emits receipt-keyed candidate DOM capture evidence: ' . $harness_error . ' ' . $harness_output );

$legacy_consumer_plan = $producer_plan;
unset( $legacy_consumer_plan['webfont_contract'] );
$legacy_consumer_plan['provider'] = 'google_fonts';
$legacy_consumer_plan['fonts'] = array( array( 'family' => 'Inter', 'weights' => array( 100, 200, 300, 400, 500, 600, 700, 800, 900 ) ) );
$legacy_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay(
	$legacy_consumer_plan,
	array(
		'writes' => array(
			array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ),
			array( 'target_path' => 'assets/materialized-svg/legacy.svg', 'source_path' => 'assets/legacy.svg', 'payload' => array( 'encoding' => 'utf8', 'data' => '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="Inter">Legacy</text></svg>' ) ),
		),
	)
);
$legacy_svg = (string) ( array_values( array_filter( $legacy_overlay['writes'] ?? array(), static fn( array $write ): bool => 'assets/materialized-svg/legacy.svg' === $write['target_path'] ) )[0]['content'] ?? '' );
$assert( ! is_wp_error( $legacy_overlay ) && str_contains( $legacy_svg, 'data:font/woff2;base64,' ), 'legacy producers retain self-contained SVG font embedding when typed consumers are unavailable' );
$diagnostic_plan = $producer_plan;
$diagnostic_plan['webfont_contract']['diagnostics'] = array( array( 'code' => 'webfont_import_unresolved' ) );
$diagnostic_failure = Static_Site_Importer_Font_Materializer::prepare_overlay( $diagnostic_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( is_wp_error( $diagnostic_failure ) && 'static_site_importer_font_materialization_producer_diagnostic' === $diagnostic_failure->get_error_code(), 'required producer diagnostics gate materialization before writes' );

$local_plan = $producer_plan;
$local_plan['webfont_contract'] = array(
	'schema' => 'blocks-engine/webfont-materialization/v1',
	'imports' => array(),
	'faces' => array(),
	'receipts' => array(),
	'browser_readiness' => array( 'state' => 'not_required', 'required_receipt_ids' => array() ),
	'diagnostics' => array( array( 'code' => 'webfont_import_unsupported_provider' ) ),
);
$request_count = count( $GLOBALS['ssi_webfont_requests'] );
$local_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $local_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $local_overlay ) && array() === $local_overlay['writes'] && $request_count === count( $GLOBALS['ssi_webfont_requests'] ), 'an authoritative local-font contract never falls back to synthesized Google requests' );
$assert( 'producer_webfont_import_unsupported_provider' === ( $local_overlay['diagnostics'][0]['reason'] ?? '' ), 'non-required local-font producer diagnostics survive the handoff without blocking import' );
$local_plan['webfont_contract']['diagnostics'] = array();
$local_overlay_without_diagnostics = Static_Site_Importer_Font_Materializer::prepare_overlay( $local_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $local_overlay_without_diagnostics ) && array() === $local_overlay_without_diagnostics['writes'] && $request_count === count( $GLOBALS['ssi_webfont_requests'] ), 'an authoritative zero-face contract without diagnostics still suppresses legacy Google requests' );

echo "Webfont producer-consumer smoke passed.\n";
