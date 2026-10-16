<?php
/**
 * Emit a cascade-layered grid form and the editor overlay the editor-canvas
 * field-list regression measures.
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
	// Utility classes live in a cascade layer, as utility-first frameworks emit them.
	$source_css = '@layer base,utilities;@layer utilities{.grid{display:grid}.gap-5{gap:20px}.gap-2{gap:8px}.card{padding:24px;border:1px solid #444}@media (min-width:640px){.sm-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm-span-2{grid-column:span 2/span 2}}}';
	$html       = '<style>' . $source_css . '</style><main><form class="grid gap-5 card sm-cols-2">'
		. '<h3 class="sm-span-2">Request an appointment</h3>'
		. '<div class="grid gap-2"><label for="name">Full name</label><input id="name" name="name" type="text"></div>'
		. '<div class="grid gap-2"><label for="phone">Phone</label><input id="phone" name="phone" type="text"></div>'
		. '<div class="grid gap-2 sm-span-2"><label for="message">Message</label><textarea id="message" name="message"></textarea></div>'
		. '<button class="sm-span-2" type="submit">Send</button>'
		. '</form></main>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup    = (string) ( $row['block_markup'] ?? '' );
	$fields    = array();
	if ( preg_match_all( '/<!-- wp:jetpack\/field-(text|textarea) (\{.*?\}) -->/', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$attrs    = json_decode( $match[2], true );
			$fields[] = array(
				'type'      => $match[1],
				'className' => (string) ( $attrs['className'] ?? '' ),
			);
		}
	}
	$heading_class = '';
	if ( preg_match( '/<!-- wp:(?:core\/)?heading (\{.*?\}) -->/', $markup, $heading ) ) {
		$attrs         = json_decode( $heading[1], true );
		$heading_class = (string) ( $attrs['className'] ?? '' );
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$attrs      = json_decode( $form[1], true );
		$form_class = (string) ( $attrs['className'] ?? '' );
	}
	echo (string) wp_json_encode(
		array(
			'status'    => (string) ( $row['status'] ?? '' ),
			'sourceCss' => $source_css,
			'editorCss' => (string) ( $row['provider_layout_overlay_css']['editor_css'] ?? '' ),
			'className' => $form_class,
			'headingClassName' => $heading_class,
			'fields'    => $fields,
		)
	);
}
