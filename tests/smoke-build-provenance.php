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

// Producer-carried artifact provenance (blocks-engine#1874): validation,
// composition, durable header lines, and the fleet-queryable site option.
$valid_provenance = array(
	'schema'         => Static_Site_Importer_Build_Provenance::ARTIFACT_PROVENANCE_SCHEMA,
	'generator'      => 'blocks-engine',
	'engine_version' => '1.0.0',
	'artifact_hash'  => str_repeat( 'a1', 32 ),
);

$assert( true === Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $valid_provenance ), 'artifact-provenance-valid-record-accepted' );
foreach ( array(
	'null'                 => null,
	'list'                 => array( $valid_provenance ),
	'foreign-schema'       => array_merge( $valid_provenance, array( 'schema' => 'some/other/schema' ) ),
	'missing-generator'    => array_diff_key( $valid_provenance, array( 'generator' => true ) ),
	'empty-generator'      => array_merge( $valid_provenance, array( 'generator' => '  ' ) ),
	'newline-engine'       => array_merge( $valid_provenance, array( 'engine_version' => "1.0.0\nVersion: 9.9.9" ) ),
	'short-hash'           => array_merge( $valid_provenance, array( 'artifact_hash' => 'a1b2c3' ) ),
	'non-hex-hash'         => array_merge( $valid_provenance, array( 'artifact_hash' => str_repeat( 'z', 64 ) ) ),
	'numeric-generator'    => array_merge( $valid_provenance, array( 'generator' => 42 ) ),
) as $label => $record ) {
	$assert( false === Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $record ), 'artifact-provenance-rejects-' . $label );
}

$composed = Static_Site_Importer_Build_Provenance::describe_artifact( $valid_provenance, '2026-09-16T08:00:00Z' );
$assert( Static_Site_Importer_Build_Provenance::SCHEMA === ( $composed['schema'] ?? '' ), 'composed-identity-keeps-build-provenance-schema' );
$assert( '2026-09-16T08:00:00Z' === ( $composed['imported_at'] ?? '' ), 'composed-identity-records-import-timestamp' );
$assert( '1.8.1' === ( $composed['static_site_importer']['version'] ?? '' ), 'composed-identity-records-importer-version' );
$assert( $valid_provenance === ( $composed['artifact'] ?? null ), 'composed-identity-carries-producer-record-verbatim' );
$assert( ! array_key_exists( 'artifact', Static_Site_Importer_Build_Provenance::describe_artifact() ), 'identity-without-provenance-omits-artifact-record' );

$header_lines = Static_Site_Importer_Build_Provenance::artifact_header_lines( $valid_provenance, 'ssi-acme' );
$assert( array( 'Version: 1.8.1+' . substr( 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 0, 8 ), 'Update URI: https://static-site-importer.invalid/ssi-acme' ) === $header_lines, 'header-lines-carry-real-version-and-neutral-update-uri', print_r( $header_lines, true ) );
$assert( array() === Static_Site_Importer_Build_Provenance::artifact_header_lines( array(), 'ssi-acme' ), 'absent-provenance-emits-no-header-lines' );

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value ): mixed {
		$filter = $GLOBALS['ssi_provenance_filters'][ $hook ] ?? null;
		return is_callable( $filter ) ? $filter( $value ) : $value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool $autoload = false ): bool {
		$GLOBALS['ssi_provenance_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['ssi_provenance_options'][ $option ] ?? $default;
	}
}
$GLOBALS['ssi_provenance_filters']['static_site_importer_update_uri_host'] = static fn ( string $host ): string => 'updates.consumer.example';
$filtered_lines = Static_Site_Importer_Build_Provenance::artifact_header_lines( $valid_provenance, 'SSI Acme!' );
$assert( (bool) preg_match( '#^Update URI: https://updates\.consumer\.example/ssi-acme$#', (string) ( $filtered_lines[1] ?? '' ) ), 'update-host-is-consumer-policy', print_r( $filtered_lines, true ) );
$GLOBALS['ssi_provenance_filters']['static_site_importer_update_uri_host'] = static fn (): string => '';
$assert( 1 === count( Static_Site_Importer_Build_Provenance::artifact_header_lines( $valid_provenance, 'ssi-acme' ) ) && str_starts_with( (string) Static_Site_Importer_Build_Provenance::artifact_header_lines( $valid_provenance, 'ssi-acme' )[0], 'Version: ' ), 'empty-update-host-emits-no-update-uri-line' );
unset( $GLOBALS['ssi_provenance_filters'] );

$assert( true === Static_Site_Importer_Build_Provenance::record_artifact_identity( $composed ), 'identity-option-write-succeeds' );
$read_identity = Static_Site_Importer_Build_Provenance::artifact_identity();
$assert( $composed === $read_identity, 'identity-option-round-trips-composed-record' );
$assert( $valid_provenance['artifact_hash'] === ( $read_identity['artifact']['artifact_hash'] ?? '' ), 'identity-option-exposes-artifact-hash' );
$GLOBALS['ssi_provenance_options'][ Static_Site_Importer_Build_Provenance::ARTIFACT_IDENTITY_OPTION ] = 'not json';
$assert( array() === Static_Site_Importer_Build_Provenance::artifact_identity(), 'unreadable-identity-option-reads-empty' );
$GLOBALS['ssi_provenance_options'][ Static_Site_Importer_Build_Provenance::ARTIFACT_IDENTITY_OPTION ] = '{"schema":"static-site-importer/build-provenance/v0"}';
$assert( array() === Static_Site_Importer_Build_Provenance::artifact_identity(), 'foreign-schema-identity-option-reads-empty' );
$assert( false === Static_Site_Importer_Build_Provenance::record_artifact_identity( array( 'schema' => 'wrong' ) ), 'identity-option-write-requires-current-schema' );
unset( $GLOBALS['ssi_provenance_options'] );

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
