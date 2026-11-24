<?php
/**
 * A form fallback a provider resolved is not cited by the fallback gate.
 *
 * A shared footer newsletter form is materialized once as a provider form in a
 * template part. Each page's source fallback is reconciled as
 * resolved_by_provider and leaves the unsupported fallback count. The
 * `fallback_blocks` gate must then cite only the fallbacks that are still
 * unsupported, not every source form fallback the plan detected.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-fallback-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

/**
 * Assert a standalone regression contract.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 */
function provider_refs_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI assertion.
		exit( 1 );
	}
}

$form_fallback = static function ( string $id, string $source_path, string $selector, string $identity, string $intent ): array {
	return array(
		'id'                => $id,
		'type'              => 'html',
		'code'              => 'html_form_fallback',
		'reason_code'       => 'html_form_fallback',
		'reason'            => 'form_requires_runtime',
		'source_path'       => $source_path,
		'selector'          => $selector,
		'form_intent'       => $intent,
		'fallback_identity' => $identity,
		'form'              => array( 'class' => 'newsletter-form' ),
		'controls'          => array(
			array(
				'tag'  => 'input',
				'type' => 'email',
				'name' => 'email',
			),
		),
	);
};

$footer_selector  = 'footer > div > form';
$home_identity    = hash( 'sha256', 'home footer newsletter' );
$about_identity   = hash( 'sha256', 'about footer newsletter' );
$cookies_identity = hash( 'sha256', 'cookies preferences form' );
$home_form        = $form_fallback( 'diag-home-form', 'website/index.html', $footer_selector, $home_identity, 'newsletter' );
$about_form       = $form_fallback( 'diag-about-form', 'website/about/index.html', $footer_selector, $about_identity, 'newsletter' );
$cookies_form     = $form_fallback( 'diag-cookies-form', 'website/cookies/index.html', 'main > section > form', $cookies_identity, 'newsletter' );

$part_hash  = hash( 'sha256', 'parts/footer.html persisted content' );
$block_hash = hash( 'sha256', '<!-- wp:jetpack/contact-form -->newsletter<!-- /wp:jetpack/contact-form -->' );
$report     = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array(
			'fallback_count' => 3,
			'fallbacks'      => array(
				array( 'fallback_identity' => $home_identity ),
				array( 'fallback_identity' => $about_identity ),
				array( 'fallback_identity' => $cookies_identity ),
			),
		),
		'diagnostics'             => array( $home_form, $about_form, $cookies_form ),
		'materialization_receipt' => array(
			'completed' => array(
				'files'                => array(
					array(
						'target_path' => 'parts/footer.html',
						'hash'        => $part_hash,
					),
				),
				'runtime_declarations' => array(
					'entity_bindings' => array(
						array(
							'status'                           => 'completed',
							'role'                             => 'form',
							'selector'                         => $footer_selector,
							'source_path'                      => 'wordpress-site-plan/shared/footer#footer',
							'fallback_reconciliation_identity' => $home_identity,
							'fallback_hash'                    => Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $home_form ),
							'reconciliation_identity'          => hash( 'sha256', 'footer form binding' ),
							'materialized_block_hash'          => $block_hash,
							'persisted_fragment_hash'          => $block_hash,
							'materialized_content_hash'        => $part_hash,
							'provider'                         => 'jetpack',
							'template_part'                    => 'parts/footer.html',
							'replaced_fallback_identities'     => array( $home_identity, $about_identity ),
						),
					),
				),
			),
		),
	)
);

$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$states  = array();
foreach ( $report->diagnostics() as $diagnostic ) {
	if ( isset( $diagnostic['id'], $diagnostic['fallback_resolution']['state'] ) ) {
		$states[ $diagnostic['id'] ] = $diagnostic['fallback_resolution']['state'];
	}
}

provider_refs_check( 'resolved_by_provider' === ( $states['diag-home-form'] ?? '' ) && 'resolved_by_provider' === ( $states['diag-about-form'] ?? '' ), 'shared-footer-form-fallbacks-resolve-by-provider' );
provider_refs_check( 'unresolved' === ( $states['diag-cookies-form'] ?? '' ), 'unreceipted-page-form-stays-unresolved' );
provider_refs_check( 1 === ( $quality['fallback_count'] ?? -1 ) && 1 === ( $quality['unsupported_fallback_count'] ?? -1 ), 'only-unresolved-form-counts-as-unsupported-fallback' );
provider_refs_check( array( 'diag-cookies-form' ) === ( $quality['diagnostic_refs']['unsupported_fallback_count'] ?? null ), 'unsupported-fallback-refs-omit-provider-resolved-forms' );
provider_refs_check( array( 'diag-cookies-form' ) === ( $quality['diagnostic_refs']['fallback_count'] ?? null ), 'fallback-refs-omit-provider-resolved-forms' );

$gate = $report['import_validation_result']['quality_gates']['fallback_blocks'] ?? array();
provider_refs_check( 1 === ( $gate['count'] ?? -1 ) && array( 'diag-cookies-form' ) === ( $gate['diagnostic_refs'] ?? null ), 'fallback-blocks-gate-cites-only-unsupported-fallbacks' );
provider_refs_check( count( $gate['diagnostic_refs'] ) === $gate['count'], 'fallback-blocks-gate-refs-match-count' );

echo "provider-resolved fallback gate refs: ok\n";
