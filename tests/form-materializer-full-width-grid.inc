<?php
/** Reduced full-track stack and genuinely two-up companion. */
$grid_html = '<form method="post" action="/subscribe"><div class="body"><div class="fields" style="display:grid;grid-template-columns:repeat(12,1fr);gap:16px 2%">'
	. '<div class="field" style="grid-column:1 / -1"><label for="e">Email</label><input id="e" type="email" name="email" required></div>'
	. '<div class="field" style="grid-column:span 12"><input type="text" name="name" placeholder="Name"></div></div></div>'
	. '<div class="footer" style="display:flex"><input type="submit" value="Subscribe"></div></form>';
foreach ( array( 'stack' => $grid_html, 'two-up' => str_replace( array( '1 / -1', 'span 12' ), 'span 6', $grid_html ) ) as $case => $html ) {
	$source = ( ( new $artifact_compiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray() )['fallbacks'][0];
	$nodes = array_column( $source['layout_graph']['nodes'], null, 'id' );
	$assert( array( 'columns' => 'repeat(12,1fr)', 'display' => 'grid', 'gap' => '16px 2%' ) === $nodes['wrapper-1']['layout'] && array( 'display' => 'flex' ) === $nodes['wrapper-4']['layout'], 'grid-and-footer-source-facts-' . $case, wp_json_encode( $nodes ) );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $source ) ) );
	$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$losses = $row['mapping_decision']['losses'] ?? array();
	$assert( empty( $validated['errors'] ), 'grid-fixture-validates-' . $case, wp_json_encode( $validated['errors'] ) );
	if ( 'stack' === $case ) {
		$markup = $row['block_markup'] ?? '';
		$css = $row['provider_layout_overlay_css']['css'] ?? '';
		$assert( 'mapped' === ( $row['status'] ?? '' ) && array() === $losses && empty( $row['form_receipt_unaccepted_losses'] ), 'full-track-grid-materializes-without-loss', wp_json_encode( $row ) );
		$assert( 1 === preg_match( '/field-email .*"required":true[\s\S]*field-text [\s\S]*Subscribe/', $markup ) && str_contains( $markup, '"placeholder":"Name"' ), 'full-track-grid-preserves-controls-in-order', $markup );
		$assert( 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}\{display:flex;flex-direction:column;gap:16px\}/', $css ) && ! str_contains( $css, 'grid-template-columns:repeat(12' ), 'full-track-grid-represents-row-gap-as-stack', $css );
	} else {
		$assert( in_array( 'provider_wrapper_layout_unrepresentable', array_column( $losses, 'reason_code' ), true ) && 'mapped' !== ( $row['status'] ?? '' ), 'two-up-grid-keeps-unrepresented-layout-loss', wp_json_encode( $row ) );
		$assert( in_array( hash( 'sha256', 'wrapper-1' ), array_column( $losses, 'node_hash' ), true ) && ! in_array( hash( 'sha256', 'wrapper-4' ), array_column( $losses, 'node_hash' ), true ), 'loss-belongs-to-grid-not-single-submit-flex-footer', wp_json_encode( $losses ) );
	}
}
