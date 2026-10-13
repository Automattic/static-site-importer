<?php
/**
 * Source findings carried inside a website artifact.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}

/**
 * Reads `source-findings.json` (schema `static-site-importer/source-findings/v1`)
 * from the root of a website artifact and turns its rows into intake findings.
 *
 * Any producer that builds the artifact (a capture tool, an exporter, a
 * generator) writes the file next to the pages so units it dropped or degraded
 * stay visible in the import report. Each row describes itself: its loss class,
 * unit, severity and owning layer come from the row, validated against this
 * importer's vocabulary. The file is evidence, not site content: it is removed
 * from the artifact before compile.
 *
 * The file is optional. An artifact without it imports exactly as before, and a
 * file that cannot be read or fails validation never fails the import: it
 * becomes one `source_findings_unreadable` warning instead.
 *
 * Contract: docs/contracts/website-artifact-evidence.md.
 */
class Static_Site_Importer_Source_Findings {

	public const SCHEMA     = 'static-site-importer/source-findings/v1';
	public const PATH       = 'website/source-findings.json';
	public const UNREADABLE = 'source_findings_unreadable';

	/** Largest file read. The byte bound is the only row bound. */
	public const MAX_BYTES = 1048576;

	public const SEVERITIES   = array( 'warning', 'error', 'info' );
	public const UNITS        = array( 'route', 'page', 'block', 'asset', 'stylesheet', 'profile', 'item', 'file' );
	public const LOSS_CLASSES = array( Static_Site_Importer_Diagnostic_Loss_Classes::OMITTED_UNIT, Static_Site_Importer_Diagnostic_Loss_Classes::DEGRADED_UNIT );

	/** Slug shape for `owning_layer`, which is free text naming the producer layer. */
	private const OWNING_LAYER_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

	/**
	 * Remove the findings file from an artifact file list and project its rows.
	 *
	 * @param array<int,mixed> $files          Artifact files with `website/`-prefixed paths.
	 * @param object|null      $payload_reader Reader for `payload_reference` files.
	 * @return array{files:array<int,mixed>,findings:array<int,array<string,mixed>>}
	 */
	public static function extract( array $files, ?object $payload_reader = null ): array {
		$file = null;
		foreach ( $files as $index => $candidate ) {
			if ( is_array( $candidate ) && self::PATH === ( $candidate['path'] ?? null ) ) {
				$file = $candidate;
				unset( $files[ $index ] );
			}
		}
		if ( null === $file ) {
			return array(
				'files'    => $files,
				'findings' => array(),
			);
		}

		try {
			$bytes = self::read( $file, $payload_reader );
		} catch ( Throwable $error ) {
			$bytes = 'The file could not be read: ' . $error->getMessage();
		}
		$document = is_array( $bytes ) ? self::decode( $bytes['bytes'] ) : $bytes;
		$findings = is_array( $document ) ? self::findings( $document ) : $document;
		if ( ! is_array( $findings ) ) {
			$findings = array( self::unreadable( $findings ) );
		}

		return array(
			'files'    => array_values( $files ),
			'findings' => $findings,
		);
	}

	/**
	 * Project a validated findings document into report rows.
	 *
	 * Validation is all or nothing: one malformed row rejects the file, so a
	 * report never carries a partial ledger. A row whose `loss_class` or `unit`
	 * is outside this importer's vocabulary is well formed and is kept, visibly
	 * unrecognized.
	 *
	 * @param array<string,mixed> $document Decoded findings document.
	 * @return array<int,array<string,mixed>>|string Rows, or why the document is malformed.
	 */
	public static function findings( array $document ): array|string {
		$rows = array();
		foreach ( $document['findings'] as $index => $finding ) {
			$row = is_array( $finding ) ? self::row( $finding ) : 'is not an object';
			if ( ! is_array( $row ) ) {
				return 'Finding ' . (int) $index . ' ' . $row . '.';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Report row for one source finding.
	 *
	 * The row's own `loss_class`, `unit`, `severity` and `owning_layer` are
	 * authoritative. An unknown `loss_class` stays on the row, where
	 * classification records it as {@see Static_Site_Importer_Diagnostic_Loss_Classes::UNRECOGNIZED_LOSS_CLASS}
	 * drift. An unknown `unit` keeps its name and makes the row unrecognized
	 * the same way, since the loss it names cannot be counted.
	 *
	 * @param array<string,mixed> $finding Source finding.
	 * @return array<string,mixed>|string Row, or why the finding is malformed.
	 */
	private static function row( array $finding ): array|string {
		$code = is_string( $finding['code'] ?? null ) ? sanitize_key( $finding['code'] ) : '';
		if ( '' === $code ) {
			return 'has no code';
		}
		if ( ! in_array( $finding['severity'] ?? null, self::SEVERITIES, true ) ) {
			return 'has no severity of ' . implode( ', ', self::SEVERITIES );
		}
		foreach ( array( 'unit', 'loss_class' ) as $field ) {
			if ( ! is_string( $finding[ $field ] ?? null ) || '' === sanitize_key( $finding[ $field ] ) ) {
				return 'has no ' . $field;
			}
		}
		$owning_layer = $finding['owning_layer'] ?? null;
		if ( ! is_string( $owning_layer ) || strlen( $owning_layer ) > 64 || 1 !== preg_match( self::OWNING_LAYER_PATTERN, $owning_layer ) ) {
			return 'has no slug owning_layer';
		}
		if ( ! is_string( $finding['message'] ?? null ) ) {
			return 'has no message';
		}
		$location = $finding['location'] ?? null;
		$url      = is_array( $location ) && is_string( $location['url'] ?? null ) ? $location['url'] : '';
		$path     = is_array( $location ) && is_string( $location['path'] ?? null ) ? ltrim( $location['path'], '/' ) : '';
		if ( '' === $url && '' === $path ) {
			return 'has no location url or path';
		}
		if ( isset( $finding['metrics'] ) && ! is_array( $finding['metrics'] ) ) {
			return 'has metrics that are not an object';
		}

		$unit       = sanitize_key( $finding['unit'] );
		$loss_class = sanitize_key( $finding['loss_class'] );
		if ( ! in_array( $unit, self::UNITS, true ) && in_array( $loss_class, self::LOSS_CLASSES, true ) ) {
			$loss_class = Static_Site_Importer_Diagnostic_Loss_Classes::UNRECOGNIZED_LOSS_CLASS;
		}
		$context = '' !== $url ? array( 'url' => $url ) : array();
		if ( is_string( $location['profile'] ?? null ) && '' !== $location['profile'] ) {
			$context['profile'] = $location['profile'];
		}
		$row = array(
			'code'            => $code,
			'diagnostic_code' => $code,
			'type'            => $owning_layer,
			'severity'        => $finding['severity'],
			'stage'           => 'capture',
			'unit'            => $unit,
			'owning_layer'    => $owning_layer,
			'reason_code'     => $code,
			'source_path'     => '' !== $path ? 'website/' . $path : $url,
		);
		if ( '' !== $url ) {
			$row['url'] = $url;
		}

		return $row + array(
			'message'    => substr( $finding['message'], 0, 512 ),
			'metrics'    => array_filter( $finding['metrics'] ?? array(), 'is_numeric' ),
			'context'    => $context,
			'loss_class' => $loss_class,
		);
	}

	/**
	 * Single warning recorded in place of a findings file that cannot be read.
	 *
	 * @param string $reason Why the file was rejected.
	 * @return array<string,mixed>
	 */
	private static function unreadable( string $reason ): array {
		return array(
			'code'            => self::UNREADABLE,
			'diagnostic_code' => self::UNREADABLE,
			'type'            => 'static-site-importer',
			'severity'        => 'warning',
			'stage'           => 'archive_intake',
			'owning_layer'    => 'static-site-importer',
			'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION,
			'reason_code'     => self::UNREADABLE,
			'source_path'     => self::PATH,
			'path'            => self::PATH,
			'message'         => substr( 'Source findings were not read: ' . $reason, 0, 512 ),
		);
	}

	/**
	 * Read the file bytes from inline content or a payload reference.
	 *
	 * @param array<string,mixed> $file           Artifact file.
	 * @param object|null         $payload_reader Payload reader.
	 * @return array{bytes:string}|string File bytes, or why they could not be read.
	 */
	private static function read( array $file, ?object $payload_reader ): array|string {
		if ( isset( $file['content'] ) && is_string( $file['content'] ) ) {
			$bytes = $file['content'];
		} elseif ( isset( $file['content_base64'] ) && is_string( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes artifact payload content.
			$bytes = base64_decode( $file['content_base64'], true );
		} elseif ( is_array( $file['payload_reference'] ?? null ) && null !== $payload_reader && method_exists( $payload_reader, 'read' ) ) {
			$bytes = $payload_reader->read( $file['payload_reference'] );
		} else {
			return 'The file has no readable content.';
		}
		if ( ! is_string( $bytes ) ) {
			return 'The file content could not be decoded.';
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return 'The file exceeds ' . self::MAX_BYTES . ' bytes.';
		}

		return array( 'bytes' => $bytes );
	}

	/**
	 * Decode and validate the document envelope.
	 *
	 * @param string $bytes File bytes.
	 * @return array<string,mixed>|string Document, or why it is malformed.
	 */
	private static function decode( string $bytes ): array|string {
		$document = json_decode( $bytes, true, 16 );
		if ( ! is_array( $document ) ) {
			return 'The file is not a JSON object.';
		}
		if ( self::SCHEMA !== ( $document['schema'] ?? null ) ) {
			return 'Unsupported schema; expected ' . self::SCHEMA . '.';
		}
		if ( ! is_array( $document['findings'] ?? null ) || ! array_is_list( $document['findings'] ) ) {
			return 'The file has no findings list.';
		}
		$optional = array(
			'entrypoint' => 'is_string',
			'complete'   => 'is_bool',
			'usable'     => 'is_bool',
			'summary'    => 'is_array',
		);
		foreach ( $optional as $field => $is_type ) {
			if ( array_key_exists( $field, $document ) && ! $is_type( $document[ $field ] ) ) {
				return 'The ' . $field . ' field has the wrong type.';
			}
		}

		return $document;
	}
}
