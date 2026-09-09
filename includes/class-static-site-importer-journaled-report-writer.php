<?php
/**
 * Journaled report artifact writer.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_WordPress_Site_Plan_Materializer' ) ) {
	require_once __DIR__ . '/class-static-site-importer-wordpress-site-plan-materializer.php';
}

/**
 * Streams public report projections into journaled atomic files.
 */
class Static_Site_Importer_Journaled_Report_Writer {

	/**
	 * Write a journaled JSON projection.
	 *
	 * @param string              $path    Projection destination.
	 * @param array<string,mixed> $payload Projection payload.
	 * @param array<string,mixed> $receipt Materialization receipt.
	 * @throws RuntimeException When the projection cannot be published.
	 */
	public static function write( string $path, array $payload, array &$receipt = array() ): void {
		Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_file( $receipt, $path );
		$temp    = tempnam( dirname( $path ), '.ssi-projection-' );
		$stream  = false !== $temp ? fopen( $temp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a public projection into an atomic temporary file.
		$written = is_resource( $stream ) && self::write_json_projection( $stream, $payload, 0 ) && self::write_all( $stream, "\n" ) && fflush( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes the complete temporary projection before publication.
		$closed  = ! is_resource( $stream ) || fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the complete temporary projection before publication.
		if ( ! $written || ! $closed || false === $temp || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename atomically publishes the complete preflighted artifact.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed atomic projection temporary file.
			}
			throw new RuntimeException( 'Failed to write a preflighted import artifact.' );
		}
	}

	/**
	 * Stream JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES without a full payload string.
	 *
	 * @param resource $stream Output stream.
	 * @param mixed    $value  JSON value.
	 * @param int      $depth  Current nesting depth.
	 * @return bool Whether all JSON bytes were written.
	 */
	private static function write_json_projection( $stream, mixed $value, int $depth ): bool {
		if ( 512 < $depth ) {
			return false;
		}
		if ( is_object( $value ) ) {
			$value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			return self::write_json_object( $stream, $value, $depth );
		}
		if ( ! is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return false !== $json && self::write_all( $stream, $json );
		}
		$is_list = self::json_list( $value );
		return self::write_json_container( $stream, $value, $depth, $is_list );
	}

	/**
	 * Write an object-shaped array.
	 *
	 * @param resource     $stream Output stream.
	 * @param array<mixed> $value  Object values.
	 * @param int          $depth  Current nesting depth.
	 * @return bool Whether all JSON bytes were written.
	 */
	private static function write_json_object( $stream, array $value, int $depth ): bool {
		return self::write_json_container( $stream, $value, $depth, false );
	}

	/**
	 * Write an array or object JSON container.
	 *
	 * @param resource     $stream  Output stream.
	 * @param array<mixed> $value   Container values.
	 * @param int          $depth   Current nesting depth.
	 * @param bool         $is_list Whether the container has list keys.
	 * @return bool Whether all JSON bytes were written.
	 */
	private static function write_json_container( $stream, array $value, int $depth, bool $is_list ): bool {
		if ( empty( $value ) ) {
			return self::write_all( $stream, $is_list ? '[]' : '{}' );
		}
		if ( ! self::write_all( $stream, $is_list ? '[' : '{' ) ) {
			return false;
		}
		$first = true;
		foreach ( $value as $key => $item ) {
			if ( ! self::write_all( $stream, $first ? "\n" : ",\n" ) || ! self::write_all( $stream, str_repeat( '    ', $depth + 1 ) ) ) {
				return false;
			}
			$first = false;
			if ( ! $is_list ) {
				$key_json = wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES );
				if ( false === $key_json || ! self::write_all( $stream, $key_json . ': ' ) ) {
					return false;
				}
			}
			if ( ! self::write_json_projection( $stream, $item, $depth + 1 ) ) {
				return false;
			}
		}
		return self::write_all( $stream, "\n" . str_repeat( '    ', $depth ) . ( $is_list ? ']' : '}' ) );
	}

	/**
	 * Write every byte or fail before the temporary projection can be published.
	 *
	 * @param resource $stream Output stream.
	 * @param string   $data   Bytes to write.
	 * @return bool Whether every byte was written.
	 */
	private static function write_all( $stream, string $data ): bool {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$written = fwrite( $stream, substr( $data, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Handles short writes while streaming a public projection.
			if ( ! is_int( $written ) || 0 >= $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/**
	 * Match PHP's array-to-JSON list detection without encoding an array.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool Whether the array has sequential integer keys.
	 */
	private static function json_list( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}
		return true;
	}
}
