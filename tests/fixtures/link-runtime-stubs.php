<?php
/**
 * WordPress function stubs for running the internal-link runtime outside WordPress.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.WP.AlternativeFunctions -- Standalone test stubs.

function wp_json_encode( $value ) {
	return json_encode( $value );
}
function get_permalink( int $id ): string {
	return $GLOBALS['ssi_link_permalinks'][ $id ] ?? '';
}
function home_url( string $path = '' ): string {
	return rtrim( $GLOBALS['ssi_link_home'], '/' ) . '/' . ltrim( $path, '/' );
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the stub.
}
function get_page_by_path( string $path ) {
	$id = $GLOBALS['ssi_link_pages'][ $path ] ?? 0;
	return $id ? (object) array( 'ID' => $id ) : null;
}
function wp_login_url( string $redirect = '' ): string {
	return 'https://playground.test/scope:abc/wp-login.php' . ( '' !== $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
}
function esc_url( string $url ): string {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function is_ssl(): bool {
	return true;
}
