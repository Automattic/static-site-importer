<?php
/**
 * Rejected media rebinds retain their public reason. Run with php.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.NamingConventions, WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions -- Standalone, isolated persistence collaborators.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( private $code, private $message, private $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class Static_Site_Importer_Current_Site_Capabilities {
	public static function check_plan( $state ) { return true; }
}
class Static_Site_Importer_Media_Library_Materializer {
	public static function resolve_plan_assets( $state ) { return array( 'image' => array( 'id' => 1, 'url' => 'https://example.org/image.png' ) ); }
}
class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	const RECEIPT_SCHEMA = 'static-site-importer/wordpress-site-plan-receipt/v1';
}
class Static_Site_Importer_Site_Plan_Preparation {
	public static $reject_refresh = false;
	public static $reason = '';
	public static function refresh_prepared_destination( $state ) {
		return ! empty( $state['rebound'] ) && self::$reject_refresh ? self::rejection() : $state;
	}
	public static function admit_prepared( $state ) { return $state; }
	public static function prepare( $plan, $args, $resolutions ) {
		return self::$reject_refresh ? array( 'status' => 'prepared', 'rebound' => true ) : self::rejection();
	}
	private static function rejection() {
		return array(
			'status' => 'rejected',
			'receipt' => array(
				'errors' => array( array( 'code' => self::$reason ) ),
				'diagnostics' => array( array( 'reason_code' => self::$reason ) ),
			),
		);
	}
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-receipt.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$state = array(
	'status' => 'prepared',
	'plan' => array( 'assets' => array(), 'reference_tokens' => array() ),
	'plan_identity' => array(),
	'receipt_instance_id' => Static_Site_Importer_Site_Plan_Receipt::receipt_instance_id(),
	'args' => array(),
	'payload_reader' => null,
	'theme' => array( 'uri' => 'https://example.org/theme' ),
	'applied' => array( 'posts' => array(), 'files' => array(), 'operations' => array() ),
	'rollback' => array( 'done' => true ),
	'skipped' => array(),
	'existing_matches' => array(),
	'diagnostics' => array(),
	'quality_budget_admission' => array(),
	'theme_materialization' => array(),
);
$reason = 'WordPress site plan webfont SVG consumer is detached from canonical assets or writes.';
$materialize = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'materialize_prepared_state' );
foreach ( array( false, true ) as $reject_refresh ) {
	Static_Site_Importer_Site_Plan_Preparation::$reject_refresh = $reject_refresh;
	foreach ( array( $reason, $reason . ' /srv/private/secret.php token=supersecret https://private.example/secret ' . str_repeat( 'padding ', 200 ) ) as $detail ) {
		Static_Site_Importer_Site_Plan_Preparation::$reason = $detail;
		$receipt = $materialize->invoke( null, $state );
		$error = $receipt['errors'][0];
		$assert( 'media_library_resolution_failed' === $error['code'], 'Stable media failure code must survive.' );
		$assert( str_contains( $error['message'], $reason ), 'Public error must state the rejected rebind reason: ' . $error['message'] );
		$assert( strlen( $error['message'] ) < 700, 'Public error must remain bounded.' );
		$assert( ! str_contains( $error['message'], '/srv/private' ) && ! str_contains( $error['message'], 'supersecret' ) && ! str_contains( $error['message'], 'private.example' ), 'Public error must redact paths, credentials and URLs.' );
		$assert( in_array( $detail, array_column( $receipt['diagnostics'], 'reason_code' ), true ), 'Rejected diagnostics must survive in the failed receipt.' );
	}
}
echo "Media rebind error smoke passed.\n";
