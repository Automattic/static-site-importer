<?php
/**
 * Emit a neutral phone, select, and rows-sized textarea form with the editor
 * overlay and the front-end overlay plus runtime-projected Jetpack field markup,
 * so the editor control boxes can be measured against the front end.
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
	$source_css = '@layer theme,base,utilities;@layer theme{:root{--spacing:.25rem}}@layer base{*,::before,::after{box-sizing:border-box;border:0 solid}input,select,textarea{font:inherit;color:inherit}}'
		. '@layer utilities{.grid{display:grid}.gap-2{gap:8px}.gap-5{gap:20px}.w-full{width:100%}.h-11{height:2.75rem}.border{border-width:1px}.border-input{border-color:#3f3f46}.bg-field{background-color:#18181b}.rounded-md{border-radius:.3rem}.px-3{padding-inline:calc(var(--spacing) * 3)}.py-1{padding-block:calc(var(--spacing) * 1)}.py-3{padding-block:calc(var(--spacing) * 3)}.text-base{font-size:16px;line-height:1.5}}';
	$control    = 'w-full border border-input bg-field rounded-md px-3 text-base';
	$html       = '<style>' . $source_css . '</style><main><form class="grid gap-5">'
		. '<div class="grid gap-2"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" class="h-11 py-1 ' . $control . '"></div>'
		. '<div class="grid gap-2"><label for="topic">Topic</label><select id="topic" name="topic" class="h-11 py-3 ' . $control . '"><option>One</option><option>Two</option></select></div>'
		. '<div class="grid gap-2"><label for="message">Message</label><textarea id="message" name="message" rows="4" class="py-3 ' . $control . '"></textarea></div>'
		. '<button type="submit">Send</button>'
		. '</form></main>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup    = (string) ( $row['block_markup'] ?? '' );
	$fields    = array();
	if ( preg_match_all( '/<!-- wp:jetpack\/field-(telephone|select|textarea) (\{.*?\}) -->.*?<!-- wp:jetpack\/(?:phone-)?input (\{.*?\}) -->/s', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$field_attrs = json_decode( $match[2], true );
			$input_attrs = json_decode( $match[3], true );
			$type        = $match[1];
			$field_class = (string) ( $field_attrs['className'] ?? '' );
			$input_class = (string) ( $input_attrs['className'] ?? '' );
			$tokens      = array_filter( explode( ' ', $field_class ) );
			$wrap        = empty( $tokens ) ? '' : implode( '-wrap ', $tokens ) . '-wrap';
			// Jetpack's rendered field: the shell carries every block class with a
			// `-wrap` suffix and the native control carries the input block class.
			if ( 'telephone' === $type ) {
				$control_html = '<input type="tel" class="telephone grunion-field ' . $input_class . '">';
			} elseif ( 'select' === $type ) {
				$control_html = '<div class="contact-form__select-wrapper ' . $input_class . '"><span class="contact-form__select-element-wrapper"><select><option value="">One</option><option>Two</option></select><span class="jetpack-field-dropdown__icon"></span></span></div>';
			} else {
				$control_html = "<textarea rows='20' class=\"textarea grunion-field " . $input_class . '"></textarea>';
			}
			$field_html = '<div class="grunion-field-wrap grunion-field-' . $type . '-wrap ' . $wrap . '"><label class="grunion-field-label">' . $type . '</label>' . $control_html . '</div>';
			$fields[]   = array(
				'type'           => $type,
				'className'      => $field_class,
				'inputClassName' => $input_class,
				'frontendHtml'   => Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes( $field_html ),
			);
		}
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$attrs      = json_decode( $form[1], true );
		$form_class = (string) ( $attrs['className'] ?? '' );
	}
	echo (string) wp_json_encode(
		array(
			'status'      => (string) ( $row['status'] ?? '' ),
			'sourceCss'   => $source_css,
			'css'         => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'editorCss'   => (string) ( $row['provider_layout_overlay_css']['editor_css'] ?? '' ),
			'className'   => $form_class,
			'fields'      => $fields,
		)
	);
}
