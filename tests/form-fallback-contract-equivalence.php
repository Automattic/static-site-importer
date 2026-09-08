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
		return json_encode( $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$contract = class_exists( 'Static_Site_Importer_Form_Fallback_Contract' )
	? Static_Site_Importer_Form_Fallback_Contract::class
	: Static_Site_Importer_Report_Diagnostics::class;
$html     = '<form class="newsletter primary" action="/subscribe" method="post"><h2>Updates</h2><label class="required-note">Required fields</label><input name="email" aria-label="Email address" required><p class="help">We only send useful mail.</p><textarea name="message" style="height: 12rem"></textarea><input type="submit" value="Subscribe" style="display: none"><a class="button primary invalid!" href="#subscribe">Subscribe</a><p class="help">Unsubscribe any time.</p></form>';
$manifest = call_user_func( array( $contract, str_contains( $contract, 'Form_Fallback_Contract' ) ? 'manifest_from_html' : 'form_manifest_from_html' ), $html );
$fallback = array(
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => $manifest['form'],
	'controls'    => $manifest['controls'],
);
$entity = $fallback;
$entity['bindings'] = array(
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'index.html',
		'search_block_markup' => $html,
		'occurrence'          => 1,
		'role'                => 'form',
	),
);
$prepared = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $entity );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
	array(
		'entities' => array(
			'forms' => array(
				'adapter'  => array(
					'provider'         => 'fixture-provider',
					'binding_callback' => static fn(): string => '<!-- wp:fixture/form -->form<!-- /wp:fixture/form -->',
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

$projection = array(
	'manifest'     => $manifest,
	'presentation' => call_user_func( array( $contract, str_contains( $contract, 'Form_Fallback_Contract' ) ? 'presentation_from_html' : 'form_presentation_from_html' ), $html, 'form.newsletter', 1 ),
	'identity'     => call_user_func( array( $contract, str_contains( $contract, 'Form_Fallback_Contract' ) ? 'reconciliation_identity' : 'fallback_reconciliation_identity' ), $fallback ),
	'hash'         => call_user_func( array( $contract, str_contains( $contract, 'Form_Fallback_Contract' ) ? 'reconciliation_hash' : 'fallback_reconciliation_hash' ), $fallback ),
	'prepared'     => $prepared,
	'bindings'     => $bindings,
	'diagnostic'   => Static_Site_Importer_Report_Diagnostics::fallback_diagnostic_entry( 'core_html_block', 'index.html', $html, array( 'reason' => 'fixture' ), array() ),
);

echo wp_json_encode( $projection ) . "\n";
