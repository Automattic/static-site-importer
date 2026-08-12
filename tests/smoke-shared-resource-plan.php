<?php
/** Run: php tests/smoke-shared-resource-plan.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string { return $this->code; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-shared-resource-plan.php';
$root = sys_get_temp_dir() . '/ssi-shared-plan-' . bin2hex( random_bytes( 4 ) ); mkdir( $root, 0700, true );
$workspace = new Static_Site_Importer_Artifact_Run_Workspace( $root, 'shared-plan' );
$plan = new Static_Site_Importer_Shared_Resource_Plan( $workspace );
$artifact = static fn( string $css, string $page ): array => array( 'files' => array( array( 'path' => 'website/index.html', 'mime_type' => 'text/html', 'content' => $page ), array( 'path' => 'website/theme.css', 'mime_type' => 'text/css', 'content' => $css ), array( 'path' => 'website/app.js', 'mime_type' => 'application/javascript', 'content' => 'shared-script' ) ) );
$first = $plan->reconcile( $artifact( 'body{color:red}', 'first' ) );
$second = ( new Static_Site_Importer_Shared_Resource_Plan( $workspace ) )->reconcile( $artifact( 'body{color:red}', 'second' ) );
$changed = $plan->reconcile( $artifact( 'body{color:blue}', 'third' ) );
if ( is_wp_error( $first['plan'] ) || $first['changed'] || is_wp_error( $second['plan'] ) || $second['changed'] || $first['digest'] !== $second['digest'] || is_wp_error( $changed['plan'] ) || ! $changed['changed'] || $changed['digest'] === $first['digest'] || ! is_file( $workspace->directory() . '/shared-resource-plan.json' ) ) { throw new RuntimeException( 'shared plans must survive restart, ignore page-only changes, and deterministically invalidate changed shared content' ); }
$expanded = $plan->reconcile( array( 'files' => array( array( 'path' => 'website/extra.css', 'mime_type' => 'text/css', 'content' => 'h1{color:blue}' ) ) ) );
$preserved = $plan->reconcile( $artifact( 'body{color:blue}', 'fourth' ) );
if ( is_wp_error( $expanded['plan'] ) || ! $expanded['changed'] || is_wp_error( $preserved['plan'] ) || $preserved['changed'] || 3 !== count( $preserved['plan']['resources'] ?? array() ) ) { throw new RuntimeException( 'shared plans must retain resources discovered only in an earlier batch' ); }
$workspace->purge(); @rmdir( $root );
echo "Shared resource plan smoke passed.\n";
