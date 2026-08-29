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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-submission-evidence.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$nimbus = array(
	'page_path'               => 'index.html',
	'form_identity'           => "index.html\nform.signup",
	'provider_id'             => 'jetpack',
	'provider_version'        => '16.0.1',
	'plan_hash'               => 'plan-nimbus',
	'materialization_receipt' => array(
		'status'    => 'completed',
		'plan_hash' => 'plan-nimbus',
	),
	'site_origin'             => 'http://nimbus.test/',
	'source_endpoints'        => array( 'https://www.wix.com/_api/wix-forms/submit' ),
	'required_fields'         => array( 'email' ),
	'valid_payload'           => array( 'email' => 'lead@nimbus.test' ),
	'adapter'                 => Static_Site_Importer_Provider_Submission_Evidence::local_wordpress_adapter(
		array(
			'endpoint'            => 'http://nimbus.test/',
			'site_origin'         => 'http://nimbus.test/',
			'required_fields'     => array( 'email' ),
			'notification_reason' => 'no_external_mail_transport',
		)
	),
);

$accepted = Static_Site_Importer_Provider_Submission_Evidence::verify( $nimbus );
$assert( Static_Site_Importer_Provider_Submission_Evidence::SCHEMA === ( $accepted['schema'] ?? '' ), 'nimbus-schema' );
$assert( 'accepted' === ( $accepted['status'] ?? '' ), 'nimbus-accepted', wp_json_encode( $accepted ) );
$assert( 'index.html' === ( $accepted['binding']['page_path'] ?? '' ), 'nimbus-page' );
$assert( "index.html\nform.signup" === ( $accepted['binding']['form_identity'] ?? '' ), 'nimbus-form-identity' );
$assert( 'jetpack' === ( $accepted['binding']['provider_id'] ?? '' ) && '16.0.1' === ( $accepted['binding']['provider_version'] ?? '' ), 'nimbus-provider' );
$assert( 'plan-nimbus' === ( $accepted['binding']['plan_hash'] ?? '' ) && 'completed' === ( $accepted['binding']['materialization_receipt']['status'] ?? '' ), 'nimbus-plan-binding' );
$assert( 'wordpress' === ( $accepted['request']['owner'] ?? '' ) && 'http://nimbus.test/' === ( $accepted['request']['url'] ?? '' ) && false === ( $accepted['request']['source_endpoint_retained'] ?? true ), 'nimbus-wordpress-owned' );
$assert( true === ( $accepted['receipt']['local'] ?? false ) && 'feedback' === ( $accepted['receipt']['type'] ?? '' ) && '' !== ( $accepted['receipt']['id'] ?? '' ), 'nimbus-local-receipt' );
$assert( array( 'required_field_failure' => 'passed', 'valid_success' => 'passed', 'provider_failure' => 'passed', 'duplicate_submit' => 'passed' ) === ( $accepted['behaviors'] ?? array() ), 'nimbus-behaviors' );
$assert( false === ( $accepted['notification']['capable'] ?? true ) && false === ( $accepted['notification']['sent'] ?? true ) && 'no_external_mail_transport' === ( $accepted['notification']['reason'] ?? '' ), 'nimbus-notification-separated' );

$wix = $nimbus;
$wix['adapter'] = Static_Site_Importer_Provider_Submission_Evidence::local_wordpress_adapter(
	array(
		'endpoint'        => 'https://www.wix.com/_api/wix-forms/submit',
		'site_origin'     => 'http://nimbus.test/',
		'required_fields' => array( 'email' ),
	)
);
$wix_result = Static_Site_Importer_Provider_Submission_Evidence::verify( $wix );
$assert( 'failed' === ( $wix_result['status'] ?? '' ) && 'provider_submission_not_wordpress_owned' === ( $wix_result['code'] ?? '' ), 'wix-endpoint-fails-closed' );

$missing_adapter = $nimbus;
unset( $missing_adapter['adapter'] );
$missing = Static_Site_Importer_Provider_Submission_Evidence::verify( $missing_adapter );
$assert( 'failed' === ( $missing['status'] ?? '' ) && 'provider_cannot_accept_submissions' === ( $missing['code'] ?? '' ), 'missing-adapter-fails-closed' );

$mail_adapter = Static_Site_Importer_Provider_Submission_Evidence::local_wordpress_adapter(
	array(
		'endpoint'        => 'http://nimbus.test/',
		'site_origin'     => 'http://nimbus.test/',
		'required_fields' => array( 'email' ),
	)
);
$mail_state_sent = false;
$original_notification = $mail_adapter['notification'];
$mail_adapter['notification'] = static function () use ( $original_notification, &$mail_state_sent ): array {
	$notification         = $original_notification();
	$notification['sent'] = true;
	unset( $mail_state_sent );
	return $notification;
};
$mailed = $nimbus;
$mailed['adapter'] = $mail_adapter;
$mailed_result = Static_Site_Importer_Provider_Submission_Evidence::verify( $mailed );
$assert( 'failed' === ( $mailed_result['status'] ?? '' ) && 'provider_external_notification_sent' === ( $mailed_result['code'] ?? '' ), 'external-email-fails-closed' );

$omitted = Static_Site_Importer_Provider_Submission_Evidence::from_entity_reports(
	array(
		array(
			'provider' => 'jetpack',
			'forms'    => array(
				array(
					'selector'       => 'form.dead',
					'source_path'    => 'index.html',
					'runtime_mapped' => false,
				),
			),
		),
	)
);
$assert( null === $omitted, 'unmapped-forms-omit-evidence' );

$mapped = Static_Site_Importer_Provider_Submission_Evidence::from_entity_reports(
	array(
		array(
			'provider' => 'jetpack',
			'counts'   => array( 'mapped' => 1 ),
			'forms'    => array(
				array(
					'selector'       => 'form.signup',
					'source_path'    => 'index.html',
					'runtime_mapped' => true,
					'controls'       => array(
						array(
							'name'     => 'email',
							'required' => true,
						),
					),
					'form'           => array(
						'action' => 'https://www.wix.com/_api/wix-forms/submit',
					),
				),
			),
		),
	),
	array(
		'provider_version'        => '16.0.1',
		'plan_hash'               => 'plan-nimbus',
		'materialization_receipt' => array(
			'status'    => 'completed',
			'plan_hash' => 'plan-nimbus',
		),
		'site_origin'             => 'http://nimbus.test/',
	)
);
$assert( 'accepted' === ( $mapped['status'] ?? '' ) && 1 === count( $mapped['forms'] ?? array() ) && false === ( $mapped['forms'][0]['request']['source_endpoint_retained'] ?? true ), 'mapped-nimbus-form-proves-local-receipt' );

$incomplete = Static_Site_Importer_Provider_Submission_Evidence::verify(
	array(
		'page_path' => 'index.html',
		'adapter'   => $nimbus['adapter'],
	)
);
$assert( 'failed' === ( $incomplete['status'] ?? '' ) && 'provider_submission_binding_incomplete' === ( $incomplete['code'] ?? '' ), 'incomplete-binding-fails-closed' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: provider submission evidence smoke passed (' . $assertions . " assertions)\n";
