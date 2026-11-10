<?php
/**
 * Responsive source form item sizing follows the provider wrapper.
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

$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-responsive-item-sizing.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row         = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$overlay     = (string) ( $row['provider_layout_overlay_css']['css'] ?? '' );
$base        = '';
$mobile      = '';
foreach ( explode( "\n", $overlay ) as $rule ) {
	if ( ! str_contains( $rule, '.jetpack-contact-form-container' ) ) {
		continue;
	}
	if ( str_starts_with( $rule, '@media (max-width: 809.98px)' ) ) {
		$mobile .= $rule;
	} elseif ( ! str_starts_with( $rule, '@' ) ) {
		$base .= $rule;
	}
}
$checks = array(
	'form maps through the provider' => 'mapped' === $row['status'] && true === ( $row['runtime_mapped'] ?? false ),
	'desktop wrapper retains source flex sizing' => (bool) preg_match( '/flex:1 0 0(?:px)?[;}]/', $base ),
	'mobile wrapper gets source flex override' => str_contains( $mobile, 'flex:0 0 auto' ),
	'mobile wrapper gets source full width' => str_contains( $mobile, 'width:100%' ),
	'mobile sizing stays conditional' => ! str_contains( $base, 'flex:0 0 auto' ) && ! str_contains( $base, 'width:100%' ),
);
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
