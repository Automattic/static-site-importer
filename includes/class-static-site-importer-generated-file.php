<?php
/** Generated-file data boundary helpers. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Generated_File {
	/** Keep imported values inside one bounded comment-header line. */
	public static function comment_header_value( string $value, int $maximum_length = 200 ): string {
		$value = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $value );
		$value = str_replace( '*/', '* /', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
		return substr( $value, 0, $maximum_length );
	}
}
