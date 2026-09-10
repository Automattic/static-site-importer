<?php
/**
 * Safe public projections for provider-controlled failure diagnostics.
 *
 * @package StaticSiteImporter
 */

class Static_Site_Importer_Public_Diagnostic_Projection {

	private const MAX_DIAGNOSTICS = 10;
	private const MAX_TEXT_BYTES  = 256;

	/** @param array<int,mixed> $diagnostics @return array<int,array<string,mixed>> */
	public static function diagnostics( array $diagnostics ): array {
		$projected = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$row = self::diagnostic( $diagnostic );
			if ( ! empty( $row ) ) {
				$projected[] = $row;
			}
			if ( self::MAX_DIAGNOSTICS <= count( $projected ) ) {
				break;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	public static function data( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( 'diagnostics' === $key && is_array( $value ) ) {
				$data[ $key ] = self::diagnostics( $value );
			} elseif ( 'message' === $key && is_string( $value ) ) {
				$data[ $key ] = 'Materialization failed.';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::data( $value );
			}
		}
		return $data;
	}

	/** @param array<int,array<string,mixed>> $diagnostics */
	public static function message( string $code, array $diagnostics = array() ): string {
		$source_path = isset( $diagnostics[0]['source_path'] ) ? (string) $diagnostics[0]['source_path'] : '';
		if ( '' !== $source_path ) {
			return 'Materialization failed for ' . $source_path . '.';
		}
		return 'Materialization failed (' . self::token( $code, 128, 'materialization_failed' ) . ').';
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private static function diagnostic( array $diagnostic ): array {
		$row = array();
		foreach ( array( 'code', 'type', 'kind', 'severity', 'declaration_id', 'entity_type', 'provider', 'reason_code', 'provider_availability_reason' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) ) {
				$row[ $field ] = self::token( $diagnostic[ $field ], 128 );
			}
		}
		if ( isset( $diagnostic['provider_available'] ) && is_bool( $diagnostic['provider_available'] ) ) {
			$row['provider_available'] = $diagnostic['provider_available'];
		}
		if ( isset( $diagnostic['loss_count'] ) && is_numeric( $diagnostic['loss_count'] ) ) {
			$row['loss_count'] = max( 0, (int) $diagnostic['loss_count'] );
		}
		if ( isset( $diagnostic['source_path'] ) ) {
			$source_path = self::source_path( $diagnostic['source_path'] );
			if ( '' !== $source_path ) {
				$row['source_path'] = $source_path;
			}
		}
		if ( isset( $diagnostic['selector'] ) ) {
			$selector = self::selector( $diagnostic['selector'] );
			if ( '' !== $selector ) {
				$row['selector'] = $selector;
			}
		}
		if ( isset( $diagnostic['reconciliation_identity'] ) && is_string( $diagnostic['reconciliation_identity'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $diagnostic['reconciliation_identity'] ) ) {
			$row['reconciliation_identity'] = $diagnostic['reconciliation_identity'];
		}
		if ( is_array( $diagnostic['binding_reconciliation_identities'] ?? null ) ) {
			$identities = array_values( array_filter( $diagnostic['binding_reconciliation_identities'], static fn( $identity ): bool => is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) );
			if ( ! empty( $identities ) ) {
				$row['binding_reconciliation_identities'] = array_slice( $identities, 0, self::MAX_DIAGNOSTICS );
			}
		}
		if ( empty( $row ) ) {
			return array();
		}
		if ( empty( $row['code'] ) ) {
			$row['code'] = 'materialization_failed';
		}
		$code           = is_string( $row['code'] ) ? $row['code'] : 'materialization_failed';
		$row['code']    = $code;
		$row['message'] = self::message( $code, array( $row ) );
		return $row;
	}

	private static function source_path( $value ): string {
		$value = self::text( $value );
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

	private static function selector( $value ): string {
		$value = self::text( $value );
		return 1 === preg_match( '/^[A-Za-z0-9_.#\-\[\]=\s>+~,*]+$/', $value ) ? $value : '';
	}

	private static function token( $value, int $bytes, string $fallback = '' ): string {
		$value = self::text( $value, $bytes );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $value ) ? $value : $fallback;
	}

	private static function text( $value, int $bytes = self::MAX_TEXT_BYTES ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/(?:password|secret|token|authorization|api[_-]?key|cookie|bearer)\s*[:=]?/i', $value ) || preg_match( '#(?:https?|file)://#i', $value ) || ! preg_match( '//u', $value ) ) {
			return '';
		}
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
