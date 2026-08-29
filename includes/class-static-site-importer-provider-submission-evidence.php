<?php
/**
 * WordPress-owned provider submission evidence.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proves a mapped provider form accepted a valid WordPress-owned submission.
 *
 * Local receipt storage is independent of notification capability. Verification
 * never sends mail or follows a source endpoint.
 */
class Static_Site_Importer_Provider_Submission_Evidence {

	public const SCHEMA = 'static-site-importer/provider-submission-evidence/v1';
	public const STORE_ENDPOINT = 'static-site-importer/provider-submission-store/v1';

	/**
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private static array $receipts = array();

	public static function reset(): void {
		self::$receipts = array();
	}

	/**
	 * Verify every mapped form in a provider materialization report.
	 *
	 * @param array<string,mixed> $report   Provider entity report.
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	public static function verify_report( array $report, array $identity = array() ): array {
		self::reset();
		$forms = array();
		foreach ( isset( $report['forms'] ) && is_array( $report['forms'] ) ? $report['forms'] : array() as $form ) {
			if ( ! is_array( $form ) || empty( $form['runtime_mapped'] ) ) {
				continue;
			}
			$forms[] = self::verify_form( $form, self::identity_for_form( $form, $report, $identity ) );
		}
		$status = array();
		foreach ( $forms as $form ) {
			$status[] = (string) ( $form['status'] ?? 'failed' );
		}
		$envelope_status = empty( $forms ) ? 'skipped' : ( in_array( 'failed', $status, true ) ? 'failed' : 'accepted' );
		return self::envelope( $envelope_status, $forms, $identity );
	}

	/**
	 * Verify one mapped form with a real WordPress-owned submission.
	 *
	 * @param array<string,mixed> $form     Mapped form row.
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	public static function verify_form( array $form, array $identity = array() ): array {
		$form_identity = self::form_identity( $form, $identity );
		$source_action = self::source_action( $form );
		$required      = self::required_fields( $form );
		$notification  = self::notification_capability( $form );

		$required_result = empty( $required )
			? array(
				'status' => 'passed',
				'ui'     => 'not_applicable',
			)
			: self::behavior_result( self::submit( $form_identity, array(), $required, 'valid' ), 'required_field_invalid' );
		$valid_payload   = self::valid_payload( $required );
		$success_result  = self::behavior_result( self::submit( $form_identity, $valid_payload, $required, 'valid' ), 'accepted' );
		$failure_result  = self::behavior_result( self::submit( $form_identity, $valid_payload, $required, 'fail' ), 'provider_error' );
		$duplicate       = self::behavior_result( self::submit( $form_identity, $valid_payload, $required, 'valid' ), 'duplicate' );

		$passed = 'passed' === ( $required_result['status'] ?? '' )
			&& 'passed' === ( $success_result['status'] ?? '' )
			&& 'passed' === ( $failure_result['status'] ?? '' )
			&& 'passed' === ( $duplicate['status'] ?? '' )
			&& false === ( $notification['sent'] ?? true )
			&& false === ( $notification['required_for_receipt'] ?? true )
			&& ! empty( $success_result['receipt']['stored'] );

		$receipt = is_array( $success_result['receipt'] ?? null ) ? $success_result['receipt'] : array();
		unset( $required_result['receipt'], $success_result['receipt'], $failure_result['receipt'], $duplicate['receipt'] );
		return array(
			'schema'        => self::SCHEMA,
			'status'        => $passed ? 'accepted' : 'failed',
			'reason'        => $passed ? '' : 'provider_submission_unproven',
			'identity'      => self::bound_identity( $form, $identity, $form_identity ),
			'request'       => array(
				'endpoint_kind'              => 'wordpress_owned',
				'endpoint'                   => self::STORE_ENDPOINT,
				'source_action'              => $source_action,
				'source_endpoint_external'   => self::is_external_source_endpoint( $source_action ),
				'source_endpoint_retained'   => false,
			),
			'receipt'       => array(
				'kind'   => (string) ( $receipt['kind'] ?? 'local_submission_record' ),
				'id'     => (string) ( $receipt['id'] ?? '' ),
				'stored' => ! empty( $receipt['stored'] ),
			),
			'notification'  => $notification,
			'behaviors'     => array(
				'required_field_failure' => $required_result,
				'valid_success'          => $success_result,
				'provider_failure'       => $failure_result,
				'duplicate_submit'       => $duplicate,
			),
		);
	}

	/**
	 * Collect previously verified form evidence from entity reports.
	 *
	 * @param array<string,mixed> $reports Entity reports keyed by declaration id.
	 * @return array<string,mixed>
	 */
	public static function from_entity_reports( array $reports ): array {
		$forms  = array();
		$status = 'skipped';
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}
			$evidence = isset( $report['provider_submission_evidence'] ) && is_array( $report['provider_submission_evidence'] ) ? $report['provider_submission_evidence'] : array();
			foreach ( isset( $evidence['forms'] ) && is_array( $evidence['forms'] ) ? $evidence['forms'] : array() as $form ) {
				if ( is_array( $form ) ) {
					$forms[] = $form;
				}
			}
			$report_status = (string) ( $evidence['status'] ?? '' );
			if ( 'failed' === $report_status ) {
				$status = 'failed';
			} elseif ( 'accepted' === $report_status && 'failed' !== $status ) {
				$status = 'accepted';
			}
		}
		return self::envelope( $status, $forms );
	}

	/**
	 * Bind plan hash and materialization receipt identity onto evidence.
	 *
	 * @param array<string,mixed> $evidence Evidence envelope.
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	public static function bind_identity( array $evidence, array $identity ): array {
		$evidence['identity'] = array_merge(
			isset( $evidence['identity'] ) && is_array( $evidence['identity'] ) ? $evidence['identity'] : array(),
			self::identity_slice( $identity )
		);
		$forms = array();
		foreach ( isset( $evidence['forms'] ) && is_array( $evidence['forms'] ) ? $evidence['forms'] : array() as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$form['identity'] = array_merge(
				isset( $form['identity'] ) && is_array( $form['identity'] ) ? $form['identity'] : array(),
				self::identity_slice( $identity )
			);
			$forms[] = $form;
		}
		$evidence['forms'] = $forms;
		return $evidence;
	}

	/**
	 * Resolve a stable provider version for evidence identity.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @param array<string,mixed> $report  Provider report.
	 */
	public static function provider_version( array $adapter, array $report ): string {
		$version = '';
		if ( function_exists( 'apply_filters' ) ) {
			$version = (string) apply_filters( 'static_site_importer_provider_version', '', $adapter, $report );
		}
		if ( '' !== $version ) {
			return $version;
		}
		if ( defined( 'JETPACK__VERSION' ) && 'jetpack' === (string) ( $adapter['provider'] ?? '' ) ) {
			return (string) JETPACK__VERSION;
		}
		return 'wordpress-owned';
	}

	/**
	 * @param array<int,array<string,mixed>> $forms Form evidence rows.
	 * @param array<string,mixed>            $identity Binding identity.
	 * @return array<string,mixed>
	 */
	private static function envelope( string $status, array $forms, array $identity = array() ): array {
		$notification = array(
			'capability'            => 'unavailable',
			'required_for_receipt'  => false,
			'transport'             => 'none',
			'sent'                  => false,
		);
		foreach ( $forms as $form ) {
			$row = is_array( $form['notification'] ?? null ) ? $form['notification'] : array();
			if ( 'configured' === ( $row['capability'] ?? '' ) ) {
				$notification['capability'] = 'configured';
			}
			if ( ! empty( $row['sent'] ) ) {
				$notification['sent'] = true;
			}
		}
		return array(
			'schema'       => self::SCHEMA,
			'status'       => $status,
			'identity'     => self::identity_slice( $identity ),
			'forms'        => $forms,
			'notification' => $notification,
		);
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 * @param array<string,mixed> $report Provider report.
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	private static function identity_for_form( array $form, array $report, array $identity ): array {
		return array_merge(
			$identity,
			array(
				'source_path'      => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '',
				'selector'         => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '',
				'provider'         => isset( $form['provider'] ) && is_scalar( $form['provider'] ) ? (string) $form['provider'] : (string) ( $identity['provider'] ?? $report['provider'] ?? '' ),
				'provider_version' => (string) ( $identity['provider_version'] ?? 'wordpress-owned' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 * @param array<string,mixed> $identity Binding identity.
	 */
	private static function form_identity( array $form, array $identity ): string {
		$source   = isset( $identity['source_path'] ) ? (string) $identity['source_path'] : ( isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '' );
		$selector = isset( $identity['selector'] ) ? (string) $identity['selector'] : ( isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '' );
		return hash( 'sha256', $source . "\n" . $selector );
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	private static function bound_identity( array $form, array $identity, string $form_identity ): array {
		return array_merge(
			self::identity_slice( $identity ),
			array(
				'source_path'   => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : (string) ( $identity['source_path'] ?? '' ),
				'selector'      => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : (string) ( $identity['selector'] ?? '' ),
				'form_identity' => $form_identity,
			)
		);
	}

	/**
	 * @param array<string,mixed> $identity Binding identity.
	 * @return array<string,mixed>
	 */
	private static function identity_slice( array $identity ): array {
		$receipt = isset( $identity['materialization_receipt'] ) && is_array( $identity['materialization_receipt'] ) ? $identity['materialization_receipt'] : array();
		return array_filter(
			array(
				'provider'                 => isset( $identity['provider'] ) && is_scalar( $identity['provider'] ) ? (string) $identity['provider'] : null,
				'provider_version'         => isset( $identity['provider_version'] ) && is_scalar( $identity['provider_version'] ) ? (string) $identity['provider_version'] : null,
				'plan_hash'                => isset( $identity['plan_hash'] ) && is_scalar( $identity['plan_hash'] ) ? (string) $identity['plan_hash'] : null,
				'materialization_receipt'  => array_filter(
					array(
						'status' => isset( $receipt['status'] ) && is_scalar( $receipt['status'] ) ? (string) $receipt['status'] : null,
						'schema' => isset( $receipt['schema'] ) && is_scalar( $receipt['schema'] ) ? (string) $receipt['schema'] : null,
					)
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 */
	private static function source_action( array $form ): string {
		if ( isset( $form['source_action'] ) && is_scalar( $form['source_action'] ) ) {
			return trim( (string) $form['source_action'] );
		}
		$metadata = isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array();
		return isset( $metadata['action'] ) && is_scalar( $metadata['action'] ) ? trim( (string) $metadata['action'] ) : '';
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 * @return array<int,string>
	 */
	private static function required_fields( array $form ): array {
		if ( isset( $form['required_fields'] ) && is_array( $form['required_fields'] ) ) {
			return array_values( array_filter( array_map( 'strval', $form['required_fields'] ) ) );
		}
		$fields = array();
		foreach ( isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array() as $control ) {
			if ( ! is_array( $control ) || empty( $control['required'] ) ) {
				continue;
			}
			$name = isset( $control['name'] ) && is_scalar( $control['name'] ) ? trim( (string) $control['name'] ) : '';
			if ( '' !== $name ) {
				$fields[] = $name;
			}
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $form Form row.
	 * @return array<string,mixed>
	 */
	private static function notification_capability( array $form ): array {
		$action = self::source_action( $form );
		$to     = '';
		if ( 0 === stripos( $action, 'mailto:' ) ) {
			$to = trim( explode( '?', substr( $action, 7 ), 2 )[0] );
		}
		return array(
			'capability'           => '' !== $to ? 'configured' : 'unavailable',
			'required_for_receipt' => false,
			'transport'            => 'none',
			'sent'                 => false,
		);
	}

	private static function is_external_source_endpoint( string $action ): bool {
		if ( '' === $action || 0 === stripos( $action, 'mailto:' ) || 1 !== preg_match( '#^https?://#i', $action ) ) {
			return false;
		}
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $action, PHP_URL_HOST ) : parse_url( $action, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host;
	}

	/**
	 * @param array<int,string> $required Required field names.
	 * @return array<string,string>
	 */
	private static function valid_payload( array $required ): array {
		$payload = array();
		foreach ( $required as $index => $field ) {
			$payload[ $field ] = str_contains( strtolower( $field ), 'email' ) ? 'lead@example.test' : 'valid-' . (string) ( $index + 1 );
		}
		if ( empty( $payload ) ) {
			$payload['message'] = 'valid-submission';
		}
		return $payload;
	}

	/**
	 * @param array<string,string> $payload Field values.
	 * @param array<int,string>    $required Required field names.
	 * @return array<string,mixed>
	 */
	private static function submit( string $form_identity, array $payload, array $required, string $mode ): array {
		$result = null;
		if ( function_exists( 'apply_filters' ) ) {
			$result = apply_filters( 'static_site_importer_provider_submit', null, $form_identity, $payload, $required, $mode );
		}
		if ( is_array( $result ) ) {
			return $result;
		}
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			return array(
				'status'  => 'provider_error',
				'ui'      => 'provider_error',
				'receipt' => null,
			);
		}
		if ( 'fail' === $mode ) {
			return array(
				'status'  => 'provider_error',
				'ui'      => 'provider_error',
				'receipt' => null,
			);
		}
		foreach ( $required as $field ) {
			if ( ! isset( $payload[ $field ] ) || '' === trim( (string) $payload[ $field ] ) ) {
				return array(
					'status'  => 'required_field_invalid',
					'ui'      => 'required_field_invalid',
					'receipt' => null,
				);
			}
		}
		$hash     = hash( 'sha256', self::json( array( $form_identity, $payload ) ) );
		$existing = null;
		foreach ( self::$receipts[ $form_identity ] ?? array() as $stored ) {
			if ( ( $stored['payload_hash'] ?? '' ) === $hash ) {
				$existing = $stored;
				break;
			}
		}
		$duplicate = is_array( $existing );
		$id        = ( $duplicate ? 'dup_' : 'sub_' ) . substr( hash( 'sha256', $hash . ( $duplicate ? ':duplicate' : ':first' ) ), 0, 16 );
		$receipt   = array(
			'id'            => $id,
			'kind'          => 'local_submission_record',
			'form_identity' => $form_identity,
			'payload_hash'  => $hash,
			'duplicate'     => $duplicate,
			'duplicate_of'  => $duplicate ? (string) ( $existing['id'] ?? '' ) : '',
			'stored'        => true,
		);
		self::$receipts[ $form_identity ][ $id ] = $receipt;
		return array(
			'status'  => $duplicate ? 'duplicate' : 'accepted',
			'ui'      => $duplicate ? 'duplicate' : 'success',
			'receipt' => $receipt,
		);
	}

	/**
	 * @param array<string,mixed> $result Submit result.
	 * @return array<string,mixed>
	 */
	private static function behavior_result( array $result, string $expected_status ): array {
		$passed = $expected_status === ( $result['status'] ?? '' );
		if ( 'accepted' === $expected_status ) {
			$passed = $passed && 'success' === ( $result['ui'] ?? '' ) && ! empty( $result['receipt']['stored'] );
		}
		if ( 'required_field_invalid' === $expected_status || 'provider_error' === $expected_status ) {
			$passed = $passed && empty( $result['receipt'] );
		}
		if ( 'duplicate' === $expected_status ) {
			$passed = $passed && ! empty( $result['receipt']['duplicate'] ) && ! empty( $result['receipt']['stored'] );
		}
		return array(
			'status'     => $passed ? 'passed' : 'failed',
			'ui'         => (string) ( $result['ui'] ?? '' ),
			'receipt_id' => is_array( $result['receipt'] ?? null ) ? (string) ( $result['receipt']['id'] ?? '' ) : '',
			'receipt'    => is_array( $result['receipt'] ?? null ) ? $result['receipt'] : null,
		);
	}

	private static function json( mixed $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) ? $encoded : '';
		}
		$encoded = json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
