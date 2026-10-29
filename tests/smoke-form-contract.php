<?php
/**
 * Form-slice contract proof on a recruitment-style source form.
 *
 * Every captured control decides: mapped onto a provider field that carries the
 * source's options, defaults and constraints, an explicit owner handoff naming
 * the control the provider cannot represent, or the form's submit. A source
 * form with no recovered recipient hands off instead of inheriting a fallback
 * address, and a form is complete only when its provider blocks are registered.
 * The seeded form is rendered through the Jetpack Forms render model to prove
 * the captured state reaches the logged-out page.
 *
 * Standalone smoke; see test-manifest.json.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once $wp_root . '/wp-includes/class-wp-block-parser.php';
require_once $wp_root . '/wp-includes/blocks.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result      = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-contract-controls.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$checks      = array(
	'fixture validates into the forms manifest' => empty( $validated['errors'] ) && ! empty( $validated['forms'][0]['controls'] ),
);
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$checks['form maps through the provider'] = 'mapped' === $row['status'] && true === ( $row['runtime_mapped'] ?? false );

// 1. Field contract: every captured control decided.
$controls  = $validated['forms'][0]['controls'];
$decisions = array();
foreach ( ( $row['field_contract'] ?? array() ) as $decision ) {
	$decisions[ $decision['index'] ?? -1 ] = $decision;
}
$checks['every control carries a decision'] = count( $controls ) === count( $decisions ) && array_keys( $decisions ) === range( 0, count( $controls ) - 1 );
$by_name = array();
foreach ( $controls as $index => $control ) {
	$by_name[ (string) ( $control['name'] ?? '' ) ] = $index;
}
$decides = static function ( string $name ) use ( $decisions, $by_name ): array {
	return $decisions[ $by_name[ $name ] ?? -1 ] ?? array();
};
$checks['role select maps to the provider select'] = 'mapped' === ( $decides( 'role' )['decision'] ?? '' ) && 'jetpack/field-select' === ( $decides( 'role' )['provider_block'] ?? '' );
$checks['desk select maps'] = 'mapped' === ( $decides( 'desk' )['decision'] ?? '' );
$checks['email maps to the provider email field'] = 'jetpack/field-email' === ( $decides( 'email' )['provider_block'] ?? '' );
$checks['independent checkboxes each map'] = 'jetpack/field-checkbox' === ( $decides( 'stack_frontend' )['provider_block'] ?? '' ) && 'jetpack/field-checkbox' === ( $decides( 'stack_backend' )['provider_block'] ?? '' ) && 'jetpack/field-checkbox' === ( $decides( 'stack_ops' )['provider_block'] ?? '' );
$checks['required consent checkbox maps'] = 'mapped' === ( $decides( 'consent' )['decision'] ?? '' );
$checks['file upload hands off naming the control'] = 'handoff' === ( $decides( 'portfolio' )['decision'] ?? '' ) && 'file_upload' === ( $decides( 'portfolio' )['capability'] ?? '' ) && 'portfolio' === ( $decides( 'portfolio' )['source']['name'] ?? '' );
$checks['color control hands off as an unrepresentable type'] = 'handoff' === ( $decides( 'accent' )['decision'] ?? '' ) && 'control_type:color' === ( $decides( 'accent' )['capability'] ?? '' );
$checks['multi-select hands off instead of downgrading to one select'] = 'handoff' === ( $decides( 'shifts' )['decision'] ?? '' ) && 'multiple_selection' === ( $decides( 'shifts' )['capability'] ?? '' );
$checks['submit control decides as the submit action'] = 'submit' === ( $decides( '' )['decision'] ?? '' ) && 'core/button' === ( $decides( '' )['provider_block'] ?? '' );

// The mapped select carries the source's own options, default, prompt and values.
$flatten = static function ( array $blocks ) use ( &$flatten ): array {
	$out = array();
	foreach ( $blocks as $block ) {
		$out[] = $block;
		$out   = array_merge( $out, $flatten( $block['innerBlocks'] ?? array() ) );
	}
	return $out;
};
$blocks = $flatten( parse_blocks( $row['block_markup'] ) );
$named  = static function ( string $block_name, string $needle ) use ( $blocks ): array {
	foreach ( $blocks as $block ) {
		if ( $block_name === ( $block['blockName'] ?? '' ) && str_contains( wp_json_encode( $block ), $needle ) ) {
			return $block;
		}
	}
	return array();
};
$role = $named( 'jetpack/field-select', 'Product manager' );
$role_input = array();
foreach ( $role['innerBlocks'] ?? array() as $inner ) {
	if ( 'jetpack/input' === ( $inner['blockName'] ?? '' ) ) {
		$role_input = $inner['attrs'] ?? array();
	}
}
$checks['select keeps source options and drops only the prompt'] = array( 'Engineer', 'Designer', 'Product manager' ) === ( $role['attrs']['options'] ?? array() );
$checks['source select default persists as defaultValue'] = 'Product manager' === ( $role['attrs']['defaultValue'] ?? '' );
$checks['source prompt persists as the field placeholder'] = 'Select your role' === ( $role_input['placeholder'] ?? '' );
$checks['select keeps source values through the choice token'] = str_contains( (string) ( $role['attrs']['className'] ?? '' ), 'ssi-choice-' );
$checks['required constraint carries onto the field'] = true === ( $role['attrs']['required'] ?? null );
$desk = $named( 'jetpack/field-select', 'Headquarters' );
$checks['select without a default resets to no default'] = ! isset( $desk['attrs']['defaultValue'] );
$frontend = $named( 'jetpack/field-checkbox', 'Frontend' );
$backend  = $named( 'jetpack/field-checkbox', 'Backend' );
$consent  = $named( 'jetpack/field-checkbox', 'I agree' );
$checks['checked checkbox default persists'] = true === ( $frontend['attrs']['defaultValue'] ?? null );
$checks['unchecked checkbox ships no invented default'] = ! isset( $backend['attrs']['defaultValue'] );
$checks['consent constraint carries onto the field'] = true === ( $consent['attrs']['required'] ?? null );
$checks['unrepresentable control is never downgraded to copy'] = ! str_contains( $row['block_markup'], 'ssi-unsupported-file-upload' ) && ! str_contains( $row['block_markup'], 'type=\'color\'' ) && ! str_contains( $row['block_markup'], 'type="color"' );

// Handoff items name what the owner must supply.
$handoffs = $row['handoff_items'] ?? array();
$control_handoffs = array_values( array_filter( $handoffs, static fn( array $item ): bool => 'control' === ( $item['kind'] ?? '' ) ) );
$recipient_handoffs = array_values( array_filter( $handoffs, static fn( array $item ): bool => 'recipient' === ( $item['kind'] ?? '' ) ) );
$checks['one handoff per unrepresentable control'] = 3 === count( $control_handoffs );
$checks['each control handoff names the captured control'] = array() === array_filter( $control_handoffs, static fn( array $item ): bool => '' === ( $item['name'] ?? '' ) || '' === ( $item['message'] ?? '' ) );

// 2. Recipient contract: no recovered recipient, no inherited fallback.
$checks['missing recipient is an explicit handoff'] = 'handoff' === ( $row['recipient']['status'] ?? '' ) && 'no_recovered_recipient' === ( $row['recipient']['reason_code'] ?? '' );
$checks['recipient handoff item exists'] = 1 === count( $recipient_handoffs ) && '' !== ( $recipient_handoffs[0]['message'] ?? '' );
$checks['provider block inherits no fallback recipient'] = ! preg_match( '/"to"\s*:/', $row['block_markup'] );

// 3. Runtime readiness: registered provider blocks and a logged-out render.
$readiness = $row['runtime_readiness'] ?? array();
$checks['form is ready with provider blocks registered'] = 'ready' === ( $readiness['status'] ?? '' ) && empty( $readiness['unregistered_block_types'] ) && ! empty( $readiness['required_block_types'] );
$checks['mapped form with recovered facts is complete'] = true === ( $row['complete'] ?? false );

// A form whose provider blocks this runtime does not register is not complete:
// the whole provider is unavailable, the row is held back, and the missing
// block types are named.
$partial_registry = array( 'jetpack/contact-form', 'jetpack/field-text', 'jetpack/field-email', 'jetpack/field-textarea' );
$GLOBALS['ssi_jetpack_registered_form_blocks'] = $partial_registry;
$held = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
unset( $GLOBALS['ssi_jetpack_registered_form_blocks'] );
$checks['unregistered provider blocks hold the form back'] = 'skipped' === ( $held['status'] ?? '' ) && 'missing_capability' === ( $held['runtime_readiness']['status'] ?? '' );
$checks['the unregistered block types are named'] = in_array( 'jetpack/field-select', (array) ( $held['runtime_readiness']['unregistered_block_types'] ?? array() ), true );
$checks['form without its provider blocks is not complete'] = false === ( $held['complete'] ?? true );

// Without the provider runtime at all, the form is held back with the gap named.
$GLOBALS['ssi_jetpack_form_blocks_available'] = false;
$unavailable = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$GLOBALS['ssi_jetpack_form_blocks_available'] = true;
$checks['unavailable provider holds the form back'] = 'skipped' === ( $unavailable['status'] ?? '' ) && 'provider_unavailable' === ( $unavailable['reason'] ?? '' ) && 'missing_capability' === ( $unavailable['runtime_readiness']['status'] ?? '' ) && false === ( $unavailable['complete'] ?? true );

// The seeded form renders logged out with its captured state intact.
$page = ssi_role_box_render_form( ssi_role_box_parse_blocks( $row['block_markup'] )[0] ?? array() );
$selected_options = array_values( array_filter( explode( "\n", $page ), static fn( string $line ): bool => str_contains( $line, 'selected=' ) ) );
$checks['logged-out render shows the source select default selected'] = 1 === count( $selected_options ) && str_contains( $selected_options[0] ?? '', 'Product manager' );
$checkbox_lines = array_values( array_filter( explode( "\n", $page ), static fn( string $line ): bool => 1 === preg_match( "/type=['\"]checkbox['\"]/", $line ) ) );
$checked_lines  = array_values( array_filter( $checkbox_lines, static fn( string $line ): bool => str_contains( $line, 'checked=' ) ) );
$required_boxes = array_values( array_filter( $checkbox_lines, static fn( string $line ): bool => str_contains( $line, 'required' ) ) );
$checks['logged-out render ticks only the checked checkbox'] = 4 === count( $checkbox_lines ) && 1 === count( $checked_lines ) && str_contains( $page, 'value="engineer"' ) && str_contains( $page, 'value="pm"' );
$checks['consent renders required'] = 1 === count( $required_boxes ) && str_contains( $page, '(required)' );
$checks['email renders required'] = 1 === preg_match( "/type='email'[^>]*required='true'/", $page );
$checks['handoff controls never render'] = ! str_contains( $page, "type='color'" ) && ! str_contains( $page, "type='file'" ) && ! preg_match( '/<select[^>]*\bmultiple/', $page );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
