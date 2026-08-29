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
 * Proves a valid form payload reached a WordPress-owned provider and created a local receipt.
 */
class Static_Site_Importer_Provider_Submission_Evidence {
	public const SCHEMA = 'static-site-importer/provider-submission-evidence/v1';

	/**
	 * @param array<string,mixed> $input Verification request.
	 * @return array<string,mixed>
	 */
	public static function verify( array $input ): array {
		$binding = self::binding_from( $input );
		if ( is_string( $binding ) ) {
			return self::failed( $binding, 'Provider submission evidence is missing a required identity binding.' );
		}
		$adapter = self::adapter_from( $input );
		if ( is_string( $adapter ) ) {
			return self::failed( $adapter, 'Provider submission evidence is missing a WordPress-owned submission adapter.', $binding );
		}
		$endpoint = self::string_value( $adapter['endpoint'] ?? '' );
		if ( '' === $endpoint ) {
			return self::failed( 'provider_submission_endpoint_missing', 'Provider submission evidence is missing the declared WordPress-owned endpoint.', $binding );
		}
		if ( ! self::wordpress_owned( $endpoint, self::string_value( $adapter['site_origin'] ?? $input['site_origin'] ?? '' ) ) ) {
			return self::failed( 'provider_submission_not_wordpress_owned', 'Provider submission did not reach a WordPress-owned endpoint.', $binding );
		}
		if ( self::source_endpoint_retained( $endpoint, $input['source_endpoints'] ?? array() ) ) {
			return self::failed( 'provider_source_endpoint_retained', 'Provider submission retained a source backend endpoint.', $binding );
		}

		$required_fields = self::string_list( $input['required_fields'] ?? array() );
		$valid_payload   = is_array( $input['valid_payload'] ?? null ) ? $input['valid_payload'] : array();
		if ( empty( $required_fields ) || empty( $valid_payload ) ) {
			return self::failed( 'provider_submission_payload_missing', 'Provider submission evidence requires a valid payload and required fields.', $binding );
		}

		$required_failure = self::submit( $adapter, array(), array() );
		if ( ! self::is_required_failure( $required_failure ) ) {
			return self::failed( 'provider_required_field_failure_unproven', 'Required-field failure did not fail closed without a local receipt.', $binding );
		}

		$success = self::submit( $adapter, $valid_payload, array() );
		if ( ! self::is_local_success( $success, $endpoint ) ) {
			return self::failed( 'provider_valid_submission_unproven', 'Valid submission did not create a WordPress-owned local receipt.', $binding );
		}

		$provider_failure = self::submit( $adapter, $valid_payload, array( 'force_failure' => true ) );
		if ( ! self::is_provider_failure( $provider_failure ) ) {
			return self::failed( 'provider_failure_unproven', 'Provider failure UI was not proven without a local receipt.', $binding );
		}

		$duplicate = self::submit( $adapter, $valid_payload, array() );
		if ( ! self::is_duplicate_success( $duplicate, $success ) ) {
			return self::failed( 'provider_duplicate_submit_unproven', 'Duplicate submit was not bounded to the local receipt.', $binding );
		}

		$notification = self::notification_from( $adapter );
		if ( true === ( $notification['sent'] ?? null ) && empty( $input['allow_external_notification'] ) ) {
			return self::failed( 'provider_external_notification_sent', 'Provider submission sent external notification during local receipt proof.', $binding );
		}

		return array(
			'schema'       => self::SCHEMA,
			'status'       => 'accepted',
			'binding'      => $binding,
			'request'      => array(
				'url'                      => $endpoint,
				'owner'                    => 'wordpress',
				'source_endpoint_retained' => false,
			),
			'receipt'      => array(
				'type'  => self::string_value( $success['receipt_type'] ?? 'feedback' ),
				'id'    => self::string_value( $success['receipt_id'] ?? '' ),
				'local' => true,
			),
			'behaviors'    => array(
				'required_field_failure' => 'passed',
				'valid_success'          => 'passed',
				'provider_failure'       => 'passed',
				'duplicate_submit'       => 'passed',
			),
			'notification' => $notification,
		);
	}

	/**
	 * @param array<string,mixed> $reports Entity materialization reports.
	 * @param array<string,mixed> $context Shared identity context.
	 * @return array<string,mixed>|null
	 */
	public static function from_entity_reports( array $reports, array $context = array() ): ?array {
		$forms = array();
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}
			$provider = self::string_value( $report['provider'] ?? $context['provider_id'] ?? 'jetpack' );
			foreach ( is_array( $report['forms'] ?? null ) ? $report['forms'] : array() as $form ) {
				if ( is_array( $form ) && ! empty( $form['runtime_mapped'] ) ) {
					$form['provider'] = self::string_value( $form['provider'] ?? $provider );
					$forms[]          = $form;
				}
			}
		}
		if ( array() === $forms ) {
			return null;
		}

		$evidence = array();
		foreach ( $forms as $form ) {
			$page_path     = self::string_value( $form['source_path'] ?? $context['page_path'] ?? '' );
			$form_identity = $page_path . "\n" . self::string_value( $form['selector'] ?? '' );
			$source_action = self::string_value( $form['source_action'] ?? ( is_array( $form['form'] ?? null ) ? ( $form['form']['action'] ?? '' ) : '' ) );
			$evidence[]    = self::verify(
				array(
					'page_path'              => $page_path,
					'form_identity'          => $form_identity,
					'provider_id'            => self::string_value( $form['provider'] ?? 'jetpack' ),
					'provider_version'       => self::string_value( $context['provider_version'] ?? '' ),
					'plan_hash'              => self::string_value( $context['plan_hash'] ?? '' ),
					'materialization_receipt' => is_array( $context['materialization_receipt'] ?? null ) ? $context['materialization_receipt'] : array(),
					'site_origin'            => self::string_value( $context['site_origin'] ?? '' ),
					'source_endpoints'       => array_values( array_filter( array( $source_action ) ) ),
					'required_fields'        => self::required_fields_from( $form, $context ),
					'valid_payload'          => self::valid_payload_from( $form, $context ),
					'adapter'                => $context['adapter'] ?? self::local_wordpress_adapter(
						array(
							'endpoint'             => self::string_value( $context['site_origin'] ?? '' ),
							'site_origin'          => self::string_value( $context['site_origin'] ?? '' ),
							'required_fields'      => self::required_fields_from( $form, $context ),
							'notification_reason'  => 'no_external_mail_transport',
						)
					),
				)
			);
		}

		$failed = array_values( array_filter( $evidence, static fn( array $row ): bool => 'accepted' !== ( $row['status'] ?? '' ) ) );
		$row    = array(
			'schema' => self::SCHEMA,
			'status' => array() === $failed ? 'accepted' : 'failed',
			'forms'  => $evidence,
		);
		if ( array() !== $failed ) {
			$row['code']   = self::string_value( $failed[0]['code'] ?? 'provider_submission_failed' );
			$row['reason'] = self::string_value( $failed[0]['reason'] ?? 'A mapped provider form did not prove WordPress-owned local receipt.' );
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $config Adapter configuration.
	 * @return array<string,mixed>
	 */
	public static function local_wordpress_adapter( array $config = array() ): array {
		$state       = (object) array(
			'receipts' => array(),
			'mail'     => array(),
		);
		$endpoint    = self::string_value( $config['endpoint'] ?? '' );
		$site_origin = self::string_value( $config['site_origin'] ?? $endpoint );
		$required    = self::string_list( $config['required_fields'] ?? array() );
		$reason      = self::string_value( $config['notification_reason'] ?? 'no_external_mail_transport' );
		return array(
			'endpoint'     => $endpoint,
			'site_origin'  => $site_origin,
			'submit'       => static function ( array $payload, array $context ) use ( $state, $endpoint, $required ): array {
				if ( ! empty( $context['force_failure'] ) ) {
					return array(
						'ok'          => false,
						'request_url' => $endpoint,
						'ui'          => 'error',
						'receipt_id'  => '',
						'duplicate'   => false,
					);
				}
				foreach ( $required as $field ) {
					if ( '' === trim( (string) ( $payload[ $field ] ?? '' ) ) ) {
						return array(
							'ok'          => false,
							'request_url' => $endpoint,
							'ui'          => 'invalid',
							'receipt_id'  => '',
							'code'        => 'required_field',
							'duplicate'   => false,
						);
					}
				}
				$fingerprint = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $payload ) : (string) json_encode( $payload );
				if ( isset( $state->receipts[ $fingerprint ] ) ) {
					return array(
						'ok'          => true,
						'request_url' => $endpoint,
						'ui'          => 'success',
						'receipt_id'   => $state->receipts[ $fingerprint ],
						'receipt_type' => 'feedback',
						'duplicate'   => true,
					);
				}
				$id                           = 'feedback-' . (string) ( count( $state->receipts ) + 1 );
				$state->receipts[ $fingerprint ] = $id;
				return array(
					'ok'           => true,
					'request_url'  => $endpoint,
					'ui'           => 'success',
					'receipt_id'   => $id,
					'receipt_type' => 'feedback',
					'duplicate'    => false,
				);
			},
			'notification' => static function () use ( $state, $reason ): array {
				return array(
					'capable'   => false,
					'transport' => 'none',
					'reason'    => $reason,
					'sent'      => array() !== $state->mail,
				);
			},
		);
	}

	/**
	 * @param array<string,mixed> $input Verification request.
	 * @return array<string,mixed>|string
	 */
	private static function binding_from( array $input ): array|string {
		$page_path     = self::string_value( $input['page_path'] ?? '' );
		$form_identity = self::string_value( $input['form_identity'] ?? '' );
		$provider_id   = self::string_value( $input['provider_id'] ?? '' );
		$version       = self::string_value( $input['provider_version'] ?? '' );
		$plan_hash     = self::string_value( $input['plan_hash'] ?? '' );
		$receipt       = is_array( $input['materialization_receipt'] ?? null ) ? $input['materialization_receipt'] : array();
		$receipt_hash  = self::string_value( $receipt['plan_hash'] ?? $plan_hash );
		if ( '' === $page_path || '' === $form_identity || '' === $provider_id || '' === $version || '' === $plan_hash || 'completed' !== ( $receipt['status'] ?? '' ) || '' === $receipt_hash ) {
			return 'provider_submission_binding_incomplete';
		}
		return array(
			'page_path'               => $page_path,
			'form_identity'           => $form_identity,
			'provider_id'             => $provider_id,
			'provider_version'        => $version,
			'plan_hash'               => $plan_hash,
			'materialization_receipt' => array(
				'status'    => 'completed',
				'plan_hash' => $receipt_hash,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input Verification request.
	 * @return array<string,mixed>|string
	 */
	private static function adapter_from( array $input ): array|string {
		$adapter = $input['adapter'] ?? null;
		if ( is_array( $adapter ) && is_callable( $adapter['submit'] ?? null ) ) {
			return $adapter;
		}
		return 'provider_cannot_accept_submissions';
	}

	/**
	 * @param array<string,mixed> $adapter Submission adapter.
	 * @param array<string,mixed> $payload Form payload.
	 * @param array<string,mixed> $context Submit context.
	 * @return array<string,mixed>
	 */
	private static function submit( array $adapter, array $payload, array $context ): array {
		$result = call_user_func( $adapter['submit'], $payload, $context );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * @param array<string,mixed> $adapter Submission adapter.
	 * @return array<string,mixed>
	 */
	private static function notification_from( array $adapter ): array {
		$notification = is_callable( $adapter['notification'] ?? null ) ? call_user_func( $adapter['notification'] ) : array();
		return array(
			'capable'   => ! empty( $notification['capable'] ),
			'transport' => self::string_value( $notification['transport'] ?? 'none' ) ?: 'none',
			'reason'    => self::string_value( $notification['reason'] ?? 'no_external_mail_transport' ) ?: 'no_external_mail_transport',
			'sent'      => ! empty( $notification['sent'] ),
		);
	}

	/**
	 * @param array<string,mixed> $result Submit result.
	 */
	private static function is_required_failure( array $result ): bool {
		return false === ( $result['ok'] ?? null ) && 'invalid' === ( $result['ui'] ?? '' ) && '' === self::string_value( $result['receipt_id'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $result Submit result.
	 */
	private static function is_local_success( array $result, string $endpoint ): bool {
		return true === ( $result['ok'] ?? null )
			&& 'success' === ( $result['ui'] ?? '' )
			&& $endpoint === self::string_value( $result['request_url'] ?? '' )
			&& '' !== self::string_value( $result['receipt_id'] ?? '' )
			&& empty( $result['duplicate'] );
	}

	/**
	 * @param array<string,mixed> $result Submit result.
	 */
	private static function is_provider_failure( array $result ): bool {
		return false === ( $result['ok'] ?? null ) && 'error' === ( $result['ui'] ?? '' ) && '' === self::string_value( $result['receipt_id'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $duplicate Duplicate submit result.
	 * @param array<string,mixed> $success   First success result.
	 */
	private static function is_duplicate_success( array $duplicate, array $success ): bool {
		return true === ( $duplicate['ok'] ?? null )
			&& true === ( $duplicate['duplicate'] ?? null )
			&& self::string_value( $success['receipt_id'] ?? '' ) === self::string_value( $duplicate['receipt_id'] ?? '' );
	}

	private static function wordpress_owned( string $endpoint, string $site_origin ): bool {
		if ( '' === $endpoint || '' === $site_origin ) {
			return false;
		}
		$endpoint_host = strtolower( (string) ( parse_url( $endpoint, PHP_URL_HOST ) ?? '' ) );
		$origin_host   = strtolower( (string) ( parse_url( $site_origin, PHP_URL_HOST ) ?? '' ) );
		return '' !== $endpoint_host && $endpoint_host === $origin_host && ! self::source_host( $endpoint );
	}

	/**
	 * @param mixed $source_endpoints Declared source backends.
	 */
	private static function source_endpoint_retained( string $endpoint, mixed $source_endpoints ): bool {
		if ( self::source_host( $endpoint ) ) {
			return true;
		}
		foreach ( self::string_list( $source_endpoints ) as $source ) {
			if ( $source === $endpoint || self::same_origin( $endpoint, $source ) ) {
				return true;
			}
		}
		return false;
	}

	private static function source_host( string $url ): bool {
		$host = strtolower( (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ) );
		return (bool) preg_match( '/(^|\.)(wix\.com|wixsite\.com|squarespace\.com|weebly\.com)$/', $host );
	}

	private static function same_origin( string $left, string $right ): bool {
		$left_host  = strtolower( (string) ( parse_url( $left, PHP_URL_HOST ) ?? '' ) );
		$right_host = strtolower( (string) ( parse_url( $right, PHP_URL_HOST ) ?? '' ) );
		return '' !== $left_host && $left_host === $right_host;
	}

	/**
	 * @param array<string,mixed> $form    Mapped form row.
	 * @param array<string,mixed> $context Shared context.
	 * @return array<int,string>
	 */
	private static function required_fields_from( array $form, array $context ): array {
		$fields = self::string_list( $context['required_fields'] ?? array() );
		if ( array() !== $fields ) {
			return $fields;
		}
		foreach ( is_array( $form['controls'] ?? null ) ? $form['controls'] : array() as $control ) {
			if ( is_array( $control ) && ! empty( $control['required'] ) && is_string( $control['name'] ?? null ) && '' !== $control['name'] ) {
				$fields[] = $control['name'];
			}
		}
		return array() === $fields ? array( 'email' ) : $fields;
	}

	/**
	 * @param array<string,mixed> $form    Mapped form row.
	 * @param array<string,mixed> $context Shared context.
	 * @return array<string,string>
	 */
	private static function valid_payload_from( array $form, array $context ): array {
		if ( is_array( $context['valid_payload'] ?? null ) && array() !== $context['valid_payload'] ) {
			return $context['valid_payload'];
		}
		$payload = array();
		foreach ( self::required_fields_from( $form, $context ) as $field ) {
			$payload[ $field ] = 'email' === $field ? 'lead@example.test' : 'nimbus-valid';
		}
		return $payload;
	}

	/**
	 * @param mixed $value List value.
	 * @return array<int,string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			$item = self::string_value( $item );
			if ( '' !== $item ) {
				$list[] = $item;
			}
		}
		return array_values( array_unique( $list ) );
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * @param array<string,mixed>|null $binding Binding when available.
	 * @return array<string,mixed>
	 */
	private static function failed( string $code, string $reason, ?array $binding = null ): array {
		$row = array(
			'schema' => self::SCHEMA,
			'status' => 'failed',
			'code'   => $code,
			'reason' => $reason,
		);
		if ( is_array( $binding ) ) {
			$row['binding'] = $binding;
		}
		return $row;
	}
}
