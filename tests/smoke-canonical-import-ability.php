<?php
/** Canonical import ability contract coverage. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_filters'] = array();
$GLOBALS['ssi_can'] = array( 'edit_posts' => true, 'switch_themes' => false );
$GLOBALS['ssi_runtime_sources'] = array();
function __( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
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
	public static $last_args = array();
	public static $drift = false;
	public static function compile_website_artifact( $artifact, $args ) { ++self::$compiled; $plan = array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'quality' => array( 'pass' => true ), 'diagnostics' => array( array( 'code' => 'planned' ) ) ); if ( 'classic' === ( $args['theme_materialization'] ?? '' ) ) { $args['classic_theme_projection'] = Static_Site_Importer_Classic_Theme_Projection::build( $artifact, $plan ); } return array( 'artifact' => $artifact, 'args' => $args, 'compiled' => array(), 'plan' => $plan, 'gutenberg_gaps' => array(), 'companion_payload' => null, 'materialization_plan' => array() ); }
	public static function import_website_artifact( $artifact, $args ) { if ( self::$drift && isset( $args['approved_classic_plan_hash'] ) ) { return new WP_Error( 'static_site_importer_approved_classic_plan_changed', 'drift' ); } ++self::$applied; self::$last_args = $args; return array( 'quality' => array( 'pass' => true ), 'import_report_summary' => array( 'status' => 'completed' ) ); }
}
class Static_Site_Importer_Classic_Theme_Projection { public static function build( $artifact, $plan ) { return array( 'schema' => 'static-site-importer/classic-theme-projection/v1', 'artifact' => $artifact['entrypoint'] ?? '', 'plan' => $plan['schema'] ?? '' ); } }
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
$resumed = static_site_importer_ability_import( array( 'runtime_lifecycle_phase' => 'resume', 'runtime_lifecycle_request_id' => 'prepared-request', 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) ) );
if ( empty( $resumed['success'] ) || 'resume' !== ( Static_Site_Importer_Theme_Generator::$last_args['runtime_lifecycle_phase'] ?? '' ) || 'prepared-request' !== ( Static_Site_Importer_Theme_Generator::$last_args['runtime_lifecycle_request_id'] ?? '' ) || '00000000-0000-4000-8000-000000000001' !== ( Static_Site_Importer_Theme_Generator::$last_args['runtime_lifecycle_invocation_id'] ?? '' ) ) { throw new RuntimeException( 'canonical imports must preserve the lifecycle handoff while assigning a server-owned invocation identity' ); }
$approved = array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'pages' => array() );
$approved_apply = static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $approved, 'slug' => 'approved-plan' ) );
	if ( empty( $approved_apply['success'] ) || $approved !== ( Static_Site_Importer_WordPress_Site_Plan_Materializer::$plans[0]['plan'] ?? null ) || 2 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'approved plan apply must delegate the exact plan without recompiling' ); }
$classic_plan = static_site_importer_ability_import( array( 'operation' => 'plan', 'theme_materialization' => 'classic', 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) ) );
$classic_apply = static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $classic_plan ) );
	if ( empty( $classic_plan['classic_materialization']['artifact_hash'] ) || empty( $classic_plan['classic_materialization']['projection_hash'] ) || empty( $classic_apply['success'] ) || 3 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'classic plan apply must verify its immutable artifact/projection bundle and use the full artifact materialization lifecycle' ); }
$tampered_strategy = $classic_plan;
$tampered_strategy['classic_materialization']['normalized_args']['theme_materialization'] = 'block';
$tampered_args = $classic_plan;
$tampered_args['classic_materialization']['normalized_args']['activate'] = true;
if ( 'static_site_importer_classic_plan_input_changed' !== ( static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $tampered_strategy ) )['error']['code'] ?? '' ) || 'static_site_importer_classic_plan_input_changed' !== ( static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $tampered_args ) )['error']['code'] ?? '' ) ) { throw new RuntimeException( 'classic apply rejects strategy and normalized argument tampering before materialization' ); }
Static_Site_Importer_Theme_Generator::$drift = true;
$writes_before_drift = Static_Site_Importer_Theme_Generator::$applied;
$drift = static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $classic_plan ) );
if ( 'static_site_importer_approved_classic_plan_changed' !== ( $drift['error']['code'] ?? '' ) || $writes_before_drift !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'compiler drift rejects the approved classic plan before materialization writes or entity lifecycle work' ); }
if ( ! static_site_importer_ability_import_permission_callback( array( 'operation' => 'plan' ) ) || static_site_importer_ability_import_permission_callback( array( 'operation' => 'apply' ) ) ) { throw new RuntimeException( 'plan and apply must use distinct capabilities' ); }
echo "Canonical import ability smoke passed.\n";
