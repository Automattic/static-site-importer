<?php
/**
 * Source-route metadata identity for persistence and portable manifests.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Source_Route {
	public const META_KEY = '_static_site_importer_source_route';

	/** Public source path, excluding the artifact's website/ wrapper. */
	public static function public_source_route( string $source_path ): string {
		$path     = ltrim( str_replace( '\\', '/', $source_path ), '/' );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( array() === $segments ) {
					return '';
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$path = implode( '/', $segments );
		return str_starts_with( $path, 'website/' ) ? substr( $path, strlen( 'website/' ) ) : $path;
	}
}
