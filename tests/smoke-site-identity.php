<?php
/**
 * Smoke test: site-identity primitive priority chain.
 *
 * Run from the repository root:
 * php tests/smoke-site-identity.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( (string) preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( $title ) ), '-' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return isset( $GLOBALS['ssi_identity_filters'][ $hook ] ) ? $GLOBALS['ssi_identity_filters'][ $hook ]( $value, ...$args ) : $value;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-identity.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// 1. Explicit site_title wins over everything.
$identity = Static_Site_Importer_Site_Identity::resolve(
	array(
		'site_title' => 'Explicit Brand',
		'html'       => '<title>Document Title</title>',
		'url'        => 'https://maya-devon.example/',
	)
);
$assert( 'Explicit Brand' === $identity['name'], 'explicit-site-title-wins-name', $identity['name'] );
$assert( 'explicit-brand' === $identity['slug'], 'explicit-site-title-derives-slug', $identity['slug'] );
$assert( $identity['title'] === $identity['name'], 'title-mirrors-name' );

// 2. Document title (suffix stripped) used when no explicit name.
$identity = Static_Site_Importer_Site_Identity::resolve(
	array(
		'html' => '<!doctype html><head><title>Maya &amp; Devon &#8212; Home</title></head>',
		'url'  => 'https://fallback.example/',
	)
);
$assert( 'Maya & Devon' === $identity['name'], 'document-title-strips-suffix-and-decodes', $identity['name'] );
$assert( 'maya-devon' === $identity['slug'], 'document-title-derives-slug', $identity['slug'] );

// 3. Website-artifact entrypoint title resolves identically to raw HTML.
$identity = Static_Site_Importer_Site_Identity::resolve(
	array(
		'artifact' => array(
			'entrypoint' => 'website/index.html',
			'files'      => array(
				array(
					'path'    => 'website/index.html',
					'content' => '<html><head><title>Northline Plumbing | Grand Rapids</title></head></html>',
				),
			),
		),
	)
);
$assert( 'Northline Plumbing' === $identity['name'], 'artifact-entrypoint-title-strips-pipe-suffix', $identity['name'] );

// 4. Artifact paths normalize consistently for matching and export callers.
$route_paths = array(
	'website/index.html?cache=1'              => 'website/index.html',
	'/website\\nested\\..\\Index.HTML'       => 'website/Index.HTML',
	'website/./nested/../Case/Keep.HTML'      => 'website/Case/Keep.HTML',
	'../../website/index.html'                 => 'website/index.html',
	''                                        => '',
	'/'                                       => '',
);
foreach ( $route_paths as $path => $expected ) {
	$assert( $expected === Static_Site_Importer_Site_Identity::normalize_route_path( $path ), 'normalize-route-path-' . md5( $path ), $path );
}

// 5. URL host fallback (minus www.) when no title is available.
$identity = Static_Site_Importer_Site_Identity::resolve(
	array(
		'url' => 'https://www.Acme-Co.example/path?x=1',
	)
);
$assert( 'acme-co.example' === $identity['name'], 'host-fallback-drops-www-and-lowercases', $identity['name'] );

// 6. Generic constant only as a last resort.
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'html' => '<main>No title here</main>' ) );
$assert( Static_Site_Importer_Site_Identity::DEFAULT_NAME === $identity['name'], 'constant-last-resort-name', $identity['name'] );
$assert( Static_Site_Importer_Site_Identity::DEFAULT_SLUG === $identity['slug'], 'constant-last-resort-slug', $identity['slug'] );

// 7. Explicit slug override wins over the name-derived slug.
$identity = Static_Site_Importer_Site_Identity::resolve(
	array(
		'name' => 'Maya & Devon',
		'slug' => 'Custom Slug Override',
	)
);
$assert( 'custom-slug-override' === $identity['slug'], 'explicit-slug-override-wins', $identity['slug'] );
$assert( 'Maya & Devon' === $identity['name'], 'explicit-name-preserved-with-slug-override', $identity['name'] );

// 8. Shared suffix-strip handles em-dash, en-dash, pipe, and hyphen separators.
$assert( 'Maya & Devon' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'Maya & Devon — Home' ), 'strip-em-dash' );
$assert( 'Maya & Devon' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'Maya & Devon – Home' ), 'strip-en-dash' );
$assert( 'Maya & Devon' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'Maya & Devon | Home' ), 'strip-pipe' );
$assert( 'Maya & Devon' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'Maya & Devon - Home' ), 'strip-hyphen' );
$assert( 'Single' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'Single' ), 'strip-no-separator-keeps-title' );
$assert( 'co-op' === Static_Site_Importer_Site_Identity::strip_title_suffix( 'co-op' ), 'strip-keeps-intra-word-hyphen' );

// 9. Uniqueness suffix appends -2, -3, ... against a taken-check.
$taken     = array( 'maya-devon' => true, 'maya-devon-2' => true );
$is_taken  = static fn ( string $slug ): bool => isset( $taken[ $slug ] );
$assert( 'maya-devon-3' === Static_Site_Importer_Site_Identity::unique_slug( 'maya-devon', $is_taken ), 'unique-slug-appends-next-free-suffix' );
$assert( 'fresh-slug' === Static_Site_Importer_Site_Identity::unique_slug( 'fresh-slug', $is_taken ), 'unique-slug-returns-desired-when-free' );
$assert( Static_Site_Importer_Site_Identity::DEFAULT_SLUG === Static_Site_Importer_Site_Identity::unique_slug( '', static fn ( string $slug ): bool => false ), 'unique-slug-falls-back-to-constant-when-empty' );

// 10. Developers can customize generated theme metadata without replacing identity resolution.
$GLOBALS['ssi_identity_filters']['static_site_importer_theme_name'] = static fn ( string $name ): string => $name . ' Custom Theme';
$GLOBALS['ssi_identity_filters']['static_site_importer_theme_slug'] = static fn ( string $slug ): string => 'custom-' . $slug;
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Inherited Site Name' ) );
$assert( 'Inherited Site Name Custom Theme' === $identity['name'], 'theme-name-filter' );
$assert( 'custom-inherited-site-name-custom-theme' === $identity['slug'], 'theme-slug-filter-sees-filtered-name' );
unset( $GLOBALS['ssi_identity_filters'] );
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'name' => 'Custom Theme Name', 'site_title' => 'Inherited Site Name' ) );
$assert( 'Custom Theme Name' === $identity['name'], 'explicit-theme-name-overrides-inherited-site-name' );

// 11. Block namespace defaults to ssi-<slug> and is filterable, sanitized, and never `core`.
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Acme Group' ) );
$assert( 'ssi-acme-group' === $identity['block_namespace'], 'block-namespace-defaults-to-ssi-slug', (string) $identity['block_namespace'] );
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Acme Group', 'slug' => 'acme' ) );
$assert( 'ssi-acme' === $identity['block_namespace'], 'block-namespace-follows-explicit-slug', (string) $identity['block_namespace'] );

$GLOBALS['ssi_identity_filters']['static_site_importer_block_namespace'] = static fn ( string $namespace, string $slug ): string => 'acme-blocks-' . $slug;
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Acme Group' ) );
$assert( 'acme-blocks-acme-group' === $identity['block_namespace'], 'block-namespace-filter-reaches-identity', (string) $identity['block_namespace'] );

$GLOBALS['ssi_identity_filters']['static_site_importer_block_namespace'] = static fn (): string => 'My Preferred NS!';
$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Acme Group' ) );
$assert( 'my-preferred-ns' === $identity['block_namespace'], 'block-namespace-filter-is-sanitized-to-valid-namespace', (string) $identity['block_namespace'] );

foreach ( array(
	'core'            => 'ssi-acme-group',
	'CORE'            => 'ssi-acme-group',
	'9starts-digit'   => 'ssi-acme-group',
	''                => 'ssi-acme-group',
	'https://evil.co' => 'https-evil-co',
) as $filtered => $expected ) {
	$GLOBALS['ssi_identity_filters']['static_site_importer_block_namespace'] = static fn (): string => $filtered;
	$identity = Static_Site_Importer_Site_Identity::resolve( array( 'site_title' => 'Acme Group' ) );
	$assert( $expected === $identity['block_namespace'], 'block-namespace-fallback-' . md5( $filtered ), (string) $identity['block_namespace'] );
}
unset( $GLOBALS['ssi_identity_filters'] );

if ( empty( $failures ) ) {
	echo 'OK: site identity smoke passed (' . (int) $assertions . " assertions)\n";
	exit( 0 );
}

echo "FAIL: site identity smoke\n";
foreach ( $failures as $failure ) {
	echo $failure . "\n";
}
exit( 1 );
