<?php
/**
 * Valued hidden controls must participate in Jetpack feedback, not remain custom inputs.
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$prepare = new ReflectionMethod( Static_Site_Importer_Form_Seeder::class, 'plan_form' );
$values  = array(
	'source'         => 'homepage_weekly',
	'consentVersion' => '2026-10-17-v1',
);

// Exercise both indexed controls and the compiler's unindexed authored-input path.
foreach ( array( true, false ) as $indexed ) {
	$form     = array(
		'selector' => 'form.newsletter',
		'controls' => array(
			array(
				'tag'   => 'input',
				'type'  => 'email',
				'name'  => 'email',
				'label' => 'Email',
			),
			array(
				'tag'   => 'button',
				'type'  => 'submit',
				'label' => 'Subscribe',
			),
		),
	);
	$children = array(
		array(
			'blockName'          => 'core/paragraph',
			'attrs'              => array(),
			'form_control_index' => 0,
		),
		array(
			'blockName'          => 'core/button',
			'attrs'              => array(),
			'form_control_index' => 1,
		),
	);
	foreach ( $values + array( '_bookkeeping' => '' ) as $name => $value ) {
		$attrs = array(
			'type'  => 'hidden',
			'name'  => $name,
			'value' => $value,
		);
		$child = array(
			'blockName' => 'ssi-test/authored-input',
			'attrs'     => $attrs,
			'innerHTML' => '<input type="hidden" name="' . $name . '" value="' . $value . '">',
		);
		if ( $indexed ) {
			$child['form_control_index'] = count( $form['controls'] );
			$form['controls'][]          = $attrs + array( 'tag' => 'input' );
		}
		$children[] = $child;
	}
	$form['readable_blocks'] = array(
		array(
			'blockName'    => 'core/group',
			'attrs'        => array( 'tagName' => 'form' ),
			'innerBlocks'  => $children,
			'innerContent' => array_fill( 0, count( $children ), null ),
		),
	);
	$plan                    = $prepare->invoke( null, $form, true );
	$assert( $plan->is_mapped(), 'Newsletter form was not mapped.' );
	$row    = $plan->to_report();
	$markup = $row['block_markup'];
	$assert( 2 === substr_count( $markup, '<!-- wp:jetpack/field-hidden ' ), 'Expected two native hidden fields.' );
	$assert( ! str_contains( $markup, 'authored-input' ) && ! str_contains( $markup, '<input type="hidden"' ), 'Raw custom hidden input survived substitution.' );
	foreach ( $values as $name => $value ) {
		$assert( str_contains( $markup, '"label":"' . $name . '"' ) && str_contains( $markup, '"id":"' . $name . '"' ) && str_contains( $markup, '"defaultValue":"' . $value . '"' ), 'Hidden field lost its name or default value.' );
		$contracts = array_filter( $row['field_contract'], static fn ( array $contract ): bool => 'mapped' === $contract['decision'] && 'jetpack/field-hidden' === $contract['provider_block'] && $name === $contract['source']['name'] );
		$assert( 1 === count( $contracts ), 'Hidden field was not reported as mapped.' );
	}
	$assert( str_contains( wp_json_encode( $row['field_contract'] ), 'hidden_bookkeeping' ), 'Valueless bookkeeping was not suppressed.' );
}
echo "PASS form-hidden-field-mapping.php\n";
