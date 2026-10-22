<?php
/**
 * Proven hidden ancestor regression, compiled through the producer contract.
 *
 * @package StaticSiteImporter
 */

$hidden_ancestor_html  = '<style>.f .fields{display:flex;flex-direction:column;gap:16px}.f .footer{display:flex}</style>
<form class="f" method="post" action="/subscribe"><div class="body"><div class="fields">
<div class="field"><label for="e">Email</label><div class="input"><input id="e" type="email" name="email" required></div></div>
<div class="field field--full"><div class="input"><input type="text" name="name" placeholder="Name"></div></div>
</div></div><div class="footer"><input type="submit" value="Subscribe"></div>REPLACE</form>';
$hidden_ancestor_trap  = '<p style="display:none !important"><label>Leave empty<textarea name="hp_trap"></textarea></label></p>';
$hidden_ancestor_rows  = array();
$hidden_ancestor_cases = array(
	'hidden'  => $hidden_ancestor_trap,
	'visible' => '',
);
foreach ( $hidden_ancestor_cases as $case => $trap ) {
	$compiled                      = ( new $artifact_compiler() )->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array(
				'index.html' => str_replace( 'REPLACE', $trap, $hidden_ancestor_html ),
			),
		)
	)->toArray();
	$validated                     = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array(
			'forms' => array( $compiled['fallbacks'][0] ?? array() ),
		)
	);
	$row                           = Static_Site_Importer_Form_Seeder::seed(
		array(
			'forms' => $validated['forms'] ?? array(),
		)
	)['forms'][0] ?? array();
	$hidden_ancestor_rows[ $case ] = $row;
	if ( 'hidden' === $case ) {
		$hidden_ancestor_form = $validated['forms'][0];
	}
	$assert( empty( $validated['errors'] ) && 'mapped' === ( $row['status'] ?? '' ), 'hidden-ancestor-' . $case . '-maps', wp_json_encode( $row ) );
	$assert( empty( $row['form_receipt_unaccepted_losses'] ) && ! in_array( 'provider_wrapper_layout_unrepresentable', array_column( $row['computed_layout_receipt']['losses'] ?? array(), 'reason_code' ), true ), 'hidden-ancestor-' . $case . '-no-unaccepted-loss', wp_json_encode( $row ) );
	$markup = $row['block_markup'] ?? '';
	$assert( 1 === preg_match( '/wp:jetpack\/field-email .*"required":true.*wp:jetpack\/field-text .*wp:.*button/s', $markup ) && ! str_contains( $markup, 'textarea' ), 'hidden-ancestor-' . $case . '-ordered-required-fields', $markup );
}
$assert( in_array( 'unconditionally_hidden_control', $hidden_ancestor_rows['hidden']['skipped_types'] ?? array(), true ) && in_array( 'provider_omitted_hidden_subtree', array_column( $hidden_ancestor_rows['hidden']['computed_layout_receipt']['operations'] ?? array(), 'strategy' ), true ), 'hidden-ancestor-omission-is-named', wp_json_encode( $hidden_ancestor_rows['hidden'] ) );
$assert( $hidden_ancestor_rows['hidden']['block_markup'] === $hidden_ancestor_rows['visible']['block_markup'], 'hidden-ancestor-visible-output-unchanged' );

// A base display fact alone is insufficient when conditional CSS can reveal it.
$conditional_ancestor                               = $hidden_ancestor_form;
$conditional_ancestor['layout_graph']['variants'][] = array(
	'node'         => 'wrapper-7',
	'condition'    => array(
		'kind'  => 'media',
		'query' => '(max-width: 48rem)',
	),
	'layout_patch' => array(
		'display' => 'block',
	),
);
$assert( empty( Static_Site_Importer_Form_Layout_Projection::unconditionally_hidden_descendants( $conditional_ancestor ) ), 'conditional-ancestor-is-not-omitted' );
$unproven_ancestor = $hidden_ancestor_form;
foreach ( $unproven_ancestor['layout_graph']['nodes'] as &$node ) {
	if ( 'wrapper-7' === $node['id'] ) {
		$node['provenance'] = array();
	}
}
unset( $node );
$assert( empty( Static_Site_Importer_Form_Layout_Projection::unconditionally_hidden_descendants( $unproven_ancestor ) ), 'unproven-ancestor-is-not-omitted' );
