<?php
/**
 * Router for the retained source server: serves each page with its shared
 * parts expanded, so outcome probes compare against the document a visitor saw.
 * Directive grammar and bounds come from Blocks Engine's artifact includes.
 *
 * This is a `php -S` router for local outcome probes, not WordPress code: it
 * runs without WordPress, reads local files and serves the source page's own
 * markup unescaped by design.
 *
 * phpcs:disable WordPress.Security.EscapeOutput, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\HtmlFragmentIncludes;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root = realpath( $_SERVER['DOCUMENT_ROOT'] );
$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$file = realpath( $root . $path . ( str_ends_with( $path, '/' ) ? 'index.html' : '' ) );
if ( false === $file || ! str_starts_with( $file, $root . DIRECTORY_SEPARATOR ) || ! preg_match( '/\.html?$/i', $file ) ) {
	return false;
}

$expand = static function ( string $html, string $name, int $depth ) use ( &$expand, $root ): string {
	if ( $depth > HtmlFragmentIncludes::MAX_DEPTH ) {
		throw new InvalidArgumentException( 'html_include_depth_exceeded: ' . $name );
	}
	foreach ( array_reverse( HtmlFragmentIncludes::directives( $html, $name ) ) as $directive ) {
		$part = realpath( $root . $directive['virtual'] );
		if ( false === $part || ! str_starts_with( $part, $root . DIRECTORY_SEPARATOR ) ) {
			throw new InvalidArgumentException( 'html_include_missing_path: ' . $directive['virtual'] );
		}
		$html = substr_replace( $html, $expand( (string) file_get_contents( $part ), $directive['virtual'], $depth + 1 ), $directive['offset'], $directive['length'] );
	}
	return $html;
};

header( 'Content-Type: text/html; charset=utf-8' );
echo $expand( (string) file_get_contents( $file ), $path, 0 );
