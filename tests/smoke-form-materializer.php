<?php
/**
 * Smoke coverage for the configurable form provider layer and Jetpack form adapter.
 *
 * Run from the repository root:
 * php tests/smoke-form-materializer.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	$GLOBALS['ssi_test_hooks'] = array();

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback ): void {
			$GLOBALS['ssi_test_hooks'][ $hook ][] = $callback;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			foreach ( $GLOBALS['ssi_test_hooks'][ $hook ] ?? array() as $callback ) {
				$value = $callback( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			unset( $name );
			return $default;
		}
	}

	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			public static function get_instance(): self {
				return new self();
			}

			public function is_registered( string $name ): bool {
				return ! empty( $GLOBALS['ssi_jetpack_form_blocks_available'] ) && in_array( $name, array( 'jetpack/contact-form', 'jetpack/field-text' ), true );
			}
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

	$transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php';
	if ( is_readable( $transformer_bootstrap ) ) {
		require_once $transformer_bootstrap;
	}

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	// --- Default provider selection -----------------------------------------
	$assert( 'jetpack' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-default-provider-jetpack' );
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-default-provider-woocommerce' );

	$form_adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'jetpack_contact_form' === ( $form_adapter['id'] ?? '' ), 'form-adapter-resolves-jetpack' );
	$assert( 'form' === ( $form_adapter['capability'] ?? '' ), 'form-adapter-capability' );
	$assert( 'allow_missing_jetpack' === ( $form_adapter['waiver_arg'] ?? '' ), 'form-adapter-waiver' );

	// --- Woo path unaffected -------------------------------------------------
	$product_adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$assert( 'woocommerce_simple_product' === ( $product_adapter['id'] ?? '' ), 'product-adapter-unchanged' );
	$assert( 'shop' === ( $product_adapter['capability'] ?? '' ), 'product-adapter-capability-shop' );
	$assert( 'allow_missing_woocommerce' === ( $product_adapter['waiver_arg'] ?? '' ), 'product-adapter-waiver-unchanged' );

	// --- Forms manifest validation rejects submit-only forms ----------------
	$submit_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array( array( 'selector' => 'form#x', 'controls' => array( array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) )
	);
	$assert( array() === $submit_only['forms'], 'submit-only-form-rejected' );
	$assert( ! empty( $submit_only['errors'] ), 'submit-only-form-error-recorded' );

	// --- Jetpack form seeder maps controls to contact-form blocks -----------
	$forms_manifest = array(
		'forms' => array(
			array(
				'selector' => 'form.contact',
				'form'     => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Your name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'Sales' ), array( 'label' => 'Support' ) ) ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send message' ),
				),
			),
		),
	);
	$seed = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$assert( 'completed' === ( $seed['status'] ?? '' ), 'seed-status-completed' );
	$assert( 1 === ( $seed['counts']['mapped'] ?? 0 ), 'seed-one-form-mapped' );
	$row    = $seed['forms'][0] ?? array();
	$markup = (string) ( $row['block_markup'] ?? '' );
	$assert( true === ( $row['runtime_mapped'] ?? false ), 'seed-form-runtime-mapped' );
	$assert( 5 === ( $row['field_count'] ?? 0 ), 'seed-five-fields-mapped' );
	$assert( str_contains( $markup, 'wp:jetpack/contact-form' ), 'markup-contact-form' );
	$assert( str_contains( $markup, 'wp:jetpack/field-text' ), 'markup-field-text' );
	$assert( str_contains( $markup, 'wp:jetpack/field-email' ), 'markup-field-email' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone' ), 'markup-field-telephone' );
	$assert( str_contains( $markup, 'wp:jetpack/field-select' ), 'markup-field-select' );
	$assert( str_contains( $markup, 'wp:jetpack/field-textarea' ), 'markup-field-textarea' );
	$assert( str_contains( $markup, 'wp:jetpack/button' ), 'markup-submit-button' );
	$assert( str_contains( $markup, 'hello@example.com' ), 'markup-mailto-recipient' );
	$assert( str_contains( $markup, '"options":["Sales","Support"]' ), 'markup-select-options' );

	// --- Provider blocks are never claimed without the provider runtime --------
	$GLOBALS['ssi_jetpack_form_blocks_available'] = false;
	$unavailable_seed                              = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$unavailable_row                               = $unavailable_seed['forms'][0] ?? array();
	$assert( 1 === ( $unavailable_seed['counts']['skipped'] ?? 0 ), 'seed-unavailable-provider-skips-form' );
	$assert( 'provider_unavailable' === ( $unavailable_row['reason'] ?? '' ), 'seed-unavailable-provider-reason' );
	$assert( false === ( $unavailable_row['runtime_mapped'] ?? true ), 'seed-unavailable-provider-not-runtime-mapped' );
	$assert( empty( $unavailable_row['block_markup'] ), 'seed-unavailable-provider-emits-no-block-markup' );
	$GLOBALS['ssi_jetpack_form_blocks_available'] = true;

	// --- Native html_form_fallback row is enriched into a form finding -------
	$enrich   = new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'diagnostic_from_conversion_report_fallback' );
	$enriched = $enrich->invoke(
		null,
		array(
			'diagnostic_code' => 'html_form_fallback',
			'reason'          => 'form_requires_runtime',
			'source_path'     => 'website/index.html',
			'selector'        => 'form.contact',
			'tag'             => 'form',
			'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
			'controls'        => array(
				array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			),
			'control_count'   => 1,
		)
	);
	$assert( 'html_form_fallback' === ( $enriched['diagnostic_code'] ?? '' ), 'enrich-carries-diagnostic-code' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === ( $enriched['loss_class'] ?? '' ), 'enrich-loss-class-preserved-runtime-island' );
	$assert( isset( $enriched['form']['action'] ) && 'mailto:hello@example.com' === $enriched['form']['action'], 'enrich-carries-form-metadata' );
	$assert( isset( $enriched['controls'][0]['type'] ) && 'email' === $enriched['controls'][0]['type'], 'enrich-carries-controls' );
	$assert( 'form' === ( $enriched['tag'] ?? '' ), 'enrich-tag-form' );
	$assert( Static_Site_Importer_Report_Diagnostics::has_materializable_form_findings( array( 'diagnostics' => array( $enriched ) ) ), 'form-finding-requires-provider-dependency' );
	$assert( ! Static_Site_Importer_Report_Diagnostics::has_materializable_form_findings( array( 'diagnostics' => array( array( 'diagnostic_code' => 'html_product_grid_fallback' ) ) ) ), 'non-form-finding-does-not-require-provider-dependency' );

	// --- Gate loop: a mapped form finding receives the runtime-mapped signal --
	$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.contact',
		'tag'             => 'form',
		'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
		'controls'        => array(
			array( 'tag' => 'input', 'type' => 'text', 'label' => 'Your name', 'required' => true ),
			array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	// A second form with no mappable controls must stay unmapped (unacceptable loss).
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.search-only',
		'tag'             => 'form',
		'controls'        => array(
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Go' ),
		),
	);

	$seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $report, array() );
	$assert( 'jetpack' === ( $seeding['provider'] ?? '' ), 'materialize-provider-jetpack' );
	$assert( 2 === ( $seeding['form_count'] ?? 0 ), 'materialize-counts-two-form-findings' );
	$assert( 1 === ( $seeding['mapped_count'] ?? 0 ), 'materialize-one-form-mapped' );

	$mapped   = $report['diagnostics'][0];
	$unmapped = $report['diagnostics'][1];
	$assert( true === ( $mapped['runtime_mapped'] ?? false ), 'finding-runtime-mapped-set' );
	$assert( 'jetpack' === ( $mapped['mapped_provider'] ?? '' ), 'finding-mapped-provider' );
	$assert( 'jetpack/contact-form' === ( $mapped['block_name'] ?? '' ), 'finding-block-name' );
	$assert( 'acceptable_preservation' === ( $mapped['acceptability'] ?? '' ), 'finding-acceptable-preservation' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $mapped ), 'finding-stays-preserved-runtime-island' );
	$assert( empty( $unmapped['runtime_mapped'] ), 'unmappable-form-stays-unsignaled' );

	// --- Graft bridges source HTML paths to generated post_content keys ---------
	$mapped_source_report                                                       = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$mapped_source_report['source_documents']['blocks_engine_documents'][]      = array(
		'source_path'  => 'website/index.html',
		'post_type'    => 'page',
		'slug'         => 'home',
		'materialized' => true,
	);
	$mapped_source_report['diagnostics'][]                                      = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.contact',
		'tag'             => 'form',
		'controls'        => array(
			array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
		'readable_blocks' => array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Email Send</p>',
				'innerContent' => array( '<p>Email Send</p>' ),
			),
		),
	);
	$mapped_source_contents                                                     = array( 'posts/page-home.post_content' => '<!-- wp:paragraph --><p>Email Send</p><!-- /wp:paragraph -->' );
	$mapped_source_seeding                                                      = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $mapped_source_report, array(), $mapped_source_contents );
	$assert( 1 === ( $mapped_source_seeding['grafted_count'] ?? 0 ), 'graft-source-document-to-post-content-key' );
	$assert( str_contains( (string) $mapped_source_contents['posts/page-home.post_content'], 'wp:jetpack/contact-form' ), 'graft-source-document-key-contact-form' );
	$assert( 'posts/page-home.post_content' === ( $mapped_source_report['diagnostics'][0]['graft_source_path'] ?? '' ), 'graft-source-path-recorded' );

	// --- Generated core/html form diagnostics materialize per page ---------------
	$core_html_form = '<form class="newsletter-form" action="#" method="post" novalidate><input type="email" name="email" placeholder="your@email.com" autocomplete="email" required aria-label="Email address"><button type="submit">Subscribe</button></form>';
	$core_html_block = static function ( string $html ): string {
		return '<!-- wp:html ' . json_encode( array( 'content' => $html ) ) . ' -->' . $html . '<!-- /wp:html -->';
	};
	$duplicate_generated_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	foreach ( array( 'posts/page-home.post_content', 'posts/page-contact.post_content' ) as $post_content_key ) {
		$duplicate_generated_report['diagnostics'][] = array(
			'type'                => 'core_html_block',
			'diagnostic_code'     => 'generated_document_contains_core_html',
			'reason_code'         => 'generated_document_contains_core_html',
			'loss_class'          => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
			'source_path'         => $post_content_key,
			'selector'            => 'form.newsletter-form',
			'tag_name'            => 'FORM',
			'block_name'          => 'core/html',
			'source_html_preview' => $core_html_form,
		);
	}
	$duplicate_generated_contents = array(
		'posts/page-home.post_content'    => '<!-- wp:group --><div class="wp-block-group">' . $core_html_block( $core_html_form ) . '</div><!-- /wp:group -->',
		'posts/page-contact.post_content' => '<!-- wp:group --><div class="wp-block-group">' . $core_html_block( $core_html_form ) . '</div><!-- /wp:group -->',
	);
	$duplicate_generated_seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $duplicate_generated_report, array(), $duplicate_generated_contents );
	$assert( 2 === ( $duplicate_generated_seeding['mapped_count'] ?? 0 ), 'graft-generated-duplicate-forms-mapped' );
	$assert( 2 === ( $duplicate_generated_seeding['grafted_count'] ?? 0 ), 'graft-generated-duplicate-forms-grafted' );
	$assert( str_contains( (string) $duplicate_generated_contents['posts/page-home.post_content'], 'wp:jetpack/contact-form' ), 'graft-generated-home-contact-form' );
	$assert( str_contains( (string) $duplicate_generated_contents['posts/page-contact.post_content'], 'wp:jetpack/contact-form' ), 'graft-generated-contact-contact-form' );
	$assert( ! str_contains( (string) $duplicate_generated_contents['posts/page-home.post_content'], '<!-- wp:html' ), 'graft-generated-home-core-html-removed' );
	$assert( ! str_contains( (string) $duplicate_generated_contents['posts/page-contact.post_content'], '<!-- wp:html' ), 'graft-generated-contact-core-html-removed' );

	// A source fallback delegates to its generated-document finding instead of
	// reporting a duplicate unanchorable graft after URL/class normalization.
	$delegated_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$delegated_report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'source_path'     => 'website/index.html',
		'selector'        => 'main > form',
		'form'            => array( 'class' => 'source-form', 'action' => 'index.html', 'method' => 'post' ),
		'controls'        => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
	);
	$delegated_report['diagnostics'][] = array(
		'type'                => 'core_html_block',
		'reason'              => 'generated_document_contains_core_html',
		'stage'               => 'generated_theme_block_analysis',
		'source'              => 'parts/footer.html',
		'source_path'         => 'parts/footer.html',
		'selector'            => 'form.generated-form',
		'tag_name'            => 'FORM',
		'block_name'          => 'core/html',
		'source_html_preview' => $core_html_form,
		'form'                => array( 'class' => 'newsletter-form', 'action' => '#', 'method' => 'post' ),
		'controls'            => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
	);
	$delegated_contents = array( 'parts/footer.html' => $core_html_block( $core_html_form ) );
	$delegated_seeding  = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $delegated_report, array(), $delegated_contents );
	$assert( true === ( $delegated_report['diagnostics'][0]['graft_delegated_to_generated_document'] ?? false ), 'source-form-graft-delegated' );
	$assert( 1 === ( $delegated_seeding['grafted_count'] ?? 0 ), 'delegated-generated-form-grafted-once' );
	$assert( 0 === count( array_filter( $delegated_report['diagnostics'], static fn ( array $diagnostic ): bool => 'form_block_graft_unanchorable' === ( $diagnostic['type'] ?? '' ) ) ), 'delegated-source-form-no-unanchorable-warning' );

	// --- Form finding enrich carries readable_blocks for graft anchoring --------
	$enrich_readable = $enrich->invoke(
		null,
		array(
			'diagnostic_code' => 'html_form_fallback',
			'reason'          => 'form_requires_runtime',
			'source_path'     => 'website/index.html',
			'selector'        => 'form.contact',
			'tag'             => 'form',
			'controls'        => array( array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ) ),
			'readable_blocks' => array( array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array() ) ),
		)
	);
	$assert( isset( $enrich_readable['readable_blocks'][0]['blockName'] ) && 'core/group' === $enrich_readable['readable_blocks'][0]['blockName'], 'enrich-carries-readable-blocks-for-graft' );

	// --- Graft: seeded contact-form markup replaces the readable fallback -------
	$transformer_available = function_exists( 'blocks_engine_php_transformer_transform_html' );
	$build_form_diagnostic = static function ( array $transformer_fallback, string $source_path ) use ( $enrich ): array {
		return $enrich->invoke(
			null,
			array(
				'diagnostic_code' => 'html_form_fallback',
				'reason'          => 'form_requires_runtime',
				'source_path'     => $source_path,
				'selector'        => $transformer_fallback['selector'] ?? '',
				'tag'             => 'form',
				'form'            => $transformer_fallback['form'] ?? array(),
				'controls'        => $transformer_fallback['controls'] ?? array(),
				'readable_blocks' => $transformer_fallback['readable_blocks'] ?? array(),
			)
		);
	};

	if ( $transformer_available ) {
		// Single-form page: text + email + textarea + submit.
		$single_html       = '<section><h2>Contact</h2><form class="contact" action="mailto:hello@example.com" method="post"><input id="name" type="text" name="name" required aria-label="Your name"><input id="email" type="email" name="email" required aria-label="Email"><textarea name="msg" aria-label="Message"></textarea><button type="submit">Send</button></form></section>';
		$single_transform  = blocks_engine_php_transformer_transform_html( $single_html );
		$single_serialized = (string) ( $single_transform['serialized_blocks'] ?? '' );
		$single_fallback   = $single_transform['fallbacks'][0] ?? array();

		$single_report                       = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/contact.html' );
		$single_report['diagnostics'][]      = $build_form_diagnostic( $single_fallback, 'website/contact.html' );
		$single_contents                     = array( 'website/contact.html' => $single_serialized );
		$single_seeding                      = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $single_report, array(), $single_contents );

		$single_grafted = (string) ( $single_contents['website/contact.html'] ?? '' );
		$single_finding = $single_report['diagnostics'][0] ?? array();
		$assert( 'completed' === ( $single_seeding['status'] ?? '' ), 'graft-seed-status-completed' );
		$assert( 1 === ( $single_seeding['mapped_count'] ?? 0 ), 'graft-one-form-mapped' );
		$assert( 1 === ( $single_seeding['grafted_count'] ?? 0 ), 'graft-one-form-grafted' );
		$assert( true === ( $single_finding['content_grafted'] ?? false ), 'graft-finding-content-grafted' );
		$assert( true === ( $single_finding['runtime_mapped'] ?? false ), 'graft-finding-runtime-mapped' );
		$assert( 'jetpack/contact-form' === ( $single_finding['block_name'] ?? '' ), 'graft-finding-block-name' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/contact-form' ), 'graft-content-has-contact-form' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-text' ), 'graft-content-has-field-text' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-email' ), 'graft-content-has-field-email' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/field-textarea' ), 'graft-content-has-field-textarea' );
		$assert( str_contains( $single_grafted, 'wp:jetpack/button' ), 'graft-content-has-submit-button' );
		$assert( ! str_contains( $single_grafted, 'Your name (required)' ), 'graft-content-drops-paragraph-fallback' );
		$assert( str_contains( $single_grafted, 'Contact' ), 'graft-content-preserves-surrounding-content' );
		$assert( ! str_contains( $single_grafted, '<!-- wp:html' ), 'graft-content-has-no-core-html-island' );

		// Multi-form page: two forms on one page graft independently.
		$multi_html       = '<section><h2>Contact A</h2><form class="contact-a" action="mailto:a@example.com" method="post"><input id="a-email" type="email" name="email" required aria-label="Email"><textarea name="msg" aria-label="Message"></textarea><button type="submit">Send A</button></form></section><section><h2>Contact B</h2><form class="contact-b" action="mailto:b@example.com" method="post"><input id="b-name" type="text" name="name" required aria-label="Name"><button type="submit">Send B</button></form></section>';
		$multi_transform  = blocks_engine_php_transformer_transform_html( $multi_html );
		$multi_serialized = (string) ( $multi_transform['serialized_blocks'] ?? '' );

		$multi_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/contact.html' );
		foreach ( $multi_transform['fallbacks'] ?? array() as $multi_fallback ) {
			$multi_report['diagnostics'][] = $build_form_diagnostic( $multi_fallback, 'website/contact.html' );
		}
		$multi_contents = array( 'website/contact.html' => $multi_serialized );
		$multi_seeding  = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $multi_report, array(), $multi_contents );

		$multi_grafted = (string) ( $multi_contents['website/contact.html'] ?? '' );
		$assert( 2 === ( $multi_seeding['mapped_count'] ?? 0 ), 'graft-multi-two-forms-mapped' );
		$assert( 2 === ( $multi_seeding['grafted_count'] ?? 0 ), 'graft-multi-two-forms-grafted' );
		// Each form contributes one opening contact-form comment delimiter.
		$assert( 2 === substr_count( $multi_grafted, '<!-- wp:jetpack/contact-form' ), 'graft-multi-two-contact-form-blocks' );
		$assert( str_contains( $multi_grafted, 'wp:jetpack/field-email' ), 'graft-multi-form-a-field-email' );
		$assert( str_contains( $multi_grafted, 'wp:jetpack/field-text' ), 'graft-multi-form-b-field-text' );
		$assert( ! str_contains( $multi_grafted, 'Send A</a>' ), 'graft-multi-drops-form-a-fallback' );
		$assert( str_contains( $multi_grafted, 'Contact A' ) && str_contains( $multi_grafted, 'Contact B' ), 'graft-multi-preserves-both-sections' );
	}

	// --- Graft leaves an unanchorable finding's fallback in place --------------
	$unanchorable_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/unanchorable.html' );
	$unanchorable_report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/unanchorable.html',
		'selector'        => 'form.no-readable',
		'tag'             => 'form',
		'form'            => array(),
		'controls'        => array( array( 'tag' => 'input', 'type' => 'text', 'label' => 'Name' ) ),
	);
	$unanchorable_contents                = array( 'website/unanchorable.html' => '<!-- wp:paragraph --><p>keep this fallback page</p><!-- /wp:paragraph -->' );
	$unanchorable_seeding                 = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $unanchorable_report, array(), $unanchorable_contents );

	$unanchorable_grafted = (string) ( $unanchorable_contents['website/unanchorable.html'] ?? '' );
	$unanchorable_finding = $unanchorable_report['diagnostics'][0] ?? array();
	$unanchorable_diag    = null;
	foreach ( $unanchorable_report['diagnostics'] ?? array() as $unanchorable_row ) {
		if ( is_array( $unanchorable_row ) && 'form_block_graft_unanchorable' === ( $unanchorable_row['type'] ?? '' ) ) {
			$unanchorable_diag = $unanchorable_row;
			break;
		}
	}
	$assert( 1 === ( $unanchorable_seeding['mapped_count'] ?? 0 ), 'graft-unanchorable-still-mapped' );
	$assert( 0 === ( $unanchorable_seeding['grafted_count'] ?? 0 ), 'graft-unanchorable-not-grafted' );
	$assert( true === ( $unanchorable_finding['runtime_mapped'] ?? false ), 'graft-unanchorable-runtime-mapped-kept' );
	$assert( false === ( $unanchorable_finding['content_grafted'] ?? true ), 'graft-unanchorable-content-not-grafted' );
	$assert( null !== $unanchorable_diag, 'graft-unanchorable-diagnostic-recorded' );
	$assert( 'html_form_fallback_graft_unanchorable' === ( $unanchorable_diag['diagnostic_code'] ?? '' ), 'graft-unanchorable-diagnostic-code' );
	$assert( 'no_readable_fallback_blocks' === ( $unanchorable_diag['reason'] ?? '' ), 'graft-unanchorable-reason' );
	$assert( '<!-- wp:paragraph --><p>keep this fallback page</p><!-- /wp:paragraph -->' === $unanchorable_grafted, 'graft-unanchorable-fallback-left-in-place' );

	// --- Provider override routes to a different registered adapter ----------
	add_filter(
		'static_site_importer_entity_materializers',
		static function ( array $adapters ): array {
			$adapters['gravity_forms_adapter'] = array(
				'id'         => 'gravity_forms_adapter',
				'capability' => 'form',
				'provider'   => 'gravity_forms',
				'waiver_arg' => 'allow_missing_gravity_forms',
			);
			return $adapters;
		}
	);
	add_filter( 'ssi_form_plugin', static fn ( string $provider ): string => 'gravity_forms' );

	$assert( 'gravity_forms' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-provider-override' );
	$overridden = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'gravity_forms_adapter' === ( $overridden['id'] ?? '' ), 'form-adapter-routes-to-override' );
	// Shop capability stays on the default provider despite the form override.
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-provider-unaffected-by-form-override' );

	if ( empty( $failures ) ) {
		echo 'PASS smoke-form-materializer.php (' . $assertions . " assertions)\n";
		exit( 0 );
	}

	echo 'FAILURES (' . count( $failures ) . ' of ' . $assertions . " assertions):\n";
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
