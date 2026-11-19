<?php
/**
 * Theme hygiene counters for the import report quality gate.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Published_File_Mode' ) ) {
	require_once __DIR__ . '/class-static-site-importer-published-file-mode.php';
}

/**
 * Measures whether an imported theme is clean to edit and carries no dead weight.
 *
 * Blocks Engine emits most counters on the WordPress site plan; SSI measures
 * file modes and shipped runtime files after materialization. A counter whose
 * producer value is missing is `null` (unmeasured) and never fails the gate.
 */
final class Static_Site_Importer_Theme_Hygiene {

	/** Counters that fail `quality_pass` when greater than zero, by failure reason. */
	public const GATED = array(
		'non_public_theme_file_count'          => 'non_public_theme_file',
		'literal_preset_color_count'           => 'literal_preset_color',
		'empty_asset_count'                    => 'empty_asset',
		'unreferenced_engine_class_count'      => 'unreferenced_engine_class',
		'unnamed_preset_count'                 => 'unnamed_preset',
		'duplicate_preset_name_count'          => 'duplicate_preset_name',
		'unused_runtime_file_count'            => 'unused_runtime_file',
		'bootstrap_unit_without_feature_count' => 'bootstrap_unit_without_feature',
	);

	/** Counters that are reported, never gated. */
	public const REPORTED = array( 'engine_class_token_share' );

	/**
	 * Plan features a Blocks Engine theme bootstrap unit may serve
	 * (`theme.bootstrap_units[].feature`).
	 */
	public const BOOTSTRAP_FEATURES = array(
		'accordion_icons',
		'block_whitespace',
		'breadcrumb_labels',
		'button_variations',
		'choice_groups',
		'dialogs',
		'document_attributes',
		'editor_interaction',
		'editor_resting_state',
		'editor_stylesheets',
		'iframe_embeds',
		'image_selection',
		'inline_svg_images',
		'listing_fields',
		'listing_overlay',
		'listing_query',
		'native_emoji',
		'native_input',
		'native_select',
		'native_textarea',
		'navigation_ancestors',
		'navigation_anchor',
		'navigation_container',
		'navigation_header_offset',
		'navigation_links',
		'navigation_opener',
		'navigation_reference_items',
		'navigation_route_state',
		'navigation_submenus',
		'post_title_link_class',
		'responsive_fragments',
		'runtime_attributes',
		'script_attributes',
		'scripts',
		'scroll_state',
		'single_source_images',
		'social_link_attribution',
		'source_text',
		'stylesheet_attributes',
		'stylesheets',
		'template_assets',
		'template_frame',
		'template_parts',
		'viewport_entrances',
		'wordmark',
	);

	/**
	 * Every counter, unmeasured.
	 *
	 * @return array<string,null>
	 */
	public static function defaults(): array {
		return array_fill_keys( array_merge( array_keys( self::GATED ), self::REPORTED ), null );
	}

	/**
	 * Measure every counter for a materialized site plan receipt.
	 *
	 * @param array<string,mixed> $receipt Materialization receipt carrying the plan.
	 * @return array<string,int|float|null>
	 */
	public static function measure( array $receipt ): array {
		$plan           = is_array( $receipt['plan'] ?? null ) ? $receipt['plan'] : array();
		$quality        = is_array( $plan['quality']['metrics'] ?? null ) ? $plan['quality']['metrics'] : array();
		$design_metrics = is_array( $plan['theme']['design_system']['metrics'] ?? null ) ? $plan['theme']['design_system']['metrics'] : array();

		return array(
			'non_public_theme_file_count'          => self::non_public_theme_file_count( $receipt ),
			'literal_preset_color_count'           => self::count_of( $design_metrics, 'literal_preset_color_count' ),
			'empty_asset_count'                    => self::count_of( $quality, 'empty_asset_count' ),
			'unreferenced_engine_class_count'      => self::count_of( $quality, 'unreferenced_engine_class_count' ),
			'unnamed_preset_count'                 => self::count_of( $design_metrics, 'unnamed_preset_count' ),
			'duplicate_preset_name_count'          => self::count_of( $design_metrics, 'duplicate_preset_name_count' ),
			'unused_runtime_file_count'            => self::unused_runtime_file_count( $receipt ),
			'bootstrap_unit_without_feature_count' => self::bootstrap_unit_without_feature_count( $plan['theme']['bootstrap_units'] ?? null ),
			'engine_class_token_share'             => is_int( $quality['engine_class_token_share'] ?? null ) || is_float( $quality['engine_class_token_share'] ?? null ) ? $quality['engine_class_token_share'] : null,
		);
	}

	/**
	 * Failure reasons for gated counters above zero. Unmeasured counters pass.
	 *
	 * @param array<string,mixed> $quality Report quality.
	 * @return array<int,string>
	 */
	public static function failure_reasons( array $quality ): array {
		$reasons = array();
		foreach ( self::GATED as $counter => $reason ) {
			if ( is_int( $quality[ $counter ] ?? null ) && $quality[ $counter ] > 0 ) {
				$reasons[] = $reason;
			}
		}
		return $reasons;
	}

	/**
	 * Theme bootstrap units whose feature is empty or not a plan feature.
	 *
	 * @param mixed $units `theme.bootstrap_units`.
	 */
	public static function bootstrap_unit_without_feature_count( $units ): ?int {
		if ( ! is_array( $units ) || ! array_is_list( $units ) ) {
			return null;
		}
		$count = 0;
		foreach ( $units as $unit ) {
			$feature = is_array( $unit ) ? ( $unit['feature'] ?? '' ) : '';
			if ( ! is_string( $feature ) || ! in_array( $feature, self::BOOTSTRAP_FEATURES, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * A producer count, or null when the producer did not emit it.
	 *
	 * @param array<string,mixed> $metrics Producer metrics.
	 * @param string              $key     Counter key.
	 */
	private static function count_of( array $metrics, string $key ): ?int {
		return is_int( $metrics[ $key ] ?? null ) ? max( 0, $metrics[ $key ] ) : null;
	}

	/**
	 * Published theme and companion plugin files not at the site file mode.
	 *
	 * A generated theme is checked whole; an existing theme only for the files
	 * the import wrote. Null when no destination files exist to measure.
	 *
	 * @param array<string,mixed> $receipt Materialization receipt.
	 */
	private static function non_public_theme_file_count( array $receipt ): ?int {
		$theme_dir = is_string( $receipt['theme']['dir'] ?? null ) ? rtrim( $receipt['theme']['dir'], '/' ) : '';
		$files     = array();
		if ( '' !== $theme_dir && is_dir( $theme_dir ) ) {
			// An existing (host) theme is the site's own; only the import's writes are measured.
			if ( 'existing_theme' !== ( $receipt['destination']['mode'] ?? '' ) ) {
				$walker = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $walker as $file ) {
					$files[] = $file->getPathname();
				}
			} else {
				foreach ( is_array( $receipt['completed']['files'] ?? null ) ? $receipt['completed']['files'] : array() as $file ) {
					if ( is_array( $file ) && is_string( $file['target_path'] ?? null ) ) {
						$files[] = $theme_dir . '/' . $file['target_path'];
					}
				}
			}
		}
		$companion = is_array( $receipt['completed']['companion_plugin'] ?? null ) ? $receipt['completed']['companion_plugin'] : array();
		$base      = ! empty( $companion['mu_plugin'] ) ? ( defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '' ) : ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' );
		if ( ! empty( $companion['installed'] ) && '' !== $base && is_array( $companion['files'] ?? null ) ) {
			foreach ( $companion['files'] as $relative ) {
				$files[] = rtrim( $base, '/' ) . '/' . $relative;
			}
		}
		$files = array_values( array_filter( $files, static fn( string $path ): bool => is_file( $path ) && ! is_link( $path ) ) );
		if ( array() === $files ) {
			return null;
		}
		$mode  = Static_Site_Importer_Published_File_Mode::file_mode();
		$count = 0;
		foreach ( $files as $path ) {
			clearstatcache( true, $path );
			if ( ( fileperms( $path ) & 0777 ) !== $mode ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Shipped theme runtime files that serve no runtime feature or generated
	 * block the site uses (#3108's `runtime_features` contract).
	 *
	 * @param array<string,mixed> $receipt Materialization receipt.
	 */
	private static function unused_runtime_file_count( array $receipt ): ?int {
		$runtime = is_array( $receipt['completed']['theme_runtime'] ?? null ) ? $receipt['completed']['theme_runtime'] : array();
		if ( ! is_array( $runtime['shipped_files'] ?? null ) || ! is_array( $runtime['runtime_features'] ?? null ) ) {
			return null;
		}
		require_once __DIR__ . '/class-static-site-importer-generated-runtime-package.php';
		return count( Static_Site_Importer_Generated_Runtime_Package::unused_files( $runtime['shipped_files'], $runtime['runtime_features'], is_array( $runtime['block_names'] ?? null ) ? $runtime['block_names'] : array() ) );
	}
}
