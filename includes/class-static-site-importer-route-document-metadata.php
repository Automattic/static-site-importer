<?php
/**
 * Source document titles belong to WordPress posts, never theme filters.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Route_Document_Metadata {
	/** @param array<string,mixed> $page */
	public static function title_from_page( array $page ): string {
		$title = $page['document_metadata']['title'] ?? '';
		if ( ! is_scalar( $title ) ) {
			return '';
		}
		$decoded = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Also used by standalone plan tests.
		$title = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $decoded ) : strip_tags( $decoded ) );
		return strlen( $title ) <= 1000 ? $title : '';
	}
}
