<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-viewport-metadata-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$page = static fn( string $path, array $meta ): array => array(
	'source_path'      => $path,
	'document_metadata' => array( 'meta' => $meta ),
);
$viewport = static fn( string $content ): array => array( 'name' => 'viewport', 'content' => $content );
$plan = array(
	'pages'  => array( $page( 'index.html', array( $viewport( 'width=320,user-scalable=YES' ) ) ), $page( 'about.html', array( $viewport( 'width=320, user-scalable=yes' ) ) ) ),
	'writes' => array(),
);
$result = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( $plan, array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => "<?php\n// Existing bootstrap.\n" ) ) ) );
$bootstrap = (string) ( $result['writes'][0]['content'] ?? '' );
$assert( 'materialized' === $result['status'] && 'width=320, user-scalable=yes' === $result['declaration'], 'Consistent declarations should materialize in normalized form.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'template_include' ) && str_contains( $bootstrap, "remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 )" ), 'The portable theme bootstrap should replace the block-template viewport callback without dropping prior bootstrap code.' );

$missing = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( array( 'pages' => array( $page( 'index.html', array( $viewport( 'width=320' ) ) ), $page( 'about.html', array() ) ) ) );
$assert( 'report_only' === $missing['status'] && 'viewport_metadata_missing_route' === ( $missing['diagnostics'][0]['reason_code'] ?? '' ) && array() === $missing['writes'], 'A declaration missing from one route should remain report-only.' );

$conflict = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( array( 'pages' => array( $page( 'index.html', array( $viewport( 'width=320' ) ) ), $page( 'about.html', array( $viewport( 'width=device-width' ) ) ) ) ) );
$assert( 'report_only' === $conflict['status'] && 'viewport_metadata_conflict' === ( $conflict['diagnostics'][0]['reason_code'] ?? '' ), 'Conflicting route declarations should emit a bounded diagnostic.' );

$invalid = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( array( 'pages' => array( $page( 'index.html', array( $viewport( 'width=javascript:alert(1)' ) ) ) ) ) );
$assert( 'report_only' === $invalid['status'] && 'viewport_metadata_invalid' === ( $invalid['diagnostics'][0]['reason_code'] ?? '' ), 'Invalid declarations should never enter generated PHP.' );

$duplicate = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( array( 'pages' => array( $page( 'index.html', array( $viewport( 'width=320' ), $viewport( 'width=320' ) ) ) ) ) );
$assert( 'report_only' === $duplicate['status'] && 'viewport_metadata_duplicate' === ( $duplicate['diagnostics'][0]['reason_code'] ?? '' ), 'Duplicate declarations should remain report-only.' );

$absent = Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( array( 'pages' => array( $page( 'index.html', array() ) ) ) );
$assert( 'not_requested' === $absent['status'] && array() === $absent['diagnostics'], 'Sites without authored viewport metadata should remain unchanged.' );

echo "viewport metadata materializer smoke passed\n";
