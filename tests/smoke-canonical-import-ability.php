<?php
/** Canonical import ability contract coverage. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_filters'] = array();
$GLOBALS['ssi_can'] = array( 'edit_posts' => true, 'switch_themes' => false );
$GLOBALS['ssi_runtime_sources'] = array();
function __( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function do_action() {}
function doing_action() { return false; }
function did_action() { return 0; }
function add_action() {}
function current_user_can( $capability ) { return ! empty( $GLOBALS['ssi_can'][ $capability ] ); }
function apply_filters( $hook, $value, ...$args ) { return isset( $GLOBALS['ssi_filters'][ $hook ] ) ? $GLOBALS['ssi_filters'][ $hook ]( $value, ...$args ) : $value; }
class WP_Error { private $code; private $message; private $data; function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function static_site_importer_source_runtime( $source ) {
	$GLOBALS['ssi_runtime_sources'][] = $source;
	if ( isset( $source['html'] ) ) {
		$files = array( array( 'path' => 'website/index.html', 'content' => $source['html'] ) );
	} elseif ( isset( $source['files'] ) ) {
		$files = $source['files'];
	} else {
		$files = array( array( 'path' => 'website/index.html', 'content' => '<h1>ZIP</h1>' ) );
	}
	return array(
		'artifact' => array(
			'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
			'entrypoint' => $source['entrypoint'] ?? 'website/index.html',
			'files' => $files,
		),
		'provider' => 'canonical-source-test',
		'source_metadata' => array(),
	);
}
class Static_Site_Importer_Theme_Generator {
	public static $compiled = 0;
	public static $applied = 0;
	public static function compile_website_artifact( $artifact, $args ) { ++self::$compiled; return array( 'artifact' => $artifact, 'args' => $args, 'compiled' => array(), 'plan' => array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'quality' => array( 'pass' => true ), 'diagnostics' => array( array( 'code' => 'planned' ) ) ), 'gutenberg_gaps' => array(), 'companion_payload' => null, 'materialization_plan' => array() ); }
	public static function import_website_artifact( $artifact, $args ) { ++self::$applied; return array( 'quality' => array( 'pass' => true ), 'import_report_summary' => array( 'status' => 'completed' ) ); }
}
class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public static $plans = array();
	public static function materialize( $plan, $args ) {
		self::$plans[] = array( 'plan' => $plan, 'args' => $args );
		return array( 'status' => 'completed', 'receipt_schema' => 'static-site-importer/materialization-receipt/v1' );
	}
}
class Static_Site_Importer_URL_Import_Runtime {
	public static function run_operation() {
		return array(
			'import_id' => 'url-import-1',
			'continuation' => false,
			'url_batch_run' => array( 'diagnostics' => array() ),
			'terminal_batch_result' => array(
				'plan' => array( 'schema' => 'blocks-engine/wordpress-site-plan/v2' ),
				'diagnostics' => array(),
				'quality' => array( 'pass' => true ),
			),
		);
	}
}
require dirname( __DIR__ ) . '/includes/abilities.php';
$files = array( array( 'path' => 'website/index.html', 'content' => '<h1>Files</h1>' ) );
$plan = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'html', 'html' => '<h1>HTML</h1>' ) ) );
if ( empty( $plan['success'] ) || 'blocks-engine/wordpress-site-plan/v2' !== ( $plan['plan']['schema'] ?? '' ) || 1 !== Static_Site_Importer_Theme_Generator::$compiled || 0 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'pasted HTML planning must compile exactly once without materializing' ); }
$files_plan = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) ) );
if ( empty( $files_plan['success'] ) || $files !== ( $GLOBALS['ssi_runtime_sources'][1]['files'] ?? null ) ) { throw new RuntimeException( 'file sources must use the canonical source normalizer' ); }
$rejected = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'zip', 'ref' => '/tmp/caller-path.zip' ) ) );
if ( 'static_site_importer_source_reference_unresolved' !== ( $rejected['error']['code'] ?? '' ) ) { throw new RuntimeException( 'caller paths must not be accepted without an opaque reference resolver' ); }
$GLOBALS['ssi_filters']['static_site_importer_resolve_source_reference'] = static function ( $value, $reference, $type ) { return 'opaque-zip-1' === $reference && 'zip' === $type ? array( 'source' => array( 'zip' => array( 'name' => 'website.zip', 'content_base64' => 'UEs=' ) ), 'provenance' => array( 'owner' => 'server' ) ) : $value; };
$zip = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'zip', 'ref' => 'opaque-zip-1' ) ) );
if ( empty( $zip['success'] ) || 'server' !== ( $zip['source']['provenance']['owner'] ?? '' ) ) { throw new RuntimeException( 'opaque references must resolve the declared source type' ); }
$url = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'url', 'url' => 'https://example.com/' ) ) );
if ( empty( $url['success'] ) || 'blocks-engine/wordpress-site-plan/v2' !== ( $url['plan']['schema'] ?? '' ) ) { throw new RuntimeException( 'URL sources must produce a canonical plan' ); }
$apply = static_site_importer_ability_import( array( 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) ) );
if ( empty( $apply['success'] ) || 1 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'apply must delegate to the canonical materializer path' ); }
$approved = array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'pages' => array() );
$approved_apply = static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $approved, 'slug' => 'approved-plan' ) );
if ( empty( $approved_apply['success'] ) || $approved !== ( Static_Site_Importer_WordPress_Site_Plan_Materializer::$plans[0]['plan'] ?? null ) || 1 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'approved plan apply must delegate the exact plan without recompiling' ); }
if ( ! static_site_importer_ability_import_permission_callback( array( 'operation' => 'plan' ) ) || static_site_importer_ability_import_permission_callback( array( 'operation' => 'apply' ) ) ) { throw new RuntimeException( 'plan and apply must use distinct capabilities' ); }
echo "Canonical import ability smoke passed.\n";
