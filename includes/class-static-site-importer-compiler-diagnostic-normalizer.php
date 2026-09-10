<?php
/**
 * Bounds compiler-owned diagnostics before they become SSI operator output.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class Static_Site_Importer_Compiler_Diagnostic_Normalizer {
	private const MESSAGE_BYTES = 512;
	private const CODE_BYTES    = 128;
	private const SOURCE_BYTES  = 128;
	private const STAGE_BYTES   = 128;
	private const CONTEXT_BYTES = 4096;
	private const CONTEXT_ITEMS = 20;
	private const CONTEXT_DEPTH = 3;
	private const CONTEXT_NODES = 100;
	private const SAMPLE_LIMIT  = 5;

	/** Project compiler rows into bounded operator diagnostics. */
	public static function normalize( array $diagnostics ): array {
		$direct                   = null;
		$total                    = 0;
		$samples                  = array();
		$by_code                  = array();
		$by_severity              = array(
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
			'info'    => 0,
		);
		$code_occurrences_omitted = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$row = self::row( $diagnostic );
			++$total;
			$direct = $row;
			++$by_severity[ $row['severity'] ];
			if ( count( $samples ) < self::SAMPLE_LIMIT ) {
				$samples[] = $row;
			}

			$code_key = self::code_key( $row['code'] );
			if ( isset( $by_code[ $code_key ] ) ) {
				++$by_code[ $code_key ]['count'];
			} elseif ( count( $by_code ) < self::CONTEXT_ITEMS ) {
				$by_code[ $code_key ] = array(
					'code'  => $row['code'],
					'count' => 1,
				);
			} else {
				// Code categories outside the fixed map still contribute to exact totals.
				++$code_occurrences_omitted;
			}
		}
		if ( 0 === $total ) {
			return array();
		}
		if ( 1 === $total ) {
			return array( $direct );
		}

		$codes = array();
		foreach ( $by_code as $entry ) {
			$codes[ $entry['code'] ] = $entry['count'];
		}
		$context = array(
			'diagnostic_count'       => $total,
			'diagnostic_by_code'     => $codes,
			'diagnostic_by_severity' => $by_severity,
			'samples'                => $samples,
			'samples_omitted'        => $total - count( $samples ),
		);
		if ( $code_occurrences_omitted > 0 ) {
			$context['code_occurrences_omitted'] = $code_occurrences_omitted;
		}
		$nodes   = self::CONTEXT_NODES;
		$context = self::context( $context, self::CONTEXT_DEPTH, $nodes );
		$context = self::fit_context( $context );
		return array(
			array(
				'code'     => 'compiler_diagnostics_aggregated',
				'severity' => self::highest_severity( $by_severity ),
				'message'  => 'Compiler emitted ' . $total . ' diagnostics; showing ' . count( $samples ) . ' sample(s).',
				'context'  => $context,
			),
		);
	}

	private static function code_key( string $code ): string {
		return ':' . $code;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function row( array $row ): array {
		$normalized = array(
			'code'     => self::string( $row['code'] ?? $row['reason_code'] ?? $row['type'] ?? 'compiler_diagnostic', self::CODE_BYTES ),
			'severity' => self::severity( $row['severity'] ?? $row['level'] ?? 'warning' ),
			'message'  => self::string( $row['message'] ?? $row['reason'] ?? 'Compiler diagnostic.', self::MESSAGE_BYTES ),
		);
		foreach (
			array(
				'source' => self::SOURCE_BYTES,
				'stage'  => self::STAGE_BYTES,
			) as $field => $limit
		) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
				$normalized[ $field ] = self::string( $row[ $field ], $limit );
			}
		}
		if ( isset( $row['context'] ) && is_array( $row['context'] ) ) {
			$nodes   = self::CONTEXT_NODES;
			$context = self::context( $row['context'], self::CONTEXT_DEPTH, $nodes );
			$context = self::fit_context( $context );
			if ( ! empty( $context ) ) {
				$normalized['context'] = $context;
			}
		}
		return $normalized;
	}

	/** @param array<array-key,mixed> $value @return array<array-key,mixed> */
	private static function context( array $value, int $depth, int &$nodes ): array {
		$result = array();
		foreach ( array_slice( $value, 0, self::CONTEXT_ITEMS, true ) as $key => $item ) {
			if ( $nodes <= 0 ) {
				break;
			}
			$key = self::string( $key, 128 );
			if ( is_array( $item ) && $depth > 0 ) {
				--$nodes;
				$result[ $key ] = self::context( $item, $depth - 1, $nodes );
			} elseif ( is_scalar( $item ) || null === $item ) {
				--$nodes;
				$result[ $key ] = is_string( $item ) ? self::string( $item, self::MESSAGE_BYTES ) : $item;
			}
		}
		return $result;
	}

	/**
	 * Return a context whose complete JSON representation fits the public cap.
	 *
	 * Values are shortened first so aggregate structure remains useful. If keys,
	 * delimiters, or empty arrays still make it too large, remove trailing entries
	 * in insertion order until the serialized document fits.
	 *
	 * @param array<array-key,mixed> $context
	 * @return array<array-key,mixed>
	 */
	private static function fit_context( array $context ): array {
		while ( self::context_bytes( $context ) > self::CONTEXT_BYTES ) {
			if ( self::shorten_longest_string( $context ) ) {
				continue;
			}
			if ( ! self::remove_last_context_entry( $context ) ) {
				return array();
			}
		}
		return $context;
	}

	/** @param array<array-key,mixed> $context */
	private static function context_bytes( array $context ): int {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context, JSON_INVALID_UTF8_SUBSTITUTE ) : json_encode( $context, JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone smoke tests do not load WordPress encoding helpers.
		return is_string( $json ) ? strlen( $json ) : PHP_INT_MAX;
	}

	/** @param array<array-key,mixed> $value */
	private static function shorten_longest_string( array &$value ): bool {
		$longest = self::longest_string_bytes( $value );
		if ( 0 === $longest ) {
			return false;
		}
		return self::shorten_first_string( $value, $longest );
	}

	/** @param array<array-key,mixed> $value */
	private static function longest_string_bytes( array $value ): int {
		$longest = 0;
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$longest = max( $longest, self::longest_string_bytes( $item ) );
			} elseif ( is_string( $item ) ) {
				$longest = max( $longest, strlen( $item ) );
			}
		}
		return $longest;
	}

	/** @param array<array-key,mixed> $value */
	private static function shorten_first_string( array &$value, int $longest ): bool {
		foreach ( $value as &$item ) {
			if ( is_array( $item ) && self::shorten_first_string( $item, $longest ) ) {
				unset( $item );
				return true;
			}
			if ( is_string( $item ) && strlen( $item ) === $longest ) {
				$item = self::string( $item, intdiv( $longest, 2 ) );
				unset( $item );
				return true;
			}
		}
		unset( $item );
		return false;
	}

	/** @param array<array-key,mixed> $value */
	private static function remove_last_context_entry( array &$value ): bool {
		$keys = array_keys( $value );
		for ( $index = count( $keys ) - 1; $index >= 0; --$index ) {
			$key = $keys[ $index ];
			if ( is_array( $value[ $key ] ) && ! empty( $value[ $key ] ) && self::remove_last_context_entry( $value[ $key ] ) ) {
				return true;
			}
			unset( $value[ $key ] );
			return true;
		}
		return false;
	}

	private static function string( mixed $value, int $limit ): string {
		$value = is_scalar( $value ) || null === $value ? trim( (string) $value ) : '';
		$value = strlen( $value ) > $limit ? substr( $value, 0, $limit ) : $value;
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ) : json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone smoke tests do not load WordPress encoding helpers.
		return is_string( $json ) ? (string) json_decode( $json, true ) : '';
	}

	private static function severity( mixed $value ): string {
		$value = strtolower( self::string( $value, 16 ) );
		return in_array( $value, array( 'error', 'warning', 'notice', 'info' ), true ) ? $value : 'warning';
	}

	/** @param array<string,int> $counts */
	private static function highest_severity( array $counts ): string {
		foreach ( array( 'error', 'warning', 'notice', 'info' ) as $severity ) {
			if ( ! empty( $counts[ $severity ] ) ) {
				return $severity;
			}
		}
		return 'warning';
	}
}
