<?php
/**
 * Captured avatars for imported comments without author email addresses.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Restrict avatar overrides to explicitly marked captured comments. */
final class Static_Site_Importer_Comment_Avatar {
	/** Install the frontend avatar filter. */
	public static function register(): void {
		add_filter( 'pre_get_avatar_data', array( self::class, 'avatar_data' ), 10, 2 );
	}

	/** Preserve core behavior for every other avatar lookup. */
	public static function avatar_data( array $args, $id_or_email ): array {
		if ( ! $id_or_email instanceof WP_Comment || ! get_comment_meta( (int) $id_or_email->comment_ID, '_static_site_importer_source_comment', true ) ) {
			return $args;
		}
		$url = get_comment_meta( (int) $id_or_email->comment_ID, '_static_site_importer_avatar_url', true );
		if ( is_string( $url ) && '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}
}
