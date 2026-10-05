<?php
/**
 * Support decisions precede form serialization and remain stable through emission.
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$source = array(
	'selector' => 'form.contact',
	'controls' => array(
		array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
	),
);

// Exercise the existing public path first: visual-state collection is planning,
// and must not serialize a whole provider form that it never consumes.
$GLOBALS['ssi_test_form_serializations'] = 0;
Static_Site_Importer_Form_Seeder::visual_states( array( 'forms' => array( $source ) ) );
$assert( 0 === $GLOBALS['ssi_test_form_serializations'], 'Visual-state collection emitted a provider form.' );

$prepare = new ReflectionMethod( Static_Site_Importer_Form_Seeder::class, 'plan_form' );
$plan    = $prepare->invoke( null, $source, true );
$assert( $plan instanceof Static_Site_Importer_Form_Mapping_Plan && $plan->is_mapped(), 'Supported form was not planned as mapped.' );
$assert( 0 === $GLOBALS['ssi_test_form_serializations'], 'Support planning serialized the final provider form.' );
$assert( array( 'jetpack/field-email' ) === $plan->decision()['supported_fields'], 'Support decision lost its planned field.' );
$row = $plan->to_report();
$assert( 1 === $GLOBALS['ssi_test_form_serializations'] && $plan->decision() === $row['mapping_decision'], 'Emission did not consume the planned decision exactly once.' );
$assert( $row === $plan->to_report() && 1 === $GLOBALS['ssi_test_form_serializations'], 'Repeated report projection regenerated provider output.' );

$unsupported = array(
	'selector' => 'form.updates',
	'controls' => array(
		array( 'tag' => 'input', 'type' => 'checkbox', 'name' => 'updates', 'label' => 'Updates', 'description' => 'Once a month.' ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Save' ),
	),
);
$GLOBALS['ssi_test_form_serializations'] = 0;
$declined = $prepare->invoke( null, $unsupported, true );
$assert( ! $declined->is_mapped() && 'declined' === $declined->decision()['status'], 'Unrenderable attribute did not decline during planning.' );
$assert( 0 === $GLOBALS['ssi_test_form_serializations'], 'Decline policy depended on emitted form markup.' );
$declined_row = $declined->to_report();
$assert( false === $declined_row['runtime_mapped'] && $declined->decision()['losses'] === $declined_row['form_receipt_unaccepted_losses'], 'Decline reporting diverged from its planned losses.' );
$assert( '' === Static_Site_Importer_Form_Seeder::binding_block_markup( array(), $declined_row ), 'Declined proposed markup became a runtime binding.' );

// A caller can inspect proposed markup when deciding a waiver. That established
// hook is the explicit pre-decision serialization exception, not a second plan.
$calls = array();
add_filter( 'static_site_importer_form_receipt_loss_accepted', static function ( $accepted, $loss, $form, $row ) use ( &$calls ): bool {
	$calls[] = array( 'loss' => $loss, 'form' => $form, 'row' => $row );
	return true;
} );
$GLOBALS['ssi_test_form_serializations'] = 0;
$waived = $prepare->invoke( null, $unsupported, true );
$assert( $waived->is_mapped() && 1 === count( $calls ), 'Waiver did not settle exactly one support decision.' );
$assert( 1 === $GLOBALS['ssi_test_form_serializations'] && ! empty( $calls[0]['row']['block_markup'] ) && ! isset( $calls[0]['row']['mapping_decision'] ), 'Waiver lost its established pre-decision emission row.' );
$waived_row = $waived->to_report();
$assert( $calls[0]['row']['block_markup'] === $waived_row['block_markup'] && 1 === $GLOBALS['ssi_test_form_serializations'] && 1 === count( $calls ), 'Final emission repeated the waiver or its serialization.' );
$assert( array() === $waived_row['mapping_decision']['losses'] && 'mapped' === $waived_row['status'], 'Waiver decision and final report disagree.' );
unset( $GLOBALS['ssi_test_hooks']['static_site_importer_form_receipt_loss_accepted'] );

$GLOBALS['ssi_test_form_serializations'] = 0;
$overflow = Static_Site_Importer_Form_Mapping_Plan::prepared(
	$source,
	array(),
	array( 'name' => 'jetpack/contact-form' ),
	null,
	array(
		'field_blocks'               => array(),
		'skipped_types'              => array(),
		'provider_layout_target_map' => array(),
		'computed_layout_receipt'    => array( 'losses' => array(), 'gate_required_loss_overflow_count' => 3, 'gate_required_loss_overflow_hash' => str_repeat( 'a', 64 ) ),
	)
);
$assert( ! $overflow->is_mapped() && 3 === $overflow->decision()['losses'][0]['loss_count'] && 0 === $GLOBALS['ssi_test_form_serializations'], 'Truncated gate losses were admitted or required serialization.' );

$empty = $prepare->invoke( null, array( 'selector' => 'form.empty', 'controls' => array() ), true );
$assert( ! $empty->is_mapped() && null === $empty->decision() && 'no_mappable_form_fields' === $empty->to_report()['reason'] && 0 === $GLOBALS['ssi_test_form_serializations'], 'Early control rejection produced an emission candidate.' );
echo 'PASS smoke-form-mapping-plan.php (' . $assertions . " assertions)\n";
