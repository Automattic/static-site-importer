<?php
/** Canonical import ability contract coverage. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_filters'] = array();
$GLOBALS['ssi_can'] = array( 'edit_posts' => true, 'switch_themes' => false );
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
require dirname( __DIR__ ) . '/includes/abilities.php';
$artifact = array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1', 'files' => array() );
$plan = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'artifact', 'artifact' => $artifact ) ) );
if ( empty( $plan['success'] ) || 'blocks-engine/wordpress-site-plan/v2' !== ( $plan['plan']['schema'] ?? '' ) || 1 !== Static_Site_Importer_Theme_Generator::$compiled || 0 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'artifact planning must compile exactly once without materializing' ); }
$rejected = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'upload', 'upload_ref' => '/tmp/caller-path.zip' ) ) );
if ( 'static_site_importer_upload_reference_unresolved' !== ( $rejected['error']['code'] ?? '' ) ) { throw new RuntimeException( 'upload paths must not be accepted without a resolver' ); }
$GLOBALS['ssi_filters']['static_site_importer_resolve_upload_reference'] = static function ( $value, $reference ) use ( $artifact ) { return 'opaque-upload-1' === $reference ? array( 'artifact' => $artifact, 'provenance' => array( 'owner' => 'server' ) ) : $value; };
$upload = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'upload', 'upload_ref' => 'opaque-upload-1' ) ) );
if ( empty( $upload['success'] ) || 'server' !== ( $upload['source']['provenance']['owner'] ?? '' ) ) { throw new RuntimeException( 'opaque upload references must use the explicit resolver contract' ); }
$apply = static_site_importer_ability_import( array( 'source' => array( 'type' => 'artifact', 'artifact' => $artifact ) ) );
if ( empty( $apply['success'] ) || 1 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'apply must delegate to the canonical materializer path' ); }
$approved = array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'pages' => array() );
$approved_apply = static_site_importer_ability_import( array( 'operation' => 'apply', 'plan' => $approved, 'slug' => 'approved-plan' ) );
if ( empty( $approved_apply['success'] ) || $approved !== ( Static_Site_Importer_WordPress_Site_Plan_Materializer::$plans[0]['plan'] ?? null ) || 1 !== Static_Site_Importer_Theme_Generator::$applied ) { throw new RuntimeException( 'approved plan apply must delegate the exact plan without recompiling' ); }
if ( ! static_site_importer_ability_import_permission_callback( array( 'operation' => 'plan' ) ) || static_site_importer_ability_import_permission_callback( array( 'operation' => 'apply' ) ) ) { throw new RuntimeException( 'plan and apply must use distinct capabilities' ); }
echo "Canonical import ability smoke passed.\n";
