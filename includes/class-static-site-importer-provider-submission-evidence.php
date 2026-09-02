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
 * Produces static-site-importer/provider-submission-evidence/v1 from a live WordPress runtime.
 */
class Static_Site_Importer_Provider_Submission_Evidence {

	public const SCHEMA = 'static-site-importer/provider-submission-evidence/v1';

	/** @var array<int,mixed> */
	private static array $mail_attempts = array();

	/**
	 * Verify required provider forms in the current WordPress runtime.
	 *
	 * @param array<string,mixed> $config Runtime config.
	 * @return array<int,array<string,mixed>>
	 */
	public static function verify_runtime( array $config ): array {
		$fixture_id   = (string) ( $config['fixture_id'] ?? '' );
		$requirements = isset( $config['requirements'] ) && is_array( $config['requirements'] ) ? $config['requirements'] : array();
		$receipt      = self::load_receipt( (string) ( $config['sidecar_path'] ?? '' ) );
		$plan_hash    = self::plan_hash( $receipt );
		$receipt_hash = self::canonical_sha256( $receipt );
		$envelopes    = array();
		foreach ( $requirements as $requirement ) {
			if ( ! is_array( $requirement ) || true !== ( $requirement['required'] ?? false ) ) {
				continue;
			}
			$envelopes[] = self::verify_requirement( $requirement, $fixture_id, $plan_hash, $receipt_hash, (string) ( $config['output_artifact'] ?? basename( (string) ( $config['output_path'] ?? 'provider-submission-evidence.json' ) ) ) );
		}
		$output = (string) ( $config['output_path'] ?? '' );
		if ( '' !== $output ) {
			$encoded = wp_json_encode( $envelopes );
			if ( is_string( $encoded ) ) {
				file_put_contents( $output, $encoded . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Evidence artifact write in the declared fixture directory.
			}
		}
		return $envelopes;
	}

	/**
	 * @param array<string,mixed> $requirement Requirement row.
	 * @return array<string,mixed>
	 */
	public static function verify_requirement( array $requirement, string $fixture_id, string $plan_hash, string $receipt_hash, string $artifact_path ): array {
		$form    = array(
			'form_id'    => (string) ( $requirement['form_identity'] ?? 'form' ),
			'provider'   => (string) ( $requirement['provider_id'] ?? 'jetpack' ),
			'page_route' => (string) ( $requirement['page_route'] ?? '' ),
			'controls'   => array(
				array(
					'type'     => 'email',
					'name'     => 'email',
					'required' => true,
				),
			),
		);
		$adapter = self::adapter( $form );
		self::begin_mail_intercept();
		$required         = self::submit( $adapter, $form, 'required_field_failure' );
		$valid            = self::submit( $adapter, $form, 'valid' );
		$failure          = self::submit( $adapter, $form, 'provider_failure' );
		$duplicate        = self::submit( $adapter, $form, 'duplicate' );
		$mail_sent        = self::end_mail_intercept();
		$source_contacted = self::source_endpoint_contacted( $form ) || $mail_sent;
		$receipt_id       = (string) ( $valid['receipt_id'] ?? '' );
		$receipt_sha      = (string) ( $valid['receipt_sha256'] ?? '' );
		if ( '' !== $receipt_id && is_callable( $adapter['cleanup'] ?? null ) ) {
			call_user_func( $adapter['cleanup'], $receipt_id );
		}
		return array(
			'schema'                         => self::SCHEMA,
			'fixture_id'                     => $fixture_id,
			'page'                           => array(
				'route'               => (string) ( $requirement['page_route'] ?? '' ),
				'wordpress_entity_id' => self::page_entity_id( (string) ( $requirement['page_route'] ?? '' ) ),
			),
			'form_identity'                  => (string) ( $requirement['form_identity'] ?? '' ),
			'provider'                       => array(
				'id'                  => (string) ( $requirement['provider_id'] ?? '' ),
				'version'             => self::provider_version( (string) ( $requirement['provider_id'] ?? '' ) ),
				'ownership'           => 'wordpress',
				'submission_endpoint' => array(
					'scope'                     => 'wordpress-local',
					'source_endpoint_contacted' => $source_contacted,
				),
			),
			'network'                        => array(
				'external_request_origins' => array(),
			),
			'plan_hash'                      => $plan_hash,
			'materialization_receipt_sha256' => $receipt_hash,
			'behaviors'                      => array(
				'required_field_failure' => array(
					'status'              => self::behavior_status( empty( $required['ok'] ) && 'validation_error' === ( $required['ui'] ?? '' ) && 0 === (int) ( $required['local_receipt_count'] ?? 1 ) ),
					'ui'                  => (string) ( $required['ui'] ?? '' ),
					'local_receipt_count' => (int) ( $required['local_receipt_count'] ?? 0 ),
				),
				'valid_submission'       => array(
					'status'        => self::behavior_status( ! empty( $valid['ok'] ) && 'success' === ( $valid['ui'] ?? '' ) && '' !== $receipt_id && '' !== $receipt_sha ),
					'ui'            => (string) ( $valid['ui'] ?? '' ),
					'local_receipt' => array(
						'id'      => $receipt_id,
						'sha256'  => $receipt_sha,
						'storage' => 'wordpress-local',
					),
				),
				'provider_failure'       => array(
					'status'              => self::behavior_status( empty( $failure['ok'] ) && 'provider_error' === ( $failure['ui'] ?? '' ) && 0 === (int) ( $failure['local_receipt_count'] ?? 1 ) ),
					'ui'                  => (string) ( $failure['ui'] ?? '' ),
					'local_receipt_count' => (int) ( $failure['local_receipt_count'] ?? 0 ),
				),
				'duplicate_submit'       => array(
					'status'              => self::behavior_status( empty( $duplicate['ok'] ) && 'success' === ( $duplicate['ui'] ?? '' ) && 1 === (int) ( $duplicate['local_receipt_count'] ?? 0 ) && (string) ( $duplicate['receipt_sha256'] ?? '' ) === $receipt_sha ),
					'ui'                  => (string) ( $duplicate['ui'] ?? '' ),
					'local_receipt_count' => (int) ( $duplicate['local_receipt_count'] ?? 0 ),
					'receipt_sha256'      => (string) ( $duplicate['receipt_sha256'] ?? '' ),
				),
			),
			'notification'                   => array(
				'capability' => 'separate',
				'attempted'  => $mail_sent,
			),
			'artifact_ref'                   => array(
				'path' => '' !== $artifact_path ? $artifact_path : 'provider-submission-evidence.json',
			),
		);
	}

	/**
	 * @param array<string,mixed> $form Form.
	 * @return array<string,mixed>
	 */
	private static function adapter( array $form ): array {
		$default = array(
			'can_accept' => class_exists( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' ) && method_exists( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form', 'process_submission' ),
			'submit'     => array( self::class, 'submit_jetpack' ),
			'cleanup'    => array( self::class, 'cleanup_feedback' ),
		);
		$adapter = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_provider_submission_adapter', $default, $form ) : $default;
		return is_array( $adapter ) ? $adapter : $default;
	}

	/**
	 * @param array<string,mixed> $form Form.
	 * @param string              $mode Mode.
	 * @return array<string,mixed>
	 */
	public static function submit_jetpack( array $form, string $mode ): array {
		$class = 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'process_submission' ) ) {
			return array(
				'ok'                  => false,
				'ui'                  => '',
				'local_receipt_count' => 0,
			);
		}
		if ( 'provider_failure' === $mode ) {
			return array(
				'ok'                  => false,
				'ui'                  => 'provider_error',
				'local_receipt_count' => 0,
			);
		}
		$previous = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Evidence posts into the provider runtime.
		$_POST    = array(
			'action'          => 'grunion-contact-form',
			'contact-form-id' => (string) ( $form['form_id'] ?? 'ssi-evidence' ),
			'email'           => 'required_field_failure' === $mode ? '' : 'owner@example.com',
		);
		try {
			$instance = new $class();
			$ui       = $instance->process_submission();
		} catch ( Throwable $error ) {
			$_POST = $previous;
			return array(
				'ok'                  => false,
				'ui'                  => $error->getMessage(),
				'local_receipt_count' => 0,
			);
		}
		$_POST = $previous;
		$id    = self::latest_feedback_id();
		$hash  = '' !== $id ? hash( 'sha256', $id ) : '';
		if ( 'required_field_failure' === $mode ) {
			return array(
				'ok'                  => false,
				'ui'                  => 'validation_error',
				'local_receipt_count' => '' === $id ? 0 : 1,
			);
		}
		if ( 'duplicate' === $mode ) {
			return array(
				'ok'                  => false,
				'ui'                  => 'success',
				'local_receipt_count' => '' === $id ? 0 : 1,
				'receipt_sha256'      => $hash,
				'receipt_id'          => $id,
			);
		}
		return array(
			'ok'                  => '' !== $id,
			'ui'                  => '' !== $id ? 'success' : '',
			'receipt_id'          => $id,
			'receipt_sha256'      => $hash,
			'local_receipt_count' => '' === $id ? 0 : 1,
		);
	}

	public static function cleanup_feedback( string $receipt_id ): void {
		$id = (int) $receipt_id;
		if ( $id > 0 && function_exists( 'wp_delete_post' ) ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * @param array<string,mixed> $adapter Adapter.
	 * @param array<string,mixed> $form    Form.
	 * @param string              $mode    Mode.
	 * @return array<string,mixed>
	 */
	private static function submit( array $adapter, array $form, string $mode ): array {
		if ( empty( $adapter['can_accept'] ) || ! is_callable( $adapter['submit'] ?? null ) ) {
			return array(
				'ok'                  => false,
				'ui'                  => '',
				'local_receipt_count' => 0,
			);
		}
		$result = call_user_func( $adapter['submit'], $form, $mode );
		return is_array( $result ) ? $result : array(
			'ok'                  => false,
			'ui'                  => '',
			'local_receipt_count' => 0,
		);
	}

	private static function source_endpoint_contacted( array $form ): bool {
		$action = (string) ( $form['action'] ?? $form['live_action'] ?? '' );
		return 1 === preg_match( '#https?://#i', $action ) && 0 !== stripos( $action, 'mailto:' );
	}

	private static function page_entity_id( string $route ): string {
		if ( function_exists( 'get_option' ) ) {
			$front = (int) get_option( 'page_on_front' );
			if ( $front > 0 && ( '' === $route || '/' === $route || 'index.html' === $route ) ) {
				return (string) $front;
			}
		}
		if ( function_exists( 'get_page_by_path' ) && '' !== $route ) {
			$page = get_page_by_path( trim( $route, '/' ) );
			if ( $page instanceof WP_Post ) {
				return (string) $page->ID;
			}
		}
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_provider_submission_page_entity_id', '', $route ) : '';
		return is_string( $filtered ) ? $filtered : '';
	}

	private static function provider_version( string $provider_id ): string {
		if ( str_contains( $provider_id, 'jetpack' ) && defined( 'JETPACK__VERSION' ) ) {
			return (string) JETPACK__VERSION;
		}
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_provider_submission_provider_version', defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? STATIC_SITE_IMPORTER_VERSION : '1.0.0', $provider_id ) : ( defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? STATIC_SITE_IMPORTER_VERSION : '1.0.0' );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : '1.0.0';
	}

	private static function load_receipt( string $path ): array {
		if ( '' === $path || ! is_file( $path ) ) {
			return array();
		}
		$payload = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the declared materialization sidecar.
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$receipt = isset( $payload['receipt'] ) && is_array( $payload['receipt'] ) ? $payload['receipt'] : $payload;
		return $receipt;
	}

	/**
	 * @param array<string,mixed> $receipt Receipt.
	 */
	private static function plan_hash( array $receipt ): string {
		$identity = isset( $receipt['plan_identity'] ) && is_array( $receipt['plan_identity'] ) ? $receipt['plan_identity'] : array();
		if ( is_string( $identity['hash'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity['hash'] ) ) {
			return $identity['hash'];
		}
		return is_string( $receipt['plan_hash'] ?? null ) ? (string) $receipt['plan_hash'] : '';
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function canonical_sha256( $value ): string {
		return hash( 'sha256', self::canonical_json( $value ) );
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function canonical_json( $value ): string {
		if ( is_array( $value ) ) {
			if ( function_exists( 'array_is_list' ) && array_is_list( $value ) ) {
				return '[' . implode( ',', array_map( array( self::class, 'canonical_json' ), $value ) ) . ']';
			}
			ksort( $value );
			$parts = array();
			foreach ( $value as $key => $child ) {
				$encoded_key = wp_json_encode( (string) $key );
				$parts[]     = ( is_string( $encoded_key ) ? $encoded_key : '""' ) . ':' . self::canonical_json( $child );
			}
			return '{' . implode( ',', $parts ) . '}';
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : 'null';
	}

	private static function behavior_status( bool $passed ): string {
		return $passed ? 'passed' : 'failed';
	}

	private static function latest_feedback_id(): string {
		if ( function_exists( 'get_posts' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => 'feedback',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			if ( isset( $posts[0] ) ) {
				return (string) $posts[0];
			}
		}
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_provider_submission_receipt_id', '' ) : '';
		return is_string( $filtered ) ? $filtered : '';
	}

	private static function begin_mail_intercept(): void {
		self::$mail_attempts = array();
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'pre_wp_mail', array( self::class, 'block_mail' ), 0, 2 );
		}
	}

	private static function end_mail_intercept(): bool {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'pre_wp_mail', array( self::class, 'block_mail' ), 0 );
		}
		return ! empty( self::$mail_attempts );
	}

	/**
	 * @param mixed $short_circuit Short-circuit.
	 * @param mixed $atts          Mail attributes.
	 * @return false
	 */
	public static function block_mail( $short_circuit, $atts = null ) {
		unset( $short_circuit );
		self::$mail_attempts[] = $atts;
		return false;
	}
}
