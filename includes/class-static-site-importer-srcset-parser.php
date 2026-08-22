<?php
/**
 * Bounded srcset candidate parsing shared by importer collection and projection.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Srcset_Parser {
	/**
	 * Parse srcset candidates without treating URL-internal commas as separators.
	 *
	 * @return array<int,array{url:string,descriptor:string}>
	 */
	public static function parse( string $srcset ): array {
		$candidates = array();
		$length     = strlen( $srcset );
		$offset     = 0;
		while ( $offset < $length ) {
			while ( $offset < $length && ( ctype_space( $srcset[ $offset ] ) || ',' === $srcset[ $offset ] ) ) {
				++$offset;
			}
			if ( $offset >= $length ) {
				break;
			}
			$start = $offset;
			while ( $offset < $length && ! ctype_space( $srcset[ $offset ] ) ) {
				++$offset;
			}
			$url = substr( $srcset, $start, $offset - $start );
			while ( $offset < $length && ctype_space( $srcset[ $offset ] ) ) {
				++$offset;
			}
			$descriptor_start = $offset;
			while ( $offset < $length && ',' !== $srcset[ $offset ] ) {
				++$offset;
			}
			if ( '' !== $url ) {
				$candidates[] = array(
					'url'        => $url,
					'descriptor' => trim( substr( $srcset, $descriptor_start, $offset - $descriptor_start ) ),
				);
			}
		}
		return $candidates;
	}
}
