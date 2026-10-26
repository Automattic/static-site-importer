<?php
/** Indexed readable forms keep source wrappers rather than projecting topology. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once $wp_root . '/wp-includes/class-wp-block-parser.php';
require_once $wp_root . '/wp-includes/blocks.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-readable-shapes.html' ) ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$checks = array(
	'validated tree reaches seeder' => empty( $validated['errors'] ) && ! empty( $validated['forms'][0]['readable_blocks'] ),
	'form maps with no gate losses' => 'mapped' === $row['status'] && empty( $row['mapping_decision']['losses'] ),
);
$anchor = $declaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] ?? '';
$page = $result['serialized_blocks'];
// Apply the canonical binding to the actual compiled page, not a fabricated shell.
$checks['binding anchor is in page'] = '' !== $anchor && 1 === substr_count( $page, $anchor );
$page = str_replace( $anchor, $row['block_markup'], $page );
$checks['exactly one provider form'] = 1 === substr_count( $page, '<!-- wp:jetpack/contact-form ' );
$checks['no topology losses'] = ! array_filter( $row['computed_layout_receipt']['losses'] ?? array(), static fn( array $loss ): bool => in_array( $loss['reason_code'], array( 'provider_wrapper_layout_unrepresentable', 'unsupported_semantic_wrapper', 'layout_target_unrepresentable' ), true ) );
$approximations = array_filter( $row['computed_layout_receipt']['losses'] ?? array(), static fn( array $loss ): bool => 'semantic_wrapper_approximated' === $loss['reason_code'] );
$checks['one non-gating fieldset approximation'] = 1 === count( $approximations ) && ! Static_Site_Importer_Form_Mapping_Decision::requires_gate( current( $approximations ) );
foreach ( array( 'Topic', 'Booking', 'Days', 'Name', 'Email', 'Phone', 'Location', 'Date', 'Guests', 'Message', 'privacy policy', 'We reply within two working days.' ) as $text ) {
	$checks[ 'copy: ' . $text ] = str_contains( $page, $text );
}
$source = $entities[0]['readable_blocks'][0];
$provider = parse_blocks( $row['block_markup'] )[0];
// The provider block carries only the form scope; the readable root is its one child.
$checks['provider block carries only the scope'] = 'jetpack/contact-form' === $provider['blockName'] && array( 'className' ) === array_keys( $provider['attrs'] ) && 1 === count( $provider['innerBlocks'] );
$actual = $provider['innerBlocks'][0];
// Compare every unchanged block and every substituted leaf at its exact path.
$compare = static function( array $before, array $after, string $path = 'root' ) use ( &$compare, &$checks ): void {
	if ( isset( $before['form_control_index'] ) ) {
		$index = $before['form_control_index'];
		$checks[ 'control at ' . $path ] = 14 === $index ? 'core/button' === $after['blockName'] && 'submit' === ( $after['attrs']['type'] ?? '' ) && '' !== trim( wp_strip_all_tags( $after['innerHTML'] ) ) : ( in_array( $index, array( 0, 1, 2, 9, 10, 11 ), true ) ? 'core/button' === $after['blockName'] : str_starts_with( $after['blockName'], 'jetpack/field-' ) );
		return;
	}
	// A fieldset's legend is stated once, as its group label, and renders as the group's first child.
	if ( 'group' === ( $before['form_field']['kind'] ?? null ) ) {
		$legend = $after['innerBlocks'][0] ?? array();
		$checks[ 'legend at ' . $path ] = 'core/paragraph' === ( $legend['blockName'] ?? null ) && wp_strip_all_tags( $before['form_field']['label'] ) === trim( wp_strip_all_tags( $legend['innerHTML'] ) ) && $before['form_field']['label_attributes']['style'] === $legend['attrs']['style'];
		if ( $checks[ 'legend at ' . $path ] ) {
			array_shift( $after['innerBlocks'] );
			array_splice( $after['innerContent'], array_search( null, $after['innerContent'], true ), 1 );
		}
	}
	// The root keeps its saved frame (padding, border, layout classes); only the element becomes a div inside the provider's form.
	$root_markup = static fn( array $block ): string => implode( '', array_filter( $block['innerContent'], 'is_string' ) );
	$checks[ 'wrapper at ' . $path ] = 'root' === $path ? 'core/group' === $after['blockName'] && array_diff_key( $before['attrs'], array( 'tagName' => true ) ) === $after['attrs'] && str_replace( array( '<form ', '</form>' ), array( '<div ', '</div>' ), $root_markup( $before ) ) === $root_markup( $after ) && str_contains( $root_markup( $after ), 'padding-top:28px' ) : $before['blockName'] === $after['blockName'] && $before['attrs'] === $after['attrs'] && $before['innerContent'] === $after['innerContent'];
	$checks[ 'child count at ' . $path ] = count( $before['innerBlocks'] ) === count( $after['innerBlocks'] );
	foreach ( $before['innerBlocks'] as $i => $child ) {
		$compare( $child, $after['innerBlocks'][ $i ] ?? array(), $path . '/' . $i );
	}
};
$compare( $source, $actual );
$checks['field paint still compiles'] = str_contains( $row['provider_layout_overlay_css']['css'] ?? '', 'border-radius:12px' );
$checks['textarea rows still compile'] = str_contains( $page, 'ssi-textarea-rows-' );
// Captured shells replace their member buttons with one semantic choice field.
foreach ( array( false, true ) as $multiple ) {
	$captured = $validated['forms'][0];
	$captured['choice_groups'] = array( array(
		'group' => array( 'label' => 'Topic' ),
		'choices' => array( array( 'label' => 'General', 'source_value' => 'general' ), array( 'label' => 'Bookings', 'source_value' => 'bookings' ), array( 'label' => 'Other', 'source_value' => 'other' ) ),
		'observed_transition' => array( 'selected' => array( true, $multiple, false ) ),
	) );
	// A fieldset framing one captured choice group is that group's node.
	$captured['readable_blocks'][0]['innerBlocks'][1]['form_choice_group_index'] = 0;
	$choice_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $captured ) ) )['forms'][0];
	$choice_tree = parse_blocks( $choice_row['block_markup'] )[0]['innerBlocks'][0]['innerBlocks'][1];
	$checks['captured choice ' . (int) $multiple] = 'mapped' === $choice_row['status'] && ( $multiple ? 'jetpack/field-checkbox-multiple' : 'jetpack/field-radio' ) === $choice_tree['blockName'] && str_contains( $choice_tree['attrs']['className'], 'is-style-button' ) && array( 'General', 'Bookings', 'Other' ) === $choice_tree['attrs']['options'];
	// Every emitted provider block must be one Jetpack registers at runtime.
	preg_match_all( '/<!-- wp:(jetpack\/[a-z0-9-]+)/', $row['block_markup'] . $choice_row['block_markup'], $emitted );
	$checks['registered provider blocks ' . (int) $multiple] = array() === array_diff( array_unique( $emitted[1] ), Static_Site_Importer_Jetpack_Forms_Runtime::required_block_types() );
}
// Suppression removes the child and its null slot, not its source ancestors.
$hidden = $validated['forms'][0];
$hidden['controls'][3]['type'] = 'hidden';
$hidden_row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $hidden ) ) )['forms'][0];
$hidden_tree = parse_blocks( $hidden_row['block_markup'] )[0]['innerBlocks'][0];
$checks['hidden bookkeeping dropped without dropping row'] = 'mapped' === $hidden_row['status'] && 2 === count( $hidden_tree['innerBlocks'][2]['innerBlocks'] );
// Incomplete producer trees are explicitly declined through the public seeder.
$partial = $validated['forms'][0];
unset( $partial['readable_blocks'][0]['innerBlocks'][2]['innerBlocks'][0]['innerBlocks'][0]['form_control_index'] );
$checks['partial trees are unsupported'] = ! Static_Site_Importer_Form_Readable_Substitution::supports( $partial );
$legacy = $validated['forms'][0];
unset( $legacy['readable_blocks'] );
$empty = $validated['forms'][0];
$empty['readable_blocks'] = array();
foreach ( array( 'missing' => $legacy, 'empty' => $empty, 'partial' => $partial ) as $case => $unsupported ) {
	$declined = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => array( $unsupported ) ) )['forms'][0];
	$checks[ $case . ' tree has gate-visible reason' ] = 'skipped' === $declined['status'] && 'incomplete_readable_form_tree' === $declined['reason'] && false === $declined['runtime_mapped'] && empty( $declined['block_markup'] );
}
$before = $declined;
$checks['root presentation retained'] = $source['attrs']['style'] === $actual['attrs']['style'] && $source['attrs']['layout'] === $actual['attrs']['layout'] && ! isset( $actual['attrs']['tagName'] );
$checks['indices never serialize'] = ! str_contains( $page, 'form_control_index' ) && ! str_contains( $page, 'form_choice_group_index' );
echo 'Before: ' . ( $before['mapping_decision']['status'] ?? $before['status'] ) . ', gate losses=' . count( $before['mapping_decision']['losses'] ?? array() ) . "\n";
echo 'After: ' . $row['status'] . ', gate losses=' . count( $row['mapping_decision']['losses'] ) . ', fields=' . $row['field_count'] . "\n";
// Exercise both presentation facts on a neutral, fully indexed producer tree.
$boxes_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-readable-boxes.html' ) ),
	)
)->toArray();
$boxes_plan        = $boxes_result['source_reports']['wordpress_site_plan'];
$boxes_declaration = current( array_filter( $boxes_plan['runtime_declarations'], static fn( array $entry ): bool => 'forms' === ( $entry['type'] ?? null ) ) );
$boxes_entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $boxes_declaration['payload']['entities'], $boxes_plan );
$boxes_validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $boxes_entities ) );
$boxes_row         = Static_Site_Importer_Form_Seeder::seed( $boxes_validated )['forms'][0];
$checks['box fixture uses readable substitution'] = empty( $boxes_validated['errors'] ) && Static_Site_Importer_Form_Readable_Substitution::supports( $boxes_validated['forms'][0] ) && 'mapped' === $boxes_row['status'];
$boxes_css = $boxes_row['provider_layout_overlay_css']['css'] ?? '';
$checks['readable checkbox wrap has source gap'] = 1 === preg_match( '/[^{}]*-wrap \\.contact-form__checkbox-wrap\\{[^{}]*gap:8px/', $boxes_css );
$checks['readable editor checkbox option has source gap'] = 1 === preg_match( '/[^{}]* > \\.wp-block-jetpack-option\\{[^{}]*gap:8px/', $boxes_row['provider_layout_overlay_css']['editor_css'] ?? '' );
$checks['readable submit wrapper has no padding'] = 1 === preg_match( '/[^{}]* \\.ssi-node-[a-f0-9]{12}\\{[^{}]*padding:0!important(?:;|\\})/', $boxes_css ) && ! preg_match( '/[^{}]* \\.ssi-node-[a-f0-9]{12}\\{[^{}]*padding:5px 10px/', $boxes_css );
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
