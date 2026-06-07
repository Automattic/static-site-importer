<?php
/**
 * Smoke coverage for prefixed h2bc capability discovery.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

namespace BlockFormatBridge\Vendor {
	function html_to_blocks_capabilities(): array {
		return array(
			'version'          => 'prefixed-test-version',
			'handler'          => __FUNCTION__,
			'transforms'       => array(
				'families' => array( 'site-editor', 'text' ),
			),
			'block_coverage'  => array(
				'supported_blocks'   => array( 'core/pattern', 'core/template-part' ),
				'unsupported_blocks' => array( 'core/query' ),
				'classifications'    => array(
					'core/pattern' => 'explicit-marker',
					'core/query'   => 'compiler-only',
				),
			),
		);
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}

	function bfb_h2bc_capability_discovery_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	require_once __DIR__ . '/../includes/api.php';

	$report = bfb_h2bc_capabilities();

	bfb_h2bc_capability_discovery_assert( '\\BlockFormatBridge\\Vendor\\html_to_blocks_capabilities' === $report['capability_api'], 'Prefixed h2bc capability function should be selected.' );
	bfb_h2bc_capability_discovery_assert( 'prefixed-test-version' === $report['version'], 'h2bc version should come from prefixed capability report.' );
	bfb_h2bc_capability_discovery_assert( 'h2bc_capabilities' === $report['inventory']['source'], 'Inventory should identify h2bc capabilities as the source.' );
	bfb_h2bc_capability_discovery_assert( in_array( 'core/pattern', $report['inventory']['block_coverage']['supported_blocks'], true ), 'Supported blocks should come from h2bc.' );
	bfb_h2bc_capability_discovery_assert( 'explicit-marker' === $report['inventory']['block_coverage']['classifications']['core/pattern'], 'Classifications should come from h2bc.' );

	echo "PASS: prefixed h2bc capability discovery\n";
}
