<?php
/**
 * Upload capability and unconditionally hidden controls are named, accepted losses.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression with WordPress/Jetpack stubs.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

// Normalized seeder input for the reduced quote form. The hidden control is
// unlabeled intentionally: its source name must still identify the dropped field.
$source = array(
	'selector' => 'form#quote',
	'controls' => array(
		array( 'tag' => 'input', 'type' => 'text', 'id' => 'n', 'name' => 'name', 'label' => 'Full Name' ),
		array( 'tag' => 'input', 'type' => 'email', 'id' => 'e', 'name' => 'email', 'label' => 'Email' ),
		array( 'tag' => 'textarea', 'type' => 'textarea', 'id' => 'd', 'name' => 'desc', 'label' => 'Project' ),
		array( 'tag' => 'input', 'type' => 'file', 'id' => 'f', 'name' => 'file', 'label' => 'Manuscript' ),
		array( 'tag' => 'input', 'type' => 'text', 'id' => 'h', 'name' => 'h', 'hidden' => array( 'property' => 'display', 'value' => 'none' ) ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Submit' ),
	),
	'readable_blocks' => array(
		array(
			'blockName' => 'core/group',
			'attrs' => array( 'tagName' => 'form', 'anchor' => 'quote' ),
			'innerContent' => array( '<div id="quote">', null, null, null, null, null, null, '</div>' ),
			'innerBlocks' => array_map(
				static fn( int $index ): array => array( 'blockName' => 5 === $index ? 'core/button' : 'core/paragraph', 'attrs' => array(), 'form_control_index' => $index, 'form_field' => array( 'kind' => 'control', 'label' => array( 'Full Name', 'Email', 'Project', 'Manuscript', '', 'Submit' )[ $index ] ) ),
				range( 0, 5 )
			),
		),
	),
);
$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $source ) ) )['forms'][0];
$losses = array_column( $row['computed_layout_receipt']['losses'], null, 'name' );
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$assert( 'mapped' === $row['status'] && true === $row['runtime_mapped'], 'Reduced form must materialize.' );
$assert( 3 === $row['field_count'] && str_contains( $row['block_markup'], 'jetpack/contact-form' ), 'Representable fields must remain in Jetpack.' );
foreach ( array( 'Full Name', 'Email', 'Project' ) as $label ) {
	$assert( str_contains( $row['block_markup'], $label ), 'Missing field: ' . $label );
}
$assert( ! str_contains( $row['block_markup'], 'Manuscript' ) && ! str_contains( $row['block_markup'], 'authored-input' ) && ! str_contains( $row['block_markup'], 'type="file"' ), 'Dropped controls must not survive as inert inputs.' );
$assert( 2 === count( $losses ) && 'Manuscript' === $losses['file']['label'] && 'jetpack_upload_endpoint_requires_connected_site_and_supported_plan' === $losses['file']['reason_code'], 'Receipt must name the upload and its capability obstacle.' );
$assert( 'unconditionally_hidden_control' === $losses['h']['reason_code'] && array_key_exists( 'label', $losses['h'] ), 'Receipt must identify even an unlabeled hidden field.' );
foreach ( $losses as $loss ) {
	$assert( 'accepted_reported_loss' === $loss['acceptability'] && 'omitted_unit' === $loss['loss_class'], 'Dropped field is not an accepted reported loss.' );
}
$assert( array() === $row['mapping_decision']['losses'], 'Accepted provider losses must not decline the form.' );
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
$report = Static_Site_Importer_Import_Report::from_array( array( 'entity_lifecycle' => array( 'entities' => array( 'quote' => array( 'forms' => array( $row ) ) ) ) ) );
$persisted = json_decode( wp_json_encode( $report ), true );
$assert( $row['computed_layout_receipt']['losses'] === $persisted['entity_lifecycle']['entities']['quote']['forms'][0]['computed_layout_receipt']['losses'], 'Import report must retain each accepted loss with its label and reason.' );

// A separate, unaccepted semantic loss must still refuse the same form.
$source['controls'][0]['description'] = 'Unrepresentable checkbox description';
$source['controls'][0]['type'] = 'checkbox';
$declined = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $source ) ) )['forms'][0];
$assert( 'skipped' === $declined['status'] && 'form_receipt_loss_unaccepted' === $declined['reason'] && false === $declined['runtime_mapped'], 'Other unaccepted losses must still refuse materialization.' );
echo "form accepted provider loss passed\n";
