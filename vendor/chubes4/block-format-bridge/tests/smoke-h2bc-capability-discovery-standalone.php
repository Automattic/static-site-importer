<?php
/**
 * Smoke coverage for standalone h2bc capability discovery.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function html_to_blocks_capabilities(): array {
	return array(
		'version'          => 'standalone-test-version',
		'handler'          => __FUNCTION__,
		'supported_blocks' => array( 'core/heading', 'core/paragraph' ),
		'classifications'  => array(
			'core/heading' => 'raw-transformable',
		),
	);
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function bfb_h2bc_standalone_capability_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once __DIR__ . '/../includes/api.php';

$report = bfb_h2bc_capabilities();

bfb_h2bc_standalone_capability_assert( 'html_to_blocks_capabilities' === $report['capability_api'], 'Standalone h2bc capability function should be selected.' );
bfb_h2bc_standalone_capability_assert( 'standalone-test-version' === $report['version'], 'h2bc version should come from standalone capability report.' );
bfb_h2bc_standalone_capability_assert( 'h2bc_capabilities' === $report['inventory']['source'], 'Inventory should identify h2bc capabilities as the source.' );
bfb_h2bc_standalone_capability_assert( in_array( 'core/heading', $report['inventory']['block_coverage']['supported_blocks'], true ), 'Supported blocks should come from h2bc.' );
bfb_h2bc_standalone_capability_assert( 'raw-transformable' === $report['inventory']['block_coverage']['classifications']['core/heading'], 'Classifications should come from h2bc.' );

echo "PASS: standalone h2bc capability discovery\n";
