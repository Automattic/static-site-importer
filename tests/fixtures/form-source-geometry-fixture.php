<?php
/**
 * Seed reduced source forms and export what the source geometry test renders
 * on three surfaces: the source page, the front end (a model of Jetpack's
 * server render of the seeded block markup through the provider runtime
 * filters) and the editor canvas (built by the test from the parsed blocks).
 *
 * Each case is one form layout shape importers meet on real sites.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	require_once __DIR__ . '/form-jetpack-render.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;

	// Controls inherit the page font, as reset stylesheets make them.
	$base     = 'body{margin:0;font-family:Arial,sans-serif;font-size:16px;line-height:1.5}input,select,textarea,button{font:inherit}';
	$tailwind = '*,::before,::after{box-sizing:border-box}'
		. '.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem * var(--tw-space-y-reverse))}'
		. '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.gap-2{gap:8px}.gap-5{gap:1.25rem}'
		. '@media (min-width:640px){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\\:col-span-2{grid-column:span 2/span 2}}'
		. '.block{display:block}.mb-2{margin-bottom:.5rem}.text-sm{font-size:14px}.w-full{width:100%}.h-11{height:2.75rem}'
		. '.px-4{padding-left:16px;padding-right:16px}.py-3{padding-top:12px;padding-bottom:12px}.border{border:1px solid #d4d4d8}'
		. '.btn{background-color:#18181b;color:#fff;padding:12px 24px;border:0;font-size:16px}';
	$input    = 'w-full px-4 py-3 border';
	$cases    = array(
		// A stacked form whose first row is a responsive two-column grid.
		'two_column_grid'  => array(
			$tailwind,
			'<form class="space-y-5"><div class="grid grid-cols-1 sm:grid-cols-2 gap-5">'
				. '<div><label for="n" class="block mb-2 text-sm">Name</label><input id="n" name="n" class="' . $input . '"></div>'
				. '<div><label for="e" class="block mb-2 text-sm">Email</label><input id="e" name="e" type="email" class="' . $input . '"></div></div>'
				. '<div><label for="m" class="block mb-2 text-sm">Message</label><textarea id="m" name="m" rows="4" class="' . $input . '"></textarea></div>'
				. '<button type="submit" class="btn">Send</button></form>',
		),
		// The form itself is a padded two-column grid card with spanning rows.
		'grid_card'        => array(
			$tailwind . '.card{padding:24px;border:1px solid #444}',
			'<form class="grid gap-5 card sm:grid-cols-2"><h3 class="sm:col-span-2" style="margin:0">Request an appointment</h3>'
				. '<div class="grid gap-2"><label for="n">Full name</label><input id="n" name="n" class="' . $input . '"></div>'
				. '<div class="grid gap-2"><label for="p">Phone</label><input id="p" name="p" class="' . $input . '"></div>'
				. '<div class="grid gap-2 sm:col-span-2"><label for="m">Message</label><textarea id="m" name="m" class="' . $input . '"></textarea></div>'
				. '<button class="sm:col-span-2 btn" type="submit">Send</button></form>',
		),
		// Sized phone, select and rows-sized textarea controls.
		'sized_controls'   => array(
			$tailwind,
			'<form class="grid gap-5">'
				. '<div class="grid gap-2"><label for="p">Phone</label><input id="p" name="p" type="tel" class="h-11 ' . $input . '"></div>'
				. '<div class="grid gap-2"><label for="t">Topic</label><select id="t" name="t" class="h-11 ' . $input . '"><option>One</option><option>Two</option></select></div>'
				. '<div class="grid gap-2"><label for="m">Message</label><textarea id="m" name="m" rows="4" class="' . $input . '"></textarea></div>'
				. '<button type="submit" class="btn">Send</button></form>',
		),
		// A column-flex form with a 12-track grid row, a note between fields and
		// a first-column submit cell with a minimum height.
		'grid_span'        => array(
			'*,::before,::after{box-sizing:border-box}.stack{display:flex;flex-direction:column;gap:24px;padding:13px 17px;background:#123456;color:#fff}.stack label{display:block;margin-bottom:8px}.stack input,.stack textarea{display:block;width:100%}.send{min-height:56px}',
			'<form class="stack"><h2 style="margin:0">Contact us</h2>'
				. '<div style="display:grid;grid-template-columns:repeat(12,1fr);column-gap:24px">'
				. '<div style="grid-column:1 / span 6"><label for="f">First name</label><input id="f" name="f"></div>'
				. '<div style="grid-column:7 / span 6"><label for="l">Last name</label><input id="l" name="l"></div>'
				. '<div style="grid-column:1 / span 12"><label for="m">Message</label><textarea id="m" name="m"></textarea></div>'
				. '<div style="grid-column:1 / span 3;min-height:64px"><button class="send" type="submit">Send</button></div>'
				. '</div><p style="margin:0">We will reply soon.</p></form>',
		),
		// Two floated halves of a name row that stack below 768px.
		'floated_name_row' => array(
			'.field-group{display:block;padding-bottom:18px}.name-row:after{content:"";display:table;clear:both}'
				. 'form .name-half{float:left;width:calc(50% - 10px)}.name-last{margin-left:20px}label{display:block}input{box-sizing:border-box;width:100%}'
				. '@media(max-width:768px){form .name-half{width:100%;margin-left:0;float:none}.name-first{margin-bottom:18px}}',
			'<form><div class="field-group"><label>Name</label><div class="name-row">'
				. '<div class="name-half name-first"><input id="first" name="first"><label for="first">First</label></div>'
				. '<div class="name-half name-last"><input id="last" name="last"><label for="last">Last</label></div></div></div>'
				. '<div class="field-group"><label for="email">Email</label><input id="email" name="email" type="email"></div>'
				. '<button type="submit">Send</button></form>',
		),
		// Label boxes that wrap their control and size a wrapping flex row, a
		// consent checkbox with a link, and alternating painted field boxes.
		'label_boxes'      => array(
			'*,::before,::after{box-sizing:border-box}.f{display:flex;flex-direction:column;gap:18px}.row{display:flex;flex-wrap:wrap;gap:16px}'
				. '.row>label{flex:1 1 240px;display:flex;flex-direction:column;gap:6px;font-size:14px;font-weight:600}'
				. '.row input,.row select{min-height:48px;padding:12px 14px;border:1.5px solid #777;border-radius:12px;font:inherit}'
				. '.alt{padding:14px 18px;background:#f0f4f8}.consent{display:flex;align-items:flex-start;gap:10px;font-size:14px}',
			'<form class="f"><div class="row">'
				. '<label>Name<input name="name" autocomplete="name"></label>'
				. '<label class="alt">Email<input name="email" type="email"></label>'
				. '<label>Location<select name="loc"><option>North</option><option>South</option></select></label></div>'
				. '<label class="consent"><input type="checkbox" name="ok"><span>I agree to the <a href="/privacy/">privacy policy</a>.</span></label>'
				. '<button type="submit">Send</button></form>',
		),
	);

	$forms = array();
	foreach ( $cases as $name => list( $css, $form_html ) ) {
		$source   = '<style>' . $base . $css . '</style><main>' . $form_html . '</main>';
		$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $source ) ) )->toArray();
		$fallback = $compiled['fallbacks'][0] ?? array();
		$valid    = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
		$row      = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $valid['forms'] ?? array() ) )['forms'][0] ?? array();
		if ( 'mapped' !== ( $row['status'] ?? null ) ) {
			fwrite( STDERR, "source form {$name} did not map: " . wp_json_encode( array( 'status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null, 'losses' => $row['form_receipt_unaccepted_losses'] ?? null ) ) . "\n" );
			exit( 1 );
		}
		Static_Site_Importer_Provider_Form_Runtime_V1::configure_visual_states( isset( $row['form_visual_state'] ) ? array( $row['form_visual_state'] ) : array() );
		// The imported theme serves the stylesheets the compile emits (engine
		// support rules, then the projected source CSS), not the raw source.
		$page_css = implode( "\n", array_map( static fn ( array $asset ): string => (string) $asset['content'], array_filter( $compiled['assets'] ?? array(), static fn ( array $asset ): bool => 'css' === ( $asset['kind'] ?? null ) ) ) );
		$writes   = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Source Geometry', $page_css, array(), array(), array( $row['provider_layout_overlay_css'] ) );
		$blocks  = ssi_role_box_parse_blocks( (string) $row['block_markup'] );
		$forms[] = array(
			'name'       => $name,
			'readable'   => Static_Site_Importer_Form_Readable_Substitution::supports( $valid['forms'][0] ?? array() ),
			'sourceHtml' => $source,
			'blocks'     => $blocks,
			'frontHtml'  => ssi_role_box_render_form( $blocks[0] ),
			'css'        => $writes['/theme/style.css'],
			'editorCss'  => $writes['/theme/assets/css/editor-style.css'],
		);
	}
	echo (string) wp_json_encode( array( 'forms' => $forms ), JSON_UNESCAPED_SLASHES );
}
