<?php
/**
 * Loss-ledger coverage for the import report.
 *
 * A site plan carrying every degrade code from the cross-layer loss ledger
 * (Automattic/data-liberation-agent docs/contracts/loss-ledger.md) is projected
 * through Receipt_Projection::compose() and finalized. Each code must land as an
 * accepted, reported loss with its unit and owning layer; every row must carry
 * the canonical finding fields; and the compact summary must keep code,
 * location and metrics, count losses, and keep losses ahead of informational
 * rows under its row cap.
 *
 * Run from the repository root:
 * php tests/smoke-import-report-loss-ledger.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '' ) {}
	}
}

$static_site_importer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $static_site_importer_autoload ) ) {
	require_once $static_site_importer_autoload;
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-capture-findings.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document-metadata-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-block-document-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-receipt-projection.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$be_compiler  = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
$be_manifest  = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\RuntimeEntityManifest';
$be_transform = 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer';

/*
 * Producer rows in the shapes Blocks Engine and SSI emit them today: flat
 * site-plan rows, ArtifactCompiler::diagnostic() rows with the page nested in
 * `context`, runtime manifest rows with `metrics`, and SSI's intake finding.
 */
$plan_rows = array(
	array(
		'code'        => 'page_compile_omitted',
		'severity'    => 'warning',
		'unit'        => 'page',
		'source_path' => 'website/pricing.html',
		'reason_code' => 'page_compile_representability_limit',
		'message'     => 'Form layout graph exceeds its node limit.',
	),
	array(
		'code'     => 'editability_policy_degraded',
		'severity' => 'warning',
		'message'  => 'website/about.html wrapper_to_content_ratio 9 exceeds 6.',
		'source'   => $be_compiler,
		'context'  => array(
			'policy_schema' => 'blocks-engine/editability-policy/v1',
			'metric'        => 'wrapper_to_content_ratio',
			'actual'        => 9,
			'maximum'       => 6,
			'source_path'   => 'website/about.html',
		),
	),
	array(
		'code'     => 'html_include_budget_exceeded',
		'severity' => 'warning',
		'message'  => 'A document exceeds the include count; its includes were left unexpanded.',
		'source'   => $be_compiler,
		'context'  => array(
			'source_path' => 'website/includes.html',
			'reason'      => 'html_include_count_exceeded: website/includes.html',
		),
	),
	array(
		'code'     => 'html_include_directive_ignored',
		'severity' => 'warning',
		'message'  => 'A document has a non-standard or unresolvable include directive; its includes were left unexpanded.',
		'source'   => $be_compiler,
		'context'  => array(
			'source_path' => 'website/directive.html',
			'reason'      => 'html_include_missing_path: partials/nav.html',
		),
	),
	array(
		'code'        => 'glyph_color_undecodable',
		'severity'    => 'warning',
		'message'     => 'A social icon glyph could not be decoded by this PHP image library, so its authored icon colour was not carried and core default icon colours apply.',
		'source'      => $be_transform,
		'mime_type'   => 'image/webp',
		'src'         => 'website/icons/x.webp',
		'source_path' => 'website/index.html',
	),
	array(
		'code'        => 'wordpress_site_plan_omitted_link_declaration',
		'severity'    => 'warning',
		'message'     => 'Omitted /manifest.json (manifest declaration), which names neither a captured asset nor an artifact route.',
		'source_path' => 'website/index.html',
		'rel'         => 'manifest',
		'value'       => '/manifest.json',
		'reason_code' => 'unresolved_local_url',
	),
	array(
		'code'          => 'wordpress_site_plan_unresolved_navigation_link',
		'severity'      => 'warning',
		'message'       => '3 more unresolved navigation links were resolved or neutralized; omitted from this diagnostic list.',
		'reason'        => 'truncated',
		'omitted_count' => 3,
	),
	array(
		'code'        => 'runtime_entity_dropped',
		'severity'    => 'warning',
		'message'     => 'A generated runtime entity was dropped with its bindings, so its block stays static.',
		'source'      => $be_manifest,
		'unit'        => 'item',
		'source_path' => 'website/shop.html',
		'selector'    => '.product-card',
		'reason_code' => 'runtime_entity_exceeds_byte_limit',
		'metrics'     => array(
			'bytes'       => 70000,
			'limit_bytes' => 65536,
		),
	),
	array(
		'code'            => 'runtime_declaration_dropped',
		'severity'        => 'warning',
		'message'         => 'The generated product entity collection was dropped with its dependency and bindings.',
		'source'          => $be_manifest,
		'unit'            => 'declaration',
		'source_path'     => 'website/shop.html',
		'declaration_key' => 'entity_collection:product',
		'reason_code'     => 'runtime_declaration_manifest_exceeds_byte_limit',
		'metrics'         => array(
			'entity_count' => 400,
			'limit_bytes'  => 65536,
		),
	),
	array(
		'code'        => 'runtime_dependency_target_missing',
		'severity'    => 'warning',
		'message'     => 'Script website/app.js references #slider, but the generated block markup does not expose that DOM target.',
		'source'      => $be_compiler,
		'unit'        => 'block',
		'source_path' => 'website/index.html',
		'selector'    => '#slider',
		'script_path' => 'website/app.js',
	),
	array(
		'code'                  => 'wordpress_site_plan_colliding_asset_target',
		'severity'              => 'warning',
		'unit'                  => 'asset',
		'message'               => 'Two assets resolved to one target with different content; the later one was given a suffixed target.',
		'source_path'           => 'website/b/style.css',
		'reason_code'           => 'colliding_asset_target',
		'colliding_source_path' => 'website/a/style.css',
		'target_path'           => 'assets/style.css',
		'resolved_target_path'  => 'assets/style-2.css',
		'omitted_count'         => 1,
	),
	array(
		'code'          => 'runtime_entity_binding_unanchored',
		'severity'      => 'warning',
		'unit'          => 'binding',
		'message'       => 'A runtime entity binding names no emitted anchor; the binding was dropped.',
		'source_path'   => 'website/shop.html',
		'reason_code'   => 'binding_anchor_missing',
		'entity_id'     => 'product-1',
		'omitted_count' => 1,
	),
	array(
		'code'                   => 'runtime_form_graph_rejected',
		'severity'               => 'warning',
		'message'                => 'A generic form layout graph failed its structural contract and was omitted from the runtime entity.',
		'source_path'            => 'website/contact.html',
		'selector'               => 'form#contact',
		'graph'                  => 'layout graph',
		'control_count'          => 4,
		'entity_schema'          => 'generic/forms/v1',
		'reason_code'            => 'runtime_form_graph_rejected',
		'pattern_family'         => 'interactive_form',
		'repair_bucket'          => 'materialize_form_provider',
		'suggested_repair_class' => 'materialize_form_provider',
		'source'                 => $be_compiler,
	),
	array(
		'code'          => 'document_head_attribute_dropped',
		'severity'      => 'warning',
		'unit'          => 'item',
		'message'       => 'Document head <meta> attribute onload cannot be carried (event_handler); it was dropped.',
		'source_path'   => 'website/index.html',
		'reason_code'   => 'event_handler',
		'tag'           => 'meta',
		'attribute'     => 'onload',
		'omitted_count' => 1,
	),
	array(
		'code'          => 'document_head_element_dropped',
		'severity'      => 'warning',
		'unit'          => 'item',
		'message'       => 'Document head <base> element cannot be carried (unsupported_element); it was dropped.',
		'source_path'   => 'website/index.html',
		'reason_code'   => 'unsupported_element',
		'tag'           => 'base',
		'omitted_count' => 1,
	),
);
$intake_row = array(
	'code'        => 'static_site_importer_non_static_file_skipped',
	'type'        => 'static-site-importer',
	'severity'    => 'warning',
	'stage'       => 'archive_intake',
	'source_path' => 'website/assets/style.php',
	'path'        => 'website/assets/style.php',
	'reason_code' => 'non_static_extension',
	'message'     => 'Skipped website/assets/style.php: only static content can be imported, so references to it are unresolved.',
);
// The other intake decisions: a dropped non-entry page and an oversize archive entry.
$more_intake_rows = array(
	array(
		'code'        => 'static_site_importer_non_static_page_dropped',
		'type'        => 'static-site-importer',
		'severity'    => 'warning',
		'stage'       => 'content_policy',
		'source_path' => 'website/blog/feed.php',
		'path'        => 'website/blog/feed.php',
		'reason_code' => 'non_static_extension',
		'message'     => 'Dropped page website/blog/feed.php because it is not static content; the remaining pages import.',
	),
	array(
		'code'        => 'static_site_importer_archive_entry_skipped',
		'type'        => 'static-site-importer',
		'severity'    => 'warning',
		'stage'       => 'archive_intake',
		'source_path' => 'website/assets/clip.mp4',
		'path'        => 'website/assets/clip.mp4',
		'reason_code' => 'entry_uncompressed_bytes_exceeded',
		'metrics'     => array( 'uncompressed_bytes' => 30000000 ),
		'message'     => 'Skipped website/assets/clip.mp4: the archive entry exceeds the entry_uncompressed_bytes limit, so references to it are unresolved.',
	),
);
// Compiler file drops, as Blocks Engine emits them in the plan; re-owned as omitted files.
$compiler_drop_rows = array(
	array(
		'code'     => 'artifact_inputs_rejected',
		'severity' => 'warning',
		'source'   => 'artifact_normalization',
		'context'  => array( 'rejected_by_code' => array( 'file_limit_exceeded' => 20 ) ),
	),
	array(
		'code'     => 'artifact_file_too_large',
		'severity' => 'warning',
		'source'   => 'artifact_normalization',
		'path'     => 'website/assets/huge.svg',
		'context'  => array( 'file_size' => 10485761 ),
	),
);
// A font face the materializer could not localize, as the receipt records it.
$font_row = array(
	'code'        => 'font_face_unavailable',
	'type'        => 'font_face_unavailable',
	'severity'    => 'warning',
	'stage'       => 'font_materialization',
	'reason'      => 'producer_font_fetch_failed',
	'reason_code' => 'producer_font_fetch_failed',
	'resolution'  => 'dropped',
	'url'         => 'https://fonts.example.test/inter.woff2',
	'message'     => 'The font face for Inter was unavailable (producer_font_fetch_failed) and was dropped; the theme uses its fallback font.',
);
// Data Liberation Agent capture findings, as intake reads them from `capture-findings.json`.
$capture_finding = static fn( string $code, string $unit, string $route, string $path = '' ): array => array_filter(
	array(
		'code'     => $code,
		'severity' => 'warning',
		'unit'     => $unit,
		'url'      => 'https://example.com/' . $route,
		'path'     => $path,
		'reason'   => $code . ' on ' . $route,
	)
);
$capture_rows    = Static_Site_Importer_Capture_Findings::findings(
	array(
		'findings'       => array(
			$capture_finding( 'route_capture_failed', 'route', 'slow/' ),
			$capture_finding( 'route_export_failed', 'route', 'pricing-export/' ),
			$capture_finding( 'route_path_collision', 'route', 'a.html' ),
			$capture_finding( 'route_off_origin', 'route', 'elsewhere/' ),
			$capture_finding( 'route_profile_uncaptured', 'profile', 'tablet/', 'tablet/index.html' ),
			$capture_finding( 'route_capture_degraded', 'route', 'about/', 'about/index.html' ),
			$capture_finding( 'stylesheet_unparseable', 'stylesheet', 'styled/', 'assets/site.css' ),
			$capture_finding( 'native_timeline_unproven', 'block', 'motion/', 'motion/index.html' ),
			$capture_finding( 'disclosure_unrestored', 'block', 'faq/', 'faq/index.html' ),
		),
		'missing_routes' => array(),
	)
);

$expected = array(
	'page_compile_omitted'                           => array( 'page_compile_omitted', 'page', 'blocks-engine', 'website/pricing.html' ),
	'editability_policy_degraded'                    => array( 'degraded_unit', 'page', 'blocks-engine', 'website/about.html' ),
	'html_include_budget_exceeded'                   => array( 'degraded_unit', 'page', 'blocks-engine', 'website/includes.html' ),
	'html_include_directive_ignored'                 => array( 'degraded_unit', 'page', 'blocks-engine', 'website/directive.html' ),
	'glyph_color_undecodable'                        => array( 'degraded_unit', 'item', 'blocks-engine', 'website/index.html' ),
	'wordpress_site_plan_unresolved_navigation_link' => array( 'degraded_unit', 'item', 'blocks-engine', '' ),
	'runtime_dependency_target_missing'              => array( 'degraded_unit', 'block', 'blocks-engine', 'website/index.html' ),
	'wordpress_site_plan_colliding_asset_target'     => array( 'degraded_unit', 'asset', 'blocks-engine', 'website/b/style.css' ),
	'wordpress_site_plan_omitted_link_declaration'   => array( 'omitted_unit', 'item', 'blocks-engine', 'website/index.html' ),
	'runtime_entity_dropped'                         => array( 'omitted_unit', 'item', 'blocks-engine', 'website/shop.html' ),
	'runtime_declaration_dropped'                    => array( 'omitted_unit', 'declaration', 'blocks-engine', 'website/shop.html' ),
	'runtime_entity_binding_unanchored'              => array( 'omitted_unit', 'binding', 'blocks-engine', 'website/shop.html' ),
	'runtime_form_graph_rejected'                    => array( 'omitted_unit', 'item', 'blocks-engine', 'website/contact.html' ),
	'document_head_attribute_dropped'                => array( 'omitted_unit', 'item', 'blocks-engine', 'website/index.html' ),
	'document_head_element_dropped'                  => array( 'omitted_unit', 'item', 'blocks-engine', 'website/index.html' ),
	'static_site_importer_non_static_file_skipped'   => array( 'omitted_unit', 'file', 'static-site-importer', 'website/assets/style.php' ),
	'static_site_importer_non_static_page_dropped'   => array( 'omitted_unit', 'page', 'static-site-importer', 'website/blog/feed.php' ),
	'static_site_importer_archive_entry_skipped'     => array( 'omitted_unit', 'file', 'static-site-importer', 'website/assets/clip.mp4' ),
	'font_face_unavailable'                          => array( 'omitted_unit', 'asset', 'static-site-importer', 'https://fonts.example.test/inter.woff2' ),
	'omitted_artifact_files'                         => array( 'omitted_unit', 'file', 'blocks-engine', 'artifact_normalization' ),
	'omitted_artifact_file'                          => array( 'omitted_unit', 'file', 'blocks-engine', 'artifact_normalization' ),
	'route_capture_failed'                           => array( 'omitted_unit', 'route', 'data-liberation-agent', 'https://example.com/slow/' ),
	'route_export_failed'                            => array( 'omitted_unit', 'route', 'data-liberation-agent', 'https://example.com/pricing-export/' ),
	'route_path_collision'                           => array( 'omitted_unit', 'route', 'data-liberation-agent', 'https://example.com/a.html' ),
	'route_off_origin'                               => array( 'omitted_unit', 'route', 'data-liberation-agent', 'https://example.com/elsewhere/' ),
	'route_profile_uncaptured'                       => array( 'omitted_unit', 'profile', 'data-liberation-agent', 'website/tablet/index.html' ),
	'route_capture_degraded'                         => array( 'degraded_unit', 'route', 'data-liberation-agent', 'website/about/index.html' ),
	'stylesheet_unparseable'                         => array( 'degraded_unit', 'stylesheet', 'data-liberation-agent', 'website/assets/site.css' ),
	'native_timeline_unproven'                       => array( 'degraded_unit', 'block', 'data-liberation-agent', 'website/motion/index.html' ),
	'disclosure_unrestored'                          => array( 'degraded_unit', 'block', 'data-liberation-agent', 'website/faq/index.html' ),
);

// The registry names every ledger code, and nothing else.
$registry = Static_Site_Importer_Diagnostic_Loss_Classes::reported_loss_codes();
$assert( array_keys( $expected ) == array_keys( $registry ), 'registry-covers-ledger-codes', implode( ',', array_diff( array_keys( $expected ), array_keys( $registry ) ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Order-insensitive key set comparison.

// Classification by code beats the contract lane and the heuristic.
foreach ( array_merge( $plan_rows, array( $intake_row ), $more_intake_rows, array( $font_row ), array_map( array( Static_Site_Importer_Diagnostic_Loss_Classes::class, 'reown_compiler_file_drop' ), $compiler_drop_rows ), $capture_rows ) as $row ) {
	$provenance = Static_Site_Importer_Diagnostic_Loss_Classes::classify_with_provenance( $row );
	$assert(
		$expected[ $row['code'] ][0] === $provenance['class'] && Static_Site_Importer_Diagnostic_Loss_Classes::SOURCE_CODE === $provenance['source'],
		'classified-by-code-' . $row['code'],
		'got: ' . $provenance['class'] . ' via ' . $provenance['source']
	);
}

$plan    = array(
	'schema'         => 'blocks-engine/wordpress-site-plan/v2',
	'diagnostics'    => array_merge( $plan_rows, $compiler_drop_rows ),
	'quality'        => array(),
	'source'         => array(
		'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
		'source_hash' => 'loss-ledger-smoke',
		'entry_path'  => 'website/index.html',
		'provenance'  => array(),
	),
	'template_parts' => array(),
	'pages'          => array(),
	'writes'         => array(),
	'assets'         => array(),
);
$receipt = array(
	'plan'             => $plan,
	'theme'            => array(
		'slug' => 'loss-ledger',
		'dir'  => sys_get_temp_dir() . '/loss-ledger',
	),
	'completed'        => array(
		'files'                => array(),
		'font_materialization' => array(
			'files'       => array(),
			'diagnostics' => array( $font_row ),
		),
	),
	'existing_matches' => array( 'pages' => array() ),
	'extensions'       => array(),
);
$args    = array( 'source_metadata' => array( 'intake_findings' => array_merge( array( $intake_row ), $more_intake_rows, $capture_rows ) ) );

$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, $args, array(), array(), array(), 'loss-ledger-run', array(), array() );
$report     = $projection['report'];
$quality    = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
$stored     = json_decode( (string) json_encode( $report->to_array() ), true );
$rows       = array();
foreach ( $stored['diagnostics'] ?? array() as $row ) {
	if ( is_array( $row ) && isset( $expected[ $row['code'] ?? '' ] ) ) {
		$rows[ $row['code'] ] = $row;
	}
}

// Items 1-3: every ledger code is an accepted, reported loss with its unit and layer.
foreach ( $expected as $code => list( $loss_class, $unit, $owning_layer, $location ) ) {
	$row = $rows[ $code ] ?? array();
	$assert( array() !== $row, 'stored-row-' . $code );
	$assert( $loss_class === ( $row['loss_class'] ?? '' ), 'loss-class-' . $code, 'got: ' . ( $row['loss_class'] ?? '' ) );
	$assert( 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ), 'acceptability-' . $code, 'got: ' . ( $row['acceptability'] ?? '' ) );
	$assert( $unit === ( $row['unit'] ?? '' ), 'unit-' . $code, 'got: ' . ( $row['unit'] ?? '' ) );
	$assert( $owning_layer === ( $row['owning_layer'] ?? '' ), 'owning-layer-' . $code, 'got: ' . ( $row['owning_layer'] ?? '' ) );
	$assert( $location === ( $row['location'] ?? null ), 'location-' . $code, 'got: ' . wp_json_encode_fallback( $row['location'] ?? null ) );
}
$assert( 'importer_materialization_bug' !== ( $rows['runtime_form_graph_rejected']['loss_class'] ?? '' ), 'form-graph-rejection-is-not-an-importer-bug' );
// Reported losses never fail the import. Omitted files stay a quality reason
// (dropped_artifact_files) that a caller's fail_on_quality policy can refuse.
$assert( 'completed' === ( $stored['compact_summary']['status'] ?? '' ) && false === $quality['fail_import'] && array( 'dropped_artifact_files' ) === $quality['failure_reasons'], 'reported-losses-keep-the-import-passing', wp_json_encode_fallback( $quality['failure_reasons'] ?? array() ) );
$strict_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array_merge( $args, array( 'fail_on_quality' => true ) ) );
$assert( true === $strict_quality['fail_import'], 'fail-on-quality-callers-can-still-refuse-omitted-files' );

// Item 6: every row carries the canonical finding fields; producer fields survive.
foreach ( $stored['diagnostics'] ?? array() as $row ) {
	foreach ( Static_Site_Importer_Diagnostic_Projection::CANONICAL_FINDING_FIELDS as $field ) {
		$assert( array_key_exists( $field, $row ), 'canonical-field-' . $field . '-' . ( $row['code'] ?? '?' ) );
	}
	$assert( is_array( $row['metrics'] ?? null ), 'canonical-metrics-is-map-' . ( $row['code'] ?? '?' ) );
}
$editability = $rows['editability_policy_degraded'] ?? array();
$assert( 'website/about.html' === ( $editability['source_path'] ?? '' ) && 'website/about.html' === ( $editability['context']['source_path'] ?? '' ), 'be-context-source-path-is-lifted-and-kept' );
$assert( array( 'wrapper_to_content_ratio' => 9, 'wrapper_to_content_ratio_maximum' => 6 ) === ( $editability['metrics'] ?? null ), 'be-editability-context-becomes-metrics' );
$include = $rows['html_include_directive_ignored'] ?? array();
$assert( 'A document has a non-standard or unresolvable include directive; its includes were left unexpanded.' === ( $include['message'] ?? '' ) && 'html_include_missing_path: partials/nav.html' === ( $include['context']['reason'] ?? '' ), 'be-include-message-and-raw-reason-kept' );
$entity = $rows['runtime_entity_dropped'] ?? array();
$assert( array( 'bytes' => 70000, 'limit_bytes' => 65536 ) === ( $entity['metrics'] ?? null ) && 'runtime_entity_exceeds_byte_limit' === ( $entity['reason_code'] ?? '' ), 'be-metrics-and-reason-code-kept' );
$overflow = $rows['wordpress_site_plan_unresolved_navigation_link'] ?? array();
$assert( array( 'omitted_count' => 3 ) === ( $overflow['metrics'] ?? null ) && 'truncated' === ( $overflow['reason'] ?? '' ) && 3 === ( $overflow['omitted_count'] ?? 0 ), 'be-overflow-row-count-becomes-metric' );
$intake = $rows['static_site_importer_non_static_file_skipped'] ?? array();
$assert( 'website/assets/style.php' === ( $intake['path'] ?? '' ) && 'non_static_extension' === ( $intake['reason_code'] ?? '' ) && str_starts_with( (string) ( $intake['message'] ?? '' ), 'Skipped website/assets/style.php' ), 'ssi-intake-row-canonical' );
$omitted = $rows['page_compile_omitted'] ?? array();
$assert( 'page_compile_representability_limit' === ( $omitted['reason_code'] ?? '' ) && 'warning' === ( $omitted['severity'] ?? '' ) && 'Form layout graph exceeds its node limit.' === ( $omitted['message'] ?? '' ), 'page-omission-row-canonical' );

// DLA-shaped row: `reason` is the human text, `url` the location.
$dla = Static_Site_Importer_Diagnostic_Projection::with_canonical_finding_fields(
	array(
		'code'       => 'route_capture_degraded',
		'severity'   => 'warning',
		'unit'       => 'route',
		'url'        => 'https://example.com/about',
		'reason'     => 'Two capture stages failed.',
		'metrics'    => array( 'failedStages' => 2 ),
		'loss_class' => 'degraded_unit',
	)
);
$assert( 'https://example.com/about' === $dla['location'] && 'Two capture stages failed.' === $dla['message'] && array( 'failedStages' => 2 ) === $dla['metrics'] && 'accepted_reported_loss' === $dla['acceptability'], 'dla-row-variants-map-to-canonical-fields' );

// Item 5: compact summary keeps code, location and metrics, and counts losses.
$summary = $stored['compact_summary'] ?? array();
$compact = array();
foreach ( $summary['diagnostics'] ?? array() as $row ) {
	$compact[ $row['code'] ?? '' ] = $row;
}
foreach ( $expected as $code => list( $loss_class, $unit, $owning_layer, $location ) ) {
	$row = $compact[ $code ] ?? array();
	$assert( $code === ( $row['code'] ?? '' ) && $loss_class === ( $row['loss_class'] ?? '' ) && 'accepted_reported_loss' === ( $row['acceptability'] ?? '' ) && $unit === ( $row['unit'] ?? '' ) && $owning_layer === ( $row['owning_layer'] ?? '' ), 'compact-row-' . $code );
	if ( '' !== $location ) {
		$assert( $location === ( $row['location'] ?? '' ), 'compact-location-' . $code );
	}
}
$assert( array( 'bytes' => 70000, 'limit_bytes' => 65536 ) === ( $compact['runtime_entity_dropped']['metrics'] ?? null ), 'compact-row-keeps-metrics' );
$assert( '/manifest.json' === ( $compact['wordpress_site_plan_omitted_link_declaration']['value'] ?? '' ), 'compact-row-keeps-value' );
$assert( 1 === ( $summary['omitted_page_count'] ?? null ), 'compact-omitted-page-count' );
$assert( 30 === ( $summary['loss_count'] ?? null ), 'compact-loss-count', wp_json_encode_fallback( $summary['loss_counts'] ?? null ) );
$assert(
	array(
		'page_compile_omitted'         => 1,
		'degraded_unit'                => 11,
		'omitted_unit'                 => 18,
		'unsupported_loss'             => 0,
		'importer_materialization_bug' => 0,
	) === ( $summary['loss_counts'] ?? null ),
	'compact-loss-counts-by-loss-class',
	wp_json_encode_fallback( $summary['loss_counts'] ?? null )
);

// A single compiler diagnostic keeps its canonical top-level fields through the compiler normalizer.
$single = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize( array( $plan_rows[7] ) );
$assert( 'item' === ( $single[0]['unit'] ?? '' ) && 'website/shop.html' === ( $single[0]['source_path'] ?? '' ) && 'runtime_entity_exceeds_byte_limit' === ( $single[0]['reason_code'] ?? '' ) && array( 'bytes' => 70000, 'limit_bytes' => 65536 ) === ( $single[0]['metrics'] ?? null ), 'compiler-normalizer-keeps-canonical-fields' );

// Compact cap: losses are kept ahead of informational rows, in report order.
$noise = array();
for ( $index = 0; $index < 60; ++$index ) {
	$noise[] = array(
		'id'          => sprintf( 'noise-%02d', $index ),
		'code'        => 'document_metadata_routed',
		'severity'    => 'info',
		'source_path' => 'website/index.html',
		'loss_class'  => 'native_conversion',
	);
}
$losses   = array(
	array_merge( $plan_rows[0], array( 'id' => 'loss-page' ) ),
	array_merge( $plan_rows[12], array( 'id' => 'loss-form' ) ),
	array(
		'id'         => 'defect',
		'code'       => 'svg_materialization_failure',
		'type'       => 'svg_materialization_failure',
		'loss_class' => 'importer_materialization_bug',
	),
);
$capped   = Static_Site_Importer_Diagnostic_Projection::compact_import_report_diagnostics(
	array_map(
		static fn( array $row ): array => Static_Site_Importer_Diagnostic_Projection::with_canonical_finding_fields( Static_Site_Importer_Diagnostic_Loss_Classes::reown_reported_loss( $row ) ),
		array_merge( $noise, $losses )
	)
);
$kept_ids = array_column( $capped, 'id' );
$assert( Static_Site_Importer_Diagnostic_Projection::COMPACT_DIAGNOSTIC_LIMIT === count( $capped ), 'compact-cap-holds' );
$assert( array( 'loss-page', 'loss-form', 'defect' ) === array_slice( $kept_ids, -3 ), 'compact-cap-keeps-losses-in-report-order', wp_json_encode_fallback( array_slice( $kept_ids, -3 ) ) );
$assert( 'noise-00' === ( $kept_ids[0] ?? '' ) && ! in_array( 'noise-47', $kept_ids, true ), 'compact-cap-drops-trailing-informational-rows' );

// Item 4: Blocks Engine's newer repair lanes are mapped, not drift.
$contract_class = 'Automattic\\BlocksEngine\\PhpTransformer\\Contract\\ConversionFindingContract';
if ( class_exists( $contract_class ) ) {
	Static_Site_Importer_Diagnostic_Loss_Classes::reset_unmapped_repair_buckets();
	$lanes = array(
		'restore_compiled_document_identity' => array(
			array(
				'code'           => 'wordpress_site_plan_not_self_contained',
				'severity'       => 'error',
				'message'        => 'Compiled page "website/x.html" has empty block markup.',
				'source_path'    => 'website/x.html',
				'reason_code'    => 'wordpress_site_plan_not_self_contained',
				'pattern_family' => 'site_plan_document',
				'repair_bucket'  => 'restore_compiled_document_identity',
			),
			'unsupported_loss',
		),
		'restore_projected_selector_binding' => array(
			array(
				'code'           => 'dead_projected_author_selector',
				'severity'       => 'warning',
				'selector'       => '.hero h1',
				'path'           => 'website/styles.css',
				'summary'        => 'Author selector projection bound to no element in the emitted document scope(s).',
				'pattern_family' => 'projected_author_selector',
				'repair_bucket'  => 'restore_projected_selector_binding',
			),
			'editable_approximation',
		),
	);
	foreach ( $lanes as $lane => list( $row, $class ) ) {
		$provenance = Static_Site_Importer_Diagnostic_Loss_Classes::classify_with_provenance( $row );
		$assert( $class === $provenance['class'] && Static_Site_Importer_Diagnostic_Loss_Classes::SOURCE_CONTRACT === $provenance['source'] && $lane === $provenance['repair_bucket'], 'repair-lane-mapped-' . $lane, 'got: ' . $provenance['class'] . ' via ' . $provenance['source'] );
	}
	$assert( array() === Static_Site_Importer_Diagnostic_Loss_Classes::unmapped_repair_buckets(), 'repair-lanes-not-drift', wp_json_encode_fallback( Static_Site_Importer_Diagnostic_Loss_Classes::unmapped_repair_buckets() ) );
	$selector = Static_Site_Importer_Diagnostic_Projection::with_canonical_finding_fields( Static_Site_Importer_Diagnostic_Loss_Classes::apply( $lanes['restore_projected_selector_binding'][0] ) );
	$assert( 'website/styles.css' === $selector['location'] && 'Author selector projection bound to no element in the emitted document scope(s).' === $selector['message'], 'be-summary-and-path-map-to-canonical-fields' );
} else {
	$failures[] = 'FAIL [contract-unavailable]: run composer install first';
}

/**
 * Minimal JSON encoder so failure detail works without WordPress loaded.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_fallback( $value ): string {
	$encoded = json_encode( $value );

	return is_string( $encoded ) ? $encoded : '';
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report loss ledger smoke passed (' . $assertions . " assertions)\n";
