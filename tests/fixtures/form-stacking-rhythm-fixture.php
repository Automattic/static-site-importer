<?php
/**
 * Emit a flattened equal-width row inside a sibling-stacked form (#2159).
 *
 * Usage: php tests/fixtures/form-stacking-rhythm-fixture.php [row-gap]
 * The optional argument replaces the source row's own gap so the stacking
 * margin and the row gap can disagree.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols across namespaces outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;
	$row_gap    = isset( $argv[1] ) && 1 === preg_match( '/^[0-9.]+rem$/D', $argv[1] ) ? $argv[1] : '1.25rem';
	$source_css = '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}'
		. '.grid{display:grid;gap:' . $row_gap . '}'
		. '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. 'label{display:block}input,textarea{display:block;width:100%}';
	$form_html  = '<form class="space-y-5">'
		. '<div class="grid sm:grid-cols-2"><div><label for="n">Name</label><input id="n"></div><div><label for="e">Email</label><input id="e" type="email"></div></div>'
		. '<div><label for="m">Message</label><textarea id="m"></textarea></div>'
		. '<button type="submit">Send</button>'
		. '</form>';
	$html       = '<style>' . $source_css . '</style>' . $form_html;
	$compiled   = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated  = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup     = (string) ( $row['block_markup'] ?? '' );
	$fields     = array();
	if ( preg_match_all( '/<!-- wp:jetpack\/field-(text|email|textarea) (\{[^\n]*?\}) -->\s*<div><!-- wp:jetpack\/label (\{[^\n]*?\}) \/?-->/', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$attrs   = json_decode( $match[2], true );
			$label   = json_decode( $match[3], true );
			$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
			$classes = false === $classes ? array() : array_map( static fn ( string $class_name ): string => $class_name . '-wrap', array_filter( $classes ) );
			$fields[] = array(
				'type'         => $match[1],
				'label'        => (string) ( $label['label'] ?? '' ),
				'labelClasses' => (string) ( $label['className'] ?? '' ),
				'classes'      => implode( ' ', $classes ),
			);
		}
	}
	$submit_class = '';
	if ( preg_match( '/<!-- wp:(?:core\/)?button (\{[^\n]*?\}) -->/', $markup, $button ) ) {
		$button_attrs = json_decode( $button[1], true );
		$submit_class = (string) ( $button_attrs['className'] ?? '' );
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$form_attrs = json_decode( $form[1], true );
		$form_class = (string) ( $form_attrs['className'] ?? '' );
	}
	echo (string) wp_json_encode(
		array(
			'status'      => (string) ( $row['status'] ?? '' ),
			'css'         => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'sourceCss'   => $source_css,
			'sourceHtml'  => $form_html,
			'className'   => $form_class,
			'fields'      => $fields,
			'runtimeHtml' => Static_Site_Importer_Form_Seeder::project_provider_submit_presentation(
				'<div class="wp-block-button ' . htmlspecialchars( $submit_class, ENT_QUOTES, 'UTF-8' ) . '"><button type="submit" class="wp-block-button__link">Send</button></div>',
				array( 'attrs' => array( 'className' => $submit_class ) )
			),
			'strategies'  => array_column( $row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ),
		)
	);
}
