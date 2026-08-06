<?php
/**
 * Smoke test: consumer-defined import disposition seam.
 *
 * Proves the actual extension behavior of
 * static_site_importer_import_website_artifact_with_disposition():
 *  - with no consumer registered, it falls back to the built-in, non-destructive
 *    Playground preview (a real blueprint URL);
 *  - a consumer registered on the 'static_site_importer_import_disposition'
 *    filter receives the normalized artifact + context and can claim the import,
 *    fully defining its outcome.
 *
 * Run from the repository root:
 * php tests/smoke-import-disposition.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) ) {
	define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// Minimal WordPress hook registry so add_filter()/apply_filters() behave for real.
$GLOBALS['ssi_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ssi_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['ssi_filters'][ $hook ] ) ) {
			return $value;
		}

		$registered = $GLOBALS['ssi_filters'][ $hook ];
		ksort( $registered );

		foreach ( $registered as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$passed = array_slice( array_merge( array( $value ), $args ), 0, $entry['accepted_args'] );
				$value  = call_user_func_array( $entry['callback'], $passed );
			}
		}

		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';

add_filter(
	'static_site_importer_playground_package',
	static fn() => array(
		'url'     => 'https://github.com/Automattic/static-site-importer/releases/download/v1.2.3/static-site-importer.zip',
		'version' => 'v1.2.3',
		'sha256'  => str_repeat( 'a', 64 ),
	)
);

$assert(
	function_exists( 'static_site_importer_import_website_artifact_with_disposition' ),
	'function-exists',
	'disposition dispatcher is defined'
);

$artifact = array(
	'schema'     => 'static-site-importer/website-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><title>Disposition</title><h1>Hi</h1>',
		),
	),
);

// --- Case 1: no consumer registered -> built-in Playground preview fallback. ---
$fallback = static_site_importer_import_website_artifact_with_disposition(
	$artifact,
	array( 'name' => 'Disposition Demo', 'slug' => 'disposition-demo' ),
	array( 'mode' => 'playground', 'preview_source' => 'upload' )
);

$assert( is_array( $fallback ), 'fallback-array', 'fallback returns an array' );
$assert( ! empty( $fallback['success'] ), 'fallback-success', 'fallback marks success' );
$assert(
	isset( $fallback['preview']['playground']['blueprint_url'] )
		&& 0 === strpos( (string) $fallback['preview']['playground']['blueprint_url'], 'https://playground.wordpress.net/#' ),
	'fallback-blueprint-url',
	'fallback yields a self-contained Playground blueprint URL'
);
$assert(
	isset( $fallback['mode'] ) && 'playground' === $fallback['mode'],
	'fallback-mode',
	'fallback is tagged as the built-in playground disposition'
);

// --- Case 2: a consumer claims the import and defines its own outcome. ---
$GLOBALS['ssi_seen'] = array();

add_filter(
	'static_site_importer_import_disposition',
	static function ( $disposition, $seen_artifact, $seen_input, $seen_context ) {
		// Defer cooperatively if a prior consumer already claimed it.
		if ( null !== $disposition ) {
			return $disposition;
		}

		$GLOBALS['ssi_seen'] = array(
			'artifact_entrypoint' => $seen_artifact['entrypoint'] ?? null,
			'context_mode'        => $seen_context['mode'] ?? null,
			'input_slug'          => $seen_input['slug'] ?? null,
		);

		// A real consumer would persist the artifact here, then return a preview.
		$preview = static_site_importer_build_playground_preview( $seen_artifact, $seen_input, 'project' );

		return array(
			'success' => true,
			'mode'    => 'project',
			'project' => array( 'id' => 4242 ),
			'preview' => $preview['preview'] ?? array(),
		);
	},
	10,
	4
);

$claimed = static_site_importer_import_website_artifact_with_disposition(
	$artifact,
	array( 'name' => 'Disposition Demo', 'slug' => 'disposition-demo' ),
	array( 'mode' => 'playground', 'preview_source' => 'upload' )
);

$assert( is_array( $claimed ), 'claimed-array', 'claimed disposition returns an array' );
$assert(
	isset( $claimed['mode'] ) && 'project' === $claimed['mode'],
	'claimed-mode',
	'consumer outcome replaces the built-in playground mode'
);
$assert(
	isset( $claimed['project']['id'] ) && 4242 === $claimed['project']['id'],
	'claimed-project',
	'consumer-defined disposition payload is returned verbatim'
);
$assert(
	isset( $claimed['preview']['playground']['blueprint_url'] ),
	'claimed-preview',
	'consumer can reuse the built-in Playground preview helper'
);
$assert(
	'website/index.html' === ( $GLOBALS['ssi_seen']['artifact_entrypoint'] ?? null ),
	'consumer-received-artifact',
	'consumer receives the normalized artifact'
);
$assert(
	'playground' === ( $GLOBALS['ssi_seen']['context_mode'] ?? null )
		&& 'disposition-demo' === ( $GLOBALS['ssi_seen']['input_slug'] ?? null ),
	'consumer-received-context',
	'consumer receives the import context and normalized input'
);

// --- Report. ---
if ( empty( $failures ) ) {
	echo 'PASS: smoke-import-disposition (' . (int) $assertions . " assertions)\n";
	exit( 0);
}

echo 'FAILURES (' . count( $failures ) . ' of ' . (int) $assertions . " assertions):\n";
foreach ( $failures as $failure ) {
	echo ' - ' . $failure . "\n";
}
exit( 1 );
