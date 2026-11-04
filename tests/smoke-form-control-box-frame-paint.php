<?php
/**
 * A control box's frame paints once, on the box the runtime builds around the
 * provider control, and a phone frame (country trigger beside the tel input)
 * is that field's control box.
 *
 * Fixture: a grid form whose caption cells sit apart from `root > err > frame`
 * pills. The frame paints the pill (background, 1px border, 40px radius), the
 * control states `border:0` and a transparent background, and a media query
 * restates both after the control's own media rule. The phone frame holds the
 * source's country button and the tel input; the message frame is a 92px box
 * around its textarea.
 */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = (string) file_get_contents( __DIR__ . '/fixtures/form-control-box-frame-paint.html' );
$seed    = static function ( string $html ): array {
	$result      = ( new ArtifactCompiler() )->compile( array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => $html ),
	) )->toArray();
	$plan        = $result['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
	$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
	return Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
};
$row         = $seed( $fixture );
$overlay     = $row['provider_layout_overlay_css'];
$css         = (string) ( $overlay['css'] ?? '' );
$editor_css  = (string) ( $overlay['editor_css'] ?? '' );
$markup      = (string) $row['block_markup'];
$rendered    = ssi_role_box_render_form( ssi_role_box_parse_blocks( $markup )[0] ?? array() );

// Every declaration block of the rules whose selector names one class, in
// source order, with the media query each sits in ('' outside one).
$rules = static function ( string $css, string $class ): array {
	$found = array();
	$media = '';
	$depth = 0;
	preg_match_all( '/@media\s*([^{]*)\{|([^{}]+)\{([^{}]*)\}|\}/', $css, $tokens, PREG_SET_ORDER );
	foreach ( $tokens as $token ) {
		if ( '' !== ( $token[1] ?? '' ) ) {
			$media = trim( $token[1] );
			continue;
		}
		if ( '' === ( $token[2] ?? '' ) ) {
			$media = '';
			continue;
		}
		if ( 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '(?=[\s,{:.>]|$)/', $token[2] ) ) {
			$found[] = array( 'media' => $media, 'declarations' => $token[3] );
		}
	}
	return $found;
};
$joined   = static fn( array $rules ): string => implode( ';', array_column( $rules, 'declarations' ) );
$classes  = static function ( string $html, string $needle ): array {
	return preg_match( '/class=["\']([^"\']*\b' . preg_quote( $needle, '/' ) . '\b[^"\']*)["\']/', $html, $match ) ? ( preg_split( '/\s+/', trim( $match[1] ) ) ?: array() ) : array();
};
$hook_in  = static function ( array $classes, string $pattern ): string {
	return (string) ( current( preg_grep( $pattern, $classes ) ) ?: '' );
};
$frame_paint = static fn( string $declarations ): bool => 1 === preg_match( '/border:1px solid|background-color:#515b2a/', $declarations );

$checks = array(
	'form maps'        => 'mapped' === $row['status'],
	'no gate losses'   => empty( $row['mapping_decision']['losses'] ),
	'overlay admitted' => null !== Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay ),
);

// --- A text field: the frame paints once, on the box. ----------------------
$first_input    = preg_match( "/<input type='text'[^>]*name='g1-field-0'[^>]*>/", $rendered, $m ) ? $m[0] : '';
$first_tokens   = preg_match( "/class='([^']*)'/", $first_input, $m ) ? preg_split( '/\s+/', trim( $m[1] ) ) : array();
$first_box      = preg_match( '/<div class="(ssi-node-[a-f0-9]{12}-destination-box)[^"]*">' . preg_quote( substr( $first_input, 0, 30 ), '/' ) . '/', $rendered, $m ) ? $m[1] : '';
$checks['text control sits inside its box'] = '' !== $first_box;
$first_painted = array();
foreach ( $first_tokens as $token ) {
	if ( 1 === preg_match( '/^ssi-node-[a-f0-9]{12}$/D', $token ) ) {
		foreach ( $rules( $css, $token ) as $rule ) {
			if ( $frame_paint( $rule['declarations'] ) ) {
				$first_painted[] = $token . ( '' === $rule['media'] ? '' : ' @' . $rule['media'] );
			}
		}
	}
}
// Before: the frame rule addressed the field's layout hook, which Jetpack
// copies onto the <input>, so the control painted the pill a second time
// inside the box (and at narrow widths the media copy won over `border:0`).
$checks['text control paints no frame (base or media)'] = array() === $first_painted;
$box_rules = '' === $first_box ? array() : $rules( $css, $first_box );
$checks['text box paints the frame']                    = (bool) array_filter( $box_rules, static fn( array $rule ): bool => '' === $rule['media'] && str_contains( $rule['declarations'], 'border:1px solid rgb(234,234,158)' ) && str_contains( $rule['declarations'], 'border-radius:40px' ) );
$checks['text box keeps the media frame restatement']   = (bool) array_filter( $box_rules, static fn( array $rule ): bool => str_contains( $rule['media'], 'max-width:1023px' ) && str_contains( $rule['declarations'], 'border:1px solid rgb(234,234,158)' ) );
$first_hook = '';
foreach ( $first_tokens as $token ) {
	if ( 1 === preg_match( '/^ssi-node-[a-f0-9]{12}$/D', $token ) && str_contains( $joined( $rules( $editor_css, $token ) ), 'border:1px solid rgb(234,234,158)' ) ) {
		$first_hook = $token;
	}
}
// The editor renders no box: its frame paint stays on the control.
$checks['editor keeps the frame on the control'] = '' !== $first_hook;

// --- The textarea: its frame's border and height reach the box. -------------
$area_input = preg_match( '/<textarea[^>]*>/', $rendered, $m ) ? $m[0] : '';
$area_box   = preg_match( '/<div class="(ssi-node-[a-f0-9]{12}-destination-box)[^"]*"><textarea/', $rendered, $m ) ? $m[1] : '';
$area_rules = '' === $area_box ? '' : $joined( $rules( $css, $area_box ) );
$checks['textarea box carries the frame border'] = str_contains( $area_rules, 'border:1px solid rgb(234,234,158)' ) && str_contains( $area_rules, 'border-radius:21px' );
$checks['textarea box carries the frame height'] = str_contains( $area_rules, 'height:92px' );
$area_tokens  = preg_match( "/class='([^']*)'/", $area_input, $m ) ? preg_split( '/\s+/', trim( $m[1] ) ) : array();
$area_painted = array_filter( $area_tokens, static fn( string $token ): bool => 1 === preg_match( '/^ssi-node-[a-f0-9]{12}$/D', $token ) && $frame_paint( $joined( $rules( $css, $token ) ) ) );
$checks['textarea paints no frame'] = array() === $area_painted;

// --- The phone: the frame holding the trigger and the input is its box. ----
$checks['phone frame shell is not kept around the field'] = ! preg_match( '/<div class="root"><div class="err"><div class="frame"><!-- wp:jetpack\/field-tele/', $markup );
$phone_at   = strpos( $rendered, 'grunion-field-telephone-wrap' );
$phone_html = false === $phone_at ? '' : (string) substr( $rendered, $phone_at, (int) strpos( $rendered, 'grunion-field-textarea-wrap', $phone_at ) - $phone_at );
$box_at     = preg_match( '/<div class="(ssi-node-[a-f0-9]{12}-destination-box) root err frame">/', $phone_html, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : -1;
$label_at   = strpos( $phone_html, 'grunion-field-label' );
$checks['phone caption sits above, outside the box'] = 0 <= $box_at && false !== $label_at && $label_at < $box_at;
$inside = 0 <= $box_at ? (string) substr( $phone_html, $box_at ) : '';
$checks['phone box holds the trigger and the input'] = str_contains( $inside, 'jetpack-field__input-phone-wrapper' ) && str_contains( $inside, 'jetpack-combobox-trigger' ) && 1 === preg_match( '/type="tel"/', $inside );
$checks['phone shell carries no frame classes']      = 1 !== preg_match( '/jetpack-field__input-phone-wrapper[^"]*\b(?:frame|ssi-source-box--)/', $phone_html );
$shell_class = $hook_in( $classes( $phone_html, 'jetpack-field__input-phone-wrapper' ), '/^ssi-node-[a-f0-9]{12}-destination-shell$/D' );
$shell_rules = '' === $shell_class ? '' : $joined( $rules( $css, $shell_class ) );
$checks['phone shell fills the box']               = str_contains( $shell_rules, 'flex:1 1 auto!important' );
$checks['country trigger inherits the field text'] = str_contains( $shell_rules, 'color:#eaea9e!important' ) && str_contains( $shell_rules, 'font-size:16px!important' );
$checks['rendered keeps no marker copies']         = ! str_contains( $rendered, 'ssi-source-box--' ) && ! str_contains( $rendered, 'ssi-source-prefix--' );
// The source element that held the trigger inside the frame (its inset from
// the frame edge is class-owned) is the provider prefix's place.
$prefix_classes = $classes( $inside, 'jetpack-field__input-prefix' );
$checks['prefix carries the source trigger element class'] = in_array( 'prefix', $prefix_classes, true ) && ! in_array( 'prefix', $classes( $inside, 'jetpack-custom-combobox' ), true );
$prefix_class = $hook_in( $prefix_classes, '/^ssi-node-[a-f0-9]{12}-destination-prefix$/D' );
$checks['prefix keeps the field text colour'] = '' !== $prefix_class && str_contains( $joined( $rules( $css, $prefix_class ) ), 'color:inherit!important' );

// --- A country trigger known only by its label is the same box. -----------
// Some captures expose the trigger without `aria-haspopup`; the seeder still
// consumes it as the field's selector, so its frame is the field's box too.
$labelled          = str_replace( 'aria-label="Select a country code" aria-haspopup="listbox"', 'aria-label="Phone country code"', $fixture );
$labelled_row      = $seed( $labelled );
$labelled_rendered = ssi_role_box_render_form( ssi_role_box_parse_blocks( (string) $labelled_row['block_markup'] )[0] ?? array() );
$labelled_at       = strpos( $labelled_rendered, 'grunion-field-telephone-wrap' );
$labelled_phone    = false === $labelled_at ? '' : (string) substr( $labelled_rendered, $labelled_at, (int) strpos( $labelled_rendered, 'grunion-field-textarea-wrap', $labelled_at ) - $labelled_at );
$labelled_box      = preg_match( '/<div class="ssi-node-[a-f0-9]{12}-destination-box root err frame">/', $labelled_phone, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : -1;
$labelled_label    = strpos( $labelled_phone, 'grunion-field-label' );
$checks['labelled trigger variant differs from the fixture'] = $labelled !== $fixture && 'mapped' === $labelled_row['status'];
$checks['labelled trigger: caption above the phone box']     = 0 <= $labelled_box && false !== $labelled_label && $labelled_label < $labelled_box
	&& str_contains( (string) substr( $labelled_phone, $labelled_box ), 'jetpack-combobox-trigger' );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
