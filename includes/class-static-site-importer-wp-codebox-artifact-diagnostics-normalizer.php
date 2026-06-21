<?php
/**
 * WP Codebox artifact diagnostics normalization adapter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes arbitrary observation/import-report diagnostics into the WP Codebox artifact diagnostics envelope.
 */
class Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer {

	/**
	 * Build a wp-codebox/artifact-diagnostics/v1 envelope.
	 *
	 * @param mixed                $input   Observation, observation list, or import-report shape.
	 * @param array<string,mixed> $options Normalization defaults.
	 * @return array<string,mixed>
	 */
	public static function build( mixed $input, array $options = array() ): array {
		$observations = array_is_list( $input ) ? $input : array( $input );
		$diagnostics  = array();

		foreach ( $observations as $observation_index => $observation ) {
			array_push( $diagnostics, ...self::diagnostics_from_observation( $observation, $observation_index, $options ) );
		}

		$summary = array(
			'total'   => count( $diagnostics ),
			'error'   => count( array_filter( $diagnostics, static fn ( array $diagnostic ): bool => 'error' === ( $diagnostic['severity'] ?? '' ) ) ),
			'warning' => count( array_filter( $diagnostics, static fn ( array $diagnostic ): bool => 'warning' === ( $diagnostic['severity'] ?? '' ) ) ),
			'notice'  => count( array_filter( $diagnostics, static fn ( array $diagnostic ): bool => 'notice' === ( $diagnostic['severity'] ?? '' ) ) ),
			'info'    => count( array_filter( $diagnostics, static fn ( array $diagnostic ): bool => 'info' === ( $diagnostic['severity'] ?? '' ) ) ),
		);

		return array(
			'schema'      => 'wp-codebox/artifact-diagnostics/v1',
			'status'      => empty( $diagnostics ) ? 'clean' : 'reported',
			'summary'     => $summary,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Build artifact diagnostics for a Static Site Importer import report.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return array<string,mixed>
	 */
	public static function build_for_import_report( array $report ): array {
		return self::build(
			array( 'diagnostics' => $report['diagnostics'] ?? array() ),
			array(
				'source'          => 'blocks-engine',
				'stage'           => 'import',
				'observationType' => 'blocks-engine/import-report',
				'refs'            => array(
					array(
						'path' => 'import-report.json',
						'kind' => 'blocks-engine/import-report',
					),
				),
			)
		);
	}

	/**
	 * Normalize diagnostics from one observation/import report.
	 *
	 * @param mixed                $observation       Observation-like value.
	 * @param int                  $observation_index Observation index.
	 * @param array<string,mixed> $options           Normalization defaults.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostics_from_observation( mixed $observation, int $observation_index, array $options ): array {
		if ( ! is_array( $observation ) ) {
			return array();
		}

		$payload = isset( $observation['data'] ) && is_array( $observation['data'] ) ? $observation['data'] : $observation;
		$raw     = array_merge(
			self::array_payload( $payload['diagnostics'] ?? null ),
			self::array_payload( $payload['findings'] ?? null ),
			self::array_payload( $payload['issues'] ?? null ),
			self::array_payload( $payload['diagnostic'] ?? null )
		);

		if ( empty( $raw ) && ! array_key_exists( 'data', $observation ) && ! self::has_diagnostic_container( $observation ) ) {
			$raw[] = $observation;
		}

		$diagnostics = array();
		foreach ( $raw as $diagnostic_index => $diagnostic ) {
			$normalized = self::normalize_diagnostic( $diagnostic, $observation, $observation_index, $diagnostic_index, $options );
			if ( null !== $normalized ) {
				$diagnostics[] = $normalized;
			}
		}

		return $diagnostics;
	}

	/**
	 * Detect an observation wrapper with explicit diagnostic containers.
	 *
	 * @param array<string,mixed> $observation Observation wrapper.
	 * @return bool
	 */
	private static function has_diagnostic_container( array $observation ): bool {
		foreach ( array( 'diagnostics', 'findings', 'issues', 'diagnostic' ) as $key ) {
			if ( array_key_exists( $key, $observation ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize one diagnostic row.
	 *
	 * @param mixed                $raw               Raw diagnostic row.
	 * @param array<string,mixed> $observation       Observation wrapper.
	 * @param int                  $observation_index Observation index.
	 * @param int                  $diagnostic_index  Diagnostic index.
	 * @param array<string,mixed> $options           Normalization defaults.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_diagnostic( mixed $raw, array $observation, int $observation_index, int $diagnostic_index, array $options ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$type    = self::string_field( $raw['type'] ?? null ) ?: ( self::string_field( $raw['kind'] ?? null ) ?: ( self::string_field( $raw['code'] ?? null ) ?: ( self::string_field( $raw['reason_code'] ?? null ) ?: 'diagnostic' ) ) );
		$message = self::string_field( $raw['message'] ?? null ) ?: ( self::string_field( $raw['summary'] ?? null ) ?: ( self::string_field( $raw['reason'] ?? null ) ?: ( self::string_field( $raw['excerpt'] ?? null ) ?: ( self::string_field( $raw['error_message'] ?? null ) ?: $type ) ) ) );

		$detail_exclusions = array_flip(
			array(
				'id',
				'diagnostic_id',
				'type',
				'kind',
				'code',
				'reason_code',
				'message',
				'summary',
				'reason',
				'excerpt',
				'error_message',
				'severity',
				'category',
				'source',
				'path',
				'source_path',
				'selector',
				'stage',
				'refs',
				'references',
				'artifactRefs',
			)
		);
		$details           = array_diff_key( $raw, $detail_exclusions );

		$row = array(
			'id'         => self::string_field( $raw['id'] ?? null ) ?: ( self::string_field( $raw['diagnostic_id'] ?? null ) ?: ( ( self::string_field( $observation['id'] ?? null ) ?: 'observation-' . $observation_index ) . '-diagnostic-' . ( $diagnostic_index + 1 ) ) ),
			'type'       => $type,
			'severity'   => self::normalize_severity( $raw['severity'] ?? null ),
			'message'    => $message,
			'category'   => self::string_field( $raw['category'] ?? null ),
			'source'     => self::string_field( $raw['source'] ?? null ) ?: self::string_field( $options['source'] ?? null ),
			'path'       => self::string_field( $raw['path'] ?? null ) ?: self::string_field( $raw['source_path'] ?? null ),
			'selector'   => self::string_field( $raw['selector'] ?? null ),
			'stage'      => self::string_field( $raw['stage'] ?? null ) ?: self::string_field( $options['stage'] ?? null ),
			'code'       => self::string_field( $raw['code'] ?? null ) ?: self::string_field( $raw['reason_code'] ?? null ),
			'provenance' => self::strip_empty(
				array(
					'observationId'   => self::string_field( $observation['id'] ?? null ),
					'observationType' => self::string_field( $observation['type'] ?? null ) ?: self::string_field( $options['observationType'] ?? null ),
					'observedAt'      => self::string_field( $observation['observedAt'] ?? null ),
				)
			),
			'refs'       => self::diagnostic_refs( $raw['refs'] ?? ( $raw['references'] ?? ( $raw['artifactRefs'] ?? null ) ), isset( $options['refs'] ) && is_array( $options['refs'] ) ? $options['refs'] : array() ),
			'details'    => empty( $details ) ? null : $details,
		);

		return self::strip_empty( $row );
	}

	/**
	 * Normalize references.
	 *
	 * @param mixed             $raw      Raw refs.
	 * @param array<int,mixed> $defaults Default refs.
	 * @return array<int,array<string,string>>
	 */
	private static function diagnostic_refs( mixed $raw, array $defaults = array() ): array {
		$refs = array();
		foreach ( array_merge( $defaults, self::array_payload( $raw ) ) as $ref ) {
			if ( ! is_array( $ref ) ) {
				continue;
			}

			$normalized = self::strip_empty(
				array(
					'path' => self::string_field( $ref['path'] ?? null ),
					'kind' => self::string_field( $ref['kind'] ?? null ),
					'id'   => self::string_field( $ref['id'] ?? null ),
					'url'  => self::string_field( $ref['url'] ?? null ),
				)
			);
			if ( ! empty( $normalized ) ) {
				$refs[] = $normalized;
			}
		}

		return $refs;
	}

	/**
	 * Wrap object payloads like the WP Codebox normalizer.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,mixed>
	 */
	private static function array_payload( mixed $value ): array {
		if ( is_array( $value ) && array_is_list( $value ) ) {
			return $value;
		}

		return is_array( $value ) ? array( $value ) : array();
	}

	/**
	 * Return scalar strings only when meaningful.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function string_field( mixed $value ): ?string {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}

		return null;
	}

	/**
	 * Normalize severity.
	 *
	 * @param mixed $value Raw severity.
	 * @return string
	 */
	private static function normalize_severity( mixed $value ): string {
		$severity = strtolower( (string) ( self::string_field( $value ) ?? '' ) );

		return in_array( $severity, array( 'error', 'warning', 'notice', 'info' ), true ) ? $severity : 'warning';
	}

	/**
	 * Strip empty optional values from normalized rows.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function strip_empty( array $row ): array {
		return array_filter(
			$row,
			static fn ( mixed $value ): bool => ! ( '' === $value || array() === $value || null === $value )
		);
	}
}
