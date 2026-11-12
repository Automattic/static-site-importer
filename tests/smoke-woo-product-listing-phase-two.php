<?php
/**
 * Reduced neutral listing fixtures for #2356 Phase 2 (option A).
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Files.FileName, Squiz.Commenting -- Standalone API stubs below.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound
require __DIR__ . '/smoke-woo-product-listing-category.php';
add_filter( 'pre_option', static fn( mixed $value, string $name ): mixed => 'blog_charset' === $name ? 'UTF-8' : array(), 10, 2 );
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-grid-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-fallback-contract.php';

$semantic_orders = Static_Site_Importer_Woo_Product_Listing_Materializer::sort_parameter(
	array(
		'options' => array(
			array( 'order' => 'default' ),
			array( 'order' => 'popularity' ),
			array( 'order' => 'newest' ),
			array( 'order' => 'price_asc' ),
			array( 'order' => 'price_desc' ),
			array( 'order' => 'name_asc' ),
			array( 'order' => 'name_desc' ),
			array( 'value' => 'most-relevant' ),
		),
	)
);
$assert( array( 'menu_order', 'popularity', 'date', 'price', 'price-desc', 'title-asc', 'title-desc', null ) === $semantic_orders['values'], 'Producer sort orders map to catalog ordering; an option without one stays unbound.' );

$source         = 'collections/quiet.html';
$empty          = '<!-- wp:html --><div id="items"><p>No items yet.</p></div><!-- /wp:html -->';
$form           = '<!-- wp:html --><form id="ordering"><select name="sort_by"><option value="manual">Featured</option><option value="price-ascending">Lowest price</option><option value="surprise">Surprise me</option></select></form><!-- /wp:html -->';
$region         = '<!-- wp:group --><div class="wp-block-group">' . $form . $empty . '</div><!-- /wp:group -->';
$binding        = static fn( string $markup, string $role ): array => array(
	'schema'              => 'generic/block-binding/v1',
	'source_path'         => $source,
	'search_block_markup' => $markup,
	'occurrence'          => 1,
	'role'                => $role,
);
$region_binding = $binding( $region, 'commerce_listing' );
// Producer spans in the pre-projection source document.
$span                             = static fn( string $page, string $markup ): array => array(
	'schema' => 'blocks-engine/runtime-binding-position/v1',
	'offset' => (int) strpos( $page, $markup ),
	'length' => strlen( $markup ),
);
$anchor                           = static fn( string $page, string $markup ): array => array(
	'schema'            => 'blocks-engine/projected-binding-anchor/v2',
	'source_block_hash' => hash( 'sha256', $markup ),
	'source_position'   => $span( $page, $markup ),
);
$sort_binding                     = $binding( $form, 'commerce_listing_control' );
$sort_binding['control']          = 'ordering';
$sort_binding['projected_anchor'] = $anchor( $region, $form );
$listing                          = array(
	'source_path'   => $source,
	'selector'      => 'div.wp-block-group',
	'grid_selector' => '#items',
	'controls'      => array(
		array(
			'id'      => 'ordering',
			'kind'    => 'sort',
			'options' => array(
				array(
					'value' => 'manual',
					'label' => 'Featured',
					'order' => 'default',
				),
				array(
					'value' => 'price-ascending',
					'label' => 'Lowest price',
					'order' => 'price_asc',
				),
				array(
					'value' => 'surprise',
					'label' => 'Surprise me',
				),
			),
		),
	),
	'bindings'      => array( $region_binding, $sort_binding ),
);
// Producer-declared fallback: its page is `source` and its resolved block is its binding.
$fallback = array(
	'source'              => $source,
	'selector'            => '#ordering',
	'form_intent'         => 'filter_sort',
	'control_count'       => 1,
	'controls'            => array( array( 'tag' => 'select', 'name' => 'sort_by' ) ),
	'binding'             => array(
		'schema'              => 'generic/block-binding/v1',
		'search_block_markup' => $form,
		'occurrence'          => 1,
		'role'                => 'form',
		'position'            => $span( $region, $form ),
	),
	'source_html_preview' => '<form id="ordering"><select name="sort_by"></select></form>',
);
// The empty collection is declared as a grid with no items and no card template.
$empty_grid = array(
	'source_path' => $source,
	'selector'    => '#items',
	'items'       => array(),
	'bindings'    => array(
		array(
			'schema'                       => 'generic/block-binding/v1',
			'source_path'                  => $source,
			'search_block_markup'          => $empty,
			'occurrence'                   => 1,
			'role'                         => 'commerce_product_grid',
			'superseded_runtime_selectors' => array( '#items' ),
		),
	),
);
$validated  = Static_Site_Importer_Entity_Materializer_Registry::validate_product_grids_manifest( array( 'product_grids' => array( $empty_grid ) ) );
$assert( array() === $validated['errors'] && 1 === count( $validated['product_grids'] ), wp_json_encode( $validated['errors'] ) . 'An empty grid declared with no items and no template is valid.' );
// The resolved plan carries no quality findings; fallbacks come from the source plan.
$fallbacks = array( $fallback );
$plan      = array(
	'pages' => array(
		array(
			'source_path'           => $source,
			'title'                 => 'Quiet collection',
			'slug'                  => 'quiet',
			'resolved_block_markup' => $region,
		),
	),
);
$lifecycle = array(
	'entities' => array(
		array(
			'adapter'  => array( 'entity_collection' => 'product_listings' ),
			'manifest' => array( 'product_listings' => array( $listing ) ),
		),
		array(
			'adapter'  => array( 'entity_collection' => 'product_grids' ),
			'manifest' => array( 'product_grids' => array( $empty_grid ) ),
		),
	),
);
$prepared  = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $lifecycle, $plan, $fallbacks );
$assert( array() === $prepared['entities'][1]['manifest']['product_grids'], 'An empty grid is owned by its listing, never materialized as a card grid.' );
$entity = $prepared['entities'][0]['manifest']['product_listings'][0];
$assert( ! empty( $entity['empty_listing'] ) && array( $region_binding ) === $entity['bindings'], 'Empty region is adopted even without products.' );
$undeclared = $lifecycle;
unset( $undeclared['entities'][1] );
$not_empty = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $undeclared, $plan, $fallbacks );
$assert( empty( $not_empty['entities'][0]['manifest']['product_listings'][0]['bindings'] ), 'Without a declared empty grid the listing is not adopted.' );
$report = Static_Site_Importer_Woo_Product_Listing_Materializer::materialize( array( 'product_listings' => array( $entity ) ) );
$assert( 'mapped' === $report['product_listings'][0]['status'], 'Empty listing materializes.' );
$markup     = Static_Site_Importer_Woo_Product_Listing_Materializer::binding_block_markup( $entity, array( 'status' => 'mapped' ) );
$collection = parse_blocks( $markup )[0];
$query      = $collection['attrs']['query'];
$assert( false === $query['inherit'] && array( $report['product_listings'][0]['term_id'] ) === $query['taxQuery']['product_cat'] && ! isset( $query['woocommerceHandPickedProducts'] ), 'Empty collection queries the category without hand-picked IDs.' );
$assert( str_contains( $markup, '<!-- wp:woocommerce/product-template' ) && str_contains( $markup, '<!-- wp:woocommerce/product-collection-no-results -->' . $empty ), 'Source empty state is no-results content, alongside a live product template.' );
$assert( str_contains( $markup, 'staticSiteImporterListingControl' ), 'Page sort form is wired.' );
$wired    = Static_Site_Importer_Woo_Product_Listing_Materializer::sort_parameter( $entity['controls'][0] );
$rendered = Static_Site_Importer_Woo_Product_Listing_Materializer::render_control( '<form><select name="sort_by"><option>Featured</option><option>Lowest price</option><option>Surprise me</option></select></form>', array( 'attrs' => array( 'metadata' => array( Static_Site_Importer_Woo_Product_Listing_Materializer::CONTROL_KEY => $wired ) ) ) );
$assert( str_contains( $rendered, 'name="orderby"' ) && str_contains( $rendered, 'value="price"' ) && str_contains( $rendered, 'disabled' ), 'Select uses orderby; unknown options are disabled, not guessed.' );
$record_method = new ReflectionMethod( Static_Site_Importer_Entity_Materializer_Registry::class, 'block_binding_record' );
$receipt       = $record_method->invoke( null, 'listing', $region_binding, $markup, $entity, array( 'provider' => 'woocommerce' ) );
$expected      = Static_Site_Importer_Entity_Materializer_Registry::page_region_fallback_identity( 'product-grid', $source, '#items' );
$assert( $expected[0] === $receipt['fallback_reconciliation_identity'] && hash( 'sha256', $markup ) === $receipt['materialized_block_hash'], 'Empty grid has the standard adopted-region receipt.' );
$assert( in_array( Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback ), $receipt['replaced_fallback_identities'], true ), 'Sort form fallback is reconciled by the listing receipt.' );
foreach ( array( 'import-report', 'diagnostic-projection', 'quality-gates' ) as $file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $file . '.php';
}
$fallback['type']                     = 'unsupported_html_fallback';
$fallback['code']                     = 'html_form_fallback';
$receipt['status']                    = 'completed';
$receipt['persisted_fragment_hash']   = hash( 'sha256', $markup );
$receipt['materialized_content_hash'] = hash( 'sha256', $markup );
$quality_report                       = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 2 ),
		'diagnostics'             => array(
			array(
				'type'               => 'unsupported_html_fallback',
				'code'               => 'html_product_grid_fallback',
				'source_path'        => $source,
				'selector'           => '#items',
				'container_selector' => '#items',
			),
			$fallback,
		),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array( $source => array( 'content_hash' => hash( 'sha256', $markup ) ) ),
				'runtime_declarations' => array( 'entity_bindings' => array( $receipt ) ),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $quality_report );
$assert( 2 === $quality_report['quality_resolutions']['resolved_by_provider'] && 0 === $quality_report['quality_resolutions']['unresolved_fallback_count'], 'Quality reconciliation resolves both listing-owned buckets using persisted receipts: ' . wp_json_encode( $quality_report['quality_resolutions'] ) );
$lifecycle['entities'][0]['manifest']['product_listings'][0]['controls'][0]['options'] = array(
	array(
		'value' => 'surprise',
		'label' => 'Surprise me',
	),
);
$prepared       = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $lifecycle, $plan, $fallbacks );
$unknown        = $prepared['entities'][0]['manifest']['product_listings'][0];
$unknown_markup = Static_Site_Importer_Woo_Product_Listing_Materializer::binding_block_markup( $unknown, array( 'status' => 'mapped' ) );
$unknown_report = Static_Site_Importer_Woo_Product_Listing_Materializer::materialize( array( 'product_listings' => array( $unknown ) ) );
$assert( str_contains( $unknown_markup, $form ) && empty( $unknown['replaced_fallback_identities'] ) && 'sort_options_unmappable' === $unknown_report['product_listings'][0]['controls'][0]['reason'], 'Unmappable sort stays an honest fallback with a reason.' );
// A populated page keeps the exact source order while also scoping to its category.
$card                       = '<!-- wp:paragraph --><p>Item</p><!-- /wp:paragraph -->';
$populated_grid             = '<!-- wp:group --><div class="wp-block-group">' . $card . '</div><!-- /wp:group -->';
$populated                  = $entity;
$populated['empty_listing'] = false;
$populated['grid']          = array(
	'items'         => array( array( 'product' => 'second-item' ), array( 'product' => 'first-item' ) ),
	'item_template' => array( 'block_markup' => $card ),
	'bindings'      => array( array( 'search_block_markup' => $populated_grid ) ),
);
$populated['bindings'][0]['search_block_markup'] = str_replace( $empty, $populated_grid, $region );
$populated_markup                                = Static_Site_Importer_Woo_Product_Listing_Materializer::binding_block_markup( $populated, $report['product_listings'][0] );
$populated_query                                 = parse_blocks( $populated_markup )[0]['attrs']['query'];
$assert( array( 12, 11 ) === $populated_query['woocommerceHandPickedProducts'] && 'post__in' === $populated_query['orderBy'] && $query['taxQuery'] === $populated_query['taxQuery'], 'Populated listing keeps hand-picked source order and adds category scope.' );

// A select-level producer binding inside the form must reconcile the form too.
$select      = '<!-- wp:html --><select name="sort_by"><option value="date">Newest</option></select><!-- /wp:html -->';
$nested_form = '<!-- wp:group {"tagName":"form","anchor":"ordering"} --><form id="ordering" class="wp-block-group">' . $select . '</form><!-- /wp:group -->';
$lifecycle['entities'][0]['manifest']['product_listings'][0]['controls'][0]['options']             = array(
	array(
		'value' => 'date',
		'label' => 'Newest',
		'order' => 'newest',
	),
);
$lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][1]['search_block_markup'] = $select;
$nested_region = str_replace( $form, $nested_form, $region );
$lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][0]['search_block_markup'] = $nested_region;
$lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][1]['projected_anchor']    = $anchor( $nested_region, $select );
$plan['pages'][0]['resolved_block_markup']      = $nested_region;
$fallbacks[0]['binding']['search_block_markup'] = $nested_form;
$fallbacks[0]['binding']['position']            = $span( $nested_region, $nested_form );
// The theme places the sort form beside the grid, outside the listing region.
$beside_page                                      = $nested_form . $empty;
$beside_plan                                      = $plan;
$beside_plan['pages'][0]['resolved_block_markup'] = $beside_page;
$beside_lifecycle                                 = $lifecycle;
$beside_lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][0]['search_block_markup'] = $empty;
$beside_lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][1]['projected_anchor']    = $anchor( $beside_page, $select );
$beside_fallbacks                           = $fallbacks;
$beside_fallbacks[0]['binding']['position'] = $span( $beside_page, $nested_form );
$beside                                     = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $beside_lifecycle, $beside_plan, $beside_fallbacks )['entities'][0]['manifest']['product_listings'][0];
$assert( ! empty( $beside['replaced_fallback_identities'] ), 'A sort form placed beside the listing region reconciles when its control is bound.' );
// An identical form elsewhere on the page holds no bound control; it stays a fallback.
$elsewhere_fallbacks                                      = $beside_fallbacks;
$elsewhere_fallbacks[0]['binding']['position']['offset'] += strlen( $beside_page );
$elsewhere = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $beside_lifecycle, $beside_plan, $elsewhere_fallbacks )['entities'][0]['manifest']['product_listings'][0];
$assert( empty( $elsewhere['replaced_fallback_identities'] ), 'A sort form whose span holds no bound control stays unresolved.' );
$normalize  = new ReflectionMethod( Static_Site_Importer_Entity_Materializer_Registry::class, 'normalize_block_binding' );
$normalized = $normalize->invoke( null, $beside_lifecycle['entities'][0]['manifest']['product_listings'][0]['bindings'][1] );
$assert( $span( $beside_page, $select )['offset'] === ( $normalized['projected_anchor']['source_position']['offset'] ?? null ), 'Normalization keeps the control span the producer declared.' );
$prepared = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $lifecycle, $plan, $fallbacks );
$nested   = $prepared['entities'][0]['manifest']['product_listings'][0];
$assert( ! empty( $nested['replaced_fallback_identities'] ), 'A sort select inside its single-select form reconciles the form.' );

// Every form control must be bound before the listing can retire its fallback.
$price_inputs = array(
	'<!-- wp:html --><input type="number" name="min_price" /><!-- /wp:html -->',
	'<!-- wp:html --><input type="number" name="max_price" /><!-- /wp:html -->',
);
$price_controls = array();
foreach ( array( 'min', 'max' ) as $bound ) {
	$price_controls[] = array(
		'id'       => 'range:price:' . $bound,
		'kind'     => 'range',
		'key'      => 'price:' . $bound,
		'field'    => 'price',
		'bound'    => $bound,
		'selector' => 'main form > div > span:nth-of-type(' . ( 'min' === $bound ? '1' : '2' ) . ') > input',
	);
}
foreach ( array(
	'price bounds' => array( $price_inputs, $price_controls, '', true ),
	'single sort'  => array( array( $select ), $nested['controls'], '', true ),
	'unbound text' => array( $price_inputs, $price_controls, '<input type="text" name="search" />', false ),
) as $case => list( $inputs, $controls, $unbound, $replaced ) ) {
	$submit = $controls === $price_controls ? '<input type="submit" value="Filtrar" />' : '';
	$case_form   = '<!-- wp:group {"tagName":"form"} --><form class="wp-block-group">' . implode( '', $inputs ) . $submit . $unbound . '</form><!-- /wp:group -->';
	$case_region = str_replace( $form, $case_form, $region );
	$case_listing = $listing;
	$case_listing['controls'] = $controls;
	$case_listing['bindings'] = array( $binding( $case_region, 'commerce_listing' ) );
	foreach ( $inputs as $index => $input ) {
		$control_binding = $binding( $input, 'commerce_listing_control' );
		$control_binding['control'] = $controls[ $index ]['id'];
		$control_binding['projected_anchor'] = $anchor( $case_region, $input );
		$case_listing['bindings'][] = $control_binding;
	}
	$case_lifecycle = $lifecycle;
	$case_lifecycle['entities'][0]['manifest']['product_listings'] = array( $case_listing );
	$case_plan = $plan;
	$case_plan['pages'][0]['resolved_block_markup'] = $case_region;
	$case_fallback = $fallback;
	$case_fallback['controls'] = $controls === $price_controls ? array(
		array( 'tag' => 'input', 'type' => 'number', 'name' => 'min_price' ),
		array( 'tag' => 'input', 'type' => 'number', 'name' => 'max_price' ),
		array( 'tag' => 'input', 'type' => 'submit' ),
	) : $fallback['controls'];
	if ( '' !== $unbound ) {
		$case_fallback['controls'][] = array( 'tag' => 'input', 'type' => 'text', 'name' => 'search' );
	}
	$case_fallback['control_count'] = count( $case_fallback['controls'] );
	$case_fallback['binding']['search_block_markup'] = $case_form;
	$case_fallback['binding']['position'] = $span( $case_region, $case_form );
	$case_entity = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $case_lifecycle, $case_plan, array( $case_fallback ) )['entities'][0]['manifest']['product_listings'][0];
	if ( 'price bounds' === $case ) {
		foreach ( array( 'reset', 'button', 'image' ) as $action_type ) {
			$action_fallback = $case_fallback;
			$action_fallback['controls'][2]['type'] = $action_type;
			$action_entity = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $case_lifecycle, $case_plan, array( $action_fallback ) )['entities'][0]['manifest']['product_listings'][0];
			$assert( ! empty( $action_entity['replaced_fallback_identities'] ), $action_type . ' inputs are not entry controls.' );
		}
		$action_fallback['controls'][2] = array( 'tag' => 'button' );
		$action_entity = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $case_lifecycle, $case_plan, array( $action_fallback ) )['entities'][0]['manifest']['product_listings'][0];
		$assert( ! empty( $action_entity['replaced_fallback_identities'] ), 'Buttons are not entry controls, even without a type.' );
	}
	$case_markup = Static_Site_Importer_Woo_Product_Listing_Materializer::archive_block_markup( $case_entity );
	$case_receipt = $record_method->invoke( null, 'listing', $case_listing['bindings'][0], $case_markup, $case_entity, array( 'provider' => 'woocommerce' ) );
	$assert( $replaced === in_array( Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $case_fallback ), $case_receipt['replaced_fallback_identities'], true ), $case . ': only fully bound forms are replaced.' );
	if ( $controls === $price_controls ) {
		$wired_params = array();
		$check_inputs = static function ( array $blocks ) use ( &$check_inputs, &$wired_params, $assert, $case ): void {
			foreach ( $blocks as $input_block ) {
				$param = $input_block['attrs']['metadata'][ Static_Site_Importer_Woo_Product_Listing_Materializer::CONTROL_KEY ]['param'] ?? '';
				if ( in_array( $param, array( 'min_price', 'max_price' ), true ) ) {
					$assert( '' === $input_block['attrs']['metadata'][ Static_Site_Importer_Woo_Product_Listing_Materializer::CONTROL_KEY ]['clear'], $case . ': numeric bounds clear to an empty value.' );
					$rendered_input = Static_Site_Importer_Woo_Product_Listing_Materializer::render_control( $input_block['innerHTML'], $input_block );
					$assert( str_contains( $rendered_input, 'data-ssi-listing-param="' . $param . '"' ), $case . ': price input is wired to ' . $param . '.' );
					$wired_params[] = $param;
				}
				$check_inputs( $input_block['innerBlocks'] ?? array() );
			}
		};
		$check_inputs( parse_blocks( $case_markup ) );
		$assert( array( 'min_price', 'max_price' ) === $wired_params, $case . ': both price bounds are wired.' );
		unset( $wired_params );
	}
}

class WP_Block {
	public array $context = array();
}
function WC(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return (object) array(
		'query' => new class() {
			public function get_catalog_ordering_args( string $order ): array {
				return array(
					'orderby' => 'price-desc' === $order ? 'price' : $order,
					'order'   => 'price-desc' === $order ? 'DESC' : 'ASC',
				);
			}
		},
	);
}
$block                   = new WP_Block();
$block->context['query'] = $query;
$source_query            = array(
	'orderby'   => 'post__in',
	'post__in'  => array( 12, 11 ),
	'tax_query' => array( 'category' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query preservation fixture.
);
$_GET['orderby']         = 'price-desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public request fixture.
$ordered                 = Static_Site_Importer_Woo_Product_Listing_Materializer::page_ordering( $source_query, $block );
$assert( 'price' === $ordered['orderby'] && 'DESC' === $ordered['order'] && $source_query['post__in'] === $ordered['post__in'] && $source_query['tax_query'] === $ordered['tax_query'], 'Chosen catalog ordering changes order, not category or membership.' );
unset( $_GET['orderby'] );
$assert( Static_Site_Importer_Woo_Product_Listing_Materializer::page_ordering( $source_query, $block ) === $source_query, 'Hand-picked default keeps source order.' );
$block->context['query'] = array( 'isProductCollectionBlock' => true );
$_GET['orderby']         = 'date';
$assert( Static_Site_Importer_Woo_Product_Listing_Materializer::page_ordering( $source_query, $block ) === $source_query, 'Unrelated collections are untouched.' );
unset( $_GET['orderby'] );
echo "Listing Phase 2 smoke passed.\n";
