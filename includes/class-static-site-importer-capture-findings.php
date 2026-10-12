<?php
/**
 * Capture findings carried inside a Data Liberation Agent website artifact.
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
 * Reads `capture-findings.json` (schema `data-liberation-agent/capture-findings/v1`)
 * from the root of a website artifact and turns its rows into intake findings.
 *
 * Data Liberation Agent writes the file next to the exported pages so routes it
 * dropped or degraded during capture stay visible in the import report. The file
 * is evidence, not site content: it is removed from the artifact before compile.
 *
 * The file is optional. An artifact without it imports exactly as before, and a
 * file that cannot be read never fails the import: it becomes one
 * `capture_findings_unreadable` warning instead.
 *
 * Producer contract: data-liberation-agent docs/contracts/loss-ledger.md.
 */
class Static_Site_Importer_Capture_Findings {

	public const SCHEMA       = 'data-liberation-agent/capture-findings/v1';
	public const PATH         = 'website/capture-findings.json';
	public const OWNING_LAYER = 'data-liberation-agent';
	public const UNREADABLE   = 'capture_findings_unreadable';

	/** Largest file read; DLA bounds the file well below this. */
	public const MAX_BYTES = 1048576;

	/** Most finding rows carried into the report. */
	public const MAX_FINDINGS = 500;

	/**
	 * Capture code => SSI loss class.
	 *
	 * Omitted family: the route produced no page. Degraded family: the page
	 * imported without part of its source. Both import and are reported. A
	 * code not listed here is a degraded loss.
	 *
	 * @var array<string,string>
	 */
	private const LOSS_CLASSES = array(
		'route_capture_failed'     => Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED,
		'route_export_failed'      => Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED,
		'route_path_collision'     => Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED,
		'route_capture_degraded'   => Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS,
		'route_profile_uncaptured' => Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS,
		'stylesheet_unparseable'   => Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS,
		'native_timeline_unproven' => Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS,
		'disclosure_unrestored'    => Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS,
		// Outside the site's origin: not part of the site, so nothing was lost.
		'route_off_origin'         => Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION,
	);

	private const UNITS = array( 'route', 'profile', 'stylesheet', 'block' );

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
	 * @param array<string,mixed> $document Decoded findings document.
	 * @return array<int,array<string,mixed>>|string Rows, or why the document is malformed.
	 */
	public static function findings( array $document ): array|string {
		$rows    = array();
		$covered = array();
		foreach ( array_slice( $document['findings'], 0, self::MAX_FINDINGS ) as $finding ) {
			if ( ! is_array( $finding ) || ! is_string( $finding['code'] ?? null ) || ! is_string( $finding['url'] ?? null ) ) {
				return 'A finding row has no code or url.';
			}
			$rows[] = self::row( $finding );
			if ( Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED === self::loss_class( $finding['code'] ) ) {
				$covered[ $finding['url'] ] = true;
			}
		}
		// Every missing route is counted once, even when its finding row was bounded out.
		foreach ( $document['missing_routes'] as $route ) {
			if ( ! is_array( $route ) || ! is_string( $route['url'] ?? null ) || ! is_string( $route['code'] ?? null ) ) {
				return 'A missing route has no code or url.';
			}
			if ( isset( $covered[ $route['url'] ] ) ) {
				continue;
			}
			$covered[ $route['url'] ] = true;
			$rows[]                   = self::row(
				array(
					'code'   => $route['code'],
					'url'    => $route['url'],
					'unit'   => 'route',
					'reason' => $route['reason'] ?? '',
				)
			);
		}

		return $rows;
	}

	/**
	 * Loss class for a capture finding code.
	 *
	 * @param string $code Capture finding code.
	 */
	public static function loss_class( string $code ): string {
		return self::LOSS_CLASSES[ $code ] ?? Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS;
	}

	/**
	 * Report row for one capture finding.
	 *
	 * @param array<string,mixed> $finding Capture finding.
	 * @return array<string,mixed>
	 */
	private static function row( array $finding ): array {
		$code     = sanitize_key( $finding['code'] );
		$url      = (string) $finding['url'];
		$path     = is_string( $finding['path'] ?? null ) && '' !== $finding['path'] ? 'website/' . ltrim( $finding['path'], '/' ) : '';
		$unit     = in_array( $finding['unit'] ?? null, self::UNITS, true ) ? $finding['unit'] : 'route';
		$severity = in_array( $finding['severity'] ?? null, array( 'warning', 'error' ), true ) ? $finding['severity'] : 'warning';
		$context  = array( 'url' => $url );
		if ( is_string( $finding['profile'] ?? null ) && '' !== $finding['profile'] ) {
			$context['profile'] = $finding['profile'];
		}
		if ( is_array( $finding['metrics'] ?? null ) ) {
			$context['metrics'] = array_filter( $finding['metrics'], 'is_numeric' );
		}

		return array(
			'code'            => $code,
			'diagnostic_code' => $code,
			'type'            => self::OWNING_LAYER,
			'severity'        => $severity,
			'stage'           => 'capture',
			'unit'            => $unit,
			'owning_layer'    => self::OWNING_LAYER,
			'loss_class'      => self::loss_class( $code ),
			'reason_code'     => $code,
			'source_path'     => '' !== $path ? $path : $url,
			'url'             => $url,
			'message'         => substr( is_scalar( $finding['reason'] ?? null ) ? (string) $finding['reason'] : '', 0, 512 ),
			'context'         => $context,
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
			'message'         => substr( 'Capture findings were not read: ' . $reason, 0, 512 ),
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
	 * Decode and validate the document shape.
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
		if ( ! is_array( $document['findings'] ?? null ) || ! is_array( $document['missing_routes'] ?? null ) ) {
			return 'The file has no findings or missing_routes list.';
		}

		return $document;
	}
}
