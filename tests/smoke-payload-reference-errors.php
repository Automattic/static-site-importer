<?php
/**
 * Payload reference failures retain file evidence through CLI receipt projection.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function apply_filters( string $hook, $value ) {
	return $value;
}
function wp_json_encode( $value, int $options = 0 ) {
	return json_encode( $value, $options );
}
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/cli.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-compiler-limits.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';

$maximum = Static_Site_Importer_Compiler_Limits::resolve()['max_file_bytes'];
$reference = array(
	'schema' => 'blocks-engine/payload-reference/v1',
	'id'     => 'asset-evidence',
	'sha256' => str_repeat( 'a', 64 ),
	'bytes'  => $maximum + 1,
);
$reader = new class() {
	public function read() {
		throw new RuntimeException( 'Invalid and oversized references must not be read.' );
	}
};
$cases = array(
	array( $reference, 'static_site_importer_payload_file_too_large' ),
	array( array_merge( $reference, array( 'sha256' => 'invalid' ) ), 'static_site_importer_payload_reference_invalid' ),
	array( array_diff_key( $reference, array( 'bytes' => true ) ), 'static_site_importer_payload_reference_invalid' ),
);
foreach ( $cases as [ $candidate, $code ] ) {
	$file = array( 'path' => 'asset-evidence.json', 'payload_reference' => $candidate );
	$error = Static_Site_Importer_Content_Policy::file_bytes( $file, $reader );
	$expected = array( 'path' => $file['path'], 'bytes' => $candidate['bytes'] ?? null, 'max_bytes' => $maximum );
	if ( ! is_wp_error( $error ) || $code !== $error->get_error_code() || $expected !== $error->get_error_data() ) {
		throw new RuntimeException( 'Incorrect payload reference error or evidence.' );
	}
	// The materialization/compilation error is wrapped by the canonical service before CLI receipt emission.
	$response = Static_Site_Importer_Canonical_Import_Service::error( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() );
	$receipt = json_decode( json_encode( static_site_importer_cli_import_receipt( $response, 1 ) ), true );
	foreach ( array( $receipt['response']['error'], $receipt['response']['diagnostics'][0], $receipt['response']['errors'][0] ) as $row ) {
		if ( $code !== $row['code'] || $expected !== $row['data'] ) {
			throw new RuntimeException( 'CLI receipt lost payload reference evidence.' );
		}
	}
}
echo "Payload reference error smoke passed.\n";
