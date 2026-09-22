<?php
/**
 * Projects public diagnostic and error payloads for ability and receipt surfaces.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Redacts materializer failures into bounded public diagnostics and error data. */
final class Static_Site_Importer_Public_Error_Projection {
	private const FAILURE_DIAGNOSTIC_MAX_ROWS    = 10;
	private const FAILURE_DIAGNOSTIC_MAX_BYTES   = 256;
	private const FAILURE_DIAGNOSTIC_SCAN_BUDGET = 10;
	/** Structured fact rows retained per diagnostic, so a gate keeps evidence without becoming a dump. */
	private const FAILURE_DIAGNOSTIC_MAX_FACTS = 3;
	/** Failure rows retained per run failure in resumability evidence. */
	public const FAILURE_EVIDENCE_MAX_DIAGNOSTICS = 3;

	/** @param array<int,mixed> $diagnostics @return array<int,array<string,mixed>> */
	public static function project_public_diagnostics( array $diagnostics ): array {
		$projected = array();
		$scanned   = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$row = self::project_public_diagnostic( $diagnostic );
			if ( ! empty( $row ) ) {
				$projected[] = $row;
			}
			if ( self::FAILURE_DIAGNOSTIC_MAX_ROWS <= count( $projected ) ) {
				break;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	public static function project_public_error_data( array $data ): array {
		$projected = array();
		foreach ( array( 'status', 'code', 'phase', 'entity_collection' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = self::project_public_token( $data[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		$declaration_id = self::project_public_identity( $data['declaration_id'] ?? null );
		if ( '' !== $declaration_id ) {
			$projected['declaration_id'] = $declaration_id;
		}
		// Validator findings are the only statement of why a declaration was rejected; keep them shallow and bounded.
		$errors = self::project_public_validation_errors( $data['errors'] ?? null );
		if ( ! empty( $errors ) ) {
			$projected['errors'] = $errors;
		}
		$import_id = self::project_public_import_id( $data['import_id'] ?? null );
		if ( '' !== $import_id ) {
			$projected['import_id'] = $import_id;
		}
		foreach ( array( 'success', 'completed' ) as $field ) {
			if ( is_bool( $data[ $field ] ?? null ) ) {
				$projected[ $field ] = $data[ $field ];
			}
		}
		if ( is_bool( $data['resumable'] ?? null ) ) {
			$projected['resumable'] = $data['resumable'];
		}
		if ( is_array( $data['artifact_run'] ?? null ) ) {
			$artifact_run = self::project_public_artifact_run( $data['artifact_run'] );
			if ( ! empty( $artifact_run ) ) {
				$projected['artifact_run'] = $artifact_run;
			}
		}
		if ( is_array( $data['diagnostics'] ?? null ) ) {
			$projected['diagnostics'] = self::project_public_diagnostics( $data['diagnostics'] );
		}
		if ( is_array( $data['dependency'] ?? null ) ) {
			$dependency = array();
			foreach ( array( 'slug', 'plugin_file', 'source', 'status' ) as $field ) {
				$value = self::project_public_token( $data['dependency'][ $field ] ?? null, 128 );
				if ( '' !== $value ) {
					$dependency[ $field ] = $value;
				}
			}
			if ( is_array( $data['dependency']['error'] ?? null ) ) {
				$error = array();
				foreach ( array( 'code', 'message' ) as $field ) {
					$value = self::project_public_token( $data['dependency']['error'][ $field ] ?? null, 256 );
					if ( '' !== $value ) {
						$error[ $field ] = $value;
					}
				}
				if ( ! empty( $error ) ) {
					$dependency['error'] = $error;
				}
			}
			if ( ! empty( $dependency ) ) {
				$projected['dependency'] = $dependency;
			}
		}
		if ( is_array( $data['import_validation_result']['diagnostics'] ?? null ) ) {
			$projected['import_validation_result'] = array(
				'diagnostics' => self::project_public_diagnostics( $data['import_validation_result']['diagnostics'] ),
			);
		}
		if ( is_array( $data['import_report_summary'] ?? null ) ) {
			$summary = self::project_public_error_summary( $data['import_report_summary'] );
			if ( ! empty( $summary ) ) {
				$projected['import_report_summary'] = $summary;
			}
		}
		if ( is_array( $data['quality'] ?? null ) ) {
			$quality = self::project_public_error_summary( $data['quality'] );
			if ( ! empty( $quality ) ) {
				$projected['quality'] = $quality;
			}
		}
		return $projected;
	}

	/** @param array<int,array<string,mixed>> $diagnostics */
	public static function project_public_error_message( string $code, array $diagnostics = array() ): string {
		$diagnostic = is_array( $diagnostics[0] ?? null ) ? $diagnostics[0] : array();
		// A gate that carried its own facts states them itself; the generic phrasing only covers what did not.
		$gate = self::project_public_gate_message( $diagnostic );
		if ( '' !== $gate ) {
			return $gate;
		}
		$source_path = isset( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '';
		$message     = '' !== $source_path ? 'Materialization failed for ' . $source_path : 'Materialization failed (' . self::project_public_token( $code, 128, 'materialization_failed' ) . ')';
		$reason      = self::project_public_reason( $diagnostic );
		return '' !== $reason ? $message . ': ' . $reason : $message . '.';
	}

	/**
	 * State the gate that rejected the import and quote its own first fact.
	 *
	 * Both carriers are already redacted and bounded by project_public_diagnostic(),
	 * so this only phrases what the diagnostic kept.
	 *
	 * @param array<string,mixed> $diagnostic
	 */
	private static function project_public_gate_message( array $diagnostic ): string {
		if ( ! empty( $diagnostic['threshold_failures'] ) && is_array( $diagnostic['threshold_failures'] ) ) {
			return self::project_public_threshold_message( $diagnostic );
		}
		if ( ! empty( $diagnostic['errors'] ) && is_array( $diagnostic['errors'] ) && '' !== self::project_public_identity( $diagnostic['declaration_id'] ?? null ) ) {
			return self::project_public_declaration_message( $diagnostic );
		}
		return '';
	}

	/** @param array<string,mixed> $diagnostic */
	private static function project_public_threshold_message( array $diagnostic ): string {
		$failures = array_values( $diagnostic['threshold_failures'] );
		$first    = is_array( $failures[0] ?? null ) ? $failures[0] : array();
		$gate     = self::project_public_token( $diagnostic['reason_code'] ?? null, 128 );
		$gate     = '' !== $gate ? str_replace( '_', ' ', (string) preg_replace( '/_(?:failed|invalid|rejected|exceeded)$/', '', $gate ) ) : 'quality policy';
		$metric   = isset( $first['metric'] ) ? (string) $first['metric'] : '';
		$fact     = '';
		if ( '' !== $metric && isset( $first['actual'] ) ) {
			$fact = $metric . ' is ' . self::project_public_number( $first['actual'] );
			foreach (
				array(
					'maximum' => 'max',
					'minimum' => 'min',
				) as $field => $label
			) {
				if ( isset( $first[ $field ] ) ) {
					$fact .= ' (' . $label . ' ' . self::project_public_number( $first[ $field ] ) . ')';
					break;
				}
			}
		} else {
			$fact = rtrim( self::project_public_detail( $first['detail'] ?? null, 128 ), '.' );
		}
		$source_path = self::project_public_source_path( $first['source_path'] ?? null );
		if ( '' !== $fact && '' !== $source_path ) {
			$fact .= ' in ' . $source_path;
		}
		$remaining = max( 0, (int) ( $diagnostic['threshold_failure_count'] ?? count( $failures ) ) - 1 );
		if ( '' !== $fact && 0 < $remaining ) {
			$fact .= ', and ' . $remaining . ( 1 === $remaining ? ' more page' : ' more pages' );
		}
		return 'Materialization failed the ' . $gate . ( '' !== $fact ? ': ' . $fact : '' ) . '.';
	}

	/** @param array<string,mixed> $diagnostic */
	private static function project_public_declaration_message( array $diagnostic ): string {
		$errors     = array_values( $diagnostic['errors'] );
		$first      = is_array( $errors[0] ?? null ) ? $errors[0] : array();
		$collection = self::project_public_token( $diagnostic['entity_collection'] ?? null, 128 );
		$subject    = 'Runtime entity declaration ' . self::project_public_identity( $diagnostic['declaration_id'] ) . ( '' !== $collection ? ' (' . $collection . ')' : '' );
		$path       = self::project_public_source_path( $first['path'] ?? null );
		$fact       = trim( ( '' !== $path ? $path . ' — ' : '' ) . self::project_public_detail( $first['detail'] ?? null, 128 ) );
		if ( '' === $fact ) {
			return $subject . ' rejected.';
		}
		$remaining = max( 0, (int) ( $diagnostic['error_count'] ?? count( $errors ) ) - 1 );
		return $subject . ' rejected: ' . $fact . ( 0 < $remaining ? ' (and ' . $remaining . ' more)' : '' );
	}

	/** @param mixed $value */
	private static function project_public_number( $value ): string {
		return is_int( $value ) ? (string) $value : rtrim( rtrim( sprintf( '%.4F', (float) $value ), '0' ), '.' );
	}

	/** @param array<string,mixed> $artifact_run @return array<string,mixed> */
	private static function project_public_artifact_run( array $artifact_run ): array {
		$projected = array();
		foreach ( array( 'state', 'phase' ) as $field ) {
			if ( isset( $artifact_run[ $field ] ) ) {
				$value = self::project_public_token( $artifact_run[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		$artifact_identity = self::project_public_hash( $artifact_run['artifact_identity'] ?? null );
		if ( '' !== $artifact_identity ) {
			$projected['artifact_identity'] = $artifact_identity;
		}
		if ( is_array( $artifact_run['progress'] ?? null ) ) {
			$progress = array();
			foreach ( array( 'page_count', 'prepared_count', 'receipt_count', 'remaining' ) as $field ) {
				if ( is_numeric( $artifact_run['progress'][ $field ] ?? null ) ) {
					$progress[ $field ] = max( 0, (int) $artifact_run['progress'][ $field ] );
				}
			}
			if ( ! empty( $progress ) ) {
				$projected['progress'] = $progress;
			}
		}
		if ( is_array( $artifact_run['work'] ?? null ) ) {
			$work = array();
			foreach ( array( 'content_policy_applications', 'client_script_policy_applications', 'payloads_retained', 'shared_prepares', 'page_prepare_passes', 'page_plans_prepared', 'compile_batches', 'pages_compiled', 'compositions', 'materialization_claims', 'materialization_attempts', 'materializations', 'lifecycle_preparation_claims', 'lifecycle_preparation_attempts', 'lifecycle_preparations' ) as $field ) {
				if ( is_numeric( $artifact_run['work'][ $field ] ?? null ) ) {
					$work[ $field ] = max( 0, (int) $artifact_run['work'][ $field ] );
				}
			}
			if ( ! empty( $work ) ) {
				$projected['work'] = $work;
			}
		}
		if ( is_array( $artifact_run['failures'] ?? null ) ) {
			$failures = array();
			foreach ( array_slice( $artifact_run['failures'], 0, self::FAILURE_DIAGNOSTIC_MAX_ROWS ) as $failure ) {
				if ( ! is_array( $failure ) ) {
					continue;
				}
				$row = array();
				foreach ( array( 'phase', 'exception_class' ) as $field ) {
					$value = self::project_public_token( $failure[ $field ] ?? null, 128 );
					if ( '' !== $value ) {
						$row[ $field ] = $value;
					}
				}
				$artifact_identity = self::project_public_hash( $failure['artifact_identity'] ?? null );
				if ( '' !== $artifact_identity ) {
					$row['artifact_identity'] = $artifact_identity;
				}
				if ( ! empty( $row ) && is_array( $failure['diagnostics'] ?? null ) ) {
					$diagnostics = self::project_public_diagnostics( array_slice( $failure['diagnostics'], 0, self::FAILURE_EVIDENCE_MAX_DIAGNOSTICS ) );
					if ( ! empty( $diagnostics ) ) {
						$row['diagnostics'] = $diagnostics;
					}
				}
				if ( ! empty( $row ) ) {
					$failures[] = $row;
				}
			}
			if ( ! empty( $failures ) ) {
				$projected['failures'] = $failures;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private static function project_public_error_summary( array $summary ): array {
		$projected = array();
		foreach ( array( 'status' ) as $field ) {
			if ( isset( $summary[ $field ] ) ) {
				$value = self::project_public_token( $summary[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		foreach ( array( 'quality_pass', 'fail_import', 'pass' ) as $field ) {
			if ( is_bool( $summary[ $field ] ?? null ) ) {
				$projected[ $field ] = $summary[ $field ];
			}
		}
		foreach ( array( 'failure_reasons' ) as $field ) {
			if ( ! is_array( $summary[ $field ] ?? null ) ) {
				continue;
			}
			$values  = array();
			$scanned = 0;
			foreach ( $summary[ $field ] as $value ) {
				if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
					break;
				}
				$value = self::project_public_token( $value, 128 );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
			if ( ! empty( $values ) ) {
				$projected[ $field ] = $values;
			}
		}
		foreach ( array( 'core_html_block_count', 'fallback_count', 'diagnostic_count', 'loss_count' ) as $field ) {
			if ( is_numeric( $summary[ $field ] ?? null ) ) {
				$projected[ $field ] = max( 0, (int) $summary[ $field ] );
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private static function project_public_diagnostic( array $diagnostic ): array {
		$row = array();
		foreach ( array( 'code', 'type', 'kind', 'severity', 'entity_type', 'entity_collection', 'provider', 'reason_code', 'provider_availability_reason' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) ) {
				$row[ $field ] = self::project_public_token( $diagnostic[ $field ], 128 );
			}
		}
		if ( isset( $diagnostic['declaration_id'] ) ) {
			$row['declaration_id'] = self::project_public_identity( $diagnostic['declaration_id'] );
		}
		foreach ( array( 'reason', 'phase' ) as $field ) {
			$value = self::project_public_token( $diagnostic[ $field ] ?? null, 128 );
			if ( '' !== $value ) {
				$row[ $field ] = $value;
			}
		}
		if ( isset( $diagnostic['provider_available'] ) && is_bool( $diagnostic['provider_available'] ) ) {
			$row['provider_available'] = $diagnostic['provider_available'];
		}
		if ( isset( $diagnostic['loss_count'] ) && is_numeric( $diagnostic['loss_count'] ) ) {
			$row['loss_count'] = max( 0, (int) $diagnostic['loss_count'] );
		}
		if ( isset( $diagnostic['exception_class'] ) && is_string( $diagnostic['exception_class'] ) ) {
			$exception_class = self::project_public_token( substr( (string) strrchr( '\\' . $diagnostic['exception_class'], '\\' ), 1 ), 128 );
			if ( '' !== $exception_class ) {
				$row['exception_class'] = $exception_class;
			}
		}
		if ( isset( $diagnostic['source_path'] ) ) {
			$source_path = self::project_public_source_path( $diagnostic['source_path'] );
			if ( '' !== $source_path ) {
				$row['source_path'] = $source_path;
			}
		}
		if ( isset( $diagnostic['selector'] ) ) {
			$selector = self::project_public_selector( $diagnostic['selector'] );
			if ( '' !== $selector ) {
				$row['selector'] = $selector;
			}
		}
		if ( isset( $diagnostic['reconciliation_identity'] ) && is_string( $diagnostic['reconciliation_identity'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $diagnostic['reconciliation_identity'] ) ) {
			$row['reconciliation_identity'] = $diagnostic['reconciliation_identity'];
		}
		if ( is_array( $diagnostic['binding_reconciliation_identities'] ?? null ) ) {
			$identities = array();
			$scanned    = 0;
			foreach ( $diagnostic['binding_reconciliation_identities'] as $identity ) {
				if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
					break;
				}
				if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
					$identities[] = $identity;
				}
			}
			if ( ! empty( $identities ) ) {
				$row['binding_reconciliation_identities'] = $identities;
			}
		}
		if ( empty( $row ) ) {
			return array();
		}
		// Free text never identifies a row on its own; it is only kept as redacted, bounded detail.
		$detail = self::project_public_detail( $diagnostic['detail'] ?? $diagnostic['message'] ?? null );
		if ( '' !== $detail ) {
			$row['detail'] = $detail;
		}
		if ( is_array( $diagnostic['fields'] ?? null ) ) {
			$fields  = array();
			$scanned = 0;
			foreach ( $diagnostic['fields'] as $key => $value ) {
				if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
					break;
				}
				$key   = self::project_public_token( $key, 64 );
				$value = self::project_public_detail( $value, 128 );
				if ( '' !== $key && '' !== $value ) {
					$fields[ $key ] = $value;
				}
			}
			if ( ! empty( $fields ) ) {
				$row['fields'] = $fields;
			}
		}
		// The gate's own measurements and validator findings; without them a reader only learns that something failed.
		$threshold_failures = self::project_public_threshold_failures( $diagnostic['threshold_failures'] ?? null );
		if ( ! empty( $threshold_failures ) ) {
			$row['threshold_failures']      = $threshold_failures;
			$row['threshold_failure_count'] = is_numeric( $diagnostic['threshold_failure_count'] ?? null ) ? max( 0, (int) $diagnostic['threshold_failure_count'] ) : count( $threshold_failures );
		}
		$errors = self::project_public_validation_errors( $diagnostic['errors'] ?? null );
		if ( ! empty( $errors ) ) {
			$row['errors']      = $errors;
			$row['error_count'] = is_numeric( $diagnostic['error_count'] ?? null ) ? max( 0, (int) $diagnostic['error_count'] ) : count( $errors );
		}
		if ( empty( $row['code'] ) ) {
			$row['code'] = 'materialization_failed';
		}
		$code           = is_string( $row['code'] ) ? $row['code'] : 'materialization_failed';
		$row['code']    = $code;
		$row['message'] = self::project_public_error_message( $code, array( $row ) );
		return $row;
	}

	/**
	 * Keep a producer threshold breach as measurements rather than prose.
	 *
	 * @param mixed $failures
	 * @return array<int,array<string,mixed>>
	 */
	private static function project_public_threshold_failures( $failures ): array {
		if ( ! is_array( $failures ) ) {
			return array();
		}
		$rows    = array();
		$scanned = 0;
		foreach ( $failures as $failure ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			if ( ! is_array( $failure ) ) {
				continue;
			}
			$row    = array();
			$metric = self::project_public_token( $failure['metric'] ?? null, 128 );
			if ( '' !== $metric ) {
				$row['metric'] = $metric;
			}
			foreach ( array( 'actual', 'maximum', 'minimum' ) as $field ) {
				if ( is_numeric( $failure[ $field ] ?? null ) ) {
					$row[ $field ] = $failure[ $field ] + 0;
				}
			}
			$source_path = self::project_public_source_path( $failure['source_path'] ?? null );
			if ( '' !== $source_path ) {
				$row['source_path'] = $source_path;
			}
			if ( empty( $row ) ) {
				continue;
			}
			$detail = self::project_public_detail( $failure['message'] ?? $failure['detail'] ?? null, 128 );
			if ( '' !== $detail ) {
				$row['detail'] = $detail;
			}
			$rows[] = $row;
			if ( self::FAILURE_DIAGNOSTIC_MAX_FACTS <= count( $rows ) ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Keep validator findings addressable: the pointer says which entity, the detail says which rule.
	 *
	 * @param mixed $errors
	 * @return array<int,array<string,mixed>>
	 */
	private static function project_public_validation_errors( $errors ): array {
		if ( ! is_array( $errors ) ) {
			return array();
		}
		$rows    = array();
		$scanned = 0;
		foreach ( $errors as $error ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			$row  = array();
			$path = self::project_public_source_path( is_array( $error ) ? ( $error['path'] ?? null ) : null );
			if ( '' !== $path ) {
				$row['path'] = $path;
			}
			$detail = self::project_public_detail( is_array( $error ) ? ( $error['message'] ?? $error['detail'] ?? null ) : $error, 128 );
			if ( '' !== $detail ) {
				$row['detail'] = $detail;
			}
			if ( empty( $row ) ) {
				continue;
			}
			$rows[] = $row;
			if ( self::FAILURE_DIAGNOSTIC_MAX_FACTS <= count( $rows ) ) {
				break;
			}
		}
		return $rows;
	}

	private static function project_public_source_path( $value ): string {
		$value = self::project_public_text( $value );
		if ( '' === $value || str_contains( $value, '\\' ) || str_contains( $value, '..' ) || preg_match( '/^[A-Za-z]:|[?#:]/', $value ) || preg_match( '#^(?:https?|file)://#i', $value ) ) {
			return '';
		}
		if ( str_starts_with( $value, '$' ) ) {
			return 1 === preg_match( '/^\$[.\[\]A-Za-z0-9_-]*$/', $value ) ? $value : '';
		}
		if ( str_starts_with( $value, '/' ) ) {
			return '';
		}
		return 1 === preg_match( '#^[\pL\pN][\pL\pN_.\-/]*$#u', $value ) ? $value : '';
	}

	/** @param array<string,mixed> $diagnostic */
	private static function project_public_reason( array $diagnostic ): string {
		if ( isset( $diagnostic['severity'] ) && 'error' !== $diagnostic['severity'] ) {
			// A warning is evidence, not the cause of the failure.
			return '';
		}
		$detail = self::project_public_detail( $diagnostic['detail'] ?? null );
		if ( '' === $detail && is_array( $diagnostic['errors'][0] ?? null ) ) {
			// An unattributed validator finding still says which rule rejected the import.
			$detail = trim( ( '' !== (string) ( $diagnostic['errors'][0]['path'] ?? '' ) ? $diagnostic['errors'][0]['path'] . ' — ' : '' ) . (string) ( $diagnostic['errors'][0]['detail'] ?? '' ) );
		}
		if ( '' === $detail ) {
			$detail = self::project_public_token( $diagnostic['reason'] ?? null, 128 );
		}
		if ( '' === $detail ) {
			return '';
		}
		$exception_class = self::project_public_token( $diagnostic['exception_class'] ?? null, 128 );
		$detail          = '' !== $exception_class ? $exception_class . ': ' . $detail : $detail;
		return preg_match( '/[.!?]$/', $detail ) ? $detail : $detail . '.';
	}

	/**
	 * Redact free text into a bounded single-line detail: URLs, absolute
	 * filesystem paths, and credential-shaped values never leave this boundary.
	 */
	private static function project_public_detail( $value, int $bytes = self::FAILURE_DIAGNOSTIC_MAX_BYTES ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$value = (string) $value;
		if ( ! preg_match( '//u', $value ) ) {
			return '';
		}
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		$value = (string) preg_replace( '#\b[A-Za-z][A-Za-z0-9+.-]*://\S*#', '[url]', $value );
		$value = (string) preg_replace( '/\bbearer\s+\S+/i', 'Bearer [redacted]', $value );
		$value = (string) preg_replace( '/\b((?:password|passwd|secret|token|authorization|api[_-]?key|cookie)[A-Za-z0-9_-]*)(["\']?\s*[:=]\s*)(?!Bearer \[redacted\])("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2[redacted]', $value );
		$value = (string) preg_replace( '#(?<![\w.~/<\\-])(?:~/|/)(?:[^\s/\'"(),;]+/)*[^\s/\'"(),;]+/?#u', '[path]', $value );
		$value = (string) preg_replace( '#(?<![\w])[A-Za-z]:\\\\[^\s\'"(),;]*#', '[path]', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return '' === $value ? '' : self::project_public_bounded( $value, $bytes );
	}

	private static function project_public_selector( $value ): string {
		$value = self::project_public_text( $value );
		return 1 === preg_match( '/^[A-Za-z0-9_.#\-\[\]=\s>+~,*]+$/', $value ) ? $value : '';
	}

	private static function project_public_token( $value, int $bytes, string $fallback = '' ): string {
		$value = self::project_public_text( $value, $bytes );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $value ) ? $value : $fallback;
	}

	private static function project_public_hash( $value ): string {
		$value = self::project_public_text( $value, 64 );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function project_public_import_id( $value ): string {
		return self::project_public_identity( $value );
	}

	/** An importer-owned identifier: a slug-shaped token, or the digest such identifiers are usually derived as. */
	private static function project_public_identity( $value ): string {
		$token = self::project_public_token( $value, 128 );
		return '' !== $token ? $token : self::project_public_hash( $value );
	}

	private static function project_public_text( $value, int $bytes = self::FAILURE_DIAGNOSTIC_MAX_BYTES ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/(?:password|secret|token|authorization|api[_-]?key|cookie|bearer)\s*[:=]?/i', $value ) || preg_match( '#(?:https?|file)://#i', $value ) || ! preg_match( '//u', $value ) ) {
			return '';
		}
		return self::project_public_bounded( $value, $bytes );
	}

	private static function project_public_bounded( string $value, int $bytes ): string {
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		preg_match_all( '/./us', $value, $characters );
		$text = '';
		foreach ( $characters[0] as $character ) {
			if ( $bytes < strlen( $text . $character ) ) {
				break;
			}
			$text .= $character;
		}
		return $text;
	}
}
