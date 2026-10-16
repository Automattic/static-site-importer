<?php
/**
 * Shared harness for the design-system report smokes.
 *
 * Loads the receipt, report, owner-handoff and CLI projections standalone and
 * provides a stubbed Blocks Engine plan whose `theme.design_system` matches the
 * `blocks-engine/design-system/v1` shape.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0 ) {
		return json_encode( $value, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stub for the WordPress function in a standalone harness.
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-diagnostic-projection.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-owner-handoff-evidence.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-document-metadata-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-block-document-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-receipt-projection.php';
require_once dirname( __DIR__, 2 ) . '/includes/cli.php';

/** A Blocks Engine `theme.design_system` (token report plus metrics). */
function ssi_design_system_fixture(): array {
	return array(
		'schema'         => 'blocks-engine/design-system/v1',
		'document_count' => 2,
		'palette'        => array(
			array(
				'slug'   => 'base',
				'color'  => '#ffffff',
				'role'   => 'base',
				'weight' => 120,
			),
			array(
				'slug'   => 'contrast',
				'color'  => '#111827',
				'role'   => 'contrast',
				'weight' => 90,
			),
			array(
				'slug'   => 'accent',
				'color'  => '#2563eb',
				'role'   => 'accent',
				'weight' => 12,
			),
		),
		'font_sizes'     => array(
			array(
				'slug'   => 'medium',
				'size'   => '1rem',
				'weight' => 80,
			),
		),
		'font_families'  => array(
			array(
				'slug'        => 'heading',
				'name'        => 'Playfair Display',
				'font_family' => '"Playfair Display", serif',
				'weight'      => 10,
			),
			array(
				'slug'        => 'body',
				'name'        => 'Inter',
				'font_family' => 'Inter, sans-serif',
				'weight'      => 70,
			),
		),
		'metrics'        => array(
			'preset_share'                  => 0.9632,
			'restyle_coverage'              => 0.875,
			'palette_size'                  => 3,
			'unused_font_families'          => array( 'Roboto' ),
			'generated_css_bytes'           => 420,
			'source_css_bytes'              => 1000,
			'generated_to_source_css_ratio' => 0.42,
		),
	);
}

/** A stubbed canonical plan, with or without the design system. */
function ssi_design_system_plan( bool $with_design_system ): array {
	$theme = array(
		'stylesheet' => 'style.css',
		'theme_json' => 'theme.json',
	);
	if ( $with_design_system ) {
		$theme['design_system'] = ssi_design_system_fixture();
	}
	return array(
		'plan_identity'  => array(
			'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
			'hash'   => str_repeat( 'a', 64 ),
		),
		'theme'          => $theme,
		'pages'          => array(),
		'template_parts' => array(),
		'assets'         => array(),
		'writes'         => array(),
		'source'         => array(
			'source_hash' => str_repeat( 'b', 64 ),
			'provenance'  => array(),
		),
		'quality'        => array(
			'editability_policy' => array(
				'schema' => 'blocks-engine/editability-policy/v1',
				'status' => 'passed',
			),
		),
	);
}

/** A completed receipt composed through the real receipt projection. */
function ssi_design_system_receipt( array $plan, array $editability_report = array() ): array {
	return Static_Site_Importer_Site_Plan_Receipt::receipt(
		'completed',
		array(
			'plan'                     => $plan,
			'plan_identity'            => Static_Site_Importer_Site_Plan_Preparation::plan_identity( $plan ),
			'editability_report'       => Static_Site_Importer_Site_Plan_Preparation::editability_decision( $plan ),
			'design_system'            => Static_Site_Importer_Site_Plan_Preparation::design_system( $plan, $editability_report ),
			'diagnostics'              => array(),
			'applied'                  => array(
				'posts'      => array(),
				'files'      => array(),
				'operations' => array(),
			),
			'skipped'                  => array(),
			'existing_matches'         => array( 'pages' => array() ),
			'quality_budget_admission' => array( 'status' => 'not_requested' ),
			'theme_materialization'    => array( 'status' => 'block_theme' ),
		)
	);
}

/** The import report, composed and finalized through the real receipt projection. */
function ssi_design_system_report( array $plan, array $receipt ): Static_Site_Importer_Import_Report {
	$receipt['theme'] = array(
		'slug' => 'design-system-smoke',
		'dir'  => sys_get_temp_dir() . '/design-system-smoke',
	);
	$projection       = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), array(), array(), array(), 'design-system-smoke', array(), array() );
	$quality          = Static_Site_Importer_Report_Diagnostics::finalize_report( $projection['report'], array() );
	Static_Site_Importer_Receipt_Projection::finalize( $projection['report'], $projection['manifest'], $plan, $receipt, array(), $quality );
	return $projection['report'];
}

/** @return callable(bool,string):void */
function ssi_design_system_assert(): callable {
	return static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test assertion message, not output.
		}
	};
}
