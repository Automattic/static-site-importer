<?php
/** Authored viewport: core owns the standard declaration; any other is a named loss. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-viewport-metadata.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$page     = static fn( string $path, array $meta, bool $synthetic = false ): array => array(
	'source_path'       => $path,
	'synthetic'         => $synthetic,
	'document_metadata' => array( 'meta' => $meta ),
);
$viewport = static fn( string $content ): array => array(
	'name'    => 'viewport',
	'content' => $content,
);
$report   = static fn( array ...$pages ): array => Static_Site_Importer_Viewport_Metadata::report( array( 'pages' => $pages ) );

foreach (
	array(
		'core-equivalent declarations are owned by core'  => array( $report( $page( 'index.html', array( $viewport( 'initial-scale=1.0, WIDTH=device-width' ) ) ), $page( 'about.html', array( $viewport( 'width=device-width, initial-scale=1' ) ) ) ), 'core_owned', array() ),
		'absent declarations change nothing'               => array( $report( $page( 'index.html', array() ) ), 'not_requested', array() ),
		'a different declaration is a named loss'          => array( $report( $page( 'index.html', array( $viewport( 'width=device-width, initial-scale=1' ) ) ), $page( 'about.html', array( $viewport( 'width=320, user-scalable=yes' ) ) ) ), 'report_only', array( 'about.html' ) ),
		'a malformed declaration is a named loss'          => array( $report( $page( 'index.html', array( $viewport( 'width=javascript:alert(1)' ) ) ) ), 'report_only', array( 'index.html' ) ),
		'synthetic hubs neither declare nor contradict'    => array( $report( $page( 'index.html', array( $viewport( 'width=device-width, initial-scale=1' ) ) ), $page( 'hub', array( $viewport( 'width=320' ) ), true ) ), 'core_owned', array() ),
	) as $name => list( $result, $status, $lost )
) {
	$assert( $status === $result['status'], $name . ': status ' . $result['status'] );
	$assert( $lost === ( $result['diagnostics'][0]['source_paths'] ?? array() ), $name . ': lost routes' );
	$assert( array() === $lost || 'viewport_metadata_not_core_default' === $result['diagnostics'][0]['reason_code'], $name . ': reason code' );
	$assert( ! array_key_exists( 'writes', $result ), $name . ': the report never writes theme code' );
}
echo "viewport metadata passed\n";
