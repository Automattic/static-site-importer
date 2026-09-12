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
		if ( ! is_singular() && ! is_front_page() ) {
			return $title;
		}

		$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), self::PROVENANCE_META_KEY, true ), true );
		if ( ! is_array( $provenance ) || 'static-site-importer/page-provenance/v1' !== ( $provenance['schema'] ?? null ) ) {
			return $title;
		}

		$document_title = self::normalize_title( $provenance['document_title'] ?? '' );
		return '' !== $document_title ? $document_title : $title;
	}

	/**
	 * Materialize the route-title filter into the generated theme so imported
	 * sites keep source document titles after the importer plugin is gone.
	 *
	 * @param array<string,mixed> $resolved_plan
	 * @param array<string,mixed> $bootstrap_overlay
	 * @return array<string,mixed>
	 */
	public static function prepare_overlay( array $resolved_plan, array $bootstrap_overlay = array() ): array {
		$bootstrap = self::bootstrap_content( $resolved_plan, $bootstrap_overlay );
		$marker    = '/* Static Site Importer authored route document titles. */';
		if ( ! str_contains( $bootstrap, $marker ) ) {
			$bootstrap .= "\n{$marker}\nadd_filter( 'pre_get_document_title', static function ( \$title ): string {\n\tif ( ! is_singular() && ! is_front_page() ) {\n\t\treturn \$title;\n\t}\n\t\$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), '_static_site_importer_provenance', true ), true );\n\tif ( ! is_array( \$provenance ) || 'static-site-importer/page-provenance/v1' !== ( \$provenance['schema'] ?? null ) ) {\n\t\treturn \$title;\n\t}\n\t\$document_title = isset( \$provenance['document_title'] ) && is_scalar( \$provenance['document_title'] ) ? trim( wp_strip_all_tags( html_entity_decode( (string) \$provenance['document_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';\n\treturn '' !== \$document_title && 1000 >= strlen( \$document_title ) ? \$document_title : \$title;\n} );\n";
		}

		return array(
			'status' => 'materialized',
			'writes' => array(
				array(
					'target_path' => 'functions.php',
					'content'     => $bootstrap,
					'encoding'    => 'utf8',
					'source_path' => 'static-site-importer/route-document-titles',
				),
			),
		);
	}

	/** @param array<string,mixed> $resolved_plan @param array<string,mixed> $overlay */
	private static function bootstrap_content( array $resolved_plan, array $overlay ): string {
		foreach ( array_reverse( $overlay['writes'] ?? array() ) as $write ) {
			if ( is_array( $write ) && 'functions.php' === ( $write['target_path'] ?? null ) && is_string( $write['content'] ?? null ) ) {
				return $write['content'];
			}
		}
		foreach ( $resolved_plan['writes'] ?? array() as $write ) {
			if ( ! is_array( $write ) || 'functions.php' !== ( $write['target_path'] ?? null ) || ! is_array( $write['payload'] ?? null ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared plan payload encoding.
			$content = 'base64' === ( $write['payload']['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['payload']['data'] ?? '' ), true ) : $write['payload']['data'] ?? null;
			if ( is_string( $content ) && str_starts_with( ltrim( $content ), '<?php' ) ) {
				return $content;
			}
		}
		return "<?php\n";
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
		$title = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $decoded_title ) : strip_tags( $decoded_title ) );
		return strlen( $title ) <= 1000 ? $title : '';
	}
}
