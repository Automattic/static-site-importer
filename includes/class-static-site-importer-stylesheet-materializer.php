<?php
/**
 * Generated theme stylesheet materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds generated theme stylesheet write payloads.
 */
class Static_Site_Importer_Stylesheet_Materializer {

	/**
	 * Build stylesheet writes for a generated block theme.
	 *
	 * @param string                  $theme_dir            Theme directory.
	 * @param string                  $theme_name           Theme name.
	 * @param string                  $css                  Source CSS.
	 * @param array<int,string>       $button_classes       Button wrapper classes observed during conversion.
	 * @param array<int,array<mixed>> $selector_provenance BAC selector provenance rows.
	 * @param callable                $style_builder        Callback that builds frontend style.css content.
	 * @param callable                $editor_style_builder Callback that builds editor-style.css content.
	 * @return array<string,string> Absolute stylesheet write paths mapped to file contents.
	 */
	public static function stylesheet_writes(
		string $theme_dir,
		string $theme_name,
		string $css,
		array $button_classes,
		array $selector_provenance,
		callable $style_builder,
		callable $editor_style_builder
	): array {
		return array(
			$theme_dir . '/style.css'                   => (string) $style_builder( $theme_name, $css, $button_classes, $selector_provenance ),
			$theme_dir . '/assets/css/editor-style.css' => (string) $editor_style_builder( $css, $button_classes, $selector_provenance ),
		);
	}
}
