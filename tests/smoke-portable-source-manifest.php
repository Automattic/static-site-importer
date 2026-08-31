<?php
/** Portable source manifest projection coverage. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-portable-source-manifest.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$file = static fn( string $path, string $content ): array => array(
	'path'           => $path,
	'content_base64' => base64_encode( $content ),
);
$manifest = static function ( array $files, string $root = 'website', string $entrypoint = 'index.html' ): string {
	return json_encode(
		array(
			'schema'     => Static_Site_Importer_Portable_Source_Manifest::SCHEMA,
			'root'       => $root,
			'entrypoint' => $entrypoint,
			'files'      => array_map(
				static fn( array $file ): array => array(
					'path'   => $file['path'],
					'sha256' => hash( 'sha256', $file['content'] ),
				),
				$files
			),
		),
		JSON_UNESCAPED_SLASHES
	);
};

$payload = array(
	array( 'path' => 'index.html', 'content' => '<main>Portable</main>' ),
	array( 'path' => 'css/site.css', 'content' => 'main{display:grid}' ),
);
$artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'wrong.html',
	'files'      => array(
		$file( Static_Site_Importer_Portable_Source_Manifest::FILENAME, $manifest( $payload ) ),
		$file( 'website/index.html', $payload[0]['content'] ),
		$file( 'website/css/site.css', $payload[1]['content'] ),
		$file( 'fixture.json', '{"complexity":3}' ),
	),
);

$projected = Static_Site_Importer_Portable_Source_Manifest::project( $artifact );
$assert( ! is_wp_error( $projected ), 'a valid manifest projects' );
$assert( 'index.html' === $projected['entrypoint'], 'the manifest owns the entrypoint' );
$assert( array( 'index.html', 'css/site.css' ) === array_column( $projected['files'], 'path' ), 'only declared files survive and paths are rebased to the web root' );
$assert( ! in_array( 'fixture.json', array_column( $projected['files'], 'path' ), true ), 'transport metadata is not compiled' );
$assert( Static_Site_Importer_Portable_Source_Manifest::SCHEMA === $projected['portable_source_manifest']['schema'], 'projection records manifest provenance' );

$wrapped = $artifact;
$wrapped['files'][0]['path'] = 'website/' . Static_Site_Importer_Portable_Source_Manifest::FILENAME;
$wrapped_manifest = json_decode( base64_decode( $wrapped['files'][0]['content_base64'] ), true );
$wrapped_manifest['root'] = '.';
$wrapped['files'][0]['content_base64'] = base64_encode( json_encode( $wrapped_manifest ) );
$wrapped = Static_Site_Importer_Portable_Source_Manifest::project( $wrapped );
$assert( ! is_wp_error( $wrapped ) && array( 'index.html', 'css/site.css' ) === array_column( $wrapped['files'], 'path' ), 'manifest paths are relative to their normalized transport directory' );

$duplicate_transport = $artifact;
$duplicate_transport['files'][] = $duplicate_transport['files'][1];
$duplicate_transport = Static_Site_Importer_Portable_Source_Manifest::project( $duplicate_transport );
$assert( is_wp_error( $duplicate_transport ) && 'static_site_importer_portable_source_duplicate_transport_path' === $duplicate_transport->get_error_code(), 'duplicate transported paths fail closed' );

$unmanaged = array( 'entrypoint' => 'index.html', 'files' => array( $file( 'index.html', '<main />' ), $file( 'fixture.json', '{}' ) ) );
$assert( $unmanaged === Static_Site_Importer_Portable_Source_Manifest::project( $unmanaged ), 'sources without a manifest preserve the complete transport payload' );

$missing = $artifact;
array_splice( $missing['files'], 2, 1 );
$missing = Static_Site_Importer_Portable_Source_Manifest::project( $missing );
$assert( is_wp_error( $missing ) && 'static_site_importer_portable_source_file_missing' === $missing->get_error_code(), 'missing declared files fail closed' );

$tampered = $artifact;
$tampered['files'][1]['content_base64'] = base64_encode( '<main>Tampered</main>' );
$tampered = Static_Site_Importer_Portable_Source_Manifest::project( $tampered );
$assert( is_wp_error( $tampered ) && 'static_site_importer_portable_source_hash_mismatch' === $tampered->get_error_code(), 'hash mismatches fail closed' );

$duplicate = $artifact;
$duplicate['files'][] = $duplicate['files'][1];
$duplicate = Static_Site_Importer_Portable_Source_Manifest::project( $duplicate );
$assert( is_wp_error( $duplicate ) && 'static_site_importer_portable_source_duplicate_transport_path' === $duplicate->get_error_code(), 'duplicate transport paths fail closed' );

$invalid_entry = $artifact;
$decoded = json_decode( base64_decode( $invalid_entry['files'][0]['content_base64'] ), true );
$decoded['entrypoint'] = 'missing.html';
$invalid_entry['files'][0]['content_base64'] = base64_encode( json_encode( $decoded ) );
$invalid_entry = Static_Site_Importer_Portable_Source_Manifest::project( $invalid_entry );
$assert( is_wp_error( $invalid_entry ) && 'static_site_importer_portable_source_entrypoint_invalid' === $invalid_entry->get_error_code(), 'entrypoints must be declared' );

echo "portable-source-manifest: ok\n";
