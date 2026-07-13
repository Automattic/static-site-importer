<?php
/**
 * Smoke test for host-filterable import permissions.
 *
 * Run with: php tests/smoke-import-permission-filter.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ssi_smoke_caps']    = array();
$GLOBALS['ssi_smoke_filters'] = array();

function __( $text, $domain = 'default' ) { return $text; }
function current_user_can( string $capability ): bool { return ! empty( $GLOBALS['ssi_smoke_caps'][ $capability ] ); }
function is_user_logged_in(): bool { return true; }
function doing_action( string $hook ): bool { return false; }
function did_action( string $hook ): int { return 0; }
function add_action( string $hook, callable|string $callback ): void {}
function add_filter( string $hook, callable $callback ): void { $GLOBALS['ssi_smoke_filters'][ $hook ][] = $callback; }
function apply_filters( string $hook, mixed $value ): mixed {
	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname( __DIR__ ) . '/includes/rest.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$passed = 0;
$failed = 0;

function ssi_smoke_assert( bool $condition, string $label ): void {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "PASS: {$label}\n";
		return;
	}
	++$failed;
	echo "FAIL: {$label}\n";
}

$rest_denied = static_site_importer_rest_manage_permission();
ssi_smoke_assert( is_wp_error( $rest_denied ), 'rest denied without switch_themes by default' );
ssi_smoke_assert( false === static_site_importer_ability_permission_callback(), 'ability denied without switch_themes by default' );

$GLOBALS['ssi_smoke_caps']['switch_themes'] = true;
ssi_smoke_assert( true === static_site_importer_rest_manage_permission(), 'rest allows switch_themes by default' );
ssi_smoke_assert( true === static_site_importer_ability_permission_callback(), 'ability allows switch_themes by default' );

$GLOBALS['ssi_smoke_caps'] = array();
add_filter( 'static_site_importer_can_manage_imports', static fn( bool $allowed ): bool => $allowed || true );
ssi_smoke_assert( true === static_site_importer_rest_manage_permission(), 'rest allows host-filtered product capability' );
ssi_smoke_assert( true === static_site_importer_ability_permission_callback(), 'ability allows host-filtered product capability' );

echo "\n";
if ( 0 === $failed ) {
	echo "All {$passed} assertions passed.\n";
	exit( 0 );
}

echo "{$failed} assertion(s) FAILED ({$passed} passed).\n";
exit( 1 );
