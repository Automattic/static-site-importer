<?php
/** Large choice lists use durable references, not oversized class names. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$options = array( array( 'label' => 'Afghanistan', 'value' => 'AF', 'selected' => false ) );
for ( $i = 1; $i < 245; ++$i ) {
	$options[] = array( 'label' => 'Choice ' . $i, 'value' => 'code-' . $i, 'selected' => 100 === $i );
}
$seed = static function ( array $options ): array {
	$html = '<!doctype html><html><head><title>Contact</title></head><body><form action="/contact" method="post"><label for="email">Email</label><input id="email" name="email" type="email"><label for="choices">Choices</label><select id="choices" name="choices">';
	foreach ( $options as $option ) {
		$html .= '<option value="' . htmlspecialchars( $option['value'] ) . '"' . ( $option['selected'] ? ' selected' : '' ) . '>' . htmlspecialchars( $option['label'] ) . '</option>';
	}
	$html .= '</select><button type="submit">Send</button></form></body></html>';
	$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
	$plan = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	return Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
};
$token = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options );
$row = $seed( $options );
$checks = array(
	'245 choices get a short stable reference' => 1 === preg_match( '/^ssi-choice-ref-[a-f0-9]{64}$/D', $token ) && $token === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options ),
	'large select maps without choice_values loss' => 'mapped' === $row['status'] && ! str_contains( json_encode( $row['receipt'] ?? $row ), 'choice_values' ),
);
// Core serialization preserves the registered className transport across saves.
$markup = serialize_blocks( parse_blocks( $row['block_markup'] ) );
$checks['reference survives block save'] = str_contains( $markup, $token );
$html = '<div class="' . $token . '-wrap"><select>';
foreach ( $options as $option ) {
	$html .= '<option value="provider" selected="selected">' . htmlspecialchars( $option['label'] ) . '</option>';
}
$html .= '</select></div>';
$projected = Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( $html );
$checks['reference projects native values'] = str_contains( $projected, '<option value="AF">Afghanistan</option>' );
$checks['reference preserves only source selected state'] = 1 === substr_count( $projected, 'selected="selected"' ) && str_contains( $projected, 'value="code-100" selected="selected">Choice 100</option>' );
foreach ( array( 'duplicate', 'two-selected' ) as $case ) {
	$invalid = $options;
	if ( 'duplicate' === $case ) {
		$invalid[1]['label'] = $invalid[0]['label'];
	} else {
		$invalid[0]['selected'] = true;
	}
	$declined = $seed( $invalid );
	$checks[ $case . ' declines choice preservation' ] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $invalid ) && str_contains( json_encode( $declined ), 'choice_values' );
}
$inline = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( array_slice( $options, 0, 2 ) );
$checks['small inline lists remain compatible'] = ! str_starts_with( $inline, 'ssi-choice-ref-' ) && str_contains( Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( '<div class="' . $inline . '-wrap"><select><option>Afghanistan</option><option>Choice 1</option></select></div>' ), '<option value="AF">Afghanistan</option>' );
$bounded = $options;
for ( $i = 245; $i < 300; ++$i ) {
	$bounded[] = array( 'label' => 'Choice ' . $i, 'value' => 'code-' . $i, 'selected' => false );
}
$checks['at least 300 choices are supported'] = '' !== Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $bounded );
$checks['option count remains bounded'] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( array_fill( 0, 501, $options[0] ) );
$too_long = $options;
$too_long[0]['value'] = str_repeat( 'x', 201 );
$checks['per-option length remains bounded'] = '' === Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $too_long );
$key = 'ssi_choice_' . substr( $token, strlen( 'ssi-choice-ref-' ) );
$stored = get_option( $key );
$checks['durable data is stored once as validated triples'] = count( $stored ) === 245 && $stored[0] === array( 'Afghanistan', 'AF', false );
update_option( $key, array() );
$checks['missing or corrupt references leave provider HTML untouched'] = $html === Static_Site_Importer_Provider_Form_Runtime_V1::project_choice_values( $html );
update_option( $key, $stored );

// Radios sharing a name are one provider radio field with one option per
// input, named by the group's label: its fieldset legend, its ARIA group
// name, or a preceding label that wraps no control.
$radio_groups = array(
	'fieldset legend'       => '<fieldset><legend>Pick an area</legend>%s</fieldset>',
	'aria group name'       => '<div role="radiogroup" aria-label="Pick an area">%s</div>',
	'plain preceding label' => '<div><label>Pick an area</label>%s</div>',
	'single radio declined' => '<div role="radiogroup" aria-label="Pick an area">%s</div>',
	'bootstrap reused ids'  => '<div class="col"><label class="form-label">Tell us about yourself</label><div id="who">%s</div></div><div class="col"><label class="form-label">How did you hear about us</label><div id="heard">%s</div></div>',
	'bootstrap six options' => '<div><label>Tell us about yourself</label><div id="g">%s</div></div>',
);
foreach ( $radio_groups as $case => $group ) {
	$radios = '<label><input type="radio" name="area" value="north"> North</label><label><input type="radio" name="area" value="south"> South</label>';
	if ( 'single radio declined' === $case ) {
		$radios = '<label><input type="radio" name="area" value="north"> North</label>';
	}
	$expected_options = array( 'North', 'South' );
	$expected_label = 'Pick an area';
	if ( 'bootstrap six options' === $case ) {
		$expected_options = array( 'Publisher', 'Author', 'Corporate', 'Editor', 'Agent', 'Other' );
		$expected_label = 'Tell us about yourself';
		$radios = '';
		foreach ( $expected_options as $i => $label ) {
			$radios .= '<div class="form-check"><input type="radio" name="who" id="o' . $i . '" value="' . $label . '"><label for="o' . $i . '">' . $label . '</label></div>';
		}
	}
	if ( 'bootstrap reused ids' === $case ) {
		$expected_options = array( 'Publisher', 'Author', 'Other' );
		$radios = '';
		foreach ( $expected_options as $label ) {
			$radios .= '<div class="form-check"><input class="form-check-input" type="radio" name="who" id="' . strtolower( $label ) . '" value="' . $label . '" required><label class="form-check-label" for="' . strtolower( $label ) . '">' . $label . '</label></div>';
		}
		$group = sprintf( $group, $radios, str_replace( 'name="who"', 'name="heard"', $radios ) );
	}
	$html   = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Radio group</title></head><body><main><form action="/submit" method="post"><div><label for="email">Email</label><input type="email" id="email" name="email"></div>' . sprintf( $group, $radios ) . '<button type="submit">Send</button></form></main></body></html>';
	$plan   = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
	$decl   = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$radio  = Static_Site_Importer_Form_Seeder::seed( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $decl['payload']['entities'], $plan ) ) ) )['forms'][0];
	$find   = static function ( array $blocks ) use ( &$find ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( 'jetpack/field-radio' === ( $block['blockName'] ?? null ) ) {
				$found[] = $block;
			}
			$found = array_merge( $found, $find( $block['innerBlocks'] ?? array() ) );
		}
		return $found;
	};
	$fields = $find( parse_blocks( (string) $radio['block_markup'] ) );
	if ( 'single radio declined' === $case ) {
		$checks['declined radio never emits an option-less field'] = array() === $fields;
		$checks['declined radio reports a named loss'] = str_contains( json_encode( $radio ), 'radio_group_without_options' );
		continue;
	}
	if ( 'bootstrap reused ids' === $case ) {
		$checks[ $case . ' maps both groups' ] = 'mapped' === $radio['status'] && 2 === count( $fields );
		foreach ( array( 'Tell us about yourself', 'How did you hear about us' ) as $index => $label ) {
			$checks[ $case . ' caption ' . $index ] = array( 'label' => $label, 'requiredIndicator' => false, 'className' => 'form-label' ) === ( $fields[ $index ]['innerBlocks'][0]['attrs'] ?? null );
			$checks[ $case . ' options ' . $index ] = $expected_options === ( $fields[ $index ]['attrs']['options'] ?? null ) && $expected_options === array_column( array_column( $fields[ $index ]['innerBlocks'][1]['innerBlocks'] ?? array(), 'attrs' ), 'label' );
		}
		$copy = array();
		$collect = static function ( array $blocks ) use ( &$collect, &$copy ): void {
			foreach ( $blocks as $block ) {
				if ( empty( $block['innerBlocks'] ) ) {
					$text = trim( html_entity_decode( strip_tags( $block['innerHTML'] ?? '' ) ) );
					$text = '' !== $text ? $text : ( $block['attrs']['label'] ?? '' );
					$copy[] = $text;
				}
				$collect( $block['innerBlocks'] ?? array() );
			}
		};
		$collect( parse_blocks( (string) $radio['block_markup'] ) );
		$counts = array_count_values( $copy );
		$checks[ $case . ' visible captions once' ] = 1 === ( $counts['Tell us about yourself'] ?? 0 ) && 1 === ( $counts['How did you hear about us'] ?? 0 );
		$checks[ $case . ' consumes every local option label' ] = 2 === ( $counts['Other'] ?? 0 ) && ! str_contains( $radio['block_markup'], 'wp:custom/authored-label' );
		continue;
	}
	$checks[ $case . ' radio group maps to one radio field' ] = 'mapped' === $radio['status'] && 1 === count( $fields );
	$checks[ $case . ' radio field carries one option per input' ] = $expected_options === ( $fields[0]['attrs']['options'] ?? null ) && $expected_options === array_map( static fn( array $option ): string => (string) ( $option['attrs']['label'] ?? '' ), $fields[0]['innerBlocks'][1]['innerBlocks'] ?? array() );
	$checks[ $case . ' radio options carry the saved block class' ] = "\n<ul class=\"wp-block-jetpack-options\">" === ( $fields[0]['innerBlocks'][1]['innerContent'][0] ?? null );
	$checks[ $case . ' radio field is named by the group label' ] = array( 'label' => $expected_label ) === ( $fields[0]['innerBlocks'][0]['attrs'] ?? null );
}

// Options save through useBlockProps.save(), unlike other div/ul wrappers.
foreach ( array( 'ul', 'div' ) as $wrapper ) {
	foreach ( array( array(), array( 'className' => '' ), array( 'className' => '  custom-choice  ' ), array( 'className' => 'choice&more' ) ) as $attrs ) {
		$custom = trim( $attrs['className'] ?? '' );
		foreach ( array( 'jetpack/options', 'jetpack/other' ) as $name ) {
			$block = Static_Site_Importer_Form_Field_Markup::parsed_block( array( 'name' => $name, 'attrs' => $attrs, 'wrapper' => $wrapper ) );
			$class = 'jetpack/options' === $name ? ' class="wp-block-jetpack-options' . ( '' !== $custom ? ' ' . htmlspecialchars( $custom, ENT_QUOTES, 'UTF-8' ) : '' ) . '"' : '';
			$checks[ $name . ' ' . $wrapper . ' saved wrapper ' . json_encode( $attrs ) ] = "\n<" . $wrapper . $class . '>' === $block['innerContent'][0] && '</' . $wrapper . ">\n" === $block['innerContent'][1] && $attrs === $block['attrs'];
		}
	}
}

// A donation-intent form materializes as the Jetpack Donations block and its
// form fallback resolves through the provider binding receipt.
foreach ( array( 'import-report', 'diagnostic-projection', 'quality-gates' ) as $class_file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $class_file . '.php';
}
$donation_html = file_get_contents( __DIR__ . '/fixtures/donation-form/index.html' );
$donation_plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $donation_html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$donation_fallbacks  = array_values( array_filter( $donation_plan['quality']['fallbacks'] ?? array(), static fn( array $row ): bool => 'html_form_fallback' === ( $row['diagnostic_code'] ?? '' ) ) );
$donation_decl       = current( array_filter( $donation_plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$donation_entities   = array_map( array( Static_Site_Importer_Entity_Materializer_Registry::class, 'prepare_form_entity' ), Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $donation_decl['payload']['entities'] ?? array(), $donation_plan ) );
$donation_validated  = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $donation_entities ) );
$donation_report     = Static_Site_Importer_Form_Seeder::seed( $donation_validated );
$donation_row        = $donation_report['forms'][0] ?? array();
$donation_markup     = (string) ( $donation_row['block_markup'] ?? '' );
$donation_block      = parse_blocks( $donation_markup )[0] ?? array();
$checks['producer classifies the form as donation'] = 1 === count( $donation_fallbacks ) && 'donation' === ( $donation_fallbacks[0]['form_intent'] ?? '' );
$checks['donation form maps to jetpack/donations'] = 'mapped' === ( $donation_row['status'] ?? '' ) && str_starts_with( $donation_markup, '<!-- wp:jetpack/donations ' ) && 'jetpack/donations' === ( $donation_block['blockName'] ?? '' ) && $donation_markup === serialize_blocks( parse_blocks( $donation_markup ) );
$checks['donation block carries both amounts and custom amount'] = array( 50, 100 ) === ( $donation_block['attrs']['oneTimeDonation']['amounts'] ?? null ) && true === ( $donation_block['attrs']['showCustomAmount'] ?? null ) && 'USD' === ( $donation_block['attrs']['currency'] ?? '' );
$checks['donation block shows one-time and monthly only'] = true === ( $donation_block['attrs']['monthlyDonation']['show'] ?? null ) && false === ( $donation_block['attrs']['annualDonation']['show'] ?? null ) && 'Donate' === ( $donation_block['attrs']['oneTimeDonation']['buttonText'] ?? '' );
$checks['fund select is a diagnostic, not a field'] = in_array( 'fund', array_column( $donation_row['diagnostics'] ?? array(), 'name' ), true ) && ! str_contains( $donation_markup, 'jetpack/contact-form' );
// Jetpack keeps the block out of the editor on an offline-mode site, so the
// mapped block is not complete there even though it registers.
$GLOBALS['ssi_jetpack_registered_form_blocks'][] = 'jetpack/donations';
$online_row = Static_Site_Importer_Form_Seeder::seed( $donation_validated )['forms'][0] ?? array();
eval( 'namespace Automattic\\Jetpack; class Status { public function is_offline_mode(): bool { return true; } }' );
$offline_row = Static_Site_Importer_Form_Seeder::seed( $donation_validated )['forms'][0] ?? array();
$checks['offline Jetpack editor leaves the donation block incomplete'] = true === ( $online_row['complete'] ?? false ) && 'mapped' === ( $offline_row['status'] ?? '' ) && 'provider_editor_unavailable' === ( $offline_row['runtime_readiness']['reason_code'] ?? '' ) && false === ( $offline_row['complete'] ?? true );
array_pop( $GLOBALS['ssi_jetpack_registered_form_blocks'] );
$donation_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
	array( 'entities' => array( 'forms' => array( 'adapter' => Static_Site_Importer_Form_Seeder::adapter(), 'manifest' => $donation_validated ) ) ),
	array( 'forms' => $donation_report )
);
$donation_binding = $donation_bindings[0] ?? array();
// Persist the binding into the compiled page and record its proof, as the import does.
$donation_page    = current( array_filter( $donation_plan['pages'] ?? array(), static fn( array $page ): bool => str_contains( (string) ( $page['canonical_block_markup'] ?? '' ), (string) ( $donation_binding['search_block_markup'] ?? "\0" ) ) ) );
$donation_content = str_replace( (string) ( $donation_binding['search_block_markup'] ?? '' ), $donation_markup, (string) ( $donation_page['canonical_block_markup'] ?? '' ) );
$donation_gate    = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 1 ),
		'diagnostics'             => $donation_fallbacks,
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array( 'index.html' => array( 'content_hash' => hash( 'sha256', $donation_content ) ) ),
				'runtime_declarations' => array(
					'entity_bindings' => array(
						array_merge(
							$donation_binding,
							array(
								'status'                    => 'completed',
								'persisted_fragment_hash'   => hash( 'sha256', $donation_markup ),
								'materialized_content_hash' => hash( 'sha256', $donation_content ),
							)
						),
					),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $donation_gate );
$checks['donation binding replaces the fallback with the block'] = $donation_markup === ( $donation_binding['replacement_block_markup'] ?? null ) && str_contains( $donation_content, $donation_markup ) && ! str_contains( $donation_content, '<form' );
$checks['no html_form_fallback remains unresolved'] = 0 === ( $donation_gate['quality']['fallback_count'] ?? -1 ) && 'resolved_by_provider' === ( $donation_gate['quality_resolutions']['resolutions'][0]['state'] ?? '' );
$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
