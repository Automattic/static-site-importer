<?php
/**
 * Projects normalized static artifacts into inert data for SSI's classic scaffold.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'Static_Site_Importer_Document' ) ) {
	require_once __DIR__ . '/class-static-site-importer-document.php';
}

final class Static_Site_Importer_Classic_Theme_Projection {
	/** Build render-neutral source fragments before compiler block conversion. */
	public static function build( array $artifact, array $plan ) {
		$files = self::files( $artifact );
		$pages = array();
		$chrome = array( 'header' => '', 'footer' => '', 'background' => '' );
		$entry = (string) ( $artifact['entrypoint'] ?? $plan['source']['entry_path'] ?? '' );
		foreach ( $plan['pages'] ?? array() as $page ) {
			$path = (string) ( $page['source_path'] ?? '' );
			if ( ! isset( $files[ $path ] ) ) {
				return new WP_Error( 'static_site_importer_classic_source_document_missing', 'The normalized artifact is missing a compiler-declared page source document.', array( 'source_path' => $path ) );
			}
			$fragments = ( new Static_Site_Importer_Document( $files[ $path ] ) )->fragments();
			$pages[ $path ] = array( 'html' => self::sanitize_html( $fragments['background'] . "\n" . $fragments['main'] ), 'source_path' => $path );
			if ( $path === $entry ) {
				$chrome = array_map( array( self::class, 'sanitize_html' ), array_intersect_key( $fragments, array_flip( array( 'header', 'footer', 'background' ) ) ) );
			}
		}
		$css = array();
		foreach ( $files as $path => $content ) {
			if ( str_ends_with( strtolower( $path ), '.css' ) ) { $css[ $path ] = $content; }
		}
		ksort( $pages, SORT_STRING ); ksort( $css, SORT_STRING );
		return array( 'schema' => 'static-site-importer/classic-theme-projection/v1', 'pages' => $pages, 'chrome' => $chrome, 'chrome_source_path' => $entry, 'stylesheets' => $css, 'bindings' => array() );
	}

	/** Convert inert fragments into destination-specific writes after the canonical resolver runs. */
	public static function writes( array $projection, array $resolved, string $theme_uri, string $name ): array {
		$urls = self::destination_urls( $resolved, $theme_uri ); $routes = array();
		foreach ( $resolved['pages'] ?? array() as $page ) { $routes[(string) ( $page['source_path'] ?? '' )] = (string) ( $page['route']['path'] ?? '' ); }
		$pages = array(); foreach ( $projection['pages'] ?? array() as $source => $page ) { $pages[ $source ] = array( 'html' => self::rewrite_urls( (string) ( $page['html'] ?? '' ), (string) $source, $urls, $routes ) ); }
		$chrome_source = (string) ( $projection['chrome_source_path'] ?? '' );
		$chrome = array(); foreach ( $projection['chrome'] ?? array() as $part => $html ) { $chrome[ $part ] = self::rewrite_urls( (string) $html, $chrome_source, $urls, $routes ); }
		$css = ''; foreach ( $projection['stylesheets'] ?? array() as $source => $stylesheet ) { $css .= self::rewrite_css( (string) $stylesheet, (string) $source, $urls ) . "\n"; }
		$scaffold = Static_Site_Importer_Theme_Materialization_Strategy::fixed_classic_scaffold( $name );
		$scaffold['style.css'] .= "\n" . $css;
		$scaffold['classic-pages.json'] = (string) wp_json_encode( array( 'pages' => $pages ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$scaffold['classic-chrome.json'] = (string) wp_json_encode( $chrome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$scaffold['classic-bindings.json'] = (string) wp_json_encode( self::binding_records( $projection ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$writes = array();
		foreach ( $scaffold as $path => $content ) { $writes[] = array( 'target_path' => $path, 'source_path' => 'static-site-importer/classic-scaffold/' . $path, 'kind' => 'theme_scaffold', 'payload' => array( 'encoding' => 'utf8', 'data' => $content ), 'payload_hash' => hash( 'sha256', $content ), 'reconciliation_identity' => hash( 'sha256', "static-site-importer/classic-scaffold/v1\n" . $path ) ); }
		return $writes;
	}

	/** Replace block-theme presentation writes but retain every resolved asset write. */
	public static function resolved_writes( array $resolved, array $classic_writes ): array { return array_merge( array_values( array_filter( $resolved['writes'] ?? array(), static fn( array $write ): bool => 'theme_asset' === ( $write['kind'] ?? '' ) ) ), $classic_writes ); }
	/** Rebuild only classic scaffold data payloads after provider substitutions. */
	public static function with_projection_writes( array $resolved, array $projection, string $theme_uri, string $name ): array { $resolved['writes'] = self::resolved_writes( $resolved, self::writes( $projection, $resolved, $theme_uri, $name ) ); return $resolved; }

	/** Apply provider output through exact source selectors before classic data writes. */
	public static function apply_runtime_bindings( array $projection, array $bindings ) {
		$result = self::transaction( $projection, $bindings, true );
		if ( is_wp_error( $result ) ) { return $result; }
		foreach ( $result as $identity => $html ) { if ( str_starts_with( $identity, 'chrome:' ) ) { $projection['chrome'][ substr( $identity, 7 ) ] = $html; } else { $projection['pages'][ $identity ]['html'] = $html; } }
		foreach ( $bindings as $binding ) { $id = (string) ( $binding['reconciliation_identity'] ?? '' ); $token = '<!--static-site-importer-binding:' . $id . '-->'; foreach ( $projection['chrome'] ?? array() as $surface => $html ) { if ( is_string( $html ) && str_contains( $html, $token ) ) { $binding['surface'] = $surface; } } if ( ! isset( $binding['surface'] ) ) { $binding['surface'] = 'page'; } $projection['bindings'][] = $binding; }
		return $projection;
	}

	/** Resolve all declared identities without mutating the projection. */
	public static function preflight_bindings( array $projection, array $bindings ) {
		$result = self::transaction( $projection, $bindings, false );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Run the same ordered mutation transaction for preflight and final projection. */
	private static function transaction( array $projection, array $bindings, bool $final ) {
		foreach ( $bindings as $left_index => $left ) { foreach ( $bindings as $right_index => $right ) { if ( $right_index <= $left_index || ( $left['source_path'] ?? '' ) !== ( $right['source_path'] ?? '' ) ) { continue; } $a = trim( (string) ( $left['selector'] ?? '' ) ); $b = trim( (string) ( $right['selector'] ?? '' ) ); if ( '' !== $a && '' !== $b && ( $a === $b || str_starts_with( $a, $b . ' ') || str_starts_with( $a, $b . '>') || str_starts_with( $b, $a . ' ') || str_starts_with( $b, $a . '>') ) ) { return new WP_Error( 'static_site_importer_classic_html_binding_overlap', 'Classic binding selectors may not claim equivalent or ancestor/descendant source nodes.' ); } } }
		$documents = array();
		foreach ( $bindings as $binding ) {
			$source = (string) ( $binding['source_path'] ?? '' ); $surface = (string) ( $binding['surface'] ?? '' );
			$selector = $binding['selector'] ?? null; $occurrence = $binding['occurrence'] ?? null;
			if ( ! is_string( $selector ) || ! is_int( $occurrence ) || $occurrence < 1 || ( $final && ( ! is_string( $binding['replacement_html'] ?? null ) || ! is_array( $binding['render'] ?? null ) ) ) ) { return new WP_Error( 'static_site_importer_classic_html_binding_invalid', 'Classic runtime binding is missing its exact source selector and adapter render contract.' ); }
			$query = self::selector_xpath( $selector ); if ( '' === $query ) { return new WP_Error( 'static_site_importer_classic_html_binding_selector_invalid', 'Classic runtime bindings require bounded source-owned selectors.' ); }
			$candidates = '' === $surface ? array( 'page', 'header', 'footer' ) : array( $surface ); $matches = array();
			foreach ( $candidates as $candidate ) { $identity = 'page' === $candidate ? $source : 'chrome:' . $candidate; $html = 'page' === $candidate ? (string) ( $projection['pages'][ $source ]['html'] ?? '' ) : (string) ( $projection['chrome'][ $candidate ] ?? '' ); if ( '' === $html ) { continue; } if ( ! isset( $documents[ $identity ] ) ) { $documents[ $identity ] = self::dom( $html ); } $root = $documents[ $identity ]->getElementById( 'ssi-classic-root' ); $nodes = ( new DOMXPath( $documents[ $identity ] ) )->query( $query, $root ); if ( false !== $nodes && $nodes->length === $occurrence ) { $matches[] = array( $identity, $nodes ); } }
			if ( 1 !== count( $matches ) ) { return new WP_Error( 'static_site_importer_classic_html_binding_cardinality_mismatch', 'Classic runtime binding must resolve exactly one page or chrome surface.', array( 'selector' => $selector, 'surface' => $surface ) ); }
			$identity = $matches[0][0]; $nodes = $matches[0][1]; $dom = $documents[ $identity ]; $root = $dom->getElementById( 'ssi-classic-root' );
			$replacement = $final ? $binding['replacement_html'] : '<div data-static-site-importer-preflight="1" />'; $fragment = $dom->createDocumentFragment();
			if ( ! $fragment->appendXML( $replacement ) ) { return new WP_Error( 'static_site_importer_classic_html_binding_replacement_invalid', 'Classic runtime binding replacement is not valid fixed provider markup.' ); }
			$target = $nodes->item( $occurrence - 1 ); if ( ! $target || $target === $root || ! $target->parentNode ) { return new WP_Error( 'static_site_importer_classic_html_binding_reserved_target', 'Classic bindings may only replace source-owned descendants.' ); }
			$target->parentNode->replaceChild( $fragment, $target );
		}
		$output = array(); foreach ( $documents as $source => $dom ) { $output[ $source ] = self::root_html( $dom ); } return $output;
	}

	private static function dom( string $html ): DOMDocument { $dom = new DOMDocument( '1.0', 'UTF-8' ); $previous = libxml_use_internal_errors( true ); $dom->loadHTML( '<div id="ssi-classic-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); libxml_clear_errors(); libxml_use_internal_errors( $previous ); return $dom; }

	private static function selector_xpath( string $selector ): string {
		if ( preg_match( '/(?:ssi-classic-root|static-site-importer)/i', $selector ) ) { return ''; }
		$tokens = preg_split( '/\s*(>)\s*|\s+/', trim( $selector ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ); if ( empty( $tokens ) ) { return ''; }
		$xpath = ''; $child = false;
		foreach ( $tokens as $token ) {
			if ( '>' === $token ) { if ( $child ) { return ''; } $child = true; continue; }
			if ( ! preg_match( '/^([a-z][a-z0-9-]*)?(?:#([A-Za-z][A-Za-z0-9_-]{0,79}))?(?:\.([A-Za-z][A-Za-z0-9_-]{0,79}))?(?::nth-child\(([1-9][0-9]*)\))?$/', $token, $m ) || '' === $token ) { return ''; }
			$xpath .= '' === $xpath ? './/*' : ( $child ? '/*' : '//*' ); $child = false;
			if ( '' !== ( $m[1] ?? '' ) ) { $xpath .= '[local-name()="' . $m[1] . '"]'; }
			if ( '' !== ( $m[2] ?? '' ) ) { $xpath .= '[@id="' . $m[2] . '"]'; }
			if ( '' !== ( $m[3] ?? '' ) ) { $xpath .= '[contains(concat(" ", normalize-space(@class), " "), " ' . $m[3] . ' ")]'; }
			if ( '' !== ( $m[4] ?? '' ) ) { $xpath .= '[count(preceding-sibling::*)=' . ( (int) $m[4] - 1 ) . ']'; }
		}
		return $child ? '' : $xpath;
	}

	/** Remove executable elements and attributes. URL/CSS checks operate on canonicalized values. */
	private static function sanitize_html( string $html ): string {
		$dom = new DOMDocument( '1.0', 'UTF-8' ); $previous = libxml_use_internal_errors( true ); $dom->loadHTML( '<div id="ssi-classic-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
		foreach ( iterator_to_array( $dom->getElementsByTagName( '*' ) ) as $element ) {
			$tag = strtolower( $element->tagName ); if ( in_array( $tag, array( 'script', 'iframe', 'object', 'embed', 'base', 'meta' ), true ) || self::unsafe_svg_animation( $tag ) || ( 'style' === $tag && ! self::safe_css( $element->textContent ) ) ) { $element->parentNode?->removeChild( $element ); continue; }
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) { $name = strtolower( $attribute->name ); if ( str_starts_with( $name, 'on' ) || 'srcdoc' === $name || ( 'style' === $name && ! self::safe_css( $attribute->value ) ) ) { $element->removeAttributeNode( $attribute ); continue; } if ( in_array( $name, array( 'href', 'src', 'action', 'formaction', 'poster', 'xlink:href' ), true ) && ! self::safe_url( $attribute->value ) ) { $element->setAttribute( $attribute->name, '#' ); } if ( 'srcset' === $name ) { $element->setAttribute( 'srcset', self::safe_srcset( $attribute->value ) ); } }
		}
		$html = self::root_html( $dom );
		// Artifact comments must never impersonate SSI-created structured slots.
		return (string) preg_replace( '/<!--\s*static-site-importer-binding:.*?-->/is', '', $html );
	}

	/** @return array<string,string> */
	private static function files( array $artifact ): array { $files = array(); foreach ( $artifact['files'] ?? array() as $key => $file ) { if ( is_string( $file ) ) { $files[(string) $key] = $file; continue; } if ( ! is_array( $file ) || ! isset( $file['path'] ) ) { continue; } $content = isset( $file['content'] ) ? (string) $file['content'] : ( isset( $file['content_base64'] ) ? base64_decode( (string) $file['content_base64'], true ) : '' ); if ( is_string( $content ) ) { $files[(string) $file['path']] = $content; } } return $files; }
	/** @return array<string,string> */
	private static function destination_urls( array $resolved, string $theme_uri ): array { $urls = array(); foreach ( $resolved['writes'] ?? array() as $write ) { $source = (string) ( $write['source_path'] ?? '' ); $target = (string) ( $write['target_path'] ?? '' ); if ( '' !== $source && '' !== $target && 'theme_asset' === ( $write['kind'] ?? '' ) ) { $urls[ trim( $source, '/' ) ] = rtrim( $theme_uri, '/' ) . '/' . ltrim( $target, '/' ); } } return $urls; }
	private static function root_html( DOMDocument $dom ): string { $html = ''; $root = $dom->getElementById( 'ssi-classic-root' ); foreach ( iterator_to_array( $root?->childNodes ?? array() ) as $node ) { $html .= $dom->saveHTML( $node ); } return $html; }
	private static function rewrite_urls( string $content, string $source, array $assets, array $routes ): string { $dir = trim( dirname( $source ), '/' ); $dom = new DOMDocument( '1.0', 'UTF-8' ); $previous = libxml_use_internal_errors( true ); $dom->loadHTML( '<div id="ssi-classic-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); libxml_clear_errors(); libxml_use_internal_errors( $previous ); foreach ( iterator_to_array( $dom->getElementsByTagName( '*' ) ) as $element ) { foreach ( array( 'src', 'href', 'action', 'formaction', 'poster', 'xlink:href' ) as $attribute ) { if ( $element->hasAttribute( $attribute ) ) { $element->setAttribute( $attribute, self::replacement( $element->getAttribute( $attribute ), $dir, $assets, $routes ) ); } } if ( $element->hasAttribute( 'srcset' ) ) { $element->setAttribute( 'srcset', self::rewrite_srcset( $element->getAttribute( 'srcset' ), $dir, $assets, $routes ) ); } if ( $element->hasAttribute( 'style' ) ) { $element->setAttribute( 'style', self::rewrite_css( $element->getAttribute( 'style' ), $source, $assets ) ); } } return self::root_html( $dom ); }
	private static function replacement( string $url, string $dir, array $assets, array $routes = array() ): string { if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url ) ) { return $url; } $parts = preg_split( '/([?#].*)/', $url, 2, PREG_SPLIT_DELIM_CAPTURE ); $path = $parts[0] ?? ''; $suffix = $parts[1] ?? ''; $key = self::artifact_path( $path, $dir ); if ( null === $key ) { return '#'; } return ( $assets[ $key ] ?? $routes[ $key ] ?? $url ) . ( isset( $assets[ $key ] ) || isset( $routes[ $key ] ) ? $suffix : '' ); }
	/** Resolve a relative artifact reference without permitting traversal above root. */
	private static function artifact_path( string $path, string $dir ): ?string { $segments = str_starts_with( $path, '/' ) || '.' === $dir ? array() : array_filter( explode( '/', trim( $dir, '/' ) ), 'strlen' ); foreach ( explode( '/', $path ) as $segment ) { if ( '' === $segment || '.' === $segment ) { continue; } if ( '..' === $segment ) { if ( empty( $segments ) ) { return null; } array_pop( $segments ); continue; } $segments[] = $segment; } return implode( '/', $segments ); }
	private static function canonical_url( string $value ): string { $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 ); for ( $i = 0; $i < 3; ++$i ) { $decoded = rawurldecode( $value ); if ( $decoded === $value ) { break; } $value = $decoded; } $value = (string) preg_replace( '#/\*.*?\*/#s', '', $value ); $value = preg_replace_callback( '/\\\\(?:([0-9a-f]{1,6})\s?|(.))/is', static function ( array $m ): string { return '' !== ( $m[1] ?? '' ) ? html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES | ENT_HTML5 ) : (string) ( $m[2] ?? '' ); }, $value ) ?? ''; return strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', $value ) ?? '' ); }
	private static function safe_url( string $url ): bool { return ! preg_match( '/^(?:javascript|vbscript|data:text\/html)/i', self::canonical_url( $url ) ); }
	private static function safe_srcset( string $srcset ): string { return implode( ', ', array_filter( array_map( static fn( string $candidate ): string => self::safe_url( (string) ( preg_split( '/\s+/', trim( $candidate ), 2 )[0] ?? '' ) ) ? trim( $candidate ) : '', explode( ',', $srcset ) ) ) ); }
	private static function rewrite_srcset( string $srcset, string $dir, array $assets, array $routes ): string { return implode( ', ', array_filter( array_map( static function ( string $candidate ) use ( $dir, $assets, $routes ): string { $parts = preg_split( '/\s+/', trim( $candidate ), 2 ); return self::safe_url( $parts[0] ?? '' ) ? self::replacement( $parts[0] ?? '', $dir, $assets, $routes ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) : ''; }, explode( ',', $srcset ) ) ) ); }
	private static function safe_css( string $css ): bool { return ! preg_match( '/(?:expression\(|behavior\s*:|-moz-binding|@import|url\([^)]*(?:javascript|vbscript|data:text\/html))/', self::canonical_url( $css ) ); }
	/** Classic output supports static SVG only; SMIL may mutate URL-bearing attributes at runtime. */
	private static function unsafe_svg_animation( string $tag ): bool { return in_array( preg_replace( '/^.*:/', '', strtolower( $tag ) ) ?? '', array( 'animate', 'animatemotion', 'animatetransform', 'set', 'discard', 'mpath' ), true ); }
	private static function safe_stylesheet( string $css ): bool { return ! preg_match( '/(?:expression\(|behavior\s*:|-moz-binding|(?:@import|url\([^)]*)[^;)]*(?:javascript|vbscript|data:text\/html))/', self::canonical_url( $css ) ); }
	private static function rewrite_css( string $css, string $source, array $assets ): string { if ( ! self::safe_stylesheet( $css ) ) { return ''; } $dir = trim( dirname( $source ), '/' ); $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css ); $css = (string) preg_replace( '/^[ \t]*(?:Theme Name|Theme URI|Description|Author|Author URI|Version|Template|Status|Tags|Text Domain|Domain Path|Requires at least|Requires PHP|Update URI)\s*:/im', '/* static-site-importer-theme-header-stripped */', $css ); $css = (string) preg_replace_callback( '/@import\s+(?:url\()?\s*(["\']?)([^\)"\';]+)\1\s*\)?\s*;/i', static function ( array $match ) use ( $dir, $assets ): string { $url = self::replacement( $match[2], $dir, $assets ); return self::safe_url( $match[2] ) && '#' !== $url ? '@import url("' . $url . '");' : ''; }, $css ); return (string) preg_replace_callback( '/url\(\s*(["\']?)([^\)"\']+)\1\s*\)/i', static function ( array $match ) use ( $dir, $assets ): string { $url = self::replacement( $match[2], $dir, $assets ); return self::safe_url( $match[2] ) && '#' !== $url ? 'url("' . $url . '")' : 'url("")'; }, $css ); }
	private static function binding_records( array $projection ): array { $records = array(); foreach ( $projection['bindings'] ?? array() as $binding ) { $id = is_array( $binding ) ? (string) ( $binding['reconciliation_identity'] ?? '' ) : ''; $render = is_array( $binding ) ? ( $binding['render'] ?? null ) : null; $source = is_array( $binding ) ? (string) ( $binding['source_path'] ?? '' ) : ''; $surface = is_array( $binding ) ? (string) ( $binding['surface'] ?? 'page' ) : 'page'; $content = 'page' === $surface ? ( $projection['pages'][ $source ]['html'] ?? '' ) : ( $projection['chrome'][ $surface ] ?? '' ); if ( preg_match( '/^[a-f0-9]{64}$/', $id ) && is_array( $render ) && in_array( $render['kind'] ?? null, array( 'shortcode', 'blocks' ), true ) && is_string( $render['content'] ?? null ) && is_string( $content ) ) { $records[ $id ] = array( 'kind' => $render['kind'], 'content' => $render['content'], 'source_path' => $source, 'surface' => $surface, 'page_hash' => hash( 'sha256', $content ) ); } } ksort( $records, SORT_STRING ); return $records; }
}
