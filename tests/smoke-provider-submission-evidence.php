<?php
/**
 * WordPress-owned provider submission evidence.
 *
 * Run from the repository root:
 * php tests/smoke-provider-submission-evidence.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

$GLOBALS['ssi_test_hooks'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['ssi_test_hooks'][ $hook ][] = $callback;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_test_hooks'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-submission-evidence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

Static_Site_Importer_Provider_Submission_Evidence::reset();

$nimbus = array(
	'selector'        => 'form#signup',
	'source_path'     => 'index.html',
	'provider'        => 'jetpack',
	'runtime_mapped'  => true,
	'source_action'   => 'https://www.wix.com/_api/wix-forms/v1/submit/nimbus-signup',
	'required_fields' => array( 'email' ),
	'form'            => array( 'action' => 'https://www.wix.com/_api/wix-forms/v1/submit/nimbus-signup' ),
);
$identity = array(
	'provider'                => 'jetpack',
	'provider_version'        => 'wordpress-owned',
	'plan_hash'               => str_repeat( 'ab', 32 ),
	'materialization_receipt' => array(
		'status' => 'completed',
		'schema' => 'static-site-importer/materialization-receipt/v2',
	),
);
$evidence = Static_Site_Importer_Provider_Submission_Evidence::verify_form( $nimbus, $identity );
$assert( 'accepted' === ( $evidence['status'] ?? '' ), 'nimbus-valid-submission-is-accepted', (string) wp_json_encode( $evidence ) );
$assert( 'wordpress_owned' === ( $evidence['request']['endpoint_kind'] ?? '' ), 'submission-target-is-wordpress-owned' );
$assert( Static_Site_Importer_Provider_Submission_Evidence::STORE_ENDPOINT === ( $evidence['request']['endpoint'] ?? '' ), 'submission-uses-local-store' );
$assert( false === ( $evidence['request']['source_endpoint_retained'] ?? true ), 'wix-endpoint-is-not-retained' );
$assert( true === ( $evidence['request']['source_endpoint_external'] ?? false ), 'wix-source-action-is-recorded-as-external' );
$assert( true === ( $evidence['receipt']['stored'] ?? false ) && '' !== ( $evidence['receipt']['id'] ?? '' ), 'local-receipt-is-stored' );
$assert( 'passed' === ( $evidence['behaviors']['required_field_failure']['status'] ?? '' ) && 'required_field_invalid' === ( $evidence['behaviors']['required_field_failure']['ui'] ?? '' ), 'required-field-failure-is-proven' );
$assert( 'passed' === ( $evidence['behaviors']['valid_success']['status'] ?? '' ) && 'success' === ( $evidence['behaviors']['valid_success']['ui'] ?? '' ), 'valid-success-ui-is-proven' );
$assert( 'passed' === ( $evidence['behaviors']['provider_failure']['status'] ?? '' ) && 'provider_error' === ( $evidence['behaviors']['provider_failure']['ui'] ?? '' ), 'provider-failure-ui-is-proven' );
$assert( 'passed' === ( $evidence['behaviors']['duplicate_submit']['status'] ?? '' ) && 'duplicate' === ( $evidence['behaviors']['duplicate_submit']['ui'] ?? '' ), 'duplicate-submit-is-proven' );
$assert( 'unavailable' === ( $evidence['notification']['capability'] ?? '' ), 'wix-form-has-no-mail-transport' );
$assert( false === ( $evidence['notification']['required_for_receipt'] ?? true ), 'notification-is-not-required-for-receipt' );
$assert( false === ( $evidence['notification']['sent'] ?? true ), 'verification-does-not-send-mail' );
$assert( str_repeat( 'ab', 32 ) === ( $evidence['identity']['plan_hash'] ?? '' ), 'evidence-binds-plan-hash' );
$assert( 'completed' === ( $evidence['identity']['materialization_receipt']['status'] ?? '' ), 'evidence-binds-materialization-receipt' );
$assert( hash( 'sha256', "index.html\nform#signup" ) === ( $evidence['identity']['form_identity'] ?? '' ), 'evidence-binds-form-identity' );
$assert( 'jetpack' === ( $evidence['identity']['provider'] ?? '' ) && 'wordpress-owned' === ( $evidence['identity']['provider_version'] ?? '' ), 'evidence-binds-provider-version' );

$mailto = $nimbus;
$mailto['source_action'] = 'mailto:owner@example.test';
$mailto['form']['action'] = 'mailto:owner@example.test';
Static_Site_Importer_Provider_Submission_Evidence::reset();
$mailto_evidence = Static_Site_Importer_Provider_Submission_Evidence::verify_form( $mailto, $identity );
$assert( 'accepted' === ( $mailto_evidence['status'] ?? '' ), 'mailto-form-still-stores-local-receipt' );
$assert( 'configured' === ( $mailto_evidence['notification']['capability'] ?? '' ), 'mailto-records-notification-capability' );
$assert( false === ( $mailto_evidence['notification']['sent'] ?? true ), 'configured-notification-is-not-sent' );
$assert( true === ( $mailto_evidence['receipt']['stored'] ?? false ), 'local-receipt-does-not-depend-on-mail' );

$GLOBALS['ssi_test_hooks']['static_site_importer_provider_submit'] = array(
	static fn() => new WP_Error( 'static_site_importer_form_provider_unavailable', 'Provider cannot accept submissions.' ),
);
Static_Site_Importer_Provider_Submission_Evidence::reset();
$closed = Static_Site_Importer_Provider_Submission_Evidence::verify_form( $nimbus, $identity );
$assert( 'failed' === ( $closed['status'] ?? '' ), 'unavailable-provider-fails-closed' );
$GLOBALS['ssi_test_hooks'] = array();

$report = array(
	'provider' => 'jetpack',
	'counts'   => array( 'mapped' => 1 ),
	'forms'    => array( $nimbus ),
);
Static_Site_Importer_Provider_Submission_Evidence::reset();
$verified = Static_Site_Importer_Provider_Submission_Evidence::verify_report( $report, $identity );
$assert( 'accepted' === ( $verified['status'] ?? '' ) && 1 === count( $verified['forms'] ?? array() ), 'report-verification-accepts-mapped-form' );

$lifecycle = array(
	'entities' => array(
		'forms' => array(
			'adapter'  => array(
				'capability'   => 'form',
				'provider'     => 'test-provider',
				'materializer' => static fn( array $manifest ): array => array(
					'status'   => 'completed',
					'provider' => 'test-provider',
					'counts'   => array( 'mapped' => 1 ),
					'forms'    => array(
						array(
							'selector'        => 'form#signup',
							'source_path'     => 'index.html',
							'provider'        => 'test-provider',
							'runtime_mapped'  => true,
							'source_action'   => 'https://www.wix.com/_api/wix-forms/v1/submit/nimbus-signup',
							'required_fields' => array( 'email' ),
						),
					),
				),
			),
			'manifest' => array( 'forms' => array( array( 'selector' => 'form#signup' ) ) ),
			'required' => true,
		),
	),
);
$materialized = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities(
	$lifecycle,
	array(
		'seed_entities' => true,
		'plan_hash'     => str_repeat( 'cd', 32 ),
	)
);
$assert( empty( $materialized['error'] ), 'lifecycle-accepts-proven-submission', (string) wp_json_encode( $materialized ) );
$lifecycle_evidence = $materialized['reports']['forms']['provider_submission_evidence'] ?? array();
$assert( 'accepted' === ( $lifecycle_evidence['status'] ?? '' ), 'lifecycle-attaches-accepted-evidence' );
$assert( str_repeat( 'cd', 32 ) === ( $lifecycle_evidence['forms'][0]['identity']['plan_hash'] ?? '' ), 'lifecycle-binds-plan-hash' );

$failing_lifecycle = $lifecycle;
$failing_lifecycle['entities']['forms']['adapter']['materializer'] = static fn( array $manifest ): array => array(
	'status'   => 'completed',
	'provider' => 'test-provider',
	'counts'   => array( 'mapped' => 1 ),
	'forms'    => array(
		array(
			'selector'       => 'form#signup',
			'source_path'    => 'index.html',
			'provider'       => 'test-provider',
			'runtime_mapped' => true,
		),
	),
);
$GLOBALS['ssi_test_hooks']['static_site_importer_provider_submit'] = array(
	static fn() => new WP_Error( 'static_site_importer_form_provider_unavailable', 'Provider cannot accept submissions.' ),
);
$rejected = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $failing_lifecycle, array( 'seed_entities' => true ) );
$assert( 'static_site_importer_provider_submission_unproven' === ( $rejected['error']['code'] ?? '' ), 'lifecycle-fails-closed-without-receipt' );
$GLOBALS['ssi_test_hooks'] = array();

$bound = Static_Site_Importer_Provider_Submission_Evidence::bind_identity(
	$verified,
	array(
		'plan_hash'               => str_repeat( 'ef', 32 ),
		'materialization_receipt' => array(
			'status' => 'completed',
			'schema' => 'static-site-importer/materialization-receipt/v2',
		),
	)
);
$report_envelope = Static_Site_Importer_Import_Report::from_array( array() );
$report_envelope['provider_submission_evidence'] = $bound;
$summary = Static_Site_Importer_Report_Diagnostics::import_report_summary( $report_envelope, array( 'pass' => true ) );
$assert( 'accepted' === ( $summary['provider_submission_evidence']['status'] ?? '' ), 'import-report-summary-carries-submission-evidence' );
$assert( str_repeat( 'ef', 32 ) === ( $bound['forms'][0]['identity']['plan_hash'] ?? '' ), 'import-report-evidence-binds-plan-hash' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: provider submission evidence smoke passed (' . $assertions . " assertions)\n";
