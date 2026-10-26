<?php
/**
 * Seed source forms that together emit every declared form overlay part, and
 * export, per form, what the browser role-box parity test needs to render both
 * surfaces of each part:
 *
 * - the materialized `style.css` and `editor-style.css` (the source CSS plus
 *   the provider overlay, assembled by `Stylesheet_Materializer::stylesheet_writes()`);
 * - the front-end HTML: a model of Jetpack Forms' server render of the seeded
 *   block markup, passed through this plugin's real provider runtime filters
 *   in their registered priority order;
 * - the parsed block tree, from which the test builds Jetpack's editor DOM;
 * - every emitted part instance: its front-end selector from the recorded
 *   target map and its editor selectors derived through the declared editor
 *   realisation (`editor_surface_part()` and the projection's own selector
 *   derivation);
 * - the declared parts, enumerated exactly as
 *   `tests/smoke-form-overlay-editor-parts.php` enumerates them.
 *
 * The Jetpack front-end model mirrors projects/packages/forms/src/contact-form/
 * class-contact-form.php (form container, form, error wrapper),
 * class-contact-form-field.php (render_field shell, render_label,
 * render_input_field, render_textarea_field, render_select_field,
 * render_telephone_field, render_radio_field, render_checkbox_field,
 * get_error_div) and class-contact-form-plugin.php (block attributes to
 * shortcode attributes: labelclasses, inputclasses, optionclasses,
 * fieldwrapperclasses) at Automattic/jetpack 9c19e18ca8f5. Interactivity
 * attributes and hidden inputs that render no box are omitted.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	require_once __DIR__ . '/form-overlay-declared-parts.php';
	require_once __DIR__ . '/form-jetpack-render.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;

	/**
	 * Source forms. No single source emits every part, so each part is measured
	 * on the form that emits it.
	 *
	 * - `controls`: text (required, with a source required marker), email,
	 *   plain tel, select, a textarea inside a source shell, a consent checkbox
	 *   whose source label wraps the input, a radio group in a fieldset, and a
	 *   submit button with a caption span.
	 * - `form_box`: a padded, filled source form placed in a page grid, with a
	 *   source submit row.
	 * - `field_list`: a source field list that wraps the fields but not the submit.
	 * - `native_form`: a page-grid form whose flex column the provider form keeps
	 *   natively.
	 * - `checkbox_row`: a flex row holding a checkbox and its sibling label.
	 * - `country`: a phone field with a source country-picker trigger.
	 * - `caption_order`: a label that wraps its text field, and an email input
	 *   placed before its caption.
	 *
	 * Each source is also seeded without its readable tree, as `<name>_projection`:
	 * that projection path still serves older or partial producer trees, and
	 * is the only path that emits the layout-target parts.
	 */
	$sources = array(
		'controls'    => '<style>*,::before,::after{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}'
			. '.f{display:grid;gap:20px;padding:24px;background-color:#f4f4f5}'
			. '.row>:not([hidden])~:not([hidden]){margin-top:6px}'
			. '.lbl{font-size:14px;font-weight:600;color:#18181b}'
			. '.req{color:#dc2626;font-size:12px;margin-left:2px}'
			. '.ctl{width:100%;height:44px;padding:4px 12px;border:1px solid #71717a;border-radius:6px;background-color:#ffffff;font-size:16px}'
			. '.area{width:100%;padding:12px;border:1px solid #71717a;background-color:#ffffff;font-size:16px}'
			. '.shell{background-color:#e4e4e7;border:2px solid #a1a1aa;padding:6px}'
			. '.consent{display:flex;align-items:center;gap:8px;font-size:14px;padding:4px 0}'
			. '.consent input{width:18px;height:18px}'
			. 'fieldset{border:1px solid #d4d4d8;padding:12px;margin:0}'
			. '.opt{display:block;font-size:15px;padding:2px 0}'
			. '.btn{background-color:#2563eb;color:#fff;padding:10px 20px;border:0;border-radius:6px;font-size:15px}'
			. '.btn .cap{font-weight:700;letter-spacing:1px}</style>'
			. '<main><form class="f">'
			. '<div class="row"><label for="name" class="lbl">Name <span class="req" aria-hidden="true">*</span></label><input id="name" name="name" type="text" required class="ctl"></div>'
			. '<div class="row"><label for="email" class="lbl">Email</label><input id="email" name="email" type="email" class="ctl"></div>'
			. '<div class="row"><label for="phone" class="lbl">Phone</label><input id="phone" name="phone" type="tel" class="ctl"></div>'
			. '<div class="row"><label for="topic" class="lbl">Topic</label><select id="topic" name="topic" class="ctl"><option>One</option><option>Two</option></select></div>'
			. '<div><label for="message" class="lbl">Message</label><div class="shell"><textarea id="message" name="message" rows="4" class="area"></textarea></div></div>'
			. '<label class="consent"><input type="checkbox" name="consent"> I agree to the terms</label>'
			. '<fieldset><legend class="lbl">Plan</legend><label class="opt"><input type="radio" name="plan" value="a"> Basic</label><label class="opt"><input type="radio" name="plan" value="b"> Pro</label></fieldset>'
			. '<button type="submit" class="btn"><span class="cap">Send</span></button>'
			. '</form></main>',
		'form_box'    => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.page{display:grid;grid-template-columns:1fr 1fr;gap:24px}.form{grid-column:2;padding:20px;background:#eee;display:grid;gap:10px}label{display:block}input{padding:8px;border:1px solid #999}.actions{padding-top:12px}.actions button{padding:10px 18px;background:#333;color:#fff;border:0}</style>'
			. '<main class="page"><div>Intro</div><form class="form"><div><label for="e">Email</label><input id="e" name="e" type="email"></div><div><label for="n">Name</label><input id="n" name="n"></div><div class="actions"><button type="submit">Send</button></div></form></main>',
		'field_list'  => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.field-list{display:flex;flex-direction:column;gap:16px}.name-row{border:0;padding:0;margin:0}label{display:block;margin-bottom:4px}input{padding:8px;border:1px solid #a1a1aa}</style>'
			. '<form><div class="field-list"><fieldset class="name-row"><legend>Name</legend><div class="field"><label for="first">First name</label><input id="first" name="first"></div><div class="field"><label for="last">Last name</label><input id="last" name="last"></div></fieldset><div class="field"><label for="email">Email</label><input id="email" name="email" type="email"></div></div><button type="submit">Send</button></form>',
		'native_form' => '<style>body{margin:0;font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.page{display:grid;grid-template-columns:1fr 1fr;gap:24px}.form{grid-column:2;display:flex;flex-direction:column;gap:12px;padding:20px;background:#eee}label{display:block;margin-bottom:4px}input{width:100%;padding:8px;border:1px solid #999}.actions{padding-top:8px;display:flex}.actions button{padding:10px 18px;background:#333;color:#fff;border:0}</style>'
			. '<main class="page"><div>Intro</div><form class="form"><div><label for="e">Email</label><input id="e" name="e" type="email"></div><div class="actions"><button type="submit">Send</button></div></form></main>',
		'two_column'  => '<style>*,::before,::after{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}'
			. '.space-y-5>:not([hidden])~:not([hidden]){margin-top:1.25rem}'
			. '.grid{display:grid}.gap-5{gap:1.25rem}'
			. '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}'
			. '.block{display:block}.mb-2{margin-bottom:.5rem}.text-sm{font-size:14px}'
			. '.w-full{width:100%}.px-4{padding-left:16px;padding-right:16px}.py-3{padding-top:12px;padding-bottom:12px}.border{border:1px solid #d4d4d8}'
			. '.btn{background-color:#18181b;color:#fff;padding:12px 24px;border:0}</style>'
			. '<main><form class="space-y-5">'
			. '<div class="grid sm:grid-cols-2 gap-5">'
			. '<div><label for="first" class="block mb-2 text-sm">First name</label><input id="first" name="first" type="text" class="w-full px-4 py-3 border"></div>'
			. '<div><label for="mail" class="block mb-2 text-sm">Email</label><input id="mail" name="mail" type="email" class="w-full px-4 py-3 border"></div>'
			. '</div>'
			. '<div><label for="msg" class="block mb-2 text-sm">Message</label><textarea id="msg" name="msg" rows="4" class="w-full px-4 py-3 border"></textarea></div>'
			. '<button type="submit" class="btn">Send</button>'
			. '</form></main>',
		'checkbox_row' => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.consent{display:flex;align-items:flex-start;gap:8px}.consent input{margin:0;width:16px;height:16px}</style>'
			. '<main><form><div class="consent"><input type="checkbox" id="news" name="news"><label for="news">Send me news</label></div><button type="submit">Send</button></form></main>',
		'caption_order' => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}form{display:grid;gap:16px;padding:16px}.wl{display:grid;gap:4px;font-size:14px;padding:8px;border:1px solid #d4d4d8}.wl input,.after input{height:36px;border:1px solid #71717a;padding:4px 8px}.after label{font-size:13px;color:#52525b}</style>'
			. '<main><form><label class="wl">Name <input name="name"></label><div class="after"><input id="email" type="email" name="email"><label for="email">We only use this to reply</label></div><button type="submit">Send</button></form></main>',
		'country'     => '<style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}.sourcegroup{display:flex;align-items:center;gap:8px}.globe{width:24px}.chevron{width:16px}.ph{display:flex;align-items:center;border:1px solid #999;background:#fff}.ph button{position:relative;flex-shrink:0;padding:6px 10px;border:0;background:#fafafa}.ph input{flex:1;border:0;padding:8px}</style>'
			. '<main><form><div class="ph"><button type="button" aria-haspopup="listbox" aria-label="Phone country selector"><span class="sourcegroup"><svg class="globe" width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><svg class="chevron" width="16" height="16" viewBox="0 0 16 16"><path d="M4 7l4 4 4-4"/></svg></span></button><input type="tel" name="phone"></div><button type="submit">Send</button></form></main>',
	);

	/** A destination's identity without its part metadata. */
	function ssi_role_box_destination_key( array $destination ): string {
		unset( $destination['part'], $destination['editor'], $destination['class'] );
		return (string) wp_json_encode( $destination );
	}

	/**
	 * Every part instance the recorded target map emits, with its front-end
	 * selector and derived editor selectors.
	 *
	 * Layout targets carry their part through their node kind and selector
	 * shape, as `provider_layout_target_map()` assigns it. Presentation
	 * destinations recover their declared part by matching the recorded
	 * destination against the descriptor enumeration at the same control index.
	 */
	function ssi_role_box_instances( array $map ): array {
		$scope           = substr( (string) $map['scope'], 1 );
		$editor_selector = new ReflectionMethod( Static_Site_Importer_Form_Presentation_Descriptor::class, 'editor_selector' );
		$instances = array();
		$add       = static function ( string $part, array $entry, string $origin, ?array $declared = null ) use ( &$instances, $editor_selector, $scope ): void {
			$surface   = Static_Site_Importer_Form_Presentation_Descriptor::editor_surface_part( array( 'part' => $part ) + ( isset( $declared['editor'] ) ? array( 'editor' => $declared['editor'] ) : array() ) + $entry );
			$selectors = array();
			if ( empty( $surface['drop'] ) ) {
				$selectors[] = $editor_selector->invoke( null, $entry['selector'], $surface, '.' . $scope );
				if ( isset( $surface['split'] ) ) {
					// The split box destination keeps the front-end selector.
					$selectors[] = $entry['selector'];
				}
			}
			$instances[] = array(
				'part'      => $part,
				'origin'    => $origin,
				'front'     => $entry['selector'],
				'editor'    => $selectors,
				'dropped'   => ! empty( $surface['drop'] ),
				'resets'    => $entry['resets'] ?? array(),
			);
		};
		foreach ( $map['targets'] as $target ) {
			$node     = (string) $target['node'];
			$selector = (string) $target['selector'];
			$native   = str_contains( $selector, 'ssi-native-form-topology' );
			if ( 'form' === $node ) {
				$part = $native ? 'native_form' : 'form';
			} elseif ( 'form-box' === $node ) {
				$part = $native ? 'native_form_box' : 'form_box';
			} elseif ( 'field-list' === $node ) {
				$part = 'field_list';
			} elseif ( preg_match( '/^field-[0-9]+$/D', $node ) ) {
				$part = 'field';
			} elseif ( preg_match( '/^checkbox-[0-9]+$/D', $node ) ) {
				$part = 'checkbox_layout';
			} elseif ( str_ends_with( $selector, ' > .wp-block-button__link' ) ) {
				$part = 'submit_link_box';
			} elseif ( preg_match( '/ \.ssi-node-[a-f0-9]{12}-wrap$/D', $selector ) ) {
				$part = 'wrap';
			} else {
				$part = 'box';
			}
			$add( $part, $target, $node );
		}
		foreach ( $map['presentation_targets'] as $target ) {
			$lookup = array();
			foreach ( ssi_editor_parts_descriptor_destinations( $scope, (int) $target['index'] ) as $destination ) {
				$lookup[ ssi_role_box_destination_key( $destination ) ][ $destination['part'] ] = $destination;
			}
			foreach ( $target['destinations'] as $destination ) {
				if ( preg_match( '/-destination-country-trigger$/D', (string) $destination['selector'] ) ) {
					// The phone country trigger is the only destination the
					// descriptor builder does not emit; the target map adds it.
					$parts = array( 'country_trigger' );
				} else {
					$parts = array_keys( $lookup[ ssi_role_box_destination_key( $destination ) ] ?? array() );
				}
				if ( 1 !== count( $parts ) ) {
					fwrite( STDERR, 'ambiguous or unknown destination part for ' . $destination['selector'] . ': ' . implode( ',', $parts ) . "\n" );
					exit( 1 );
				}
				$add( $parts[0], $destination, 'control-' . $target['index'], $lookup[ ssi_role_box_destination_key( $destination ) ][ $parts[0] ] ?? null );
			}
		}
		return $instances;
	}

	$forms = array();
	foreach ( $sources as $name => $html ) {
		$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
		$fallback = $compiled['fallbacks'][0] ?? array();
		if ( 'country' === $name ) {
			// Producer contract: a telephone control the producer classifies as a
			// phone composite (a country picker beside the number input) arrives
			// as type `phone`; that is what selects Jetpack's country selector.
			foreach ( $fallback['controls'] ?? array() as $index => $control ) {
				if ( 'tel' === ( $control['type'] ?? null ) ) {
					$fallback['controls'][ $index ]['type'] = 'phone';
				}
			}
		}
		$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
		$projected = $validated['forms'] ?? array();
		unset( $projected[0]['readable_blocks'] );
		foreach ( array( $name => $validated['forms'] ?? array(), $name . '_projection' => $projected ) as $variant => $seed_forms ) {
		$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $seed_forms ) )['forms'][0] ?? array();
		if ( 'mapped' !== ( $row['status'] ?? null ) ) {
			if ( $variant !== $name ) {
				continue;
			}
			fwrite( STDERR, "source form {$name} did not map: " . wp_json_encode( array( 'status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null, 'losses' => $row['form_receipt_unaccepted_losses'] ?? null ) ) . "\n" );
			exit( 1 );
		}
		Static_Site_Importer_Provider_Form_Runtime_V1::configure_visual_states( isset( $row['form_visual_state'] ) ? array( $row['form_visual_state'] ) : array() );
		preg_match_all( '/<style>(.*?)<\/style>/s', $html, $styles );
		$writes   = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Role Box Parity', implode( "\n", $styles[1] ), array(), array(), array( $row['provider_layout_overlay_css'] ) );
		$blocks   = ssi_role_box_parse_blocks( (string) $row['block_markup'] );
		$forms[]  = array(
			'name'      => $variant,
			'scope'     => substr( (string) $row['provider_layout_target_map']['scope'], 1 ),
			'blocks'    => $blocks,
			'frontHtml' => ssi_role_box_render_form( $blocks[0] ),
			'css'       => $writes['/theme/style.css'],
			'editorCss' => $writes['/theme/assets/css/editor-style.css'],
			'instances' => ssi_role_box_instances( $row['provider_layout_target_map'] ),
		);
		}
	}
	$declared = array();
	foreach ( ssi_editor_parts_declared( '.ssi-form-000000000000' ) as $part => $surface ) {
		$declared[ $part ] = array( 'dropped' => ! empty( $surface['drop'] ) );
	}
	echo (string) wp_json_encode( array( 'declared' => $declared, 'forms' => $forms ), JSON_UNESCAPED_SLASHES );
}
