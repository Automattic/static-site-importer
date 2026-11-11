<?php
/** Paired engine check: submitted choices stay editable but require remediation. */
// phpcs:ignoreFile -- Standalone CLI regression.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return $value;
}
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
$engine_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' );
if ( ! $engine_root ) {
	throw new RuntimeException( 'Set STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH to the engine checkout containing the choice-submission fix.' );
}
require_once rtrim( $engine_root, '/\\' ) . '/php-transformer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$finalize = static function ( string $html, array $args = array() ): array {
	$compiled = ( new ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array( 'index.html' => $html ),
		)
	)->toArray();
	$report   = Static_Site_Importer_Import_Report::from_array(
		array(
			'quality'     => array( 'fallback_count' => count( $compiled['fallbacks'] ?? array() ) ),
			'diagnostics' => $compiled['source_reports']['wordpress_site_plan']['diagnostics'] ?? array(),
		)
	);
	$quality  = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
	return array( $compiled, $report, $quality );
};

$fixture                            = (string) file_get_contents( __DIR__ . '/fixtures/choice-submission-form.html' );
[ $compiled, $report, $quality ]    = $finalize( $fixture );
$blocks                             = (string) ( $compiled['serialized_blocks'] ?? '' );
$assert( false === ( $quality['pass'] ?? true ) && true === ( $quality['fail_import'] ?? false ), 'requires-remediation-with-default-options' );
$assert( 0 === ( $quality['unsupported_fallback_count'] ?? -1 ) && 0 === ( $quality['accepted_preserved_runtime_island_count'] ?? -1 ), 'choice-form-is-not-accepted-runtime' );
$assert( ! str_contains( $blocks, 'wp:html' ), 'no-raw-html-block' );
$assert( 2 === substr_count( $blocks, 'type="radio"' ), 'keeps-real-radios' );
$assert( str_contains( $blocks, 'Yes, very often' ) && str_contains( $blocks, 'Not this time' ), 'keeps-choice-labels' );
$assert( str_contains( $blocks, 'type="submit"' ), 'keeps-submit-control' );
$assert( 1 === ( $quality['choice_submission_form_count'] ?? 0 ), 'quality-counts-form' );
$assert( 1 === ( $report['import_validation_result']['counts']['choice_submission_forms'] ?? 0 ), 'validation-result-counts-form' );
$assert( 'failed' === ( $report['import_validation_result']['status'] ?? '' ) && 'failed' === ( $report['compact_summary']['status'] ?? '' ), 'choice-form-cannot-complete-migration' );
[ , , $permissive_quality ] = $finalize( $fixture, array( 'fail_on_quality' => false, 'max_fallbacks' => 100 ) );
$assert( true === $permissive_quality['fail_import'], 'permissive-options-cannot-accept-missing-submission-handler' );

$no_submit = str_replace( '<input type="submit" class="btn" value="Submit">', '', $fixture );
[ , , $no_submit_quality ] = $finalize( $no_submit );
$assert( true === ( $no_submit_quality['pass'] ?? false ), 'no-submit-passes-quality-gate' );
$assert( 0 === ( $no_submit_quality['choice_submission_form_count'] ?? -1 ), 'no-submit-form-is-not-counted' );

$payment = str_replace( '<input type="submit" class="btn" value="Submit">', '<input type="image" src="pay.png" alt="PayPal - The safer, easier way to pay online!">', $fixture );
[ , , $payment_quality ] = $finalize( $payment );
$assert( false === ( $payment_quality['pass'] ?? true ), 'payment-form-still-fails-quality-gate' );
$assert( 0 === ( $payment_quality['choice_submission_form_count'] ?? -1 ), 'payment-form-is-not-counted-as-poll' );

$event_fixture = str_replace( 'type="radio"', 'onclick="void 0" type="radio"', $fixture );
[ $event_compiled, , $event_quality ] = $finalize( $event_fixture );
$assert( false === ( $event_quality['pass'] ?? true ), 'event-form-retains-quality-failure' );
$assert( 2 === substr_count( $event_compiled['serialized_blocks'], 'type="radio"' ), 'event-form-retains-radios' );
$assert( str_contains( $event_compiled['serialized_blocks'], 'Do you read the newsletter?' ), 'event-form-retains-question' );
$assert( 0 === ( $event_quality['choice_submission_form_count'] ?? -1 ), 'blocked-form-is-not-counted-as-editable' );

$donation = str_replace( '<input type="submit" class="btn" value="Submit">', '<input type="image" src="donate.png" alt="Donate">', $fixture );
[ , , $donation_quality ] = $finalize( $donation );
$assert( false === ( $donation_quality['pass'] ?? true ), 'donation-retains-quality-failure' );
$assert( 0 === ( $donation_quality['choice_submission_form_count'] ?? -1 ), 'donation-is-not-counted' );

preg_match( '~<form\b.*?</form>~s', $fixture, $form_match );
$two_forms = str_replace( $form_match[0], $form_match[0] . $form_match[0], $fixture );
[ , , $two_form_quality ] = $finalize( $two_forms );
$assert( 2 === ( $two_form_quality['choice_submission_form_count'] ?? -1 ), 'same-class-forms-count-separately' );

$runtime_diagnostic = array_values( array_filter( $report['diagnostics'], static fn( array $d ): bool => 'preserved_runtime_island' === ( $d['code'] ?? '' ) ) )[0];
$count_diagnostics = static function ( array $diagnostics ): int {
	$report = Static_Site_Importer_Import_Report::from_array( array( 'diagnostics' => $diagnostics ) );
	$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array( 'fail_on_quality' => true ) );
	return $quality['choice_submission_form_count'];
};
$echo = $runtime_diagnostic;
$echo['id'] = 'echo';
$echo['source_diagnostic']['id'] = 'producer-echo';
$assert( 1 === $count_diagnostics( array( $runtime_diagnostic, $echo ) ), 'runtime-echo-counted-once' );
$other_page = $runtime_diagnostic;
$other_page['source_path'] = 'other.html';
$assert( 2 === $count_diagnostics( array( $runtime_diagnostic, $other_page ) ), 'different-source-pages-count-separately' );
$unrelated = $runtime_diagnostic;
$unrelated['code'] = 'unrelated_diagnostic';
$assert( 0 === $count_diagnostics( array( $unrelated ) ), 'intent-on-other-diagnostic-is-not-counted' );

foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo empty( $failures ) ? "Choice submission form: passed\n" : '';
exit( empty( $failures ) ? 0 : 1 );
