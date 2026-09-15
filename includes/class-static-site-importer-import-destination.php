<?php
/**
 * Destination boundary for canonical site-plan materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves where a canonical plan materializes and what it may write there.
 *
 * A generated-theme import owns its whole theme directory, so it may write every
 * theme file the plan declares and may activate the result. An existing-theme
 * import lands inside a theme the destination site already owns, so the same
 * plan must not write that theme's scaffold, bootstrap, or templates, and must
 * never switch the active theme.
 *
 * Both destinations share one canonical plan, one resolver, one reconciliation
 * identity, and one materialization receipt. Only the write surface and the
 * activation right differ.
 */
final class Static_Site_Importer_Import_Destination {

	public const GENERATED_THEME = 'generated_theme';
	public const EXISTING_THEME  = 'existing_theme';

	/**
	 * Theme files an existing destination already owns.
	 *
	 * These describe the theme itself rather than imported content, so writing
	 * them into a destination-owned theme would replace the host's own design.
	 */
	private const HOST_OWNED_WRITE_KINDS = array( 'theme_scaffold', 'theme_bootstrap', 'theme_template' );

	/**
	 * Resolve the destination declared by canonical import arguments.
	 *
	 * @param array $args Canonical materialization arguments.
	 * @return array|WP_Error Destination descriptor, or an error when unusable.
	 */
	public static function normalize( array $args ) {
		$mode = isset( $args['destination'] ) && is_scalar( $args['destination'] ) ? (string) $args['destination'] : self::GENERATED_THEME;
		if ( ! in_array( $mode, array( self::GENERATED_THEME, self::EXISTING_THEME ), true ) ) {
			return new WP_Error( 'static_site_importer_destination_invalid', 'destination must be generated_theme or existing_theme.' );
		}
		if ( self::EXISTING_THEME === $mode ) {
			return self::existing_theme_destination();
		}
		return self::generated_theme_destination( $args );
	}

	/**
	 * Describe a new importer-owned theme directory.
	 *
	 * @param array $args Canonical materialization arguments.
	 * @return array|WP_Error
	 */
	private static function generated_theme_destination( array $args ) {
		$slug = sanitize_key( (string) ( $args['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_theme_slug', 'A generated-theme destination requires a theme slug.' );
		}
		$theme_root = get_theme_root();
		if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validates the native theme destination before atomic local writes.
			return new WP_Error( 'theme_destination_not_ready', 'The theme directory is not writable.' );
		}
		$theme_dir = trailingslashit( $theme_root ) . $slug;
		if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
			return new WP_Error( 'unsafe_theme_destination', 'The resolved theme destination is not a directory.' );
		}
		return array(
			'schema'             => 'static-site-importer/import-destination/v1',
			'mode'               => self::GENERATED_THEME,
			'slug'               => $slug,
			'theme_dir'          => $theme_dir,
			'theme_uri'          => trailingslashit( get_theme_root_uri() ) . $slug,
			'owns_theme'         => true,
			'permits_activation' => true,
		);
	}

	/**
	 * Describe the theme the destination site is already running.
	 *
	 * @return array|WP_Error
	 */
	private static function existing_theme_destination() {
		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_stylesheet_directory' ) ) {
			return new WP_Error( 'existing_theme_unavailable', 'The destination site has no resolvable active theme.' );
		}
		$slug      = (string) get_stylesheet();
		$theme_dir = (string) get_stylesheet_directory();
		if ( '' === $slug || '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'existing_theme_unavailable', 'The destination site has no resolvable active theme.' );
		}
		return array(
			'schema'             => 'static-site-importer/import-destination/v1',
			'mode'               => self::EXISTING_THEME,
			'slug'               => $slug,
			'theme_dir'          => $theme_dir,
			'theme_uri'          => (string) get_stylesheet_directory_uri(),
			'owns_theme'         => false,
			'permits_activation' => false,
		);
	}

	/**
	 * Whether the destination may write a declared plan write.
	 *
	 * @param array  $destination Destination descriptor.
	 * @param string $kind        Declared write kind.
	 */
	public static function permits_write( array $destination, string $kind ): bool {
		if ( ! empty( $destination['owns_theme'] ) ) {
			return true;
		}
		return ! in_array( $kind, self::HOST_OWNED_WRITE_KINDS, true );
	}

	/**
	 * Whether the destination may switch the site's active theme.
	 *
	 * @param array $destination Destination descriptor.
	 */
	public static function permits_activation( array $destination ): bool {
		return ! empty( $destination['permits_activation'] );
	}

	/**
	 * Report a write the destination withheld because the host theme owns it.
	 *
	 * Declared and withheld is a reviewable outcome; silently dropping a write
	 * would let an import claim a fidelity it never materialized.
	 *
	 * @param array  $destination Destination descriptor.
	 * @param string $kind        Declared write kind.
	 * @param string $target_path Declared target path.
	 */
	public static function withheld_write_diagnostic( array $destination, string $kind, string $target_path ): array {
		return array(
			'reason_code' => 'destination_withheld_host_theme_write',
			'severity'    => 'notice',
			'destination' => (string) ( $destination['mode'] ?? '' ),
			'kind'        => $kind,
			'target_path' => $target_path,
			'detail'      => 'The destination site owns this theme file, so the import did not replace it.',
		);
	}
}
