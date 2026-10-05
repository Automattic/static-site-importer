<?php
/**
 * Seed the committed v3 producer entity and emit what the browser matrix renders:
 * the overlay/context CSS, the form scope class, the saved markup of the context
 * blocks, and the provider submit shell carrying its source wrapper hook.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';

	$fixture   = json_decode( (string) file_get_contents( __DIR__ . '/form-source-boxes-v3.json' ), true );
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fixture['complete'] ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup    = (string) ( $row['block_markup'] ?? '' );
	$form_attrs = preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ? json_decode( $form[1], true ) : array();
	$submit_attrs = preg_match( '/<!-- wp:(?:core\/)?button (\{.*?\}) -->/', $markup, $button ) ? json_decode( $button[1], true ) : array();
	$submit_class = (string) ( $submit_attrs['className'] ?? '' );
	// Saved core block markup is the element tree Core renders on the frontend;
	// drop the block delimiters and keep the context blocks in source order.
	$inner = preg_replace( '/^.*?<div class="wp-block-jetpack-contact-form[^"]*">|<\/div>\s*<!-- \/wp:jetpack\/contact-form -->\s*$/s', '', $markup );
	$before = strstr( (string) $inner, '<!-- wp:jetpack/', true );
	$tail   = strrpos( (string) $inner, '<!-- /wp:core/button -->' );
	$after  = false === $tail ? '' : substr( (string) $inner, $tail + strlen( '<!-- /wp:core/button -->' ) );
	$strip  = static fn( string $html ): string => trim( (string) preg_replace( '/<!-- \/?wp:[^>]*-->/', '', $html ) );
	echo (string) wp_json_encode(
		array(
			'status'      => (string) ( $row['status'] ?? '' ),
			'className'   => (string) ( $form_attrs['className'] ?? '' ),
			'css'         => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'contextCss'  => (string) ( $row['provider_layout_overlay_css']['context_css'] ?? '' ),
			'editorContextCss' => (string) ( $row['provider_layout_overlay_css']['editor_context_css'] ?? '' ),
			'sourceCss'   => (string) $fixture['source_css'],
			'beforeHtml'  => $strip( false === $before ? '' : $before ),
			'afterHtml'   => $strip( (string) $after ),
			'submitHtml'  => Static_Site_Importer_Form_Seeder::project_provider_submit_presentation(
				'<div class="wp-block-button ' . htmlspecialchars( $submit_class, ENT_QUOTES, 'UTF-8' ) . '"><button type="submit" class="wp-block-button__link">Send</button></div>',
				array( 'attrs' => array( 'className' => $submit_class ) )
			),
			'blockMarkup' => $markup,
		)
	);
}
