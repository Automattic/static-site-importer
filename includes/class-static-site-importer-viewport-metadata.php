<?php
/**
 * Reports authored viewport declarations that core's block canvas does not render.
 *
 * Core's block template canvas prints one standard viewport tag. A source
 * declaration with the same meaning is owned by core; any other declaration is
 * a named loss in the receipt, never a request-time override.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Viewport_Metadata {
	/** What `_block_template_viewport_meta_tag()` prints, as components. */
	private const CORE_DECLARATION = array(
		'initial-scale' => '1',
		'width'         => 'device-width',
	);

	/**
	 * @param array<string,mixed> $resolved_plan
	 * @return array{status:string,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function report( array $resolved_plan ): array {
		$declared = false;
		$lost     = array();
		foreach ( $resolved_plan['pages'] ?? array() as $page ) {
			// A synthetic route (a hub the site plan adds for nested routes) has no authored document.
			if ( ! is_array( $page ) || true === ( $page['synthetic'] ?? null ) ) {
				continue;
			}
			$metadata = is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array();
			foreach ( is_array( $metadata['meta'] ?? null ) ? $metadata['meta'] : array() as $row ) {
				if ( ! is_array( $row ) || 'viewport' !== strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
					continue;
				}
				$declared = true;
				if ( self::CORE_DECLARATION !== self::components( $row['content'] ?? null ) ) {
					$lost[] = is_string( $page['source_path'] ?? null ) ? $page['source_path'] : '';
					break;
				}
			}
		}
		if ( ! $declared ) {
			return array(
				'status'      => 'not_requested',
				'diagnostics' => array(),
			);
		}
		if ( array() === $lost ) {
			return array(
				'status'      => 'core_owned',
				'diagnostics' => array(),
			);
		}
		return array(
			'status'      => 'report_only',
			'diagnostics' => array(
				array(
					'code'         => 'static_site_importer_viewport_metadata_not_core_default',
					'type'         => 'static-site-importer',
					'severity'     => 'warning',
					'reason_code'  => 'viewport_metadata_not_core_default',
					'message'      => 'Authored viewport metadata differs from the viewport core renders, so it is not carried.',
					'source_paths' => array_values( array_unique( $lost ) ),
				),
			),
		);
	}

	/** @return array<string,string>|null Sorted components; null when malformed. */
	private static function components( mixed $content ): ?array {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}
		$components = array();
		foreach ( explode( ',', strtolower( $content ) ) as $component ) {
			$pair = array_map( 'trim', explode( '=', $component, 2 ) );
			if ( 2 !== count( $pair ) || '' === $pair[0] || '' === $pair[1] || isset( $components[ $pair[0] ] ) ) {
				return null;
			}
			$components[ $pair[0] ] = is_numeric( $pair[1] ) ? (string) (float) $pair[1] : $pair[1];
		}
		ksort( $components );
		return $components;
	}
}
