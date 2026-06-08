<?php
/**
 * Local asset import reporting helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initializes asset-map and local asset policy report fields.
 */
class Static_Site_Importer_Asset_Reporter {
	/**
	 * Initialize report fields for the caller-supplied local asset policy.
	 *
	 * @param array<string,mixed> $report Conversion report envelope, passed by reference.
	 * @param array<string,mixed> $args   Import args.
	 * @return string|WP_Error Normalized local asset materialization policy, or error.
	 */
	public static function initialize_report( array &$report, array $args ) {
		$asset_policy = self::normalize_asset_materialization_policy( $args['asset_materialization_policy'] ?? '' );
		if ( is_wp_error( $asset_policy ) ) {
			return $asset_policy;
		}

		$asset_map = self::normalize_asset_map( isset( $args['asset_map'] ) && is_array( $args['asset_map'] ) ? $args['asset_map'] : array() );

		$report['assets']['policy']         = 'theme';
		$report['assets']['local_policy']   = $asset_policy;
		$report['asset_map']['supplied']    = ! empty( $asset_map );
		$report['asset_map']['entry_count'] = count( $asset_map );

		return $asset_policy;
	}

	/**
	 * Normalize caller-supplied asset map entries by source-relative key.
	 *
	 * @param array<string, mixed> $asset_map Raw asset map.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_asset_map( array $asset_map ): array {
		$normalized = array();
		foreach ( $asset_map as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}

			$path = self::normalize_asset_map_key( $key );
			if ( '' === $path ) {
				continue;
			}

			$normalized[ $path ] = $entry;
		}

		return $normalized;
	}

	/**
	 * Normalize and validate the local asset materialization policy.
	 *
	 * @param mixed $policy Raw policy value.
	 * @return string|WP_Error
	 */
	private static function normalize_asset_materialization_policy( $policy ) {
		$policy = is_string( $policy ) ? sanitize_key( $policy ) : '';
		if ( '' === $policy ) {
			return 'copy_to_theme';
		}

		if ( in_array( $policy, array( 'copy_to_theme', 'use_map' ), true ) ) {
			return $policy;
		}

		return new WP_Error( 'static_site_importer_invalid_asset_materialization_policy', 'Asset materialization policy must be one of: copy_to_theme, use_map.' );
	}

	/**
	 * Normalize an asset-map key without allowing traversal outside the source root.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private static function normalize_asset_map_key( string $path ): string {
		$path     = str_replace( '\\', '/', html_entity_decode( $path, ENT_QUOTES ) );
		$path     = ltrim( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( empty( $segments ) ) {
					return '';
				}

				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}
}
