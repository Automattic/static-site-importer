<?php
/**
 * Smoke coverage for the layout projection engine.
 *
 * Run from the repository root:
 * php tests/smoke-layout-projection.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ) {
			return json_encode( $value );
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private $data = null ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $option, $default = false ) {
			return $GLOBALS['stub_layout_option'] ?? $default;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $tag, $value, ...$extra ) {
			if ( 'ssi_layout_plugin' === $tag && isset( $GLOBALS['stub_layout_provider_filter'] ) ) {
				return $GLOBALS['stub_layout_provider_filter'];
			}
			if ( 'ssi_entity_materializer_provider' === $tag && isset( $GLOBALS['stub_cross_provider_filter'] ) ) {
				return $GLOBALS['stub_cross_provider_filter'];
			}
			if ( 'static_site_importer_layout_adapters' === $tag && class_exists( 'Stub_Extra_Layout_Adapter' ) ) {
				$value['stub-extra'] = new Stub_Extra_Layout_Adapter();
			}
			return $value;
		}
	}

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			public static bool $registered = true;
			private static ?WP_Block_Type_Registry $instance = null;
			public static function get_instance(): WP_Block_Type_Registry {
				return self::$instance ??= new self();
			}
			public function is_registered( string $name ): bool {
				return self::$registered && 'tabor/canvas' === $name;
			}
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-placement-model.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-none-layout-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-core-grid-layout-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canvas-layout-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-adapter-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-projector.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-release.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-layout-marker-filter.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$box          = static fn( float $x, float $y, float $width, float $height ): array => array( 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height );
	$viewports    = static fn( array $base, array $tablet, array $mobile ): array => array( 1440 => $base, 700 => $tablet, 390 => $mobile );
	$find         = static function ( array $pieces, string $path ): ?array {
		$segments = explode( '.', $path );
		$blocks   = array_values( array_filter( $pieces, static fn( $piece ): bool => is_array( $piece ) && isset( $piece['blockName'] ) ) );
		$block    = null;
		$last     = count( $segments ) - 1;
		foreach ( $segments as $index => $segment ) {
			$position = (int) $segment;
			if ( ! isset( $blocks[ $position ] ) ) {
				return null;
			}
			$block = $blocks[ $position ];
			if ( $index < $last ) {
				$blocks = $block['innerBlocks'];
			}
		}
		return $block;
	};

	$original_markup = "<!-- wp:group {\"metadata\":{\"name\":\"bistro\"}} -->\n<div class=\"wp-block-group bistro\">\n<!-- wp:image {\"id\":12,\"sizeSlug\":\"large\"} /-->\n<!-- wp:paragraph -->\n<p>Wood-fired everything.</p>\n<!-- /wp:paragraph -->\n<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"#\">Reserve</a></div>\n<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->\n<!-- wp:template-part {\"slug\":\"contact\",\"tagName\":\"aside\"} /-->\n</div>\n<!-- /wp:group -->\n";

	$bistro_model = array(
		'schema' => 'static-site-importer/layout-placement/v1',
		'host'   => array(
			'path'      => '0',
			'viewports' => $viewports( $box( 120, 64, 1200, 420 ), $box( 30, 64, 640, 300 ), $box( 20, 64, 350, 200 ) ),
		),
		'items'  => array(
			array(
				'path'      => '0.0',
				'viewports' => $viewports( $box( 120, 64, 300, 200 ), $box( 30, 64, 320, 200 ), $box( 20, 64, 350, 200 ) ),
			),
			array(
				'path'      => '0.1',
				'viewports' => $viewports( $box( 570, 64, 750, 420 ), $box( 350, 64, 320, 200 ), $box( 20, 64, 350, 100 ) ),
			),
			array(
				'path'      => '0.2',
				'viewports' => $viewports( $box( 120, 400, 150, 42 ), $box( 30, 322, 150, 42 ), $box( 20, 222, 120, 42 ) ),
			),
		),
	);

	// --- Model validation -------------------------------------------------------
	$validation = Static_Site_Importer_Layout_Placement_Model::validate( $bistro_model );
	$assert( empty( $validation['errors'] ), 'bistro-model-validates', (string) wp_json_encode( $validation['errors'] ) );
	$assert( array( 1440, 700, 390 ) === Static_Site_Importer_Layout_Placement_Model::viewport_widths( $validation['model']['host'] ), 'viewports-normalize-widest-first' );
	$assert( 120.0 === Static_Site_Importer_Layout_Placement_Model::box_at( $validation['model']['host'], 1440 )['x'], 'host-box-1440-x-preserved' );

	$wrong_schema                   = $bistro_model;
	$wrong_schema['schema']         = 'other/v1';
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $wrong_schema )['errors'] ), 'wrong-schema-rejected');

	$nan_width                      = $bistro_model;
	$nan_width['host']['viewports'][1440]['width'] = NAN;
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $nan_width )['errors'] ), 'non-finite-width-rejected');

	$infinite_height                = $bistro_model;
	$infinite_height['items'][0]['viewports'][700]['height'] = INF;
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $infinite_height )['errors'] ), 'infinite-height-rejected');

	$oversized_width                = $bistro_model;
	$oversized_width['items'][1]['viewports'][1440]['width'] = 2000000;
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $oversized_width )['errors'] ), 'oversized-width-rejected');

	$negative_size                  = $bistro_model;
	$negative_size['host']['viewports'][1440]['height'] = -1;
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $negative_size )['errors'] ), 'negative-size-rejected');

	$string_size                    = $bistro_model;
	$string_size['items'][2]['viewports'][390]['width'] = 'wide';
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $string_size )['errors'] ), 'non-numeric-size-rejected');

	$bad_path                       = $bistro_model;
	$bad_path['items'][0]['path']   = '0.x';
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $bad_path )['errors'] ), 'malformed-item-path-rejected');

	$duplicate_paths                = $bistro_model;
	$duplicate_paths['items'][2]['path'] = '0.0';
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $duplicate_paths )['errors'] ), 'duplicate-item-paths-rejected');

	$host_collide                   = $bistro_model;
	$host_collide['items'][0]['path'] = '0';
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $host_collide )['errors'] ), 'item-path-colliding-with-host-rejected');

	$bad_viewport_key               = $bistro_model;
	$bad_viewport_key['host']['viewports']['narrow'] = $box( 1, 1, 1, 1 );
	$assert(! empty( Static_Site_Importer_Layout_Placement_Model::validate( $bad_viewport_key )['errors'] ), 'non-numeric-viewport-key-rejected');

	// --- Canonical parse and serialize round trip --------------------------------
	$parsed = Static_Site_Importer_Layout_Projector::parse( $original_markup );
	$assert( null !== $parsed, 'canonical-markup-parses' );
	$assert( $original_markup === Static_Site_Importer_Layout_Projector::serialize( $parsed ), 'parse-serialize-round-trip-is-byte-stable' );
	$assert( null === Static_Site_Importer_Layout_Projector::parse( "<!-- wp:group -->\n<div>unclosed\n" ), 'unclosed-markup-refuses-to-parse' );

	// --- Registry ----------------------------------------------------------------
	$capabilities = Static_Site_Importer_Layout_Adapter_Registry::capabilities();
	$assert( array( 'default_provider' => 'none', 'option' => 'static_site_importer_layout_plugin', 'filter' => 'ssi_layout_plugin' ) === ( $capabilities['layout'] ?? null ), 'layout-capability-contract' );
	$assert( 'none' === Static_Site_Importer_Layout_Adapter_Registry::provider_for( 'layout' ), 'layout-provider-defaults-to-none' );
	$assert( Static_Site_Importer_Layout_Adapter_Registry::layout_adapter() instanceof Static_Site_Importer_None_Layout_Adapter, 'default-layout-adapter-is-none' );
	$assert( Static_Site_Importer_Layout_Adapter_Registry::adapter( 'core-grid' ) instanceof Static_Site_Importer_Core_Grid_Layout_Adapter, 'core-grid-adapter-registered-by-id' );
	$assert( null === Static_Site_Importer_Layout_Adapter_Registry::adapter_for_capability( 'form' ), 'unknown-capability-has-no-adapter' );

	$GLOBALS['stub_layout_option'] = 'canvas';
	$assert( 'canvas' === Static_Site_Importer_Layout_Adapter_Registry::provider_for( 'layout' ), 'layout-provider-option-override' );
	$assert( Static_Site_Importer_Layout_Adapter_Registry::layout_adapter() instanceof Static_Site_Importer_Canvas_Layout_Adapter, 'option-override-resolves-canvas-adapter' );
	$GLOBALS['stub_layout_option'] = 'not-an-adapter';
	$assert( null === Static_Site_Importer_Layout_Adapter_Registry::layout_adapter(), 'unregistered-provider-resolves-to-no-adapter' );
	unset( $GLOBALS['stub_layout_option'] );

	$GLOBALS['stub_layout_provider_filter'] = 'core-grid';
	$assert( 'core-grid' === Static_Site_Importer_Layout_Adapter_Registry::provider_for( 'layout' ), 'layout-provider-filter-override' );
	unset( $GLOBALS['stub_layout_provider_filter'] );

	$GLOBALS['stub_cross_provider_filter'] = 'canvas';
	$assert( 'canvas' === Static_Site_Importer_Layout_Adapter_Registry::provider_for( 'layout' ), 'cross-capability-provider-filter-override' );
	unset( $GLOBALS['stub_cross_provider_filter'] );

	$assert( true === Static_Site_Importer_Layout_Adapter_Registry::dependencies_available( new Static_Site_Importer_Canvas_Layout_Adapter() ), 'canvas-dependency-satisfied-by-registered-block-type' );
	WP_Block_Type_Registry::$registered = false;
	$rows = Static_Site_Importer_Layout_Adapter_Registry::dependency_rows( new Static_Site_Importer_Canvas_Layout_Adapter() );
	$assert( array( 'tabor/canvas' ) === array_keys( $rows ) && false === $rows['tabor/canvas']['active'], 'canvas-dependency-row-reports-missing-block-type' );
	$assert( false === Static_Site_Importer_Layout_Adapter_Registry::dependencies_available( new Static_Site_Importer_Canvas_Layout_Adapter() ), 'canvas-dependency-missing-when-block-type-unregistered' );
	$assert( true === Static_Site_Importer_Layout_Adapter_Registry::dependencies_available( new Static_Site_Importer_Core_Grid_Layout_Adapter() ), 'core-grid-has-no-dependencies' );
	WP_Block_Type_Registry::$registered = true;

	// --- Core-grid projection ----------------------------------------------------
	$core_grid = Static_Site_Importer_Layout_Projector::project( $original_markup, $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( true === $core_grid['applied'] && 3 === $core_grid['placed'] && '' === $core_grid['reason'], 'core-grid-projects-all-items', (string) wp_json_encode( $core_grid['losses'] ) );
	$core_pieces = Static_Site_Importer_Layout_Projector::parse( $core_grid['markup'] );
	$assert( null !== $core_pieces, 'core-grid-output-parses' );
	$core_host = $find( $core_pieces, '0' );
	$assert( array( 'type' => 'grid', 'columnCount' => 12 ) === ( $core_host['attrs']['layout'] ?? null ), 'core-grid-host-layout-attribute', (string) wp_json_encode( $core_host['attrs'] ?? null ) );
	$assert( array( 'name' => 'bistro' ) === ( $core_host['attrs']['metadata'] ?? null ), 'core-grid-host-preserves-existing-attributes' );
	$assert( false !== strpos( (string) $core_host['innerContent'][0], 'class="wp-block-group bistro ssi-layout-core-grid-host"' ), 'core-grid-host-class-appended', (string) wp_json_encode( $core_host['innerContent'][0] ?? null ) );
	$assert( 'core/group' === ( $core_host['blockName'] ?? '' ), 'core-grid-host-keeps-block-name' );

	// Base viewport rows derive from the distinct item top edges (0 and 336px).
	$core_image = $find( $core_pieces, '0.0' );
	$assert( array( 'columnStart' => 1, 'columnSpan' => 3, 'rowStart' => 1, 'rowSpan' => 1 ) === ( $core_image['attrs']['style']['layout'] ?? null ), 'core-grid-image-base-placement', (string) wp_json_encode( $core_image['attrs'] ?? null ) );
	$assert( array( 'columnStart' => 1, 'columnSpan' => 6, 'rowStart' => 1, 'rowSpan' => 1 ) === ( $core_image['attrs']['style']['@tablet']['layout'] ?? null ), 'core-grid-image-tablet-override-under-breakpoint-style', (string) wp_json_encode( $core_image['attrs'] ?? null ) );
	$assert( array( 'columnStart' => 1, 'columnSpan' => 12, 'rowStart' => 1, 'rowSpan' => 2 ) === ( $core_image['attrs']['style']['@mobile']['layout'] ?? null ), 'core-grid-image-mobile-override-under-breakpoint-style', (string) wp_json_encode( $core_image['attrs'] ?? null ) );
	$assert( ! isset( $core_image['attrs']['style']['layout']['@tablet'] ), 'core-grid-overrides-not-nested-in-base-layout' );
	$assert( 12 === ( $core_image['attrs']['id'] ?? null ), 'core-grid-image-preserves-existing-attributes' );
	$core_paragraph = $find( $core_pieces, '0.1' );
	$assert( array( 'columnStart' => 6, 'columnSpan' => 7, 'rowStart' => 1, 'rowSpan' => 2 ) === ( $core_paragraph['attrs']['style']['layout'] ?? null ), 'core-grid-paragraph-spans-both-rows', (string) wp_json_encode( $core_paragraph['attrs'] ?? null ) );
	$core_buttons = $find( $core_pieces, '0.2' );
	$assert( array( 'columnStart' => 1, 'columnSpan' => 2, 'rowStart' => 2, 'rowSpan' => 1 ) === ( $core_buttons['attrs']['style']['layout'] ?? null ), 'core-grid-buttons-second-row', (string) wp_json_encode( $core_buttons['attrs'] ?? null ) );
	$assert( false === strpos( $core_grid['markup'], 'ssi-layout-canvas-host' ), 'core-grid-does-not-leak-canvas-class' );

	// --- Canvas projection -------------------------------------------------------
	$canvas = Static_Site_Importer_Layout_Projector::project( $original_markup, $validation['model'], new Static_Site_Importer_Canvas_Layout_Adapter() );
	$assert( true === $canvas['applied'] && 3 === $canvas['placed'], 'canvas-projects-all-items', (string) wp_json_encode( $canvas['losses'] ) );
	$canvas_pieces = Static_Site_Importer_Layout_Projector::parse( $canvas['markup'] );
	$canvas_host   = $find( $canvas_pieces, '0' );
	$assert( 'core/group' === ( $canvas_host['blockName'] ?? '' ) && array( 'name' => 'bistro' ) === ( $canvas_host['attrs']['metadata'] ?? null ), 'canvas-host-keeps-its-block-and-attributes' );
	$assert( false !== strpos( (string) $canvas_host['innerContent'][0], 'class="wp-block-group bistro ssi-layout-canvas-host"' ), 'canvas-host-class-appended' );
	$canvas_block = $find( $canvas_pieces, '0.0' );
	$assert( 'tabor/canvas' === ( $canvas_block['blockName'] ?? '' ) && 3 === count( $canvas_block['innerBlocks'] ?? array() ), 'canvas-block-wraps-placed-items' );
	$assert( array( 'desktopRows' => 16, 'tabletRows' => 22, 'mobileRows' => 27, 'style' => array( 'spacing' => array( 'blockGap' => array( 'top' => '0px', 'left' => '0px' ) ) ) ) === ( $canvas_block['attrs'] ?? null ), 'canvas-block-rows-and-zero-gap', (string) wp_json_encode( $canvas_block['attrs'] ?? null ) );
	$assert( 'core/template-part' === ( $find( $canvas_pieces, '0.1' )['blockName'] ?? '' ), 'ineligible-child-stays-in-host-flow-after-canvas' );

	$canvas_image = $find( $canvas_pieces, '0.0.0' );
	$assert( array( 'desktop' => array( 'column' => 1, 'row' => 1, 'columnSpan' => 3, 'rowSpan' => 8, 'gridColumns' => 12, 'frameRatio' => 1.5 ), 'tablet' => array( 'column' => 1, 'row' => 1, 'columnSpan' => 6, 'rowSpan' => 15, 'gridColumns' => 12, 'frameRatio' => 1.6 ), 'mobile' => array( 'column' => 1, 'row' => 1, 'columnSpan' => 12, 'rowSpan' => 27, 'gridColumns' => 12, 'frameRatio' => 1.75 ) ) === ( $canvas_image['attrs']['canvas'] ?? null ), 'canvas-image-placements-and-frame-ratio', (string) wp_json_encode( $canvas_image['attrs'] ?? null ) );
	$assert( 12 === ( $canvas_image['attrs']['id'] ?? null ), 'canvas-image-preserves-existing-attributes' );
	$canvas_buttons = $find( $canvas_pieces, '0.0.2' );
	$assert( array( 'column' => 1, 'row' => 14, 'columnSpan' => 4, 'rowSpan' => 2, 'gridColumns' => 12 ) === ( $canvas_buttons['attrs']['canvas']['desktop'] ?? null ), 'canvas-buttons-minimum-span-and-row-math', (string) wp_json_encode( $canvas_buttons['attrs'] ?? null ) );

	// --- Ineligible sections and per-item losses ---------------------------------
	$paragraph_host_markup = "<!-- wp:paragraph -->\n<p>No items here.</p>\n<!-- /wp:paragraph -->\n";
	$paragraph_host_model  = $validation['model'];
	$paragraph_host_model['host']['path'] = '0';
	$refused               = Static_Site_Importer_Layout_Projector::project( $paragraph_host_markup, $paragraph_host_model, new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $refused['applied'] && 'host_without_items' === $refused['reason'], 'host-without-inner-blocks-refused', (string) wp_json_encode( $refused ) );

	$no_items_model        = $validation['model'];
	$no_items_model['items'] = array();
	$no_items              = Static_Site_Importer_Layout_Projector::project( $original_markup, $no_items_model, new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $no_items['applied'] && 'model_without_items' === $no_items['reason'], 'model-without-items-refused' );

	$unresolved_host       = $validation['model'];
	$unresolved_host['host']['path'] = '9';
	$unresolved            = Static_Site_Importer_Layout_Projector::project( $original_markup, $unresolved_host, new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $unresolved['applied'] && 'host_path_unresolved' === $unresolved['reason'], 'unresolved-host-path-refused' );

	$unparseable           = Static_Site_Importer_Layout_Projector::project( "<!-- wp:group -->\n<div>unclosed\n", $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $unparseable['applied'] && 'markup_unparseable' === $unparseable['reason'], 'unclosed-markup-refused-with-unparseable-reason' );
	$without_blocks        = Static_Site_Importer_Layout_Projector::project( 'plain text without any block', $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $without_blocks['applied'] && 'host_path_unresolved' === $without_blocks['reason'], 'blockless-markup-refused-with-unresolved-host' );

	$shared_reference_model = $validation['model'];
	$shared_reference_model['items'][] = array(
		'path'      => '0.3',
		'viewports' => $viewports( $box( 120, 470, 1200, 120 ), $box( 30, 370, 640, 120 ), $box( 20, 270, 350, 120 ) ),
	);
	$shared_reference      = Static_Site_Importer_Layout_Projector::project( $original_markup, $shared_reference_model, new Static_Site_Importer_Canvas_Layout_Adapter() );
	$shared_reasons        = array_column( array_filter( $shared_reference['losses'], static fn( array $loss ): bool => '0.3' === ( $loss['item'] ?? '' ) ), 'reason' );
	$assert( array( 'item_shared_reference' ) === $shared_reasons && 3 === $shared_reference['placed'], 'shared-reference-item-lost-with-reason', (string) wp_json_encode( $shared_reference['losses'] ) );

	$hidden_model          = $validation['model'];
	$hidden_model['items'][2]['viewports'][390] = $box( 0, 0, 0, 0 );
	$hidden                = Static_Site_Importer_Layout_Projector::project( $original_markup, $hidden_model, new Static_Site_Importer_Canvas_Layout_Adapter() );
	$hidden_reasons        = array_column( array_filter( $hidden['losses'], static fn( array $loss ): bool => '0.2' === ( $loss['item'] ?? '' ) ), 'reason' );
	$assert( array( 'item_hidden_at_viewport' ) === $hidden_reasons && 2 === $hidden['placed'], 'hidden-item-lost-with-viewport-detail', (string) wp_json_encode( $hidden['losses'] ) );
	$hidden_pieces         = Static_Site_Importer_Layout_Projector::parse( $hidden['markup'] );
	$hidden_buttons        = $find( $hidden_pieces, '0.1' );
	$assert( 'core/buttons' === ( $hidden_buttons['blockName'] ?? '' ) && null === ( $hidden_buttons['attrs']['canvas'] ?? null ), 'hidden-item-stays-in-flow-without-placement' );

	$nested_model          = $validation['model'];
	$nested_model['items'][2]['path'] = '0.2.0';
	$nested                = Static_Site_Importer_Layout_Projector::project( $original_markup, $nested_model, new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$nested_reasons        = array_column( array_filter( $nested['losses'], static fn( array $loss ): bool => '0.2.0' === ( $loss['item'] ?? '' ) ), 'reason' );
	$assert( array( 'item_not_host_child' ) === $nested_reasons && 2 === $nested['placed'], 'nested-item-refused-as-not-host-child', (string) wp_json_encode( $nested['losses'] ) );

	$all_hidden_model      = $validation['model'];
	foreach ( $all_hidden_model['items'] as $index => $_ ) {
		$all_hidden_model['items'][ $index ]['viewports'][700] = $box( 0, 0, 0, 0 );
	}
	unset( $_ );
	$all_hidden            = Static_Site_Importer_Layout_Projector::project( $original_markup, $all_hidden_model, new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( false === $all_hidden['applied'] && 'no_placeable_items' === $all_hidden['reason'], 'all-hidden-items-refuse-section' );

	// --- Round trip from the original block tree ----------------------------------
	$round_none   = Static_Site_Importer_Layout_Projector::project( $original_markup, $validation['model'], new Static_Site_Importer_None_Layout_Adapter() );
	$assert( true === $round_none['applied'] && $original_markup === $round_none['markup'], 'none-adapter-restores-original-markup' );
	$round_core   = Static_Site_Importer_Layout_Projector::project( $original_markup, $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$direct_core  = Static_Site_Importer_Layout_Projector::project( $original_markup, $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( $round_core['markup'] === $direct_core['markup'], 'reprojection-after-canvas-and-none-matches-direct-projection' );
	$assert( $canvas['markup'] !== $round_core['markup'], 'canvas-and-core-grid-projections-differ' );
	$dirty_core   = Static_Site_Importer_Layout_Projector::project( $canvas['markup'], $validation['model'], new Static_Site_Importer_Core_Grid_Layout_Adapter() );
	$assert( $dirty_core['markup'] !== $direct_core['markup'] && false !== strpos( $dirty_core['markup'], '"canvas":' ), 'projecting-canvas-output-without-restore-is-not-clean' );

	// --- Host layout release and capture markers -----------------------------------
	$stylesheet = Static_Site_Importer_Layout_Release::stylesheet();
	$assert( false !== strpos( $stylesheet, '.ssi-layout-canvas-host{display:block!important}' ), 'release-stylesheet-blocks-canvas-hosts', $stylesheet );
	$assert( false !== strpos( $stylesheet, '.ssi-layout-core-grid-host{display:grid!important;grid-template-columns:repeat(12,minmax(0,1fr))!important;grid-template-rows:none!important;grid-template-areas:none!important}' ), 'release-stylesheet-grids-core-hosts', $stylesheet );

	Static_Site_Importer_Layout_Marker_Filter::begin_content_capture( '' );
	Static_Site_Importer_Layout_Marker_Filter::open_frame( array( 'blockName' => 'core/group' ) );
	Static_Site_Importer_Layout_Marker_Filter::open_frame( array( 'blockName' => 'core/paragraph' ) );
	$marked_child  = Static_Site_Importer_Layout_Marker_Filter::close_frame( '<p>Wood-fired everything.</p>', array( 'blockName' => 'core/paragraph' ) );
	$marked_parent = Static_Site_Importer_Layout_Marker_Filter::close_frame( '<div class="wp-block-group bistro"><p>Wood-fired everything.</p></div>', array( 'blockName' => 'core/group' ) );
	Static_Site_Importer_Layout_Marker_Filter::end_content_capture( '' );
	$assert( '<p data-ssi-block-path="0.0">Wood-fired everything.</p>' === $marked_child, 'marker-filter-marks-nested-block-path', $marked_child );
	$assert( false !== strpos( $marked_parent, '<div class="wp-block-group bistro" data-ssi-block-path="0">' ), 'marker-filter-marks-host-block-path', $marked_parent );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: layout projection smoke passed (' . $assertions . " assertions)\n";
}
