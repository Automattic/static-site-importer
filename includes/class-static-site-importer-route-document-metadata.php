<?php
/**
 * Projects canonical source document metadata onto materialized routes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Route_Document_Metadata {
	private const PROVENANCE_META_KEY = '_static_site_importer_provenance';

	public static function register(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ) );
	}

	public static function filter_document_title( string $title ): string {
		if ( ! is_singular() ) {
			return $title;
		}

		$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), self::PROVENANCE_META_KEY, true ), true );
		if ( ! is_array( $provenance ) || 'static-site-importer/page-provenance/v1' !== ( $provenance['schema'] ?? null ) ) {
			return $title;
		}

		$document_title = self::normalize_title( $provenance['document_title'] ?? '' );
		return '' !== $document_title ? $document_title : $title;
	}

	/** @param array<string,mixed> $page */
	public static function title_from_page( array $page ): string {
		$metadata = is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array();
		return self::normalize_title( $metadata['title'] ?? '' );
	}

	private static function normalize_title( mixed $title ): string {
		if ( ! is_scalar( $title ) ) {
			return '';
		}

		$decoded_title = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone materializer smoke tests run without WordPress tag helpers.
		$title         = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $decoded_title ) : strip_tags( $decoded_title ) );
		return strlen( $title ) <= 1000 ? $title : '';
	}
}
