<?php
/**
 * Scoped CSS selector and section ownership regression tests.
 *
 * @package StaticSiteImporter
 */
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-existing-theme-styles.php';
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$diagnostics = array();
$source = 'html body h1,body>nav,:root{color:red}*{box-sizing:border-box}header,nav,footer,h1{color:blue}@media(min-width:700px){body h1{color:green}}@supports(display:grid){main{display:grid}}@container(min-width:300px){h1{color:red}}';
$css = Static_Site_Importer_Existing_Theme_Styles::scope( $source, '.page-test', $diagnostics );
$assert( str_contains( $css, '.page-test h1,.page-test>nav,.page-test{' ), 'root aliases and descendants target the page root' );
$assert( str_contains( $css, '.page-test,.page-test *{' ), 'document universal selector includes only root and descendants' );
$assert( str_contains( $css, '.page-test header,.page-test nav,.page-test footer,.page-test h1{' ), 'bare elements are contained' );
$assert( str_contains( $css, '@media(min-width:700px){.page-test h1{color:green}}' ), 'arbitrary media condition retained' );
$assert( str_contains( $css, '@supports(display:grid){.page-test main{display:grid}}' ) && str_contains( $css, '@container(min-width:300px){.page-test h1{color:red}}' ), 'supports and container retained' );
$assert( array() === $diagnostics, 'ordinary conditional CSS is lossless' );
$css = Static_Site_Importer_Existing_Theme_Styles::scope( '@import "remote.css";@font-face{font-family:test;src:url(font.woff2)}@keyframes pulse{to{opacity:0}}h1{color:red}', '.page-test', $diagnostics );
$assert( ! str_contains( $css, '@import' ) && ! str_contains( $css, '@font-face' ) && ! str_contains( $css, '@keyframes' ) && str_contains( $css, '.page-test h1{' ), 'unsupported global registrations omitted without losing following style rules' );
$assert( count( $diagnostics ) >= 3, 'unsupported constructs are diagnosed' );
$css = Static_Site_Importer_Existing_Theme_Styles::scope( 'body + header{color:red}body{& ~ footer{color:blue}}', '.page-test', $diagnostics );
$assert( ! str_contains( $css, '.page-test + header' ) && ! str_contains( $css, '& ~ footer' ), 'sibling and nested selectors cannot escape the root' );
$css = Static_Site_Importer_Existing_Theme_Styles::scope( 'h1[data-title="{,}"]{content:"}"}', '.page-test', $diagnostics );
$assert( str_contains( $css, '.page-test h1[data-title="{,}"]{content:"}"}' ), 'strings and attribute selector commas do not confuse the parser' );
$owner = "/* owner CSS */\n.x { color:red; }\n";
$one = Static_Site_Importer_Existing_Theme_Styles::replace_section( $owner, 'one', 'first' );
$two = Static_Site_Importer_Existing_Theme_Styles::replace_section( $one, 'two', 'second' );
$replaced = Static_Site_Importer_Existing_Theme_Styles::replace_section( $two, 'one', 'replacement' );
$assert( str_starts_with( $replaced, $owner ) && 1 === substr_count( $replaced, ':one:start' ) && str_contains( $replaced, 'second' ), 'replace only one import section' );
$assert( Static_Site_Importer_Existing_Theme_Styles::replace_section( $replaced, 'one', '' ) === Static_Site_Importer_Existing_Theme_Styles::replace_section( $owner, 'two', 'second' ), 'removal leaves owner bytes and other import section identical' );
echo "Existing-theme CSS smoke passed.\n";
