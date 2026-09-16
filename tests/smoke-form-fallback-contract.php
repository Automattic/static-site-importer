<?php
/**
 * Focused coverage for form fallback normalization and reconciliation facts.
 *
 * Run from the repository root:
 * php tests/smoke-form-fallback-contract.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-fallback-contract.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$controls = array(
	array(
		'tag'      => 'input',
		'type'     => 'text',
		'name'     => 'email',
		'label'    => 'Email address',
		'required' => true,
	),
);
for ( $index = 0; $index <= 16; ++$index ) {
	$controls[] = array(
		'tag'    => 'textarea',
		'type'   => 'textarea',
		'name'   => 'message-' . $index,
		'height' => ( $index + 1 ) . 'rem',
	);
}
$controls[] = array(
	'tag'  => 'input',
	'type' => 'submit',
);
$metadata   = array(
	'form'     => array(
		'class'                         => 'newsletter primary',
		'action'                        => '/subscribe',
		'method'                        => 'post',
		'context_before'                => array(
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
		'context_after'                 => array(
			array(
				'type' => 'paragraph',
				'text' => 'Unsubscribe any time.',
			),
		),
		'interleaved_context'           => true,
		'submit_presentation'           => array(
			'text'    => 'Subscribe',
			'classes' => array( 'button', 'primary' ),
		),
		'textarea_height_omitted_count' => 1,
	),
	'controls' => $controls,
);

$manifest = Static_Site_Importer_Form_Fallback_Contract::manifest_from_metadata( $metadata );
$assert( array( 'class' => 'newsletter primary', 'action' => '/subscribe', 'method' => 'post' ) === $manifest['form'], 'manifest-retains-provider-neutral-form-attributes' );
$assert( 'text' === ( $manifest['controls'][0]['type'] ?? '' ) && 'Email address' === ( $manifest['controls'][0]['label'] ?? '' ) && true === ( $manifest['controls'][0]['required'] ?? false ), 'manifest-normalizes-input-defaults-and-accessibility' );
$assert( 19 === count( $manifest['controls'] ) && 'submit' === ( $manifest['controls'][18]['type'] ?? '' ), 'manifest-retains-control-order-and-submit' );

$presentation = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata( $metadata, 'form.newsletter', 3 );
$assert( 'generic/form-presentation/v1' === ( $presentation['schema'] ?? '' ) && 'form.newsletter' === ( $presentation['selector'] ?? '' ) && 3 === ( $presentation['document_ordinal'] ?? 0 ), 'presentation-identifies-the-original-form' );
$assert( 'Updates' === ( $presentation['context_before'][0]['text'] ?? '' ) && 'Required fields' === ( $presentation['context_before'][1]['text'] ?? '' ) && 'Unsubscribe any time.' === ( $presentation['context_after'][0]['text'] ?? '' ), 'presentation-keeps-bounded-before-and-after-context' );
$assert( true === ( $presentation['interleaved_context'] ?? false ) && 'Subscribe' === ( $presentation['submit_presentation']['text'] ?? '' ) && array( 'button', 'primary' ) === ( $presentation['submit_presentation']['classes'] ?? array() ), 'presentation-detects-interleaving-and-prefers-visible-submit-treatment' );
$assert( 16 === count( $presentation['textarea_heights'] ?? array() ) && '1rem' === ( $presentation['textarea_heights'][1] ?? '' ) && 1 === ( $presentation['textarea_height_omitted_count'] ?? 0 ), 'presentation-bounds-textarea-heights-without-changing-control-order' );
$assert( ! isset( $presentation['submit_presentation']['label_classes'] ), 'submit-treatment-without-a-label-element-reports-no-label-classes' );
$labelled_submit = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata(
	array(
		'form'     => array(
			'class'               => 'labelled',
			'action'              => '/subscribe',
			'method'              => 'post',
			'submit_presentation' => array(
				'text'          => 'Send',
				'classes'       => array( 'cta' ),
				'label_classes' => array( 'cta-label', 'typography-small' ),
			),
		),
		'controls' => array(
			array(
				'tag'  => 'input',
				'type' => 'text',
				'name' => 'email',
			),
			array(
				'tag'  => 'button',
				'type' => 'submit',
			),
		),
	),
	'form.labelled',
	1
);
$assert( 'Send' === ( $labelled_submit['submit_presentation']['text'] ?? '' ) && array( 'cta-label', 'typography-small' ) === ( $labelled_submit['submit_presentation']['label_classes'] ?? array() ), 'submit-label-element-classes-are-reported-for-the-materialized-button' );
$mixed_submit = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata(
	array(
		'form'     => array(
			'class'               => 'mixed',
			'action'              => '/subscribe',
			'method'              => 'post',
			'submit_presentation' => array(
				'text'    => 'Send now',
				'classes' => array( 'cta' ),
			),
		),
		'controls' => array(
			array(
				'tag'  => 'input',
				'type' => 'text',
				'name' => 'email',
			),
			array(
				'tag'  => 'button',
				'type' => 'submit',
			),
		),
	),
	'form.mixed',
	1
);
$assert( ! isset( $mixed_submit['submit_presentation']['label_classes'] ), 'submit-text-outside-a-single-label-element-reports-no-label-classes' );

$fallback = array(
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => array( 'class' => 'newsletter' ),
	'controls'    => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
);
$reordered = array(
	'selector'    => 'form.newsletter',
	'source_path' => 'index.html',
	'controls'    => array( array( 'name' => 'email', 'type' => 'email', 'tag' => 'input' ) ),
	'form'        => array( 'class' => 'newsletter' ),
);
$assert( Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $fallback ) === Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $reordered ) && Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback ) === Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $reordered ), 'reconciliation-is-stable-across-associative-key-order' );
$source_identity = hash( 'sha256', 'blocks-engine-identity' );
$assert( $source_identity === Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( array( 'source_fallback_identity' => $source_identity ) ), 'reconciliation-preserves-producer-identity' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: form fallback contract smoke passed (' . $assertions . " assertions)\n";
