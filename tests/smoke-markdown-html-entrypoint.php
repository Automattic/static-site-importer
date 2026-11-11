<?php
/**
 * HTML entry documents take precedence over unsupported Markdown twins (#2869).
 *
 * @package StaticSiteImporter
 */

// Model an HTML-only runtime even when the development vendor tree supports Markdown.
class SSI_Unavailable_Markdown_Adapter {
	public static function isAvailable(): bool {
		return false;
	}
}
class_alias( SSI_Unavailable_Markdown_Adapter::class, 'Automattic\\BlocksEngine\\PhpTransformer\\FormatBridge\\MarkdownAdapter' );

require_once __DIR__ . '/fixtures/direct-import-harness.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$assert( ! Static_Site_Importer_Runtime_Capabilities::supports_source_format( 'markdown' ), 'The test runtime must lack Markdown conversion.' );
$request = static fn ( array $files ): array => array(
	'operation' => 'apply',
	'slug'      => 'markdown-twins',
	'source'    => array(
		'type'  => 'files',
		'files' => array_map( static fn ( string $path, string $content ): array => array( 'path' => 'website/' . $path, 'content' => $content ), array_keys( $files ), $files ),
	),
);

try {
	// Put Markdown first: admission must not depend on file order or a declared entrypoint.
	$result = ssi_harness_drive( $request( array(
		'index.md'   => '# Home',
		'about.md'   => '# About',
		'index.html' => '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1><p>Welcome.</p><a href="about.html">About</a></main></body></html>',
		'about.html' => '<!doctype html><html><head><title>About</title></head><body><main><h1>About</h1><p>About us.</p><a href="index.html">Home</a></main></body></html>',
	) ) );
	$assert( true === ( $result['success'] ?? false ), 'HTML with Markdown twins must complete: ' . json_encode( $result ) );
	$stored = ssi_harness_last_import();
	$paths  = array_column( $stored['plan']['pages'] ?? array(), 'source_path' );
	sort( $paths );
	$assert( array( 'website/about.html', 'website/index.html' ) === $paths, 'Only the two HTML pages must be compiled and persisted.' );
	$rows = ssi_harness_findings( $stored['report'], Static_Site_Importer_Content_Policy::FILE_SKIPPED );
	$skipped = array();
	foreach ( $rows as $row ) {
		if ( 'unsupported_source_format' === ( $row['reason_code'] ?? '' ) ) {
			$skipped[] = $row['source_path'];
		}
	}
	sort( $skipped );
	$assert( array( 'website/about.md', 'website/index.md' ) === $skipped, 'Both Markdown twins must be reported as unsupported_source_format.' );

	$result = ssi_harness_drive( $request( array( 'index.md' => '# Only entry document' ) ) );
	$assert( 'static_site_importer_source_format_unsupported' === ( $result['error']['code'] ?? '' ), 'Markdown-only entry must keep its error: ' . json_encode( $result ) );
	$assert( str_contains( $result['error']['message'] ?? '', 'markdown conversion capability required by website/index.md' ), 'Markdown-only entry must keep its clear capability message.' );
} finally {
	ssi_harness_cleanup();
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Markdown twins end-to-end import passed.\n";
