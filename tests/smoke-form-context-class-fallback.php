<?php
/**
 * Regression coverage for form fallback context classes and like-with-like reconciliation.
 *
 * A producer form whose `context_before` carries a paragraph item with an author
 * class must keep that class through the fallback contract and onto the emitted
 * `core/paragraph`, and the source finding must still reconcile as
 * `resolved_by_provider` against the materialized provider binding: both sides
 * hash the same contract-normalized form, so a normalized field cannot by
 * itself leave a provider-materialized form unresolved.
 *
 * Run from the repository root:
 * php tests/smoke-form-context-class-fallback.php
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

if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( array $block ): string {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) && ! empty( $block['attrs'] ) ? ' ' . (string) wp_json_encode( $block['attrs'] ) : '';
		$inner = '';
		$index = 0;
		foreach ( $block['innerContent'] ?? array() as $piece ) {
			if ( null === $piece ) {
				$child  = $block['innerBlocks'][ $index ] ?? null;
				$inner .= is_array( $child ) ? serialize_block( $child ) : '';
				++$index;
				continue;
			}
			$inner .= (string) $piece;
		}
		if ( '' === $name ) {
			return $inner;
		}
		return '<!-- wp:' . $name . $attrs . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
	}
}

require_once ABSPATH . 'includes/class-static-site-importer-form-fallback-contract.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-diagnostic-projection.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';
require_once ABSPATH . 'includes/class-static-site-importer-form-field-markup.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$form_manifest = array(
	'class'          => 'newsletter',
	'action'         => '/subscribe',
	'method'         => 'post',
	'context_before' => array(
		array(
			'type'  => 'heading',
			'level' => 2,
			'text'  => 'Stay Connected with Us',
			'class' => 'font-serif text-2xl',
			'styles' => array(
				'font_size'   => '28px',
				'font_family' => 'Georgia, serif',
				'color'       => 'rgb(243, 242, 237)',
				'line_height' => '1.5',
				'font_weight' => 'unset',
			),
		),
		array(
			'type'  => 'paragraph',
			'text'  => 'Required fields are marked',
			'class' => 'form-note lead',
			'styles' => array(
				'font_size' => '15px',
				'color'     => '#f3f2ed',
				'font_family' => 'url(evil)',
			),
		),
	),
	'context_after'  => array(
		array(
			'type' => 'paragraph',
			'text' => 'Unsubscribe any time.',
		),
	),
);
$controls      = array(
	array(
		'tag'  => 'input',
		'type' => 'email',
		'name' => 'email',
	),
);

// The source finding carries the raw producer manifest, exactly as a form
// fallback diagnostic does when the provider maps the form.
$source_fallback = array(
	'type'        => 'unsupported_html_fallback',
	'code'        => 'html_form_fallback',
	'reason_code' => 'html_form_fallback',
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => $form_manifest,
	'controls'    => $controls,
);

// The provider side materializes the same producer manifest as an entity row.
$entity = array(
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => $form_manifest,
	'controls'    => $controls,
	'bindings'    => array(
		array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => 'index.html',
			'search_block_markup' => '<!-- wp:html --><form class="newsletter"></form><!-- /wp:html -->',
			'occurrence'          => 1,
			'role'                => 'form',
		),
	),
);
$prepared        = Static_Site_Importer_Entity_Materializer_Registry::prepare_form_entity( $entity );
$entity_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
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
$binding         = $entity_bindings[0] ?? array();

// Reconciliation compares like with like: the raw finding and the materialized
// binding hash the same contract-normalized representation.
$source_hash     = Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $source_fallback );
$source_identity = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $source_fallback );
$assert( '' !== ( $binding['fallback_hash'] ?? '' ) && $source_hash === $binding['fallback_hash'], 'raw-source-finding-and-provider-binding-hash-the-normalized-form', wp_json_encode( array( $source_hash, $binding['fallback_hash'] ?? '' ) ) );
$assert( $source_identity === ( $binding['fallback_reconciliation_identity'] ?? '' ), 'raw-source-finding-and-provider-binding-share-the-reconciliation-identity' );

// A completed receipt proves the persisted provider replacement, exactly as
// the runtime declaration receipts carry it.
$page_hash   = hash( 'sha256', '<!-- wp:group -->materialized page<!-- /wp:group -->' );
$receipt     = array(
	'schema'                           => 'static-site-importer/quality-resolution-receipt/v1',
	'status'                           => 'completed',
	'fallback_reconciliation_identity' => $binding['fallback_reconciliation_identity'],
	'source_path'                      => $binding['source_path'],
	'fallback_hash'                    => $binding['fallback_hash'],
	'binding_reconciliation_identity'  => $binding['reconciliation_identity'],
	'materialized_block_hash'          => $binding['materialized_block_hash'],
	'persisted_fragment_hash'          => $binding['materialized_block_hash'],
	'materialized_content_hash'        => $page_hash,
	'provider'                         => 'fixture-provider',
);
$report      = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 1 ),
		'diagnostics'             => array( $source_fallback ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages' => array(
					'index.html' => array( 'content_hash' => $page_hash ),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report, array( $receipt ) );
$resolution = $report['quality_resolutions']['resolutions'][0] ?? array();
$assert( 'resolved_by_provider' === ( $resolution['state'] ?? '' ), 'provider-materialized-form-with-paragraph-context-class-resolves', (string) ( $resolution['state'] ?? '' ) );
$assert( 0 === ( $report['quality']['fallback_count'] ?? -1 ) && 1 === ( $report['quality']['source_fallback_count'] ?? 0 ), 'resolved-form-fallback-leaves-the-quality-count' );
$assert( $source_hash === ( $resolution['fallback_hash'] ?? '' ), 'recorded-resolution-carries-the-normalized-fallback-hash' );

// The paragraph context class survives the contract and reaches the emitted
// block and its saved markup, mirroring the heading treatment.
$context_before = $prepared['form']['context_before'] ?? array();
$assert( 'form-note lead' === ( $context_before[1]['class'] ?? '' ), 'contract-keeps-validated-paragraph-context-class', wp_json_encode( $context_before[1] ?? null ) );
$prepared_form  = array( 'form' => $prepared['form'] );
$blocks         = Static_Site_Importer_Form_Field_Markup::context_blocks( $prepared_form, 'context_before' );
$heading_block  = $blocks[0] ?? array();
$paragraph      = $blocks[1] ?? array();
$assert( 'core/heading' === ( $heading_block['name'] ?? '' ) && 'font-serif text-2xl' === ( $heading_block['attrs']['className'] ?? '' ), 'heading-context-class-treatment-is-unchanged' );
$assert( 'core/paragraph' === ( $paragraph['name'] ?? '' ) && 'form-note lead' === ( $paragraph['attrs']['className'] ?? '' ), 'paragraph-context-class-is-emitted-as-className', wp_json_encode( $paragraph ) );
$paragraph_markup = trim( Static_Site_Importer_Form_Field_Markup::serialize_block( $paragraph ) );
$assert( 1 === preg_match( '#<p class="wp-block-paragraph form-note lead[^"]*"[^>]*>Required fields are marked</p>#', $paragraph_markup ), 'serialized-paragraph-carries-the-class-on-the-element', $paragraph_markup );
$plain = Static_Site_Importer_Form_Field_Markup::context_blocks( array( 'form' => array( 'context_after' => $prepared['form']['context_after'] ?? array() ) ), 'context_after' );
$plain_markup = isset( $plain[0] ) ? trim( Static_Site_Importer_Form_Field_Markup::serialize_block( $plain[0] ) ) : '';
$assert( str_contains( $plain_markup, '<p>Unsubscribe any time.</p>' ) && ! str_contains( $plain_markup, 'class=' ), 'classless-paragraph-markup-is-unchanged', $plain_markup );

// Resolved context typography reaches the emitted blocks as editable style
// attributes, and the saved element carries the matching inline declarations.
// Unsafe or CSS-wide values never travel.
$heading_style = $heading_block['attrs']['style'] ?? array();
$assert( '28px' === ( $heading_style['typography']['fontSize'] ?? '' ) && 'Georgia, serif' === ( $heading_style['typography']['fontFamily'] ?? '' ) && '1.5' === ( $heading_style['typography']['lineHeight'] ?? '' ) && 'rgb(243, 242, 237)' === ( $heading_style['color']['text'] ?? '' ), 'heading-context-typography-becomes-block-style-attributes', wp_json_encode( $heading_style ) );
$assert( ! isset( $heading_style['typography']['fontWeight'] ), 'css-wide-context-typography-value-is-dropped', wp_json_encode( $heading_style ) );
$heading_markup = trim( Static_Site_Importer_Form_Field_Markup::serialize_block( $heading_block ) );
$assert( str_contains( $heading_markup, 'font-size:28px' ) && str_contains( $heading_markup, 'color:rgb(243, 242, 237)' ) && str_contains( $heading_markup, 'has-text-color' ), 'saved-heading-carries-the-inline-typography', $heading_markup );
$paragraph_style = $paragraph['attrs']['style'] ?? array();
$assert( '15px' === ( $paragraph_style['typography']['fontSize'] ?? '' ) && '#f3f2ed' === ( $paragraph_style['color']['text'] ?? '' ) && ! isset( $paragraph_style['typography']['fontFamily'] ), 'paragraph-context-typography-keeps-safe-values-only', wp_json_encode( $paragraph_style ) );
$assert( str_contains( $paragraph_markup, 'font-size:15px' ) && ! str_contains( $paragraph_markup, 'url(' ), 'saved-paragraph-carries-safe-inline-typography', $paragraph_markup );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: form context class fallback smoke passed (' . $assertions . " assertions)\n";
