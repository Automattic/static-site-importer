<?php
/** A choices-only form with a submit stays editable and is counted on the receipt. */
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
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$finalize = static function ( string $html ): array {
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
	$quality  = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array( 'fail_on_quality' => true ) );
	return array( $compiled, $report, $quality );
};

$fixture                            = (string) file_get_contents( __DIR__ . '/fixtures/choice-submission-form.html' );
[ $compiled, $report, $quality ]    = $finalize( $fixture );
$blocks                             = (string) ( $compiled['serialized_blocks'] ?? '' );
$assert( true === ( $quality['pass'] ?? false ), 'passes-quality-gate' );
$assert( 0 === ( $quality['unsupported_fallback_count'] ?? -1 ), 'no-unsupported-fallback' );
$assert( ! str_contains( $blocks, 'wp:html' ), 'no-raw-html-block' );
$assert( 2 === substr_count( $blocks, 'type="radio"' ), 'keeps-real-radios' );
$assert( str_contains( $blocks, 'Yes, very often' ) && str_contains( $blocks, 'Not this time' ), 'keeps-choice-labels' );
$assert( str_contains( $blocks, 'type="submit"' ), 'keeps-submit-control' );
$assert( 1 === ( $quality['choice_submission_form_count'] ?? 0 ), 'quality-counts-form' );
$assert( 1 === ( $report['import_validation_result']['counts']['choice_submission_forms'] ?? 0 ), 'validation-result-counts-form' );

$no_submit = str_replace( '<input type="submit" class="btn" value="Submit">', '', $fixture );
[ , , $no_submit_quality ] = $finalize( $no_submit );
$assert( true === ( $no_submit_quality['pass'] ?? false ), 'no-submit-passes-quality-gate' );
$assert( 0 === ( $no_submit_quality['choice_submission_form_count'] ?? -1 ), 'no-submit-form-is-not-counted' );

$payment = str_replace( '<input type="submit" class="btn" value="Submit">', '<input type="image" src="pay.png" alt="PayPal - The safer, easier way to pay online!">', $fixture );
[ , , $payment_quality ] = $finalize( $payment );
$assert( false === ( $payment_quality['pass'] ?? true ), 'payment-form-still-fails-quality-gate' );
$assert( 0 === ( $payment_quality['choice_submission_form_count'] ?? -1 ), 'payment-form-is-not-counted-as-poll' );

foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo empty( $failures ) ? "Choice submission form: passed\n" : '';
exit( empty( $failures ) ? 0 : 1 );
