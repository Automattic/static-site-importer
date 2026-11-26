<?php
/**
 * A form's inline icon resolves in the page's post content without media.
 *
 * Blocks Engine keeps a form's inline SVG icon as an image asset in the
 * form's readable blocks, as a plan token. The form provider builds its
 * fragment from those blocks, and bindings patch the page's resolved markup.
 * With no Media Library rebind to resolve fragments later, the post content
 * kept the raw token and the final reference check rejected the page.
 *
 * Run: php tests/smoke-form-icon-post-content.php
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-prepared-plan-application.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$failures = array();
$check    = static function ( bool $condition, string $name, string $detail = '' ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $name . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$plan        = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-inline-icon.html' ) ),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$row         = Static_Site_Importer_Form_Seeder::seed( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) ) )['forms'][0];
$check( 1 === preg_match( '~\{\{wordpress-site-plan:asset:([^}]+)\}\}~', $row['block_markup'], $icon ), 'the-provider-form-carries-the-icon-token', $row['block_markup'] );
$target = array_values( array_filter( $plan['reference_tokens'], static fn( array $token ): bool => $icon[1] === $token['token'] ) )[0]['target_path'] ?? '';

// The page's resolved markup, as the resolver binds it to this destination.
$theme_uri = 'https://example.test/wp-content/themes/imported';
$page      = WordPressSitePlanResolver::resolveMarkup( $plan['pages'][0]['canonical_block_markup'], WordPressSitePlanResolver::references( $plan['reference_tokens'], $theme_uri ) );
$anchor    = $declaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] ?? '';
$check( '' !== $anchor && 1 === substr_count( $page, $anchor ), 'the-binding-anchor-is-in-the-page' );

$binding = array(
	'search_block_markup'      => $anchor,
	'replacement_block_markup' => $row['block_markup'],
	'materialized_block_hash'  => hash( 'sha256', $row['block_markup'] ),
);
$resolve = new ReflectionMethod( Static_Site_Importer_Prepared_Plan_Application::class, 'resolve_binding_asset_references' );
$bound   = $resolve->invoke( null, array( $binding ), $plan, array( 'resolution' => array( 'theme_uri' => $theme_uri ) ) )[0];
$content = str_replace( $anchor, $bound['replacement_block_markup'], $page );
$error   = Static_Site_Importer_Site_Plan_Persistence::validate_output_content( $content, 'post:1' );
$check( null === $error, 'post-content-holds-no-unresolved-reference', null === $error ? '' : 'rejected' );
$check( '' !== $target && str_contains( $content, 'src="' . $theme_uri . '/' . $target . '"' ), 'the-icon-is-the-theme-file-url', $content );
$check( hash( 'sha256', $bound['replacement_block_markup'] ) === $bound['materialized_block_hash'], 'the-proof-hash-follows-the-resolved-fragment' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "PASS: form icon in post content\n";
