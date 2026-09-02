<?php
/**
 * Smoke test: build-provenance primitive records the build that produced an import.
 *
 * Run from the repository root:
 * php tests/smoke-build-provenance.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

define( 'STATIC_SITE_IMPORTER_VERSION', '1.8.1' );

if ( ! function_exists( 'blocks_engine_php_transformer_version' ) ) {
	function blocks_engine_php_transformer_version(): string {
		return '0.8.0';
	}
}

if ( ! function_exists( 'blocks_engine_figma_transformer_version' ) ) {
	function blocks_engine_figma_transformer_version(): string {
		return '0.2.0';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-build-provenance.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$package_root = sys_get_temp_dir() . '/ssi-build-provenance-' . bin2hex( random_bytes( 6 ) );
mkdir( $package_root, 0o777, true );
$receipt_path = $package_root . '/' . Static_Site_Importer_Build_Provenance::DEVELOPMENT_PACKAGE_RECEIPT;

$released = Static_Site_Importer_Build_Provenance::describe();

$assert( Static_Site_Importer_Build_Provenance::SCHEMA === ( $released['schema'] ?? '' ), 'provenance-declares-schema' );
$assert( '1.8.1' === ( $released['static_site_importer']['version'] ?? '' ), 'provenance-records-importer-version' );
$assert( '0.8.0' === ( $released['blocks_engine']['php_transformer'] ?? '' ), 'provenance-records-php-transformer-version' );
$assert( '0.2.0' === ( $released['blocks_engine']['figma_transformer'] ?? '' ), 'provenance-records-figma-transformer-version' );
$assert(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ( $released['imported_at'] ?? '' ) ),
	'provenance-records-utc-import-timestamp',
	(string) ( $released['imported_at'] ?? '' )
);
$assert( '2026-08-29T12:00:00Z' === ( Static_Site_Importer_Build_Provenance::describe( '2026-08-29T12:00:00Z' )['imported_at'] ?? '' ), 'provenance-accepts-explicit-timestamp' );
$assert( ! array_key_exists( 'development_package', $released ), 'released-package-omits-development-receipt' );

$assert( array() === Static_Site_Importer_Build_Provenance::development_package_receipt( $package_root ), 'absent-receipt-reads-empty' );

file_put_contents( $receipt_path, 'not json' );
$assert( array() === Static_Site_Importer_Build_Provenance::development_package_receipt( $package_root ), 'unreadable-receipt-reads-empty' );

file_put_contents( $receipt_path, wp_json_encode_fixture( array( 'schema' => 'some/other/schema', 'static_site_importer' => array( 'head' => 'a' ) ) ) );
$assert( array() === Static_Site_Importer_Build_Provenance::development_package_receipt( $package_root ), 'foreign-schema-receipt-is-rejected' );

$packaged = array(
	'schema'               => Static_Site_Importer_Build_Provenance::DEVELOPMENT_PACKAGE_SCHEMA,
	'command'              => 'npm run build:dev-package',
	'static_site_importer' => array(
		'head'        => str_repeat( 'a', 40 ),
		'dirty'       => true,
		'diff_sha256' => str_repeat( 'b', 64 ),
	),
	'blocks_engine'        => array(
		'ref' => 'origin/trunk',
		'sha' => str_repeat( 'c', 40 ),
	),
	'composer_lock_sha256' => str_repeat( 'd', 64 ),
);
file_put_contents( $receipt_path, wp_json_encode_fixture( $packaged ) );

$assert( $packaged === Static_Site_Importer_Build_Provenance::development_package_receipt( $package_root ), 'development-receipt-is-read-verbatim' );

define( 'STATIC_SITE_IMPORTER_PATH', $package_root . '/' );
$development = Static_Site_Importer_Build_Provenance::describe();
$assert( $packaged === ( $development['development_package'] ?? array() ), 'development-package-identity-reaches-provenance' );
$assert( '1.8.1' === ( $development['static_site_importer']['version'] ?? '' ), 'development-package-keeps-release-version' );

unlink( $receipt_path );
rmdir( $package_root );

if ( $failures ) {
	echo implode( "\n", $failures ) . "\n";
	echo 'FAILED: build-provenance smoke (' . count( $failures ) . ' of ' . $assertions . " assertions)\n";
	exit( 1 );
}

echo 'OK: build-provenance smoke passed (' . $assertions . " assertions)\n";

/**
 * Encode fixture receipts without depending on WordPress helpers.
 *
 * @param array<string,mixed> $value Receipt payload.
 * @return string JSON payload.
 */
function wp_json_encode_fixture( array $value ): string {
	return (string) json_encode( $value );
}
