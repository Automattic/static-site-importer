<?php
/**
 * A form in shared chrome materializes as a provider form in its template part.
 *
 * Pages that share a footer carrying the same newsletter form compile to one
 * `parts/footer.html`; the producer keeps one form entity, rebases it onto the
 * part's `source_path` and names its source fallback by `fallback_identity`.
 * The consumer must join that entity to its readable fallback tree by the
 * identity, not by the page path the entity no longer carries, or the provider
 * declines it as `incomplete_readable_form_tree` and every page's form fallback
 * stays unresolved.
 *
 * Run from the repository root:
 * php tests/smoke-shared-part-form.php
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach ( array( 'index.html', 'about.html', 'contact.html', 'site.css' ) as $name ) {
	$files[ 'website/' . $name ] = file_get_contents( __DIR__ . '/fixtures/shared-footer-form/' . $name );
}
$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'website/index.html', 'files' => $files ) )->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'];

$part  = current( array_filter( $plan['template_parts'] ?? array(), static fn( array $part ): bool => 'footer' === ( $part['slug'] ?? null ) ) );
$forms = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$page_fallbacks = array_values( array_filter( $plan['quality']['fallbacks'] ?? array(), static fn( array $fallback ): bool => 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? '' ) ) );
$checks = array(
	'producer emits one shared footer part'      => is_array( $part ) && 'wordpress-site-plan/shared/footer#footer' === $part['source_path'],
	'producer keeps one entity, rebased on part' => is_array( $forms ) && 1 === count( $forms['payload']['entities'] ) && 'wordpress-site-plan/shared/footer#footer' === $forms['payload']['entities'][0]['source_path'],
	'each page keeps its own form fallback'      => 3 === count( $page_fallbacks ) && 3 === count( array_unique( array_column( $page_fallbacks, 'source' ) ) ),
);

$entities  = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $forms['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$seeded    = Static_Site_Importer_Form_Seeder::seed( $validated );
$row       = $seeded['forms'][0] ?? array();

$checks['readable tree joins by fallback identity'] = ! empty( $entities[0]['readable_blocks'] ) && empty( $validated['errors'] );
$checks['provider admits the part form']            = 'mapped' === ( $row['status'] ?? null ) && 'incomplete_readable_form_tree' !== ( $row['reason'] ?? null ) && str_contains( (string) ( $row['block_markup'] ?? '' ), '<!-- wp:jetpack/contact-form ' );
foreach ( array( 'wp:jetpack/field-email', 'wp:jetpack/field-checkbox', 'Sign Up' ) as $needle ) {
	$checks[ 'provider form carries ' . $needle ] = str_contains( (string) ( $row['block_markup'] ?? '' ), $needle );
}

// Apply the canonical binding to the compiled part document, as the import does.
$binding = $forms['payload']['entities'][0]['bindings'][0] ?? array();
$markup  = (string) ( $part['canonical_block_markup'] ?? '' );
$anchor  = (string) ( $binding['search_block_markup'] ?? '' );
$checks['binding names the part']           = 'wordpress-site-plan/shared/footer#footer' === ( $binding['source_path'] ?? null ) && '' !== $anchor && 1 === substr_count( $markup, $anchor );
$materialized                               = str_replace( $anchor, (string) ( $row['block_markup'] ?? '' ), $markup );
$checks['part holds exactly one provider form'] = 1 === substr_count( $materialized, '<!-- wp:jetpack/contact-form ' );

// The one replacement answers for every page's source fallback.
$replaced = $validated['forms'][0]['replaced_fallback_identities'] ?? array();
sort( $replaced );
$expected = array_column( $page_fallbacks, 'fallback_identity' );
sort( $expected );
$checks['replacement covers every page fallback'] = array() !== $expected && $expected === $replaced;

$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( '; ', $failed ) . "\n" . json_encode( array( 'status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null ) ) . "\n" );
	exit( 1 );
}
echo 'shared-part-form: ' . count( $checks ) . " checks ok\n";
