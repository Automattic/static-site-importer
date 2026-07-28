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
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
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

$checkout_candidates = array_filter( array_merge( array( getenv( 'BLOCKS_ENGINE_DIR' ) ?: '' ), glob( dirname( dirname( __DIR__ ) ) . '/blocks-engine*' ) ?: array() ) );
$blocks_engine_root = '';
foreach ( $checkout_candidates as $candidate ) {
	$builder = $candidate . '/php-transformer/src/StaticSite/FontMaterialization/FontMaterializationPlanBuilder.php';
	if ( is_file( $builder ) && str_contains( (string) file_get_contents( $builder ), 'webfont_contract' ) && is_dir( $candidate . '/fixtures/websites/37-art-gallery-exhibition' ) ) {
		$blocks_engine_root = $candidate;
		break;
	}
}
if ( '' === $blocks_engine_root ) {
	echo "Webfont producer-consumer smoke skipped: Blocks Engine checkout unavailable.\n";
	exit( 0 );
}
$blocks_engine = $blocks_engine_root . '/php-transformer/src/';
require $blocks_engine . 'StaticSite/FontMaterialization/FontMaterializationPlanBuilder.php';
spl_autoload_register(
	static function ( string $class ) use ( $blocks_engine ): void {
		$prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$path = $blocks_engine . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $path ) ) require $path;
		}
	},
	true,
	true
);
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$fixture_root = $blocks_engine_root . '/fixtures/websites/37-art-gallery-exhibition';
$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture_root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() ) $files[ substr( $file->getPathname(), strlen( $fixture_root ) + 1 ) ] = file_get_contents( $file->getPathname() );
}
$producer_plan = ( new Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder() )->fromWebFontSources(
	(string) $files['index.html'],
	(string) $files['css/style.css'],
	array( array( 'path' => 'css/style.css', 'content' => (string) $files['css/style.css'], 'source_hash' => hash( 'sha256', (string) $files['css/style.css'] ) ) )
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
$legacy_overlay = Static_Site_Importer_Font_Materializer::prepare_overlay( $legacy_consumer_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( ! is_wp_error( $legacy_overlay ) && ! empty( $legacy_overlay['writes'] ), 'new producer remains consumable by the legacy Google-font fallback when face records are ignored' );
$diagnostic_plan = $producer_plan;
$diagnostic_plan['webfont_contract']['diagnostics'] = array( array( 'code' => 'webfont_import_unresolved' ) );
$diagnostic_failure = Static_Site_Importer_Font_Materializer::prepare_overlay( $diagnostic_plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => '<?php' ) ) ) ) );
$assert( is_wp_error( $diagnostic_failure ) && 'static_site_importer_font_materialization_producer_diagnostic' === $diagnostic_failure->get_error_code(), 'required producer diagnostics gate materialization before writes' );

echo "Webfont producer-consumer smoke passed.\n";
