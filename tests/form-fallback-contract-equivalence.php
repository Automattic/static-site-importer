<?php
/**
 * Emit form fallback contract projections for baseline/candidate comparison.
 *
 * Run from the repository root:
 * php tests/form-fallback-contract-equivalence.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone test shim supplies the unavailable WordPress encoder.
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$entity       = array(
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => array(
		'class'               => 'newsletter primary',
		'action'              => '/subscribe',
		'method'              => 'post',
		'context_before'      => array(
			array(
				'type'  => 'heading',
				'level' => 2,
				'text'  => 'Updates',
			),
			array(
				'type' => 'paragraph',
				'text' => 'Required fields',
			),
		),
		'context_after'       => array(
			array(
				'type' => 'paragraph',
				'text' => 'Unsubscribe any time.',
			),
		),
		'interleaved_context' => true,
		'submit_presentation' => array(
			'text'    => 'Subscribe',
			'classes' => array( 'button', 'primary' ),
		),
	),
	'controls'    => array(
		array(
			'tag'      => 'input',
			'type'     => 'text',
			'name'     => 'email',
			'label'    => 'Email address',
			'required' => true,
		),
		array(
			'tag'    => 'textarea',
			'type'   => 'textarea',
			'name'   => 'message',
			'height' => '12rem',
		),
		array(
			'tag'  => 'input',
			'type' => 'submit',
		),
	),
	'bindings'    => array(
		array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => 'index.html',
			'search_block_markup' => '<!-- wp:html --><form class="newsletter primary"></form><!-- /wp:html -->',
			'occurrence'          => 1,
			'role'                => 'form',
		),
	),
);
$manifest     = Static_Site_Importer_Form_Fallback_Contract::manifest_from_metadata( $entity );
$presentation = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata( $entity, 'form.newsletter', 1 );
$analysis     = Static_Site_Importer_Form_Fallback_Contract::analysis_from_metadata( $entity, 'form.newsletter', 1 );
if ( $manifest !== $analysis['manifest'] || $presentation !== $analysis['presentation'] ) {
	throw new RuntimeException( 'Expected combined analysis to preserve the metadata helper outputs.' );
}
$fallback = array(
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => $manifest['form'],
	'controls'    => $manifest['controls'],
);
$prepared = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $entity );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
	array(
		'entities' => array(
			'forms' => array(
				'adapter'  => array(
					'provider'          => 'fixture-provider',
					'entity_collection' => 'forms',
					'binding_callback'  => static fn(): string => '<!-- wp:fixture/form -->form<!-- /wp:fixture/form -->',
				),
				'manifest' => array( 'forms' => array( $prepared ) ),
			),
		),
	),
	array(
		'forms' => array(
			'forms' => array(
				array(
					'source_path' => 'index.html',
					'selector'    => 'form.newsletter',
					'status'      => 'created',
				),
			),
		),
	)
);
if ( ! is_array( $bindings ) || empty( $bindings ) ) {
	throw new RuntimeException( 'Expected the fixture form binding projection.' );
}

$projection = array(
	'manifest'     => $manifest,
	'presentation' => $presentation,
	'identity'     => Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback ),
	'hash'         => Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $fallback ),
	'prepared'     => $prepared,
	'bindings'     => $bindings,
	'diagnostic'   => Static_Site_Importer_Report_Diagnostics::fallback_diagnostic_entry( 'core_html_block', 'index.html', '<form class="newsletter"></form>', array(
		'reason'   => 'fixture',
		'form'     => $manifest['form'],
		'controls' => $manifest['controls'],
	), array() ),
);

echo wp_json_encode( $projection ) . "\n";
