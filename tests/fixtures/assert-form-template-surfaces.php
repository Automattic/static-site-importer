<?php
/**
 * Persisted form surface regression assertions for issue #1890.
 *
 * @package Static_Site_Importer
 */

// #1890: archive templates and absorbed pagination routes use the same form
// materializer and exact persisted-fragment proof as ordinary page entities.
$surface_fixtures = require __DIR__ . '/form-template-surfaces.php';
foreach ( array_reverse( $surface_fixtures, true ) as $surface_name => $fixture ) {
	$source                   = $fixture['source_path'];
	$fallback                 = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'source_path'     => $source,
		'selector'        => 'form#wpforms-form-7658',
		'form'            => array( 'id' => 'wpforms-form-7658' ),
		'controls'        => array(
			array(
				'tag'   => 'input',
				'type'  => 'email',
				'name'  => 'email',
				'label' => 'Email',
			),
		),
	);
	$variant                  = $fallback;
	$variant['source_path']   = $fixture['variant'];
	$unmatched                = $variant;
	$unmatched['form']['id']  = 'different-form';
	$unrelated                = $fallback;
	$unrelated['source_path'] = 'unrelated/index.html';
	$surface_search           = '<!-- wp:html --><form id="wpforms-form-7658"><input type="email" name="email"></form><!-- /wp:html -->';
	$document                 = array(
		'source_path'           => $source,
		'slug'                  => $fixture['slug'],
		'resolved_block_markup' => $surface_search,
		'template_surface'      => array( 'source_variants' => array( array( 'source_path' => $source ), array( 'source_path' => $fixture['variant'] ) ) ),
	);
	if ( 'pages' === $fixture['group'] ) {
		$document['source_variants'] = $document['template_surface']['source_variants'];
		unset( $document['template_surface'] );
	}
	$surface_plan                        = array(
		'pages'          => array(),
		'templates'      => array(),
		'template_parts' => array(),
		'writes'         => array(),
		'quality'        => array( 'fallbacks' => array( $fallback, $variant, $unmatched, $unrelated ) ),
	);
	$surface_plan[ $fixture['group'] ][] = $document;
	if ( isset( $fixture['target'] ) ) {
		$surface_plan['writes'][] = array(
			'target_path' => $fixture['target'],
			'payload'     => array(
				'encoding' => 'utf8',
				'data'     => $surface_search,
			),
		);
	}
	$entity             = $fallback;
	$entity['bindings'] = array(
		array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => $source,
			'search_block_markup' => $surface_search,
			'occurrence'          => 1,
			'role'                => 'form',
		),
	);
	$provider_row       = array(
		'source_path'    => $source,
		'selector'       => $entity['selector'],
		'status'         => 'mapped',
		'runtime_mapped' => true,
		'block_markup'   => '<!-- wp:jetpack/contact-form --><!-- wp:jetpack/field-email {"label":"Email"} /--><!-- /wp:jetpack/contact-form -->',
	);
	$surface_lifecycle  = array(
		'entities' => array(
			'forms' => array(
				'adapter'  => Static_Site_Importer_Form_Seeder::adapter(),
				'manifest' => array( 'forms' => array( $entity ) ),
			),
		),
	);
	$assert( true === Static_Site_Importer_Runtime_Entity_Binding_Validation::preflight_runtime_entity_binding_anchors( $surface_plan, $surface_lifecycle, array() ), $surface_name . ' preflights the provider anchor on its canonical surface' );
	$surface_bindings    = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $surface_lifecycle, array( 'forms' => array( 'forms' => array( $provider_row ) ) ) );
	$surface_reports     = array();
	$surface_diagnostics = array();
	$surface_error       = '';
	try {
		Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $surface_plan, $surface_bindings, $surface_reports, $surface_diagnostics );
	} catch ( InvalidArgumentException $error ) {
		$surface_error = $error->getMessage();
	}
	$assert( '' === $surface_error, $surface_name . ' form surface accepts the existing provider binding: ' . $surface_error );
	$markup = $surface_plan[ $fixture['group'] ][0]['materialized_block_markup'] ?? '';
	$assert( str_contains( $markup, 'wp:jetpack/contact-form' ) && ! str_contains( $markup, 'wp:html' ), $surface_name . ' surface materializes a Jetpack form' );
	$surface_receipt = array(
		'completed' => array(
			'runtime_declarations' => array( 'entity_bindings' => $surface_reports ),
			'files'                => array(),
			'materialized_pages'   => array(),
		),
	);
	if ( isset( $fixture['target'] ) ) {
		if ( ! is_dir( dirname( $part_theme_dir . '/' . $fixture['target'] ) ) ) {
			mkdir( dirname( $part_theme_dir . '/' . $fixture['target'] ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone local theme fixture.
		}
		$written = $surface_plan['writes'][0]['payload']['data'];
		file_put_contents( $part_theme_dir . '/' . $fixture['target'], $written ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Persists the local fixture to exercise receipt completion.
		$surface_state = array(
			'theme_dir' => $part_theme_dir,
			'resolved'  => $surface_plan,
			'applied'   => array( 'runtime_declarations' => array( 'entity_bindings' => $surface_reports ) ),
		);
		Static_Site_Importer_Site_Plan_Persistence::complete_template_part_entity_bindings( $surface_state );
		$surface_receipt['completed']['runtime_declarations'] = $surface_state['applied']['runtime_declarations'];
		$surface_receipt['completed']['files'][]              = array(
			'target_path' => $fixture['target'],
			'hash'        => hash( 'sha256', $written ),
		);
		$assert( str_contains( $written, 'wp:jetpack/contact-form' ), $surface_name . ' written surface contains the provider block' );
		file_put_contents( $part_theme_dir . '/' . $fixture['target'], $surface_search ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulates a write that did not persist the replacement.
		$stale_state = $surface_state;
		Static_Site_Importer_Site_Plan_Persistence::complete_template_part_entity_bindings( $stale_state );
		$assert( 'unresolved' === reset( $stale_state['applied']['runtime_declarations']['entity_bindings'] )['status'], $surface_name . ' does not complete a receipt without the written provider fragment' );
		file_put_contents( $part_theme_dir . '/' . $fixture['target'], $written ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restores the persisted fixture.
	} else {
		foreach ( $surface_receipt['completed']['runtime_declarations']['entity_bindings'] as &$surface_report ) {
			$surface_report['status']                  = 'completed';
			$surface_report['persisted_fragment_hash'] = $surface_report['materialized_block_hash'];
		}
		unset( $surface_report );
		$surface_receipt['completed']['materialized_pages'][ $source ] = array( 'content_hash' => hash( 'sha256', $markup ) );
	}
	$surface_quality = Static_Site_Importer_Report_Diagnostics::new_conversion_report( $source );
	$surface_quality->merge_quality( array( 'fallback_count' => 2 ) );
	$surface_quality['diagnostics']             = array( $fallback, $variant );
	$surface_quality['materialization_receipt'] = $surface_receipt;
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $surface_quality );
	$assert( 0 === $surface_quality->quality()['fallback_count'], $surface_name . ' has zero unsupported fallbacks after canonical and variant reconciliation' );
	$surface_final_quality = Static_Site_Importer_Quality_Gates::finalize_quality_report( $surface_quality, array( 'fail_on_quality' => true ) );
	$assert( ! in_array( 'unsupported_html_fallback', $surface_final_quality['failure_reasons'], true ), $surface_name . ' clears the derived unsupported fallback gate' );
	$variant_identity = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $variant );
	$assert( in_array( $variant_identity, reset( $surface_reports )['replaced_fallback_identities'], true ), $surface_name . ' records the exact displaced variant identity' );
	$unmatched_quality = Static_Site_Importer_Report_Diagnostics::new_conversion_report( $source );
	$unmatched_quality->merge_quality( array( 'fallback_count' => 2 ) );
	$unmatched_quality['diagnostics']             = array( $unmatched, $unrelated );
	$unmatched_quality['materialization_receipt'] = $surface_receipt;
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $unmatched_quality );
	$assert( 2 === $unmatched_quality->quality()['fallback_count'], $surface_name . ' leaves different forms and unrelated routes unresolved' );
	$tampered_receipt = $surface_receipt;
	foreach ( $tampered_receipt['completed']['runtime_declarations']['entity_bindings'] as &$tampered_binding ) {
		$tampered_binding['persisted_fragment_hash'] = str_repeat( '0', 64 );
	}
	unset( $tampered_binding );
	$tampered_quality = Static_Site_Importer_Report_Diagnostics::new_conversion_report( $source );
	$tampered_quality->merge_quality( array( 'fallback_count' => 2 ) );
	$tampered_quality['diagnostics']             = array( $fallback, $variant );
	$tampered_quality['materialization_receipt'] = $tampered_receipt;
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $tampered_quality );
	$assert( 2 === $tampered_quality->quality()['fallback_count'], $surface_name . ' never resolves canonical or variant forms from tampered persistence proof' );
}
